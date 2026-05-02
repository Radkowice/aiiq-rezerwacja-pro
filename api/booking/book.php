<?php

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../helpers/google_calendar.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

start_secure_session();
date_default_timezone_set('Europe/Warsaw');

function json_response(array $payload, int $status = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function debug_log(string $label, $data): void
{
    @file_put_contents(
        '/var/www/data/debug.log',
        date('Y-m-d H:i:s') . " [{$label}] " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n",
        FILE_APPEND
    );
}

function is_valid_international_phone(string $phone): bool
{
    $phone = trim($phone);

    if ($phone === '') {
        return false;
    }

    if (!preg_match('/^\+?[0-9\s-]+$/', $phone)) {
        return false;
    }

    if (substr_count($phone, '+') > 1) {
        return false;
    }

    if (str_contains($phone, '+') && !str_starts_with($phone, '+')) {
        return false;
    }

    $digits = preg_replace('/\D+/', '', $phone);

    if (!is_string($digits)) {
        return false;
    }

    if (str_starts_with($phone, '+48')) {
        return strlen($digits) === 11 && str_starts_with($digits, '48');
    }

    if (str_starts_with($phone, '+')) {
        return false;
    }

    return strlen($digits) === 9;
}

function supabase_headers(string $key, string $schema, bool $minimal = false): array
{
    $headers = [
        "apikey: {$key}",
        "Authorization: Bearer {$key}",
        'Content-Type: application/json',
        'Accept: application/json',
        "Accept-Profile: {$schema}",
        "Content-Profile: {$schema}",
    ];

    $headers[] = $minimal ? 'Prefer: return=minimal' : 'Prefer: return=representation';

    return $headers;
}

function supabase_insert(string $url, array $payload, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'response' => $response,
        'error' => $error,
        'httpCode' => $httpCode,
    ];
}

function supabase_select(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = null;
    if ($response !== false && $response !== '') {
        $decoded = json_decode($response, true);
    }

    return [
        'response' => $response,
        'data' => $decoded,
        'error' => $error,
        'httpCode' => $httpCode,
    ];
}

function fetch_single_record(string $baseUrl, array $headers, string $table, string $query): ?array
{
    $url = rtrim($baseUrl, '/') . "/rest/v1/{$table}?{$query}&limit=1";
    $res = supabase_select($url, $headers);

    if (($res['httpCode'] ?? 0) !== 200 || empty($res['data'][0]) || !is_array($res['data'][0])) {
        return null;
    }

    return $res['data'][0];
}

function calculate_payment_expires_at(int $value, string $unit): string
{
    if ($value <= 0) {
        $value = 48;
    }

    $unit = in_array($unit, ['hours', 'days'], true) ? $unit : 'hours';

    $date = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

    if ($unit === 'days') {
        $date = $date->modify('+' . $value . ' days');
    } else {
        $date = $date->modify('+' . $value . ' hours');
    }

    return $date->format(DateTimeInterface::ATOM);
}

function replacePlaceholders(string $text, array $data): string
{
    return str_replace(array_keys($data), array_values($data), $text);
}

function getSystemFooter(): string
{
  return
    '<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">' .
        'Obsługiwane przez <a href="https://ai-iq.pl" target="_blank" style="color:#607284;text-decoration:none;font-weight:600;">AI-IQ</a> | Inteligentne automatyzacje' .
    '</div>';
}

function buildFooter(string $plan, string $mode, string $custom): string
{
    if ($plan === 'basic') {
        return getSystemFooter();
    }

    if ($plan === 'pro') {
        return $mode === 'none' ? '' : getSystemFooter();
    }

    if ($plan === 'premium') {
        if ($mode === 'custom' && trim($custom) !== '') {
            return $custom;
        }
        if ($mode === 'none') {
            return '';
        }
        return getSystemFooter();
    }

    return getSystemFooter();
}

