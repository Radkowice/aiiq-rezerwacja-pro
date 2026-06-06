<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../helpers/booking_mail.php';
require_once __DIR__ . '/../helpers/plan_features.php';

function payu_notify_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function payu_notify_get_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                return trim((string)$value);
            }
        }
    }

    return '';
}

function payu_notify_parse_signature_header(string $header): array
{
    $result = [];

    foreach (explode(';', $header) as $part) {
        $part = trim($part);

        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $part, 2);
        $key = strtolower(trim($key));
        $value = trim($value);

        if ($key !== '') {
            $result[$key] = $value;
        }
    }

    return $result;
}

function payu_notify_verify_signature(string $rawBody, string $secondKey, string $signatureHeader): bool
{
    if ($rawBody === '' || $secondKey === '' || $signatureHeader === '') {
        return false;
    }

    $signatureData = payu_notify_parse_signature_header($signatureHeader);

    $incomingSignature = strtolower((string)($signatureData['signature'] ?? ''));
    $algorithm = strtolower((string)($signatureData['algorithm'] ?? 'md5'));

    if ($incomingSignature === '') {
        return false;
    }

    if ($algorithm !== 'md5') {
        payu_debug('PAYU_NOTIFY_UNSUPPORTED_SIGNATURE_ALGORITHM', [
            'algorithm' => $algorithm,
        ]);

        return false;
    }

    $expectedSignature = md5($rawBody . $secondKey);

    return hash_equals($expectedSignature, $incomingSignature);
}

function payu_notify_fetch_booking_by_order(string $orderId, string $extOrderId = ''): ?array
{
    $supabaseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string)getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_NOTIFY_ENV_MISSING');
        return null;
    }

    $filters = [];

    if ($orderId !== '') {
        $filters[] = 'payment_order_id.eq.' . rawurlencode($orderId);
    }

    if ($extOrderId !== '') {
        $filters[] = 'payment_order_id.eq.' . rawurlencode($extOrderId);
    }

    if (!$filters) {
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?select=*'
        . '&or=(' . implode(',', $filters) . ')'
        . '&limit=1';

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('PAYU_NOTIFY_BOOKING_FETCH_ERROR', [
            'http_code' => $result['http_code'],
            'has_error' => !empty($result['error']),
            'order_id' => $orderId,
            'ext_order_id' => $extOrderId,
        ]);

        return null;
    }

    return $result['data'][0] ?? null;
}

function payu_notify_update_booking(string $bookingId, string $tenantId, array $payload): bool
{
    $supabaseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string)getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_NOTIFY_UPDATE_ENV_MISSING');
        return false;
    }

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?id=eq.' . rawurlencode($bookingId)
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = payu_supabase_request(
        $url,
        'PATCH',
        $supabaseKey,
        $schema,
        $payload,
        ['Prefer: return=representation']
    );

    if ($result['error'] || $result['http_code'] < 200 || $result['http_code'] >= 300) {
        payu_debug('PAYU_NOTIFY_BOOKING_UPDATE_ERROR', [
            'booking_id' => $bookingId,
            'http_code' => $result['http_code'],
            'has_error' => !empty($result['error']),
        ]);

        return false;
    }

    return true;
}

function payu_notify_map_status(string $payuStatus): string
{
    $status = strtoupper(trim($payuStatus));

    return match ($status) {
        'COMPLETED' => 'paid',
        'CANCELED' => 'cancelled',
        default => 'pending',
    };
}

function payu_notify_public_base_url(): string
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

function payu_notify_manage_token_is_active(string $expiresAt): bool
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

function payu_notify_reschedule_url(string $tenantId, array $booking): string
{
    if (!tenant_has_feature($tenantId, 'reschedule_booking')) {
        return '';
    }

    $token = trim((string)($booking['manage_token'] ?? ''));
    $expiresAt = trim((string)($booking['manage_token_expires_at'] ?? ''));

    if ($token === '' || !payu_notify_manage_token_is_active($expiresAt)) {
        return '';
    }

    $baseUrl = payu_notify_public_base_url();

    if ($baseUrl === '') {
        return '';
    }

    return $baseUrl . '/przeloz-rezerwacje.html?token=' . rawurlencode($token);
}

