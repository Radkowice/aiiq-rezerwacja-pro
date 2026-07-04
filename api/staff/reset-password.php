<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/security.php';

function staff_reset_password_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_reset_password_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if (in_array(strtoupper($method), ['PATCH', 'POST', 'DELETE'], true)) {
        $headers = array_values(array_filter($headers, static function (string $header): bool {
            return stripos($header, 'Prefer:') !== 0;
        }));
        $headers[] = 'Prefer: return=minimal';
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'response' => $response,
        'error' => $curlError,
        'httpCode' => $httpCode,
        'data' => json_decode((string) $response, true),
    ];
}

function staff_reset_password_feature_locked(): void
{
    staff_reset_password_json([
        'success' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny w planie Pro. Twój abonament Pro wygasł albo konto działa w planie Free. Opłać abonament Pro, aby odzyskać dostęp do funkcji personelu.',
    ], 403);
}

function staff_reset_password_validation_error(string $password): string
{
    if (strlen($password) < 8) {
        return 'Nowe hasło musi mieć minimum 8 znaków.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Nowe hasło musi zawierać małą literę.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Nowe hasło musi zawierać dużą literę.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Nowe hasło musi zawierać cyfrę.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Nowe hasło musi zawierać znak specjalny.';
    }

    return '';
}

function staff_reset_password_security_context(
    ?string $tenantId = null,
    ?string $email = null,
    ?int $responseStatus = null,
    ?string $result = null,
    string $reason = ''
): array {
    $context = [
        'endpoint' => '/api/staff/reset-password.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'staff',
        'ip_address' => security_client_ip(),
        'details' => [
            'reason' => $reason,
        ],
    ];

    if ($tenantId !== null && $tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    if ($email !== null && $email !== '') {
        $context['email'] = $email;
    }

    if ($responseStatus !== null) {
        $context['response_status'] = $responseStatus;
    }

    if ($result !== null && $result !== '') {
        $context['result'] = $result;
    }

    return $context;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_reset_password_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_reset_password_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    staff_reset_password_json([
        'success' => false,
        'error' => 'Nie udało się ustalić firmy dla tej domeny.'
    ], 400);
}

if (!tenant_has_feature((string) $tenantId, 'staff_module')) {
    staff_reset_password_feature_locked();
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    security_log_event('staff_password_reset_invalid_json', staff_reset_password_security_context(
        (string) $tenantId,
        null,
        400,
        'failed',
        'staff_password_reset_invalid_json'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON.'
    ], 400);
}

$token = trim((string) ($input['token'] ?? ''));
$password = (string) ($input['password'] ?? '');
$passwordConfirm = (string) ($input['password_confirm'] ?? '');

if ($token === '') {
    security_log_event('staff_password_reset_invalid_token', staff_reset_password_security_context(
        (string) $tenantId,
        null,
        400,
        'failed',
        'staff_password_reset_missing_token'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Brak tokenu resetu hasła.'
    ], 400);
}

if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    security_log_event('staff_password_reset_invalid_token', staff_reset_password_security_context(
        (string) $tenantId,
        null,
        410,
        'failed',
        'staff_password_reset_invalid_token_format'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Link resetu hasła jest nieprawidłowy albo wygasł.'
    ], 410);
}

if ($password === '' || $passwordConfirm === '') {
    staff_reset_password_json([
        'success' => false,
        'error' => 'Wypełnij wszystkie pola.'
    ], 422);
}

if ($password !== $passwordConfirm) {
    staff_reset_password_json([
        'success' => false,
        'error' => 'Hasła nie są takie same.'
    ], 422);
}

$passwordError = staff_reset_password_validation_error($password);

if ($passwordError !== '') {
    staff_reset_password_json([
        'success' => false,
        'error' => $passwordError
    ], 422);
}

$securityIp = security_client_ip();
$securityEndpoint = '/api/staff/reset-password.php';
$securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';

$rateLimitResult = security_rate_limit_check(
    'staff_password_reset_token_probe',
    [
        'ip' => $securityIp,
    ],
    [
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'staff',
        'ip_address' => $securityIp,
        'metadata' => [
            'reason' => 'staff_password_reset_token_probe',
        ],
    ]
);

