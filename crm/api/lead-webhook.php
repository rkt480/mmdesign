<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/email.php';
require_once dirname(__DIR__) . '/lib/meta-capi.php';
require_once dirname(__DIR__) . '/lib/security.php';

header('Content-Type: application/json; charset=utf-8');
crm_send_security_headers();

function lead_webhook_respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lead_webhook_normalize_key(string $key): string
{
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        if (is_string($ascii)) {
            $key = $ascii;
        }
    }

    return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $key));
}

function lead_webhook_scalar(mixed $value): string
{
    if (is_scalar($value)) {
        return trim((string) $value);
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $parts[] = trim((string) $item);
            }
        }

        return trim(implode(', ', array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    return '';
}

function lead_webhook_truncate(string $value, int $maxLength): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    if (function_exists('iconv_substr')) {
        $truncated = iconv_substr($value, 0, $maxLength, 'UTF-8');
        if (is_string($truncated)) {
            return $truncated;
        }
    }

    return substr($value, 0, $maxLength);
}

function lead_webhook_response_answers(mixed $answers): array
{
    if (!is_array($answers)) {
        return [];
    }

    $normalized = [];

    foreach ($answers as $key => $answer) {
        if (count($normalized) >= 50) {
            break;
        }

        if (is_array($answer)) {
            $question = lead_webhook_scalar($answer['question'] ?? $answer['label'] ?? (is_string($key) ? $key : ''));
            $value = lead_webhook_scalar($answer['value'] ?? $answer['answer'] ?? '');
            $questionId = lead_webhook_scalar($answer['question_id'] ?? $answer['id'] ?? (is_string($key) ? $key : ''));
        } else {
            $question = is_string($key) ? $key : 'Resposta ' . ((int) $key + 1);
            $questionId = is_string($key) ? $key : 'answer_' . (int) $key;
            $value = lead_webhook_scalar($answer);
        }

        if ($value === '') {
            continue;
        }

        if (in_array(lead_webhook_normalize_key($questionId), ['email', 'mail', 'correioeletronico'], true)) {
            $question = 'E-mail';
        }

        $normalized[] = [
            'question_id' => lead_webhook_truncate($questionId, 120),
            'question' => lead_webhook_truncate($question !== '' ? $question : $questionId, 220),
            'value' => lead_webhook_truncate($value, 1000),
            'answer' => lead_webhook_truncate($value, 1000),
        ];
    }

    return $normalized;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    lead_webhook_respond(405, ['ok' => false, 'error' => 'Método não permitido. Use POST.']);
}

$settings = crm_read_settings();
$expectedToken = trim((string) ($settings['lead_webhook_token'] ?? ''));
$receivedTokenValue = $_GET['token'] ?? $_SERVER['HTTP_X_CRM_WEBHOOK_TOKEN'] ?? '';
$receivedToken = is_scalar($receivedTokenValue) ? trim((string) $receivedTokenValue) : '';

if ($expectedToken === '') {
    lead_webhook_respond(503, ['ok' => false, 'error' => 'Webhook ainda não configurado no CRM.']);
}

if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    lead_webhook_respond(401, ['ok' => false, 'error' => 'Credencial do webhook inválida.']);
}