function payu_notify_fetch_single_record(string $table, string $query): ?array
{
    $supabaseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string)getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_NOTIFY_FETCH_SINGLE_ENV_MISSING', [
            'table' => $table,
        ]);

        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/' . rawurlencode($table)
        . '?select=*'
        . '&' . $query
        . '&limit=1';

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('PAYU_NOTIFY_FETCH_SINGLE_ERROR', [
            'table' => $table,
            'http_code' => $result['http_code'],
            'has_error' => !empty($result['error']),
        ]);

        return null;
    }

    return $result['data'][0] ?? null;
}

function payu_notify_fetch_staff_email_profile(string $tenantId, string $staffId): ?array
{
    if ($tenantId === '' || $staffId === '') {
        return null;
    }

    return payu_notify_fetch_single_record(
        'staff_profiles',
        'tenant_id=eq.' . rawurlencode($tenantId)
            . '&id=eq.' . rawurlencode($staffId)
            . '&select=id,display_name,email_subject,email_heading,email_body'
    );
}

function payu_notify_effective_email_template(array $globalTemplate, ?array $staff): array
{
    $template = $globalTemplate;

    if (!is_array($staff)) {
        return $template;
    }

    $staffSubject = trim((string)($staff['email_subject'] ?? ''));
    $staffHeading = trim((string)($staff['email_heading'] ?? ''));
    $staffBody = trim((string)($staff['email_body'] ?? ''));

    if ($staffSubject !== '') {
        $template['subject'] = $staffSubject;
    }

    if ($staffHeading !== '') {
        $template['service_name'] = $staffHeading;
    }

    if ($staffBody !== '') {
        $template['body_html'] = $staffBody;
    }

    return $template;
}

function payu_notify_send_paid_email(string $tenantId, array $booking): bool
{
    $tenantQuery = 'tenant_id=eq.' . rawurlencode($tenantId);

    $emailSettings = payu_notify_fetch_single_record(
        'email_settings',
        $tenantQuery . '&is_active=eq.true'
    );

    $emailTemplate = payu_notify_fetch_single_record(
        'email_templates',
        $tenantQuery . '&template_key=eq.booking_client_confirmation&is_enabled=eq.true'
    );

    $tenantData = payu_notify_fetch_single_record(
        'tenant_branding',
        $tenantQuery
    );

    if (!$emailSettings || !$emailTemplate || !$tenantData) {
        payu_debug('PAYU_NOTIFY_OFFICIAL_EMAIL_CONFIG_MISSING', [
            'tenant_id' => $tenantId,
            'email_settings' => (bool)$emailSettings,
            'email_template' => (bool)$emailTemplate,
            'tenant_data' => (bool)$tenantData,
        ]);

        return false;
    }

    $staffEmailProfile = payu_notify_fetch_staff_email_profile(
        $tenantId,
        trim((string)($booking['staff_id'] ?? ''))
    );

    $staffDisplayName = is_array($staffEmailProfile)
        ? trim((string)($staffEmailProfile['display_name'] ?? ''))
        : '';

    if ($staffDisplayName !== '') {
        $booking['staff_display_name'] = $staffDisplayName;
    }

    $effectiveEmailTemplate = payu_notify_effective_email_template(
        $emailTemplate,
        $staffEmailProfile
    );

    $rescheduleUrl = payu_notify_reschedule_url($tenantId, $booking);
   
    return booking_mail_send_client_confirmation(
        $emailSettings,
        $effectiveEmailTemplate,
        $tenantData,
        $booking,
        [
            'status_label' => 'Opłacono',
            'amount' => $booking['payment_amount'] ?? null,
            'currency' => (string)($booking['payment_currency'] ?? 'PLN'),
            'reschedule_url' => $rescheduleUrl,
        ]
    );
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        payu_notify_response([
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ], 405);
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        payu_debug('PAYU_NOTIFY_INVALID_JSON', [
            'body_length' => strlen($rawBody),
            'json_error' => json_last_error_msg(),
        ]);

        payu_notify_response([
            'success' => false,
            'error' => 'Nieprawidłowy JSON.',
        ], 400);
    }

    $order = is_array($data['order'] ?? null) ? $data['order'] : [];

    $orderId = trim((string)($order['orderId'] ?? ''));
    $extOrderId = trim((string)($order['extOrderId'] ?? ''));
    $payuStatus = trim((string)($order['status'] ?? ''));

    if ($orderId !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $orderId)) {
        payu_notify_response([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator płatności.',
        ], 400);
    }

    if ($extOrderId !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $extOrderId)) {
        payu_notify_response([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator płatności.',
        ], 400);
    }

    if ($orderId === '' && $extOrderId === '') {
        payu_debug('PAYU_NOTIFY_ORDER_ID_MISSING', [
            'body_length' => strlen($rawBody),
            'has_order' => is_array($data['order'] ?? null),
        ]);

        payu_notify_response([
            'success' => false,
            'error' => 'Brak orderId/extOrderId.',
        ], 400);
    }

    $booking = payu_notify_fetch_booking_by_order($orderId, $extOrderId);

   if (!$booking) {
    payu_debug('PAYU_NOTIFY_BOOKING_NOT_FOUND', [
        'order_id' => $orderId,
        'ext_order_id' => $extOrderId,
        'status' => $payuStatus,
    ]);

    payu_notify_response([
        'success' => false,
        'error' => 'Nieprawidłowe powiadomienie PayU.',
    ], 401);
}

    $bookingId = (string)($booking['id'] ?? '');
    $tenantId = (string)($booking['tenant_id'] ?? '');

    if ($bookingId === '' || $tenantId === '') {
        payu_debug('PAYU_NOTIFY_BOOKING_INVALID', [
            'order_id_present' => $orderId !== '',
            'ext_order_id_present' => $extOrderId !== '',
            'booking_id_present' => $bookingId !== '',
            'tenant_id_present' => $tenantId !== '',
        ]);

        payu_notify_response([
            'success' => false,
            'error' => 'Nieprawidłowa rezerwacja.',
        ], 422);
    }

    $payu = payu_get_integration($tenantId);

    if (!$payu || empty($payu['second_key'])) {
        payu_debug('PAYU_NOTIFY_INTEGRATION_MISSING', [
            'tenant_id' => $tenantId,
            'second_key_set' => !empty($payu['second_key'] ?? ''),
        ]);

        payu_notify_response([
            'success' => false,
            'error' => 'Brak konfiguracji PayU.',
        ], 422);
    }

    $signatureHeader = payu_notify_get_header('OpenPayu-Signature');

    if (!payu_notify_verify_signature($rawBody, (string)$payu['second_key'], $signatureHeader)) {
        payu_debug('PAYU_NOTIFY_SIGNATURE_INVALID', [
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'order_id' => $orderId,
            'ext_order_id' => $extOrderId,
            'signature_header_set' => $signatureHeader !== '',
        ]);

        payu_notify_response([
            'success' => false,
            'error' => 'Nieprawidłowy podpis PayU.',
        ], 401);
    }

   $newStatus = payu_notify_map_status($payuStatus);
