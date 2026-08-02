<?php

declare(strict_types=1);

require_once __DIR__ . '/meta-whatsapp.php';
require_once __DIR__ . '/pilot-status.php';
require_once __DIR__ . '/settings.php';

function crm_whatsapp_provider_label(?string $provider = null): string
{
    $provider = $provider ?? crm_whatsapp_provider();

    return match ($provider) {
        'meta_cloud' => 'Meta Cloud API',
        'pilot_status' => 'Pilot Status',
        default => 'Pilot Status',
    };
}

function crm_whatsapp_send_text(string $number, string $text, bool $usePresence = true): array
{
    if (crm_whatsapp_provider() === 'meta_cloud') {
        return meta_whatsapp_send_text($number, $text);
    }

    return pilot_status_send_text($number, $text);
}

function crm_whatsapp_send_media(
    string $number,
    string $filePath,
    string $mimeType,
    string $mediaType,
    string $caption = '',
    string $fileName = ''
): array {
    if (crm_whatsapp_provider() === 'meta_cloud') {
        return meta_whatsapp_send_media($number, $filePath, $mimeType, $mediaType, $caption, $fileName);
    }

    return pilot_status_send_media($number, $filePath, $mimeType, $mediaType, $caption, $fileName);
}

function crm_whatsapp_send_followup(array $queueItem): array
{
    $senderName = trim((string) ($queueItem['assigned_user_name'] ?? ''));

    if ($senderName === '') {
        $senderName = trim((string) ($queueItem['assigned_username'] ?? ''));
    }

    if ($senderName !== '') {
        $queueItem['message'] = $senderName . ", disse:\n" . ltrim((string) ($queueItem['message'] ?? ''));
    }

    if (crm_whatsapp_provider() === 'meta_cloud') {
        return meta_whatsapp_send_followup($queueItem);
    }

    if (crm_whatsapp_provider() === 'pilot_status') {
        return pilot_status_send_followup($queueItem);
    }

    return pilot_status_send_followup($queueItem);
}
