<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/storage.php';

crm_require_login();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.']);
    exit;
}

try {
    $versionRows = [];

    foreach (crm_read_leads() as $lead) {
        $versionRows[] = [
            (string) ($lead['id'] ?? ''),
            (string) ($lead['status'] ?? ''),
            (string) ($lead['assigned_user_id'] ?? ''),
            (string) ($lead['updated_at'] ?? ''),
            (string) ($lead['last_activity_at'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'version' => hash('sha256', json_encode($versionRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível verificar os leads.']);
}
