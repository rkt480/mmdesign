<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/pilot-status.php';
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

$delivery = pilot_status_extract_delivery_event($payload);

if ($delivery['event'] !== '') {
    $messageId = (string) $delivery['id'];
    $lead = $messageId !== '' ? crm_find_lead_by_pilot_status_message_id($messageId) : null;

    // Official Meta status payloads identify the message with a WhatsApp
    // wamid, while the CRM stores Pilot Status' internal message ID. When the
    // IDs differ, the recipient lets us still update the affected conversation.
    if ($lead === null && trim((string) ($delivery['destination'] ?? '')) !== '') {
        $lead = crm_find_lead_by_whatsapp((string) $delivery['destination']);
    }

    if ($lead === null) {
        pilot_status_log('Evento de entrega sem lead correspondente.', ['delivery' => $delivery]);
        echo json_encode(['ok' => true, 'processed' => false, 'reason' => 'Mensagem não vinculada ao CRM.']);
        exit;
    }

    $notes = (string) ($lead['notes'] ?? '');
    $eventMarker = 'Pilot Status evento: ' . $delivery['event'] . ' | ID: ' . $messageId;

    if (str_contains($notes, $eventMarker)) {
        echo json_encode(['ok' => true, 'processed' => false, 'duplicate' => true]);
        exit;
    }

    $statusMap = [
        'message.sent' => 'enviado',
        'message.delivered' => 'entregue',
        'message.read' => 'lido',
        'message.failed' => 'falhou',
    ];
    $status = $statusMap[$delivery['event']];
    $statusText = match ($delivery['event']) {
        'message.sent' => 'Pilot Status confirmou o envio ao WhatsApp.',
        'message.delivered' => 'WhatsApp confirmou a entrega ao destinatário.',
        'message.read' => 'WhatsApp confirmou a leitura pelo destinatário.',
        'message.failed' => 'Pilot Status confirmou falha na entrega' . ($delivery['error'] !== '' ? ': ' . $delivery['error'] : '.'),
    };

    crm_update_whatsapp_status((string) $lead['id'], $status, $delivery['event'] === 'message.failed' ? $delivery['error'] : null);
    crm_append_lead_note(
        (string) $lead['id'],
        $eventMarker . ' em ' . date('d/m/Y H:i:s') . ":\n" . $statusText
    );

    echo json_encode(['ok' => true, 'processed' => true, 'lead_id' => $lead['id'], 'status' => $status]);
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
    $media = is_array($incoming['media'] ?? null) ? $incoming['media'] : [];
    $mediaUrl = trim((string) ($media['url'] ?? ''));
    $incomingMessageId = trim((string) ($incoming['id'] ?? ''));
    $incomingMediaId = trim((string) ($media['id'] ?? ''));
    $name = trim((string) ($incoming['name'] ?? ''));
    $profilePictureUrl = crm_normalize_profile_picture_url((string) ($incoming['profile_picture_url'] ?? ''));

    if ($mediaUrl !== '' && ($media['temporary_url'] ?? false) === true) {
        $downloadedMedia = pilot_status_download_inbound_media(
            (string) ($media['id'] ?? ''),
            (string) ($media['phone_number_id'] ?? '')
        );

        $storedMedia = ($downloadedMedia['ok'] ?? false) === true
            ? pilot_status_store_inbound_media_data_uri(
                (string) ($downloadedMedia['base64'] ?? ''),
                (string) ($downloadedMedia['mime_type'] ?? ($media['mime_type'] ?? '')),
                (string) ($downloadedMedia['filename'] ?? ($media['filename'] ?? ''))
            )
            : $downloadedMedia;

        if (($storedMedia['ok'] ?? false) === true) {
            $mediaUrl = (string) ($storedMedia['url'] ?? '');
            $media['mime_type'] = (string) ($storedMedia['mime_type'] ?? ($media['mime_type'] ?? ''));
            $media['filename'] = (string) ($storedMedia['filename'] ?? ($media['filename'] ?? ''));
        } else {
            pilot_status_log('Falha ao baixar mídia nativa recebida.', [
                'message_id' => (string) ($incoming['id'] ?? ''),
                'type' => (string) ($media['type'] ?? ''),
                'error' => (string) ($storedMedia['error'] ?? 'Erro desconhecido.'),
            ]);
        }
    }

    if ($whatsapp === '' || ($message === '' && $mediaUrl === '')) {
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
        // Media is stored as a structured conversation note below. Keeping
        // it out of the lead's initial text lets the chat render the actual
        // image, audio player or file link instead of a plain placeholder.
        'message' => $mediaUrl === '' ? $message : '',
        'page' => 'Pilot Status',
        'utm_source' => 'pilot_status',
        'utm_medium' => 'whatsapp',
        'utm_campaign' => 'mensagem_recebida',
        'landing_path' => 'crm/api/pilot-status-webhook.php',
    ];

    try {
        $leadResult = crm_create_lead_once($leadPayload);
        $lead = $leadResult['lead'];

        if ($profilePictureUrl !== '' && $profilePictureUrl !== (string) ($lead['profile_picture_url'] ?? '')) {
            crm_update_lead_profile_picture((string) $lead['id'], $profilePictureUrl);
        }

        if ($mediaUrl !== '') {
            $dedupeMarker = $incomingMessageId !== ''
                ? '"crm_message_id":' . json_encode($incomingMessageId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : ($incomingMediaId !== ''
                    ? '"id":' . json_encode($incomingMediaId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '');

            if ($dedupeMarker !== '' && str_contains((string) ($lead['notes'] ?? ''), $dedupeMarker)) {
                $results[] = [
                    'ok' => true,
                    'whatsapp' => $whatsapp,
                    'lead_id' => $lead['id'],
                    'duplicate' => true,
                ];
                continue;
            }

            $media['url'] = $mediaUrl;
            $media['type'] = trim((string) ($media['type'] ?? ''));
            $media['caption'] = trim((string) ($media['caption'] ?? '')) ?: $message;
            $media['mime_type'] = trim((string) ($media['mime_type'] ?? ''));
            $media['filename'] = trim((string) ($media['filename'] ?? ''));

            if ($incomingMessageId !== '') {
                $media['crm_message_id'] = $incomingMessageId;
            }

            $mediaJson = json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($mediaJson) && $mediaJson !== '') {
                crm_append_lead_note(
                    (string) $lead['id'],
                    'Mídia recebida pela Pilot Status em ' . date('d/m/Y H:i:s') . ":\n[crm_media]" . $mediaJson
                );
            }
        } elseif (($leadResult['created'] ?? false) === false && $message !== '') {
            crm_append_lead_note(
                (string) $lead['id'],
                'Mensagem recebida pela Pilot Status em ' . date('d/m/Y H:i:s') . ":\n" . $message
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
