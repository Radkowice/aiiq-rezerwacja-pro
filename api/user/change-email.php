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
$newEmail = trim((string)($input['email'] ?? ''));

if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Niepoprawny email'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string) $_SESSION['user']['id'];
$currentEmail = (string) ($_SESSION['user']['email'] ?? '');

if ($currentEmail !== '' && mb_strtolower($newEmail) === mb_strtolower($currentEmail)) {
    echo json_encode([
        'success' => false,
        'error' => 'Nowy email jest taki sam jak obecny'
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
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

if ($tenantId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Brak tenant_id w sesji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$securityIp = security_client_ip();
$clientIp = $securityIp ?: getClientIpAddress();
$securityEndpoint = '/api/user/change-email.php';
$securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$securityEmail = $currentEmail !== '' ? $currentEmail : $newEmail;

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
        'error' => 'Błąd sprawdzania limitu zmian email'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rateCheckHttpCode = (int) curl_getinfo($rateCheckCh, CURLINFO_HTTP_CODE);
curl_close($rateCheckCh);

if ($rateCheckHttpCode < 200 || $rateCheckHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się sprawdzić limitu zmian email'
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
        'error' => 'Przekroczono limit zmian email. Spróbuj ponownie za godzinę.'
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
        'error' => 'Błąd przygotowania danych limitu zmian email'
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
        'error' => 'Błąd zapisu próby zmiany email'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$attemptHttpCode = (int) curl_getinfo($attemptCh, CURLINFO_HTTP_CODE);
curl_close($attemptCh);

if ($attemptHttpCode < 200 || $attemptHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać próby zmiany email'
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

$url = $supabaseUrl
    . '/rest/v1/users'
    . '?id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId);

$payload = json_encode([
    'email' => $newEmail
], JSON_UNESCAPED_UNICODE);

if ($payload === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd przygotowania danych'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
        'Prefer: return=representation',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 20,
]);

$response = curl_exec($ch);

if ($response === false) {
    $curlError = curl_error($ch);
    curl_close($ch);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd połączenia z bazą'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {

    $oldEmail = $_SESSION['user']['email'] ?? '';
    $_SESSION['user']['email'] = $newEmail;
    
    security_log_event('email_change_success', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'email' => $securityEmail,
        'ip_address' => $clientIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => 200,
        'result' => 'success',
        'details' => [
            'reason' => 'email_change_success',
        ],
    ]);

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$logPayload = json_encode([
    'tenant_id'  => $tenantId,
    'user_id'    => $userId,
    'old_email'  => $oldEmail,
    'new_email'  => $newEmail,
    'ip_address' => $clientIp,
    'user_agent' => $userAgent,
], JSON_UNESCAPED_UNICODE);

if ($logPayload !== false) {
    $logUrl = $supabaseUrl . '/rest/v1/email_change_logs';

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

    // 🔥 MAIL DO ADMINA
    
    require_once __DIR__ . '/../helpers/php_mail.php';

try {
    // 📩 mail na STARY adres (ostrzeżenie)
    $htmlOld = buildEmailChangeOldAddressHtml($oldEmail, $newEmail);
    sendSystemMail(
        $oldEmail,
        'Zmiana adresu email',
        $htmlOld
    );

    // 📩 mail na NOWY adres (potwierdzenie)
    $htmlNew = buildEmailChangeNewAddressHtml($oldEmail, $newEmail);
    sendSystemMail(
        $newEmail,
        'Potwierdzenie zmiany email',
        $htmlNew
    );

} catch (Throwable $e) {
    // NIE rozwalamy API
}
  

    echo json_encode([
        'success' => true,
        'message' => 'Email został zmieniony',
        'email' => $newEmail
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
    $curlError = curl_error($checkCh);
    curl_close($checkCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd sprawdzania emaila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$checkHttpCode = (int) curl_getinfo($checkCh, CURLINFO_HTTP_CODE);
curl_close($checkCh);

if ($checkHttpCode < 200 || $checkHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się sprawdzić dostępności emaila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$existingUsers = json_decode($checkResponse, true);

if (is_array($existingUsers) && !empty($existingUsers)) {
    security_log_event('email_change_conflict', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
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
        'error' => 'Ten adres email już istnieje'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

security_log_event('email_change_failed', [
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'email' => $securityEmail,
    'ip_address' => $clientIp,
    'endpoint' => $securityEndpoint,
    'http_method' => $securityMethod,
    'actor_type' => 'tenant_user',
    'response_status' => $httpCode > 0 ? $httpCode : 500,
    'result' => 'failed',
    'details' => [
        'reason' => 'email_change_failed',
    ],
]);

http_response_code(500);
echo json_encode([
    'success' => false,
    'error' => 'Nie udało się zmienić emaila. Spróbuj ponownie później.'
], JSON_UNESCAPED_UNICODE);
exit;
