<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

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

header('Content-Type: application/json; charset=utf-8');

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Brak autoryzacji'], JSON_UNESCAPED_UNICODE);
    exit;
}

function userChangeEmailCsrfToken(): string
{
    $token = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($token === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $token = trim((string) ($headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ''));
    }

    return $token;
}

function requireUserChangeEmailCsrf(): void
{
    $sessionToken = (string) ($_SESSION['csrf'] ?? '');
    $requestToken = userChangeEmailCsrfToken();

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'csrf_invalid'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

requireUserChangeEmailCsrf();

$input = json_decode(file_get_contents('php://input'), true);
$newEmail = trim((string)($input['email'] ?? $input['new_email'] ?? ''));
$currentPassword = trim((string)($input['current_password'] ?? ''));

if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Niepoprawny e-mail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($currentPassword === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Podaj aktualne hasło'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string) $_SESSION['user']['id'];
$currentEmail = (string) ($_SESSION['user']['email'] ?? '');

if ($currentEmail !== '' && mb_strtolower($newEmail, 'UTF-8') === mb_strtolower($currentEmail, 'UTF-8')) {
    echo json_encode([
        'success' => false,
        'error' => 'Nowy e-mail jest taki sam jak obecny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

if ($tenantId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Brak poprawnej sesji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$securityIp = security_client_ip();
$clientIp = $securityIp ?: getClientIpAddress();
$securityEndpoint = '/api/user/change-email.php';
$securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$securityEmail = $currentEmail !== '' ? $currentEmail : $newEmail;

function markEmailChangeCodesUsed(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    array $filters = []
): array {
    $url = $supabaseUrl
        . '/rest/v1/email_change_codes'
        . '?select=code_hash'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&user_id=eq.' . rawurlencode($userId)
        . '&used_at=is.null';

    foreach ($filters as $filter) {
        $filter = trim((string) $filter);

        if ($filter !== '') {
            $url .= '&' . $filter;
        }
    }

    $payload = json_encode([
        'used_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return ['ok' => false, 'affected' => null];
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'affected' => null];
    }

    $configured = curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
            'Prefer: return=representation',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    if (!$configured) {
        curl_close($ch);

        return ['ok' => false, 'affected' => null];
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'affected' => null];
    }

    $rows = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
        return ['ok' => false, 'affected' => null];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'affected' => null];
        }
    }

    return ['ok' => true, 'affected' => count($rows)];
}

function issueEmailChangeCode(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $currentEmail,
    string $newEmail,
    string $codeHash,
    string $ipAddress
): array {
    $payload = json_encode([
        'p_tenant_id' => $tenantId,
        'p_user_id' => $userId,
        'p_current_email' => $currentEmail,
        'p_new_email' => $newEmail,
        'p_code_hash' => $codeHash,
        'p_ip_address' => $ipAddress,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return ['ok' => false, 'issued' => false, 'retry_later' => false];
    }

    $url = $supabaseUrl . '/rest/v1/rpc/issue_email_change_code';
    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'issued' => false, 'retry_later' => false];
    }

    $configured = curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    if (!$configured) {
        curl_close($ch);

        return ['ok' => false, 'issued' => false, 'retry_later' => false];
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'issued' => false, 'retry_later' => false];
    }

    $result = json_decode((string) $response, true);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_array($result)
        || !array_key_exists('success', $result)
        || !is_bool($result['success'])
    ) {
        return ['ok' => false, 'issued' => false, 'retry_later' => false];
    }

    if ($result['success'] === true) {
        return ['ok' => true, 'issued' => true, 'retry_later' => false];
    }

    if (($result['reason'] ?? null) === 'retry_later') {
        return ['ok' => true, 'issued' => false, 'retry_later' => true];
    }

    return ['ok' => false, 'issued' => false, 'retry_later' => false];
}

$userUrl = $supabaseUrl
    . '/rest/v1/users'
    . '?select=id,email,password_hash,tenant_id'
    . '&id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$userCh = curl_init($userUrl);
