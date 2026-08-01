<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/data/pilot-status.log';

if (!file_exists($logFile)) {
    echo "O arquivo de log pilot-status.log ainda não existe no servidor.\n";
    exit;
}

echo "--- CONTEÚDO DO PILOT-STATUS.LOG ---\n";
echo file_get_contents($logFile);