$tokenIdentity = hash('sha256', $expectedToken);
if (crm_throttle_is_limited('lead-webhook', $tokenIdentity, 120, 600)) {
    lead_webhook_respond(429, ['ok' => false, 'error' => 'Muitas tentativas. Tente novamente em alguns minutos.']);
}
crm_throttle_record('lead-webhook', $tokenIdentity, 600);

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 256 * 1024) {
    lead_webhook_respond(413, ['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
}

$rawBody = file_get_contents('php://input') ?: '';

if (strlen($rawBody) > 256 * 1024) {
    lead_webhook_respond(413, ['ok' => false, 'error' => 'A requisição excede o tamanho permitido.']);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));

if ($contentType === 'application/json' || $contentType === 'application/ld+json') {
    $payload = json_decode($rawBody !== '' ? $rawBody : '{}', true);

    if (!is_array($payload)) {
        lead_webhook_respond(400, ['ok' => false, 'error' => 'JSON inválido.']);
    }
} else {
    $payload = $_POST;

    if ($payload === [] && $rawBody !== '' && $contentType === 'application/x-www-form-urlencoded') {
        parse_str($rawBody, $payload);
    }

    if (!is_array($payload)) {
        lead_webhook_respond(400, ['ok' => false, 'error' => 'Formato de dados inválido.']);
    }
}

$rdEventType = strtoupper(lead_webhook_scalar($payload['event_type'] ?? ''));
$rdEntityType = strtoupper(lead_webhook_scalar($payload['entity_type'] ?? ''));
$isRdStationWebhook = $rdEntityType === 'CONTACT'
    && in_array($rdEventType, ['WEBHOOK.CONVERTED', 'WEBHOOK.MARKED_OPPORTUNITY'], true);

if (trim(lead_webhook_scalar($payload['website'] ?? '')) !== '') {
    lead_webhook_respond(201, ['ok' => true, 'created' => false]);
}

$rdOrigin = '';
$rdEventIdentifier = '';
if ($isRdStationWebhook && is_array($payload['contact'] ?? null)) {
    $rdOrigin = lead_webhook_scalar($payload['contact']['funnel']['origin'] ?? '');
}
if ($isRdStationWebhook) {
    $rdEventIdentifier = lead_webhook_scalar($payload['event_identifier'] ?? '');
}

for ($wrapperDepth = 0; $wrapperDepth < 3; $wrapperDepth++) {
    foreach (['data', 'lead', 'contact', 'fields'] as $wrapperKey) {
        $nestedPayload = $payload[$wrapperKey] ?? null;
        if (is_array($nestedPayload)) {
            $isList = $nestedPayload === []
                || array_keys($nestedPayload) === range(0, count($nestedPayload) - 1);

            if ($wrapperKey === 'fields' && $isList) {
                $mappedFields = [];
                foreach ($nestedPayload as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $fieldName = lead_webhook_scalar($field['name'] ?? $field['id'] ?? $field['key'] ?? $field['label'] ?? '');
                    if ($fieldName === '') {
                        continue;
                    }

                    $mappedFields[$fieldName] = $field['value'] ?? $field['answer'] ?? $field['selected'] ?? '';
                }
                $nestedPayload = $mappedFields;
            }

            $payload = array_merge($payload, $nestedPayload);
        }
    }
}

$values = [];
$normalizedPayload = [];
foreach ($payload as $key => $value) {
    if (is_string($key)) {
        $normalizedPayload[lead_webhook_normalize_key($key)] = $value;
    }
}

$fieldAliases = [
    'name' => ['name', 'nome', 'fullname', 'nomecompleto', 'leadname'],
    'whatsapp' => ['whatsapp', 'phone', 'telefone', 'celular', 'mobile', 'mobilephone', 'personalphone', 'mobilenumber', 'cellphone', 'cell', 'fone', 'phonenumber', 'telefonewhatsapp', 'whatsappnumber'],
    'company' => ['company', 'empresa', 'companyname', 'nomeempresa'],
    'cpf' => ['cpf', 'document'],
    'segment' => ['segment', 'segmento', 'industry', 'ramo'],
    'advertises' => ['advertises', 'advertising', 'comocontrolaosleads', 'comovocedivulga', 'divulgacao'],
    'message' => ['message', 'mensagem', 'necessidade', 'observacao', 'observacoes', 'notes', 'description'],
    'page' => ['page', 'pagina', 'landingpage', 'sourcepage'],
    'utm_source' => ['utmsource'],
    'utm_medium' => ['utmmedium'],
    'utm_campaign' => ['utmcampaign'],
    'utm_content' => ['utmcontent'],
    'utm_term' => ['utmterm'],
    'referrer' => ['referrer', 'referer'],
    'landing_path' => ['landingpath', 'pathname', 'pageurl'],
    'tags' => ['tags', 'etiquetas'],
];

foreach ($fieldAliases as $target => $aliases) {
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $normalizedPayload)) {
            $candidate = lead_webhook_scalar($normalizedPayload[$alias]);
            if ($candidate === '') {
                continue;
            }

            $values[$target] = $candidate;
            break;
        }
    }
}

