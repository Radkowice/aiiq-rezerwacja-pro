<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/login_security.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_login_code_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_login_code_neutral_success(): void
{
    staff_login_code_json([
        'success' => true,
        'message' => 'Jeśli aktywne konto istnieje, kod logowania został wysłany.',
    ], 202);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_login_code_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe.',
    ], 400);
}

$action = strtolower(trim((string) ($input['action'] ?? '')));
$email = login_security_normalize_email((string) ($input['email'] ?? ''));

if (!in_array($action, ['request', 'verify'], true)) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Nieprawidłowa operacja logowania.',
    ], 400);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Podaj poprawny adres e-mail.',
    ], 422);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_login_code_json([
        'success' => false,
        'error' => 'Logowanie jest chwilowo niedostępne.',
    ], 503);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Nie udało się ustalić firmy dla tej domeny.',
    ], 400);
}

if (!tenant_has_feature((string) $tenantId, 'staff_module')) {
    staff_login_code_json([
        'success' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny dla kont z aktywnym planem Pro.',
    ], 403);
}

if (!login_security_storage_available($supabaseUrl, $supabaseKey, $schema)) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Logowanie kodem jest chwilowo niedostępne.',
    ], 503);
}

$rateAction = $action === 'request'
    ? 'auth_login_code_request_staff'
    : 'auth_login_code_verify_staff';
$rateLimit = login_security_rate_limit($rateAction, 'staff', (string) $tenantId, $email);

if (empty($rateLimit['ok'])) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Logowanie jest chwilowo niedostępne.',
    ], 503);
}

if (($rateLimit['allowed'] ?? true) === false) {
    $payload = security_neutral_rate_limit_response($rateLimit);
    $payload['error'] = (string) ($payload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
    staff_login_code_json($payload, 429);
}

$accountResult = login_security_request(
    'GET',
    $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?select=id,tenant_id,staff_id,email,password_hash,is_active'
        . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
        . '&email=eq.' . rawurlencode($email)
        . '&limit=1',
    $supabaseKey,
    $schema
);

if (!$accountResult['ok']) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Logowanie jest chwilowo niedostępne.',
    ], 503);
}

$account = login_security_first_row($accountResult);
$accountId = trim((string) ($account['id'] ?? ''));
$accountTenantId = trim((string) ($account['tenant_id'] ?? ''));
$staffId = trim((string) ($account['staff_id'] ?? ''));
$accountEmail = login_security_normalize_email((string) ($account['email'] ?? ''));
$passwordHash = trim((string) ($account['password_hash'] ?? ''));
$accountActive = filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
$accountValid = is_array($account)
    && $accountId !== ''
    && $accountTenantId !== ''
    && hash_equals((string) $tenantId, $accountTenantId)
    && $staffId !== ''
    && $accountEmail !== ''
    && $passwordHash !== ''
    && hash_equals($email, $accountEmail)
    && $accountActive;
$staff = null;

if ($accountValid) {
    $staffResult = login_security_request(
        'GET',
        $supabaseUrl
            . '/rest/v1/staff_profiles'
            . '?select=id,display_name,email,is_active'
            . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
            . '&id=eq.' . rawurlencode($staffId)
            . '&limit=1',
        $supabaseKey,
        $schema
    );

    if (!$staffResult['ok']) {
        staff_login_code_json([
            'success' => false,
            'error' => 'Logowanie jest chwilowo niedostępne.',
        ], 503);
    }

    $staff = login_security_first_row($staffResult);
}