curl_setopt_array($userCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$userResponse = curl_exec($userCh);

if ($userResponse === false) {
    curl_close($userCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userHttpCode = (int) curl_getinfo($userCh, CURLINFO_HTTP_CODE);
curl_close($userCh);

if ($userHttpCode < 200 || $userHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$users = json_decode($userResponse, true);
$user = is_array($users) && isset($users[0]) && is_array($users[0]) ? $users[0] : null;
$passwordHash = (string) ($user['password_hash'] ?? '');

$dbCurrentEmail = trim((string) ($user['email'] ?? ''));
if ($dbCurrentEmail !== '') {
    $currentEmail = $dbCurrentEmail;
    $securityEmail = $currentEmail;
}

if ($currentEmail !== '' && mb_strtolower($newEmail, 'UTF-8') === mb_strtolower($currentEmail, 'UTF-8')) {
    echo json_encode([
        'success' => false,
        'error' => 'Nowy e-mail jest taki sam jak obecny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$user || $passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
    security_log_event('email_change_current_password_failed', [
        'email' => $securityEmail,
        'ip_address' => $clientIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => 422,
        'result' => 'failed',
        'details' => [
            'reason' => 'current_password_failed',
        ],
    ]);

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Aktualne hasło jest nieprawidłowe'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$checkUrl = $supabaseUrl
    . '/rest/v1/users'
    . '?select=id,email'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&email=ilike.' . rawurlencode($newEmail)
    . '&limit=1';

$checkCh = curl_init($checkUrl);
curl_setopt_array($checkCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$checkResponse = curl_exec($checkCh);

if ($checkResponse === false) {
    curl_close($checkCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się sprawdzić dostępności e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$checkHttpCode = (int) curl_getinfo($checkCh, CURLINFO_HTTP_CODE);
curl_close($checkCh);

if ($checkHttpCode < 200 || $checkHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się sprawdzić dostępności e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$existingUsers = json_decode($checkResponse, true);

if (is_array($existingUsers) && !empty($existingUsers)) {
    security_log_event('email_change_conflict', [
        'email' => $securityEmail,
        'ip_address' => $clientIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => 409,
        'result' => 'failed',
        'details' => [
            'reason' => 'email_change_conflict',
        ],
    ]);

    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'Ten adres e-mail jest niedostępny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rateCheckUrl = $supabaseUrl
    . '/rest/v1/email_change_attempts'
    . '?select=id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId)
    . '&created_at=gte.' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

$rateCheckCh = curl_init($rateCheckUrl);
curl_setopt_array($rateCheckCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$rateCheckResponse = curl_exec($rateCheckCh);

if ($rateCheckResponse === false) {
    $curlError = curl_error($rateCheckCh);
    curl_close($rateCheckCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd sprawdzania limitu zmian e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rateCheckHttpCode = (int) curl_getinfo($rateCheckCh, CURLINFO_HTTP_CODE);
curl_close($rateCheckCh);

if ($rateCheckHttpCode < 200 || $rateCheckHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się sprawdzić limitu zmian e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$recentAttempts = json_decode($rateCheckResponse, true);

if (!is_array($recentAttempts)) {
    $recentAttempts = [];
}

if (count($recentAttempts) >= 3) {
    security_log_event('email_change_rate_limited', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'email' => $securityEmail,
        'ip_address' => $clientIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => 429,
        'result' => 'blocked',
        'details' => [
            'reason' => 'email_change_request',
            'limiter' => 'email_change_attempts',
        ],
    ]);

    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Przekroczono limit zmian e-maila. Spróbuj ponownie za godzinę.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$attemptPayload = json_encode([
    'tenant_id'  => $tenantId,
    'user_id'    => $userId,
    'ip_address' => $clientIp,
], JSON_UNESCAPED_UNICODE);

if ($attemptPayload === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd przygotowania danych limitu zmian e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$attemptUrl = $supabaseUrl . '/rest/v1/email_change_attempts';

$attemptCh = curl_init($attemptUrl);
curl_setopt_array($attemptCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $attemptPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
        'Prefer: return=minimal',
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$attemptResponse = curl_exec($attemptCh);

if ($attemptResponse === false) {
    $curlError = curl_error($attemptCh);
    curl_close($attemptCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd zapisu próby zmiany e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$attemptHttpCode = (int) curl_getinfo($attemptCh, CURLINFO_HTTP_CODE);
curl_close($attemptCh);

if ($attemptHttpCode < 200 || $attemptHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać próby zmiany e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = (string) random_int(100000, 999999);
$codeHash = password_hash($code, PASSWORD_DEFAULT);

$issueCodeResult = issueEmailChangeCode(
    $supabaseUrl,
    $serviceRoleKey,
    $schema,
    $tenantId,
    $userId,
    $currentEmail,
    $newEmail,
    $codeHash,
    $clientIp
);

if (($issueCodeResult['ok'] ?? false) !== true) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($issueCodeResult['retry_later'] ?? false) === true) {
    security_log_event('email_change_code_rate_limited', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'email' => $securityEmail,
        'ip_address' => $clientIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => 429,
        'result' => 'blocked',
        'details' => [
            'reason' => 'email_change_code_cooldown',
        ],
    ]);

    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Nowy kod został już niedawno wygenerowany. Spróbuj ponownie za chwilę.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($issueCodeResult['issued'] ?? false) !== true) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

security_log_event('email_change_request', [
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'email' => $securityEmail,
    'ip_address' => $clientIp,
    'endpoint' => $securityEndpoint,
    'http_method' => $securityMethod,
    'actor_type' => 'tenant_user',
    'response_status' => 202,
    'result' => 'accepted',
    'details' => [
        'reason' => 'email_change_request',
    ],
]);

try {
    $mailHtml = buildEmailChangeCodeHtml($code, $currentEmail, $newEmail);
    $mailSent = sendSystemMail($newEmail, 'Kod potwierdzenia zmiany e-maila', $mailHtml);
} catch (Throwable $e) {
    $mailSent = false;
}

if (!$mailSent) {
    $discardCodeResult = markEmailChangeCodesUsed(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        [
            'code_hash=eq.' . rawurlencode($codeHash),
        ]
    );

    if (
        ($discardCodeResult['ok'] ?? false) !== true
        || ($discardCodeResult['affected'] ?? null) !== 1
    ) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się wysłać kodu potwierdzającego'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

security_log_event('email_change_code_sent', [
    'email' => $securityEmail,
    'ip_address' => $clientIp,
    'endpoint' => $securityEndpoint,
    'http_method' => $securityMethod,
    'actor_type' => 'tenant_user',
    'response_status' => 200,
    'result' => 'success',
    'details' => [
        'reason' => 'email_change_code_sent',
    ],
]);

echo json_encode([
    'success' => true,
    'message' => 'Wysłaliśmy kod potwierdzający na nowy adres e-mail'
], JSON_UNESCAPED_UNICODE);
exit;
