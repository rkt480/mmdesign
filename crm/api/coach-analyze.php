<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/openai-coach.php';

crm_require_login();
crm_send_security_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

crm_require_valid_csrf();

$leadId = trim((string) ($_POST['lead_id'] ?? ''));

if ($leadId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lead inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lead = crm_find_lead($leadId);

if (!is_array($lead)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Lead não encontrado ou sem permissão.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = crm_current_user();
$currentUserId = (int) ($currentUser['id'] ?? 0);

try {
    $messages = crm_openai_coach_conversation_messages($lead);
    $analysis = crm_openai_coach_analyze($lead, $messages, $currentUserId);

    echo json_encode([
        'ok' => true,
        'analysis' => $analysis,
        'message_count' => count($messages),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
