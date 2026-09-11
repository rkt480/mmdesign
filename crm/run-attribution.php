<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/pilot-status.php';

$lockHandle = @fopen(__DIR__ . '/data/pilot-status-attribution.lock', 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $result = [
        'processed' => 0,
        'resolved' => 0,
        'retried' => 0,
        'exhausted' => 0,
        'failed' => 0,
        'updated' => 0,
        'skipped' => 'Processador de atribuição já está em execução.',
    ];

    header('Content-Type: ' . (PHP_SAPI === 'cli' ? 'text/plain' : 'application/json') . '; charset=utf-8');
    echo PHP_SAPI === 'cli' ? "Processador de atribuição já está em execução.\n" : json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (PHP_SAPI !== 'cli') {
    crm_require_sales_manager();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Método não permitido.';
        exit;
    }

    crm_require_valid_csrf();
}

function pilot_status_attribution_worker_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/data/pilot-status-attribution.log', $line, FILE_APPEND | LOCK_EX);
}

pilot_status_attribution_worker_log('Processador iniciado via ' . PHP_SAPI);

try {
    // The first pass runs as soon as the scheduler invokes this script. The
    // following passes implement the recommended +5s and +60s retries while
    // keeping the inbound Pilot Status webhook completely independent.
    $result = pilot_status_process_referral_attribution_queue(50);
    $secondPass = null;

    if (($result['retried'] ?? 0) > 0) {
        sleep(5);
        $secondPass = pilot_status_process_referral_attribution_queue(50);
        foreach (['processed', 'resolved', 'retried', 'exhausted', 'failed', 'updated'] as $key) {
            $result[$key] += (int) ($secondPass[$key] ?? 0);
        }
    }

    if (is_array($secondPass) && ($secondPass['retried'] ?? 0) > 0) {
        sleep(60);
        $thirdPass = pilot_status_process_referral_attribution_queue(50);
        foreach (['processed', 'resolved', 'retried', 'exhausted', 'failed', 'updated'] as $key) {
            $result[$key] += (int) ($thirdPass[$key] ?? 0);
        }
    }

    pilot_status_attribution_worker_log(
        'Processador finalizado: processados=' . (int) ($result['processed'] ?? 0)
        . ' resolvidos=' . (int) ($result['resolved'] ?? 0)
        . ' atualizados=' . (int) ($result['updated'] ?? 0)
        . ' novas_tentativas=' . (int) ($result['retried'] ?? 0)
        . ' esgotados=' . (int) ($result['exhausted'] ?? 0)
        . ' falharam=' . (int) ($result['failed'] ?? 0)
    );
} catch (Throwable $error) {
    pilot_status_attribution_worker_log('Erro fatal: ' . $error->getMessage());
    throw $error;
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

if (PHP_SAPI === 'cli') {
    echo "Atribuições processadas: " . (int) ($result['processed'] ?? 0) . "\n";
    echo "Resolvidas: " . (int) ($result['resolved'] ?? 0) . "\n";
    echo "Leads atualizados: " . (int) ($result['updated'] ?? 0) . "\n";
    echo "Novas tentativas: " . (int) ($result['retried'] ?? 0) . "\n";
    echo "Esgotadas: " . (int) ($result['exhausted'] ?? 0) . "\n";
    echo "Falharam: " . (int) ($result['failed'] ?? 0) . "\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
