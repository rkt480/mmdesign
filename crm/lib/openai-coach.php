<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function crm_openai_coach_default_prompt(): string
{
    return <<<'PROMPT'
Você é um coach de vendas experiente. Analise a conversa entre o vendedor e o cliente usando a metodologia comercial cadastrada e os materiais de apoio disponíveis.

Seu objetivo é ensinar o vendedor a melhorar a negociação, sem inventar fatos. Baseie cada recomendação em evidências da conversa. Seja direto, didático e respeitoso. Diferencie claramente o que foi dito do que é uma hipótese.

Avalie descoberta de necessidades, perguntas consultivas, escuta, clareza da proposta, tratamento de objeções, condução para o próximo passo e qualidade da comunicação. Não recomende pressão indevida, promessas não autorizadas ou descontos não mencionados.

Retorne somente o formato estruturado solicitado pelo sistema.
PROMPT;
}

function crm_openai_api_key(): string
{
    $settings = crm_read_settings();

    return trim((string) ($settings['openai_api_key'] ?? ''));
}

function crm_openai_coach_prompt(): string
{
    $settings = crm_read_settings();
    $prompt = trim((string) ($settings['openai_coach_prompt'] ?? ''));

    return $prompt !== '' ? $prompt : crm_openai_coach_default_prompt();
}

function crm_openai_coach_model(): string
{
    $settings = crm_read_settings();
    $model = trim((string) ($settings['openai_coach_model'] ?? 'gpt-5.6-luna'));

    return in_array($model, ['gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol'], true)
        ? $model
        : 'gpt-5.6-luna';
}

function crm_openai_coach_temperature_for_score(int $score): string
{
    if ($score >= 70) {
        return 'quente';
    }

    if ($score >= 40) {
        return 'morno';
    }

    return 'frio';
}

function crm_openai_coach_documents(): array
{
    $stmt = crm_db()->query(
        'SELECT id, filename, openai_file_id, vector_store_id, mime_type, size_bytes, status, created_at, updated_at
         FROM openai_coach_documents
         ORDER BY created_at DESC, id DESC'
    );

    return $stmt->fetchAll() ?: [];
}

function crm_openai_coach_latest_analysis(string $leadId): ?array
{
    $stmt = crm_db()->prepare(
        'SELECT id, lead_id, seller_user_id, model, status, score, temperature, potential,
                result_json, conversation_fingerprint, input_tokens, output_tokens,
                error_message, created_at
         FROM openai_coach_analyses
         WHERE lead_id = :lead_id AND status = "completed"
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute(['lead_id' => $leadId]);
    $analysis = $stmt->fetch();

    if (!is_array($analysis)) {
        return null;
    }

    $result = json_decode((string) ($analysis['result_json'] ?? ''), true);
    $analysis['result'] = is_array($result) ? $result : [];
    $analysis['temperature'] = crm_openai_coach_temperature_for_score((int) ($analysis['score'] ?? 0));
    $analysis['result']['lead_temperature'] = $analysis['temperature'];

    return $analysis;
}

function crm_openai_coach_vector_store_id(): string
{
    $settings = crm_read_settings();
    $vectorStoreId = trim((string) ($settings['openai_coach_vector_store_id'] ?? ''));

    if ($vectorStoreId !== '') {
        return $vectorStoreId;
    }

    $response = crm_openai_json_request('POST', '/v1/vector_stores', [
        'name' => 'CRM Coach de Vendas',
    ]);
    $vectorStoreId = trim((string) ($response['id'] ?? ''));

    if ($vectorStoreId === '') {
        throw new RuntimeException('A OpenAI não retornou o identificador da base de documentos.');
    }

    $settings['openai_coach_vector_store_id'] = $vectorStoreId;
    crm_write_settings($settings);

    return $vectorStoreId;
}

function crm_openai_json_request(string $method, string $path, array $payload = []): array
{
    $apiKey = crm_openai_api_key();

    if ($apiKey === '') {
        throw new RuntimeException('Cadastre a chave da OpenAI em Configurações antes de usar o coach.');
    }

    $handle = curl_init('https://api.openai.com' . $path);

    if ($handle === false) {
        throw new RuntimeException('Não foi possível iniciar a conexão com a OpenAI.');
    }

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
    ]);

    if ($payload !== [] && strtoupper($method) !== 'GET') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Não foi possível conectar à OpenAI: ' . ($curlError !== '' ? $curlError : 'erro de rede.'));
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('A OpenAI retornou uma resposta inválida.');
    }

    if ($status < 200 || $status >= 300) {
        $message = (string) ($decoded['error']['message'] ?? 'A OpenAI recusou a solicitação.');
        throw new RuntimeException('OpenAI: ' . $message);
    }

    return $decoded;
}

