<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/meta-whatsapp.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (meta_whatsapp_validate_webhook_challenge()) {
        http_response_code(200);
        exit;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Webhook não autorizado.';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

$body = file_get_contents('php://input') ?: '';

if (!meta_whatsapp_validate_webhook_signature($body)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Assinatura do webhook inválida.']);
    exit;
}

$payload = json_decode($body !== '' ? $body : '{}', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

$incomingMessages = meta_whatsapp_extract_incoming_messages($payload);
$statuses = meta_whatsapp_extract_statuses($payload);

if ($statuses !== []) {
    foreach ($statuses as $status) {
        $logMessage = 'Status de mensagem recebido: ' . (string) ($status['status'] ?? '');

        if (($status['status'] ?? '') === 'failed') {
            $logMessage = 'Falha de entrega recebida pela Meta Cloud API.';
        }

        meta_whatsapp_log($logMessage, ['status' => $status]);
    }
}

if ($incomingMessages === []) {
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'Nenhuma mensagem recebida no payload.',
        'statuses' => $statuses,
    ]);
    exit;
}

$results = [];

foreach ($incomingMessages as $incoming) {
    $whatsapp = (string) ($incoming['number'] ?? '');
    $message = trim((string) ($incoming['text'] ?? ''));
    $name = trim((string) ($incoming['name'] ?? ''));
    $profilePictureUrl = crm_normalize_profile_picture_url((string) ($incoming['profile_picture_url'] ?? ''));

    if ($whatsapp === '') {
        continue;
    }

    if ($name === '') {
        $name = 'Contato WhatsApp ' . substr($whatsapp, -4);
    }

    $leadPayload = [
        'name' => $name,
        'whatsapp' => $whatsapp,
        'profile_picture_url' => $profilePictureUrl,
        'company' => 'Não informado',
        'segment' => 'WhatsApp',
        'advertises' => 'whatsapp',
        'message' => $message !== '' ? $message : 'Mensagem recebida pela Meta Cloud API.',
        'page' => 'Meta WhatsApp Cloud API',
        'utm_source' => 'meta_whatsapp_cloud',
        'utm_medium' => 'whatsapp',
        'utm_campaign' => meta_whatsapp_settings()['coex_enabled'] ? 'coex_mensagem_recebida' : 'mensagem_recebida',
        'landing_path' => 'crm/api/meta-whatsapp-webhook.php',
    ];

    try {
        $leadResult = crm_create_lead_once($leadPayload);
        $lead = $leadResult['lead'];
        $followupAutomation = ($leadResult['created'] ?? false) === false
            ? crm_stop_followup_after_incoming_reply((string) $lead['id'])
            : ['stopped' => false, 'cancelled' => 0];

        if ($profilePictureUrl !== '' && $profilePictureUrl !== (string) ($lead['profile_picture_url'] ?? '')) {
            crm_update_lead_profile_picture((string) $lead['id'], $profilePictureUrl);
        }

        if (($leadResult['created'] ?? false) === false && $message !== '') {
            crm_append_lead_note(
                (string) $lead['id'],
                'Mensagem recebida pela Meta Cloud API em ' . date('d/m/Y H:i') . ":\n" . $message
            );
            crm_notify_lead_reply_push($lead, $message);
        }

        if (($followupAutomation['stopped'] ?? false) === true) {
            crm_append_lead_note(
                (string) $lead['id'],
                'Follow-up cancelado automaticamente após resposta do lead; lead movido para Em contato.'
            );
        }
    } catch (Throwable $error) {
        meta_whatsapp_log('Erro ao processar mensagem recebida.', [
            'incoming' => $incoming,
            'error' => $error->getMessage(),
        ]);

        $results[] = [
            'ok' => false,
            'whatsapp' => $whatsapp,
            'error' => 'Não foi possível processar a mensagem recebida.',
        ];
        continue;
    }

    $metaResult = ['ok' => true, 'skipped' => true, 'reason' => 'Lead já existe no CRM.'];
    $emailResult = ['ok' => true, 'skipped' => true, 'reason' => 'Notificação por e-mail não repetida.'];

    if (($leadResult['created'] ?? false) === true) {
        try {
            $metaResult = meta_capi_send_lead_created($lead, $leadPayload);
        } catch (Throwable $error) {
            meta_whatsapp_log('Erro Meta CAPI webhook WhatsApp Lead.', [
                'lead_id' => (string) $lead['id'],
                'error' => $error->getMessage(),
            ]);
            $metaResult = ['ok' => false, 'error' => 'Meta CAPI falhou.'];
        }

        try {
            $emailResult = crm_send_lead_email_notification($lead);

            if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
                meta_whatsapp_log('Erro e-mail webhook WhatsApp Lead.', [
                    'lead_id' => (string) $lead['id'],
                    'error' => (string) ($emailResult['error'] ?? 'Erro desconhecido.'),
                ]);
            }
        } catch (Throwable $error) {
            meta_whatsapp_log('Erro e-mail webhook WhatsApp Lead.', [
                'lead_id' => (string) $lead['id'],
                'error' => $error->getMessage(),
            ]);
            $emailResult = ['ok' => false, 'error' => 'Notificação por e-mail falhou.'];
        }
    }

    $results[] = [
        'ok' => true,
        'lead_id' => $lead['id'],
        'created' => (bool) ($leadResult['created'] ?? false),
        'message_id' => (string) ($incoming['id'] ?? ''),
        'followup' => $followupAutomation,
        'email' => $emailResult,
        'meta' => $metaResult,
    ];
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'processed' => count($results),
    'statuses' => $statuses,
    'results' => $results,
]);
