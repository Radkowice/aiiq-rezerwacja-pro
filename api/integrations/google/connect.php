<?php

require_once __DIR__ . '/../../helpers/session.php';
require_once __DIR__ . '/../../helpers/supabase.php';

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

$stateToken = bin2hex(random_bytes(32));
$expiresAt = (new DateTimeImmutable('+10 minutes'))->format(DateTimeInterface::ATOM);

$payload = [
    'state_token' => $stateToken,
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'provider' => 'google_calendar',
    'expires_at' => $expiresAt,
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