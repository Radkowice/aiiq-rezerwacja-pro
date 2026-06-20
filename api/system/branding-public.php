<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
require_once __DIR__ . '/../system/tenant.php';

function branding_public_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function branding_public_cache_dir(): string
{
    return __DIR__ . '/../../data/cache/public-branding';
}

function branding_public_cache_key(): string
{
    $hosts = host_candidates();
    $hostKey = !empty($hosts) ? implode('|', $hosts) : (string)($_SERVER['HTTP_HOST'] ?? '');

    return hash('sha256', strtolower($hostKey));
}

function branding_public_cache_file(string $cacheKey): string
{
    return branding_public_cache_dir() . '/' . $cacheKey . '.json';
}

function branding_public_ensure_cache_dir(): bool
{
    $dir = branding_public_cache_dir();

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return is_dir($dir);
}

function branding_public_lock_cache(string $cacheKey)
{
    if (!branding_public_ensure_cache_dir()) {
        return null;
    }

    $lockHandle = @fopen(branding_public_cache_dir() . '/' . $cacheKey . '.lock', 'c');

    if (!$lockHandle) {
        return null;
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        return null;
    }

    return $lockHandle;
}

function branding_public_read_cache(string $cacheKey): ?array
{
    $file = branding_public_cache_file($cacheKey);

    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    $cache = json_decode((string) $raw, true);

    if (
        !is_array($cache)
        || !isset($cache['expires_at'], $cache['payload'])
        || (int) $cache['expires_at'] < time()
        || !is_array($cache['payload'])
    ) {
        return null;
    }

    return $cache['payload'];
}

function branding_public_write_cache(string $cacheKey, array $payload, int $ttlSeconds = 45): void
{
    if (!branding_public_ensure_cache_dir()) {
        return;
    }

    $cache = [
        'expires_at' => time() + $ttlSeconds,
        'payload' => $payload,
    ];

    @file_put_contents(
        branding_public_cache_file($cacheKey),
        json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function branding_public_request(string $url, string $serviceRoleKey, string $schema): array
{
    $attempts = 2;
    $lastResult = [
        'ok' => false,
        'retryable' => true,
        'response' => false,
        'curl_error' => '',
        'http_code' => 0,
        'data' => null,
        'json_valid' => false,
    ];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => supabaseHeaders($serviceRoleKey, $schema),
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $data = json_decode((string) $response, true);
        $jsonValid = json_last_error() === JSON_ERROR_NONE;
        $retryable = $response === false
            || $curlError !== ''
            || $httpCode === 429
            || $httpCode >= 500
            || $httpCode === 0;

        $lastResult = [
            'ok' => $response !== false
                && $curlError === ''
                && $httpCode >= 200
                && $httpCode < 300
                && $jsonValid
                && is_array($data),
            'retryable' => $retryable,
            'response' => $response,
            'curl_error' => $curlError,
            'http_code' => $httpCode,
            'data' => $data,
            'json_valid' => $jsonValid,
        ];

        if ($lastResult['ok'] || !$retryable || $attempt === $attempts) {
            break;
        }

        usleep(150000);
    }

    return $lastResult;
}

function branding_public_technical_status(array $result): int
{
    return (int)($result['http_code'] ?? 0) === 429 ? 429 : 503;
}

$cacheKey = branding_public_cache_key();
$cachedPayload = branding_public_read_cache($cacheKey);

if (is_array($cachedPayload)) {
    branding_public_json($cachedPayload);
}

$cacheLockHandle = branding_public_lock_cache($cacheKey);

if ($cacheLockHandle) {
    register_shutdown_function(static function () use ($cacheLockHandle): void {
        flock($cacheLockHandle, LOCK_UN);
        fclose($cacheLockHandle);
    });

    $cachedPayload = branding_public_read_cache($cacheKey);

    if (is_array($cachedPayload)) {
        branding_public_json($cachedPayload);
    }
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    branding_public_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

$tenantLookup = getTenantLookupFromHost($supabaseUrl, $serviceRoleKey, $schema);

if (($tenantLookup['status'] ?? '') === 'not_found') {
    branding_public_json([
        'success' => false,
        'error' => 'tenant_not_found',
        'message' => 'Ten adres nie jest zarejestrowany w AI-IQ Rezerwacja Pro.'
    ], 404);
}

if (($tenantLookup['status'] ?? '') !== 'found' || empty($tenantLookup['tenant_id'])) {
    $statusCode = (int)($tenantLookup['http_code'] ?? 503);
    $statusCode = $statusCode === 429 ? 429 : 503;

    branding_public_json([
        'success' => false,
        'error' => 'temporary_unavailable',
        'message' => 'Nie udało się chwilowo potwierdzić domeny kalendarza. Spróbuj ponownie za moment.'
    ], $statusCode);
}

$tenantId = (string) $tenantLookup['tenant_id'];
$url = $supabaseUrl
    . '/rest/v1/tenant_branding'
    . '?select=tenant_id,client_name,service_title_front,logo_url_front,favicon_url_front,calendar_front_style,calendar_form_fields,updated_at'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$brandingResult = branding_public_request($url, $serviceRoleKey, $schema);

if (!$brandingResult['ok']) {
    $statusCode = branding_public_technical_status($brandingResult);

    branding_public_json([
        'success' => false,
        'error' => 'temporary_unavailable',
        'message' => 'Nie udało się chwilowo pobrać brandingu. Spróbuj ponownie za moment.'
    ], $statusCode);
}

$data = $brandingResult['data'];

if (!is_array($data) || empty($data[0])) {
    branding_public_json([
        'success' => false,
        'error' => 'Nie znaleziono brandingu klienta'
    ], 404);
}

$row = $data[0];

$publicLogoUrl = branding_asset_public_url((string)($row['logo_url_front'] ?? ''), $tenantId, 'logo');
$publicFaviconUrl = branding_asset_public_url((string)($row['favicon_url_front'] ?? ''), $tenantId, 'favicon');
$planContext = plan_features_get_context((string) $tenantId);
$features = is_array($planContext['features'] ?? null) ? $planContext['features'] : [];
$publicFeatures = [
    'branding_logo' => !empty($features['branding_logo']),
    'branding_favicon' => !empty($features['branding_favicon']),
    'legal_documents' => !empty($features['legal_documents']),
    'online_payments' => !empty($features['online_payments']),
    'reschedule_booking' => !empty($features['reschedule_booking']),
];
$publicPlanCode = (string) ($planContext['plan_code'] ?? 'free');

$payload = [
    'success' => true,
    'plan_context' => [
        'plan_code' => $publicPlanCode,
        'is_free' => $publicPlanCode === 'free',
        'features' => $publicFeatures,
    ],
    'branding' => [
        'client_name' => $row['client_name'] ?? '',
        'service_title_front' => $row['service_title_front'] ?? '',
        'logo_url_front' => $publicLogoUrl,
        'favicon_url_front' => $publicFaviconUrl,
        'calendar_front_style' => is_array($row['calendar_front_style'] ?? null)
            ? $row['calendar_front_style']
            : [],
        'calendar_form_fields' => is_array($row['calendar_form_fields'] ?? null)
            ? $row['calendar_form_fields']
            : [],
    ],
];

branding_public_write_cache($cacheKey, $payload);
branding_public_json($payload);
