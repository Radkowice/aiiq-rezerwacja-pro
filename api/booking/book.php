<?php

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/plan_features.php';
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

function debug_log(string $label, $data): void
{
    @file_put_contents(
        '/var/www/data/debug.log',
        date('Y-m-d H:i:s') . " [{$label}] " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n",
        FILE_APPEND
    );
}

function booking_debug_log_service(array $data): void
{
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: '');
    $appEnv = strtolower((string) getenv('APP_ENV'));
    $isDebugEnvironment = stripos((string) $schema, '_beta') !== false;
    $isDebugEnvironment = $isDebugEnvironment
        || stripos($schema, 'dev') !== false
        || in_array($appEnv, ['dev', 'development', 'local'], true);

    if (!$isDebugEnvironment) {
        return;
    }

    try {
        $dir = '/var/www/logs';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (!is_dir($dir)) {
            error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: katalog logów nie istnieje po próbie utworzenia');
            return;
        }

        if (!is_writable($dir)) {
            error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: katalog logów nie jest zapisywalny');
            return;
        }

        $payload = array_merge([
            'timestamp' => date(DATE_ATOM),
        ], $data);

        $written = file_put_contents(
            $dir . '/rezerwacje-service-debug.log',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );

        if ($written === false) {
            error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: nie udało się zapisać wpisu logu');
        }
    } catch (Throwable $e) {
        error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: ' . $e->getMessage());
        // Debug techniczny nie może przerywać rezerwacji.
    }
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

function fetch_public_staff_for_booking(string $baseUrl, array $headers, string $tenantId, string $staffId): ?array
{
    if ($staffId === '') {
        return null;
    }

    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&is_active=eq.true'
        . '&select=id,display_name,service_name,service_duration_minutes,service_break_minutes,booking_buffer_minutes,service_price,payments_enabled,email_subject,email_heading,email_body';

    return fetch_single_record($baseUrl, $headers, 'staff_profiles', $query);
}

function fetch_public_service_for_booking(string $baseUrl, array $headers, string $tenantId, string $serviceId): ?array
{
    if ($serviceId === '') {
        return null;
    }

    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($serviceId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&select=id,name,description,duration_minutes,break_minutes,booking_buffer_minutes,price_amount,price_currency,payments_enabled';

    return fetch_single_record($baseUrl, $headers, 'tenant_services', $query);
}

function fetch_service_staff_ids_for_booking(string $baseUrl, array $headers, string $tenantId, string $serviceId): ?array
{
    if ($serviceId === '') {
        return [];
    }

    $query = 'select=staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=eq.' . rawurlencode($serviceId);

    $url = rtrim($baseUrl, '/') . '/rest/v1/tenant_service_staff?' . $query;
    $result = supabase_select($url, $headers);

    if (($result['httpCode'] ?? 0) !== 200 || !is_array($result['data'])) {
        return null;
    }

    $staffIds = [];

    foreach ($result['data'] as $row) {
        if (!is_array($row) || empty($row['staff_id'])) {
            continue;
        }

        $staffIds[(string) $row['staff_id']] = true;
    }

    return array_keys($staffIds);
}

function booking_nullable_int(array $row, string $key): ?int
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return (int) $row[$key];
}

function booking_nullable_float(array $row, string $key): ?float
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return is_numeric($row[$key]) ? (float) $row[$key] : null;
}

function booking_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
}

function booking_effective_min_notice_minutes(?int $serviceBufferMinutes, int $globalBookingBuffer): int
{
    if ($serviceBufferMinutes !== null && $serviceBufferMinutes > 0) {
        return max(0, $serviceBufferMinutes);
    }

    return max(0, $globalBookingBuffer);
}

function booking_slot_respects_buffer(string $date, string $time, int $bufferMinutes): bool
{
    $bufferMinutes = max(0, $bufferMinutes);

    if ($bufferMinutes <= 0) {
        return true;
    }

    $timezone = new DateTimeZone('Europe/Warsaw');
    $slotTime = substr($time, 0, 5);
    $slotDateTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $date . ' ' . $slotTime,
        $timezone
    );

    if (!$slotDateTime instanceof DateTimeImmutable || $slotDateTime->format('Y-m-d H:i') !== $date . ' ' . $slotTime) {
        return false;
    }

    $now = new DateTimeImmutable('now', $timezone);
    $minAllowedDateTime = $now->modify('+' . $bufferMinutes . ' minutes');

    return $slotDateTime >= $minAllowedDateTime;
}