$staffActive = filter_var($staff['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
$isValidStaff = $accountValid
    && is_array($staff)
    && hash_equals($staffId, (string) ($staff['id'] ?? ''))
    && $staffActive;

if ($action === 'request') {
    if (!$isValidStaff) {
        staff_login_code_neutral_success();
    }

    $challenge = login_security_issue_code(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        'staff',
        (string) $tenantId,
        $accountId,
        $accountEmail
    );

    if (!empty($challenge['ok'])) {
        $sent = login_security_send_code($accountEmail, 'staff', (string) $challenge['code']);

        if (!$sent) {
            login_security_cancel_code(
                $supabaseUrl,
                $supabaseKey,
                $schema,
                (string) $challenge['challenge_id']
            );
        }
    }

    security_log_event('staff_login_code_requested', [
        'action_key' => $rateAction,
        'actor_type' => 'staff_user',
        'tenant_id' => (string) $tenantId,
        'staff_account_id' => $accountId,
        'staff_id' => $staffId,
        'email' => $email,
        'ip_address' => security_client_ip(),
        'endpoint' => '/api/staff/login-code.php',
        'http_method' => 'POST',
        'response_status' => 202,
        'result' => 'accepted',
        'severity' => 'low',
        'details' => [
            'reason' => 'login_code_request',
            'challenge_created' => !empty($challenge['ok']),
        ],
    ]);

    staff_login_code_neutral_success();
}

$code = trim((string) ($input['code'] ?? ''));

if (!$isValidStaff || preg_match('/^[0-9]{6}$/', $code) !== 1) {
    staff_login_code_json([
        'success' => false,
        'error' => 'Kod jest nieprawidłowy albo wygasł.',
    ], 401);
}

if (!login_security_verify_code(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'staff',
    (string) $tenantId,
    $accountId,
    $code
)) {
    security_log_event('staff_login_code_failed', [
        'action_key' => $rateAction,
        'actor_type' => 'staff_user',
        'tenant_id' => (string) $tenantId,
        'staff_account_id' => $accountId,
        'staff_id' => $staffId,
        'email' => $email,
        'ip_address' => security_client_ip(),
        'endpoint' => '/api/staff/login-code.php',
        'http_method' => 'POST',
        'response_status' => 401,
        'result' => 'failed',
        'severity' => 'high',
        'details' => [
            'reason' => 'invalid_or_expired_login_code',
        ],
    ]);

    staff_login_code_json([
        'success' => false,
        'error' => 'Kod jest nieprawidłowy albo wygasł.',
    ], 401);
}

$displayName = trim((string) ($staff['display_name'] ?? ''));

if ($displayName === '') {
    $displayName = $accountEmail;
}

session_regenerate_id(true);
unset($_SESSION['user']);
$_SESSION['staff_user'] = [
    'account_id' => $accountId,
    'tenant_id' => (string) $tenantId,
    'staff_id' => $staffId,
    'email' => $accountEmail,
    'display_name' => $displayName,
];

$trustedDeviceIssued = false;

if (($input['trust_device'] ?? false) === true) {
    $trustedDeviceIssued = login_security_issue_trusted_device(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        'staff',
        (string) $tenantId,
        $accountId,
        $passwordHash
    );
}

$now = gmdate('c');
login_security_request(
    'PATCH',
    $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?tenant_id=eq.' . rawurlencode((string) $tenantId)
        . '&id=eq.' . rawurlencode($accountId)
        . '&staff_id=eq.' . rawurlencode($staffId),
    $supabaseKey,
    $schema,
    [
        'last_login_at' => $now,
        'updated_at' => $now,
    ],
    'return=minimal'
);

security_log_event('staff_login_code_success', [
    'action_key' => $rateAction,
    'actor_type' => 'staff_user',
    'tenant_id' => (string) $tenantId,
    'staff_account_id' => $accountId,
    'staff_id' => $staffId,
    'email' => $email,
    'ip_address' => security_client_ip(),
    'endpoint' => '/api/staff/login-code.php',
    'http_method' => 'POST',
    'response_status' => 200,
    'result' => 'success',
    'severity' => 'low',
    'details' => [
        'reason' => 'login_code_success',
        'trusted_device_issued' => $trustedDeviceIssued,
    ],
]);

$refSecret = public_response_ref_secret($supabaseKey);

staff_login_code_json([
    'success' => true,
    'trusted_device' => $trustedDeviceIssued,
    'staff' => [
        'staff_ref' => public_response_staff_ref((string) $tenantId, $staffId, $refSecret),
        'email' => $accountEmail,
        'display_name' => $displayName,
    ],
]);
