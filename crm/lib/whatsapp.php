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

function crm_whatsapp_reply_context_note(array $context): string
{
    $stored = [];

    foreach (['id', 'text', 'type', 'url'] as $key) {
        $value = trim((string) ($context[$key] ?? ''));

        if ($value !== '') {
            $stored[$key] = $value;
        }
    }

    if ($stored === []) {
        return '';
    }

    $json = json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) && $json !== '' ? "\n[crm_reply]" . $json : '';
}

function crm_whatsapp_response_message_ids(array $result): array
{
    $response = $result['response'] ?? null;
    $paths = [
        ['id'],
        ['message_id'],
        ['messageId'],
        ['messages', 0, 'id'],
        ['data', 'id'],
        ['data', 'messages', 0, 'id'],
        ['key', 'id'],
        ['messages', 0, 'message_id'],
        ['messages', 0, 'messageId'],
        ['messages', 0, 'key', 'id'],
        ['data', 'message_id'],
        ['data', 'messageId'],
        ['data', 'key', 'id'],
        ['data', 'messages', 0, 'message_id'],
        ['data', 'messages', 0, 'messageId'],
        ['data', 'messages', 0, 'key', 'id'],
        ['message', 'id'],
        ['message', 'message_id'],
        ['message', 'messageId'],
        ['message', 'key', 'id'],
        ['data', 'message', 'id'],
        ['data', 'message', 'message_id'],
        ['data', 'message', 'messageId'],
        ['data', 'message', 'key', 'id'],
        ['result', 'id'],
        ['result', 'message_id'],
        ['result', 'messageId'],
        ['result', 'message', 'id'],
        ['data', 'result', 'id'],
        ['data', 'result', 'message_id'],
        ['data', 'result', 'messageId'],
        ['data', 'result', 'message', 'id'],
    ];

    if (!is_array($response)) {
        return [];
    }

    $messageIds = [];

    foreach ($paths as $path) {
        $candidate = $response;

        foreach ($path as $segment) {
            if (!is_array($candidate) || !array_key_exists($segment, $candidate)) {
                $candidate = null;
                break;
            }

            $candidate = $candidate[$segment];
        }

        if (!is_scalar($candidate)) {
            continue;
        }

        $messageId = trim((string) $candidate);

        if ($messageId !== '' && !in_array($messageId, $messageIds, true)) {
            $messageIds[] = $messageId;
        }
    }

    return $messageIds;
}

function crm_whatsapp_response_message_id(array $result): string
{
    return crm_whatsapp_response_message_ids($result)[0] ?? '';
}

function crm_whatsapp_after_hours_settings(): array
{
    $settings = crm_read_settings();

    return [
        'enabled' => !empty($settings['whatsapp_after_hours_enabled']),
        'business_start_time' => crm_normalize_whatsapp_business_time(
            (string) ($settings['whatsapp_business_start_time'] ?? ''),
            '08:00:00'
        ),
        'business_end_time' => crm_normalize_whatsapp_business_time(
            (string) ($settings['whatsapp_business_end_time'] ?? ''),
            '18:00:00'
        ),
        'saturday_enabled' => !empty($settings['whatsapp_business_saturday_enabled']),
        'sunday_enabled' => !empty($settings['whatsapp_business_sunday_enabled']),
        'message' => trim((string) ($settings['whatsapp_after_hours_message'] ?? ''))
            ?: 'Olá! Nosso atendimento funciona em horário comercial. Recebemos sua mensagem e retornaremos assim que possível.',
    ];
}