function buildClientEmailHtml(
    string $introHtml,
    string $companyName,
    string $serviceName,
    string $footerHtml,
    string $name,
    string $email,
    string $date,
    string $time,
    string $note
): string {
    return
        '<div style="margin:0;padding:0;background:#f4f7fb;">' .
            '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">' .

                '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">' .
                    '<div style="font-size:42px;line-height:1;margin-bottom:12px;">📅</div>' .
                    '<h1 style="margin:0;font-size:28px;">Rezerwacja potwierdzona</h1>' .
                    '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">Dziękujemy za umówienie ' . htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') . ' | ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' .
                '</div>' .

                '<div style="padding:32px 24px;">' .
                    $introHtml .

                    '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📆 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>' .
                    '</div>' .

                    (
                        trim($note) !== ''
                            ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                                '<p style="margin:0;font-size:16px;"><strong>💬 Twoja wiadomość:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' .
                              '</div>'
                            : ''
                    ) .

                    '<p style="font-size:14px;line-height:1.6;color:#4f6478;">W razie pytań po prostu odpowiedz na tę wiadomość.</p>' .
                '</div>' .

                $footerHtml .

            '</div>' .
        '</div>';
}

function buildAdminEmailHtml(
    string $introHtml,
    string $companyName,
    string $footerHtml,
    string $name,
    string $email,
    string $phone,
    string $date,
    string $time,
    string $note
): string {
    return
        '<div style="margin:0;padding:0;background:#f4f7fb;">' .
            '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">' .

                '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">' .
                    '<div style="font-size:42px;line-height:1;margin-bottom:12px;">📬</div>' .
                    '<h1 style="margin:0;font-size:28px;">Nowa rezerwacja</h1>' .
                    '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' .
                '</div>' .

                '<div style="padding:32px 24px;">' .
                    $introHtml .

                    '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📞 Telefon:</strong> ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📆 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>' .
                    '</div>' .

                    (
                        trim($note) !== ''
                            ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                                '<p style="margin:0;font-size:16px;"><strong>💬 Wiadomość klienta:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' .
                              '</div>'
                            : ''
                    ) .

                '</div>' .

                $footerHtml .

            '</div>' .
        '</div>';
}

function configureMailer(PHPMailer $mail, array $emailSettings): void
{
    $smtpHost = trim((string) ($emailSettings['smtp_host'] ?? ''));
    $smtpPort = (int) ($emailSettings['smtp_port'] ?? 587);

    $smtpUser = trim((string) (
        $emailSettings['smtp_user']
        ?? $emailSettings['smtp_username']
        ?? ''
    ));

    $smtpPass = (string) (
        $emailSettings['smtp_pass']
        ?? $emailSettings['smtp_password']
        ?? ''
    );

    $fromEmail = trim((string) (
        $emailSettings['smtp_email']
        ?? $emailSettings['from_email']
        ?? ''
    ));

    $fromName = trim((string) (
        $emailSettings['smtp_name']
        ?? $emailSettings['from_name']
        ?? ''
    ));

    if ($smtpHost === '') {
        throw new Exception('Brak smtp_host w email_settings');
    }

    if ($fromEmail === '') {
        throw new Exception('Brak smtp_email/from_email w email_settings');
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Nieprawidłowy adres nadawcy SMTP');
    }

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->Port = $smtpPort > 0 ? $smtpPort : 587;
    $mail->SMTPAuth = $smtpUser !== '' || $smtpPass !== '';
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->CharSet = 'UTF-8';

    $encryption = strtolower(trim((string) ($emailSettings['smtp_encryption'] ?? 'tls')));

    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls' || $encryption === '') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'none') {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : $fromEmail);

    $replyToEmail = trim((string) ($emailSettings['reply_to_email'] ?? ''));

    if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $replyToName = trim((string) ($emailSettings['reply_to_name'] ?? ''));
        $mail->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $replyToEmail);
    }
}

// ENV
$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    json_response([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase',
    ], 500);
}

// Tenant po domenie
$TENANT_ID = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA);
debug_log('BOOK_TENANT_FINAL', $TENANT_ID);

