<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function payment_return_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

function payment_return_session_booking_id(string $tenantId): string
{
    $handoff = $_SESSION['booking_payment_return_handoff'] ?? null;

    if (!is_array($handoff)) {
        return '';
    }

    $bookingId = trim((string)($handoff['booking_id'] ?? ''));
    $handoffTenantId = trim((string)($handoff['tenant_id'] ?? ''));
    $createdAt = (int)($handoff['created_at'] ?? 0);

    if ($bookingId === '' || $handoffTenantId === '' || $createdAt <= 0) {
        unset($_SESSION['booking_payment_return_handoff']);
        return '';
    }

    if (time() - $createdAt > 7200) {
        unset($_SESSION['booking_payment_return_handoff']);
        return '';
    }

    if (!hash_equals($tenantId, $handoffTenantId)) {
        return '';
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $bookingId)) {
        unset($_SESSION['booking_payment_return_handoff']);
        return '';
    }

    return $bookingId;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        payment_return_response([
            'success' => false,
            'error' => 'Metoda niedozwolona.'
        ], 405);
    }

    $bookingId = trim((string)($_GET['booking_id'] ?? ''));

    if ($bookingId !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $bookingId)) {
        payment_return_response([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator rezerwacji.'
        ], 400);
    }

    $supabaseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string)getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payment_return_response([
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności.'
        ], 500);
    }

    $tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

    if (!$tenantId) {
        payment_return_response([
            'success' => false,
            'error' => 'Nie rozpoznano klienta.'
        ], 404);
    }

    if ($bookingId === '') {
        $bookingId = payment_return_session_booking_id((string)$tenantId);
    }

    if ($bookingId === '') {
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
        payment_return_response([
            'success' => false,
            'error' => 'Nie udało się pobrać rezerwacji.'
        ], 500);
    }

    $booking = $bookingResult['data'][0] ?? null;

    if (!$booking) {
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
    payment_return_response([
        'success' => false,
        'error' => 'Błąd pobierania statusu płatności.',
    ], 500);
}