if (trim((string) ($values['name'] ?? '')) === '') {
    $firstName = lead_webhook_scalar($normalizedPayload['firstname'] ?? '');
    $lastName = lead_webhook_scalar($normalizedPayload['lastname'] ?? '');
    $values['name'] = trim($firstName . ' ' . $lastName);
}

if ($isRdStationWebhook) {
    $rdEmail = lead_webhook_scalar($normalizedPayload['email'] ?? '');

    if (trim((string) ($values['name'] ?? '')) === '' && $rdEmail !== '') {
        $emailSeparator = strpos($rdEmail, '@');
        $values['name'] = $emailSeparator === false ? '' : trim(substr($rdEmail, 0, $emailSeparator));
    }

    // O RD pode enviar contatos sem celular. O CRM aceita o campo vazio e
    // preserva o e-mail como resposta do formulário.
    if (trim((string) ($values['name'] ?? '')) === ''
        && (trim((string) ($values['whatsapp'] ?? '')) !== '' || $rdEmail !== '')) {
        $values['name'] = 'Lead RD Station';
    }

    // A ação "Verificar" do RD pode enviar um evento de validação sem dados
    // de contato. Responder 2xx confirma a URL sem criar um card vazio.
    if (trim((string) ($values['name'] ?? '')) === ''
        && trim((string) ($values['whatsapp'] ?? '')) === ''
        && $rdEmail === '') {
        lead_webhook_respond(200, [
            'ok' => true,
            'accepted' => true,
            'created' => false,
            'message' => 'Validação recebida; nenhum lead foi criado.',
        ]);
    }
} elseif (trim((string) ($values['name'] ?? '')) === '' || trim((string) ($values['whatsapp'] ?? '')) === '') {
    lead_webhook_respond(422, ['ok' => false, 'error' => 'Informe os campos obrigatórios: name (ou nome) e whatsapp (ou telefone).']);
}

$lengthLimits = [
    'name' => 160,
    'whatsapp' => 40,
    'cpf' => 14,
    'company' => 180,
    'segment' => 180,
    'advertises' => 180,
    'message' => 10000,
    'page' => 2000,
    'utm_source' => 120,
    'utm_medium' => 120,
    'utm_campaign' => 180,
    'utm_content' => 180,
    'utm_term' => 180,
    'referrer' => 2000,
    'landing_path' => 2000,
    'tags' => 1000,
];

$leadPayload = [
    'name' => lead_webhook_truncate(trim((string) $values['name']), $lengthLimits['name']),
    'whatsapp' => lead_webhook_truncate(trim((string) $values['whatsapp']), $lengthLimits['whatsapp']),
    'page' => lead_webhook_truncate(trim((string) ($values['page'] ?? '')) ?: 'Landing page (webhook)', $lengthLimits['page']),
    'tags' => lead_webhook_truncate((string) ($values['tags'] ?? ''), $lengthLimits['tags']),
];