if (!$TENANT_ID) {
    debug_log('BOOK_TENANT_ERROR', [
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'server_name' => $_SERVER['SERVER_NAME'] ?? null,
    ]);

    json_response([
        'success' => false,
        'error' => 'Błąd konfiguracji systemu (brak tenant)',
    ], 400);
}

// Input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

if (!is_array($input) || empty($input)) {
    json_response([
        'success' => false,
        'error' => 'Brak danych',
    ], 400);
}

// Anty-spam / blacklist / rate limit
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$blacklistFile = __DIR__ . '/../data/blacklist.json';
if (!file_exists($blacklistFile)) {
    @file_put_contents($blacklistFile, json_encode([], JSON_UNESCAPED_UNICODE));
}
$blacklist = json_decode(@file_get_contents($blacklistFile), true);
if (!is_array($blacklist)) {
    $blacklist = [];
}

if (in_array($ip, $blacklist, true)) {
    json_response([
        'success' => false,
        'error' => 'Dostęp zablokowany',
    ], 403);
}

$rateFile = __DIR__ . '/../data/rate_limit_book.json';
if (!file_exists($rateFile)) {
    @file_put_contents($rateFile, json_encode([], JSON_UNESCAPED_UNICODE));
}

$rateData = json_decode(@file_get_contents($rateFile), true);
if (!is_array($rateData)) {
    $rateData = [];
}

$now = time();
$limit = 3;
$window = 60;

if (!isset($rateData[$ip]) || !is_array($rateData[$ip])) {
    $rateData[$ip] = [];
}

$rateData[$ip] = array_values(array_filter($rateData[$ip], function ($t) use ($now, $window) {
    return ($now - (int) $t) < $window;
}));

