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

function userDeleteAccountCsrfToken(): string
{
    $token = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($token === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $token = trim((string) ($headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ''));
    }

    return $token;
}

function requireUserDeleteAccountCsrf(): void
{
    $sessionToken = (string) ($_SESSION['csrf'] ?? '');
    $requestToken = userDeleteAccountCsrfToken();

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'csrf_invalid'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

requireUserDeleteAccountCsrf();

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

function accountDeletePatchCodeCas(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $codeId,
    string $codeHash,
    int $expectedAttempts,
    array $changes
): bool {
    if ($codeId === '' || $codeHash === '' || $expectedAttempts < 0) {
        return false;
    }

    $payload = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return false;
    }

    $url = $supabaseUrl
        . '/rest/v1/account_deletion_codes'
        . '?select=id'
        . '&id=eq.' . rawurlencode($codeId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&user_id=eq.' . rawurlencode($userId)
        . '&code_hash=eq.' . rawurlencode($codeHash)
        . '&used_at=is.null'
        . '&attempts=eq.' . rawurlencode((string) $expectedAttempts);

    $ch = curl_init($url);

    if ($ch === false) {
        return false;
    }

    $configured = curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
            'Prefer: return=representation',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    if (!$configured) {
        curl_close($ch);

        return false;
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    $rows = json_decode((string) $response, true);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_array($rows)
        || count($rows) !== 1
        || !isset($rows[0])
        || !is_array($rows[0])
        || !array_key_exists('id', $rows[0])
        || (!is_string($rows[0]['id']) && !is_int($rows[0]['id']))
    ) {
        return false;
    }

    return hash_equals($codeId, (string) $rows[0]['id']);
}

function accountDeleteIncrementCodeAttemptsCas(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $codeId,
    string $codeHash,
    int $attempts
): bool {
    return accountDeletePatchCodeCas(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        $codeId,
        $codeHash,
        $attempts,
        ['attempts' => $attempts + 1]
    );
}

function accountDeleteMarkCodeUsedCas(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $codeId,
    string $codeHash,
    int $attempts
): bool {
    return accountDeletePatchCodeCas(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        $codeId,
        $codeHash,
        $attempts,
        ['used_at' => gmdate('Y-m-d\TH:i:s\Z')]
    );
}

function accountDeleteTenantWithConsentRetention(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId
): bool {
    $payload = json_encode([
        'p_tenant_id' => $tenantId,
        'p_user_id' => $userId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return false;
    }

    $ch = curl_init(
        $supabaseUrl . '/rest/v1/rpc/delete_tenant_with_consent_retention'
    );

    if ($ch === false) {
        return false;
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

        return false;
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    $result = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
        return false;
    }

    return array_key_exists('success', $result)
        && $result['success'] === true
        && array_key_exists('scope', $result)
        && $result['scope'] === 'tenant';
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

function accountDeleteIssueCode(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $email,
    string $codeHash,
    ?string $ipAddress
): string {
    if ($codeHash === '') {
        return 'failed';
    }

    $payload = json_encode([
        'p_tenant_id' => $tenantId,
        'p_user_id' => $userId,
        'p_email' => $email,
        'p_code_hash' => $codeHash,
        'p_ip_address' => $ipAddress,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return 'failed';
    }

    $ch = curl_init(
        $supabaseUrl . '/rest/v1/rpc/issue_account_deletion_code'
    );

    if ($ch === false) {
        return 'failed';
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

        return 'failed';
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return 'failed';
    }

    $result = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
        return 'failed';
    }

    if (
        count($result) === 1
        && array_key_exists('success', $result)
        && $result['success'] === true
    ) {
        return 'issued';
    }

    if (
        count($result) === 2
        && array_key_exists('success', $result)
        && $result['success'] === false
        && array_key_exists('reason', $result)
        && $result['reason'] === 'retry_later'
    ) {
        return 'retry_later';
    }

    return 'failed';
}

function accountDeleteInvalidateIssuedCode(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $codeHash
): bool {
    if ($codeHash === '') {
        return false;
    }

    $payload = json_encode([
        'used_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return false;
    }

    $url = $supabaseUrl
        . '/rest/v1/account_deletion_codes'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&user_id=eq.' . rawurlencode($userId)
        . '&code_hash=eq.' . rawurlencode($codeHash)
        . '&used_at=is.null'
        . '&attempts=eq.0';

    $ch = curl_init($url);

    if ($ch === false) {
        return false;
    }

    $configured = curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
            'Prefer: return=representation',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    if (!$configured) {
        curl_close($ch);

        return false;
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    $rows = json_decode((string) $response, true);

    return json_last_error() === JSON_ERROR_NONE
        && is_array($rows)
        && count($rows) === 1
        && isset($rows[0])
        && is_array($rows[0])
        && array_key_exists('id', $rows[0])
        && (is_string($rows[0]['id']) || is_int($rows[0]['id']))
        && (string) $rows[0]['id'] !== '';
}

function sendAccountDeleteCode(string $email, string $plainCode): bool
{
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
            . '?select=id,tenant_id,user_id,code_hash,expires_at,used_at,attempts'
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
        if (!accountDeleteIncrementCodeAttemptsCas(
            $supabaseUrl,
            $serviceRoleKey,
            $schema,
            $tenantId,
            $userId,
            $codeId,
            $codeHash,
            $attempts
        )) {
            accountDeleteLogSecurityEvent(
                'account_delete_failed',
                $tenantId,
                $userId,
                $email,
                $securityIp,
                $securityEndpoint,
                $securityMethod,
                500,
                'failed',
                'account_delete_attempts_cas_failed'
            );

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Nie udało się zweryfikować kodu potwierdzenia'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

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

    if (!accountDeleteMarkCodeUsedCas(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        $codeId,
        $codeHash,
        $attempts
    )) {
        accountDeleteLogSecurityEvent(
            'account_delete_failed',
            $tenantId,
            $userId,
            $email,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            500,
            'failed',
            'account_delete_code_use_cas_failed'
        );

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się zweryfikować kodu potwierdzenia'
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

if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $supabaseSchema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
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
    $plainCode = (string) random_int(100000, 999999);
    $codeHash = password_hash($plainCode, PASSWORD_DEFAULT);

    if (!is_string($codeHash) || $codeHash === '') {
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
            'account_delete_code_hash_failed'
        );

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się wysłać kodu potwierdzającego'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $issueStatus = accountDeleteIssueCode(
        $supabaseUrl,
        $serviceRoleKey,
        $supabaseSchema,
        $tenantId,
        $userId,
        $userEmail,
        $codeHash,
        $securityIp
    );

    if ($issueStatus === 'retry_later') {
        accountDeleteLogSecurityEvent(
            'account_delete_rate_limited',
            $tenantId,
            $userId,
            $securityEmail,
            $securityIp,
            $securityEndpoint,
            $securityMethod,
            429,
            'blocked',
            'account_delete_request_cooldown'
        );

        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Zbyt wiele prób. Spróbuj ponownie za chwilę.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($issueStatus !== 'issued') {
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
            'account_delete_code_issue_failed'
        );

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się wysłać kodu potwierdzającego'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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

    if (!sendAccountDeleteCode($userEmail, $plainCode)) {
        $invalidated = accountDeleteInvalidateIssuedCode(
            $supabaseUrl,
            $serviceRoleKey,
            $supabaseSchema,
            $tenantId,
            $userId,
            $codeHash
        );

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
            $invalidated
                ? 'account_delete_code_send_failed_invalidated'
                : 'account_delete_code_send_failed_compensation_failed'
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

if (!accountDeleteTenantWithConsentRetention(
    $supabaseUrl,
    $serviceRoleKey,
    $supabaseSchema,
    $tenantId,
    $userId
)) {
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
        'account_delete_rpc_failed'
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się usunąć konta'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

deleteTenantFiles($tenantId);

function buildAccountDeletedHtml(string $email): string
{
    $message = ''
        . '<p style="margin:0 0 14px;"><strong>Twoje konto zostało usunięte.</strong></p>'
        . '<p style="margin:0 0 10px;">Potwierdzamy usunięcie konta <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong>. Niektóre dane wymagające dalszego przechowywania do celów dowodowych pozostają objęte obowiązującą polityką retencji.</p>'
        . '<p style="margin:0 0 10px;">Szkoda, że odchodzisz. Jeśli czegoś zabrakło, coś nie działało tak jak trzeba albo możemy pomóc wrócić — napisz do nas na biuro@ai-iq.pl</p>'
        . '<p style="margin:0 0 10px;">Będzie nam też bardzo miło, jeśli zostawisz krótką opinię: co było okej, czego zabrakło i co warto poprawić.</p>'
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
    buildAccountDeletedHtml($userEmail)
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
    'message' => 'Konto zostało usunięte'
], JSON_UNESCAPED_UNICODE);