function staff_slot_matches_schedule(
    string $baseUrl,
    array $headers,
    string $tenantId,
    string $staffId,
    string $date,
    string $time,
    int $duration,
    int $break
): bool {
    $weekday = (int) (new DateTimeImmutable($date))->format('N');
    $query = 'select=weekday,start_time,end_time,is_active'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&weekday=eq.' . rawurlencode((string) $weekday)
        . '&is_active=eq.true';

    $url = rtrim($baseUrl, '/') . '/rest/v1/staff_availability?' . $query;
    $result = supabase_select($url, $headers);

    if (($result['httpCode'] ?? 0) !== 200 || !is_array($result['data'])) {
        return false;
    }

    $slotMinutes = booking_time_to_minutes($time);
    $duration = max(1, $duration);
    $break = max(0, $break);

    foreach ($result['data'] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $start = substr((string)($row['start_time'] ?? ''), 0, 5);
        $end = substr((string)($row['end_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            continue;
        }

        $current = booking_time_to_minutes($start);
        $endMinutes = booking_time_to_minutes($end);

        while ($current + $duration <= $endMinutes) {
            if ($current === $slotMinutes) {
                return true;
            }

            $current += $duration + $break;
        }
    }

    return false;
}

function staff_slot_is_free(string $baseUrl, array $headers, string $tenantId, string $staffId, string $date, string $time): bool
{
    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&booking_date=eq.' . rawurlencode($date)
        . '&booking_time=eq.' . rawurlencode($time)
        . '&select=id';

    return fetch_single_record($baseUrl, $headers, 'bookings', $query) === null;
}

function booking_global_slot_is_available(string $baseUrl, array $headers, string $tenantId, string $date, string $time, string $staffId = ''): bool
{
    $staffBlockFilter = $staffId === ''
        ? '&staff_id=is.null'
        : '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

    $blockedDateQuery = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . $staffBlockFilter
        . '&select=date';

    if (fetch_single_record($baseUrl, $headers, 'blocked_dates', $blockedDateQuery) !== null) {
        return false;
    }

    $blockedTimesUrl = rtrim($baseUrl, '/') . '/rest/v1/blocked_times?select=time'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . $staffBlockFilter;

    $blockedTimesResult = supabase_select($blockedTimesUrl, $headers);

    if (($blockedTimesResult['httpCode'] ?? 0) !== 200 || !is_array($blockedTimesResult['data'])) {
        return true;
    }

    foreach ($blockedTimesResult['data'] as $row) {
        if (!is_array($row) || empty($row['time'])) {
            continue;
        }

        $blockedTime = substr((string) $row['time'], 0, 5);

        if ($blockedTime === 'all' || $blockedTime === $time) {
            return false;
        }
    }

    return true;
}

function booking_subscription_allows_staff(?string $planCode, ?string $status, ?string $currentPeriodEnd = null): bool
{
    $planValue = strtolower(trim((string) $planCode));
    $planValue = $planValue === 'biznes' ? 'business' : $planValue;
    $statusValue = strtolower(trim((string) $status));

    if (!in_array($planValue, ['pro', 'vip', 'business'], true)
        || !in_array($statusValue, ['active', 'trial'], true)) {
        return false;
    }

    $periodEndValue = trim((string) $currentPeriodEnd);

    if ($periodEndValue === '') {
        return true;
    }

    try {
        $periodEnd = (new DateTimeImmutable($periodEndValue, new DateTimeZone('Europe/Warsaw')))->setTime(0, 0, 0);
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw')))->setTime(0, 0, 0);

        return $periodEnd >= $today;
    } catch (Throwable $e) {
        return false;
    }
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

function generateBookingManageToken(): string
{
    return bin2hex(random_bytes(32));
}

function calculateBookingManageTokenExpiresAt(string $date, string $time): string
{
    $bookingStart = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $date . ' ' . $time,
        new DateTimeZone('Europe/Warsaw')
    );

    if (!$bookingStart instanceof DateTimeImmutable) {
        throw new RuntimeException('Nie udało się wyliczyć ważności tokenu rezerwacji.');
    }

    return $bookingStart->format(DateTimeInterface::ATOM);
}

function bookingPublicBaseUrl(): string
{
    $scheme = 'https';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0] ?? ''));

        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
        }
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? '';

    $host = trim(explode(',', (string) $host)[0] ?? '');

    if ($host === '' || !preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
        return '';
    }

    return $scheme . '://' . $host;
}

