<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_bookings_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_bookings_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema
): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 20,
    ]);

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

function staff_bookings_clear_session(): void
{
    unset($_SESSION['staff_user']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_bookings_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], 405);
}

$staffSession = $_SESSION['staff_user'] ?? null;

if (!is_array($staffSession) || empty($staffSession['tenant_id']) || empty($staffSession['staff_id'])) {
    staff_bookings_json([
        'success' => false,
        'error' => 'Brak aktywnej sesji personelu.',
    ], 401);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_bookings_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ], 500);
}

$hostTenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);
$sessionTenantId = (string) ($staffSession['tenant_id'] ?? '');
$sessionStaffId = (string) ($staffSession['staff_id'] ?? '');

if (!$hostTenantId || !hash_equals($sessionTenantId, (string) $hostTenantId)) {
    staff_bookings_clear_session();
    staff_bookings_json([
        'success' => false,
        'error' => 'Sesja personelu nie pasuje do domeny.',
    ], 401);
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$bookingsUrl = $supabaseUrl
    . '/rest/v1/bookings'
    . '?select=id,booking_date,booking_time,name,email,phone,notes,status,payment_status,payment_required,service_id,service_name_snapshot,created_at,reschedule_count,rescheduled_at'
    . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&staff_id=eq.' . rawurlencode($sessionStaffId)
    . '&booking_date=gte.' . rawurlencode($today)
    . '&order=booking_date.asc'
    . '&order=booking_time.asc'
    . '&limit=100';

$bookingsResult = staff_bookings_request('GET', $bookingsUrl, $supabaseKey, $schema);

if (
    $bookingsResult['response'] === false
    || $bookingsResult['error'] !== ''
    || $bookingsResult['httpCode'] < 200
    || $bookingsResult['httpCode'] >= 300
) {
    staff_bookings_json([
        'success' => false,
        'error' => 'Nie udało się pobrać rezerwacji personelu.',
    ], 500);
}

$bookings = is_array($bookingsResult['data'] ?? null) ? $bookingsResult['data'] : [];

staff_bookings_json([
    'success' => true,
    'bookings' => $bookings,
]);
