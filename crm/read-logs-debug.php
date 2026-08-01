<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__ . '/data';
$testFile = $dir . '/test-write.txt';

if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$written = @file_put_contents($testFile, "Teste de escrita em " . date('Y-m-d H:i:s'));

if ($written === false) {
    echo "ERRO: O servidor da Hostinger não tem permissão para escrever na pasta crm/data/.\n";
} else {
    echo "SUCESSO: O servidor tem permissão de escrita. Arquivo de teste criado.\n";
    @unlink($testFile);
}

$logFile = $dir . '/pilot-status.log';

if (!file_exists($logFile)) {
    echo "O arquivo de log pilot-status.log ainda não existe no servidor.\n";
    exit;
}

echo "--- CONTEÚDO DO PILOT-STATUS.LOG ---\n";
echo file_get_contents($logFile);
