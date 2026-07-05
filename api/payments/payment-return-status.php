<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function payment_return_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function payment_return_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result,
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $stage = null
): void {
    $details = [
        'reason' => $reason,
    ];

    if ($stage !== null && trim($stage) !== '') {
        $details['stage'] = trim($stage);
    }

    security_log_event($eventKey, [
        'action_key' => 'payment_return_status',
        'endpoint' => '/api/payments/payment-return-status.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'actor_type' => 'public',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'tenant_id' => $tenantId,
        'details' => $details,
    ]);
}

function payment_return_supabase_get(string $url, string $key, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
            'Content-Type: application/json',
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode((string) $raw, true);

    return [
        'http_code' => $httpCode,
        'error' => $error ?: null,
        'raw' => $raw,
        'data' => is_array($data) ? $data : null,
    ];
}

function payment_return_session_booking_id(string $tenantId, ?string &$failureReason = null): string
{
    $failureReason = null;
    $handoff = $_SESSION['booking_payment_return_handoff'] ?? null;

    if (!is_array($handoff)) {
        $failureReason = 'handoff_missing';
        return '';
    }

    $bookingId = trim((string)($handoff['booking_id'] ?? ''));
    $handoffTenantId = trim((string)($handoff['tenant_id'] ?? ''));
    $createdAt = (int)($handoff['created_at'] ?? 0);

    if ($bookingId === '' || $handoffTenantId === '' || $createdAt <= 0) {
        unset($_SESSION['booking_payment_return_handoff']);
        $failureReason = 'handoff_invalid';
        return '';
    }

    if (time() - $createdAt > 7200) {
        unset($_SESSION['booking_payment_return_handoff']);
        $failureReason = 'handoff_expired';
        return '';
    }

    if (!hash_equals($tenantId, $handoffTenantId)) {
        $failureReason = 'handoff_tenant_mismatch';
        return '';
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $bookingId)) {
        unset($_SESSION['booking_payment_return_handoff']);
        $failureReason = 'handoff_booking_ref_invalid';
        return '';
    }

    return $bookingId;
}

$tenantId = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        payment_return_security_event(
            'payment_return_status_method_not_allowed',
            'method_not_allowed',
            405,
            'failed',
            'low'
        );
        payment_return_response([
            'success' => false,
            'error' => 'Metoda niedozwolona.'
        ], 405);
    }

    /*
     * Status powrotu z PayU nie przyjmuje już publicznego booking_id z URL.
     * Techniczny identyfikator rezerwacji może pochodzić wyłącznie z backendowego handoffu sesyjnego.
     */
    $bookingId = '';

    $supabaseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string)getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payment_return_security_event(
            'payment_return_status_env_missing',
            'env_missing',
            500,
            'error',
            'high',
            null,
            'config'
        );
        payment_return_response([
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności.'
        ], 500);
    }

    $tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

    if (!$tenantId) {
        payment_return_security_event(
            'payment_return_status_tenant_denied',
            'tenant_denied',
            404,
            'failed',
            'medium'
        );
        payment_return_response([
            'success' => false,
            'error' => 'Nie rozpoznano klienta.'
        ], 404);
    }

    if ($bookingId === '') {
        $handoffFailureReason = null;
        $bookingId = payment_return_session_booking_id((string)$tenantId, $handoffFailureReason);
    }

    if ($bookingId === '') {
        payment_return_security_event(
            'payment_return_status_handoff_missing',
            $handoffFailureReason ?: 'handoff_missing',
            400,
            'failed',
            'medium',
            (string)$tenantId,
            'handoff'
        );
        payment_return_response([
            'success' => false,
            'error' => 'Brak aktywnej rezerwacji do sprawdzenia płatności.'
        ], 400);
    }

    $bookingUrl = $supabaseUrl
        . '/rest/v1/bookings'
        . '?select=tenant_id,name,booking_date,booking_time,service_name_snapshot,payment_status,payment_required,payment_amount,payment_currency'
        . '&id=eq.' . rawurlencode($bookingId)
        . '&tenant_id=eq.' . rawurlencode((string)$tenantId)
        . '&limit=1';

    $bookingResult = payment_return_supabase_get($bookingUrl, $supabaseKey, $schema);

    if ($bookingResult['error'] || $bookingResult['http_code'] !== 200) {
        payment_return_security_event(
            'payment_return_status_booking_fetch_failed',
            'booking_fetch_failed',
            500,
            'error',
            'medium',
            (string)$tenantId,
            'booking_fetch'
        );
        payment_return_response([
            'success' => false,
            'error' => 'Nie udało się pobrać rezerwacji.'
        ], 500);
    }

    $booking = $bookingResult['data'][0] ?? null;

    if (!$booking) {
        payment_return_security_event(
            'payment_return_status_booking_not_found',
            'booking_not_found',
            404,
            'failed',
            'medium',
            (string)$tenantId,
            'booking_fetch'
        );
        payment_return_response([
            'success' => false,
            'error' => 'Nie znaleziono rezerwacji.'
        ], 404);
    }

    $brandingUrl = $supabaseUrl
        . '/rest/v1/tenant_branding'
        . '?select=client_name,logo_url_front,favicon_url_front'
        . '&tenant_id=eq.' . rawurlencode((string)$tenantId)
        . '&limit=1';

    $brandingResult = payment_return_supabase_get($brandingUrl, $supabaseKey, $schema);
    $branding = [];

    if (!$brandingResult['error'] && $brandingResult['http_code'] === 200) {
        $branding = $brandingResult['data'][0] ?? [];
    }

    $serviceUrl = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?select=service_name,service_description'
        . '&tenant_id=eq.' . rawurlencode((string)$tenantId)
        . '&limit=1';

    $serviceResult = payment_return_supabase_get($serviceUrl, $supabaseKey, $schema);
    $service = [];

    if (!$serviceResult['error'] && $serviceResult['http_code'] === 200) {
        $service = $serviceResult['data'][0] ?? [];
    }

    payment_return_response([
        'success' => true,
        'booking' => [
            'booking_date' => $booking['booking_date'] ?? '',
            'name' => $booking['name'] ?? '',
            'service_name_snapshot' => $booking['service_name_snapshot'] ?? '',
            'booking_time' => $booking['booking_time'] ?? '',
            'payment_status' => $booking['payment_status'] ?? '',
            'payment_required' => $booking['payment_required'] ?? false,
            'payment_amount' => $booking['payment_amount'] ?? null,
            'payment_currency' => $booking['payment_currency'] ?? 'PLN',
        ],
        'branding' => [
            'client_name' => $branding['client_name'] ?? '',
            'logo_url_front' => branding_asset_public_url((string)($branding['logo_url_front'] ?? ''), (string)$tenantId, 'logo'),
            'favicon_url_front' => branding_asset_public_url((string)($branding['favicon_url_front'] ?? ''), (string)$tenantId, 'favicon'),
        ],
        'service' => [
            'service_name' => $service['service_name'] ?? '',
            'service_description' => $service['service_description'] ?? '',
        ],
    ]);

} catch (Throwable $e) {
    payment_return_security_event(
        'payment_return_status_fatal',
        'fatal_error',
        500,
        'error',
        'high',
        is_string($tenantId) && $tenantId !== '' ? $tenantId : null,
        'fatal'
    );
    payment_return_response([
        'success' => false,
        'error' => 'Błąd pobierania statusu płatności.',
    ], 500);
}