foreach (['cpf', 'company', 'segment', 'advertises', 'message', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer', 'landing_path'] as $field) {
    $leadPayload[$field] = lead_webhook_truncate((string) ($values[$field] ?? ''), $lengthLimits[$field]);
}

$customAnswers = lead_webhook_response_answers($payload['answers'] ?? $payload['form_answers'] ?? []);
if ($rdOrigin !== '' && count($customAnswers) < 50) {
    $customAnswers[] = [
        'question_id' => 'rd_origin',
        'question' => 'Origem (RD Station)',
        'value' => lead_webhook_truncate($rdOrigin, 1000),
        'answer' => lead_webhook_truncate($rdOrigin, 1000),
    ];
}
if ($rdEventIdentifier !== '' && count($customAnswers) < 50) {
    $customAnswers[] = [
        'question_id' => 'rd_event_identifier',
        'question' => 'Evento de conversão (RD Station)',
        'value' => lead_webhook_truncate($rdEventIdentifier, 1000),
        'answer' => lead_webhook_truncate($rdEventIdentifier, 1000),
    ];
}
$recognizedAliases = [];
foreach ($fieldAliases as $aliases) {
    foreach ($aliases as $alias) {
        $recognizedAliases[$alias] = true;
    }
}
$recognizedAliases['firstname'] = true;
$recognizedAliases['lastname'] = true;
$reservedKeys = array_fill_keys([
    'website', 'data', 'lead', 'contact', 'fields', 'answers', 'formanswers', 'formid', 'id',
    'event', 'eventid', 'eventtype', 'entitytype', 'eventidentifier', 'timestamp', 'eventtimestamp',
    'funnel', 'company', 'legalbases', 'token', 'csrf', 'csrftoken', 'password', 'authorization',
    'headers', 'createdat', 'updatedat',
], true);

foreach ($payload as $key => $value) {
    if (!is_string($key)) {
        continue;
    }

    $normalizedKey = lead_webhook_normalize_key($key);
    if ($normalizedKey === '' || isset($recognizedAliases[$normalizedKey]) || isset($reservedKeys[$normalizedKey])) {
        continue;
    }

    $answerValue = lead_webhook_scalar($value);
    if ($answerValue === '' || count($customAnswers) >= 50) {
        continue;
    }

    $displayQuestion = in_array($normalizedKey, ['email', 'mail', 'correioeletronico'], true) ? 'E-mail' : $key;

    $customAnswers[] = [
        'question_id' => lead_webhook_truncate($normalizedKey, 120),
        'question' => lead_webhook_truncate($displayQuestion, 220),
        'value' => lead_webhook_truncate($answerValue, 1000),
        'answer' => lead_webhook_truncate($answerValue, 1000),
    ];
}

$leadPayload['form_answers'] = $customAnswers;
$leadPayload['form_id'] = '';

try {
    $leadResult = crm_create_lead_once($leadPayload);
    $lead = $leadResult['lead'];
} catch (Throwable $error) {
    error_log('Erro ao receber lead via webhook do CRM: ' . $error->getMessage());
    lead_webhook_respond(500, ['ok' => false, 'error' => 'Não foi possível salvar o lead no CRM.']);
}

if (($leadResult['created'] ?? false) === true) {
    try {
        $metaResult = meta_capi_send_lead_created($lead, $leadPayload);
        if (($metaResult['ok'] ?? false) !== true && ($metaResult['skipped'] ?? false) !== true) {
            error_log('Erro Meta CAPI Lead ' . (string) $lead['id'] . ': ' . (string) ($metaResult['error'] ?? 'Erro desconhecido.'));
        }
    } catch (Throwable $error) {
        error_log('Erro Meta CAPI Lead ' . (string) $lead['id'] . ': ' . $error->getMessage());
    }

    try {
        $emailResult = crm_send_lead_email_notification($lead);
        if (($emailResult['ok'] ?? false) !== true && ($emailResult['skipped'] ?? false) !== true) {
            error_log('Erro e-mail Lead ' . (string) $lead['id'] . ': ' . (string) ($emailResult['error'] ?? 'Erro desconhecido.'));
        }
    } catch (Throwable $error) {
        error_log('Erro e-mail Lead ' . (string) $lead['id'] . ': ' . $error->getMessage());
    }
}

lead_webhook_respond(201, [
    'ok' => true,
    'lead_id' => (string) ($lead['id'] ?? ''),
    'created' => (bool) ($leadResult['created'] ?? false),
]);
