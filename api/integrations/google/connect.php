<?php

require_once __DIR__ . '/../../helpers/session.php';
require_once __DIR__ . '/../../helpers/supabase.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

function google_connect_normalize_return_host(?string $rawHost): string
{
    $host = strtolower(trim((string) $rawHost));

    if ($host === '' || preg_match('/[\x00-\x1F\x7F]/', $host)) {
        return '';
    }

    $host = preg_replace('#^https?://#i', '', $host);

    if (!is_string($host) || $host === '') {
        return '';
    }

    $host = preg_replace('/:\d+$/', '', $host);

    if (!is_string($host)) {
        return '';
    }

    $host = rtrim($host, '.');

    if (
        $host === ''
        || strlen($host) > 253
        || preg_match('/[\/\\\\:?#\s]/', $host)
        || !preg_match('/^[a-z0-9.-]+$/', $host)
    ) {
        return '';
    }

    $labels = explode('.', $host);

    foreach ($labels as $label) {
        if (
            $label === ''
            || strlen($label) > 63
            || str_starts_with($label, '-')
            || str_ends_with($label, '-')
        ) {
            return '';
        }
    }

    return $host;
}

function google_connect_tenant_owns_host(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $host
): bool {
    $url = $supabaseUrl
        . '/rest/v1/tenant_domains'
        . '?select=tenant_id'
        . '&domain=eq.' . rawurlencode($host)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&limit=1';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => array_merge(
            supabaseHeaders($supabaseKey, $schema),
            [
                'Accept: application/json',
            ]
        ),
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode !== 200) {
        return false;
    }

    $data = json_decode((string) $response, true);

    return is_array($data)
        && isset($data[0])
        && is_array($data[0])
        && hash_equals($tenantId, (string) ($data[0]['tenant_id'] ?? ''));
}

if (!isset($_SESSION['user']['id'], $_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Nie zalogowany'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$userId = (string) $_SESSION['user']['id'];

$googleClientId = trim((string) getenv('GOOGLE_CLIENT_ID'));
$googleRedirectUri = trim((string) getenv('GOOGLE_REDIRECT_URI'));

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($googleClientId === '' || $googleRedirectUri === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Google OAuth'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($supabaseUrl === '' || $supabaseKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$returnHost = google_connect_normalize_return_host($_SERVER['HTTP_HOST'] ?? '');

if ($returnHost === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się ustalić poprawnej domeny powrotu.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!google_connect_tenant_owns_host($supabaseUrl, $supabaseKey, $schema, $tenantId, $returnHost)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Domena powrotu nie należy do bieżącego klienta.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stateToken = bin2hex(random_bytes(32));
$expiresAt = (new DateTimeImmutable('+10 minutes'))->format(DateTimeInterface::ATOM);

$payload = [
    'state_token' => $stateToken,
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'provider' => 'google_calendar',
    'expires_at' => $expiresAt,
    'return_host' => $returnHost,
];

$insertUrl = $supabaseUrl . '/rest/v1/google_oauth_states';

$ch = curl_init($insertUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => array_merge(
        supabaseHeaders($supabaseKey, $schema),
        [
            'Accept: application/json',
            'Prefer: return=minimal',
        ]
    ),
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd połączenia z Supabase',
        'debug' => $curlError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode ?: 500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się rozpocząć autoryzacji Google',
        'debug' => $response
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$scope = implode(' ', [
    'https://www.googleapis.com/auth/calendar.events',
]);

$query = http_build_query([
    'client_id' => $googleClientId,
    'redirect_uri' => $googleRedirectUri,
    'response_type' => 'code',
    'scope' => $scope,
    'access_type' => 'offline',
    'include_granted_scopes' => 'true',
    'prompt' => 'consent',
    'state' => $stateToken,
]);

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;

echo json_encode([
    'success' => true,
    'auth_url' => $authUrl
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
