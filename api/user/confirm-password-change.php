<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../helpers/security.php';

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

function markPasswordChangeCodesUsed(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    array $filters = []
): void {
    $url = $supabaseUrl
        . '/rest/v1/password_change_codes'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
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
        return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
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
            'Prefer: return=minimal',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    curl_exec($ch);
    curl_close($ch);
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

$securityEmail = $email;
$securityIp = security_client_ip();
$securityEndpoint = '/api/user/confirm-password-change.php';
$securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$clientIp = $securityIp ?: 'unknown';

function passwordChangeConfirmLogSecurityEvent(
    string $eventKey,
    ?string $securityIp,
    string $securityEndpoint,
    string $securityMethod,
    int $responseStatus,
    string $result,
    string $reason,
    array $extraDetails = [],
    string $severity = 'medium'
): void {
    security_log_event($eventKey, [
        'action_key' => 'password_change_confirm',
        'ip_address' => $securityIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => array_merge([
            'reason' => $reason,
        ], $extraDetails),
    ]);
}

function passwordChangeConfirmRegisterInvalidAttempt(
    string $tenantId,
    string $email,
    ?string $securityIp,
    string $securityEndpoint,
    string $securityMethod,
    int $responseStatus,
    string $reason
): void {
    $invalidCodeRateLimitResult = security_rate_limit_check(
        'password_change_confirm_invalid_code',
        [
            'tenant_id' => $tenantId,
            'email' => $email,
            'ip' => $securityIp,
        ],
        [
            'endpoint' => $securityEndpoint,
            'http_method' => $securityMethod,
            'actor_type' => 'tenant_user',
            'ip_address' => $securityIp,
            'metadata' => [
                'reason' => 'password_change_confirm_invalid_code',
            ],
        ]
    );

    if (isset($invalidCodeRateLimitResult['allowed']) && $invalidCodeRateLimitResult['allowed'] === false) {
        passwordChangeConfirmLogSecurityEvent(
            'password_change_confirm_rate_limited',
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            429,
            'blocked',
            'password_change_confirm_invalid_code',
            [
                'limiter' => 'security_rate_limit_check',
            ],
            'high'
        );

        http_response_code(429);

        $rateLimitPayload = security_neutral_rate_limit_response($invalidCodeRateLimitResult);
        if (!isset($rateLimitPayload['error'])) {
            $rateLimitPayload['error'] = (string) ($rateLimitPayload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
        }

        echo json_encode($rateLimitPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    passwordChangeConfirmLogSecurityEvent(
        'password_change_confirm_invalid_code',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        $responseStatus,
        'failed',
        $reason
    );
}

if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    passwordChangeConfirmLogSecurityEvent(
        'password_change_confirm_session_denied',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        401,
        'denied',
        'tenant_mismatch'
    );

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    passwordChangeConfirmRegisterInvalidAttempt(
        $tenantId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        400,
        'password_change_confirm_invalid_json'
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = trim((string) ($input['code'] ?? ''));

if ($code === '') {
    passwordChangeConfirmRegisterInvalidAttempt(
        $tenantId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        422,
        'password_change_confirm_missing_code'
    );

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Podaj kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{6}$/', $code)) {
    passwordChangeConfirmRegisterInvalidAttempt(
        $tenantId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        400,
        'password_change_confirm_invalid_code_format'
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowy kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
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
    passwordChangeConfirmRegisterInvalidAttempt(
        $tenantId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        404,
        'password_change_confirm_no_active_code'
    );

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
    if ($codeId !== '') {
        markPasswordChangeCodesUsed(
            $supabaseUrl,
            $serviceRoleKey,
            $schema,
            $tenantId,
            $userId,
            ['id=eq.' . rawurlencode($codeId)]
        );
    }

    passwordChangeConfirmLogSecurityEvent(
        'password_change_confirm_expired_code',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        410,
        'failed',
        'password_change_confirm_expired_code'
    );

    http_response_code(410);
    echo json_encode([
        'success' => false,
        'error' => 'Kod wygasł. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($attempts >= 5) {
    passwordChangeConfirmLogSecurityEvent(
        'password_change_confirm_rate_limited',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        429,
        'blocked',
        'password_change_confirm_invalid_code',
        [
            'limiter' => 'legacy_code_attempts',
        ],
        'high'
    );

    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Przekroczono limit prób wpisania kodu. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($codeHash === '' || !password_verify($code, $codeHash)) {
    passwordChangeConfirmRegisterInvalidAttempt(
        $tenantId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        422,
        'password_change_confirm_invalid_code'
    );

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
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
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
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
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
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
        'Prefer: return=minimal',
    ],
    CURLOPT_TIMEOUT        => 20,
]);

curl_exec($usedPatchCh);
curl_close($usedPatchCh);

markPasswordChangeCodesUsed(
    $supabaseUrl,
    $serviceRoleKey,
    $schema,
    $tenantId,
    $userId,
    ['id=neq.' . rawurlencode($codeId)]
);

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
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
            'Prefer: return=minimal',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    curl_exec($logCh);
    curl_close($logCh);
}

passwordChangeConfirmLogSecurityEvent(
    'password_change_confirm_success',
    $securityIp,
    $securityEndpoint,
    $securityMethod,
    200,
    'success',
    'password_change_confirm_success',
    [],
    'high'
);

$mailHtml = buildPasswordChangedHtml();
sendSystemMail($rowEmail, 'Potwierdzenie zmiany hasła', $mailHtml);

echo json_encode([
    'success' => true,
    'message' => 'Hasło zostało zmienione poprawnie'
], JSON_UNESCAPED_UNICODE);