if (isset($rateLimitResult['allowed']) && $rateLimitResult['allowed'] === false) {
    security_log_event('staff_password_reset_token_probe_rate_limited', [
        'action_key' => 'staff_password_reset_token_probe',
        'ip_address' => $securityIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'severity' => 'high',
        'actor_type' => 'staff',
        'response_status' => 429,
        'result' => 'blocked',
        'details' => [
            'reason' => 'staff_password_reset_token_probe',
            'limiter' => 'security_rate_limit_check',
        ],
    ]);

    http_response_code(429);

    $rateLimitPayload = security_neutral_rate_limit_response($rateLimitResult);
    if (!isset($rateLimitPayload['error'])) {
        $rateLimitPayload['error'] = (string) ($rateLimitPayload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
    }

    echo json_encode($rateLimitPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tokenHash = hash('sha256', $token);
$now = gmdate('c');

$tokenUrl = $supabaseUrl
    . '/rest/v1/staff_password_reset_tokens'
    . '?select=id,tenant_id,staff_account_id,staff_id,email,expires_at,used_at'
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&token_hash=eq.' . rawurlencode($tokenHash)
    . '&used_at=is.null'
    . '&expires_at=gt.' . rawurlencode($now)
    . '&limit=1';

$tokenResult = staff_reset_password_request('GET', $tokenUrl, $supabaseKey, $schema);

if (
    $tokenResult['response'] === false
    || $tokenResult['error'] !== ''
    || $tokenResult['httpCode'] < 200
    || $tokenResult['httpCode'] >= 300
) {
    security_log_event('staff_password_reset_failed', staff_reset_password_security_context(
        (string) $tenantId,
        null,
        500,
        'error',
        'staff_password_reset_token_lookup_failed'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić tokenu resetu hasła.'
    ], 500);
}

$tokenRows = is_array($tokenResult['data'] ?? null) ? $tokenResult['data'] : [];
$tokenRow = is_array($tokenRows[0] ?? null) ? $tokenRows[0] : null;

if (!is_array($tokenRow) || empty($tokenRow['id']) || empty($tokenRow['staff_account_id']) || empty($tokenRow['staff_id'])) {
    security_log_event('staff_password_reset_invalid_or_expired_token', staff_reset_password_security_context(
        (string) $tenantId,
        null,
        410,
        'failed',
        'staff_password_reset_invalid_or_expired_token'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Link resetu hasła jest nieprawidłowy albo wygasł.'
    ], 410);
}

$tokenId = (string) ($tokenRow['id'] ?? '');
$accountId = (string) ($tokenRow['staff_account_id'] ?? '');
$staffId = (string) ($tokenRow['staff_id'] ?? '');
$email = strtolower(trim((string) ($tokenRow['email'] ?? '')));

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,tenant_id,staff_id,email,is_active'
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&id=eq.' . rawurlencode($accountId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$accountResult = staff_reset_password_request('GET', $accountUrl, $supabaseKey, $schema);

if (
    $accountResult['response'] === false
    || $accountResult['error'] !== ''
    || $accountResult['httpCode'] < 200
    || $accountResult['httpCode'] >= 300
) {
    security_log_event('staff_password_reset_failed', staff_reset_password_security_context(
        (string) $tenantId,
        $email,
        500,
        'error',
        'staff_password_reset_account_lookup_failed'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić konta personelu.'
    ], 500);
}

$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];
$account = is_array($accountRows[0] ?? null) ? $accountRows[0] : null;

if (!is_array($account) || empty($account['id']) || !filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    security_log_event('staff_password_reset_inactive_account', staff_reset_password_security_context(
        (string) $tenantId,
        $email,
        403,
        'failed',
        'staff_password_reset_inactive_account'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Konto personelu jest nieaktywne.'
    ], 403);
}

$accountEmail = strtolower(trim((string) ($account['email'] ?? '')));

if ($email !== '' && $accountEmail !== '' && !hash_equals($email, $accountEmail)) {
    security_log_event('staff_password_reset_token_account_mismatch', staff_reset_password_security_context(
        (string) $tenantId,
        $email,
        400,
        'failed',
        'staff_password_reset_token_account_mismatch'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Token resetu hasła nie pasuje do konta personelu.'
    ], 400);
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,is_active'
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_reset_password_request('GET', $staffUrl, $supabaseKey, $schema);

if (
    $staffResult['response'] === false
    || $staffResult['error'] !== ''
    || $staffResult['httpCode'] < 200
    || $staffResult['httpCode'] >= 300
) {
    security_log_event('staff_password_reset_failed', staff_reset_password_security_context(
        (string) $tenantId,
        $accountEmail !== '' ? $accountEmail : $email,
        500,
        'error',
        'staff_password_reset_profile_lookup_failed'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić profilu personelu.'
    ], 500);
}

$staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = is_array($staffRows[0] ?? null) ? $staffRows[0] : null;

if (!is_array($staff) || empty($staff['id']) || !filter_var($staff['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
    security_log_event('staff_password_reset_inactive_profile', staff_reset_password_security_context(
        (string) $tenantId,
        $accountEmail !== '' ? $accountEmail : $email,
        403,
        'failed',
        'staff_password_reset_inactive_profile'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Profil personelu jest nieaktywny.'
    ], 403);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$updatedAt = gmdate('c');

$updateUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&id=eq.' . rawurlencode($accountId)
    . '&staff_id=eq.' . rawurlencode($staffId);

$updateResult = staff_reset_password_request('PATCH', $updateUrl, $supabaseKey, $schema, [
    'password_hash' => $passwordHash,
    'updated_at' => $updatedAt,
]);

if (
    $updateResult['response'] === false
    || $updateResult['error'] !== ''
    || $updateResult['httpCode'] < 200
    || $updateResult['httpCode'] >= 300
) {
    security_log_event('staff_password_reset_failed', staff_reset_password_security_context(
        (string) $tenantId,
        $accountEmail !== '' ? $accountEmail : $email,
        500,
        'error',
        'staff_password_reset_password_update_failed'
    ));

    staff_reset_password_json([
        'success' => false,
        'error' => 'Nie udało się zapisać nowego hasła.'
    ], 500);
}

$usedUrl = $supabaseUrl
    . '/rest/v1/staff_password_reset_tokens'
    . '?id=eq.' . rawurlencode($tokenId)
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&used_at=is.null';

$usedResult = staff_reset_password_request('PATCH', $usedUrl, $supabaseKey, $schema, [
    'used_at' => $updatedAt,
]);

if (
    $usedResult['response'] === false
    || $usedResult['error'] !== ''
    || $usedResult['httpCode'] < 200
    || $usedResult['httpCode'] >= 300
) {
    security_log_event('staff_password_reset_token_mark_used_failed', staff_reset_password_security_context(
        (string) $tenantId,
        $accountEmail !== '' ? $accountEmail : $email,
        500,
        'error',
        'staff_password_reset_token_mark_used_failed'
    ));

    error_log('STAFF_PASSWORD_RESET_TOKEN_MARK_USED_FAILED tenant_id_set=' . ($tenantId !== '' ? 'true' : 'false') . ' token_id_set=' . ($tokenId !== '' ? 'true' : 'false'));
}

$invalidateUrl = $supabaseUrl
    . '/rest/v1/staff_password_reset_tokens'
    . '?tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&staff_account_id=eq.' . rawurlencode($accountId)
    . '&used_at=is.null';

$invalidateResult = staff_reset_password_request('PATCH', $invalidateUrl, $supabaseKey, $schema, [
    'used_at' => $updatedAt,
]);

if (
    $invalidateResult['response'] === false
    || $invalidateResult['error'] !== ''
    || $invalidateResult['httpCode'] < 200
    || $invalidateResult['httpCode'] >= 300
) {
    security_log_event('staff_password_reset_other_tokens_invalidate_failed', staff_reset_password_security_context(
        (string) $tenantId,
        $accountEmail !== '' ? $accountEmail : $email,
        500,
        'error',
        'staff_password_reset_other_tokens_invalidate_failed'
    ));

    error_log('STAFF_PASSWORD_RESET_OTHER_TOKENS_INVALIDATE_FAILED tenant_id_set=' . ($tenantId !== '' ? 'true' : 'false') . ' staff_account_id_set=' . ($accountId !== '' ? 'true' : 'false'));
}

security_log_event('staff_password_reset_success', staff_reset_password_security_context(
    (string) $tenantId,
    $accountEmail !== '' ? $accountEmail : $email,
    200,
    'success',
    'staff_password_reset_success'
));

staff_reset_password_json([
    'success' => true,
    'message' => 'Hasło zostało zmienione. Możesz się zalogować.'
]);
