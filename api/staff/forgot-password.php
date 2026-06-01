<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/php_mail.php';

start_secure_session();

function staff_forgot_password_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_forgot_password_neutral(): void
{
    staff_forgot_password_json([
        'success' => true,
        'message' => 'Jeśli konto personelu istnieje, wysłaliśmy wiadomość z instrukcją resetu hasła.'
    ]);
}

function staff_forgot_password_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if (in_array(strtoupper($method), ['PATCH', 'POST'], true)) {
        $headers = array_values(array_filter($headers, static function (string $header): bool {
            return stripos($header, 'Prefer:') !== 0;
        }));
        $headers[] = 'Prefer: return=minimal';
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

function staff_forgot_password_company_name(string $supabaseUrl, string $supabaseKey, string $schema, string $tenantId): string
{
    $settingsUrl = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?select=company_full_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $settingsResult = staff_forgot_password_request('GET', $settingsUrl, $supabaseKey, $schema);

    if (
        $settingsResult['response'] !== false
        && $settingsResult['error'] === ''
        && $settingsResult['httpCode'] >= 200
        && $settingsResult['httpCode'] < 300
    ) {
        $settingsRows = is_array($settingsResult['data'] ?? null) ? $settingsResult['data'] : [];
        $settings = is_array($settingsRows[0] ?? null) ? $settingsRows[0] : [];
        $companyFullName = trim((string) ($settings['company_full_name'] ?? ''));

        if ($companyFullName !== '') {
            return $companyFullName;
        }
    }

    $brandingUrl = $supabaseUrl
        . '/rest/v1/tenant_branding'
        . '?select=client_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $brandingResult = staff_forgot_password_request('GET', $brandingUrl, $supabaseKey, $schema);

    if (
        $brandingResult['response'] !== false
        && $brandingResult['error'] === ''
        && $brandingResult['httpCode'] >= 200
        && $brandingResult['httpCode'] < 300
    ) {
        $brandingRows = is_array($brandingResult['data'] ?? null) ? $brandingResult['data'] : [];
        $branding = is_array($brandingRows[0] ?? null) ? $brandingRows[0] : [];
        $clientName = trim((string) ($branding['client_name'] ?? ''));

        if ($clientName !== '') {
            return $clientName;
        }
    }

    return 'Usługodawca';
}

function staff_forgot_password_reset_link(string $token): ?string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return null;
    }

    $scheme = 'https';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    return $scheme
        . '://'
        . $host
        . '/panel-personelu/reset-hasla.html?token='
        . rawurlencode($token);
}

function staff_forgot_password_mail_html(
    string $companyName,
    string $staffDisplayName,
    string $staffEmail,
    string $resetLink
): string
{
    $companyNameEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
    $staffDisplayNameEsc = htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8');
    $staffEmailEsc = htmlspecialchars($staffEmail, ENT_QUOTES, 'UTF-8');
    $resetLinkEsc = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>Otrzymaliśmy prośbę o reset hasła do panelu personelu.</strong></p>'
        . '<p style="margin:0 0 10px;">Firma: <strong>' . $companyNameEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Konto personelu: <strong>' . $staffDisplayNameEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">E-mail konta: <strong>' . $staffEmailEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Kliknij poniższy przycisk, aby ustawić nowe hasło:</p>'
        . '<div style="text-align:center;margin:26px 0;">'
        . '<a href="' . $resetLinkEsc . '" style="background:#212d45;color:#ffffff;padding:13px 22px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;">'
        . 'Ustaw nowe hasło'
        . '</a>'
        . '</div>'
        . '<p style="margin:0 0 10px;">Link jest ważny przez <strong>15 minut</strong>.</p>'
        . '<p style="margin:10px 0 0;">Jeśli to nie Ty inicjowałeś reset hasła, zignoruj tę wiadomość.</p>';

    return buildSystemMailLayout(
        'Reset hasła personelu',
        'To wiadomość systemowa dotycząca bezpieczeństwa konta personelu.',
        $message,
        'Nie odpowiadaj na wiadomość. Skrzynka nie jest monitorowana.'
    );
}

function staff_forgot_password_count_recent_tokens(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $field,
    string $value,
    string $since
): ?int {
    $url = $supabaseUrl
        . '/rest/v1/staff_password_reset_tokens'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&' . $field . '=eq.' . rawurlencode($value)
        . '&created_at=gte.' . rawurlencode($since);

    $result = staff_forgot_password_request('GET', $url, $supabaseKey, $schema);

    if (
        $result['response'] === false
        || $result['error'] !== ''
        || $result['httpCode'] < 200
        || $result['httpCode'] >= 300
    ) {
        return null;
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

    return count($rows);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Nie udało się ustalić firmy dla tej domeny.'
    ], 400);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON.'
    ], 400);
}

