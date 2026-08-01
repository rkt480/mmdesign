<?php

declare(strict_types=1);

function crm_settings_file(): string
{
    return dirname(__DIR__) . '/data/settings.json';
}

function crm_read_settings(): array
{
    $file = crm_settings_file();

    if (!is_file($file)) {
        return [];
    }

    $contents = file_get_contents($file);
    $settings = json_decode($contents !== false ? $contents : '{}', true);

    return is_array($settings) ? $settings : [];
}

function crm_write_settings(array $settings): void
{
    $dir = dirname(crm_settings_file());

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    file_put_contents(crm_settings_file(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function crm_normalize_whatsapp_number(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if ($digits === '') {
        return '';
    }

    return str_starts_with($digits, '55') ? $digits : '55' . $digits;
}

function crm_whatsapp_number_variants(string $number): array
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if ($digits === '') {
        return [];
    }

    if (!str_starts_with($digits, '55')) {
        $digits = '55' . $digits;
    }

    $variants = [$digits];

    if (strlen($digits) === 12 && preg_match('/^55\d{2}[6-9]\d{7}$/', $digits) === 1) {
        array_unshift($variants, substr($digits, 0, 4) . '9' . substr($digits, 4));
    }
    if (strlen($digits) === 13 && preg_match('/^55\d{2}9[6-9]\d{7}$/', $digits) === 1) {
        $variants[] = substr($digits, 0, 4) . substr($digits, 5);
    }

    return array_values(array_unique($variants));
}

function crm_whatsapp_number(): string
{
    $settings = crm_read_settings();
    return crm_normalize_whatsapp_number((string) ($settings['whatsapp_number'] ?? ''));
}

function crm_whatsapp_provider(): string
{
    $settings = crm_read_settings();
    $provider = trim((string) ($settings['whatsapp_provider'] ?? 'pilot_status'));

    return in_array($provider, ['meta_cloud', 'pilot_status'], true) ? $provider : 'pilot_status';
}

function crm_normalize_url_base(string $url, string $fallback): string
{
    $normalized = rtrim(trim($url), '/');

    if (str_starts_with($normalized, 'https://api.pilotstatus.com.br')) {
        $normalized = 'https://pilotstatus.com.br' . substr($normalized, strlen('https://api.pilotstatus.com.br'));
    }

    if ($normalized === '') {
        return $fallback;
    }

    return filter_var($normalized, FILTER_VALIDATE_URL) !== false ? $normalized : $fallback;
}

function crm_normalize_meta_graph_version(string $version): string
{
    $normalized = strtolower(trim($version));
    $normalized = ltrim($normalized, 'v');

    if (preg_match('/^\d{1,2}\.\d$/', $normalized) !== 1) {
        return '20.0';
    }

    return $normalized;
}

function crm_meta_whatsapp_settings(): array
{
    $settings = crm_read_settings();
    $config = [];
    $configFile = dirname(__DIR__) . '/config.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        $config = is_array($loaded) ? ($loaded['meta_whatsapp'] ?? []) : [];
    }

    return [
        'graph_version' => crm_normalize_meta_graph_version((string) ($settings['meta_whatsapp_graph_version'] ?? '20.0')),
        'phone_number_id' => preg_replace('/\D+/', '', (string) ($settings['meta_whatsapp_phone_number_id'] ?? '')) ?? '',
        'business_account_id' => preg_replace('/\D+/', '', (string) ($settings['meta_whatsapp_business_account_id'] ?? '')) ?? '',
        'access_token' => trim((string) ($settings['meta_whatsapp_access_token'] ?? ($config['access_token'] ?? ''))),
        'verify_token' => trim((string) ($settings['meta_whatsapp_verify_token'] ?? ($config['verify_token'] ?? ''))),
        'app_secret' => trim((string) ($settings['meta_whatsapp_app_secret'] ?? ($config['app_secret'] ?? ''))),
        'coex_enabled' => !empty($settings['meta_whatsapp_coex_enabled']),
    ];
}

function crm_meta_whatsapp_is_configured(): bool
{
    $meta = crm_meta_whatsapp_settings();

    return $meta['phone_number_id'] !== '' && $meta['access_token'] !== '';
}

function crm_pilot_status_settings(): array
{
    $settings = crm_read_settings();
    $config = [];
    $configFile = dirname(__DIR__) . '/config.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        $config = is_array($loaded) ? ($loaded['pilot_status'] ?? []) : [];
    }

    return [
        'base_url' => crm_normalize_url_base((string) ($settings['pilot_status_base_url'] ?? ($config['base_url'] ?? '')), 'https://pilotstatus.com.br/v1'),
        'api_key' => trim((string) ($settings['pilot_status_api_key'] ?? ($config['api_key'] ?? ''))),
        'webhook_secret' => trim((string) ($settings['pilot_status_webhook_secret'] ?? ($config['webhook_secret'] ?? ''))),
    ];
}

