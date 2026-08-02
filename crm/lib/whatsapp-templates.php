<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/meta-whatsapp.php';

function crm_whatsapp_template_variables(string $text): array
{
    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);
    $variables = array_map('intval', $matches[1] ?? []);
    $variables = array_values(array_unique(array_filter($variables, static fn(int $value): bool => $value > 0)));
    sort($variables);

    return $variables;
}

function crm_whatsapp_template_render(string $text, array $values, array $lead = []): string
{
    $rendered = strtr($text, [
        '{{name}}' => (string) ($lead['name'] ?? ''),
        '{{company}}' => (string) ($lead['company'] ?? ''),
        '{{segment}}' => (string) ($lead['segment'] ?? ''),
    ]);

    return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', static function (array $match) use ($values): string {
        $key = (string) ((int) ($match[1] ?? 0));
        return trim((string) ($values[$key] ?? ''));
    }, $rendered) ?? $rendered;
}

function crm_whatsapp_last_incoming_at(array $lead): ?int
{
    $timestamps = [];
    $createdAt = strtotime((string) ($lead['created_at'] ?? ''));

    if (trim((string) ($lead['message'] ?? '')) !== '' && $createdAt !== false) {
        $timestamps[] = $createdAt;
    }

    $notes = (string) ($lead['notes'] ?? '');
    preg_match_all(
        '/Mensagem recebida(?: pelo provedor anterior| pela Meta Cloud API| pela Pilot Status)? em (\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}):/u',
        $notes,
        $matches
    );

    foreach ($matches[1] ?? [] as $date) {
        $timestamp = DateTime::createFromFormat('d/m/Y H:i', (string) $date);

        if ($timestamp instanceof DateTime) {
            $timestamps[] = $timestamp->getTimestamp();
        }
    }

    if ($timestamps === []) {
        return null;
    }

    return max($timestamps);
}

function crm_whatsapp_is_in_24h_window(array $lead): bool
{
    $lastIncoming = crm_whatsapp_last_incoming_at($lead);

    return $lastIncoming !== null && (time() - $lastIncoming) < 86400;
}

function crm_whatsapp_window_label(array $lead): string
{
    $lastIncoming = crm_whatsapp_last_incoming_at($lead);

    if ($lastIncoming === null) {
        return 'Aguardando uma mensagem do contato';
    }

    $remaining = max(0, 86400 - (time() - $lastIncoming));
    $hours = intdiv($remaining, 3600);
    $minutes = intdiv($remaining % 3600, 60);

    return $remaining > 0
        ? 'Janela aberta · restam ' . $hours . 'h ' . $minutes . 'min'
        : 'Janela encerrada · use um template aprovado';
}

function crm_whatsapp_template_is_sendable(array $template): bool
{
    return (int) ($template['active'] ?? 0) === 1
        && strtolower((string) ($template['meta_status'] ?? '')) === 'approved'
        && trim((string) ($template['name'] ?? '')) !== '';
}