if (count($rateData[$ip]) >= $limit) {
    $banFile = __DIR__ . '/../data/ban_counter.json';
    if (!file_exists($banFile)) {
        @file_put_contents($banFile, json_encode([], JSON_UNESCAPED_UNICODE));
    }

    $banData = json_decode(@file_get_contents($banFile), true);
    if (!is_array($banData)) {
        $banData = [];
    }

    if (!isset($banData[$ip])) {
        $banData[$ip] = 0;
    }

    $banData[$ip]++;
    @file_put_contents($banFile, json_encode($banData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    if ($banData[$ip] >= 5) {
        $blacklist[] = $ip;
        @file_put_contents(
            $blacklistFile,
            json_encode(array_values(array_unique($blacklist)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    json_response([
        'success' => false,
        'error' => 'Za dużo prób. Spróbuj za chwilę.',
    ], 429);
}

$rateData[$ip][] = $now;
@file_put_contents($rateFile, json_encode($rateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

if (!isset($_SESSION['last_booking_time'])) {
    $_SESSION['last_booking_time'] = 0;
}

if (time() - (int) $_SESSION['last_booking_time'] < 10) {
    json_response([
        'success' => false,
        'error' => 'Poczekaj chwilę przed kolejną próbą',
    ], 429);
}

$_SESSION['last_booking_time'] = time();

// Walidacja

$date  = trim((string) ($input['date'] ?? ''));
$time  = trim((string) ($input['time'] ?? ''));
$name  = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$note  = trim((string) ($input['note'] ?? $input['message'] ?? ''));

$website = trim((string) ($input['website'] ?? ''));
$formStartedAtRaw = trim((string) ($input['form_started_at'] ?? ''));
$termsAcceptedRaw = $input['terms_accepted'] ?? null;

// Cicha pułapka na boty — człowiek tego pola nie widzi.
if ($website !== '') {
    debug_log('BOOK_BOT_HONEYPOT_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'website' => $website,
    ]);

    json_response([
        'success' => false,
        'error' => 'Nie udało się zapisać rezerwacji. Spróbuj ponownie za chwilę.',
    ], 400);
}

// Zgoda regulaminu musi być potwierdzona również na backendzie.
$termsAccepted = in_array($termsAcceptedRaw, [true, 1, '1', 'true', 'on', 'yes'], true);

if (!$termsAccepted) {
    json_response([
        'success' => false,
        'error' => 'Zaakceptuj regulamin i politykę prywatności.',
    ], 400);
}

// Minimalny czas wypełnienia formularza.
// Date.now() z frontu wysyła milisekundy.
$formStartedAt = ctype_digit($formStartedAtRaw) ? (int) $formStartedAtRaw : 0;
$formSubmittedAt = (int) round(microtime(true) * 1000);

if ($formStartedAt <= 0) {
    debug_log('BOOK_BOT_MISSING_FORM_STARTED_AT', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
    ]);

    json_response([
        'success' => false,
        'error' => 'Odśwież stronę i spróbuj ponownie.',
    ], 400);
}

$formFillTimeMs = $formSubmittedAt - $formStartedAt;

if ($formFillTimeMs < 3000) {
    debug_log('BOOK_BOT_TOO_FAST_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'form_fill_time_ms' => $formFillTimeMs,
    ]);

    json_response([
        'success' => false,
        'error' => 'Formularz został wysłany zbyt szybko. Spróbuj ponownie.',
    ], 400);
}

if ($formFillTimeMs > 1000 * 60 * 60 * 6) {
    debug_log('BOOK_FORM_TOO_OLD_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'form_fill_time_ms' => $formFillTimeMs,
    ]);

    json_response([
        'success' => false,
        'error' => 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.',
    ], 400);
}

$headers = supabase_headers($SUPABASE_KEY, $SUPABASE_DB_SCHEMA, false);
$minimalHeaders = supabase_headers($SUPABASE_KEY, $SUPABASE_DB_SCHEMA, true);

$calendarSettingsUrl = $SUPABASE_URL
    . '/rest/v1/calendar_settings'
    . '?select=calendar_enabled'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';

$calendarSettingsResult = supabase_select($calendarSettingsUrl, $headers);
$calendarSettingsRow = $calendarSettingsResult['data'][0] ?? [];

$calendarEnabled = !empty($calendarSettingsRow['calendar_enabled']);

if ($calendarEnabled !== true) {
    json_response([
        'success' => false,
        'error' => 'Kalendarz rezerwacji jest obecnie wyłączony.',
    ], 403);
}

$formFieldsUrl = $SUPABASE_URL
    . '/rest/v1/tenant_branding'
    . '?select=calendar_form_fields'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';

$formFieldsResult = supabase_select($formFieldsUrl, $headers);
$formFieldsRow = $formFieldsResult['data'][0] ?? [];
$formFields = is_array($formFieldsRow['calendar_form_fields'] ?? null)
    ? $formFieldsRow['calendar_form_fields']
    : [];

$requireEmail = true;
$requirePhone = ($formFields['show_phone'] ?? true) !== false;

if ($date === '' || $time === '' || $name === '') {
    json_response([
        'success' => false,
        'error' => 'Brak wymaganych danych',
    ], 400);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy format daty',
    ], 400);
}

$dateCheck = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateCheck || $dateCheck->format('Y-m-d') !== $date) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowa data',
    ], 400);
}

if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy format godziny',
    ], 400);
}

if ($requireEmail) {
    if ($email === '') {
        json_response([
            'success' => false,
            'error' => 'Brak adresu e-mail',
        ], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy adres e-mail',
        ], 400);
    }
} else {
    $email = '';
}

if ($requirePhone) {
    if ($phone === '') {
        json_response([
            'success' => false,
            'error' => 'Brak numeru telefonu',
        ], 400);
    }

    if (!is_valid_international_phone($phone)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy numer telefonu',
        ], 400);
    }

    $phone = trim(preg_replace('/\s+/', ' ', $phone) ?? '');
} else {
    $phone = '';
}

// Ustawienia usługi i płatności
$tenantQuery = 'tenant_id=eq.' . rawurlencode($TENANT_ID);

