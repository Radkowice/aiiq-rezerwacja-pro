<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/tenant.php';

const AI_IQ_MAIN_DOMAIN = 'rezerwacja-ai-iq.pl';

function subdomain_guard_seo_mode(): bool
{
    return trim((string) ($_SERVER['HTTP_X_AIIQ_SEO_MODE'] ?? '')) === '1';
}

function subdomain_guard_set_robots_header(string $value): void
{
    if (!subdomain_guard_seo_mode()) {
        return;
    }

    $robots = $value === 'index,follow' ? 'index,follow' : 'noindex,nofollow';
    header('X-AIIQ-Robots: ' . $robots);
}

function subdomain_guard_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === 1 || $value === '1' || $value === 'true') {
        return true;
    }

    if ($value === 0 || $value === '0' || $value === 'false') {
        return false;
    }

    return $default;
}

function subdomain_guard_decode_rpc_result(mixed $data): ?array
{
    if (!is_array($data)) {
        return null;
    }

    if (array_key_exists('success', $data)) {
        return $data;
    }

    foreach ($data as $value) {
        if (!is_array($value)) {
            continue;
        }

        $decoded = subdomain_guard_decode_rpc_result($value);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function subdomain_guard_resolve_robots(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): string {
    // Fail closed: brak funkcji SEO w planie = brak indeksowania.
    if (!tenant_has_feature($tenantId, 'seo_google')) {
        return 'noindex,nofollow';
    }

    $ch = curl_init($supabaseUrl . '/rest/v1/rpc/get_tenant_seo_settings');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            ['p_tenant_id' => $tenantId],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return 'noindex,nofollow';
    }

    $data = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return 'noindex,nofollow';
    }

    $record = subdomain_guard_decode_rpc_result($data);

    if (!is_array($record) || !subdomain_guard_bool($record['success'] ?? false)) {
        return 'noindex,nofollow';
    }

    return subdomain_guard_bool($record['indexing_enabled'] ?? null, false)
        ? 'index,follow'
        : 'noindex,nofollow';
}


function subdomain_guard_response(int $statusCode): void
{
    http_response_code($statusCode);
    exit;
}

function subdomain_guard_current_host(): string
{
    $host = $_SERVER['HTTP_X_ORIGINAL_HOST']
        ?? $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? '';

    return normalize_host((string) $host);
}

function subdomain_guard_is_local_host(string $host): bool
{
    return in_array($host, ['localhost', '127.0.0.1', '::1', '_'], true)
        || preg_match('/(^|\.)localhost$/', $host) === 1;
}

function subdomain_guard_is_main_domain(string $host): bool
{
    return $host === AI_IQ_MAIN_DOMAIN || $host === 'www.' . AI_IQ_MAIN_DOMAIN;
}

subdomain_guard_set_robots_header('noindex,nofollow');

$host = subdomain_guard_current_host();

if ($host === '') {
    subdomain_guard_response(403);
}

if (subdomain_guard_is_local_host($host) || subdomain_guard_is_main_domain($host)) {
    subdomain_guard_response(204);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    subdomain_guard_response(403);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    subdomain_guard_response(403);
}

if (subdomain_guard_seo_mode()) {
    try {
        subdomain_guard_set_robots_header(
            subdomain_guard_resolve_robots($supabaseUrl, $supabaseKey, $schema, $tenantId)
        );
    } catch (Throwable $e) {
        // Fail closed: błąd SEO nie może przypadkiem włączyć indeksowania.
        subdomain_guard_set_robots_header('noindex,nofollow');
    }
}

subdomain_guard_response(204);