function bookingManageTokenIsActive(string $expiresAt): bool
{
    $expiresAt = trim($expiresAt);

    if ($expiresAt === '') {
        return false;
    }

    try {
        $expires = new DateTimeImmutable($expiresAt);
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

        return $expires > $now;
    } catch (Throwable $e) {
        return false;
    }
}

function bookingBuildRescheduleUrl(string $token, string $expiresAt): string
{
    $token = trim($token);

    if ($token === '' || !bookingManageTokenIsActive($expiresAt)) {
        return '';
    }

    $baseUrl = bookingPublicBaseUrl();

    if ($baseUrl === '') {
        return '';
    }

    return $baseUrl . '/przeloz-rezerwacje.html?token=' . rawurlencode($token);
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
    string $emailHeading,
    string $footerHtml,
    string $name,
    string $email,
    string $date,
    string $time,
    string $note,
    string $bookedServiceName = '',
    string $staffDisplayName = '',
    string $rescheduleUrl = ''
): string {
    $serviceRow = trim($bookedServiceName) !== ''
        ? '<p style="margin:0 0 12px 0;font-size:16px;"><strong>🛠️ Usługa:</strong> ' . htmlspecialchars($bookedServiceName, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    $staffRow = trim($staffDisplayName) !== ''
        ? '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👥 Osoba obsługująca:</strong> ' . htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    $rescheduleUrl = trim($rescheduleUrl);
    $rescheduleSection = $rescheduleUrl !== ''
        ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;text-align:center;">' .
            '<h2 style="margin:0 0 10px 0;font-size:20px;color:#17324d;">Chcesz zmienić termin?</h2>' .
            '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#4f6478;">Jeśli ten termin Ci nie pasuje, możesz przełożyć rezerwację na inny dostępny termin.</p>' .
            '<a href="' . htmlspecialchars($rescheduleUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 20px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">Przełóż rezerwację</a>' .
            '<p style="margin:14px 0 0 0;font-size:13px;line-height:1.5;color:#607284;">Link jest ważny do momentu rozpoczęcia rezerwacji.</p>' .
          '</div>'
        : '';

    return
        '<div style="margin:0;padding:0;background:#f4f7fb;">' .
            '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">' .

                '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">' .
                    '<div style="font-size:42px;line-height:1;margin-bottom:12px;">✓</div>' .
                    '<h1 style="margin:0;font-size:28px;">Rezerwacja potwierdzona</h1>' .
                    '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($emailHeading, ENT_QUOTES, 'UTF-8') . ' | ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' .
                '</div>' .

                '<div style="padding:32px 24px;">' .
                    $introHtml .

                    '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>' .
                        $serviceRow .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📅 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>' .
                        $staffRow .
                        '<p style="margin:0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>' .
                    '</div>' .

                    (
                        trim($note) !== ''
                            ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                                '<p style="margin:0;font-size:16px;"><strong>💬 Twoja wiadomość:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' .
                              '</div>'
                            : ''
                    ) .

                    $rescheduleSection .

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
    string $note,
    string $staffDisplayName = ''
): string {
    $staffRow = trim($staffDisplayName) !== ''
        ? '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👥 Personel:</strong> ' . htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

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
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📅 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>' .
                        $staffRow .
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
    $mail->Encoding = 'base64';

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
$staffId = trim((string) ($input['staff_id'] ?? ''));
$serviceId = trim((string) ($input['service_id'] ?? ''));

$website = trim((string) ($input['website'] ?? ''));
$formStartedAtRaw = trim((string) ($input['form_started_at'] ?? ''));
$formFillTimeRaw = trim((string) ($input['form_fill_time_ms'] ?? ''));
$termsAcceptedRaw = $input['terms_accepted'] ?? null;

// Cicha pułapka na boty — człowiek tego pola nie widzi.
if ($website !== '') {
    debug_log('BOOK_BOT_HONEYPOT_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'honeypot_triggered' => true,
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
$hasClientFillTime = ctype_digit($formFillTimeRaw);

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

$formFillTimeMs = $hasClientFillTime
    ? (int) $formFillTimeRaw
    : $formSubmittedAt - $formStartedAt;

$formFillTimeSource = $hasClientFillTime
    ? 'form_fill_time_ms'
    : 'server_minus_client_started_at';

if ($formFillTimeMs < 3000) {
    debug_log('BOOK_BOT_TOO_FAST_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'form_fill_time_ms' => $formFillTimeMs,
        'source' => $formFillTimeSource,
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
    . '?select=calendar_enabled,consultation_duration,consultation_break,booking_buffer'
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

if (mb_strlen($name) > 120) {
    json_response([
        'success' => false,
        'error' => 'Imię i nazwisko jest za długie.'
    ], 400);
}

if ($email !== '' && mb_strlen($email) > 190) {
    json_response([
        'success' => false,
        'error' => 'Adres e-mail jest za długi.'
    ], 400);
}

if (mb_strlen($note) > 1000) {
    json_response([
        'success' => false,
        'error' => 'Wiadomość jest za długa.'
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

$staffDisplayName = '';
$staffServiceName = '';
$staffServicePrice = null;
$staffPaymentsEnabled = null;
$staffEmailSubject = '';
$staffEmailHeading = '';
$staffEmailBody = '';
$globalBookingBuffer = max(0, (int) ($calendarSettingsRow['booking_buffer'] ?? 0));
$selectedService = null;
$selectedServiceStaffIds = [];

if ($serviceId !== '') {
    if (!tenant_has_feature($TENANT_ID, 'multiple_services')) {
        json_response([
            'success' => false,
            'error' => 'Wiele usług jest dostępne w wersji Pro.',
            'feature' => 'multiple_services',
            'upgrade_required' => true,
        ], 403);
    }

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $serviceId)) {
        json_response([
            'success' => false,
            'error' => 'Wybrana usługa jest niedostępna.',
        ], 404);
    }

    $selectedService = fetch_public_service_for_booking($SUPABASE_URL, $headers, $TENANT_ID, $serviceId);

    if (!is_array($selectedService) || empty($selectedService['id'])) {
        json_response([
            'success' => false,
            'error' => 'Wybrana usługa jest niedostępna.',
        ], 404);
    }

    $selectedServiceStaffIds = fetch_service_staff_ids_for_booking($SUPABASE_URL, $headers, $TENANT_ID, $serviceId);

    if (!is_array($selectedServiceStaffIds)) {
        json_response([
            'success' => false,
            'error' => 'Nie udało się sprawdzić personelu dla wybranej usługi.',
        ], 500);
    }

    if (!empty($selectedServiceStaffIds)) {
        if ($staffId === '') {
            json_response([
                'success' => false,
                'error' => 'Wybierz osobę obsługującą tę usługę.',
            ], 400);
        }

        if (!in_array($staffId, $selectedServiceStaffIds, true)) {
            json_response([
                'success' => false,
                'error' => 'Wybrana osoba nie obsługuje tej usługi.',
            ], 422);
        }
    } elseif ($staffId !== '') {
        json_response([
            'success' => false,
            'error' => 'Ta usługa nie wymaga wyboru personelu.',
        ], 422);
    }
}

if ($staffId !== '') {
    if (!tenant_has_feature($TENANT_ID, 'staff_module')) {
        json_response([
            'success' => false,
            'error' => 'Rezerwacja do pracownika jest dostępna w wersji Pro.',
            'feature' => 'staff_module',
            'upgrade_required' => true,
        ], 403);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $staffId)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy personel',
        ], 400);
    }

    $subscriptionRow = fetch_single_record(
        $SUPABASE_URL,
        $headers,
        'tenant_subscriptions',
        'tenant_id=eq.' . rawurlencode($TENANT_ID) . '&select=plan_code,status,current_period_end'
    );

    $planCode = is_array($subscriptionRow) ? (string) ($subscriptionRow['plan_code'] ?? 'free') : 'free';
    $subscriptionStatus = is_array($subscriptionRow) ? (string) ($subscriptionRow['status'] ?? '') : '';
    $currentPeriodEnd = is_array($subscriptionRow) ? (string) ($subscriptionRow['current_period_end'] ?? '') : '';

    if (!booking_subscription_allows_staff($planCode, $subscriptionStatus, $currentPeriodEnd)) {
        json_response([
            'success' => false,
            'error' => 'Personel jest niedostępny',
        ], 403);
    }

    $staffRow = fetch_public_staff_for_booking($SUPABASE_URL, $headers, $TENANT_ID, $staffId);

    if (!is_array($staffRow) || empty($staffRow['id'])) {
        json_response([
            'success' => false,
            'error' => 'Wybrana osoba jest niedostępna',
        ], 404);
    }

    $staffDisplayName = trim((string) ($staffRow['display_name'] ?? ''));
    $staffServiceName = trim((string) ($staffRow['service_name'] ?? ''));
    $staffEmailSubject = trim((string) ($staffRow['email_subject'] ?? ''));
    $staffEmailHeading = trim((string) ($staffRow['email_heading'] ?? ''));
    $staffEmailBody = trim((string) ($staffRow['email_body'] ?? ''));
    $staffServicePrice = booking_nullable_float($staffRow, 'service_price');
    $staffPaymentsEnabled = array_key_exists('payments_enabled', $staffRow) && $staffRow['payments_enabled'] !== null
        ? filter_var($staffRow['payments_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        : null;

    $staffDuration = booking_nullable_int($staffRow, 'service_duration_minutes');
    $staffBreak = booking_nullable_int($staffRow, 'service_break_minutes');
    $serviceDuration = is_array($selectedService) ? booking_nullable_int($selectedService, 'duration_minutes') : null;
    $serviceBreak = is_array($selectedService) ? booking_nullable_int($selectedService, 'break_minutes') : null;
    $serviceBuffer = is_array($selectedService) ? booking_nullable_int($selectedService, 'booking_buffer_minutes') : null;

    $effectiveDuration = max(1, $serviceDuration ?? $staffDuration ?? (int)($calendarSettingsRow['consultation_duration'] ?? 60));
    $effectiveBreak = max(0, $serviceBreak ?? $staffBreak ?? (int)($calendarSettingsRow['consultation_break'] ?? 0));
    $effectiveBuffer = booking_effective_min_notice_minutes($serviceBuffer, $globalBookingBuffer);

    if (!booking_slot_respects_buffer($date, $time, $effectiveBuffer)) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest już niedostępna',
        ], 409);
    }

    if (!booking_global_slot_is_available($SUPABASE_URL, $headers, $TENANT_ID, $date, $time, $staffId)) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest już niedostępna',
        ], 409);
    }

    $staffSlotMatchesSchedule = staff_slot_matches_schedule(
        $SUPABASE_URL,
        $headers,
        $TENANT_ID,
        $staffId,
        $date,
        $time,
        $effectiveDuration,
        $effectiveBreak
    );

    if (!$staffSlotMatchesSchedule) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest niedostępna dla tej osoby',
        ], 409);
    }

    if (!staff_slot_is_free($SUPABASE_URL, $headers, $TENANT_ID, $staffId, $date, $time)) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest już zajęta',
        ], 409);
    }
} elseif (!booking_slot_respects_buffer(
    $date,
    $time,
    booking_effective_min_notice_minutes(
        is_array($selectedService) ? booking_nullable_int($selectedService, 'booking_buffer_minutes') : null,
        $globalBookingBuffer
    )
)) {
    json_response([
        'success' => false,
        'error' => 'Wybrana godzina jest już niedostępna',
    ], 409);
} elseif (!booking_global_slot_is_available($SUPABASE_URL, $headers, $TENANT_ID, $date, $time)) {
    json_response([
        'success' => false,
        'error' => 'Wybrana godzina jest już niedostępna',
    ], 409);
}