$serviceSettings = fetch_single_record(
    $SUPABASE_URL,
    $headers,
    'tenant_service_settings',
    $tenantQuery
);

$paymentRequired = false;
$paymentStatus = 'not_required';
$paymentProvider = null;
$paymentAmount = null;
$paymentCurrency = 'PLN';
$paymentExpiresAt = null;

$paymentRequiredConfigured = false;
$payuEnabled = false;

$payuIntegration = fetch_single_record(
    $SUPABASE_URL,
    $headers,
    'tenant_integrations',
    $tenantQuery . '&provider=eq.payu'
);

if (is_array($payuIntegration)) {
    $payuEnabled = !empty($payuIntegration['enabled']);
}

if (is_array($serviceSettings)) {
    $paymentRequiredConfigured = !empty($serviceSettings['payment_required']);
    $paymentRequired = $paymentRequiredConfigured && $payuEnabled;

    $configuredAmount = isset($serviceSettings['price_amount'])
        ? (float) $serviceSettings['price_amount']
        : 0.0;

    $configuredCurrency = trim((string) ($serviceSettings['price_currency'] ?? 'PLN'));

    if ($configuredCurrency === '') {
        $configuredCurrency = 'PLN';
    }

    if ($paymentRequired) {
        $paymentStatus = 'pending';
        $paymentProvider = 'payu';
        $paymentAmount = $configuredAmount > 0 ? $configuredAmount : null;
        $paymentCurrency = $configuredCurrency;

        $paymentLimitValue = (int) ($serviceSettings['payment_time_limit_value'] ?? 48);
        $paymentLimitUnit = (string) ($serviceSettings['payment_time_limit_unit'] ?? 'hours');

        $paymentExpiresAt = calculate_payment_expires_at($paymentLimitValue, $paymentLimitUnit);
    }
}

debug_log('BOOK_PAYMENT_SETTINGS', [
    'payment_required_configured' => $paymentRequiredConfigured,
    'payu_enabled' => $payuEnabled,
    'payment_required' => $paymentRequired,
    'payment_status' => $paymentStatus,
    'payment_provider' => $paymentProvider,
    'payment_amount' => $paymentAmount,
    'payment_currency' => $paymentCurrency,
    'payment_expires_at' => $paymentExpiresAt,
]);
// Zapis rezerwacji
$bookingPayload = [
    'tenant_id'    => $TENANT_ID,
    'booking_date' => $date,
    'booking_time' => $time,
    'name'         => $name,
    'email'        => $email,
    'phone'        => $phone,
    'notes'        => $note,
    'status'       => 'new',
    'source'       => 'www',

    'payment_required'   => $paymentRequired,
    'payment_status'     => $paymentStatus,
    'payment_provider'   => $paymentProvider,
    'payment_amount'     => $paymentAmount,
    'payment_currency'   => $paymentCurrency,
    'payment_expires_at' => $paymentExpiresAt,

    'created_at'   => date('c'),
    'updated_at'   => date('c'),
];

$bookingResult = supabase_insert(
    $SUPABASE_URL . '/rest/v1/bookings',
    $bookingPayload,
    $headers
);

debug_log('BOOK_BOOKINGS_RESPONSE', $bookingResult['response'] ?: $bookingResult['error']);

if ($bookingResult['error'] || $bookingResult['httpCode'] >= 400) {
    json_response([
        'success' => false,
        'error' => 'Błąd zapisu rezerwacji',
        'httpCode' => $bookingResult['httpCode'],
        'debug' => $bookingResult['response'] ?: $bookingResult['error'],
    ], 500);
}

$bookingRows = json_decode((string)($bookingResult['response'] ?? ''), true);
$createdBooking = is_array($bookingRows) && isset($bookingRows[0]) && is_array($bookingRows[0])
    ? $bookingRows[0]
    : [];

$bookingId = (string)($createdBooking['id'] ?? '');

debug_log('BOOK_CREATED_ID', $bookingId !== '' ? $bookingId : 'BRAK_ID');

