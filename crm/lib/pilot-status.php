<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lead-notification.php';

function pilot_status_settings(): array
{
    return crm_pilot_status_settings();
}

function pilot_status_is_configured(): bool
{
    return crm_pilot_status_is_configured();
}

function pilot_status_log(string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    @file_put_contents($dir . '/pilot-status.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function pilot_status_request(string $endpoint, array $payload, int $timeout = 20): array
{
    $settings = pilot_status_settings();
    $apiKey = trim((string) $settings['api_key']);

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'API key da Pilot Status não configurada.'];
    }

    $url = rtrim((string) $settings['base_url'], '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'error' => 'Não foi possível iniciar o cURL da Pilot Status.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['ok' => false, 'error' => $curlError ?: 'Erro desconhecido no cURL da Pilot Status.'];
    }

    $decoded = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        pilot_status_log('Pilot Status erro HTTP.', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => $decoded,
        ]);

        return ['ok' => false, 'error' => 'Pilot Status HTTP ' . $httpCode . ': ' . $body, 'response' => $decoded];
    }

    return ['ok' => true, 'response' => is_array($decoded) ? $decoded : $body];
}

function pilot_status_send_text(string $number, string $text): array
{
    $to = crm_normalize_whatsapp_number($number);

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    return pilot_status_request('/messages/send', [
        'destinationNumber' => $to,
        'text' => $text,
    ]);
}

function pilot_status_send_media(
    string $number,
    string $filePath,
    string $mimeType,
    string $mediaType,
    string $caption = '',
    string $fileName = ''
): array {
    $to = crm_normalize_whatsapp_number($number);

    if ($to === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    if (!in_array($mediaType, ['image', 'audio', 'document'], true)) {
        return ['ok' => false, 'error' => 'Tipo de mídia não suportado.'];
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'error' => 'Arquivo de mídia indisponível para envio.'];
    }

    $contents = file_get_contents($filePath);

    if ($contents === false || $contents === '') {
        return ['ok' => false, 'error' => 'Não foi possível ler o arquivo de mídia.'];
    }

    $payload = [
        'destinationNumber' => $to,
        'media' => 'data:' . $mimeType . ';base64,' . base64_encode($contents),
        'mediaType' => $mediaType,
    ];

    if (trim($caption) !== '') {
        $payload['caption'] = trim($caption);
    }

    if ($mediaType === 'document' && trim($fileName) !== '') {
        $payload['fileName'] = trim($fileName);
    }

    return pilot_status_request('/messages/send', $payload, 60);
}

function pilot_status_render_internal_notification(array $lead): string
{
    $config = [];
    $configFile = dirname(__DIR__) . '/config.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        $config = is_array($loaded) ? ($loaded['whatsapp'] ?? []) : [];
    }

    return crm_render_lead_notification($lead, (string) ($config['internal_notification_message'] ?? ''));
}

function pilot_status_render_custom_message(string $message, array $lead): string
{
    $replacements = [
        '{{name}}' => (string) ($lead['name'] ?? ''),
        '{{company}}' => (string) ($lead['company'] ?? ''),
        '{{segment}}' => (string) ($lead['segment'] ?? ''),
    ];

    return strtr($message, $replacements);
}

function pilot_status_send_lead_notification(array $lead): array
{
    if (!pilot_status_is_configured()) {
        crm_update_whatsapp_status((string) $lead['id'], 'nao_configurado', 'Pilot Status ainda não configurada.');
        return ['ok' => false, 'error' => 'Pilot Status ainda não configurada.'];
    }

    $number = crm_whatsapp_number();

    if ($number === '') {
        crm_update_whatsapp_status((string) $lead['id'], 'notifica_sem_numero', 'Número interno do WhatsApp não configurado.');
        return ['ok' => false, 'error' => 'Número interno do WhatsApp não configurado.'];
    }

    $result = pilot_status_send_text($number, pilot_status_render_internal_notification($lead));

    if (($result['ok'] ?? false) === true) {
        crm_update_whatsapp_status((string) $lead['id'], 'notifica_enviada');
        return $result;
    }

    crm_update_whatsapp_status((string) $lead['id'], 'notifica_falhou', (string) ($result['error'] ?? 'Falha ao enviar.'));
    return $result;
}

