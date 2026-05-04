<?php

require_once __DIR__ . '/../../helpers/supabase.php';
require_once __DIR__ . '/../../helpers/crypto.php';

$frontendReturnUrl = '/panel-admina.php';

function redirect_with_status(string $status, string $message = ''): void
{
    global $frontendReturnUrl;

    $query = http_build_query([
        'google_calendar' => $status,
        'message' => $message,
    ]);

    header('Location: ' . $frontendReturnUrl . '?' . $query . '#integracje');
    exit;
}

function google_callback_supabase_request(
    string $url,
    string $method,
    string $key,
    string $schema,
    ?array $body = null,
    array $extraHeaders = []
): array {
    $headers = array_merge(
        supabaseHeaders($key, $schema),
        [
            'Accept: application/json',
        ],
        $extraHeaders
    );

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
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

$code = trim((string) ($_GET['code'] ?? ''));
$stateToken = trim((string) ($_GET['state'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

if ($error !== '') {
    redirect_with_status('error', 'Autoryzacja Google została przerwana.');
}

if ($code === '' || $stateToken === '') {
    redirect_with_status('error', 'Brak kodu autoryzacji Google.');
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

$googleClientId = trim((string) getenv('GOOGLE_CLIENT_ID'));
$googleClientSecret = trim((string) getenv('GOOGLE_CLIENT_SECRET'));
$googleRedirectUri = trim((string) getenv('GOOGLE_REDIRECT_URI'));

if (
    $supabaseUrl === ''
    || $supabaseKey === ''
    || $googleClientId === ''
    || $googleClientSecret === ''
    || $googleRedirectUri === ''
) {
    redirect_with_status('error', 'Brak konfiguracji Google OAuth.');
}

/**
 * 1. Odczyt i walidacja state.
 */
$stateUrl = $supabaseUrl
    . '/rest/v1/google_oauth_states'
    . '?select=id,state_token,tenant_id,user_id,expires_at,used_at'
    . '&state_token=eq.' . rawurlencode($stateToken)
    . '&provider=eq.google_calendar'
    . '&limit=1';

$stateResult = google_callback_supabase_request(
    $stateUrl,
    'GET',
    $supabaseKey,
    $schema
);

if ($stateResult['error'] || $stateResult['http_code'] !== 200) {
    redirect_with_status('error', 'Nie udało się sprawdzić tokenu state.');
}

$stateRow = $stateResult['data'][0] ?? null;

if (!$stateRow) {
    redirect_with_status('error', 'Nieprawidłowy token state.');
}

if (!empty($stateRow['used_at'])) {
    redirect_with_status('error', 'Ten token Google został już użyty.');
}

$expiresAt = strtotime((string) ($stateRow['expires_at'] ?? ''));

if (!$expiresAt || $expiresAt < time()) {
    redirect_with_status('error', 'Token Google wygasł. Spróbuj połączyć konto ponownie.');
}

$tenantId = (string) $stateRow['tenant_id'];

/**
 * 2. Wymiana code na tokeny.
 */
$tokenPayload = http_build_query([
    'code' => $code,
    'client_id' => $googleClientId,
    'client_secret' => $googleClientSecret,
    'redirect_uri' => $googleRedirectUri,
    'grant_type' => 'authorization_code',
]);

$tokenCh = curl_init('https://oauth2.googleapis.com/token');

curl_setopt_array($tokenCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => $tokenPayload,
    CURLOPT_TIMEOUT => 25,
]);

$tokenResponse = curl_exec($tokenCh);
$tokenHttpCode = (int) curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
$tokenCurlError = curl_error($tokenCh);

curl_close($tokenCh);

if ($tokenCurlError || $tokenHttpCode < 200 || $tokenHttpCode >= 300) {
    $logDir = __DIR__ . '/../../data';
    $logFile = $logDir . '/google-calendar.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] TOKEN_ERROR ' . json_encode([
            'http_code' => $tokenHttpCode,
            'curl_error' => $tokenCurlError,
            'response' => $tokenResponse,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    redirect_with_status('error', 'Nie udało się pobrać tokenów Google.');
}

$tokenData = json_decode((string) $tokenResponse, true);

if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    redirect_with_status('error', 'Google nie zwrócił poprawnych tokenów.');
}

$expiresIn = (int) ($tokenData['expires_in'] ?? 3600);
$tokenExpiresAt = (new DateTimeImmutable('+' . max(60, $expiresIn - 60) . ' seconds'))
    ->format(DateTimeInterface::ATOM);

$newSecrets = [
    'access_token' => (string) $tokenData['access_token'],
    'token_expires_at' => $tokenExpiresAt,
];

if (!empty($tokenData['refresh_token'])) {
    $newSecrets['refresh_token'] = (string) $tokenData['refresh_token'];
}

/**
 * 3. Jeśli Google nie zwróci refresh_token przy ponownym połączeniu,
 * zachowujemy stary refresh_token, o ile istnieje.
 */
$existingIntegrationUrl = $supabaseUrl
    . '/rest/v1/tenant_integrations'
    . '?select=settings,secrets'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&provider=eq.google_calendar'
    . '&limit=1';

$existingIntegrationResult = google_callback_supabase_request(
    $existingIntegrationUrl,
    'GET',
    $supabaseKey,
    $schema
);

$existingSettings = [];
$existingSecrets = [];

if (
    $existingIntegrationResult['http_code'] === 200
    && is_array($existingIntegrationResult['data'])
    && !empty($existingIntegrationResult['data'][0])
) {
    $existingRow = $existingIntegrationResult['data'][0];

    if (is_array($existingRow['settings'] ?? null)) {
        $existingSettings = $existingRow['settings'];
    }

    if (is_array($existingRow['secrets'] ?? null)) {
        try {
            $existingSecrets = decrypt_json_secret($existingRow['secrets']);
        } catch (Throwable $e) {
            $existingSecrets = [];
        }
    }
}

$mergedSecrets = array_merge($existingSecrets, $newSecrets);

try {
    $encryptedSecrets = encrypt_json_secret($mergedSecrets);
} catch (Throwable $e) {
    redirect_with_status('error', 'Nie udało się zaszyfrować tokenów Google.');
}

$settings = array_merge(
    [
        'calendar_id' => 'primary',
        'calendar_name' => 'Kalendarz główny',
        'sync_mode' => 'create_event_only',
    ],
    $existingSettings
);

$savePayload = [
    'tenant_id' => $tenantId,
    'provider' => 'google_calendar',
    'enabled' => true,
    'mode' => 'production',
    'settings' => $settings,
    'secrets' => $encryptedSecrets,
    'connected_at' => date('c'),
    'disconnected_at' => null,
];

$saveUrl = $supabaseUrl
    . '/rest/v1/tenant_integrations'
    . '?on_conflict=tenant_id,provider';

$saveResult = google_callback_supabase_request(
    $saveUrl,
    'POST',
    $supabaseKey,
    $schema,
    $savePayload,
    [
        'Prefer: resolution=merge-duplicates,return=minimal',
    ]
);

if ($saveResult['error'] || $saveResult['http_code'] < 200 || $saveResult['http_code'] >= 300) {
    redirect_with_status('error', 'Nie udało się zapisać integracji Google.');
}

/**
 * 4. Oznaczenie state jako użyte.
 */
$markStateUrl = $supabaseUrl
    . '/rest/v1/google_oauth_states'
    . '?state_token=eq.' . rawurlencode($stateToken);

google_callback_supabase_request(
    $markStateUrl,
    'PATCH',
    $supabaseKey,
    $schema,
    [
        'used_at' => date('c'),
    ],
    [
        'Prefer: return=minimal',
    ]
);

redirect_with_status('success', 'Google Calendar połączony.');