function crm_openai_upload_file(string $temporaryPath, string $filename): array
{
    $apiKey = crm_openai_api_key();

    if ($apiKey === '') {
        throw new RuntimeException('Cadastre a chave da OpenAI em Configurações antes de enviar documentos.');
    }

    $handle = curl_init('https://api.openai.com/v1/files');

    if ($handle === false) {
        throw new RuntimeException('Não foi possível iniciar o envio do PDF.');
    }

    $upload = new CURLFile($temporaryPath, 'application/pdf', $filename);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
        CURLOPT_POSTFIELDS => ['purpose' => 'user_data', 'file' => $upload],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
    ]);

    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Não foi possível enviar o PDF para a OpenAI.');
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded) || $status < 200 || $status >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        throw new RuntimeException('OpenAI: ' . ($message !== '' ? $message : 'falha no upload do PDF.'));
    }

    return $decoded;
}

function crm_openai_coach_upload_document(array $upload, int $createdBy): array
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $filename = trim((string) ($upload['name'] ?? ''));
    $size = (int) ($upload['size'] ?? 0);

    if ($error !== UPLOAD_ERR_OK || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Selecione um PDF válido para enviar.');
    }

    if ($size <= 0 || $size > 25 * 1024 * 1024) {
        throw new RuntimeException('O PDF deve ter no máximo 25 MB.');
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeType = function_exists('finfo_open')
        ? (string) (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $temporaryPath) ?: '')
        : '';

    if ($extension !== 'pdf' || !in_array($mimeType, ['application/pdf', 'application/x-pdf', ''], true)) {
        throw new RuntimeException('Apenas arquivos PDF são aceitos.');
    }

    $vectorStoreId = crm_openai_coach_vector_store_id();
    $uploaded = crm_openai_upload_file($temporaryPath, $filename);
    $openaiFileId = trim((string) ($uploaded['id'] ?? ''));

    if ($openaiFileId === '') {
        throw new RuntimeException('A OpenAI não retornou o identificador do PDF.');
    }

    $attached = crm_openai_json_request(
        'POST',
        '/v1/vector_stores/' . rawurlencode($vectorStoreId) . '/files',
        ['file_id' => $openaiFileId]
    );
    $status = trim((string) ($attached['status'] ?? 'processing')) ?: 'processing';
    $now = date('Y-m-d H:i:s');
    $stmt = crm_db()->prepare(
        'INSERT INTO openai_coach_documents
            (filename, openai_file_id, vector_store_id, mime_type, size_bytes, status, created_by, created_at, updated_at)
         VALUES (:filename, :openai_file_id, :vector_store_id, :mime_type, :size_bytes, :status, :created_by, :created_at, :updated_at)'
    );
    $stmt->execute([
        'filename' => mb_substr($filename, 0, 255),
        'openai_file_id' => $openaiFileId,
        'vector_store_id' => $vectorStoreId,
        'mime_type' => 'application/pdf',
        'size_bytes' => $size,
        'status' => $status,
        'created_by' => $createdBy > 0 ? $createdBy : null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => (int) crm_db()->lastInsertId(),
        'filename' => $filename,
        'status' => $status,
    ];
}

function crm_openai_coach_conversation_messages(array $lead): array
{
    $messages = [];
    $initial = trim((string) ($lead['message'] ?? ''));

    if ($initial !== '') {
        $messages[] = [
            'direction' => 'cliente',
            'at' => (string) ($lead['created_at'] ?? date('Y-m-d H:i:s')),
            'text' => $initial,
        ];
    }

    $notes = trim((string) ($lead['notes'] ?? ''));

    if ($notes === '') {
        return $messages;
    }

    $blocks = preg_split('/(?:\R){2,}/u', $notes) ?: [];

    foreach ($blocks as $block) {
        $block = trim($block);

        if ($block === '' || preg_match('/^(?:Status|Pilot Status ID|CRM message ID|Falha ao enviar|Observação do CRM|Pilot Status evento:)/iu', $block) === 1) {
            continue;
        }

        $datePattern = '([0-9]{2}\/\d{2}\/\d{4} [0-9]{2}:[0-9]{2}(?::[0-9]{2})?)';
        $body = '';
        $direction = '';
        $at = (string) ($lead['updated_at'] ?? date('Y-m-d H:i:s'));

        if (preg_match('/^Mensagem recebida[^\n]* em ' . $datePattern . ':\R(.+)$/su', $block, $match) === 1) {
            $direction = 'cliente';
            $at = crm_openai_coach_parse_br_datetime((string) $match[1]);
            $body = trim((string) $match[2]);
        } elseif (preg_match('/^(?:Mensagem|Mídia|Template .+) enviada[^\n]* em ' . $datePattern . ':\R(.+)$/su', $block, $match) === 1) {
            $direction = 'vendedor';
            $at = crm_openai_coach_parse_br_datetime((string) $match[1]);
            $body = trim((string) $match[2]);
        } elseif (preg_match('/^Resposta automática[^\n]* em ' . $datePattern . ':\R(.+)$/su', $block, $match) === 1) {
            $direction = 'sistema';
            $at = crm_openai_coach_parse_br_datetime((string) $match[1]);
            $body = trim((string) $match[2]);
        }

        $body = preg_replace('/\R(?:Status inicial|Status|Pilot Status ID|CRM message ID):.*$/isu', '', $body) ?? $body;
        $body = preg_replace('/^\*[^\n]+, disse:\*\s*/u', '', trim($body)) ?? trim($body);
        $body = trim($body);

        if ($direction !== '' && $body !== '') {
            $messages[] = ['direction' => $direction, 'at' => $at, 'text' => $body];
        }
    }

    usort($messages, static function (array $left, array $right): int {
        $leftAt = strtotime((string) ($left['at'] ?? '')) ?: 0;
        $rightAt = strtotime((string) ($right['at'] ?? '')) ?: 0;

        return $leftAt <=> $rightAt;
    });

    return $messages;
}