function pilot_status_send_followup(array $queueItem): array
{
    if (!pilot_status_is_configured()) {
        return ['ok' => false, 'error' => 'Pilot Status ainda não configurada.'];
    }

    $number = crm_normalize_whatsapp_number((string) ($queueItem['whatsapp'] ?? ''));

    if ($number === '') {
        return ['ok' => false, 'error' => 'WhatsApp inválido.'];
    }

    return pilot_status_send_text($number, pilot_status_render_custom_message((string) $queueItem['message'], $queueItem));
}

function pilot_status_read_path(array $payload, array $path): mixed
{
    $value = $payload;

    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

function pilot_status_first_payload_value(array $payload, array $paths): string
{
    foreach ($paths as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (is_scalar($value)) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function pilot_status_normalize_phone_candidate(string $phone): string
{
    $phone = preg_replace('/@.+$/', '', trim($phone)) ?? '';
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $length = strlen($digits);

    if ($length === 10 || $length === 11) {
        return '55' . $digits;
    }

    if (($length === 12 || $length === 13) && str_starts_with($digits, '55')) {
        return $digits;
    }

    return '';
}

function pilot_status_collect_phone_candidates(mixed $value, string $key = ''): array
{
    $candidates = [];

    if (is_array($value)) {
        foreach ($value as $childKey => $childValue) {
            $childKey = is_scalar($childKey) ? (string) $childKey : '';
            array_push($candidates, ...pilot_status_collect_phone_candidates($childValue, $childKey));
        }

        return $candidates;
    }

    if (!is_scalar($value)) {
        return [];
    }

    $raw = trim((string) $value);
    $number = pilot_status_normalize_phone_candidate($raw);

    if ($number === '') {
        return [];
    }

    $score = 0;
    $lowerKey = strtolower($key);
    $lowerRaw = strtolower($raw);

    if (str_contains($lowerRaw, '@s.whatsapp.net') || str_contains($lowerRaw, '@c.us')) {
        $score += 100;
    }

    foreach (['from', 'sender', 'contact', 'number', 'phone', 'whatsapp', 'destinationnumber'] as $hint) {
        if (str_contains($lowerKey, $hint)) {
            $score += 20;
            break;
        }
    }

    if (str_contains($lowerKey, 'to') || str_contains($lowerKey, 'destination')) {
        $score -= 20;
    }

    return [[
        'raw' => $raw,
        'number' => $number,
        'is_group' => str_contains($lowerRaw, '@g.us'),
        'score' => $score,
    ]];
}

function pilot_status_payload_is_from_me(array $payload): bool
{
    foreach ([['fromMe'], ['from_me'], ['direction'], ['message', 'fromMe'], ['message', 'direction'], ['data', 'fromMe'], ['data', 'direction'], ['data', 'message', 'fromMe'], ['data', 'message', 'direction']] as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            $normalized = strtolower(trim((string) $value));

            if (in_array($normalized, ['1', 'true', 'yes', 'sim', 'outbound', 'outgoing', 'sent'], true)) {
                return true;
            }
        }
    }

    return false;
}

function pilot_status_extract_text(array $payload): string
{
    return pilot_status_first_payload_value($payload, [
        ['text'],
        ['body'],
        ['message'],
        ['content'],
        ['message', 'text'],
        ['message', 'body'],
        ['message', 'content'],
        ['message', 'conversation'],
        ['message', 'text', 'body'],
        ['data', 'text'],
        ['data', 'body'],
        ['data', 'message'],
        ['data', 'content'],
        ['data', 'message', 'text'],
        ['data', 'message', 'body'],
        ['data', 'message', 'content'],
        ['data', 'message', 'conversation'],
        ['payload', 'text'],
        ['payload', 'body'],
        ['payload', 'message', 'text'],
        ['payload', 'message', 'body'],
    ]);
}

function pilot_status_extract_name(array $payload): string
{
    return pilot_status_first_payload_value($payload, [
        ['name'],
        ['pushName'],
        ['senderName'],
        ['contactName'],
        ['contact', 'name'],
        ['contact', 'pushName'],
        ['message', 'pushName'],
        ['message', 'senderName'],
        ['data', 'name'],
        ['data', 'pushName'],
        ['data', 'senderName'],
        ['data', 'contact', 'name'],
    ]);
}

function pilot_status_extract_single_incoming_message(array $payload): array
{
    $phone = [
        'raw' => '',
        'number' => '',
        'is_group' => false,
    ];

    foreach ([
        ['from'],
        ['sender'],
        ['number'],
        ['phone'],
        ['whatsapp'],
        ['contact', 'number'],
        ['contact', 'phone'],
        ['message', 'from'],
        ['message', 'sender'],
        ['message', 'number'],
        ['message', 'remoteJid'],
        ['data', 'from'],
        ['data', 'sender'],
        ['data', 'number'],
        ['data', 'phone'],
        ['data', 'remoteJid'],
        ['data', 'contact', 'number'],
        ['data', 'contact', 'phone'],
        ['data', 'message', 'from'],
        ['data', 'message', 'sender'],
        ['data', 'message', 'remoteJid'],
    ] as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (!is_scalar($value)) {
            continue;
        }

        $raw = trim((string) $value);
        $number = pilot_status_normalize_phone_candidate($raw);

        if ($number !== '') {
            $phone = [
                'raw' => $raw,
                'number' => $number,
                'is_group' => str_contains(strtolower($raw), '@g.us'),
            ];
            break;
        }
    }

    if ($phone['number'] === '') {
        $candidates = pilot_status_collect_phone_candidates($payload);

        if ($candidates !== []) {
            usort($candidates, fn(array $a, array $b): int => ($b['score'] <=> $a['score']));
            $phone = [
                'raw' => (string) $candidates[0]['raw'],
                'number' => (string) $candidates[0]['number'],
                'is_group' => (bool) $candidates[0]['is_group'],
            ];
        }
    }

    return [
        'id' => pilot_status_first_payload_value($payload, [['id'], ['messageId'], ['message_id'], ['message', 'id'], ['data', 'id'], ['data', 'messageId'], ['data', 'message', 'id']]),
        'raw_number' => $phone['raw'],
        'number' => $phone['number'],
        'text' => pilot_status_extract_text($payload),
        'name' => pilot_status_extract_name($payload),
        'from_me' => pilot_status_payload_is_from_me($payload),
        'is_group' => (bool) $phone['is_group'],
        'event' => pilot_status_first_payload_value($payload, [['event'], ['type'], ['eventType']]),
    ];
}

function pilot_status_extract_incoming_messages(array $payload): array
{
    $items = [];

    foreach ([['messages'], ['data', 'messages'], ['payload', 'messages'], ['events']] as $path) {
        $value = pilot_status_read_path($payload, $path);

        if (!is_array($value)) {
            continue;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
    }

    if ($items === []) {
        $items[] = $payload;
    }

    $messages = [];

    foreach ($items as $item) {
        $incoming = pilot_status_extract_single_incoming_message($item);
        $event = strtolower((string) ($incoming['event'] ?: pilot_status_first_payload_value($payload, [['event'], ['type'], ['eventType']])));

        if ($event !== '' && !str_contains($event, 'received') && !str_contains($event, 'incoming') && !str_contains($event, 'reply')) {
            continue;
        }

        if (($incoming['from_me'] ?? false) === true || ($incoming['is_group'] ?? false) === true || (string) ($incoming['number'] ?? '') === '') {
            continue;
        }

        $messages[] = $incoming;
    }

    return $messages;
}

function pilot_status_validate_webhook(string $body, array $payload): bool
{
    $settings = pilot_status_settings();
    $secret = trim((string) $settings['webhook_secret']);

    if ($secret === '') {
        return true;
    }

    $plainCandidates = [
        (string) ($_GET['token'] ?? ''),
        (string) ($_SERVER['HTTP_X_PILOT_STATUS_TOKEN'] ?? ''),
        (string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''),
        (string) ($payload['token'] ?? ''),
        (string) ($payload['secret'] ?? ''),
    ];

    foreach ($plainCandidates as $candidate) {
        $candidate = trim($candidate);

        if ($candidate !== '' && hash_equals($secret, $candidate)) {
            return true;
        }
    }

    $signatureCandidates = [
        (string) ($_SERVER['HTTP_X_PILOT_STATUS_SIGNATURE'] ?? ''),
        (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''),
        (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
    ];

    $hashes = [
        hash_hmac('sha256', $body, $secret),
        'sha256=' . hash_hmac('sha256', $body, $secret),
    ];

    foreach ($signatureCandidates as $signature) {
        $signature = trim($signature);

        foreach ($hashes as $hash) {
            if ($signature !== '' && hash_equals($hash, $signature)) {
                return true;
            }
        }
    }

    return false;
}