function crm_whatsapp_is_outside_business_hours(?DateTimeImmutable $now = null): bool
{
    $afterHours = crm_whatsapp_after_hours_settings();

    if (!$afterHours['enabled']) {
        return false;
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $dayOfWeek = (int) $now->format('N');

    if ($dayOfWeek === 6 && !$afterHours['saturday_enabled']) {
        return true;
    }

    if ($dayOfWeek === 7 && !$afterHours['sunday_enabled']) {
        return true;
    }

    $currentTime = $now->format('H:i:s');

    return $currentTime < $afterHours['business_start_time']
        || $currentTime >= $afterHours['business_end_time'];
}

function crm_whatsapp_after_hours_reply_was_sent(array $lead, string $incomingMessageId): bool
{
    $incomingMessageId = trim($incomingMessageId);
    $notes = (string) ($lead['notes'] ?? '');

    if ($incomingMessageId !== '' && str_contains($notes, 'CRM gatilho de horário: ' . $incomingMessageId)) {
        return true;
    }

    return preg_match(
        '/Resposta automática fora do horário enviada via .* em '
        . preg_quote(date('d/m/Y'), '/')
        . ' \d{2}:\d{2}:\d{2}/u',
        $notes
    ) === 1;
}

function crm_whatsapp_send_after_hours_reply(
    array $lead,
    string $incomingMessageId,
    ?string $provider = null
): array {
    $afterHours = crm_whatsapp_after_hours_settings();

    if (!$afterHours['enabled']) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'Resposta fora do horário desativada.'];
    }

    if (!crm_whatsapp_is_outside_business_hours()) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'Dentro do horário comercial.'];
    }

    $incomingMessageId = trim($incomingMessageId);

    $number = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));

    if ($number === '') {
        return ['ok' => false, 'error' => 'Este contato não tem WhatsApp válido para resposta automática.'];
    }

    $provider = $provider ?? crm_whatsapp_provider();
    $leadId = trim((string) ($lead['id'] ?? ''));
    $lockName = 'crm_after_hours_reply_' . substr(hash('sha256', $leadId . date('Y-m-d')), 0, 40);
    $lockStmt = crm_db()->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $lockStmt->execute(['lock_name' => $lockName]);

    if ((int) $lockStmt->fetchColumn() !== 1) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'Outra resposta automática está sendo processada.'];
    }

    try {
        $freshLead = $leadId !== '' ? crm_find_lead($leadId, false) : null;
        $leadForCheck = is_array($freshLead) ? $freshLead : $lead;

        if (crm_whatsapp_after_hours_reply_was_sent($leadForCheck, $incomingMessageId)) {
            return ['ok' => true, 'skipped' => true, 'duplicate' => true, 'reason' => 'Resposta automática já enviada neste período fora do horário.'];
        }

        $result = $provider === 'meta_cloud'
            ? meta_whatsapp_send_text($number, $afterHours['message'])
            : pilot_status_send_text($number, $afterHours['message']);

        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        $messageIdMarker = $incomingMessageId !== ''
            ? "\nCRM gatilho de horário: " . $incomingMessageId
            : '';
        $sentMessageId = crm_whatsapp_response_message_id($result);

        if ($sentMessageId !== '') {
            $messageIdMarker .= "\nCRM message ID: " . $sentMessageId;
        }
        $queuedLabel = $provider === 'pilot_status' ? ' (aceita pelo provedor)' : '';
        $note = 'Resposta automática fora do horário enviada via '
            . crm_whatsapp_provider_label($provider)
            . $queuedLabel
            . ' em ' . date('d/m/Y H:i:s') . ":\n"
            . $afterHours['message']
            . $messageIdMarker;

        crm_append_lead_note($leadId, $note);
        crm_update_whatsapp_status($leadId, $provider === 'pilot_status' ? 'aguardando' : 'enviado');

        return $result + ['automatic' => true];
    } finally {
        $unlockStmt = crm_db()->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $unlockStmt->execute(['lock_name' => $lockName]);
    }
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

        $result = crm_whatsapp_provider() === 'pilot_status'
            ? pilot_status_send_template($number, $template, $providerVariables)
            : meta_whatsapp_send_template($number, $template, array_values($providerVariables));

        if (($result['ok'] ?? false) === true) {
            crm_record_followup_template_send($queueItem, $template, $providerVariables, $result);
        }

        return $result;
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

    $result = crm_whatsapp_provider() === 'meta_cloud'
        ? meta_whatsapp_send_followup($queueItem)
        : pilot_status_send_followup($queueItem);

    if (($result['ok'] ?? false) === true) {
        crm_record_followup_text_send($queueItem, $result);
    }

    return $result;
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
        'seller' => crm_whatsapp_followup_seller_name($queueItem),
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
                'seller', 'vendedor', 'atendente' => 'seller',
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

function crm_whatsapp_followup_seller_name(array $queueItem): string
{
    $seller = trim((string) ($queueItem['assigned_user_name'] ?? ''));

    if ($seller === '') {
        $seller = trim((string) ($queueItem['assigned_username'] ?? ''));
    }

    return $seller !== '' ? $seller : 'Equipe comercial';
}

function crm_record_followup_template_send(array $queueItem, array $template, array $providerVariables, array $result = []): void
{
    $provider = crm_whatsapp_provider();
    $providerLabel = crm_whatsapp_provider_label($provider);
    $renderContext = $queueItem + [
        'seller' => crm_whatsapp_followup_seller_name($queueItem),
    ];
    $renderedBody = crm_whatsapp_template_render(
        (string) ($template['body_text'] ?? ''),
        $providerVariables,
        $renderContext
    );
    $seller = crm_whatsapp_followup_seller_name($queueItem);
    $note = 'Template "' . (string) ($template['name'] ?? 'sem nome') . '" enviado via ' . $providerLabel . ' em ' . date('d/m/Y H:i:s') . ":\n"
        . 'Follow-up · vendedor: ' . $seller . "\n"
        . $renderedBody;
    $sentMessageId = crm_whatsapp_response_message_id($result);

    if ($sentMessageId !== '') {
        $note .= "\nCRM message ID: " . $sentMessageId;
    }

    try {
        crm_append_lead_note((string) ($queueItem['lead_id'] ?? ''), $note);
        crm_update_whatsapp_status((string) ($queueItem['lead_id'] ?? ''), $provider === 'pilot_status' ? 'aguardando' : 'enviado');
    } catch (Throwable $error) {
        error_log('Follow-up enviado, mas não foi possível registrar o histórico do lead: ' . $error->getMessage());
    }
}