function crm_openai_coach_parse_br_datetime(string $value): string
{
    $date = DateTime::createFromFormat('d/m/Y H:i:s', trim($value));
    $date = $date instanceof DateTime ? $date : DateTime::createFromFormat('d/m/Y H:i', trim($value));

    return $date instanceof DateTime ? $date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
}

function crm_openai_coach_build_context(array $lead, array $messages): array
{
    $messages = array_values(array_filter($messages, static fn(array $message): bool => in_array($message['direction'] ?? '', ['cliente', 'vendedor'], true)));
    $messages = count($messages) > 120 ? array_slice($messages, -120) : $messages;
    $lines = [];
    $totalLength = 0;

    foreach ($messages as $message) {
        $text = trim((string) ($message['text'] ?? ''));
        $text = mb_substr($text, 0, 4000);

        if ($text === '') {
            continue;
        }

        $line = '[' . (string) ($message['at'] ?? '') . '] ' . ucfirst((string) $message['direction']) . ': ' . $text;

        if ($totalLength + strlen($line) > 60000) {
            break;
        }

        $lines[] = $line;
        $totalLength += strlen($line);
    }

    $leadContext = [
        'nome' => trim((string) ($lead['name'] ?? '')),
        'empresa' => trim((string) ($lead['company'] ?? '')),
        'segmento' => trim((string) ($lead['segment'] ?? '')),
        'produto_ou_interesse' => trim((string) ($lead['advertises'] ?? '')),
        'status_no_funil' => trim((string) ($lead['status'] ?? '')),
        'valor_da_proposta' => $lead['proposal_value'] ?? null,
        'observacoes_comerciais' => mb_substr(trim((string) ($lead['commercial_notes'] ?? '')), 0, 4000),
    ];

    return [
        'lead' => $leadContext,
        'conversation' => implode("\n", $lines),
        'message_count' => count($lines),
    ];
}

function crm_openai_coach_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'summary' => ['type' => 'string'],
            'coach_grade' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'closing_potential' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'lead_temperature' => ['type' => 'string', 'enum' => ['frio', 'morno', 'quente']],
            'positive_points' => ['type' => 'array', 'items' => ['type' => 'string']],
            'improvements' => ['type' => 'array', 'items' => ['type' => 'string']],
            'missed_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            'objections' => ['type' => 'array', 'items' => ['type' => 'string']],
            'next_action' => ['type' => 'string'],
            'suggested_reply' => ['type' => 'string'],
            'evidence' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [
            'summary', 'coach_grade', 'closing_potential', 'lead_temperature',
            'positive_points', 'improvements', 'missed_questions', 'objections',
            'next_action', 'suggested_reply', 'evidence',
        ],
    ];
}

