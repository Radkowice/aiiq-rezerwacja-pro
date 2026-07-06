<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

$SUPABASE_URL = rtrim((string) getenv('SUPABASE_URL'), '/');
$SUPABASE_KEY = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$SCHEMA       = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Brak konfiguracji Supabase'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

// 🔑 TENANT Z DOMENY (kluczowe!)
$tenantId = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA);

if (!$tenantId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak tenant']);
    exit;
}

// 📥 dane z formularza
$input = json_decode(file_get_contents('php://input'), true);

$email    = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$securityEmail = function_exists('mb_strtolower')
    ? mb_strtolower($email, 'UTF-8')
    : strtolower($email);
$securityIp = security_client_ip();
$securityEndpoint = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/auth/login.php');
$securityMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Podaj email i hasło']);
    exit;
}

$rateLimitResult = security_rate_limit_check(
    'auth_login_user',
    [
        'tenant_id' => $tenantId,
        'email' => $securityEmail,
        'ip' => $securityIp,
    ],
    [
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'tenant_id' => $tenantId,
        'email' => $securityEmail,
        'metadata' => [
            'reason' => 'login_attempt',
        ],
    ]
);

if (isset($rateLimitResult['allowed']) && $rateLimitResult['allowed'] === false) {
    http_response_code(429);

    $rateLimitPayload = security_neutral_rate_limit_response($rateLimitResult);
    if (!isset($rateLimitPayload['error'])) {
        $rateLimitPayload['error'] = (string) ($rateLimitPayload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
    }

    echo json_encode($rateLimitPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 🔎 zapytanie do users z tenant_id
$url = $SUPABASE_URL
    . "/rest/v1/users?select=id,email,password_hash,tenant_id,role,is_active"
    . "&email=eq." . rawurlencode($email)
    . "&tenant_id=eq." . rawurlencode($tenantId)
    . "&limit=1";

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => supabaseHeaders($SUPABASE_KEY, $SCHEMA)
]);

$response = curl_exec($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd połączenia']);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => 'Błąd zapytania']);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data)) {
    security_log_event('login_unknown_email', [
        'action_key' => 'auth_login_unknown_email',
        'severity' => 'medium',
        'actor_type' => 'tenant_user',
        'tenant_id' => $tenantId,
        'email' => $securityEmail,
        'ip_address' => $securityIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'response_status' => 401,
        'result' => 'failed',
        'details' => [
            'reason' => 'unknown_email',
        ],
    ]);

    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Błędny login lub hasło']);
    exit;
}

$user = $data[0];

// 🔐 weryfikacja hasła
if (!password_verify($password, $user['password_hash'])) {
    security_log_event('login_failed', [
        'action_key' => 'auth_login_user',
        'severity' => 'high',
        'actor_type' => 'tenant_user',
        'tenant_id' => $tenantId,
        'email' => $securityEmail,
        'ip_address' => $securityIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'response_status' => 401,
        'result' => 'failed',
        'details' => [
            'reason' => 'invalid_credentials',
        ],
    ]);

    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Błędny login lub hasło']);
    exit;
}

// ✅ zapis do sesji
if (!filter_var($user['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'activation_required' => true,
        'error' => 'Konto nie zostało jeszcze aktywowane. Sprawdź e-mail i kliknij link aktywacyjny.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 🔁 regeneracja ID sesji po pomyślnym uwierzytelnieniu (ochrona przed session fixation)
session_regenerate_id(true);

$_SESSION['user'] = [
    'id'        => $user['id'],
    'email'     => $user['email'],
    'tenant_id' => $user['tenant_id'],
    'role'      => $user['role'] ?? 'admin'
];

security_log_event('login_success', [
    'action_key' => 'auth_login_user',
    'severity' => 'low',
    'actor_type' => 'tenant_user',
    'tenant_id' => (string) ($user['tenant_id'] ?? $tenantId),
    'user_id' => (string) ($user['id'] ?? ''),
    'email' => $securityEmail,
    'ip_address' => $securityIp,
    'endpoint' => $securityEndpoint,
    'http_method' => $securityMethod,
    'response_status' => 200,
    'result' => 'success',
    'details' => [
        'reason' => 'login_success',
    ],
]);

echo json_encode([
    'success' => true,
    'user'    => [
        'email' => (string) ($user['email'] ?? ''),
        'role'  => (string) ($user['role'] ?? 'admin'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