$googleEventDurationMinutes = max(1, (int) (
    (is_array($selectedService) ? booking_nullable_int($selectedService, 'duration_minutes') : null)
    ?? ($effectiveDuration ?? null)
    ?? (int) ($calendarSettingsRow['consultation_duration'] ?? 60)
));

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
$globalServiceName = '';
$configuredAmount = 0.0;
$configuredCurrency = 'PLN';
$paymentLimitValue = 48;
$paymentLimitUnit = 'hours';
$globalPaymentRequiredConfigured = false;

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
    $globalServiceName = trim((string) ($serviceSettings['service_name'] ?? ''));
    $globalPaymentRequiredConfigured = !empty($serviceSettings['payment_required']);
    $paymentRequiredConfigured = $globalPaymentRequiredConfigured;
    $configuredAmount = isset($serviceSettings['price_amount'])
        ? (float) $serviceSettings['price_amount']
        : 0.0;

    $configuredCurrency = trim((string) ($serviceSettings['price_currency'] ?? 'PLN'));

    if ($configuredCurrency === '') {
        $configuredCurrency = 'PLN';
    }

    $paymentLimitValue = (int) ($serviceSettings['payment_time_limit_value'] ?? 48);
    $paymentLimitUnit = (string) ($serviceSettings['payment_time_limit_unit'] ?? 'hours');
}

