<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

function resetPasswordJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function supabaseResetRequest(
    string $method,
    string $url,
    string $serviceRoleKey,
    string $schema,
    ?array $payload = null
): array {
    $ch = curl_init($url);

    $headers = [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'error' => $curlError,
        'body' => $response,
        'json' => json_decode((string) $response, true),
    ];
}

function isStrongResetPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    resetPasswordJson([
        'success' => false,
        'message' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$TENANT_ID = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA);

if (!$TENANT_ID) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Nie udało się ustalić klienta po domenie.'
    ], 400);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Brak danych wejściowych.'
    ], 400);
}

$token = trim((string)($data['token'] ?? ''));
$newPassword = (string)($data['password'] ?? '');

if ($token === '') {
    resetPasswordJson([
        'success' => false,
        'message' => 'Brak tokena resetu hasła.'
    ], 400);
}

if (!isStrongResetPassword($newPassword)) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Hasło musi mieć minimum 8 znaków, małą i dużą literę, cyfrę oraz znak specjalny.'
    ], 400);
}

/*
 * Szukamy tokena po token + tenant.
 */
$tokenUrl = $SUPABASE_URL
    . '/rest/v1/password_reset_tokens'
    . '?select=id,email,token,expires_at,tenant_id'
    . '&token=eq.' . rawurlencode($token)
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';

$tokenResult = supabaseResetRequest(
    'GET',
    $tokenUrl,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA
);

if (!$tokenResult['ok']) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Błąd odczytu tokena.',
        'debug' => $tokenResult['json'] ?: $tokenResult['body'] ?: $tokenResult['error'],
    ], 500);
}

$tokens = is_array($tokenResult['json']) ? $tokenResult['json'] : [];

if (count($tokens) === 0) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Token nieprawidłowy.'
    ], 400);
}

$tokenData = $tokens[0];
$tokenId = (string)($tokenData['id'] ?? '');
$email = trim((string)($tokenData['email'] ?? ''));
$expiresAt = (string)($tokenData['expires_at'] ?? '');

if ($tokenId === '') {
    resetPasswordJson([
        'success' => false,
        'message' => 'Nieprawidłowy rekord tokena.'
    ], 500);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Nieprawidłowy e-mail przypisany do tokena.'
    ], 400);
}

if ($expiresAt === '' || strtotime($expiresAt) === false) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Nieprawidłowa data ważności tokena.'
    ], 400);
}

if (strtotime($expiresAt) < time()) {
    /*
     * Token wygasł — usuwamy go porządkowo.
     */
    $deleteExpiredUrl = $SUPABASE_URL
        . '/rest/v1/password_reset_tokens'
        . '?id=eq.' . rawurlencode($tokenId)
        . '&tenant_id=eq.' . rawurlencode($TENANT_ID);

    supabaseResetRequest(
        'DELETE',
        $deleteExpiredUrl,
        $SUPABASE_KEY,
        $SUPABASE_DB_SCHEMA
    );

    resetPasswordJson([
        'success' => false,
        'message' => 'Token wygasł. Wygeneruj nowy link resetu hasła.'
    ], 400);
}

/*
 * Sprawdzamy użytkownika po e-mail + tenant.
 */
$userUrl = $SUPABASE_URL
    . '/rest/v1/users'
    . '?select=id,email,is_active'
    . '&email=eq.' . rawurlencode($email)
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';

$userResult = supabaseResetRequest(
    'GET',
    $userUrl,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA
);

if (!$userResult['ok']) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Nie udało się sprawdzić użytkownika.',
        'debug' => $userResult['json'] ?: $userResult['body'] ?: $userResult['error'],
    ], 500);
}

$userRows = is_array($userResult['json']) ? $userResult['json'] : [];

if (count($userRows) === 0) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Użytkownik nie istnieje.'
    ], 400);
}

$user = $userRows[0];

if (isset($user['is_active']) && $user['is_active'] === false) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Konto jest nieaktywne.'
    ], 403);
}

/*
 * Aktualizacja hasła.
 */
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

$updateUrl = $SUPABASE_URL
    . '/rest/v1/users'
    . '?email=eq.' . rawurlencode($email)
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID);

$updatePayload = [
    'password_hash' => $newHash,
];

$updateResult = supabaseResetRequest(
    'PATCH',
    $updateUrl,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA,
    $updatePayload
);

if (!$updateResult['ok']) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Nie udało się zmienić hasła.',
        'debug' => $updateResult['json'] ?: $updateResult['body'] ?: $updateResult['error'],
    ], 500);
}

/*
 * Usuwamy token po poprawnej zmianie hasła.
 */
$deleteUrl = $SUPABASE_URL
    . '/rest/v1/password_reset_tokens'
    . '?id=eq.' . rawurlencode($tokenId)
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID);

$deleteResult = supabaseResetRequest(
    'DELETE',
    $deleteUrl,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA
);

if (!$deleteResult['ok']) {
    resetPasswordJson([
        'success' => false,
        'message' => 'Hasło zostało zmienione, ale nie udało się usunąć tokena resetu.',
        'debug' => $deleteResult['json'] ?: $deleteResult['body'] ?: $deleteResult['error'],
    ], 500);
}

/*
 * Log techniczny resetu.
 */
$logData = [
    'tenant_id' => $TENANT_ID,
    'email' => $email,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'time' => date('Y-m-d H:i:s'),
];

$logDir = __DIR__ . '/../data';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

@file_put_contents(
    $logDir . '/reset_password.log',
    json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

resetPasswordJson([
    'success' => true,
    'message' => 'Hasło zostało zmienione. Możesz się zalogować.'
]);