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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$action = trim((string) ($input['action'] ?? ''));
$password = trim((string)($input['password'] ?? ''));
$code = trim((string) ($input['code'] ?? ''));
$dataLossConfirmed = ($input['data_loss_confirmed'] ?? false) === true;
$finalConfirmation = ($input['final_confirmation'] ?? false) === true;

if (!in_array($action, ['request_code', 'confirm_delete'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowe żądanie usunięcia konta'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($password === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Podaj hasło, aby usunąć konto'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$dataLossConfirmed) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Potwierdź świadomość utraty danych'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'confirm_delete' && $code === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Podaj kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'confirm_delete' && !preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Kod potwierdzenia musi mieć 6 cyfr'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'confirm_delete' && !$finalConfirmation) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Wymagane jest ostateczne potwierdzenie usunięcia konta'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string) $_SESSION['user']['id'];
$tenantId = (string) $_SESSION['user']['tenant_id'];
$userEmail = (string) $_SESSION['user']['email'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$supabaseSchema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

$securityEmail = $userEmail;
$securityIp = security_client_ip();
$securityEndpoint = '/api/user/delete-account.php';
$securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';

function deleteDirectoryRecursive(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }

    $items = scandir($dir);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path) && !is_link($path)) {
            deleteDirectoryRecursive($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function deleteTenantFiles(string $tenantId): void
{
    $safeTenantId = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId);

    if ($safeTenantId === '') {
        return;
    }

    $baseDir = realpath(__DIR__ . '/../../html/data');

    if ($baseDir === false) {
        return;
    }

    $tenantDirs = [
        $baseDir . '/logo/' . $safeTenantId,
        $baseDir . '/favicon/' . $safeTenantId,
    ];

    foreach ($tenantDirs as $dir) {
        deleteDirectoryRecursive($dir);
    }
}

function accountDeleteClientIpAddress(): string
{
    $trustedIp = security_client_ip();

    if (is_string($trustedIp) && $trustedIp !== '') {
        return $trustedIp;
    }

    $remoteIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $remoteIp !== '' ? $remoteIp : 'unknown';
}

function accountDeleteRequest(
    string $method,
    string $url,
    string $serviceRoleKey,
    string $schema,
    ?array $payload = null,
    string $prefer = 'return=minimal'
): array {
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    if ($prefer !== '') {
        $headers[] = 'Prefer: ' . $prefer;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
    } elseif ($method !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }

    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'encode_failed'];
        }
        $options[CURLOPT_POSTFIELDS] = $encoded;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = null;
    if (is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
        $data = is_array($decoded) ? $decoded : null;
    }

    return [
        'ok' => $curlError === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $data,
        'error' => $curlError,
    ];
}


function accountDeleteSecurityActionKey(string $eventKey): string
{
    return match ($eventKey) {
        'account_delete_rate_limited',
        'account_delete_invalid_password',
        'account_delete_code_sent',
        'account_delete_code_send_failed' => 'account_delete_request',
        'account_delete_confirm_invalid_code',
        'account_delete_confirm_rate_limited' => 'account_delete_confirm_invalid_code',
        default => $eventKey,
    };
}

function accountDeleteLogSecurityEvent(
    string $eventKey,
    string $tenantId,
    string $userId,
    string $email,
    ?string $ipAddress,
    string $endpoint,
    string $method,
    int $responseStatus,
    string $result,
    string $reason,
    array $extraDetails = []
): void {
    security_log_event($eventKey, [
        'action_key' => accountDeleteSecurityActionKey($eventKey),
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'email' => $email,
        'ip_address' => $ipAddress,
        'endpoint' => $endpoint,
        'http_method' => $method,
        'actor_type' => 'tenant_user',
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => array_merge([
            'reason' => $reason,
        ], $extraDetails),
    ]);
}

