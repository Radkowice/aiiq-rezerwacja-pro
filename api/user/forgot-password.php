<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/php_mail.php';

start_secure_session();

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

function forgotPasswordJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function forgotPasswordNeutralSuccess(): void
{
    forgotPasswordJson([
        'success' => true,
        'message' => 'Jeśli konto istnieje, wysłaliśmy wiadomość z instrukcją resetu hasła.'
    ]);
}

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$TENANT_ID = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA);

if (!$TENANT_ID) {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Nie udało się ustalić klienta po domenie.'
    ], 400);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Brak danych wejściowych.'
    ], 400);
}

$email = trim((string)($input['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Podaj poprawny adres e-mail.'
    ], 400);
}

$securityEmail = $email;
$securityIp = security_client_ip();
$securityEndpoint = '/api/user/forgot-password.php';
$securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';

$rateLimitResult = security_rate_limit_check(
    'password_reset_request',
    [
        'tenant_id' => $TENANT_ID,
        'email' => $securityEmail,
        'ip' => $securityIp,
    ],
    [
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'tenant_id' => $TENANT_ID,
        'email' => $securityEmail,
        'ip_address' => $securityIp,
        'metadata' => [
            'reason' => 'password_reset_request',
        ],
    ]
);

if (isset($rateLimitResult['allowed']) && $rateLimitResult['allowed'] === false) {
    security_log_event('password_reset_rate_limited', [
        'tenant_id' => $TENANT_ID,
        'email' => $securityEmail,
        'ip_address' => $securityIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => 429,
        'result' => 'blocked',
        'details' => [
            'reason' => 'password_reset_request',
            'limiter' => 'security_rate_limit_check',
        ],
    ]);

    http_response_code(429);

    $rateLimitPayload = security_neutral_rate_limit_response($rateLimitResult);
    if (!isset($rateLimitPayload['error'])) {
        $rateLimitPayload['error'] = (string) ($rateLimitPayload['message'] ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.');
    }

    echo json_encode($rateLimitPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * Rate limit resetu hasła.
 * Limitujemy po IP + e-mail, ale nie ujawniamy, czy konto istnieje.
 */
$rateFile = __DIR__ . '/../data/rate_limit_reset.json';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$nowTs = time();
$limitSeconds = 600;
$maxAttempts = 3;

$rateDir = dirname($rateFile);

if (!is_dir($rateDir)) {
    @mkdir($rateDir, 0775, true);
}

$rateData = file_exists($rateFile)
    ? json_decode((string)file_get_contents($rateFile), true)
    : [];

if (!is_array($rateData)) {
    $rateData = [];
}

$emailKey = function_exists('mb_strtolower')
    ? mb_strtolower($email, 'UTF-8')
    : strtolower($email);

$rateKey = hash('sha256', $ip . '|' . $emailKey);

$rateData[$rateKey] = array_values(array_filter(
    $rateData[$rateKey] ?? [],
    static function ($timestamp) use ($nowTs, $limitSeconds) {
        return is_numeric($timestamp) && ($nowTs - (int)$timestamp) < $limitSeconds;
    }
));

if (count($rateData[$rateKey]) >= $maxAttempts) {
    security_log_event('password_reset_rate_limited', [
        'tenant_id' => $TENANT_ID,
        'email' => $securityEmail,
        'ip_address' => $securityIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => 429,
        'result' => 'blocked',
        'details' => [
            'reason' => 'password_reset_request',
            'limiter' => 'legacy_json_rate_limit',
        ],
    ]);

    forgotPasswordJson([
        'success' => false,
        'error' => 'Zbyt wiele prób. Spróbuj ponownie za 10 minut.'
    ], 429);
}

$rateData[$rateKey][] = $nowTs;

@file_put_contents(
    $rateFile,
    json_encode($rateData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);

security_log_event('password_reset_request', [
    'tenant_id' => $TENANT_ID,
    'email' => $securityEmail,
    'ip_address' => $securityIp,
    'endpoint' => $securityEndpoint,
    'http_method' => $securityMethod,
    'actor_type' => 'tenant_user',
    'response_status' => 202,
    'result' => 'accepted',
    'details' => [
        'reason' => 'password_reset_request',
    ],
]);

/*
 * Szukamy użytkownika.
 * Nie ujawniamy na froncie, czy e-mail istnieje.
 */
$userUrl = $SUPABASE_URL
    . '/rest/v1/users'
    . '?select=id,email,is_active'
    . '&email=eq.' . rawurlencode($email)
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';

$ch = curl_init($userUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $SUPABASE_KEY,
        'Authorization: Bearer ' . $SUPABASE_KEY,
        'Accept: application/json',
        'Accept-Profile: ' . $SUPABASE_DB_SCHEMA,
    ],
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error || $httpCode >= 400) {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Nie udało się obsłużyć resetu hasła.',
    ], 500);
}

$users = json_decode((string)$response, true);

if (!is_array($users) || count($users) === 0) {
    forgotPasswordNeutralSuccess();
}

$user = $users[0];

if (isset($user['is_active']) && $user['is_active'] === false) {
    forgotPasswordNeutralSuccess();
}

/*
 * Token resetu hasła.
 */
$token = bin2hex(random_bytes(32));
$expires = gmdate('c', time() + 900);

$tokenPayload = [[
    'tenant_id' => $TENANT_ID,
    'email' => $email,
    'token' => $token,
    'expires_at' => $expires,
]];

$tokenUrl = $SUPABASE_URL . '/rest/v1/password_reset_tokens';

$ch = curl_init($tokenUrl);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $SUPABASE_KEY,
        'Authorization: Bearer ' . $SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Profile: ' . $SUPABASE_DB_SCHEMA,
        'Prefer: return=minimal',
    ],
    CURLOPT_POSTFIELDS => json_encode($tokenPayload, JSON_UNESCAPED_UNICODE),
]);