if (is_array($selectedService)) {
    $globalServiceName = trim((string) ($selectedService['name'] ?? ''));
    $paymentRequiredConfigured = $globalPaymentRequiredConfigured && !empty($selectedService['payments_enabled']);
    $configuredAmount = booking_nullable_float($selectedService, 'price_amount') ?? 0.0;
    $configuredCurrency = trim((string) ($selectedService['price_currency'] ?? 'PLN'));

    if ($configuredCurrency === '') {
        $configuredCurrency = 'PLN';
    }
}

$effectivePaymentRequiredConfigured = $paymentRequiredConfigured;
$effectivePaymentAmount = $configuredAmount;
$effectivePaymentCurrency = $configuredCurrency;

if ($staffId !== '' && !is_array($selectedService)) {
    if ($staffPaymentsEnabled === true) {
        $effectivePaymentRequiredConfigured = $globalPaymentRequiredConfigured;
    } elseif ($staffPaymentsEnabled === false) {
        $effectivePaymentRequiredConfigured = false;
    }

    if ($staffServicePrice !== null && $staffServicePrice > 0) {
        $effectivePaymentAmount = $staffServicePrice;
    }
}

if (!tenant_has_feature($TENANT_ID, 'online_payments') || !tenant_has_feature($TENANT_ID, 'payu')) {
    $effectivePaymentRequiredConfigured = false;
    $payuEnabled = false;
}

