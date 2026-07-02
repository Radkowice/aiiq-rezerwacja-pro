<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/php_mail.php';

function cron_payments_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cron_payments_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string)$value);
    return $value !== '' ? $value : $default;
}

function cron_payments_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function cron_payments_request_secret(): string
{
    $headerSecret = trim((string)($_SERVER['HTTP_X_CRON_SECRET'] ?? ''));

    if ($headerSecret !== '') {
        return $headerSecret;
    }

    return trim((string)($_GET['secret'] ?? $_POST['secret'] ?? ''));
}

function cron_payments_expected_secret(): string
{
    $secret = cron_payments_env('CHECK_PENDING_PAYMENTS_CRON_SECRET');

    if ($secret !== '') {
        return $secret;
    }

    $secret = cron_payments_env('PAYMENTS_CRON_SECRET');

    if ($secret !== '') {
        return $secret;
    }

    return cron_payments_env('CRON_SECRET');
}

function cron_payments_require_authorization(): void
{
    if (cron_payments_is_cli()) {
        return;
    }

    $expectedSecret = cron_payments_expected_secret();

    if ($expectedSecret === '') {
        cron_payments_response([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }

    $providedSecret = cron_payments_request_secret();

    if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        cron_payments_response([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }
}

function cron_payments_trace(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    return substr(hash('sha256', $value), 0, 16);
}

function cron_payments_payload_keys(array $payload): array
{
    $keys = array_keys($payload);
    sort($keys);
    return $keys;
}

function cron_payments_email_domain(string $email): string
{
    $email = trim($email);

    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }

    return strtolower((string)substr(strrchr($email, '@'), 1));
}

function cron_payments_safe_booking_for_email(array $booking): array
{
    $allowedKeys = [
        'name',
        'email',
        'phone',
        'booking_date',
        'booking_time',
        'payment_amount',
        'payment_currency',
        'payment_expires_at',
        'payment_url',
    ];

    $safeBooking = [];

    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $booking)) {
            $safeBooking[$key] = $booking[$key];
        }
    }

    return $safeBooking;
}

function cron_payments_get_supabase_config(): array
{
    $supabaseUrl = rtrim(cron_payments_env('SUPABASE_URL'), '/');
    $supabaseKey = cron_payments_env('SUPABASE_SERVICE_ROLE_KEY');
    $schema = cron_payments_env('SUPABASE_DB_SCHEMA', 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        throw new RuntimeException('Brak konfiguracji Supabase.');
    }

    return [$supabaseUrl, $supabaseKey, $schema];
}

function cron_payments_fetch_records(string $query): array
{
    [$supabaseUrl, $supabaseKey, $schema] = cron_payments_get_supabase_config();

    $url = $supabaseUrl . '/rest/v1/bookings?' . $query;

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('CRON_PAYMENTS_FETCH_ERROR', [
            'query_trace' => cron_payments_trace($query),
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null && $result['error'] !== '',
            'has_response' => $result['response'] !== null && $result['response'] !== '',
        ]);

        throw new RuntimeException('Nie udało się pobrać rezerwacji do obsługi płatności.');
    }

    return is_array($result['data']) ? $result['data'] : [];
}

function cron_payments_update_booking(string $bookingId, array $payload): bool
{
    [$supabaseUrl, $supabaseKey, $schema] = cron_payments_get_supabase_config();

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?id=eq.' . rawurlencode($bookingId);

    $result = payu_supabase_request(
        $url,
        'PATCH',
        $supabaseKey,
        $schema,
        $payload,
        ['Prefer: return=representation']
    );

    if ($result['error'] || $result['http_code'] < 200 || $result['http_code'] >= 300) {
        payu_debug('CRON_PAYMENTS_UPDATE_ERROR', [
            'booking_id_set' => $bookingId !== '',
            'booking_trace' => cron_payments_trace($bookingId),
            'payload_keys' => cron_payments_payload_keys($payload),
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null && $result['error'] !== '',
            'has_response' => $result['response'] !== null && $result['response'] !== '',
        ]);

        return false;
    }

    return true;
}