$tokenResponse = curl_exec($ch);
$tokenError = curl_error($ch);
$tokenHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($tokenError || $tokenHttpCode >= 400) {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Nie udało się zapisać tokenu resetu hasła.',
    ], 500);
}

/*
 * Link resetu budowany z aktualnego hosta.
 */
$scheme = 'https';

if (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
) {
    $scheme = 'https';
}

$host = $_SERVER['HTTP_HOST'] ?? '';

if ($host === '') {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Nie udało się ustalić domeny aplikacji.'
    ], 500);
}

$link = $scheme
    . '://'
    . $host
    . '/nowe-haslo.html?token='
    . rawurlencode($token)
    . '&email='
    . rawurlencode($email);

/*
 * Konfiguracja SMTP z ENV.
 */

$safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

$mailMessage = ''
    . '<p style="margin:0 0 14px;"><strong>🔐 Otrzymaliśmy prośbę o reset hasła do Twojego konta.</strong></p>'
    . '<p style="margin:0 0 10px;">Kliknij poniższy przycisk, aby ustawić nowe hasło:</p>'
    . '<div style="text-align:center; margin:26px 0;">'
    . '<a href="' . $safeLink . '" style="background:#212d45;color:#ffffff;padding:13px 22px;'
    . 'text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;">'
    . 'Ustaw nowe hasło'
    . '</a>'
    . '</div>'
    . '<p style="margin:0 0 10px;">Link jest ważny przez <strong>15 minut</strong>.</p>'
    . '<p style="margin:10px 0 0;">Jeśli to nie Ty inicjowałeś reset hasła, zignoruj wiadomość.</p>';

$mailHtml = buildSystemMailLayout(
    'Reset hasła',
    'To wiadomość systemowa dotycząca bezpieczeństwa Twojego konta.',
    $mailMessage,
    'Nie odpowiadaj na wiadomość. Skrzynka nie jest monitorowana.'
);

$mailSent = sendSystemMail($email, 'Reset hasła', $mailHtml);

if (!$mailSent) {
    forgotPasswordJson([
        'success' => false,
        'error' => 'Nie udało się wysłać wiadomości resetującej hasło.'
    ], 500);
}

forgotPasswordNeutralSuccess();