function crm_openai_coach_output_text(array $response): string
{
    if (trim((string) ($response['output_text'] ?? '')) !== '') {
        return trim((string) $response['output_text']);
    }

    foreach (($response['output'] ?? []) as $outputItem) {
        foreach (($outputItem['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && trim((string) ($content['text'] ?? '')) !== '') {
                return trim((string) $content['text']);
            }
        }
    }

    return '';
}

function crm_openai_coach_analyze(array $lead, array $messages, int $sellerUserId): array
{
    $context = crm_openai_coach_build_context($lead, $messages);

    if ((int) ($context['message_count'] ?? 0) === 0) {
        throw new RuntimeException('Este lead ainda não possui mensagens de texto para analisar.');
    }

    $settings = crm_read_settings();
    $vectorStoreId = trim((string) ($settings['openai_coach_vector_store_id'] ?? ''));
    $tools = [];

    if ($vectorStoreId !== '') {
        $tools[] = [
            'type' => 'file_search',
            'vector_store_ids' => [$vectorStoreId],
            'max_num_results' => 8,
        ];
    }

    $inputText = "DADOS DO LEAD:\n" . json_encode($context['lead'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\n\nCONVERSA:\n" . (string) $context['conversation']
        . "\n\nAnalise o vendedor e o cliente como um coach. Use os documentos disponíveis quando forem pertinentes. Se não houver informação suficiente, diga isso explicitamente.";
    $payload = [
        'model' => crm_openai_coach_model(),
        'instructions' => crm_openai_coach_prompt(),
        'input' => [[
            'role' => 'user',
            'content' => [['type' => 'input_text', 'text' => $inputText]],
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'sales_coach_analysis',
                'strict' => true,
                'schema' => crm_openai_coach_schema(),
            ],
        ],
        'max_output_tokens' => 2200,
        'store' => false,
    ];

    if ($tools !== []) {
        $payload['tools'] = $tools;
    }

    $fingerprint = hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    // The API request can take longer than the MySQL wait_timeout configured
    // by some hosts. Release the old connection and open a fresh one for the
    // analysis write below.
    if (function_exists('crm_db_release')) {
        crm_db_release();
    }
    $response = crm_openai_json_request('POST', '/v1/responses', $payload);
    if (function_exists('crm_db_reconnect')) {
        crm_db_reconnect();
    }
    $text = crm_openai_coach_output_text($response);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
    $result = json_decode(trim($text), true);

    if (!is_array($result)) {
        throw new RuntimeException('A OpenAI não retornou uma análise estruturada válida.');
    }

    $score = max(0, min(100, (int) ($result['closing_potential'] ?? 0)));
    $temperature = crm_openai_coach_temperature_for_score($score);
    $result['lead_temperature'] = $temperature;
    $now = date('Y-m-d H:i:s');
    $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
    $stmt = crm_db()->prepare(
        'INSERT INTO openai_coach_analyses
            (lead_id, seller_user_id, model, status, score, temperature, potential, result_json,
             conversation_fingerprint, input_tokens, output_tokens, created_at)
         VALUES (:lead_id, :seller_user_id, :model, "completed", :score, :temperature, :potential,
                 :result_json, :conversation_fingerprint, :input_tokens, :output_tokens, :created_at)'
    );
    $stmt->execute([
        'lead_id' => (string) ($lead['id'] ?? ''),
        'seller_user_id' => $sellerUserId > 0 ? $sellerUserId : null,
        'model' => crm_openai_coach_model(),
        'score' => $score,
        'temperature' => $temperature,
        'potential' => $score >= 70 ? 'alto' : ($score >= 40 ? 'médio' : 'baixo'),
        'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'conversation_fingerprint' => $fingerprint,
        'input_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
        'output_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
        'created_at' => $now,
    ]);
    $analysisId = (int) crm_db()->lastInsertId();

    $reason = trim((string) ($result['summary'] ?? ''));
    [$accessSql, $accessParams] = crm_lead_access_sql('leads');
    $scoreStmt = crm_db()->prepare(
        'UPDATE leads
         SET lead_score = :lead_score,
             lead_temperature = :lead_temperature,
             score_reasons = :score_reasons,
             updated_at = :updated_at
         WHERE id = :id' . $accessSql
    );
    $scoreStmt->execute([
        'id' => (string) ($lead['id'] ?? ''),
        'lead_score' => $score,
        'lead_temperature' => $temperature,
        'score_reasons' => mb_substr($reason, 0, 1000),
        'updated_at' => $now,
    ] + $accessParams);

    if (function_exists('crm_record_lead_timeline_event')) {
        crm_record_lead_timeline_event(
            (string) ($lead['id'] ?? ''),
            'ai_coach_analysis',
            'Análise do coach de vendas concluída',
            'Score de potencial: ' . $score . '/100. Temperatura: ' . $temperature . '.',
            null,
            null,
            ['analysis_id' => $analysisId, 'score' => $score, 'temperature' => $temperature]
        );
    }

    return [
        'id' => $analysisId,
        'lead_id' => (string) ($lead['id'] ?? ''),
        'model' => crm_openai_coach_model(),
        'score' => $score,
        'temperature' => $temperature,
        'potential' => $score >= 70 ? 'alto' : ($score >= 40 ? 'médio' : 'baixo'),
        'result' => $result,
        'created_at' => $now,
        'input_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
        'output_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
    ];
}