$email = strtolower(trim((string) ($input['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Podaj poprawny adres e-mail.'
    ], 400);
}

$ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateLimitSince = gmdate('c', time() - 900);
$recentEmailTokens = staff_forgot_password_count_recent_tokens(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    (string) $tenantId,
    'email',
    $email,
    $rateLimitSince
);
$recentIpTokens = staff_forgot_password_count_recent_tokens(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    (string) $tenantId,
    'ip_address',
    $ipAddress,
    $rateLimitSince
);

if (
    ($recentEmailTokens !== null && $recentEmailTokens >= 3)
    || ($recentIpTokens !== null && $recentIpTokens >= 10)
) {
    staff_forgot_password_neutral();
}

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,tenant_id,staff_id,email,is_active'
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&email=eq.' . rawurlencode($email)
    . '&limit=1';

$accountResult = staff_forgot_password_request('GET', $accountUrl, $supabaseKey, $schema);

if (
    $accountResult['response'] === false
    || $accountResult['error'] !== ''
    || $accountResult['httpCode'] < 200
    || $accountResult['httpCode'] >= 300
) {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Nie udało się obsłużyć resetu hasła.'
    ], 500);
}

$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];
$account = is_array($accountRows[0] ?? null) ? $accountRows[0] : null;

if (!is_array($account) || empty($account['id']) || empty($account['staff_id']) || !filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    staff_forgot_password_neutral();
}

$accountId = (string) ($account['id'] ?? '');
$staffId = (string) ($account['staff_id'] ?? '');
$accountEmail = strtolower(trim((string) ($account['email'] ?? $email)));

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,is_active,display_name'
    . '&tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_forgot_password_request('GET', $staffUrl, $supabaseKey, $schema);

if (
    $staffResult['response'] === false
    || $staffResult['error'] !== ''
    || $staffResult['httpCode'] < 200
    || $staffResult['httpCode'] >= 300
) {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Nie udało się obsłużyć resetu hasła.'
    ], 500);
}

$staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = is_array($staffRows[0] ?? null) ? $staffRows[0] : null;

if (!is_array($staff) || empty($staff['id']) || !filter_var($staff['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
    staff_forgot_password_neutral();
}

$staffDisplayName = trim((string) ($staff['display_name'] ?? ''));

if ($staffDisplayName === '') {
    $staffDisplayName = 'Personel';
}

$now = gmdate('c');
$invalidateUrl = $supabaseUrl
    . '/rest/v1/staff_password_reset_tokens'
    . '?tenant_id=eq.' . rawurlencode((string) $tenantId)
    . '&staff_account_id=eq.' . rawurlencode($accountId)
    . '&used_at=is.null';

staff_forgot_password_request('PATCH', $invalidateUrl, $supabaseKey, $schema, [
    'used_at' => $now,
]);

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = gmdate('c', time() + 900);

$insertResult = staff_forgot_password_request('POST', $supabaseUrl . '/rest/v1/staff_password_reset_tokens', $supabaseKey, $schema, [
    'tenant_id' => (string) $tenantId,
    'staff_account_id' => $accountId,
    'staff_id' => $staffId,
    'email' => $email,
    'token_hash' => $tokenHash,
    'expires_at' => $expiresAt,
    'ip_address' => $ipAddress,
]);

if (
    $insertResult['response'] === false
    || $insertResult['error'] !== ''
    || $insertResult['httpCode'] < 200
    || $insertResult['httpCode'] >= 300
) {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Nie udało się zapisać tokenu resetu hasła.'
    ], 500);
}

$resetLink = staff_forgot_password_reset_link($token);

if ($resetLink === null) {
    staff_forgot_password_json([
        'success' => false,
        'error' => 'Nie udało się ustalić domeny aplikacji.'
    ], 500);
}

$companyName = staff_forgot_password_company_name($supabaseUrl, $supabaseKey, $schema, (string) $tenantId);
$mailHtml = staff_forgot_password_mail_html($companyName, $staffDisplayName, $accountEmail, $resetLink);

sendSystemMail($email, 'Reset hasła personelu', $mailHtml);

staff_forgot_password_neutral();
