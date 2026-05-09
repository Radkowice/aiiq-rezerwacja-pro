<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/php_mail.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id']) || empty($_SESSION['user']['email'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getClientIpAddress(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $value) {
        if (!$value) {
            continue;
        }

        $ip = trim(explode(',', $value)[0]);
        if ($ip !== '') {
            return $ip;
        }
    }

    return 'unknown';
}

function buildPasswordChangedHtml(): string
{
    $message = ''
        . '<p style="margin:0 0 14px;"><strong>✅ Hasło do Twojego konta zostało zmienione.</strong></p>'
        . '<p style="margin:0 0 10px;">Jeśli to była Twoja operacja, nie musisz nic robić.</p>'
        . '<p style="margin:10px 0 0;">Jeśli nie rozpoznajesz tej zmiany, jak najszybciej zabezpiecz konto i skontaktuj się z administratorem.</p>';

    return buildSystemMailLayout(
        'Potwierdzenie zmiany hasła',
        'To wiadomość systemowa dotycząca bezpieczeństwa Twojego konta.',
        $message,
        'Nie odpowiadaj na tę wiadomość. Skrzynka nie jest monitorowana.'
    );
}

$input = json_decode(file_get_contents('php://input'), true);
$code = trim((string) ($input['code'] ?? ''));

if ($code === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Podaj kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string) $_SESSION['user']['id'];
$tenantId = (string) $_SESSION['user']['tenant_id'];
$email = (string) $_SESSION['user']['email'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIp = getClientIpAddress();

$codeUrl = $supabaseUrl
    . '/rest/v1/password_change_codes'
    . '?select=id,tenant_id,user_id,email,new_password_hash,code_hash,expires_at,used_at,attempts'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId)
    . '&used_at=is.null'
    . '&order=created_at.desc'
    . '&limit=1';

$codeCh = curl_init($codeUrl);
curl_setopt_array($codeCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: rezerwacja_pro',
        'Content-Profile: rezerwacja_pro',
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$codeResponse = curl_exec($codeCh);

if ($codeResponse === false) {
    $curlError = curl_error($codeCh);
    curl_close($codeCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się odczytać kodu potwierdzenia. Spróbuj ponownie.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$codeHttpCode = (int) curl_getinfo($codeCh, CURLINFO_HTTP_CODE);
curl_close($codeCh);

if ($codeHttpCode < 200 || $codeHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać kodu potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = json_decode($codeResponse, true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

if (!$row) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Brak aktywnego kodu potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$codeId = (string) ($row['id'] ?? '');
$codeHash = (string) ($row['code_hash'] ?? '');
$newPasswordHash = (string) ($row['new_password_hash'] ?? '');
$attempts = (int) ($row['attempts'] ?? 0);
$expiresAt = (string) ($row['expires_at'] ?? '');
$rowEmail = (string) ($row['email'] ?? $email);

if ($expiresAt === '' || strtotime($expiresAt) < time()) {
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'error' => 'Kod wygasł. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($attempts >= 5) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Przekroczono limit prób wpisania kodu. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($codeHash === '' || !password_verify($code, $codeHash)) {
    $attemptPatchUrl = $supabaseUrl
        . '/rest/v1/password_change_codes'
        . '?id=eq.' . rawurlencode($codeId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&user_id=eq.' . rawurlencode($userId);

    $attemptPatchPayload = json_encode([
        'attempts' => $attempts + 1
    ], JSON_UNESCAPED_UNICODE);

    $attemptPatchCh = curl_init($attemptPatchUrl);
    curl_setopt_array($attemptPatchCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $attemptPatchPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: rezerwacja_pro',
            'Content-Profile: rezerwacja_pro',
            'Prefer: return=minimal',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    curl_exec($attemptPatchCh);
    curl_close($attemptPatchCh);

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowy kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userPatchUrl = $supabaseUrl
    . '/rest/v1/users'
    . '?id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId);

$userPatchPayload = json_encode([
    'password_hash' => $newPasswordHash
], JSON_UNESCAPED_UNICODE);

$userPatchCh = curl_init($userPatchUrl);
curl_setopt_array($userPatchCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => $userPatchPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: rezerwacja_pro',
        'Content-Profile: rezerwacja_pro',
        'Prefer: return=minimal',
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$userPatchResponse = curl_exec($userPatchCh);

if ($userPatchResponse === false) {
    $curlError = curl_error($userPatchCh);
    curl_close($userPatchCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zmienić hasła. Spróbuj ponownie.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userPatchHttpCode = (int) curl_getinfo($userPatchCh, CURLINFO_HTTP_CODE);
curl_close($userPatchCh);

if ($userPatchHttpCode < 200 || $userPatchHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać nowego hasła'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$usedPatchUrl = $supabaseUrl
    . '/rest/v1/password_change_codes'
    . '?id=eq.' . rawurlencode($codeId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId);

$usedPatchPayload = json_encode([
    'used_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'attempts' => $attempts + 1
], JSON_UNESCAPED_UNICODE);

$usedPatchCh = curl_init($usedPatchUrl);
curl_setopt_array($usedPatchCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => $usedPatchPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: rezerwacja_pro',
        'Content-Profile: rezerwacja_pro',
        'Prefer: return=minimal',
    ],
    CURLOPT_TIMEOUT        => 20,
]);

curl_exec($usedPatchCh);
curl_close($usedPatchCh);

$logPayload = json_encode([
    'tenant_id'  => $tenantId,
    'user_id'    => $userId,
    'email'      => $rowEmail,
    'ip_address' => $clientIp,
], JSON_UNESCAPED_UNICODE);

if ($logPayload !== false) {
    $logUrl = $supabaseUrl . '/rest/v1/password_change_logs';

    $logCh = curl_init($logUrl);
    curl_setopt_array($logCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $logPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: rezerwacja_pro',
            'Content-Profile: rezerwacja_pro',
            'Prefer: return=minimal',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    curl_exec($logCh);
    curl_close($logCh);
}

$mailHtml = buildPasswordChangedHtml();
sendSystemMail($rowEmail, 'Potwierdzenie zmiany hasła', $mailHtml);

echo json_encode([
    'success' => true,
    'message' => 'Hasło zostało zmienione poprawnie'
], JSON_UNESCAPED_UNICODE);
