<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../helpers/php_mail.php';

function payu_create_order_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function payu_get_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function payu_fetch_booking(string $bookingId): ?array
{
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_BOOKING_ENV_MISSING');
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?select=*'
        . '&id=eq.' . rawurlencode($bookingId)
        . '&limit=1';

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('PAYU_BOOKING_FETCH_ERROR', [
            'booking_id' => $bookingId,
            'http_code' => $result['http_code'],
            'error' => $result['error'],
            'response' => $result['response'],
        ]);
        return null;
    }

    return $result['data'][0] ?? null;
}

function payu_update_booking_payment(string $bookingId, array $payload): bool
{
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_UPDATE_ENV_MISSING');
        return false;
    }

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
        payu_debug('PAYU_BOOKING_PAYMENT_UPDATE_ERROR', [
            'booking_id' => $bookingId,
            'http_code' => $result['http_code'],
            'error' => $result['error'],
            'response' => $result['response'],
        ]);
        return false;
    }

    return true;
}

function payu_get_public_base_url(): string
{
    $scheme = 'https';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? '';

    $host = trim((string) $host);

    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host;
}

function payu_create_order_send_pending_email(array $booking, string $paymentUrl): bool
{
    $email = trim((string)($booking['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        payu_debug('PAYU_CREATE_ORDER_PENDING_EMAIL_SKIPPED_NO_EMAIL', [
            'booking_id' => $booking['id'] ?? '',
        ]);

        return false;
    }

    $name = trim((string)($booking['name'] ?? ''));
    $bookingDate = trim((string)($booking['booking_date'] ?? ''));
    $bookingTime = trim((string)($booking['booking_time'] ?? ''));
    $expiresAt = trim((string)($booking['payment_expires_at'] ?? ''));
    $amount = $booking['payment_amount'] ?? null;
    $currency = trim((string)($booking['payment_currency'] ?? 'PLN'));

    $amountText = '';

    if ($amount !== null && $amount !== '') {
        $displayCurrency = strtoupper(trim($currency)) === 'PLN' ? 'zł' : trim($currency);

if ($displayCurrency === '') {
    $displayCurrency = 'zł';
}

$amountText = number_format((float)$amount, 2, ',', ' ') . ' ' . $displayCurrency;
    }

    $expiresText = '—';

    if ($expiresAt !== '') {
        try {
            $expiresDate = new DateTimeImmutable($expiresAt);
            $expiresText = $expiresDate
                ->setTimezone(new DateTimeZone('Europe/Warsaw'))
                ->format('Y-m-d H:i');
        } catch (Throwable $e) {
            $expiresText = $expiresAt;
        }
    }

    $safeName = htmlspecialchars($name !== '' ? $name : 'Kliencie', ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars($bookingDate !== '' ? $bookingDate : '—', ENT_QUOTES, 'UTF-8');
    $safeTime = htmlspecialchars($bookingTime !== '' ? $bookingTime : '—', ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars($amountText !== '' ? $amountText : '—', ENT_QUOTES, 'UTF-8');
    $safeExpires = htmlspecialchars($expiresText, ENT_QUOTES, 'UTF-8');
    $safePaymentUrl = htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>Twoja rezerwacja została rozpoczęta.</strong></p>'
        . '<p style="margin:0 0 12px;">Dziękujemy, <strong>' . $safeName . '</strong>.</p>'
        . '<p style="margin:0 0 10px;">Poniżej znajdziesz dane rezerwacji oraz link do płatności online PayU.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;border-collapse:collapse;">'
        . '<tr><td style="padding:8px 0;color:#6b7280;">Data:</td><td style="padding:8px 0;text-align:right;"><strong>' . $safeDate . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#6b7280;">Godzina:</td><td style="padding:8px 0;text-align:right;"><strong>' . $safeTime . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#6b7280;">Kwota:</td><td style="padding:8px 0;text-align:right;"><strong>' . $safeAmount . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#6b7280;">Termin płatności:</td><td style="padding:8px 0;text-align:right;"><strong>' . $safeExpires . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#6b7280;">Status:</td><td style="padding:8px 0;text-align:right;"><strong>Aktualizowany automatycznie po potwierdzeniu przez PayU</strong></td></tr>'
        . '</table>'
        . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">'
        . 'Jeżeli płatność została już wykonana, nie musisz nic robić — po potwierdzeniu płatności przez PayU rezerwacja zostanie zaktualizowana automatycznie.'
        . '</p>'
        . '<p style="margin:12px 0 0;color:#374151;line-height:1.6;">'
        . 'Jeżeli jeszcze nie opłaciłeś rezerwacji albo płatność została przerwana, możesz wrócić do płatności, korzystając z poniższego przycisku.'
        . '</p>'
        . '<div style="margin-top:22px;text-align:center;">'
        . '<a href="' . $safePaymentUrl . '" style="display:inline-block;padding:13px 22px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">Przejdź do płatności</a>'
        . '</div>'
        . '<p style="margin:18px 0 0;color:#6b7280;font-size:13px;line-height:1.5;">Jeśli przycisk nie działa, skopiuj i otwórz ten link w przeglądarce:<br>'
        . '<span style="word-break:break-all;">' . $safePaymentUrl . '</span></p>';

    $html = buildSystemMailLayout(
        'Twoja rezerwacja — link do płatności',
        'Jeżeli płatność została przerwana, możesz wrócić do niej z tego maila.',
        $message,
        'Email zawiera link do płatności za rezerwację. Jeśli płatność została już wykonana, potraktuj tę wiadomość informacyjnie.'
    );

    return sendSystemMail(
        $email,
        'Twoja rezerwacja — link do płatności',
        $html
    );
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        payu_create_order_response([
            'success' => false,
            'error' => 'Metoda niedozwolona.'
        ], 405);
    }

    $input = payu_get_json_input();
    $bookingId = trim((string) ($input['booking_id'] ?? ''));

    if ($bookingId === '') {
        payu_create_order_response([
            'success' => false,
            'error' => 'Brak booking_id.'
        ], 400);
    }

    $booking = payu_fetch_booking($bookingId);

    if (!$booking) {
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie znaleziono rezerwacji.'
        ], 404);
    }

    $tenantId = (string) ($booking['tenant_id'] ?? '');

    if ($tenantId === '') {
        payu_create_order_response([
            'success' => false,
            'error' => 'Rezerwacja nie ma tenant_id.'
        ], 422);
    }

    $paymentRequired = $booking['payment_required'] === true || $booking['payment_required'] === 'true';

    if (!$paymentRequired) {
        payu_create_order_response([
            'success' => false,
            'error' => 'Ta rezerwacja nie wymaga płatności.'
        ], 422);
    }

    $paymentStatus = (string) ($booking['payment_status'] ?? 'not_required');

    if ($paymentStatus === 'paid') {
        payu_create_order_response([
            'success' => false,
            'error' => 'Ta rezerwacja jest już opłacona.'
        ], 422);
    }

    $amount = isset($booking['payment_amount'])
        ? (float) $booking['payment_amount']
        : 0.0;

    if ($amount <= 0) {
        payu_create_order_response([
            'success' => false,
            'error' => 'Brak poprawnej kwoty płatności.'
        ], 422);
    }

    $currency = (string) ($booking['payment_currency'] ?? 'PLN');
    if ($currency === '') {
        $currency = 'PLN';
    }

    $payu = payu_get_integration($tenantId);

    if (!$payu) {
        payu_create_order_response([
            'success' => false,
            'error' => 'Integracja PayU nie jest skonfigurowana albo jest wyłączona.'
        ], 422);
    }

    $publicBaseUrl = payu_get_public_base_url();

    if ($publicBaseUrl === '') {
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie udało się ustalić publicznego adresu aplikacji.'
        ], 500);
    }

    $amountInGrosze = (int) round($amount * 100);

    $bookingDate = (string) ($booking['booking_date'] ?? '');
    $bookingTime = (string) ($booking['booking_time'] ?? '');
    $customerName = trim((string) ($booking['name'] ?? 'Klient'));
    $customerEmail = trim((string) ($booking['email'] ?? ''));

    $description = 'Rezerwacja terminu';

    if ($bookingDate !== '' || $bookingTime !== '') {
        $description .= ' ' . trim($bookingDate . ' ' . $bookingTime);
    }

    $extOrderId = 'booking-' . $bookingId . '-' . time();

    $orderPayload = [
        'notifyUrl' => $publicBaseUrl . '/api/payments/payu-notify.php',
        'continueUrl' => $publicBaseUrl . '/platnosc-powrot.html?booking_id=' . rawurlencode($bookingId),
        'customerIp' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'merchantPosId' => $payu['pos_id'],
        'description' => $description,
        'currencyCode' => $currency,
        'totalAmount' => (string) $amountInGrosze,
        'extOrderId' => $extOrderId,
        'products' => [
            [
                'name' => $description,
                'unitPrice' => (string) $amountInGrosze,
                'quantity' => '1',
            ],
        ],
    ];

    if ($customerEmail !== '') {
        $orderPayload['buyer'] = [
            'email' => $customerEmail,
            'firstName' => $customerName,
        ];
    }

    payu_debug('PAYU_CREATE_ORDER_PAYLOAD', [
        'booking_id' => $bookingId,
        'tenant_id' => $tenantId,
        'amount' => $amount,
        'currency' => $currency,
        'mode' => $payu['mode'],
    ]);

    $created = payu_create_order($payu, $orderPayload);

    if (empty($created['success'])) {
        payu_update_booking_payment($bookingId, [
            'payment_status' => 'failed',
            'payment_provider' => 'payu',
            'updated_at' => gmdate('c'),
        ]);

        payu_create_order_response([
            'success' => false,
            'error' => $created['error'] ?? 'Nie udało się utworzyć zamówienia PayU.',
            'details' => $created,
        ], 500);
    }

    $orderId = (string) ($created['order_id'] ?? '');
    $redirectUri = (string) ($created['redirect_uri'] ?? '');

    $now = gmdate('c');

    $updated = payu_update_booking_payment($bookingId, [
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'payment_provider' => 'payu',
        'payment_order_id' => $orderId,
        'payment_url' => $redirectUri,
        'payment_started_at' => $now,
        'updated_at' => $now,
    ]);

    if (!$updated) {
        payu_create_order_response([
            'success' => false,
            'error' => 'Zamówienie PayU utworzone, ale nie udało się zapisać danych płatności w rezerwacji.',
            'payment_url' => $redirectUri,
            'payment_order_id' => $orderId,
        ], 500);
    }

    $pendingEmailSent = payu_create_order_send_pending_email(
        array_merge($booking, [
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'payment_order_id' => $orderId,
            'payment_url' => $redirectUri,
            'payment_started_at' => $now,
        ]),
        $redirectUri
    );

    payu_debug('PAYU_CREATE_ORDER_PENDING_EMAIL_RESULT', [
        'booking_id' => $bookingId,
        'email_sent' => $pendingEmailSent,
    ]);

    payu_create_order_response([
        'success' => true,
        'payment_url' => $redirectUri,
        'payment_order_id' => $orderId,
        'pending_email_sent' => $pendingEmailSent,
    ]);

} catch (Throwable $e) {
    payu_debug('PAYU_CREATE_ORDER_FATAL', $e->getMessage());

    payu_create_order_response([
        'success' => false,
        'error' => 'Błąd tworzenia płatności PayU.',
        'details' => $e->getMessage(),
    ], 500);
}