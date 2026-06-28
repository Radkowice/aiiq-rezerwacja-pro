<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
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
$refSecret = public_response_ref_secret($supabaseKey);

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

require_tenant_feature(
    $sessionTenantId,
    'staff_module',
    'Panel pracownika jest dostępny dla kont z aktywnym planem Pro. To konto działa obecnie w planie Free albo abonament Pro wygasł. Opłać abonament Pro, aby odzyskać dostęp do panelu pracownika.'
);

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

function staff_bookings_unique_ids(array $rows, string $field): array
{
    $ids = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = trim((string) ($row[$field] ?? ''));

        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

function staff_bookings_index_changes_by_booking(array $changes): array
{
    $indexed = [];

    foreach ($changes as $change) {
        if (!is_array($change)) {
            continue;
        }

        $bookingId = trim((string) ($change['booking_id'] ?? ''));

        if ($bookingId === '') {
            continue;
        }

        if (!isset($indexed[$bookingId])) {
            $indexed[$bookingId] = [];
        }

        $indexed[$bookingId][] = $change;
    }

    return $indexed;
}

function staff_bookings_latest_change_for_booking(array $changesByBooking, string $bookingId): ?array
{
    $changes = $changesByBooking[$bookingId] ?? [];

    if (!is_array($changes) || $changes === []) {
        return null;
    }

    usort($changes, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $changes[0] ?? null;
}


function staff_bookings_public_booking(array $booking, string $tenantId, string $staffId, string $refSecret): array
{
    $bookingId = trim((string) ($booking['id'] ?? ''));
    $serviceId = trim((string) ($booking['service_id'] ?? ''));
    $bookingStaffId = trim((string) ($booking['staff_id'] ?? $staffId));

    $bookingRef = $bookingId !== '' ? public_response_booking_ref($tenantId, $bookingId, $refSecret) : '';
    $serviceRef = $serviceId !== '' ? public_response_service_ref($tenantId, $serviceId, $refSecret) : '';
    $staffRef = $bookingStaffId !== '' ? public_response_staff_ref($tenantId, $bookingStaffId, $refSecret) : '';

    $payload = [
        'booking_ref' => $bookingRef,
        'booking_date' => (string) ($booking['booking_date'] ?? ''),
        'booking_time' => substr((string) ($booking['booking_time'] ?? ''), 0, 5),
        'name' => (string) ($booking['name'] ?? ''),
        'email' => (string) ($booking['email'] ?? ''),
        'phone' => (string) ($booking['phone'] ?? ''),
        'notes' => (string) ($booking['notes'] ?? ''),
        'status' => (string) ($booking['status'] ?? ''),
        'service_ref' => $serviceRef,
        'service_name_snapshot' => (string) ($booking['service_name_snapshot'] ?? ''),
        'staff_ref' => $staffRef,
        'reschedule_count' => max(0, (int) ($booking['reschedule_count'] ?? 0)),
        'rescheduled_at' => $booking['rescheduled_at'] ?? null,
    ];

    $assignmentState = (string) ($booking['employee_assignment_state'] ?? 'current');
    $assignmentMessage = trim((string) ($booking['employee_assignment_message'] ?? ''));

    if ($assignmentState !== '' && $assignmentState !== 'current') {
        $payload['employee_assignment_state'] = $assignmentState;
    }

    if ($assignmentMessage !== '') {
        $payload['employee_assignment_message'] = $assignmentMessage;
    }

    if (
        $assignmentMessage !== ''
        && array_key_exists('employee_assignment_changed_at', $booking)
        && $booking['employee_assignment_changed_at'] !== null
        && $booking['employee_assignment_changed_at'] !== ''
    ) {
        $payload['employee_assignment_changed_at'] = $booking['employee_assignment_changed_at'];
    }

    return $payload;
}

function staff_bookings_public_bookings(array $bookings, string $tenantId, string $staffId, string $refSecret): array
{
    $publicBookings = [];

    foreach ($bookings as $booking) {
        if (!is_array($booking)) {
            continue;
        }

        $publicBookings[] = staff_bookings_public_booking($booking, $tenantId, $staffId, $refSecret);
    }

    return $publicBookings;
}

function staff_bookings_filter_active_future_rows(array $rows, string $today): array
{
    return array_values(array_filter($rows, static function ($row) use ($today): bool {
        if (!is_array($row)) {
            return false;
        }

        $bookingDate = trim((string) ($row['booking_date'] ?? ''));

        return $bookingDate === '' || $bookingDate >= $today;
    }));
}

function staff_bookings_fetch_by_ids(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    array $bookingIds
): array {
    if ($bookingIds === []) {
        return [];
    }

    $safeIds = array_values(array_filter(array_map('trim', $bookingIds), static function (string $id): bool {
        return $id !== '';
    }));

    if ($safeIds === []) {
        return [];
    }

    $quotedIds = array_map(static function (string $id): string {
        return '"' . str_replace('"', '\\"', $id) . '"';
    }, $safeIds);

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?select=id,booking_date,booking_time,name,email,phone,notes,status,service_id,service_name_snapshot,reschedule_count,rescheduled_at,staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=in.(' . rawurlencode(implode(',', $quotedIds)) . ')'
        . '&order=booking_date.asc'
        . '&order=booking_time.asc'
        . '&limit=100';

    $result = staff_bookings_request('GET', $url, $supabaseKey, $schema);

    if (
        $result['response'] === false
        || $result['error'] !== ''
        || $result['httpCode'] < 200
        || $result['httpCode'] >= 300
    ) {
        return [];
    }

    return is_array($result['data'] ?? null) ? $result['data'] : [];
}

$currentBookingsUrl = $supabaseUrl
    . '/rest/v1/bookings'
    . '?select=id,booking_date,booking_time,name,email,phone,notes,status,service_id,service_name_snapshot,reschedule_count,rescheduled_at,staff_id'
    . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&staff_id=eq.' . rawurlencode($sessionStaffId)
    . '&booking_date=gte.' . rawurlencode($today)
    . '&order=booking_date.asc'
    . '&order=booking_time.asc'
    . '&limit=100';

$currentBookingsResult = staff_bookings_request('GET', $currentBookingsUrl, $supabaseKey, $schema);

if (
    $currentBookingsResult['response'] === false
    || $currentBookingsResult['error'] !== ''
    || $currentBookingsResult['httpCode'] < 200
    || $currentBookingsResult['httpCode'] >= 300
) {
    staff_bookings_json([
        'success' => false,
        'error' => 'Nie udało się pobrać rezerwacji personelu.',
    ], 500);
}

$currentBookings = staff_bookings_filter_active_future_rows(
    is_array($currentBookingsResult['data'] ?? null) ? $currentBookingsResult['data'] : [],
    $today
);

$changesUrl = $supabaseUrl
    . '/rest/v1/booking_staff_changes'
    . '?select=id,booking_id,old_staff_id,new_staff_id,old_staff_name,new_staff_name,action,note,changed_by,created_at'
    . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&or=('
        . 'old_staff_id.eq.' . rawurlencode($sessionStaffId)
        . ',new_staff_id.eq.' . rawurlencode($sessionStaffId)
    . ')'
    . '&order=created_at.desc'
    . '&limit=200';

$changesResult = staff_bookings_request('GET', $changesUrl, $supabaseKey, $schema);
$staffChanges = [];

if (
    $changesResult['response'] !== false
    && $changesResult['error'] === ''
    && $changesResult['httpCode'] >= 200
    && $changesResult['httpCode'] < 300
) {
    $staffChanges = is_array($changesResult['data'] ?? null) ? $changesResult['data'] : [];
}

$changesByBooking = staff_bookings_index_changes_by_booking($staffChanges);
$currentBookingsById = [];

foreach ($currentBookings as $booking) {
    if (!is_array($booking)) {
        continue;
    }

    $bookingId = trim((string) ($booking['id'] ?? ''));

    if ($bookingId === '') {
        continue;
    }

    $latestChange = staff_bookings_latest_change_for_booking($changesByBooking, $bookingId);

    $booking['employee_assignment_state'] = 'current';
    $booking['employee_assignment_message'] = '';

    if (
        is_array($latestChange)
        && ($latestChange['action'] ?? '') === 'change_staff'
        && trim((string) ($latestChange['new_staff_id'] ?? '')) === $sessionStaffId
    ) {
        $booking['employee_assignment_state'] = 'assigned_by_admin';
        $booking['employee_assignment_message'] = 'Administrator przypisał Tobie tę rezerwację.';
        $booking['employee_assignment_changed_at'] = $latestChange['created_at'] ?? null;
        $booking['employee_assignment_old_staff_name'] = $latestChange['old_staff_name'] ?? null;
        $booking['employee_assignment_new_staff_name'] = $latestChange['new_staff_name'] ?? null;
    }

    $currentBookingsById[$bookingId] = $booking;
}

$removedChanges = array_values(array_filter($staffChanges, static function ($change) use ($sessionStaffId): bool {
    if (!is_array($change)) {
        return false;
    }

    $action = (string) ($change['action'] ?? '');
    $oldStaffId = trim((string) ($change['old_staff_id'] ?? ''));

    return $oldStaffId === $sessionStaffId && in_array($action, ['change_staff', 'detach_staff'], true);
}));

$removedBookingIds = staff_bookings_unique_ids($removedChanges, 'booking_id');
$removedBookings = staff_bookings_fetch_by_ids(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    $sessionTenantId,
    $removedBookingIds
);

foreach ($removedBookings as $booking) {
    if (!is_array($booking)) {
        continue;
    }

    $bookingId = trim((string) ($booking['id'] ?? ''));

    if ($bookingId === '' || isset($currentBookingsById[$bookingId])) {
        continue;
    }

    $bookingDate = trim((string) ($booking['booking_date'] ?? ''));

    if ($bookingDate !== '' && $bookingDate < $today) {
        continue;
    }

    $latestChange = staff_bookings_latest_change_for_booking($changesByBooking, $bookingId);

    if (!is_array($latestChange)) {
        continue;
    }

    $action = (string) ($latestChange['action'] ?? '');
    $oldStaffId = trim((string) ($latestChange['old_staff_id'] ?? ''));
    $newStaffIdFromChange = trim((string) ($latestChange['new_staff_id'] ?? ''));

    if ($oldStaffId !== $sessionStaffId || !in_array($action, ['change_staff', 'detach_staff'], true)) {
        continue;
    }

    if ($action === 'change_staff' && $newStaffIdFromChange === $sessionStaffId) {
        continue;
    }

    $booking['employee_assignment_state'] = $action === 'detach_staff'
        ? 'detached_by_admin'
        : 'changed_by_admin';

    $booking['employee_assignment_message'] = $action === 'detach_staff'
        ? 'Administrator odłączył Cię od tej rezerwacji.'
        : 'Administrator zmienił personel do tej rezerwacji.';

    $booking['employee_assignment_changed_at'] = $latestChange['created_at'] ?? null;
    $booking['employee_assignment_old_staff_name'] = $latestChange['old_staff_name'] ?? null;
    $booking['employee_assignment_new_staff_name'] = $latestChange['new_staff_name'] ?? null;

    $currentBookingsById[$bookingId] = $booking;
}

$bookings = array_values($currentBookingsById);

usort($bookings, static function (array $a, array $b): int {
    $aDate = (string) ($a['booking_date'] ?? '');
    $bDate = (string) ($b['booking_date'] ?? '');

    if ($aDate !== $bDate) {
        return strcmp($aDate, $bDate);
    }

    return strcmp((string) ($a['booking_time'] ?? ''), (string) ($b['booking_time'] ?? ''));
});

staff_bookings_json([
    'success' => true,
    'bookings' => staff_bookings_public_bookings($bookings, $sessionTenantId, $sessionStaffId, $refSecret),
]);