if ($effectivePaymentRequiredConfigured && $effectivePaymentAmount <= 0) {
    json_response([
        'success' => false,
        'error' => 'Brak poprawnej kwoty płatności dla wybranej usługi.',
    ], 422);
}

$paymentRequiredConfigured = $effectivePaymentRequiredConfigured;
$paymentRequired = $paymentRequiredConfigured && $payuEnabled;

if ($paymentRequired) {
    $paymentStatus = 'pending';
    $paymentProvider = 'payu';
    $paymentAmount = $effectivePaymentAmount > 0 ? $effectivePaymentAmount : null;
    $paymentCurrency = $effectivePaymentCurrency;
    $paymentExpiresAt = calculate_payment_expires_at($paymentLimitValue, $paymentLimitUnit);
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

$serviceNameSnapshot = $globalServiceName;

if ($staffId !== '' && $staffServiceName !== '' && !is_array($selectedService)) {
    $serviceNameSnapshot = $staffServiceName;
}

$manageToken = '';
$manageTokenExpiresAt = '';

try {
    $manageToken = generateBookingManageToken();
    $manageTokenExpiresAt = calculateBookingManageTokenExpiresAt($date, $time);
} catch (Throwable $e) {
    debug_log('BOOK_MANAGE_TOKEN_ERROR', [
        'exception_type' => get_class($e),
        'tenant_id' => $TENANT_ID,
        'booking_date' => $date,
        'booking_time' => $time,
    ]);

    json_response([
        'success' => false,
        'error' => 'Nie udało się przygotować rezerwacji. Spróbuj ponownie.',
    ], 500);
}

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
    'service_name_snapshot' => $serviceNameSnapshot !== '' ? $serviceNameSnapshot : null,

    'payment_required'   => $paymentRequired,
    'payment_status'     => $paymentStatus,
    'payment_provider'   => $paymentProvider,
    'payment_amount'     => $paymentAmount,
    'payment_currency'   => $paymentCurrency,
    'payment_expires_at' => $paymentExpiresAt,

    'manage_token' => $manageToken,
    'manage_token_expires_at' => $manageTokenExpiresAt,

    'created_at'   => date('c'),
    'updated_at'   => date('c'),
];

if ($staffId !== '') {
    $bookingPayload['staff_id'] = $staffId;
}

if ($serviceId !== '' && is_array($selectedService)) {
    $bookingPayload['service_id'] = $serviceId;
}

booking_debug_log_service([
    'event' => 'before_booking_insert',
    'tenant_id' => $TENANT_ID,
    'received_service_id' => $serviceId,
    'received_staff_id' => $staffId,
    'selected_service_found' => is_array($selectedService),
    'booking_payload_has_service_id' => array_key_exists('service_id', $bookingPayload),
    'booking_payload_service_id' => $bookingPayload['service_id'] ?? null,
    'service_name_snapshot' => $bookingPayload['service_name_snapshot'] ?? null,
    'booking_date' => $date,
    'booking_time' => $time,
    'payment_required' => $paymentRequired,
    'status' => $bookingPayload['status'] ?? null,
]);

$bookingResult = supabase_insert(
    $SUPABASE_URL . '/rest/v1/bookings',
    $bookingPayload,
    $headers
);

