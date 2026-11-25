<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/forms.php';
require_once __DIR__ . '/lib/lead-filters.php';

crm_require_admin();

$leads = crm_read_leads();
$statusLabels = [];
foreach (crm_read_kanban_columns() as $column) {
    $statusLabels[(string) $column['status']] = (string) $column['label'];
}
$filters = crm_normalize_lead_filters($_GET, $leads, $statusLabels, true);
$leads = crm_filter_leads($leads, $filters);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="leads-publi-ai.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Nome', 'WhatsApp', 'E-mail', 'CPF', 'Lead Score', 'Temperatura', 'Respostas do formulário', 'Status', 'Tags', 'Observações', 'Origem', 'Vendedor', 'Recebido em']);

foreach ($leads as $lead) {
    $formAnswers = crm_decode_lead_form_answers($lead);
    $email = '';
    foreach ($formAnswers as $answer) {
        $value = trim((string) ($answer['answer'] ?? $answer['value'] ?? ''));
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $value;
            break;
        }
    }

    $answers = array_map(
        static fn(array $answer): string => (string) ($answer['question'] ?? 'Pergunta') . ': ' . (string) ($answer['answer'] ?? ''),
        array_values(array_filter(
            $formAnswers,
            static fn(array $answer): bool => (string) ($answer['question_id'] ?? '') !== 'segment'
        ))
    );

    fputcsv($output, [
        $lead['name'] ?? '',
        $lead['whatsapp'] ?? '',
        $email,
        crm_format_cpf((string) ($lead['cpf'] ?? '')),
        $lead['lead_score'] ?? '',
        $lead['lead_temperature'] ?? '',
        count($answers) > 0 ? implode(' | ', $answers) : implode(' | ', array_filter([$lead['advertises'] ?? '', $lead['message'] ?? ''])),
        $lead['status'] ?? '',
        implode(', ', crm_decode_lead_tags($lead)),
        $lead['commercial_notes'] ?? '',
        crm_lead_origin_key($lead) === '__without_origin__' ? 'Sem origem' : lead_origin_channel_label($lead),
        trim((string) ($lead['assigned_user_name'] ?? '')) !== '' ? $lead['assigned_user_name'] : 'Sem vendedor',
        $lead['created_at'] ?? '',
    ]);
}

fclose($output);