function accountDeleteCheckRequestRateLimit(
    string $tenantId,
    string $userId,
    string $email,
    ?string $ipAddress,
    string $endpoint,
    string $method
): void {
    $rateLimitResult = security_rate_limit_check(
        'account_delete_request',
        [
            'tenant_id' => $tenantId,
            'email' => $email,
            'ip' => $ipAddress,
        ],
        [
            'endpoint' => $endpoint,
            'http_method' => $method,
            'actor_type' => 'tenant_user',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => $email,
            'ip_address' => $ipAddress,
            'metadata' => [
                'reason' => 'account_delete_request',
            ],
        ]
    );

    if (isset($rateLimitResult['allowed']) && $rateLimitResult['allowed'] === false) {
        accountDeleteLogSecurityEvent(
            'account_delete_rate_limited',
            $tenantId,
            $userId,
            $email,
            $ipAddress,
            $endpoint,
            $method,
            429,
            'blocked',
            'account_delete_request',
            [
                'limiter' => 'security_rate_limit_check',
            ]
        );

        http_response_code(429);

        $rateLimitPayload = security_neutral_rate_limit_response($rateLimitResult);
        if (!isset($rateLimitPayload['error'])) {
            $rateLimitPayload['error'] = (string) ($rateLimitPayload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
        }

        echo json_encode($rateLimitPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function accountDeleteRegisterInvalidCodeAttempt(
    string $tenantId,
    string $userId,
    string $email,
    ?string $ipAddress,
    string $endpoint,
    string $method,
    int $responseStatus,
    string $reason
): void {
    $rateLimitResult = security_rate_limit_check(
        'account_delete_confirm_invalid_code',
        [
            'tenant_id' => $tenantId,
            'email' => $email,
            'ip' => $ipAddress,
        ],
        [
            'endpoint' => $endpoint,
            'http_method' => $method,
            'actor_type' => 'tenant_user',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => $email,
            'ip_address' => $ipAddress,
            'metadata' => [
                'reason' => 'account_delete_confirm_invalid_code',
            ],
        ]
    );

    if (isset($rateLimitResult['allowed']) && $rateLimitResult['allowed'] === false) {
        accountDeleteLogSecurityEvent(
            'account_delete_confirm_rate_limited',
            $tenantId,
            $userId,
            $email,
            $ipAddress,
            $endpoint,
            $method,
            429,
            'blocked',
            'account_delete_confirm_invalid_code',
            [
                'limiter' => 'security_rate_limit_check',
            ]
        );

        http_response_code(429);

        $rateLimitPayload = security_neutral_rate_limit_response($rateLimitResult);
        if (!isset($rateLimitPayload['error'])) {
            $rateLimitPayload['error'] = (string) ($rateLimitPayload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
        }

        echo json_encode($rateLimitPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    accountDeleteLogSecurityEvent(
        'account_delete_confirm_invalid_code',
        $tenantId,
        $userId,
        $email,
        $ipAddress,
        $endpoint,
        $method,
        $responseStatus,
        'failed',
        $reason
    );
}

function buildAccountDeleteCodeHtml(string $code): string
{
    $message = ''
        . '<p style="margin:0 0 14px;"><strong>⚠️ Otrzymaliśmy żądanie usunięcia konta.</strong></p>'
        . '<p style="margin:0 0 10px;">Aby kontynuować usuwanie konta i danych, wpisz poniższy kod w panelu administratora:</p>'
        . '<div style="margin:22px 0;padding:18px 20px;background:#111827;color:#ffffff;'
        . 'font-size:32px;font-weight:700;letter-spacing:0.25em;text-align:center;border-radius:14px;">'
        . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
        . '</div>'
        . '<p style="margin:0 0 10px;">Kod jest ważny przez <strong>10 minut</strong>.</p>'
        . '<p style="margin:10px 0 0;">Jeśli to nie Ty inicjowałeś usunięcie konta, zignoruj tę wiadomość i jak najszybciej zabezpiecz konto.</p>';

    return buildSystemMailLayout(
        'Kod potwierdzenia usunięcia konta',
        'To wiadomość systemowa dotycząca bezpieczeństwa Twojego konta.',
        $message,
        'Nie odpowiadaj na tę wiadomość. Skrzynka nie jest monitorowana.'
    );
}

function sendAccountDeleteCode(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $email
): bool {
    $now = gmdate('Y-m-d\TH:i:s\Z');

    accountDeleteRequest(
        'PATCH',
        $supabaseUrl
            . '/rest/v1/account_deletion_codes'
            . '?tenant_id=eq.' . rawurlencode($tenantId)
            . '&user_id=eq.' . rawurlencode($userId)
            . '&used_at=is.null',
        $serviceRoleKey,
        $schema,
        ['used_at' => $now]
    );

    $plainCode = (string) random_int(100000, 999999);
    $insert = accountDeleteRequest(
        'POST',
        $supabaseUrl . '/rest/v1/account_deletion_codes',
        $serviceRoleKey,
        $schema,
        [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => $email,
            'code_hash' => password_hash($plainCode, PASSWORD_DEFAULT),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
            'attempts' => 0,
            'ip_address' => accountDeleteClientIpAddress(),
        ],
        ''
    );

    if (!$insert['ok']) {
        return false;
    }

    return sendSystemMail(
        $email,
        'Kod potwierdzenia usunięcia konta',
        buildAccountDeleteCodeHtml($plainCode)
    );
}

function verifyAccountDeleteCode(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $email,
    ?string $securityIp,
    string $securityEndpoint,
    string $securityMethod,
    string $code
): bool {
    $result = accountDeleteRequest(
        'GET',
        $supabaseUrl
            . '/rest/v1/account_deletion_codes'
            . '?select=id,code_hash,expires_at,used_at,attempts'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&user_id=eq.' . rawurlencode($userId)
            . '&used_at=is.null'
            . '&order=created_at.desc'
            . '&limit=1',
        $serviceRoleKey,
        $schema,
        null,
        ''
    );

    if (!$result['ok']) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się zweryfikować kodu potwierdzenia'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = is_array($result['data']) ? $result['data'] : [];
    $row = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

    if (!$row) {
        accountDeleteRegisterInvalidCodeAttempt(
            $tenantId,
            $userId,
            $email,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            404,
            'account_delete_confirm_no_active_code'
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
    $expiresAt = (string) ($row['expires_at'] ?? '');
    $attempts = (int) ($row['attempts'] ?? 0);

    if ($expiresAt === '' || strtotime($expiresAt) < time()) {
        accountDeleteRegisterInvalidCodeAttempt(
            $tenantId,
            $userId,
            $email,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            410,
            'account_delete_confirm_expired_code'
        );

        http_response_code(410);
        echo json_encode([
            'success' => false,
            'error' => 'Kod wygasł. Wygeneruj nowy kod.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($attempts >= 5) {
        accountDeleteLogSecurityEvent(
            'account_delete_confirm_rate_limited',
            $tenantId,
            $userId,
            $email,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            429,
            'blocked',
            'account_delete_confirm_invalid_code',
            [
                'limiter' => 'legacy_code_attempts',
            ]
        );

        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Przekroczono limit prób wpisania kodu. Wygeneruj nowy kod.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($codeHash === '' || !password_verify($code, $codeHash)) {
        accountDeleteRequest(
            'PATCH',
            $supabaseUrl
                . '/rest/v1/account_deletion_codes'
                . '?id=eq.' . rawurlencode($codeId)
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&user_id=eq.' . rawurlencode($userId),
            $serviceRoleKey,
            $schema,
            ['attempts' => $attempts + 1]
        );

        accountDeleteRegisterInvalidCodeAttempt(
            $tenantId,
            $userId,
            $email,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            422,
            'account_delete_confirm_invalid_code'
        );

        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'Nieprawidłowy kod potwierdzenia'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $markUsed = accountDeleteRequest(
        'PATCH',
        $supabaseUrl
            . '/rest/v1/account_deletion_codes'
            . '?id=eq.' . rawurlencode($codeId)
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&user_id=eq.' . rawurlencode($userId),
        $serviceRoleKey,
        $schema,
        [
            'used_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'attempts' => $attempts + 1,
        ]
    );

    if (($markUsed['ok'] ?? false) !== true) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się potwierdzić użycia kodu'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return true;
}

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'request_code') {
    accountDeleteCheckRequestRateLimit(
        $tenantId,
        $userId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod
    );
}

$userUrl = $supabaseUrl
    . '/rest/v1/users?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($userId)
    . '&select=id,email,password_hash,tenant_id'
    . '&limit=1';

$userCh = curl_init($userUrl);

curl_setopt_array($userCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept: application/json',
        'Accept-Profile: ' . $supabaseSchema,
        'Content-Profile: ' . $supabaseSchema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$userResponse = curl_exec($userCh);
$userHttpCode = (int) curl_getinfo($userCh, CURLINFO_HTTP_CODE);
$userCurlError = curl_error($userCh);

curl_close($userCh);

if ($userCurlError || $userHttpCode >= 400) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać danych użytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userData = json_decode((string) $userResponse, true);

if (!is_array($userData) || empty($userData[0]['password_hash'])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Nie znaleziono użytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRow = $userData[0];
$passwordHash = (string) ($userRow['password_hash'] ?? '');

if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
    if ($action === 'confirm_delete') {
        accountDeleteCheckRequestRateLimit(
            $tenantId,
            $userId,
            $securityEmail,
            $securityIp,
            $securityEndpoint,
            $securityMethod
        );
    }

    accountDeleteLogSecurityEvent(
        'account_delete_invalid_password',
        $tenantId,
        $userId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        422,
        'failed',
        'account_delete_invalid_password'
    );

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowe hasło'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'request_code') {
    accountDeleteLogSecurityEvent(
        'account_delete_request',
        $tenantId,
        $userId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        202,
        'accepted',
        'account_delete_request'
    );

    if (!sendAccountDeleteCode($supabaseUrl, $serviceRoleKey, $supabaseSchema, $tenantId, $userId, $userEmail)) {
        accountDeleteLogSecurityEvent(
            'account_delete_code_send_failed',
            $tenantId,
            $userId,
            $securityEmail,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            500,
            'failed',
            'account_delete_code_send_failed'
        );

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się wysłać kodu potwierdzającego'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    accountDeleteLogSecurityEvent(
        'account_delete_code_sent',
        $tenantId,
        $userId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        200,
        'success',
        'account_delete_code_sent'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Wysłaliśmy kod potwierdzający na adres e-mail administratora'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

verifyAccountDeleteCode(
    $supabaseUrl,
    $serviceRoleKey,
    $supabaseSchema,
    $tenantId,
    $userId,
    $securityEmail,
    $securityIp,
    $securityEndpoint,
    $securityMethod,
    $code
);

accountDeleteLogSecurityEvent(
    'account_delete_confirm_success',
    $tenantId,
    $userId,
    $securityEmail,
    $securityIp,
    $securityEndpoint,
    $securityMethod,
    202,
    'success',
    'account_delete_confirm_success'
);

$countUsersUrl = $supabaseUrl
    . '/rest/v1/users?tenant_id=eq.' . rawurlencode($tenantId)
    . '&select=id';

$countUsersCh = curl_init($countUsersUrl);

curl_setopt_array($countUsersCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept: application/json',
        'Accept-Profile: ' . $supabaseSchema,
        'Content-Profile: ' . $supabaseSchema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$countUsersResponse = curl_exec($countUsersCh);
$countUsersHttpCode = (int) curl_getinfo($countUsersCh, CURLINFO_HTTP_CODE);
$countUsersCurlError = curl_error($countUsersCh);

curl_close($countUsersCh);

if ($countUsersCurlError || $countUsersHttpCode >= 400) {
    accountDeleteLogSecurityEvent(
        'account_delete_failed',
        $tenantId,
        $userId,
        $securityEmail,
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        500,
        'failed',
        'account_delete_count_users_failed'
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się sprawdzić liczby użytkowników'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$countUsersData = json_decode((string) $countUsersResponse, true);
$userCount = is_array($countUsersData) ? count($countUsersData) : 0;
$isLastUser = $userCount <= 1;

if ($isLastUser) {
    $deleteTenantUrl = $supabaseUrl
        . '/rest/v1/tenant_branding?tenant_id=eq.' . rawurlencode($tenantId);

    $deleteTenantCh = curl_init($deleteTenantUrl);

    curl_setopt_array($deleteTenantCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept: application/json',
            'Prefer: return=minimal',
            'Accept-Profile: ' . $supabaseSchema,
            'Content-Profile: ' . $supabaseSchema,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $deleteTenantResponse = curl_exec($deleteTenantCh);
    $deleteTenantHttpCode = (int) curl_getinfo($deleteTenantCh, CURLINFO_HTTP_CODE);
    $deleteTenantCurlError = curl_error($deleteTenantCh);

    curl_close($deleteTenantCh);

      if ($deleteTenantCurlError || $deleteTenantHttpCode >= 400) {
        accountDeleteLogSecurityEvent(
            'account_delete_failed',
            $tenantId,
            $userId,
            $securityEmail,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            500,
            'failed',
            'account_delete_tenant_delete_failed'
        );

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się usunąć wszystkich danych konta'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    deleteTenantFiles($tenantId);
} else {
    $deleteUserUrl = $supabaseUrl
        . '/rest/v1/users?tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($userId);

    $deleteUserCh = curl_init($deleteUserUrl);

    curl_setopt_array($deleteUserCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept: application/json',
            'Prefer: return=minimal',
            'Accept-Profile: ' . $supabaseSchema,
            'Content-Profile: ' . $supabaseSchema,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $deleteUserResponse = curl_exec($deleteUserCh);
    $deleteUserHttpCode = (int) curl_getinfo($deleteUserCh, CURLINFO_HTTP_CODE);
    $deleteUserCurlError = curl_error($deleteUserCh);

    curl_close($deleteUserCh);

    if ($deleteUserCurlError || $deleteUserHttpCode >= 400) {
        accountDeleteLogSecurityEvent(
            'account_delete_failed',
            $tenantId,
            $userId,
            $securityEmail,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            500,
            'failed',
            'account_delete_user_delete_failed'
        );

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się usunąć konta'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function buildAccountDeletedHtml(string $email, bool $isLastUser): string
{
  $message = $isLastUser
    ? ''
        . '<p style="margin:0 0 14px;"><strong>Twoje konto oraz wszystkie dane zostały usunięte.</strong></p>'
        . '<p style="margin:0 0 10px;">Potwierdzamy usunięcie konta <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong> oraz wszystkich danych powiązanych z Twoją przestrzenią w systemie.</p>'
        . '<p style="margin:0 0 10px;">Szkoda, że odchodzisz. Jeśli czegoś zabrakło, coś nie działało tak jak trzeba albo możemy pomóc wrócić — napisz do nas na biuro@ai-iq.pl</p>'
        . '<p style="margin:0 0 10px;">Będzie nam też bardzo miło, jeśli zostawisz krótką opinię: co było okej, czego zabrakło i co warto poprawić.</p>'
        . '<p style="margin:14px 0 0;">Dziękujemy za korzystanie z naszej aplikacji.</p>'
    : ''
        . '<p style="margin:0 0 14px;"><strong>Twoje konto użytkownika zostało usunięte.</strong></p>'
        . '<p style="margin:0 0 10px;">Potwierdzamy usunięcie konta <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<p style="margin:0 0 10px;">Pozostałe dane organizacji zostały zachowane, ponieważ w tej przestrzeni istnieją jeszcze inni użytkownicy.</p>'
        . '<p style="margin:0 0 10px;">Jeśli czegoś zabrakło albo możemy pomóc wrócić — napisz do nas na biuro@ai-iq.pl</p>'
        . '<p style="margin:14px 0 0;">Dziękujemy za korzystanie z naszej aplikacji.</p>';

    $html = buildSystemMailLayout(
        'Potwierdzenie usunięcia konta',
        'To wiadomość systemowa potwierdzająca usunięcie konta.',
        $message,
        'Jeśli chcesz wrócić lub przekazać opinię, napisz na biuro@ai-iq.pl'
    );

    $footer = '<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">'
        . '© ' . date('Y') . ' '
        . '<a href="https://www.ai-iq.pl" style="color:#28406b;text-decoration:none;font-weight:700;">AI-IQ</a>'
        . ' | Inteligentne systemy · Powiadomienie systemowe'
        . '</div>';

    return preg_replace(
        '/<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">.*?<\/div>\s*<\/div>\s*$/s',
        $footer . '</div>',
        $html,
        1
    ) ?: $html;
}

sendSystemMail(
    $userEmail,
    'Potwierdzenie usunięcia konta',
    buildAccountDeletedHtml($userEmail, $isLastUser)
);

accountDeleteLogSecurityEvent(
    'account_delete_success',
    $tenantId,
    $userId,
    $securityEmail,
    $securityIp,
    $securityEndpoint,
    $securityMethod,
    200,
    'success',
    'account_delete_success'
);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();

echo json_encode([
    'success' => true,
    'message' => $isLastUser
        ? 'Konto oraz wszystkie dane zostały usunięte'
        : 'Konto użytkownika zostało usunięte'
], JSON_UNESCAPED_UNICODE);
