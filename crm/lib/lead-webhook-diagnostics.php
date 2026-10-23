<?php

declare(strict_types=1);

function crm_lead_webhook_diagnostics_file(): string
{
    return dirname(__DIR__) . '/data/lead-webhook-diagnostics.jsonl';
}

function crm_lead_webhook_record_attempt(string $requestId, string $phase, int $status = 0, string $result = ''): void
{
    $file = @fopen(crm_lead_webhook_diagnostics_file(), 'c+');

    if ($file === false) {
        return;
    }

    try {
        if (!flock($file, LOCK_EX)) {
            return;
        }

        rewind($file);
        $contents = stream_get_contents($file);
        $lines = is_string($contents) ? preg_split('/\r?\n/', trim($contents)) : [];
        $lines = is_array($lines) ? array_values(array_filter($lines, 'strlen')) : [];
        $lines = array_slice($lines, -39);

        $entry = [
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id' => substr($requestId, 0, 32),
            'phase' => substr($phase, 0, 24),
            'status' => $status,
            'method' => substr(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), 0, 12),
            'content_type' => substr(strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])), 0, 80),
            'result' => substr($result, 0, 160),
        ];
        $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        rewind($file);
        ftruncate($file, 0);
        fwrite($file, implode("\n", $lines) . "\n");
        fflush($file);
        flock($file, LOCK_UN);
    } finally {
        fclose($file);
    }
}

function crm_lead_webhook_recent_attempts(): array
{
    $contents = @file_get_contents(crm_lead_webhook_diagnostics_file());

    if (!is_string($contents) || $contents === '') {
        return [];
    }

    $lines = array_reverse(array_slice(preg_split('/\r?\n/', trim($contents)) ?: [], -20));
    $attempts = [];

    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry)) {
            $attempts[] = $entry;
        }
    }

    return $attempts;
}