// Blokada terminu

$blockPayload = [
    'tenant_id' => $TENANT_ID,
    'date'      => $date,
    'time'      => $time,
];

$blockResult = supabase_insert(
    $SUPABASE_URL . '/rest/v1/blocked_times',
    $blockPayload,
    $minimalHeaders
);

debug_log('BOOK_BLOCKED_TIMES_RESPONSE', $blockResult['response'] ?: $blockResult['error']);

if ($blockResult['error'] || $blockResult['httpCode'] >= 400) {
    json_response([
        'success' => false,
        'error' => 'Rezerwacja zapisana, ale nie udało się zablokować godziny',
        'httpCode' => $blockResult['httpCode'],
        'debug' => $blockResult['response'] ?: $blockResult['error'],
    ], 500);
}

// Google Calendar — tworzenie wydarzenia po zapisaniu rezerwacji
try {
    if ($bookingId !== '') {
        $bookingForGoogle = array_merge($bookingPayload, [
            'id' => $bookingId,
        ]);

        $googleTenantId = (string)($bookingPayload['tenant_id'] ?? '');

        if ($googleTenantId !== '') {
            $googleEventId = createGoogleCalendarEventForBooking($googleTenantId, $bookingForGoogle);

            if ($googleEventId) {
                google_calendar_update_booking_event_id($bookingId, $googleEventId);
                debug_log('BOOK_GOOGLE_EVENT_CREATED', $googleEventId);
            } else {
                debug_log('BOOK_GOOGLE_EVENT_SKIPPED', 'Brak eventu Google lub integracja nieaktywna');
            }
        } else {
            debug_log('BOOK_GOOGLE_EVENT_SKIPPED', 'Brak tenant_id w bookingPayload');
        }
    } else {
        debug_log('BOOK_GOOGLE_EVENT_SKIPPED', 'Brak booking_id');
    }
} catch (Throwable $e) {
    debug_log('BOOK_GOOGLE_EVENT_ERROR', $e->getMessage());
}

// Maile
$mailSentClient = false;
$mailSentAdmin = false;
$mailErrors = [];