function crm_pilot_status_is_configured(): bool
{
    $pilot = crm_pilot_status_settings();

    return $pilot['base_url'] !== '' && $pilot['api_key'] !== '';
}

function crm_normalize_notification_email(string $email): string
{
    $normalized = trim($email);

    if ($normalized === '') {
        return '';
    }

    return filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false ? $normalized : '';
}

function crm_notification_email(): string
{
    $settings = crm_read_settings();
    return crm_normalize_notification_email((string) ($settings['notification_email'] ?? ''));
}

function crm_meta_capi_settings(): array
{
    $settings = crm_read_settings();

    return [
        'pixel_id' => trim((string) ($settings['meta_pixel_id'] ?? '')),
        'access_token' => trim((string) ($settings['meta_access_token'] ?? '')),
        'test_event_code' => trim((string) ($settings['meta_test_event_code'] ?? '')),
    ];
}

function crm_meta_capi_is_configured(): bool
{
    $meta = crm_meta_capi_settings();

    return $meta['pixel_id'] !== '' && $meta['access_token'] !== '';
}

function crm_normalize_gtm_id(string $gtmId): string
{
    $normalized = strtoupper(trim($gtmId));

    if ($normalized === '') {
        return '';
    }

    return preg_match('/^GTM-[A-Z0-9]+$/', $normalized) === 1 ? $normalized : '';
}

function crm_google_tag_manager_id(): string
{
    $settings = crm_read_settings();
    return crm_normalize_gtm_id((string) ($settings['google_tag_manager_id'] ?? ''));
}

function crm_google_calendar_redirect_uri(): string
{
    $settings = crm_read_settings();
    $configured = trim((string) ($settings['google_calendar_redirect_uri'] ?? ''));

    if ($configured !== '') {
        return $configured;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/crm/settings.php')), '/') . '/google-calendar-callback.php';
}

function crm_google_calendar_settings(): array
{
    $settings = crm_read_settings();

    return [
        'client_id' => trim((string) ($settings['google_calendar_client_id'] ?? '')),
        'client_secret' => trim((string) ($settings['google_calendar_client_secret'] ?? '')),
        'redirect_uri' => crm_google_calendar_redirect_uri(),
        'calendar_id' => trim((string) ($settings['google_calendar_id'] ?? 'primary')) ?: 'primary',
        'access_token' => trim((string) ($settings['google_calendar_access_token'] ?? '')),
        'refresh_token' => trim((string) ($settings['google_calendar_refresh_token'] ?? '')),
        'token_expires_at' => (int) ($settings['google_calendar_token_expires_at'] ?? 0),
        'connected_at' => trim((string) ($settings['google_calendar_connected_at'] ?? '')),
    ];
}

function crm_google_calendar_is_configured(): bool
{
    $calendar = crm_google_calendar_settings();

    return $calendar['client_id'] !== ''
        && $calendar['client_secret'] !== ''
        && $calendar['redirect_uri'] !== '';
}

function crm_google_calendar_is_connected(): bool
{
    $calendar = crm_google_calendar_settings();

    return crm_google_calendar_is_configured()
        && $calendar['refresh_token'] !== '';
}

function crm_normalize_sales_sla_statuses($statuses): array
{
    if (is_string($statuses)) {
        $statuses = preg_split('/[,;\s]+/', $statuses) ?: [];
    }

    if (!is_array($statuses)) {
        return ['novo', 'contatado', 'followup'];
    }

    $normalized = [];

    foreach ($statuses as $status) {
        $status = trim((string) $status);

        if ($status !== '') {
            $normalized[$status] = $status;
        }
    }

    return array_values($normalized);
}

function crm_sales_distribution_settings(): array
{
    $settings = crm_read_settings();
    $slaMinutes = max(15, min(10080, (int) ($settings['sales_sla_inactivity_minutes'] ?? 240)));
    $warningMinutes = max(0, min($slaMinutes, (int) ($settings['sales_sla_warning_minutes'] ?? 30)));
    $slaAction = trim((string) ($settings['sales_sla_action'] ?? 'rotation'));

    return [
        'rotation_enabled' => !empty($settings['sales_rotation_enabled']),
        'sla_enabled' => !empty($settings['sales_sla_enabled']),
        'sla_inactivity_minutes' => $slaMinutes,
        'sla_warning_minutes' => $warningMinutes,
        'sla_action' => in_array($slaAction, ['rotation', 'manager_review'], true) ? $slaAction : 'rotation',
        'sla_statuses' => crm_normalize_sales_sla_statuses($settings['sales_sla_statuses'] ?? ['novo', 'contatado', 'followup']),
    ];
}