$now = gmdate('c');

$payload = [
    'payment_status' => $newStatus,
    'payment_provider' => 'payu',
    'updated_at' => $now,
];

if ($orderId !== '') {
    $payload['payment_order_id'] = $orderId;
}

if ($newStatus === 'paid') {
    $payload['status'] = 'confirmed';
    $payload['paid_at'] = $now;
}

$updated = payu_notify_update_booking($bookingId, $tenantId, $payload);

    if (!$updated) {
        payu_notify_response([
            'success' => false,
            'error' => 'Nie udało się zaktualizować rezerwacji.',
        ], 500);
    }

    $paidEmailSent = null;

    if ($newStatus === 'paid') {
        $bookingForEmail = $booking;
        $bookingForEmail['payment_status'] = 'paid';
        $bookingForEmail['paid_at'] = $payload['paid_at'] ?? gmdate('c');

        $paidEmailSent = payu_notify_send_paid_email($tenantId, $bookingForEmail);

        payu_debug('PAYU_NOTIFY_PAID_EMAIL_RESULT', [
            'booking_id' => $bookingId,
            'email_sent' => $paidEmailSent,
        ]);
    }

    payu_debug('PAYU_NOTIFY_SUCCESS', [
        'booking_id' => $bookingId,
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'ext_order_id' => $extOrderId,
        'payu_status' => $payuStatus,
        'payment_status' => $newStatus,
    ]);

       payu_notify_response([
        'success' => true,
        'status' => $newStatus,
        'paid_email_sent' => $paidEmailSent,
    ]);

} catch (Throwable $e) {
    payu_debug('PAYU_NOTIFY_FATAL', [
        'exception_type' => get_class($e),
    ]);

    payu_notify_response([
        'success' => false,
        'error' => 'Błąd obsługi powiadomienia PayU.',
    ], 500);
}
