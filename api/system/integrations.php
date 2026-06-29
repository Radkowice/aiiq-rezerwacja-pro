<?php

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/crypto.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user']['id'], $_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Nie zalogowany'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) $_SESSION['user']['tenant_id'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($supabaseUrl === '' || $supabaseKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedProviders = [
    'google_calendar',
    'payu',
    'stripe',
    'przelewy24'
];

$allowedModes = [
    'sandbox',
    'production'
];

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function curl_supabase(string $url, string $method, string $key, string $schema, ?array $body = null): array
{
    $headers = supabaseHeaders($key, $schema);
    $headers[] = 'Accept: application/json';

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $curlError,
        'data' => json_decode((string) $response, true),
    ];
}

function sanitize_bool($value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function filter_settings(string $provider, array $settings): array
{
    $allowed = [
        'google_calendar' => [
            'calendar_id',
            'calendar_name',
            'sync_mode',
        ],
        'payu' => [
            'pos_id',
            'client_id',
        ],
        'stripe' => [
            'public_key',
        ],
        'przelewy24' => [
            'merchant_id',
            'pos_id',
        ],
    ];

    $result = [];

    foreach ($allowed[$provider] ?? [] as $key) {
        if (array_key_exists($key, $settings)) {
            $result[$key] = trim((string) $settings[$key]);
        }
    }

    return $result;
}

function filter_secrets(string $provider, array $secrets): array
{
    $allowed = [
        'google_calendar' => [
            'access_token',
            'refresh_token',
            'token_expires_at',
        ],
        'payu' => [
            'client_secret',
            'second_key',
        ],
        'stripe' => [
            'secret_key',
            'webhook_secret',
        ],
        'przelewy24' => [
            'crc_key',
            'api_key',
        ],
    ];

    $result = [];

    foreach ($allowed[$provider] ?? [] as $key) {
        if (array_key_exists($key, $secrets)) {
            $value = trim((string) $secrets[$key]);

            if ($value !== '') {
                $result[$key] = $value;
            }
        }
    }

    return $result;
}

function safe_integration_row(array $row): array
{
    $provider = (string) ($row['provider'] ?? '');
    $storedSecrets = is_array($row['secrets'] ?? null) ? $row['secrets'] : [];

    try {
        $secrets = decrypt_json_secret($storedSecrets);
    } catch (Throwable $e) {
        $secrets = [];
    }

    $safe = [
        'provider' => $provider,
        'enabled' => (bool) ($row['enabled'] ?? false),
        'mode' => (string) ($row['mode'] ?? 'sandbox'),
        'settings' => is_array($row['settings'] ?? null) ? $row['settings'] : [],
        'connected_at' => $row['connected_at'] ?? null,
        'disconnected_at' => $row['disconnected_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'secrets_status' => [],
    ];

    if ($provider === 'payu') {
        $safe['secrets_status'] = [
            'client_secret_saved' => !empty($secrets['client_secret']),
            'second_key_saved' => !empty($secrets['second_key']),
        ];
    }

    if ($provider === 'google_calendar') {
        $safe['secrets_status'] = [
            'access_token_saved' => !empty($secrets['access_token']),
            'refresh_token_saved' => !empty($secrets['refresh_token']),
        ];
    }

    return $safe;
}

function integration_feature_available(string $tenantId, string $provider): bool
{
    if ($provider === 'payu') {
        return tenant_has_feature($tenantId, 'online_payments')
            && tenant_has_feature($tenantId, 'payu');
    }

    if ($provider === 'google_calendar') {
        return tenant_has_feature($tenantId, 'google_calendar');
    }

    return true;
}

function integration_feature_error_payload(string $provider): array
{
    if ($provider === 'payu') {
        return [
            'success' => false,
            'error' => 'Integracja PayU jest niedostępna w aktualnym planie.',
            'upgrade_required' => true,
        ];
    }

    if ($provider === 'google_calendar') {
        return [
            'success' => false,
            'error' => 'Integracja Google Calendar jest niedostępna w aktualnym planie.',
            'upgrade_required' => true,
        ];
    }

    return [
        'success' => false,
        'error' => 'Ta integracja jest niedostępna w aktualnym planie.',
        'upgrade_required' => true,
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $url = $supabaseUrl
        . '/rest/v1/tenant_integrations'
        . '?select=tenant_id,provider,enabled,mode,settings,secrets,connected_at,disconnected_at,created_at,updated_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&order=provider.asc';

    $result = curl_supabase($url, 'GET', $supabaseKey, $schema);

    if ($result['error']) {
        json_response([
            'success' => false,
            'error' => 'Błąd połączenia z Supabase'
        ], 500);
    }

    if ($result['http_code'] !== 200) {
        json_response([
            'success' => false,
            'error' => 'Błąd odczytu integracji'
        ], $result['http_code'] ?: 500);
    }

    $rows = is_array($result['data']) ? $result['data'] : [];
    $integrations = [];

    foreach ($rows as $row) {
        $safe = safe_integration_row($row);
        $integrations[$safe['provider']] = $safe;
    }

    json_response([
        'success' => true,
        'integrations' => $integrations
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowe dane JSON'
    ], 400);
}

$provider = trim((string) ($input['provider'] ?? ''));

if (!in_array($provider, $allowedProviders, true)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy provider integracji'
    ], 400);
}

$mode = trim((string) ($input['mode'] ?? 'sandbox'));

if (!in_array($mode, $allowedModes, true)) {
    $mode = 'sandbox';
}

$enabled = sanitize_bool($input['enabled'] ?? false);

$settingsInput = is_array($input['settings'] ?? null) ? $input['settings'] : [];
$secretsInput = is_array($input['secrets'] ?? null) ? $input['secrets'] : [];

$settings = filter_settings($provider, $settingsInput);
$newSecrets = filter_secrets($provider, $secretsInput);

/**
 * Pobieramy obecne secrets, żeby puste pole hasła w panelu
 * nie skasowało zapisanych wcześniej kluczy.
 */
$existingUrl = $supabaseUrl
    . '/rest/v1/tenant_integrations'
    . '?select=settings,secrets,mode'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&provider=eq.' . rawurlencode($provider)
    . '&limit=1';

$existingResult = curl_supabase($existingUrl, 'GET', $supabaseKey, $schema);

$existingSecrets = [];
$existingSettings = [];
$existingMode = $mode;

if (
    $existingResult['http_code'] === 200
    && is_array($existingResult['data'])
    && !empty($existingResult['data'][0])
    && is_array($existingResult['data'][0])
) {
    $existingRow = $existingResult['data'][0];

    if (is_array($existingRow['settings'] ?? null)) {
        $existingSettings = $existingRow['settings'];
    }

    $storedMode = trim((string) ($existingRow['mode'] ?? ''));
    if (in_array($storedMode, $allowedModes, true)) {
        $existingMode = $storedMode;
    }

    if (!empty($existingRow['secrets']) && is_array($existingRow['secrets'])) {
        try {
            $existingSecrets = decrypt_json_secret($existingRow['secrets']);
        } catch (Throwable $e) {
            $existingSecrets = [];
        }
    }
}

if (!integration_feature_available($tenantId, $provider)) {
    if ($enabled) {
        json_response(integration_feature_error_payload($provider), 403);
    }

    $settings = $existingSettings;
    $mode = $existingMode;
    $newSecrets = [];
}

$mergedSecrets = array_merge($existingSecrets, $newSecrets);

try {
    $encryptedSecrets = encrypt_json_secret($mergedSecrets);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się zaszyfrować danych integracji'
    ], 500);
}

$payload = [
    'tenant_id' => $tenantId,
    'provider' => $provider,
    'enabled' => $enabled,
    'mode' => $mode,
    'settings' => $settings,
    'secrets' => $encryptedSecrets,
    'connected_at' => $enabled ? date('c') : null,
    'disconnected_at' => $enabled ? null : date('c'),
];

$saveUrl = $supabaseUrl
    . '/rest/v1/tenant_integrations'
    . '?on_conflict=tenant_id,provider';

$saveResult = curl_supabase($saveUrl, 'POST', $supabaseKey, $schema, $payload);

if ($saveResult['error']) {
    json_response([
        'success' => false,
        'error' => 'Błąd połączenia z Supabase'
    ], 500);
}

if ($saveResult['http_code'] < 200 || $saveResult['http_code'] >= 300) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się zapisać integracji'
    ], $saveResult['http_code'] ?: 500);
}

$row = is_array($saveResult['data']) && isset($saveResult['data'][0])
    ? $saveResult['data'][0]
    : $payload;

json_response([
    'success' => true,
    'message' => 'Integracja zapisana',
    'integration' => safe_integration_row($row)
]);
