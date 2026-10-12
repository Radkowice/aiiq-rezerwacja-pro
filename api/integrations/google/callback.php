<?php

require_once __DIR__ . '/../../helpers/supabase.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/plan_features.php';
require_once __DIR__ . '/../../helpers/security.php';

const GOOGLE_CALLBACK_FALLBACK_HOST = 'rezerwacja-ai-iq.pl';
const GOOGLE_CALLBACK_RETURN_PATH = '/panel-admina.php';
const GOOGLE_CALLBACK_RETURN_HOST_ERROR = 'Nie udało się ustalić domeny powrotu';


function google_callback_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    array $context = []
): void {
    $details = [
        'reason' => $reason,
    ];

    if (isset($context['stage']) && is_scalar($context['stage']) && trim((string) $context['stage']) !== '') {
        $details['stage'] = trim((string) $context['stage']);
    }

    security_log_event($eventKey, [
        'action_key' => 'google_calendar_callback',
        'endpoint' => '/api/integrations/google/callback.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'actor_type' => $context['actor_type'] ?? 'public',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'tenant_id' => $context['tenant_id'] ?? null,
        'user_id' => $context['user_id'] ?? null,
        'details' => $details,
    ]);
}

function google_callback_normalize_return_host(?string $rawHost): string
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

    foreach (explode('.', $host) as $label) {
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

function redirect_with_status(string $status, string $message = '', string $returnHost = ''): void
{
    $returnHost = google_callback_normalize_return_host($returnHost);

    if ($returnHost === '') {
        $returnHost = GOOGLE_CALLBACK_FALLBACK_HOST;
        $status = 'error';
        $message = GOOGLE_CALLBACK_RETURN_HOST_ERROR;
    }

    $query = http_build_query(
        [
            'google_calendar' => $status,
            'message' => $message,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    header(
        'Location: https://'
        . $returnHost
        . GOOGLE_CALLBACK_RETURN_PATH
        . '?'
        . $query
        . '#integracje'
    );
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

function google_callback_tenant_owns_host(
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

    $result = google_callback_supabase_request(
        $url,
        'GET',
        $supabaseKey,
        $schema
    );

    return $result['error'] === ''
        && $result['http_code'] === 200
        && isset($result['data'][0])
        && is_array($result['data'][0])
        && hash_equals($tenantId, (string) ($result['data'][0]['tenant_id'] ?? ''));
}

/** Recheck the current tenant-bound administrator before consuming OAuth state. */
function google_callback_active_admin(string $base, string $key, string $schema, string $tenant, string $actor): bool
{
    if (!preg_match('/\A[0-9a-f-]{36}\z/iD', $actor)) { return false; }
    $url = $base . '/rest/v1/users?select=id,tenant_id,role,is_active'
         . '&tenant_id=eq.' . rawurlencode($tenant)
         . '&id=eq.' . rawurlencode($actor) . '&limit=2';
    $result = google_callback_supabase_request($url, 'GET', $key, $schema);
    $rows = $result['data'] ?? null;
    return ($result['error'] ?? null) === '' && ($result['http_code'] ?? 0) === 200
        && is_array($rows) && count($rows) === 1
        && ($rows[0]['tenant_id'] ?? null) === $tenant
        && ($rows[0]['id'] ?? null) === $actor
        && ($rows[0]['role'] ?? null) === 'administrator'
        && ($rows[0]['is_active'] ?? null) === true;
}

function google_callback_mark_state_used(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $stateId,
    string $stateToken
): bool {
    $url = $supabaseUrl
        . '/rest/v1/google_oauth_states'
        . '?id=eq.' . rawurlencode($stateId)
        . '&state_token=eq.' . rawurlencode($stateToken)
        . '&provider=eq.google_calendar'
        . '&used_at=is.null';

    $result = google_callback_supabase_request(
        $url,
        'PATCH',
        $supabaseKey,
        $schema,
        [
            'used_at' => date('c'),
        ],
        [
            'Prefer: return=representation',
        ]
    );

    return $result['error'] === ''
        && $result['http_code'] >= 200
        && $result['http_code'] < 300
        && isset($result['data'][0])
        && is_array($result['data'][0]);
}

$code = trim((string) ($_GET['code'] ?? ''));
$stateToken = trim((string) ($_GET['state'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

if ($stateToken === '') {
    google_callback_security_event('google_calendar_callback_state_missing', 'state_missing', 400);
    redirect_with_status('error');
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($supabaseUrl === '' || $supabaseKey === '') {
    google_callback_security_event('google_calendar_callback_env_missing', 'env_missing', 500, 'error', 'high');
    redirect_with_status('error');
}

/**
 * 1. Odczyt i walidacja state.
 */
$stateUrl = $supabaseUrl
    . '/rest/v1/google_oauth_states'
    . '?select=id,state_token,tenant_id,user_id,expires_at,used_at,return_host'
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
    google_callback_security_event('google_calendar_callback_state_lookup_failed', 'state_lookup_failed', 500, 'error', 'high');
    redirect_with_status('error', 'Nie udało się sprawdzić tokenu state.');
}

$stateRow = $stateResult['data'][0] ?? null;

if (!$stateRow) {
    google_callback_security_event('google_calendar_callback_state_not_found', 'state_not_found', 400);
    redirect_with_status('error', 'Nieprawidłowy token state.');
}

if (!empty($stateRow['used_at'])) {
    google_callback_security_event('google_calendar_callback_state_used', 'state_used', 409, 'failed', 'medium', [
        'tenant_id' => (string) ($stateRow['tenant_id'] ?? ''),
        'user_id' => (string) ($stateRow['user_id'] ?? ''),
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error', 'Ten token Google został już użyty.');
}

$expiresAt = strtotime((string) ($stateRow['expires_at'] ?? ''));

if (!$expiresAt || $expiresAt < time()) {
    google_callback_security_event('google_calendar_callback_state_expired', 'state_expired', 410, 'failed', 'medium', [
        'tenant_id' => (string) ($stateRow['tenant_id'] ?? ''),
        'user_id' => (string) ($stateRow['user_id'] ?? ''),
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error', 'Token Google wygasł. Spróbuj połączyć konto ponownie.');
}

$stateId = trim((string) ($stateRow['id'] ?? ''));
$tenantId = trim((string) ($stateRow['tenant_id'] ?? ''));
$userId = trim((string) ($stateRow['user_id'] ?? ''));
$returnHost = google_callback_normalize_return_host((string) ($stateRow['return_host'] ?? ''));

if (
    $stateId === ''
    || $tenantId === ''
    || $returnHost === ''
    || !google_callback_tenant_owns_host($supabaseUrl, $supabaseKey, $schema, $tenantId, $returnHost)
) {
    google_callback_security_event('google_calendar_callback_tenant_denied', 'tenant_denied', 403, 'denied', 'medium', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error');
}

if (!tenant_has_feature($tenantId, 'google_calendar')) {
    google_callback_security_event('google_calendar_callback_feature_denied', 'feature_denied', 403, 'denied', 'medium', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status(
        'error',
        'Funkcja Google Calendar jest niedostępna w aktualnym planie.',
        $returnHost
    );
}

if (!google_callback_active_admin($supabaseUrl, $supabaseKey, $schema, $tenantId, $userId)) {
    google_callback_security_event('google_calendar_callback_role_denied', 'role_denied', 403, 'denied', 'high',
        ['tenant_id' => $tenantId, 'user_id' => $userId]);
    redirect_with_status('error', 'Utracono uprawnienia do integracji Google.', $returnHost);
}

if (!google_callback_mark_state_used($supabaseUrl, $supabaseKey, $schema, $stateId, $stateToken)) {
    google_callback_security_event('google_calendar_callback_state_mark_failed', 'state_mark_failed', 500, 'error', 'high', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status(
        'error',
        'Nie udało się bezpiecznie zakończyć autoryzacji Google.',
        $returnHost
    );
}

if ($error !== '') {
    google_callback_security_event('google_calendar_callback_google_error', 'google_error', 400, 'failed', 'medium', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error', 'Autoryzacja Google została przerwana.', $returnHost);
}

if ($code === '') {
    google_callback_security_event('google_calendar_callback_code_missing', 'code_missing', 400, 'failed', 'medium', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error', 'Brak kodu autoryzacji Google.', $returnHost);
}

$googleClientId = trim((string) getenv('GOOGLE_CLIENT_ID'));
$googleClientSecret = trim((string) getenv('GOOGLE_CLIENT_SECRET'));
$googleRedirectUri = trim((string) getenv('GOOGLE_REDIRECT_URI'));

if ($googleClientId === '' || $googleClientSecret === '' || $googleRedirectUri === '') {
    google_callback_security_event('google_calendar_callback_oauth_env_missing', 'oauth_env_missing', 500, 'error', 'high', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error', 'Brak konfiguracji Google OAuth.', $returnHost);
}

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
$tokenCurlErrno = (int) curl_errno($tokenCh);
$tokenCurlError = curl_error($tokenCh);

curl_close($tokenCh);

if ($tokenCurlError || $tokenHttpCode < 200 || $tokenHttpCode >= 300) {
    google_callback_security_event('google_calendar_callback_token_exchange_failed', 'token_exchange_failed', $tokenHttpCode ?: 500, 'error', 'high', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    $logDir = __DIR__ . '/../../data';
    $logFile = $logDir . '/google-calendar.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $tokenErrorData = json_decode((string) $tokenResponse, true);
    $tokenErrorType = '';

    if (is_array($tokenErrorData) && is_scalar($tokenErrorData['error'] ?? null)) {
        $candidateError = trim((string) $tokenErrorData['error']);

        if (preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $candidateError) === 1) {
            $tokenErrorType = $candidateError;
        }
    }

    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] TOKEN_ERROR ' . json_encode([
            'http_code' => $tokenHttpCode,
            'curl_errno' => $tokenCurlErrno,
            'has_curl_error' => $tokenCurlError !== '',
            'has_response' => is_string($tokenResponse) && $tokenResponse !== '',
            'response_length' => is_string($tokenResponse) ? strlen($tokenResponse) : 0,
            'error_type' => $tokenErrorType !== '' ? $tokenErrorType : null,
            'has_error_description' => is_array($tokenErrorData)
                && is_scalar($tokenErrorData['error_description'] ?? null)
                && trim((string) $tokenErrorData['error_description']) !== '',
            'has_redirect_uri' => $googleRedirectUri !== '',
            'has_client_id' => $googleClientId !== '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    redirect_with_status('error', 'Nie udało się pobrać tokenów Google.', $returnHost);
}

$tokenData = json_decode((string) $tokenResponse, true);

if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    google_callback_security_event('google_calendar_callback_token_invalid', 'token_invalid', 500, 'error', 'high', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error', 'Google nie zwrócił poprawnych tokenów.', $returnHost);
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
 * A successful new consent must provide its OWN refresh token. Never merge
 * credentials from the previously connected account (A) with new account B.
 * Google Connect requests offline access + prompt=consent.
 */
if (!is_string($newSecrets['refresh_token'] ?? null)
    || trim($newSecrets['refresh_token']) === '') {
    google_callback_security_event('google_calendar_callback_refresh_missing',
        'refresh_missing', 409, 'failed', 'high', ['tenant_id' => $tenantId]);
    redirect_with_status('error', 'Google nie zwrócił nowego tokenu odświeżania. Połączenie nie zostało zmienione.', $returnHost);
}
$mergedSecrets = $newSecrets;
$existingSettings = []; // Never inherit an old account's calendar_id.

try {
    $encryptedSecrets = encrypt_json_secret($mergedSecrets);
} catch (Throwable $e) {
    google_callback_security_event('google_calendar_callback_encrypt_failed', 'encrypt_failed', 500, 'error', 'critical', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'actor_type' => 'tenant_user',
    ]);

    redirect_with_status('error', 'Nie udało się zaszyfrować tokenów Google.', $returnHost);
}

// New OAuth consent is a new immutable identity generation, even if the
// previous account and the connection timestamp happen to be identical.
$generationBytes = random_bytes(16);
$generationBytes[6] = chr((ord($generationBytes[6]) & 0x0f) | 0x40);
$generationBytes[8] = chr((ord($generationBytes[8]) & 0x3f) | 0x80);
$generationHex = bin2hex($generationBytes);
$connectionGeneration = substr($generationHex, 0, 8) . '-' . substr($generationHex, 8, 4)
    . '-' . substr($generationHex, 12, 4) . '-' . substr($generationHex, 16, 4)
    . '-' . substr($generationHex, 20, 12);
$settings = [
    'calendar_id' => 'primary',
    'calendar_name' => 'Kalendarz główny',
    'sync_mode' => 'create_event_only',
    'connection_generation' => $connectionGeneration,
];

// F10: final authorization, most-recent-started intent check and credential
// write are one PostgreSQL transaction. A stale OAuth callback MUST NOT use
// REST upsert of tenant_integrations even if a subsequent readback fails.
// The begin-state INSERT on connect.php is fenced by the DB AFTER INSERT
// trigger installed with this exact SQL migration.
if (!google_callback_active_admin($supabaseUrl, $supabaseKey, $schema, $tenantId, $userId)) {
    google_callback_security_event('google_calendar_callback_role_changed', 'role_changed', 403, 'denied', 'high',
        ['tenant_id' => $tenantId, 'user_id' => $userId]);
    redirect_with_status('error', 'Uprawnienia do integracji uległy zmianie.', $returnHost);
}

$finishUrl = $supabaseUrl . '/rest/v1/rpc/google_calendar_oauth_finish_latest';
$finishResult = google_callback_supabase_request(
    $finishUrl,
    'POST',
    $supabaseKey,
    $schema,
    [
        'p_tenant_id' => $tenantId,
        'p_actor_id' => $userId,
        'p_state_id' => $stateId,
        'p_state_token' => $stateToken,
        'p_generation' => $connectionGeneration,
        'p_encrypted_secrets' => $encryptedSecrets,
    ]
);
$finishData = $finishResult['data'] ?? null;
if ($finishResult['error'] !== ''
    || ($finishResult['http_code'] ?? 0) !== 200
    || !is_array($finishData)
    || ($finishData['ok'] ?? null) !== true
    || ($finishData['generation'] ?? null) !== $connectionGeneration) {
    google_callback_security_event('google_calendar_callback_atomic_save_refused',
        'atomic_save_refused', 409, 'denied', 'high', ['tenant_id' => $tenantId, 'user_id' => $userId]);
    redirect_with_status('error', 'Połączenie Google zostało zastąpione lub utraciło ważność. Uruchom połączenie ponownie.', $returnHost);
}

// Verify the newly-connected generation was actually persisted. A concurrent
// callback may have replaced it; never report that ambiguous case as success.
$readbackUrl = $supabaseUrl . '/rest/v1/tenant_integrations'
    . '?select=id,tenant_id,provider,enabled,disconnected_at,settings'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&provider=eq.google_calendar&limit=2';
$readback = google_callback_supabase_request($readbackUrl, 'GET', $supabaseKey, $schema);
$readbackRows = $readback['data'] ?? null;
if (($readback['http_code'] ?? 0) !== 200 || !is_array($readbackRows)
    || count($readbackRows) !== 1
    || ($readbackRows[0]['tenant_id'] ?? null) !== $tenantId
    || ($readbackRows[0]['provider'] ?? null) !== 'google_calendar'
    || ($readbackRows[0]['enabled'] ?? null) !== true
    || ($readbackRows[0]['disconnected_at'] ?? null) !== null
    || ($readbackRows[0]['settings']['connection_generation'] ?? null) !== $connectionGeneration) {
    google_callback_security_event('google_calendar_callback_generation_unverified',
        'generation_unverified', 503, 'error', 'high', ['tenant_id' => $tenantId]);
    redirect_with_status('error', 'Nie udało się potwierdzić nowej integracji Google.', $returnHost);
}

google_callback_security_event('google_calendar_callback_success', 'google_calendar_callback_success', 200, 'success', 'medium', [
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'actor_type' => 'tenant_user',
]);

redirect_with_status('success', 'Google Calendar połączony.', $returnHost);