$bookingRowsForDebug = json_decode((string)($bookingResult['response'] ?? ''), true);
$bookingIdForDebug = is_array($bookingRowsForDebug) && isset($bookingRowsForDebug[0]) && is_array($bookingRowsForDebug[0])
    ? (string)($bookingRowsForDebug[0]['id'] ?? '')
    : '';

booking_debug_log_service([
    'event' => 'after_booking_insert',
    'tenant_id' => $TENANT_ID,
    'received_service_id' => $serviceId,
    'received_staff_id' => $staffId,
    'selected_service_found' => is_array($selectedService),
    'booking_payload_has_service_id' => array_key_exists('service_id', $bookingPayload),
    'booking_payload_service_id' => $bookingPayload['service_id'] ?? null,
    'service_name_snapshot' => $bookingPayload['service_name_snapshot'] ?? null,
    'booking_date' => $date,
    'booking_time' => $time,
    'payment_required' => $paymentRequired,
    'status' => $bookingPayload['status'] ?? null,
    'insert_success' => !$bookingResult['error'] && $bookingResult['httpCode'] < 400,
    'insert_error' => $bookingResult['error'] ? substr((string) $bookingResult['error'], 0, 180) : null,
    'booking_id' => $bookingIdForDebug !== '' ? $bookingIdForDebug : null,
]);

debug_log('BOOK_BOOKINGS_RESPONSE', [
    'httpCode' => $bookingResult['httpCode'],
    'has_error' => $bookingResult['error'] !== '',
    'tenant_id' => $TENANT_ID,
]);

if ($bookingResult['error'] || $bookingResult['httpCode'] >= 400) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się zapisać rezerwacji. Spróbuj ponownie.',
    ], 500);
}

$bookingRows = json_decode((string)($bookingResult['response'] ?? ''), true);
$createdBooking = is_array($bookingRows) && isset($bookingRows[0]) && is_array($bookingRows[0])
    ? $bookingRows[0]
    : [];

$bookingId = (string)($createdBooking['id'] ?? '');

debug_log('BOOK_CREATED_ID', $bookingId !== '' ? $bookingId : 'BRAK_ID');

// Blokada terminu dla starego trybu globalnego.
// Rezerwacje personelu blokujemy przez bookings.staff_id, bez założenia kolumny staff_id w blocked_times.
if ($staffId === '') {
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

    debug_log('BOOK_BLOCKED_TIMES_RESPONSE', [
        'httpCode' => $blockResult['httpCode'],
        'has_error' => $blockResult['error'] !== '',
        'booking_id' => $bookingId,
        'tenant_id' => $TENANT_ID,
        'date' => $date,
        'time' => $time,
    ]);

    if ($blockResult['error'] || $blockResult['httpCode'] >= 400) {
        json_response([
            'success' => false,
            'error' => 'Nie udało się zablokować terminu. Spróbuj ponownie.',
        ], 500);
    }
}

