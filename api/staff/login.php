<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_login_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_login_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

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

function staff_login_fail(): void
{
    staff_login_json([
        'success' => false,
        'error' => 'Nieprawidłowy e-mail lub hasło.'
    ], 401);
}

function staff_login_feature_locked(): void
{
    staff_login_json([
        'success' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny dla kont z aktywnym planem Pro. To konto działa obecnie w planie Free albo abonament Pro wygasł. Opłać abonament Pro, aby odzyskać dostęp do panelu pracownika.',
    ], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_login_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_login_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    staff_login_json([
        'success' => false,
        'error' => 'Nie udało się ustalić firmy dla tej domeny.'
    ], 400);
}

if (!tenant_has_feature((string) $tenantId, 'staff_module')) {
    staff_login_feature_locked();
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_login_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON.'
    ], 400);
}

$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    staff_login_json([
        'success' => false,
        'error' => 'Podaj e-mail i hasło.'
    ], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    staff_login_json([
        'success' => false,
        'error' => 'Podaj poprawny adres e-mail.'
    ], 400);
}

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,tenant_id,staff_id,email,password_hash,is_active'
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&email=eq.' . rawurlencode($email)
    . '&limit=1';

$accountResult = staff_login_request('GET', $accountUrl, $supabaseKey, $schema);

if (
    $accountResult['response'] === false
    || $accountResult['error'] !== ''
    || $accountResult['httpCode'] < 200
    || $accountResult['httpCode'] >= 300
) {
    staff_login_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić konta pracownika.'
    ], 500);
}

$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];
$account = is_array($accountRows[0] ?? null) ? $accountRows[0] : null;

if (!is_array($account)) {
    staff_login_fail();
}

$accountId = (string) ($account['id'] ?? '');
$staffId = (string) ($account['staff_id'] ?? '');
$accountTenantId = (string) ($account['tenant_id'] ?? '');
$accountEmail = strtolower(trim((string) ($account['email'] ?? '')));
$passwordHash = (string) ($account['password_hash'] ?? '');
$isActive = filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (
    $accountId === ''
    || $staffId === ''
    || $accountTenantId === ''
    || !hash_equals((string) $tenantId, $accountTenantId)
    || $accountEmail === ''
    || $passwordHash === ''
    || !$isActive
) {
    staff_login_fail();
}

if (!password_verify($password, $passwordHash)) {
    staff_login_fail();
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,display_name,email,is_active'
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_login_request('GET', $staffUrl, $supabaseKey, $schema);

if (
    $staffResult['response'] === false
    || $staffResult['error'] !== ''
    || $staffResult['httpCode'] < 200
    || $staffResult['httpCode'] >= 300
) {
    staff_login_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić profilu pracownika.'
    ], 500);
}

$staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = is_array($staffRows[0] ?? null) ? $staffRows[0] : null;

if (!is_array($staff) || empty($staff['id'])) {
    staff_login_fail();
}

$staffIsActive = filter_var($staff['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

if (!$staffIsActive) {
    staff_login_json([
        'success' => false,
        'error' => 'Konto pracownika jest nieaktywne.'
    ], 403);
}

$displayName = trim((string) ($staff['display_name'] ?? ''));

if ($displayName === '') {
    $displayName = $accountEmail;
}

session_regenerate_id(true);

$_SESSION['staff_user'] = [
    'account_id' => $accountId,
    'tenant_id' => (string) $tenantId,
    'staff_id' => $staffId,
    'email' => $accountEmail,
    'display_name' => $displayName,
];

$now = gmdate('c');

$updateUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&id=eq.' . rawurlencode($accountId);

staff_login_request('PATCH', $updateUrl, $supabaseKey, $schema, [
    'last_login_at' => $now,
    'updated_at' => $now,
]);

$refSecret = public_response_ref_secret($supabaseKey);

staff_login_json([
    'success' => true,
    'staff' => [
        'staff_ref' => public_response_staff_ref((string) $tenantId, $staffId, $refSecret),
        'email' => $accountEmail,
        'display_name' => $displayName,
    ],
]);