try {
    $tenantQuery = 'tenant_id=eq.' . rawurlencode($TENANT_ID);

    $emailSettings = fetch_single_record(
        $SUPABASE_URL,
        $headers,
        'email_settings',
        $tenantQuery . '&is_active=eq.true'
    );

    $emailTemplate = fetch_single_record(
        $SUPABASE_URL,
        $headers,
        'email_templates',
        $tenantQuery . '&template_key=eq.booking_client_confirmation&is_enabled=eq.true'
    );

    $adminEmailTemplate = fetch_single_record(
        $SUPABASE_URL,
        $headers,
        'email_templates',
        $tenantQuery . '&template_key=eq.booking_admin_notification&is_enabled=eq.true'
    );

    $tenantData = fetch_single_record(
        $SUPABASE_URL,
        $headers,
        'tenant_branding',
        $tenantQuery
    );

    if (!$emailSettings || !$emailTemplate || !$adminEmailTemplate || !$tenantData) {
        throw new Exception('Brak konfiguracji email lub template dla tenant.');
    }

    $companyName = (string) ($tenantData['client_name'] ?? '');
    $plan = (string) ($tenantData['plan'] ?? 'basic');
    $footerMode = (string) ($tenantData['email_footer_mode'] ?? 'system');
    $footerCustom = (string) ($tenantData['email_footer_custom'] ?? '');
    $serviceName = (string) ($emailTemplate['service_name'] ?? 'wizyty');

    $placeholders = [
        '{name}'    => $name,
        '{date}'    => $date,
        '{time}'    => $time,
        '{email}'   => $email,
        '{phone}'   => $phone,
        '{message}' => $note,
    ];

    $finalSubject = replacePlaceholders((string) ($emailTemplate['subject'] ?? ''), $placeholders);
    $introHtml = replacePlaceholders((string) ($emailTemplate['body_html'] ?? ''), $placeholders);

    $adminFinalSubject = replacePlaceholders((string) ($adminEmailTemplate['subject'] ?? ''), $placeholders);
    $adminIntroHtml = replacePlaceholders((string) ($adminEmailTemplate['body_html'] ?? ''), $placeholders);

    $footerHtml = buildFooter($plan, $footerMode, $footerCustom);

    $clientHtml = buildClientEmailHtml(
        $introHtml,
        $companyName,
        $serviceName,
        $footerHtml,
        $name,
        $email,
        $date,
        $time,
        $note
    );

    $clientAltBody =
        "Rezerwacja potwierdzona\n\n" .
        "Dziękujemy za umówienie {$serviceName} z {$companyName}\n\n" .
        "Imię: {$name}\n" .
        "E-mail: {$email}\n" .
        "Data: {$date}\n" .
        "Godzina: {$time}\n" .
        ($note !== '' ? "Wiadomość: {$note}\n" : '') .
        "\n";

       if (!$paymentRequired && !empty($emailSettings['send_client_confirmation'])) {
        $mail = new PHPMailer(true);
        configureMailer($mail, $emailSettings);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $finalSubject !== '' ? $finalSubject : ('Potwierdzenie rezerwacji – ' . $date . ' ' . $time);
        $mail->Body = $clientHtml;
        $mail->AltBody = $clientAltBody;
        $mail->send();
        $mailSentClient = true;
    }

    if ($paymentRequired) {
        debug_log('BOOK_CLIENT_EMAIL_SKIPPED_PAYMENT_REQUIRED', [
            'booking_id' => $bookingId,
            'email' => $email,
            'payment_status' => $paymentStatus,
        ]);
    }

    if (!empty($emailSettings['send_admin_notification'])) {
        $adminNotifyEmail = trim((string) ($emailSettings['admin_notify_email'] ?? ''));

        if ($adminNotifyEmail !== '') {
            $adminHtml = buildAdminEmailHtml(
                $adminIntroHtml,
                $companyName,
                $footerHtml,
                $name,
                $email,
                $phone,
                $date,
                $time,
                $note
            );

            $adminAltBody =
                "Nowa rezerwacja\n\n" .
                "Imię: {$name}\n" .
                "E-mail: {$email}\n" .
                "Telefon: {$phone}\n" .
                "Data: {$date}\n" .
                "Godzina: {$time}\n" .
                ($note !== '' ? "Wiadomość klienta: {$note}\n" : '') .
                "\n";

            $adminMail = new PHPMailer(true);
            configureMailer($adminMail, $emailSettings);
            $adminMail->addAddress($adminNotifyEmail);
            $adminMail->isHTML(true);
            $adminMail->Subject = $adminFinalSubject !== '' ? $adminFinalSubject : ('Nowa rezerwacja – ' . $date . ' ' . $time);
            $adminMail->Body = $adminHtml;
            $adminMail->AltBody = $adminAltBody;
            $adminMail->send();
            $mailSentAdmin = true;
        }
    }
} catch (Exception $e) {
    $mailErrors[] = $e->getMessage();
    debug_log('BOOK_MAIL_ERROR', $e->getMessage());
}

// Finalna odpowiedź
json_response([
    'success' => true,
    'message' => 'Rezerwacja zapisana',

    'booking_id' => $bookingId,

    'payment_required_configured' => $paymentRequiredConfigured,
    'payment_provider_enabled' => $payuEnabled,
    'payment_required' => $paymentRequired,
    'payment_status' => $paymentStatus,
    'payment_provider' => $paymentProvider,
    'payment_amount' => $paymentAmount,
    'payment_currency' => $paymentCurrency,
    'payment_expires_at' => $paymentExpiresAt,

    'mail_sent_client' => $mailSentClient,
    'mail_sent_admin' => $mailSentAdmin,
    'mail_errors' => empty($mailErrors) ? null : $mailErrors,
], 200);