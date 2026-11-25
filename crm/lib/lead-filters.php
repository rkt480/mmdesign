<?php

declare(strict_types=1);

require_once __DIR__ . '/lead-view.php';

function crm_lead_origin_key(array $lead): string
{
    if (trim((string) ($lead['utm_source'] ?? '')) === '' && trim((string) ($lead['referrer'] ?? '')) === '') {
        return '__without_origin__';
    }

    $label = lead_origin_channel_label($lead);
    return 'source:' . (function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label));
}

function crm_available_lead_origins(array $leads): array
{
    $origins = [];
    foreach ($leads as $lead) {
        $key = crm_lead_origin_key($lead);
        if ($key !== '__without_origin__') {
            $origins[$key] = lead_origin_channel_label($lead);
        }
    }
    natcasesort($origins);
    return $origins;
}

function crm_normalize_lead_filters(array $input, array $leads, array $statusLabels, bool $canManageSales): array
{
    $value = static fn(string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    $date = static function (string $raw): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return '';
        }
        [$year, $month, $day] = array_map('intval', explode('-', $raw));
        return checkdate($month, $day, $year) ? $raw : '';
    };

    $status = $value('status');
    $tag = $value('tag');
    $origin = $value('origin');
    $seller = $canManageSales ? $value('seller') : '';
    $availableTags = crm_read_available_tags($leads);
    $availableOrigins = crm_available_lead_origins($leads);

    if (!array_key_exists($status, $statusLabels)) {
        $status = '';
    }
    if ($tag !== '' && !in_array($tag, $availableTags, true)) {
        $tag = '';
    }
    if ($origin !== '__without_origin__' && !array_key_exists($origin, $availableOrigins)) {
        $origin = '';
    }
    if ($seller !== 'unassigned' && !(preg_match('/^[1-9]\d*$/', $seller) === 1)) {
        $seller = '';
    }

    return [
        'q' => $value('q'),
        'status' => $status,
        'tag' => $tag,
        'origin' => $origin,
        'seller' => $seller,
        'date_from' => $date($value('date_from')),
        'date_to' => $date($value('date_to')),
    ];
}

function crm_filter_leads(array $leads, array $filters): array
{
    return array_values(array_filter($leads, static function (array $lead) use ($filters): bool {
        $createdDate = substr(trim((string) ($lead['created_at'] ?? '')), 0, 10);
        if ($filters['date_from'] !== '' && ($createdDate === '' || $createdDate < $filters['date_from'])) {
            return false;
        }
        if ($filters['date_to'] !== '' && ($createdDate === '' || $createdDate > $filters['date_to'])) {
            return false;
        }
        if ($filters['status'] !== '' && (string) ($lead['status'] ?? '') !== $filters['status']) {
            return false;
        }
        if ($filters['seller'] === 'unassigned' && (int) ($lead['assigned_user_id'] ?? 0) !== 0) {
            return false;
        }
        if ($filters['seller'] !== '' && $filters['seller'] !== 'unassigned' && (int) ($lead['assigned_user_id'] ?? 0) !== (int) $filters['seller']) {
            return false;
        }
        if ($filters['tag'] !== '') {
            $tags = array_map(static fn(string $tag): string => function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag), crm_decode_lead_tags($lead));
            $tag = function_exists('mb_strtolower') ? mb_strtolower($filters['tag'], 'UTF-8') : strtolower($filters['tag']);
            if (!in_array($tag, $tags, true)) {
                return false;
            }
        }
        if ($filters['origin'] !== '' && crm_lead_origin_key($lead) !== $filters['origin']) {
            return false;
        }
        if ($filters['q'] === '') {
            return true;
        }
        $haystack = implode(' ', [
            $lead['name'] ?? '', $lead['whatsapp'] ?? '', $lead['cpf'] ?? '',
            $lead['message'] ?? '', $lead['notes'] ?? '',
            implode(' ', crm_decode_lead_tags($lead)),
        ]);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        $needle = function_exists('mb_strtolower') ? mb_strtolower($filters['q'], 'UTF-8') : strtolower($filters['q']);
        return str_contains($haystack, $needle);
    }));
}
