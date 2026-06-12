<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/tenant.php';

const AI_IQ_MAIN_DOMAIN = 'rezerwacja-ai-iq.pl';

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

subdomain_guard_response($tenantId ? 204 : 403);
