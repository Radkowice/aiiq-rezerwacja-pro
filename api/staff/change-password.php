<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_change_password_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_change_password_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if (strtoupper($method) === 'PATCH') {
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

function staff_change_password_clear_session(): void
{
    unset($_SESSION['staff_user']);
}

function staff_change_password_validation_error(string $password): string
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_change_password_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$staffSession = $_SESSION['staff_user'] ?? null;

if (!is_array($staffSession) || empty($staffSession['account_id']) || empty($staffSession['tenant_id']) || empty($staffSession['staff_id'])) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Brak aktywnej sesji personelu.'
    ], 401);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_change_password_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$hostTenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);
$sessionTenantId = (string) ($staffSession['tenant_id'] ?? '');

if (!$hostTenantId || !hash_equals($sessionTenantId, (string) $hostTenantId)) {
    staff_change_password_clear_session();
    staff_change_password_json([
        'success' => false,
        'error' => 'Sesja personelu nie pasuje do domeny.'
    ], 401);
}

require_tenant_feature(
    $sessionTenantId,
    'staff_module',
    'Panel pracownika jest dostępny dla kont z aktywnym planem Pro. To konto działa obecnie w planie Free albo abonament Pro wygasł. Opłać abonament Pro, aby odzyskać dostęp do panelu pracownika.'
);

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON.'
    ], 400);
}

$currentPassword = (string) ($input['current_password'] ?? '');
$newPassword = (string) ($input['new_password'] ?? '');
$confirmPassword = (string) ($input['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    staff_change_password_json([
        'success' => false,
        'error' => 'Wypełnij wszystkie pola.'
    ], 422);
}

if ($newPassword !== $confirmPassword) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Nowe hasła nie są takie same.'
    ], 422);
}

$passwordError = staff_change_password_validation_error($newPassword);

if ($passwordError !== '') {
    staff_change_password_json([
        'success' => false,
        'error' => $passwordError
    ], 422);
}

$accountId = (string) ($staffSession['account_id'] ?? '');
$staffId = (string) ($staffSession['staff_id'] ?? '');

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,tenant_id,staff_id,email,password_hash,is_active'
    . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&id=eq.' . rawurlencode($accountId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$accountResult = staff_change_password_request('GET', $accountUrl, $supabaseKey, $schema);

if (
    $accountResult['response'] === false
    || $accountResult['error'] !== ''
    || $accountResult['httpCode'] < 200
    || $accountResult['httpCode'] >= 300
) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić konta personelu.'
    ], 500);
}

$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];
$account = is_array($accountRows[0] ?? null) ? $accountRows[0] : null;

if (!is_array($account) || empty($account['id']) || !filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    staff_change_password_clear_session();
    staff_change_password_json([
        'success' => false,
        'error' => 'Konto personelu jest nieaktywne.'
    ], 401);
}

$accountEmail = strtolower(trim((string) ($account['email'] ?? '')));

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,is_active'
    . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_change_password_request('GET', $staffUrl, $supabaseKey, $schema);

if (
    $staffResult['response'] === false
    || $staffResult['error'] !== ''
    || $staffResult['httpCode'] < 200
    || $staffResult['httpCode'] >= 300
) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić profilu personelu.'
    ], 500);
}

$staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = is_array($staffRows[0] ?? null) ? $staffRows[0] : null;

if (!is_array($staff) || empty($staff['id']) || !filter_var($staff['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
    staff_change_password_clear_session();
    staff_change_password_json([
        'success' => false,
        'error' => 'Profil personelu jest nieaktywny.'
    ], 401);
}

$passwordHash = (string) ($account['password_hash'] ?? '');

if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Obecne hasło jest nieprawidłowe.'
    ], 422);
}

if (password_verify($newPassword, $passwordHash)) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Nowe hasło musi być inne niż obecne.'
    ], 422);
}

$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
$now = gmdate('c');

$updateUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&id=eq.' . rawurlencode($accountId)
    . '&staff_id=eq.' . rawurlencode($staffId);

$updateResult = staff_change_password_request('PATCH', $updateUrl, $supabaseKey, $schema, [
    'password_hash' => $newPasswordHash,
    'updated_at' => $now,
]);

if (
    $updateResult['response'] === false
    || $updateResult['error'] !== ''
    || $updateResult['httpCode'] < 200
    || $updateResult['httpCode'] >= 300
) {
    staff_change_password_json([
        'success' => false,
        'error' => 'Nie udało się zapisać nowego hasła.'
    ], 500);
}

if ($accountEmail !== '' && filter_var($accountEmail, FILTER_VALIDATE_EMAIL)) {
    $mailMessage = ''
        . '<p style="margin:0 0 14px;"><strong>🔐 Hasło do panelu personelu zostało zmienione.</strong></p>'
        . '<p style="margin:0 0 10px;">Jeśli to Ty wykonałeś tę zmianę, nie musisz nic robić.</p>'
        . '<p style="margin:0;">Jeśli to nie Ty, skontaktuj się z administratorem firmy.</p>';

    $mailHtml = buildSystemMailLayout(
        'Hasło personelu zostało zmienione',
        'To wiadomość systemowa dotycząca bezpieczeństwa konta personelu.',
        $mailMessage,
        'Nie odpowiadaj na tę wiadomość. Skrzynka nie jest monitorowana.'
    );

    sendSystemMail($accountEmail, 'Hasło personelu zostało zmienione', $mailHtml);
}

session_regenerate_id(true);

staff_change_password_json([
    'success' => true,
    'message' => 'Hasło zostało zmienione.'
]);
