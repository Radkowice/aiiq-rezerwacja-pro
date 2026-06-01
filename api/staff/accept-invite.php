<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';

function staff_accept_invite_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_accept_invite_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    bool $returnRepresentation = false
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if ($returnRepresentation) {
        $headers[] = 'Prefer: return=representation';
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

function staff_accept_invite_database_error(): void
{
    staff_accept_invite_json([
        'success' => false,
        'error' => 'Nie udało się zaakceptować zaproszenia.'
    ], 500);
}

function staff_accept_invite_token_error(): void
{
    staff_accept_invite_json([
        'success' => false,
        'error' => 'Link zaproszenia jest nieprawidłowy albo wygasł.'
    ], 410);
}

function staff_accept_invite_password_error(string $password, string $passwordConfirm): string
{
    if ($password === '') {
        return 'Podaj hasło.';
    }

    if ($password !== $passwordConfirm) {
        return 'Hasła muszą być takie same.';
    }

    if (mb_strlen($password, 'UTF-8') < 8) {
        return 'Hasło musi mieć minimum 8 znaków.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Hasło musi zawierać małą literę.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Hasło musi zawierać wielką literę.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Hasło musi zawierać cyfrę.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Hasło musi zawierać znak specjalny.';
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_accept_invite_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_accept_invite_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_accept_invite_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON'
    ], 400);
}

$token = trim((string) ($input['token'] ?? ''));
$password = (string) ($input['password'] ?? '');
$passwordConfirm = (string) ($input['password_confirm'] ?? '');

if ($token === '') {
    staff_accept_invite_json([
        'success' => false,
        'error' => 'Brak tokenu zaproszenia.'
    ], 400);
}

$passwordError = staff_accept_invite_password_error($password, $passwordConfirm);

if ($passwordError !== '') {
    staff_accept_invite_json([
        'success' => false,
        'error' => $passwordError
    ], 400);
}

$tokenHash = hash('sha256', $token);
$now = gmdate('c');

$inviteUrl = $supabaseUrl
    . '/rest/v1/staff_invites'
    . '?select=id,tenant_id,staff_id,email,expires_at'
    . '&token_hash=eq.' . rawurlencode($tokenHash)
    . '&accepted_at=is.null'
    . '&revoked_at=is.null'
    . '&expires_at=gt.' . rawurlencode($now)
    . '&limit=1';

$inviteResult = staff_accept_invite_request('GET', $inviteUrl, $supabaseKey, $schema);

if ($inviteResult['response'] === false || $inviteResult['error'] !== '' || $inviteResult['httpCode'] >= 500) {
    staff_accept_invite_database_error();
}

if ($inviteResult['httpCode'] < 200 || $inviteResult['httpCode'] >= 300) {
    staff_accept_invite_database_error();
}

$inviteRows = is_array($inviteResult['data'] ?? null) ? $inviteResult['data'] : [];
$invite = is_array($inviteRows[0] ?? null) ? $inviteRows[0] : null;

if (!is_array($invite) || empty($invite['id']) || empty($invite['tenant_id']) || empty($invite['staff_id'])) {
    staff_accept_invite_token_error();
}

$inviteId = (string) $invite['id'];
$tenantId = (string) $invite['tenant_id'];
$staffId = (string) $invite['staff_id'];
$email = strtolower(trim((string) ($invite['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    staff_accept_invite_database_error();
}

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,password_hash,is_active'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$accountResult = staff_accept_invite_request('GET', $accountUrl, $supabaseKey, $schema);

if ($accountResult['response'] === false || $accountResult['error'] !== '' || $accountResult['httpCode'] < 200 || $accountResult['httpCode'] >= 300) {
    staff_accept_invite_database_error();
}

$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];
$account = is_array($accountRows[0] ?? null) ? $accountRows[0] : null;

if (
    is_array($account)
    && trim((string) ($account['password_hash'] ?? '')) !== ''
    && filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
) {
    staff_accept_invite_json([
        'success' => false,
        'error' => 'Konto pracownika jest już aktywne.'
    ], 409);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$passwordPayload = [
    'email' => $email,
    'password_hash' => $passwordHash,
    'password_set_at' => $now,
    'is_active' => true,
    'updated_at' => $now,
];

if (is_array($account) && !empty($account['id'])) {
    $accountWriteUrl = $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId);

    $accountWriteResult = staff_accept_invite_request('PATCH', $accountWriteUrl, $supabaseKey, $schema, $passwordPayload);
} else {
    $accountWriteResult = staff_accept_invite_request('POST', $supabaseUrl . '/rest/v1/staff_accounts', $supabaseKey, $schema, [
        'tenant_id' => $tenantId,
        'staff_id' => $staffId,
        'email' => $email,
        'password_hash' => $passwordHash,
        'password_set_at' => $now,
        'is_active' => true,
        'updated_at' => $now,
    ]);
}

if ($accountWriteResult['response'] === false || $accountWriteResult['error'] !== '' || $accountWriteResult['httpCode'] < 200 || $accountWriteResult['httpCode'] >= 300) {
    staff_accept_invite_database_error();
}

$acceptedUrl = $supabaseUrl
    . '/rest/v1/staff_invites'
    . '?id=eq.' . rawurlencode($inviteId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&accepted_at=is.null'
    . '&revoked_at=is.null';

$acceptedResult = staff_accept_invite_request('PATCH', $acceptedUrl, $supabaseKey, $schema, [
    'accepted_at' => $now,
]);

if ($acceptedResult['response'] === false || $acceptedResult['error'] !== '' || $acceptedResult['httpCode'] < 200 || $acceptedResult['httpCode'] >= 300) {
    staff_accept_invite_database_error();
}

staff_accept_invite_json([
    'success' => true,
    'message' => 'Hasło zostało ustawione. Możesz się zalogować.'
]);
