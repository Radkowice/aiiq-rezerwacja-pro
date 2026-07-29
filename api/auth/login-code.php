<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/login_security.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function admin_login_code_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_login_code_neutral_success(): void
{
    admin_login_code_json([
        'success' => true,
        'message' => 'Jeśli aktywne konto istnieje, kod logowania został wysłany.',
    ], 202);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    admin_login_code_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe.',
    ], 400);
}

$action = strtolower(trim((string) ($input['action'] ?? '')));
$email = login_security_normalize_email((string) ($input['email'] ?? ''));

if (!in_array($action, ['request', 'verify'], true)) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Nieprawidłowa operacja logowania.',
    ], 400);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Podaj poprawny adres e-mail.',
    ], 422);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    admin_login_code_json([
        'success' => false,
        'error' => 'Logowanie jest chwilowo niedostępne.',
    ], 503);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Nie udało się ustalić firmy dla tej domeny.',
    ], 400);
}

if (!login_security_storage_available($supabaseUrl, $supabaseKey, $schema)) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Logowanie kodem jest chwilowo niedostępne.',
    ], 503);
}

$rateAction = $action === 'request'
    ? 'auth_login_code_request_admin'
    : 'auth_login_code_verify_admin';
$rateLimit = login_security_rate_limit($rateAction, 'admin', (string) $tenantId, $email);

if (empty($rateLimit['ok'])) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Logowanie jest chwilowo niedostępne.',
    ], 503);
}

if (($rateLimit['allowed'] ?? true) === false) {
    $payload = security_neutral_rate_limit_response($rateLimit);
    $payload['error'] = (string) ($payload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
    admin_login_code_json($payload, 429);
}

$userResult = login_security_request(
    'GET',
    $supabaseUrl
        . '/rest/v1/users'
        . '?select=id,email,password_hash,tenant_id,role,is_active'
        . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
        . '&email=eq.' . rawurlencode($email)
        . '&limit=1',
    $supabaseKey,
    $schema
);

if (!$userResult['ok']) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Logowanie jest chwilowo niedostępne.',
    ], 503);
}

$user = login_security_first_row($userResult);
$userId = trim((string) ($user['id'] ?? ''));
$userTenantId = trim((string) ($user['tenant_id'] ?? ''));
$userEmail = login_security_normalize_email((string) ($user['email'] ?? ''));
$passwordHash = trim((string) ($user['password_hash'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));
$isActive = filter_var($user['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
$isValidAdmin = is_array($user)
    && $userId !== ''
    && $userTenantId !== ''
    && hash_equals((string) $tenantId, $userTenantId)
    && $userEmail !== ''
    && $passwordHash !== ''
    && hash_equals($email, $userEmail)
    && in_array($role, ['admin', 'administrator'], true)
    && $isActive;

if ($action === 'request') {
    if (!$isValidAdmin) {
        admin_login_code_neutral_success();
    }

    $challenge = login_security_issue_code(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        'admin',
        (string) $tenantId,
        $userId,
        $userEmail
    );

    if (!empty($challenge['ok'])) {
        $sent = login_security_send_code($userEmail, 'admin', (string) $challenge['code']);

        if (!$sent) {
            login_security_cancel_code(
                $supabaseUrl,
                $supabaseKey,
                $schema,
                (string) $challenge['challenge_id']
            );
        }
    }

    security_log_event('admin_login_code_requested', [
        'action_key' => $rateAction,
        'actor_type' => 'tenant_user',
        'tenant_id' => (string) $tenantId,
        'user_id' => $userId,
        'email' => $email,
        'ip_address' => security_client_ip(),
        'endpoint' => '/api/auth/login-code.php',
        'http_method' => 'POST',
        'response_status' => 202,
        'result' => 'accepted',
        'severity' => 'low',
        'details' => [
            'reason' => 'login_code_request',
            'challenge_created' => !empty($challenge['ok']),
        ],
    ]);

    admin_login_code_neutral_success();
}

$code = trim((string) ($input['code'] ?? ''));

if (!$isValidAdmin || preg_match('/^[0-9]{6}$/', $code) !== 1) {
    admin_login_code_json([
        'success' => false,
        'error' => 'Kod jest nieprawidłowy albo wygasł.',
    ], 401);
}

if (!login_security_verify_code(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'admin',
    (string) $tenantId,
    $userId,
    $code
)) {
    security_log_event('admin_login_code_failed', [
        'action_key' => $rateAction,
        'actor_type' => 'tenant_user',
        'tenant_id' => (string) $tenantId,
        'user_id' => $userId,
        'email' => $email,
        'ip_address' => security_client_ip(),
        'endpoint' => '/api/auth/login-code.php',
        'http_method' => 'POST',
        'response_status' => 401,
        'result' => 'failed',
        'severity' => 'high',
        'details' => [
            'reason' => 'invalid_or_expired_login_code',
        ],
    ]);

    admin_login_code_json([
        'success' => false,
        'error' => 'Kod jest nieprawidłowy albo wygasł.',
    ], 401);
}

session_regenerate_id(true);
unset($_SESSION['staff_user']);
$_SESSION['user'] = [
    'id' => $userId,
    'email' => $userEmail,
    'tenant_id' => (string) $tenantId,
    'role' => $role,
];

$trustedDeviceIssued = false;

if (($input['trust_device'] ?? false) === true) {
    $trustedDeviceIssued = login_security_issue_trusted_device(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        'admin',
        (string) $tenantId,
        $userId,
        $passwordHash
    );
}

security_log_event('admin_login_code_success', [
    'action_key' => $rateAction,
    'actor_type' => 'tenant_user',
    'tenant_id' => (string) $tenantId,
    'user_id' => $userId,
    'email' => $email,
    'ip_address' => security_client_ip(),
    'endpoint' => '/api/auth/login-code.php',
    'http_method' => 'POST',
    'response_status' => 200,
    'result' => 'success',
    'severity' => 'low',
    'details' => [
        'reason' => 'login_code_success',
        'trusted_device_issued' => $trustedDeviceIssued,
    ],
]);

admin_login_code_json([
    'success' => true,
    'trusted_device' => $trustedDeviceIssued,
    'user' => [
        'email' => $userEmail,
        'role' => $role,
    ],
]);
