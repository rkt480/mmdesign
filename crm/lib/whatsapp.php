<?php

declare(strict_types=1);

require_once __DIR__ . '/meta-whatsapp.php';
require_once __DIR__ . '/pilot-status.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/whatsapp-templates.php';

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
    $templateId = (int) ($queueItem['template_id'] ?? 0);

    if ($templateId > 0) {
        $template = crm_find_whatsapp_template($templateId);

        if (!is_array($template) || !crm_whatsapp_template_is_sendable($template)) {
            return ['ok' => false, 'error' => 'A etapa do follow-up depende de um template aprovado e ativo.'];
        }

        $variables = crm_whatsapp_followup_template_variables($queueItem, $template);

        if (!$variables['ok']) {
            return $variables;
        }

        $number = crm_normalize_whatsapp_number((string) ($queueItem['whatsapp'] ?? ''));

        if ($number === '') {
            return ['ok' => false, 'error' => 'WhatsApp inválido.'];
        }

        $providerVariables = $variables['values'];

        return crm_whatsapp_provider() === 'pilot_status'
            ? pilot_status_send_template($number, $template, $providerVariables)
            : meta_whatsapp_send_template($number, $template, array_values($providerVariables));
    }

    $legacyLead = [
        'created_at' => (string) ($queueItem['lead_created_at'] ?? ''),
        'message' => (string) ($queueItem['lead_message'] ?? ''),
        'notes' => (string) ($queueItem['lead_notes'] ?? ''),
    ];

    if (!crm_whatsapp_is_in_24h_window($legacyLead)) {
        return ['ok' => false, 'error' => 'Esta etapa usa mensagem livre, mas a janela de 24 horas está encerrada. Selecione um template aprovado.'];
    }

    $senderName = trim((string) ($queueItem['assigned_user_name'] ?? ''));

    if ($senderName === '') {
        $senderName = trim((string) ($queueItem['assigned_username'] ?? ''));
    }

    if ($senderName !== '') {
        $queueItem['message'] = '*' . $senderName . ", disse:*\n" . ltrim((string) ($queueItem['message'] ?? ''));
    }

    if (crm_whatsapp_provider() === 'meta_cloud') {
        return meta_whatsapp_send_followup($queueItem);
    }

    if (crm_whatsapp_provider() === 'pilot_status') {
        return pilot_status_send_followup($queueItem);
    }

    return pilot_status_send_followup($queueItem);
}

function crm_whatsapp_followup_template_variables(array $queueItem, array $template): array
{
    $mapping = json_decode((string) ($queueItem['variable_mapping'] ?? ''), true);
    $mapping = is_array($mapping) ? $mapping : [];
    $templateVariables = crm_whatsapp_template_variable_keys((string) ($template['body_text'] ?? ''));
    $leadValues = [
        'name' => (string) ($queueItem['name'] ?? ''),
        'company' => (string) ($queueItem['company'] ?? ''),
        'segment' => (string) ($queueItem['segment'] ?? ''),
        'message' => (string) ($queueItem['lead_message'] ?? ''),
        'whatsapp' => (string) ($queueItem['whatsapp'] ?? ''),
    ];
    $values = [];

    foreach ($templateVariables as $index => $variable) {
        $field = trim((string) ($mapping[$variable] ?? ''));

        if ($field === '') {
            $field = match (strtolower($variable)) {
                'name', 'nome' => 'name',
                'company', 'empresa' => 'company',
                'segment', 'segmento' => 'segment',
                'message', 'mensagem' => 'message',
                'whatsapp', 'phone', 'telefone' => 'whatsapp',
                default => ['name', 'company', 'segment', 'message'][$index] ?? 'name',
            };
        }

        if (!array_key_exists($field, $leadValues)) {
            return ['ok' => false, 'error' => 'A variável {{' . $variable . '}} não está mapeada para um campo válido do lead.'];
        }

        $value = trim($leadValues[$field]);

        if ($value === '') {
            return ['ok' => false, 'error' => 'O campo ' . $field . ' está vazio para preencher a variável {{' . $variable . '}}.'];
        }

        $values[$variable] = $value;
    }

    return ['ok' => true, 'values' => $values];
}
