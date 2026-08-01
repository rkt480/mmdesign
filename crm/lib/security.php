<?php

declare(strict_types=1);

function crm_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function crm_security_data_file(string $bucket): string
{
    $safeBucket = preg_replace('/[^a-z0-9_-]+/i', '-', $bucket) ?: 'default';
    $dir = dirname(__DIR__) . '/data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir . '/security-' . $safeBucket . '.json';
}

function crm_throttle_update(string $bucket, string $identity, int $windowSeconds, bool $record, bool $clear = false): int
{
    $file = crm_security_data_file($bucket);
    $key = hash('sha256', crm_client_ip() . '|' . $identity);
    $now = time();
    $events = [];
    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        return 0;
    }

    flock($handle, LOCK_EX);
    $contents = stream_get_contents($handle);
    $data = json_decode($contents !== false ? $contents : '{}', true);

    if (!is_array($data)) {
        $data = [];
    }

    foreach (($data[$key] ?? []) as $timestamp) {
        $timestamp = (int) $timestamp;

        if ($timestamp >= $now - $windowSeconds) {
            $events[] = $timestamp;
        }
    }

    if ($clear) {
        unset($data[$key]);
        $events = [];
    } else {
        if ($record) {
            $events[] = $now;
        }

        $data[$key] = $events;
    }

    foreach ($data as $storedKey => $timestamps) {
        $data[$storedKey] = array_values(array_filter(
            is_array($timestamps) ? $timestamps : [],
            fn($timestamp): bool => (int) $timestamp >= $now - $windowSeconds
        ));

        if (count($data[$storedKey]) === 0) {
            unset($data[$storedKey]);
        }
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return count($events);
}

function crm_throttle_is_limited(string $bucket, string $identity, int $limit, int $windowSeconds): bool
{
    return crm_throttle_update($bucket, $identity, $windowSeconds, false) >= $limit;
}

function crm_throttle_record(string $bucket, string $identity, int $windowSeconds): int
{
    return crm_throttle_update($bucket, $identity, $windowSeconds, true);
}

function crm_throttle_clear(string $bucket, string $identity, int $windowSeconds): void
{
    crm_throttle_update($bucket, $identity, $windowSeconds, false, true);
}
