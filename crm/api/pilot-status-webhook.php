<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/pilot-status.php';
require_once dirname(__DIR__) . '/lib/whatsapp.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$rawBody = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];

pilot_status_log('Webhook hit recebido.', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'query' => $_GET,
    'body' => $rawBody,
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $challenge = $_GET['hub_challenge'] ?? $_GET['challenge'] ?? $_GET['echo'] ?? null;
    if ($challenge !== null) {
        echo (string)$challenge;
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

$body = $rawBody;
$payload = json_decode($body !== '' ? $body : '{}', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

if (!pilot_status_validate_webhook($body, $payload)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Webhook não autorizado.']);
    exit;
}

$incomingMessages = pilot_status_extract_incoming_messages($payload);

if ($incomingMessages === []) {
    pilot_status_log('Webhook sem mensagem recebida processável.', ['payload' => $payload]);
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'Nenhuma mensagem recebida no payload.',
    ]);
    exit;
}

$results = [];

foreach ($incomingMessages as $incoming) {
    $whatsapp = (string) ($incoming['number'] ?? '');
    $message = trim((string) ($incoming['text'] ?? ''));
    $name = trim((string) ($incoming['name'] ?? ''));

    if ($whatsapp === '') {
        continue;
    }

    if ($name === '') {
        $name = 'Contato WhatsApp ' . substr($whatsapp, -4);
    }

    $leadPayload = [
        'name' => $name,
        'whatsapp' => $whatsapp,
        'company' => 'Não informado',
        'segment' => 'WhatsApp',
        'advertises' => 'whatsapp',
        'message' => $message !== '' ? $message : 'Mensagem recebida pela Pilot Status.',
        'page' => 'Pilot Status',
        'utm_source' => 'pilot_status',
        'utm_medium' => 'whatsapp',
        'utm_campaign' => 'mensagem_recebida',
        'landing_path' => 'crm/api/pilot-status-webhook.php',
    ];

    try {
        $leadResult = crm_create_lead_once($leadPayload);
        $lead = $leadResult['lead'];

        if (($leadResult['created'] ?? false) === false && $message !== '') {
            crm_append_lead_note(
                (string) $lead['id'],
                'Mensagem recebida pela Pilot Status em ' . date('d/m/Y H:i') . ":\n" . $message
            );
        }
    } catch (Throwable $error) {
        pilot_status_log('Erro ao processar mensagem recebida.', [
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
    $whatsappResult = ['ok' => true, 'skipped' => true, 'reason' => 'Notificação interna não repetida.'];
    $emailResult = ['ok' => true, 'skipped' => true, 'reason' => 'Notificação por e-mail não repetida.'];

    if (($leadResult['created'] ?? false) === true) {
        try {
            $metaResult = meta_capi_send_lead_created($lead, $leadPayload);
        } catch (Throwable $error) {
            pilot_status_log('Erro Meta CAPI webhook Pilot Status Lead.', [
                'lead_id' => (string) $lead['id'],
                'error' => $error->getMessage(),
            ]);
            $metaResult = ['ok' => false, 'error' => 'Meta CAPI falhou.'];
        }

        try {
            $whatsappResult = crm_whatsapp_send_lead_notification($lead);
        } catch (Throwable $error) {
            crm_update_whatsapp_status((string) $lead['id'], 'falhou', 'Erro ao enviar WhatsApp: ' . $error->getMessage());
            pilot_status_log('Erro notificação WhatsApp webhook Pilot Status Lead.', [
                'lead_id' => (string) $lead['id'],
                'error' => $error->getMessage(),
            ]);
            $whatsappResult = ['ok' => false, 'error' => 'Notificação interna falhou.'];
        }

        try {
            $emailResult = crm_send_lead_email_notification($lead);

            if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
                pilot_status_log('Erro e-mail webhook Pilot Status Lead.', [
                    'lead_id' => (string) $lead['id'],
                    'error' => (string) ($emailResult['error'] ?? 'Erro desconhecido.'),
                ]);
            }
        } catch (Throwable $error) {
            pilot_status_log('Erro e-mail webhook Pilot Status Lead.', [
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
        'whatsapp' => $whatsappResult,
        'email' => $emailResult,
        'meta' => $metaResult,
    ];
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'processed' => count($results),
    'results' => $results,
]);