function crm_whatsapp_send_rd_lead_template(array $lead, int $templateId): array
{
    $leadId = trim((string) ($lead['id'] ?? ''));
    if ($leadId === '' || $templateId <= 0) {
        return ['ok' => false, 'error' => 'Lead ou template automático do RD inválido.'];
    }

    $provider = crm_whatsapp_provider();
    $providerLabel = crm_whatsapp_provider_label($provider);
    $template = crm_find_whatsapp_template($templateId);
    $templateName = trim((string) ($template['name'] ?? 'template configurado'));

    $recordFailure = static function (string $error) use ($leadId, $templateName, $providerLabel): array {
        $note = 'Falha no envio automático do template RD "' . $templateName . '" via ' . $providerLabel
            . ' em ' . date('d/m/Y H:i:s') . ":\n" . $error;

        try {
            crm_append_lead_note($leadId, $note);
            crm_update_whatsapp_status($leadId, 'falhou', $error);
            crm_record_lead_timeline_event(
                $leadId,
                'whatsapp_template_failed',
                'Falha no envio automático do template RD',
                'Template ' . $templateName . ' via ' . $providerLabel . '. ' . $error,
                'Sistema'
            );
        } catch (Throwable $recordError) {
            error_log('Não foi possível registrar a falha do template RD no lead ' . $leadId . ': ' . $recordError->getMessage());
        }

        return ['ok' => false, 'error' => $error];
    };

    if (!is_array($template) || !crm_whatsapp_template_is_sendable($template, $provider)) {
        return $recordFailure('O template selecionado não está ativo e aprovado para o provedor configurado.');
    }

    $number = crm_normalize_lead_whatsapp((string) ($lead['whatsapp'] ?? ''));
    if ($number === '') {
        return $recordFailure('O lead não tem um número de WhatsApp válido.');
    }

    $queueItem = $lead;
    $queueItem['lead_id'] = $leadId;
    $queueItem['lead_message'] = (string) ($lead['message'] ?? '');
    $variables = crm_whatsapp_followup_template_variables($queueItem, $template);
    if (($variables['ok'] ?? false) !== true) {
        return $recordFailure((string) ($variables['error'] ?? 'Não foi possível preencher as variáveis do template.'));
    }

    $providerVariables = $variables['values'];
    try {
        $result = $provider === 'pilot_status'
            ? pilot_status_send_template($number, $template, $providerVariables)
            : meta_whatsapp_send_template($number, $template, array_values($providerVariables));
    } catch (Throwable $error) {
        return $recordFailure($error->getMessage());
    }

    if (($result['ok'] ?? false) !== true) {
        return $recordFailure((string) ($result['error'] ?? 'Erro desconhecido ao enviar o template.'));
    }

    $renderedBody = crm_whatsapp_template_render(
        (string) ($template['body_text'] ?? ''),
        $providerVariables,
        $lead
    );
    $note = 'Template "' . $templateName . '" enviado via ' . $providerLabel
        . ' em ' . date('d/m/Y H:i:s') . ":\n" . $renderedBody;
    $sentMessageId = crm_whatsapp_response_message_id($result);
    if ($sentMessageId !== '') {
        $note .= "\nCRM message ID: " . $sentMessageId;
    }

    try {
        crm_append_lead_note($leadId, $note);
        crm_update_whatsapp_status($leadId, $provider === 'pilot_status' ? 'aguardando' : 'enviado');
        if (trim((string) ($lead['first_contact_at'] ?? '')) === '') {
            crm_record_lead_timeline_event(
                $leadId,
                'first_contact',
                'Atendimento iniciado',
                'Primeiro contato automático enviado pelo WhatsApp.',
                'Sistema'
            );
        }
        crm_record_lead_timeline_event(
            $leadId,
            'whatsapp_template_sent',
            'Template automático enviado pelo WhatsApp',
            'Template ' . $templateName . ' enviado via ' . $providerLabel . '.',
            'Sistema'
        );
    } catch (Throwable $recordError) {
        error_log('Template RD enviado, mas não foi possível registrar o histórico do lead ' . $leadId . ': ' . $recordError->getMessage());
    }

    return $result;
}

function crm_record_followup_text_send(array $queueItem, array $result = []): void
{
    $provider = crm_whatsapp_provider();
    $note = 'Mensagem enviada via ' . crm_whatsapp_provider_label($provider) . ' em ' . date('d/m/Y H:i:s') . ":\n"
        . 'Follow-up · vendedor: ' . crm_whatsapp_followup_seller_name($queueItem) . "\n"
        . (string) ($queueItem['message'] ?? '');
    $sentMessageId = crm_whatsapp_response_message_id($result);

    if ($sentMessageId !== '') {
        $note .= "\nCRM message ID: " . $sentMessageId;
    }

    try {
        crm_append_lead_note((string) ($queueItem['lead_id'] ?? ''), $note);
        crm_update_whatsapp_status((string) ($queueItem['lead_id'] ?? ''), $provider === 'pilot_status' ? 'aguardando' : 'enviado');
    } catch (Throwable $error) {
        error_log('Follow-up enviado, mas não foi possível registrar o histórico do lead: ' . $error->getMessage());
    }
}
