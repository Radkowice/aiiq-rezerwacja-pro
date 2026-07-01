<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/php_mail.php';

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

function buildPasswordChangeCodeHtml(string $code): string
{
    $message = ''
        . '<p style="margin:0 0 14px;"><strong>🔐 Otrzymaliśmy żądanie zmiany hasła do Twojego konta.</strong></p>'
        . '<p style="margin:0 0 10px;">Aby potwierdzić zmianę hasła, wpisz poniższy kod w panelu:</p>'
        . '<div style="margin:22px 0;padding:18px 20px;background:#111827;color:#ffffff;'
        . 'font-size:32px;font-weight:700;letter-spacing:0.25em;text-align:center;border-radius:14px;">'
        . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
        . '</div>'
        . '<p style="margin:0 0 10px;">Kod jest ważny przez <strong>10 minut</strong>.</p>'
        . '<p style="margin:10px 0 0;">Jeśli to nie Ty inicjowałeś zmianę hasła, zignoruj tę wiadomość i jak najszybciej zabezpiecz konto.</p>';
   
    return buildSystemMailLayout(
        'Kod potwierdzenia zmiany hasła',
        'To wiadomość systemowa dotycząca bezpieczeństwa Twojego konta.',
        $message,
        'Nie odpowiadaj na tę wiadomość. Skrzynka nie jest monitorowana.'
    );
}

function passwordChangeRateLimit(string $tenantId, string $userId, string $clientIp): string
{
    $rateFile = __DIR__ . '/../data/rate_limit_password_change.json';
    $rateDir = dirname($rateFile);

    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0775, true);
    }

    $handle = @fopen($rateFile, 'c+');

    if ($handle === false) {
        return 'storage_error';
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return 'storage_error';
    }

    rewind($handle);
    $rawData = stream_get_contents($handle);
    $rateData = json_decode((string) $rawData, true);

    if (!is_array($rateData)) {
        $rateData = [];
    }

    $now = time();
    $windowSeconds = 180;
    $rateKey = hash('sha256', $tenantId . '|' . $userId . '|' . $clientIp);

    $rateData[$rateKey] = array_values(array_filter(
        $rateData[$rateKey] ?? [],
        static function ($timestamp) use ($now, $windowSeconds): bool {
            return is_numeric($timestamp) && ($now - (int) $timestamp) < $windowSeconds;
        }
    ));

    if (count($rateData[$rateKey]) >= 1) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return 'limited';
    }

    $rateData[$rateKey][] = $now;
    $encoded = json_encode($rateData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($encoded === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return 'storage_error';
    }

    rewind($handle);

    if (!ftruncate($handle, 0)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return 'storage_error';
    }

    $written = fwrite($handle, $encoded);

    if ($written === false || $written < strlen($encoded)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return 'storage_error';
    }

    if (!fflush($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return 'storage_error';
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return 'allowed';
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

$input = json_decode(file_get_contents('php://input'), true);

$currentPassword = trim((string) ($input['current_password'] ?? ''));
$newPassword = (string) ($input['new_password'] ?? '');

// === WALIDACJA HASŁA ===
if (
    strlen($newPassword) < 8 ||
    !preg_match('/[a-z]/', $newPassword) ||
    !preg_match('/[A-Z]/', $newPassword) ||
    !preg_match('/[0-9]/', $newPassword) ||
    !preg_match('/[^A-Za-z0-9]/', $newPassword)
) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Hasło musi mieć min. 8 znaków, dużą i małą literę, cyfrę oraz znak specjalny.'
    ]);
    exit;
}

$confirmPassword = (string) ($input['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Wypełnij wszystkie pola'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nowe hasła nie są takie same'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($newPassword) < 8) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nowe hasło musi mieć minimum 8 znaków'
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
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userUrl = $supabaseUrl
    . '/rest/v1/users'
    . '?select=id,password_hash,email,tenant_id'
    . '&id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$userCh = curl_init($userUrl);
curl_setopt_array($userCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
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
    $curlError = curl_error($userCh);
    curl_close($userCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd połączenia z bazą'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userHttpCode = (int) curl_getinfo($userCh, CURLINFO_HTTP_CODE);
curl_close($userCh);

if ($userHttpCode < 200 || $userHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać użytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$users = json_decode($userResponse, true);
$user = is_array($users) && isset($users[0]) && is_array($users[0]) ? $users[0] : null;

if (!$user) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Nie znaleziono użytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$passwordHash = (string) ($user['password_hash'] ?? '');

if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Obecne hasło jest nieprawidłowe'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (password_verify($newPassword, $passwordHash)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nowe hasło musi być inne niż obecne'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIp = getClientIpAddress();

$rateLimitStatus = passwordChangeRateLimit($tenantId, $userId, $clientIp);

if ($rateLimitStatus === 'limited') {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($rateLimitStatus !== 'allowed') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = (string) random_int(100000, 999999);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
$expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + 600);

$insertPayload = json_encode([
    'tenant_id'         => $tenantId,
    'user_id'           => $userId,
    'email'             => $email,
    'new_password_hash' => $newPasswordHash,
    'code_hash'         => $codeHash,
    'expires_at'        => $expiresAt,
    'ip_address'        => $clientIp,
], JSON_UNESCAPED_UNICODE);

if ($insertPayload === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd przygotowania kodu zmiany hasła'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$insertUrl = $supabaseUrl . '/rest/v1/password_change_codes';

$insertCh = curl_init($insertUrl);
curl_setopt_array($insertCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $insertPayload,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . $serviceRoleKey,
    'Authorization: Bearer ' . $serviceRoleKey,
    'Accept-Profile: ' . $schema,
    'Content-Profile: ' . $schema,
],
    CURLOPT_TIMEOUT        => 20,
]);

$insertResponse = curl_exec($insertCh);

if ($insertResponse === false) {
    $curlError = curl_error($insertCh);
    curl_close($insertCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd zapisu kodu zmiany hasła'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$insertHttpCode = (int) curl_getinfo($insertCh, CURLINFO_HTTP_CODE);
curl_close($insertCh);

if ($insertHttpCode < 200 || $insertHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać kodu zmiany hasła'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mailHtml = buildPasswordChangeCodeHtml($code);
$mailSent = sendSystemMail($email, 'Kod potwierdzenia zmiany hasła', $mailHtml);

if (!$mailSent) {
    markPasswordChangeCodesUsed(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        [
            'code_hash=eq.' . rawurlencode($codeHash),
        ]
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się wysłać kodu potwierdzającego'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

markPasswordChangeCodesUsed(
    $supabaseUrl,
    $serviceRoleKey,
    $schema,
    $tenantId,
    $userId,
    [
        'code_hash=neq.' . rawurlencode($codeHash),
    ]
);

echo json_encode([
    'success' => true,
    'message' => 'Wysłaliśmy kod potwierdzający na Twój adres email'
], JSON_UNESCAPED_UNICODE);