function cron_payments_fetch_admin_email(string $tenantId): string
{
    [$supabaseUrl, $supabaseKey, $schema] = cron_payments_get_supabase_config();

    $url = $supabaseUrl
        . '/rest/v1/users'
        . '?select=email'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&role=in.(admin,administrator)'
        . '&is_active=eq.true'
        . '&limit=1';

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('CRON_PAYMENTS_ADMIN_EMAIL_FETCH_ERROR', [
            'tenant_id_set' => $tenantId !== '',
            'tenant_trace' => cron_payments_trace($tenantId),
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null && $result['error'] !== '',
            'has_response' => $result['response'] !== null && $result['response'] !== '',
        ]);

        return '';
    }

    return trim((string)($result['data'][0]['email'] ?? ''));
}

function cron_payments_format_datetime(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Europe/Warsaw'))
            ->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function cron_payments_format_money($amount, string $currency): string
{
    if ($amount === null || $amount === '') {
        return '';
    }

    $displayCurrency = strtoupper(trim($currency)) === 'PLN' ? 'zł' : trim($currency);

    if ($displayCurrency === '') {
        $displayCurrency = 'zł';
    }

    return number_format((float)$amount, 2, ',', ' ') . ' ' . $displayCurrency;
}

function cron_payments_booking_summary_html(array $booking): string
{
    $name = htmlspecialchars((string)($booking['name'] ?? '—'), ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars((string)($booking['email'] ?? '—'), ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars((string)($booking['phone'] ?? '—'), ENT_QUOTES, 'UTF-8');
    $date = htmlspecialchars((string)($booking['booking_date'] ?? '—'), ENT_QUOTES, 'UTF-8');
    $time = htmlspecialchars((string)($booking['booking_time'] ?? '—'), ENT_QUOTES, 'UTF-8');
    $amount = htmlspecialchars(
        cron_payments_format_money($booking['payment_amount'] ?? null, (string)($booking['payment_currency'] ?? 'PLN')),
        ENT_QUOTES,
        'UTF-8'
    );
    $expires = htmlspecialchars(cron_payments_format_datetime($booking['payment_expires_at'] ?? ''), ENT_QUOTES, 'UTF-8');
    $row = static function (string $icon, string $label, string $value): string {
        return '<tr><td style="padding:7px 0;color:#6b7280;"><span style="display:inline-block;width:24px;" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>' . $label . ':</td><td style="padding:7px 0;text-align:right;"><strong>' . $value . '</strong></td></tr>';
    };

    return ''
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">'
        . $row('👤', 'Klient', $name)
        . $row('✉️', 'Email', $email)
        . $row('📞', 'Telefon', $phone)
        . $row('📅', 'Data', $date)
        . $row('🕒', 'Godzina', $time)
        . $row('💰', 'Kwota', $amount)
        . $row('⏰', 'Termin płatności', $expires)
        . '</table>';
}

function cron_payments_send_reminder_email(array $booking): bool
{
    $email = trim((string)($booking['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        payu_debug('CRON_PAYMENTS_REMINDER_EMAIL_SKIPPED', [
            'email_domain' => cron_payments_email_domain($email),
            'email_valid' => false,
        ]);

        return false;
    }

    $paymentUrl = htmlspecialchars((string)($booking['payment_url'] ?? ''), ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>Przypomnienie o płatności za rezerwację.</strong></p>'
        . '<p style="margin:0 0 14px;">Nie odnotowaliśmy jeszcze potwierdzenia płatności z PayU.</p>'
        . cron_payments_booking_summary_html($booking);

    if ($paymentUrl !== '') {
        $message .= ''
            . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">'
            . 'Jeżeli płatność została już wykonana, nie musisz nic robić — status zostanie zaktualizowany po potwierdzeniu przez PayU.'
            . '</p>'
            . '<p style="margin:12px 0 0;color:#374151;line-height:1.6;">'
            . 'Jeżeli płatność została przerwana lub jeszcze jej nie wykonałeś, możesz wrócić do płatności poniższym przyciskiem.'
            . '</p>'
            . '<div style="margin-top:22px;text-align:center;">'
            . '<a href="' . $paymentUrl . '" style="display:inline-block;padding:13px 22px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">Przejdź do płatności</a>'
            . '</div>';
    }

    $html = buildSystemMailLayout(
        'Przypomnienie o płatności',
        'Rezerwacja nadal oczekuje na potwierdzenie płatności.',
        $message,
        'Jeżeli płatność została wykonana chwilę temu, PayU może potrzebować czasu na przesłanie potwierdzenia.'
    );

    return sendSystemMail($email, 'Przypomnienie o płatności za rezerwację', $html);
}

function cron_payments_send_expired_customer_email(array $booking): bool
{
    $email = trim((string)($booking['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        payu_debug('CRON_PAYMENTS_EXPIRED_CUSTOMER_EMAIL_SKIPPED', [
            'email_domain' => cron_payments_email_domain($email),
            'email_valid' => false,
        ]);

        return false;
    }

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>Płatność za rezerwację nie została odnotowana w wyznaczonym czasie.</strong></p>'
        . '<p style="margin:0 0 14px;">Rezerwacja została oznaczona w systemie jako nieopłacona.</p>'
        . cron_payments_booking_summary_html($booking)
        . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">'
        . 'Administrator otrzymał informację o braku płatności i podejmie decyzję, co dalej z rezerwacją.'
        . '</p>';

    $html = buildSystemMailLayout(
        'Płatność nie została odnotowana',
        'Rezerwacja wymaga decyzji administratora.',
        $message,
        'Wiadomość została wysłana automatycznie po przekroczeniu czasu płatności.'
    );

    return sendSystemMail($email, 'Płatność za rezerwację nie została odnotowana', $html);
}

function cron_payments_send_expired_admin_email(array $booking, string $adminEmail): bool
{
    if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        payu_debug('CRON_PAYMENTS_EXPIRED_ADMIN_EMAIL_SKIPPED', [
            'admin_email_domain' => cron_payments_email_domain($adminEmail),
            'admin_email_valid' => false,
        ]);

        return false;
    }

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>Rezerwacja nie została opłacona w wyznaczonym czasie.</strong></p>'
        . '<p style="margin:0 0 14px;">Termin pozostaje zablokowany do Twojej decyzji.</p>'
        . cron_payments_booking_summary_html($booking)
        . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">'
        . 'W panelu administracyjnym rezerwacja powinna być oznaczona jako <strong>NIE OPŁACONO</strong>. '
        . 'Możesz ją usunąć, aby zwolnić termin w kalendarzu.'
        . '</p>';

    $html = buildSystemMailLayout(
        'Rezerwacja nieopłacona',
        'System oznaczył rezerwację jako nieopłaconą.',
        $message,
        'System nie usuwa tej rezerwacji automatycznie. Decyzja należy do administratora.'
    );

    return sendSystemMail($adminEmail, 'Rezerwacja nieopłacona — wymagana decyzja', $html);
}

function cron_payments_process_reminders(DateTimeImmutable $now): array
{
    $threshold = $now->modify('-30 minutes')->format(DATE_ATOM);

    $query = http_build_query([
        'select' => 'id,tenant_id,name,email,phone,booking_date,booking_time,payment_amount,payment_currency,payment_expires_at,payment_url,payment_started_at,payment_reminder_sent_at',
        'payment_required' => 'eq.true',
        'payment_status' => 'eq.pending',
        'payment_reminder_sent_at' => 'is.null',
        'payment_started_at' => 'lte.' . $threshold,
        'payment_expires_at' => 'gt.' . $now->format(DATE_ATOM),
        'order' => 'payment_started_at.asc',
        'limit' => '50',
    ]);

    $records = cron_payments_fetch_records($query);

    $checked = count($records);
    $sent = 0;
    $updated = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($records as $booking) {
        $bookingId = (string)($booking['id'] ?? '');
        $tenantId = (string)($booking['tenant_id'] ?? '');

        if ($bookingId === '' || $tenantId === '') {
            $failed++;
            continue;
        }

        if (!tenant_has_feature($tenantId, 'payment_reminders')) {
            $skipped++;
            continue;
        }

        $safeBooking = cron_payments_safe_booking_for_email($booking);
        $emailSent = cron_payments_send_reminder_email($safeBooking);

        if ($emailSent) {
            $sent++;
        }

        $wasUpdated = cron_payments_update_booking($bookingId, [
            'payment_reminder_sent_at' => $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
        ]);

        if ($wasUpdated) {
            $updated++;
        } else {
            $failed++;
        }
    }

    return [
        'checked' => $checked,
        'emails_sent' => $sent,
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => $failed,
    ];
}

function cron_payments_process_expired(DateTimeImmutable $now): array
{
    $threshold = $now->modify('-30 minutes')->format(DATE_ATOM);

    $query = http_build_query([
        'select' => 'id,tenant_id,name,email,phone,booking_date,booking_time,payment_amount,payment_currency,payment_expires_at,payment_url',
        'payment_required' => 'eq.true',
        'payment_status' => 'eq.pending',
        'payment_expires_at' => 'lte.' . $threshold,
        'order' => 'payment_expires_at.asc',
        'limit' => '50',
    ]);

    $records = cron_payments_fetch_records($query);

    $checked = count($records);
    $customerEmails = 0;
    $adminEmails = 0;
    $updated = 0;
    $failed = 0;

    foreach ($records as $booking) {
        $bookingId = (string)($booking['id'] ?? '');
        $tenantId = (string)($booking['tenant_id'] ?? '');

        if ($bookingId === '' || $tenantId === '') {
            $failed++;
            continue;
        }

        $wasUpdated = cron_payments_update_booking($bookingId, [
            'status' => 'payment_overdue',
            'payment_status' => 'expired',
            'payment_expired_at' => $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
        ]);

        if (!$wasUpdated) {
            $failed++;
            continue;
        }

        $updated++;

        $safeBooking = cron_payments_safe_booking_for_email($booking);

        if (cron_payments_send_expired_customer_email($safeBooking)) {
            $customerEmails++;
        }

        $adminEmail = cron_payments_fetch_admin_email($tenantId);

        if (cron_payments_send_expired_admin_email($safeBooking, $adminEmail)) {
            $adminEmails++;
        }
    }

    return [
        'checked' => $checked,
        'customer_emails_sent' => $customerEmails,
        'admin_emails_sent' => $adminEmails,
        'updated' => $updated,
        'failed' => $failed,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        cron_payments_response([
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ], 405);
    }

    cron_payments_require_authorization();

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $reminders = cron_payments_process_reminders($now);
    $expired = cron_payments_process_expired($now);

    payu_debug('CRON_PAYMENTS_DONE', [
        'reminders' => $reminders,
        'expired' => $expired,
    ]);

    cron_payments_response([
        'success' => true,
        'now' => $now->format(DATE_ATOM),
        'reminders' => $reminders,
        'expired' => $expired,
    ]);

} catch (Throwable $e) {
    payu_debug('CRON_PAYMENTS_FATAL', [
        'error_class' => get_class($e),
        'message_trace' => cron_payments_trace($e->getMessage()),
    ]);

    cron_payments_response([
        'success' => false,
        'error' => 'Błąd obsługi płatności oczekujących.',
    ], 500);
}