// Google Calendar — tworzenie wydarzenia po zapisaniu rezerwacji
try {
    if ($bookingId !== '') {
        $bookingForGooglePayload = $bookingPayload;
        unset($bookingForGooglePayload['staff_id']);
        unset($bookingForGooglePayload['manage_token'], $bookingForGooglePayload['manage_token_expires_at']);

        $bookingForGoogle = array_merge($bookingForGooglePayload, [
            'id' => $bookingId,
            'staff_display_name' => $staffDisplayName,
            'duration_minutes' => $googleEventDurationMinutes,
        ]);

        $googleTenantId = (string)($bookingPayload['tenant_id'] ?? '');

        if ($googleTenantId !== '') {
            $googleEventId = createGoogleCalendarEventForBooking($googleTenantId, $bookingForGoogle);

            if ($googleEventId) {
                google_calendar_update_booking_event_id($bookingId, $googleEventId, $googleTenantId);
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
    debug_log('BOOK_GOOGLE_EVENT_ERROR', [
        'exception_type' => get_class($e),
        'booking_id' => $bookingId,
        'tenant_id' => $TENANT_ID,
    ]);
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

    $tenantData = fetch_single_record(
        $SUPABASE_URL,
        $headers,
        'tenant_branding',
        $tenantQuery
    );

    if (!$emailSettings || !$emailTemplate || !$tenantData) {
        throw new Exception('Brak konfiguracji email lub template klienta dla tenant.');
    }

    $companyName = (string) ($tenantData['client_name'] ?? '');
    $plan = 'basic';
    $footerMode = (string) ($tenantData['email_footer_mode'] ?? 'system');
    $footerCustom = (string) ($tenantData['email_footer_custom'] ?? '');
    $effectiveEmailTemplate = $emailTemplate;

    if ($staffEmailSubject !== '') {
        $effectiveEmailTemplate['subject'] = $staffEmailSubject;
    }

    if ($staffEmailHeading !== '') {
        $effectiveEmailTemplate['service_name'] = $staffEmailHeading;
    }

    if ($staffEmailBody !== '') {
        $effectiveEmailTemplate['body_html'] = $staffEmailBody;
    }

    $emailHeading = trim((string) ($effectiveEmailTemplate['service_name'] ?? ''));

    if ($emailHeading === '') {
        $emailHeading = 'Dziękujemy za rezerwację';
    }

    $bookedServiceName = trim((string) ($serviceNameSnapshot ?? ''));

    $placeholders = [
        '{name}'    => $name,
        '{date}'    => $date,
        '{time}'    => $time,
        '{email}'   => $email,
        '{phone}'   => $phone,
        '{message}' => $note,
    ];

    $finalSubject = replacePlaceholders((string) ($effectiveEmailTemplate['subject'] ?? ''), $placeholders);
    $introHtml = replacePlaceholders((string) ($effectiveEmailTemplate['body_html'] ?? ''), $placeholders);

    $adminFinalSubject = 'Nowa rezerwacja – ' . $date . ' ' . $time;
    $adminIntroHtml =
        '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">'
        . 'W systemie pojawiła się nowa rezerwacja. Szczegóły rezerwacji znajdują się poniżej.'
        . '</p>';

    $footerHtml = buildFooter($plan, $footerMode, $footerCustom);
    $rescheduleUrl = '';

    if (tenant_has_feature($TENANT_ID, 'reschedule_booking')) {
        $rescheduleUrl = bookingBuildRescheduleUrl($manageToken, $manageTokenExpiresAt);
    }

    if (!$paymentRequired && !empty($emailSettings['send_client_confirmation'])) {
        $clientHtml = buildClientEmailHtml(
            $introHtml,
            $companyName,
            $emailHeading,
            $footerHtml,
            $name,
            $email,
            $date,
            $time,
            $note,
            $bookedServiceName,
            $staffDisplayName,
            $rescheduleUrl
        );

        $clientAltBody =
            "Rezerwacja potwierdzona\n\n" .
            "{$emailHeading} | {$companyName}\n\n" .
            "Imię: {$name}\n" .
            "E-mail: {$email}\n" .
            ($bookedServiceName !== '' ? "Usługa: {$bookedServiceName}\n" : '') .
            "Data: {$date}\n" .
            ($staffDisplayName !== '' ? "Osoba obsługująca: {$staffDisplayName}\n" : '') .
            "Godzina: {$time}\n" .
            ($note !== '' ? "Wiadomość: {$note}\n" : '') .
            ($rescheduleUrl !== ''
                ? "\nChcesz zmienić termin?\nJeśli ten termin Ci nie pasuje, możesz przełożyć rezerwację na inny dostępny termin.\nPrzełóż rezerwację: {$rescheduleUrl}\nLink jest ważny do momentu rozpoczęcia rezerwacji.\n"
                : '') .
            "\n";

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
            'payment_required' => true,
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
                $note,
                $staffDisplayName
            );

            $adminAltBody =
                "Nowa rezerwacja\n\n" .
                "Imię: {$name}\n" .
                "E-mail: {$email}\n" .
                "Telefon: {$phone}\n" .
                "Data: {$date}\n" .
                ($staffDisplayName !== '' ? "Personel: {$staffDisplayName}\n" : '') .
                "Godzina: {$time}\n" .
                ($note !== '' ? "Wiadomość klienta: {$note}\n" : '') .
                "\n";

            $adminMail = new PHPMailer(true);
            configureMailer($adminMail, $emailSettings);
            $adminMail->addAddress($adminNotifyEmail);
            $adminMail->isHTML(true);
            $adminMail->Subject = $adminFinalSubject;
            $adminMail->Body = $adminHtml;
            $adminMail->AltBody = $adminAltBody;
            $adminMail->send();

            $mailSentAdmin = true;
        }
    }
} catch (Exception $e) {
    $mailErrors[] = $e->getMessage();
    debug_log('BOOK_MAIL_ERROR', [
        'exception_type' => get_class($e),
        'booking_id' => $bookingId,
        'tenant_id' => $TENANT_ID,
    ]);
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
], 200);
