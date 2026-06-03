<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

const STAFF_SERVICE_SETTINGS_HOLIDAYS = [
    '01-01',
    '01-06',
    '05-01',
    '05-02',
    '05-03',
    '08-15',
    '11-01',
    '11-11',
    '12-24',
    '12-25',
    '12-26',
];

function staff_service_settings_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_service_settings_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    array $extraHeaders = []
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'response' => $response,
        'error' => $error,
        'httpCode' => $httpCode,
        'data' => json_decode((string) $response, true),
    ];
}

function staff_service_settings_fail_result(string $message, array $result): void
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $details = trim((string) ($data['message'] ?? $data['details'] ?? $result['error'] ?? ''));

    staff_service_settings_json([
        'success' => false,
        'error' => $details !== '' ? $message . ': ' . substr($details, 0, 400) : $message,
        'httpCode' => $result['httpCode'] ?? 500,
    ], 500);
}

function staff_service_settings_clear_session(): void
{
    unset($_SESSION['staff_user']);
}

function staff_service_settings_nullable_int(array $row, string $key): ?int
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return (int) $row[$key];
}

function staff_service_settings_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
}

function staff_service_settings_slots(array $availability, int $duration, int $break, string $date): array
{
    $timestamp = strtotime($date . ' 00:00:00');
    $weekday = $timestamp !== false ? (int) date('N', $timestamp) : null;
    $slots = [];

    foreach ($availability as $entry) {
        if (!is_array($entry) || empty($entry['is_active'])) {
            continue;
        }

        if ($weekday !== null && (int) ($entry['weekday'] ?? 0) !== $weekday) {
            continue;
        }

        $start = substr((string) ($entry['start_time'] ?? ''), 0, 5);
        $end = substr((string) ($entry['end_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            continue;
        }

        $current = staff_service_settings_time_to_minutes($start);
        $endMinutes = staff_service_settings_time_to_minutes($end);

        while ($current + $duration <= $endMinutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
            $current += $duration + $break;
        }
    }

    $slots = array_values(array_unique($slots));
    sort($slots);

    return $slots;
}

function staff_service_settings_source(?int $serviceValue, ?int $staffValue): string
{
    if ($serviceValue !== null) {
        return 'service';
    }

    if ($staffValue !== null) {
        return 'staff';
    }

    return 'global';
}

function staff_service_settings_effective_settings(array $service, array $staff, array $calendar): array
{
    $serviceDuration = staff_service_settings_nullable_int($service, 'duration_minutes');
    $serviceBreak = staff_service_settings_nullable_int($service, 'break_minutes');
    $serviceBuffer = staff_service_settings_nullable_int($service, 'booking_buffer_minutes');

    $staffDuration = staff_service_settings_nullable_int($staff, 'service_duration_minutes');
    $staffBreak = staff_service_settings_nullable_int($staff, 'service_break_minutes');
    $staffBuffer = staff_service_settings_nullable_int($staff, 'booking_buffer_minutes');

    $duration = $serviceDuration ?? $staffDuration ?? (int) ($calendar['consultation_duration'] ?? 60);
    $break = $serviceBreak ?? $staffBreak ?? (int) ($calendar['consultation_break'] ?? 0);
    $buffer = $serviceBuffer ?? $staffBuffer ?? (int) ($calendar['booking_buffer'] ?? 0);

    $sources = array_values(array_unique([
        staff_service_settings_source($serviceDuration, $staffDuration),
        staff_service_settings_source($serviceBreak, $staffBreak),
        staff_service_settings_source($serviceBuffer, $staffBuffer),
    ]));

    return [
        'duration' => max(1, (int) $duration),
        'break' => max(0, (int) $break),
        'buffer' => max(0, (int) $buffer),
        'settings_source' => implode('/', $sources),
    ];
}

function staff_service_settings_ranges_overlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

function staff_service_settings_time_block_overlaps(array $blockedTimes, int $candidateStart, int $candidateEnd): bool
{
    foreach (array_keys($blockedTimes) as $blockedTime) {
        $time = substr((string) $blockedTime, 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            continue;
        }

        $blockStart = staff_service_settings_time_to_minutes($time);
        $blockEnd = $blockStart + 60;

        if (staff_service_settings_ranges_overlap($candidateStart, $candidateEnd, $blockStart, $blockEnd)) {
            return true;
        }
    }

    return false;
}

function staff_service_settings_interval_end(int $start, array $settings): int
{
    return $start
        + max(1, (int) ($settings['duration'] ?? 60))
        + max(0, (int) ($settings['break'] ?? 0))
        + max(0, (int) ($settings['buffer'] ?? 0));
}

function staff_service_settings_reservation_payload(array $booking, string $time): array
{
    return [
        'name' => (string) ($booking['name'] ?? ''),
        'email' => (string) ($booking['email'] ?? ''),
        'phone' => (string) ($booking['phone'] ?? ''),
        'time' => $time,
        'service_id' => (string) ($booking['service_id'] ?? ''),
        'service' => (string) ($booking['service_name_snapshot'] ?? ''),
        'reschedule_count' => max(0, (int) ($booking['reschedule_count'] ?? 0)),
        'rescheduled_at' => (string) ($booking['rescheduled_at'] ?? ''),
    ];
}

function staff_service_settings_service_map(array $servicesRows, array $staff, array $calendar): array
{
    $map = [];

    foreach ($servicesRows as $service) {
        if (!is_array($service) || empty($service['id'])) {
            continue;
        }

        $map[(string) $service['id']] = [
            'service' => $service,
            'settings' => staff_service_settings_effective_settings($service, $staff, $calendar),
        ];
    }

    return $map;
}

function staff_service_settings_fallback_settings(array $staff, array $calendar): array
{
    return staff_service_settings_effective_settings([], $staff, $calendar);
}

function staff_service_settings_occupied_intervals(array $bookings, array $servicesById, array $fallbackSettings): array
{
    $intervals = [];

    foreach ($bookings as $booking) {
        if (!is_array($booking)) {
            continue;
        }

        $time = substr((string) ($booking['booking_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            continue;
        }

        $bookingServiceId = trim((string) ($booking['service_id'] ?? ''));
        $settings = isset($servicesById[$bookingServiceId]['settings']) && is_array($servicesById[$bookingServiceId]['settings'])
            ? $servicesById[$bookingServiceId]['settings']
            : $fallbackSettings;
        $start = staff_service_settings_time_to_minutes($time);

        $intervals[] = [
            'booking_id' => (string) ($booking['id'] ?? ''),
            'service_id' => $bookingServiceId,
            'start' => $start,
            'end' => staff_service_settings_interval_end($start, $settings),
            'reservation' => staff_service_settings_reservation_payload($booking, $time),
        ];
    }

    return $intervals;
}

function staff_service_settings_time_set(array $rows, ?string $staffScope): array
{
    $times = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowStaffId = $row['staff_id'] ?? null;
        $isGlobal = $rowStaffId === null || $rowStaffId === '';
        $matchesScope = $staffScope === null ? $isGlobal : (string) $rowStaffId === $staffScope;

        if (!$matchesScope) {
            continue;
        }

        $time = substr((string) ($row['time'] ?? ''), 0, 5);

        if ($time !== '') {
            $times[$time] = true;
        }
    }

    return $times;
}

function staff_service_settings_has_date_block(array $rows, ?string $staffScope): bool
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowStaffId = $row['staff_id'] ?? null;
        $isGlobal = $rowStaffId === null || $rowStaffId === '';

        if ($staffScope === null && $isGlobal) {
            return true;
        }

        if ($staffScope !== null && (string) $rowStaffId === $staffScope) {
            return true;
        }
    }

    return false;
}

function staff_service_settings_slot_status(
    string $time,
    array $occupiedIntervals,
    string $serviceId,
    array $serviceSettings,
    bool $hasGlobalDateBlock,
    bool $hasGlobalRuleBlock,
    bool $hasStaffDateBlock,
    array $globalBlockedTimes,
    array $staffBlockedTimes,
    int $buffer,
    string $date
): array {
    $candidateStart = staff_service_settings_time_to_minutes($time);
    $candidateEnd = staff_service_settings_interval_end($candidateStart, $serviceSettings);
    $hasGlobalTimeBlock = staff_service_settings_time_block_overlaps($globalBlockedTimes, $candidateStart, $candidateEnd);

    if ($hasGlobalDateBlock || $hasGlobalTimeBlock) {
        return [
            'time' => $time,
            'status' => 'blocked_global',
            'block_source' => $hasGlobalDateBlock ? 'global_date' : 'global_time',
        ];
    }

    if ($hasGlobalRuleBlock) {
        return [
            'time' => $time,
            'status' => 'blocked_global',
            'block_source' => 'global_rule',
        ];
    }

    if ($hasStaffDateBlock || isset($staffBlockedTimes[$time])) {
        return [
            'time' => $time,
            'status' => 'blocked_staff',
            'block_source' => $hasStaffDateBlock ? 'staff_date' : 'staff_time',
        ];
    }

    if ($date === date('Y-m-d') && $buffer > 0) {
        $minAllowedMinutes = ((int) date('H') * 60) + (int) date('i') + $buffer;

        if (staff_service_settings_time_to_minutes($time) < $minAllowedMinutes) {
            return [
                'time' => $time,
                'status' => 'blocked_global',
                'block_source' => 'booking_buffer',
            ];
        }
    }

    foreach ($occupiedIntervals as $interval) {
        if (!is_array($interval)) {
            continue;
        }

        $busyStart = (int) ($interval['start'] ?? 0);
        $busyEnd = (int) ($interval['end'] ?? 0);

        if (!staff_service_settings_ranges_overlap($candidateStart, $candidateEnd, $busyStart, $busyEnd)) {
            continue;
        }

        $sameService = $serviceId !== '' && (string) ($interval['service_id'] ?? '') === $serviceId;
        $sameStart = $candidateStart === $busyStart;

        if ($sameService && $sameStart && is_array($interval['reservation'] ?? null)) {
            return [
                'time' => $time,
                'status' => 'reserved',
                'reservation' => $interval['reservation'],
            ];
        }

        return [
            'time' => $time,
            'status' => 'staff_busy',
        ];
    }

    return [
        'time' => $time,
        'status' => 'available',
    ];
}

function staff_service_settings_month_bounds(string $date): array
{
    $timestamp = strtotime($date . ' 00:00:00');

    if ($timestamp === false) {
        $timestamp = time();
    }

    return [
        date('Y-m-01', $timestamp),
        date('Y-m-t', $timestamp),
    ];
}

function staff_service_settings_date_range(string $start, string $end): array
{
    $startTimestamp = strtotime($start . ' 00:00:00');
    $endTimestamp = strtotime($end . ' 00:00:00');

    if ($startTimestamp === false || $endTimestamp === false || $startTimestamp > $endTimestamp) {
        return [];
    }

    $dates = [];

    for ($timestamp = $startTimestamp; $timestamp <= $endTimestamp; $timestamp = strtotime('+1 day', $timestamp)) {
        if ($timestamp === false) {
            break;
        }

        $dates[] = date('Y-m-d', $timestamp);
    }

    return $dates;
}

function staff_service_settings_is_global_rule_blocked(
    string $date,
    array $blockSettings,
    array $globalAvailabilityExceptions,
    array $staffAvailabilityExceptions
): bool {
    $timestamp = strtotime($date . ' 00:00:00');

    if ($timestamp === false) {
        return false;
    }

    $weekday = (int) date('w', $timestamp);
    $monthDay = date('m-d', $timestamp);
    $isBlockedByRule = (!empty($blockSettings['block_saturdays']) && $weekday === 6)
        || (!empty($blockSettings['block_sundays']) && $weekday === 0)
        || (!empty($blockSettings['block_holidays']) && in_array($monthDay, STAFF_SERVICE_SETTINGS_HOLIDAYS, true));

    if (!$isBlockedByRule) {
        return false;
    }

    return !in_array($date, $globalAvailabilityExceptions, true)
        && !in_array($date, $staffAvailabilityExceptions, true);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_service_settings_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], 405);
}

$date = trim((string) ($_GET['date'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Nieprawidłowy format daty.',
    ], 400);
}

$staffSession = $_SESSION['staff_user'] ?? null;

if (!is_array($staffSession) || empty($staffSession['account_id']) || empty($staffSession['tenant_id']) || empty($staffSession['staff_id'])) {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Brak aktywnej sesji personelu.',
    ], 401);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ], 500);
}

$hostTenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);
$tenantId = (string) ($staffSession['tenant_id'] ?? '');
$staffId = (string) ($staffSession['staff_id'] ?? '');
$accountId = (string) ($staffSession['account_id'] ?? '');

if (!$hostTenantId || !hash_equals($tenantId, (string) $hostTenantId)) {
    staff_service_settings_clear_session();
    staff_service_settings_json([
        'success' => false,
        'error' => 'Sesja personelu nie pasuje do domeny.',
    ], 401);
}

require_tenant_feature($tenantId, 'staff_module');

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,tenant_id,staff_id,email,is_active'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($accountId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&limit=1';

$accountResult = staff_service_settings_request('GET', $accountUrl, $supabaseKey, $schema);
$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];

if ($accountResult['response'] === false || $accountResult['error'] !== '' || $accountResult['httpCode'] >= 400) {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić sesji personelu.',
    ], 500);
}

if (empty($accountRows[0]['id'])) {
    staff_service_settings_clear_session();
    staff_service_settings_json([
        'success' => false,
        'error' => 'Sesja personelu jest nieaktywna.',
    ], 401);
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,display_name,service_duration_minutes,service_break_minutes,booking_buffer_minutes,is_active,visible_on_front'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&visible_on_front=eq.true'
    . '&limit=1';

$staffResult = staff_service_settings_request('GET', $staffUrl, $supabaseKey, $schema);
$staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = is_array($staffRows[0] ?? null) ? $staffRows[0] : null;

if ($staffResult['response'] === false || $staffResult['error'] !== '' || $staffResult['httpCode'] >= 400) {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Nie udało się pobrać profilu personelu.',
    ], 500);
}

if (!is_array($staff) || empty($staff['id'])) {
    staff_service_settings_json([
        'success' => true,
        'date' => $date,
        'services' => [],
    ]);
}

$relationsUrl = $supabaseUrl
    . '/rest/v1/tenant_service_staff'
    . '?select=service_id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId);

$relationsResult = staff_service_settings_request('GET', $relationsUrl, $supabaseKey, $schema);

if ($relationsResult['response'] === false || $relationsResult['error'] !== '' || $relationsResult['httpCode'] >= 400) {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Nie udało się pobrać przypisanych usług.',
    ], 500);
}

$serviceIds = [];

foreach ((is_array($relationsResult['data'] ?? null) ? $relationsResult['data'] : []) as $row) {
    if (is_array($row) && !empty($row['service_id'])) {
        $serviceIds[(string) $row['service_id']] = true;
    }
}

if (empty($serviceIds)) {
    staff_service_settings_json([
        'success' => true,
        'date' => $date,
        'services' => [],
    ]);
}

$serviceIdList = implode(',', array_map('rawurlencode', array_keys($serviceIds)));
$servicesUrl = $supabaseUrl
    . '/rest/v1/tenant_services'
    . '?select=id,name,description,duration_minutes,break_minutes,booking_buffer_minutes,sort_order'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=in.(' . $serviceIdList . ')'
    . '&is_active=eq.true'
    . '&visible_on_front=eq.true'
    . '&order=sort_order.asc'
    . '&order=name.asc';

$servicesResult = staff_service_settings_request('GET', $servicesUrl, $supabaseKey, $schema);

if ($servicesResult['response'] === false || $servicesResult['error'] !== '' || $servicesResult['httpCode'] >= 400) {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Nie udało się pobrać usług.',
    ], 500);
}

$servicesRows = is_array($servicesResult['data'] ?? null) ? $servicesResult['data'] : [];

$availabilityUrl = $supabaseUrl
    . '/rest/v1/staff_availability'
    . '?select=weekday,start_time,end_time,is_active'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&order=weekday.asc'
    . '&order=start_time.asc';

$availabilityResult = staff_service_settings_request('GET', $availabilityUrl, $supabaseKey, $schema);

if ($availabilityResult['response'] === false || $availabilityResult['error'] !== '' || $availabilityResult['httpCode'] >= 400) {
    staff_service_settings_json([
        'success' => false,
        'error' => 'Nie udało się pobrać dostępności personelu.',
    ], 500);
}

$availability = is_array($availabilityResult['data'] ?? null) ? $availabilityResult['data'] : [];

$calendarUrl = $supabaseUrl
    . '/rest/v1/calendar_settings'
    . '?select=consultation_duration,consultation_break,booking_buffer'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$calendarResult = staff_service_settings_request('GET', $calendarUrl, $supabaseKey, $schema);
$calendarRows = is_array($calendarResult['data'] ?? null) ? $calendarResult['data'] : [];
$calendarSettings = is_array($calendarRows[0] ?? null) ? $calendarRows[0] : [];

$blockedDateUrl = $supabaseUrl
    . '/rest/v1/blocked_dates'
    . '?select=date,staff_id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&date=eq.' . rawurlencode($date)
    . '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

$blockedDateResult = staff_service_settings_request('GET', $blockedDateUrl, $supabaseKey, $schema);

if ($blockedDateResult['response'] === false || $blockedDateResult['error'] !== '' || $blockedDateResult['httpCode'] >= 400) {
    staff_service_settings_fail_result('Nie udało się pobrać blokad dni personelu', $blockedDateResult);
}

$blockedDateRows = is_array($blockedDateResult['data'] ?? null) ? $blockedDateResult['data'] : [];

$blockedTimeUrl = $supabaseUrl
    . '/rest/v1/blocked_times'
    . '?select=date,time,staff_id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&date=eq.' . rawurlencode($date)
    . '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

$blockedTimeResult = staff_service_settings_request('GET', $blockedTimeUrl, $supabaseKey, $schema);

if ($blockedTimeResult['response'] === false || $blockedTimeResult['error'] !== '' || $blockedTimeResult['httpCode'] >= 400) {
    staff_service_settings_fail_result('Nie udało się pobrać blokad godzin personelu', $blockedTimeResult);
}

$blockedTimeRows = is_array($blockedTimeResult['data'] ?? null) ? $blockedTimeResult['data'] : [];

$bookingsUrl = $supabaseUrl
    . '/rest/v1/bookings'
    . '?select=id,booking_date,booking_time,name,email,phone,service_id,service_name_snapshot,reschedule_count,rescheduled_at'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&booking_date=eq.' . rawurlencode($date);

$bookingsResult = staff_service_settings_request('GET', $bookingsUrl, $supabaseKey, $schema);
$bookingRows = is_array($bookingsResult['data'] ?? null) ? $bookingsResult['data'] : [];

[$monthStart, $monthEnd] = staff_service_settings_month_bounds($date);

$monthBookingsUrl = $supabaseUrl
    . '/rest/v1/bookings'
    . '?select=booking_date,reschedule_count,rescheduled_at'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&booking_date=gte.' . rawurlencode($monthStart)
    . '&booking_date=lte.' . rawurlencode($monthEnd);

$monthBookingsResult = staff_service_settings_request('GET', $monthBookingsUrl, $supabaseKey, $schema);
$monthBookingRows = is_array($monthBookingsResult['data'] ?? null) ? $monthBookingsResult['data'] : [];

$monthBlockedDateUrl = $supabaseUrl
    . '/rest/v1/blocked_dates'
    . '?select=date,staff_id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&date=gte.' . rawurlencode($monthStart)
    . '&date=lte.' . rawurlencode($monthEnd)
    . '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

$monthBlockedDateResult = staff_service_settings_request('GET', $monthBlockedDateUrl, $supabaseKey, $schema);

if ($monthBlockedDateResult['response'] === false || $monthBlockedDateResult['error'] !== '' || $monthBlockedDateResult['httpCode'] >= 400) {
    staff_service_settings_fail_result('Nie udało się pobrać miesięcznych blokad dni personelu', $monthBlockedDateResult);
}

$monthBlockedDateRows = is_array($monthBlockedDateResult['data'] ?? null) ? $monthBlockedDateResult['data'] : [];

$monthBlockedTimeUrl = $supabaseUrl
    . '/rest/v1/blocked_times'
    . '?select=date,staff_id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&date=gte.' . rawurlencode($monthStart)
    . '&date=lte.' . rawurlencode($monthEnd)
    . '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

$monthBlockedTimeResult = staff_service_settings_request('GET', $monthBlockedTimeUrl, $supabaseKey, $schema);

if ($monthBlockedTimeResult['response'] === false || $monthBlockedTimeResult['error'] !== '' || $monthBlockedTimeResult['httpCode'] >= 400) {
    staff_service_settings_fail_result('Nie udało się pobrać miesięcznych blokad godzin personelu', $monthBlockedTimeResult);
}

$monthBlockedTimeRows = is_array($monthBlockedTimeResult['data'] ?? null) ? $monthBlockedTimeResult['data'] : [];

$blockSettings = [
    'block_saturdays' => false,
    'block_sundays' => false,
    'block_holidays' => false,
];
$blockSettingsUrl = $supabaseUrl
    . '/rest/v1/block_settings'
    . '?select=block_saturdays,block_sundays,block_holidays'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$blockSettingsResult = staff_service_settings_request('GET', $blockSettingsUrl, $supabaseKey, $schema);

if ($blockSettingsResult['response'] === false || $blockSettingsResult['error'] !== '' || $blockSettingsResult['httpCode'] >= 400) {
    staff_service_settings_fail_result('Nie udało się pobrać globalnych ustawień blokad', $blockSettingsResult);
}

$blockSettingsRows = is_array($blockSettingsResult['data'] ?? null) ? $blockSettingsResult['data'] : [];

if (is_array($blockSettingsRows[0] ?? null)) {
    $blockSettings = [
        'block_saturdays' => !empty($blockSettingsRows[0]['block_saturdays']),
        'block_sundays' => !empty($blockSettingsRows[0]['block_sundays']),
        'block_holidays' => !empty($blockSettingsRows[0]['block_holidays']),
    ];
}

$availabilityExceptionsUrl = $supabaseUrl
    . '/rest/v1/availability_exceptions'
    . '?select=date,allow_booking,staff_id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&allow_booking=eq.true'
    . '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

$availabilityExceptionsResult = staff_service_settings_request('GET', $availabilityExceptionsUrl, $supabaseKey, $schema);

if (
    $availabilityExceptionsResult['response'] === false
    || $availabilityExceptionsResult['error'] !== ''
    || $availabilityExceptionsResult['httpCode'] >= 400
) {
    staff_service_settings_fail_result('Nie udało się pobrać wyjątków dostępności', $availabilityExceptionsResult);
}

$globalAvailabilityExceptions = [];
$staffAvailabilityExceptions = [];
$availabilityExceptionRows = is_array($availabilityExceptionsResult['data'] ?? null) ? $availabilityExceptionsResult['data'] : [];

foreach ($availabilityExceptionRows as $row) {
    if (!is_array($row) || empty($row['date'])) {
        continue;
    }

    $exceptionDate = (string) $row['date'];

    if (empty($row['staff_id'])) {
        $globalAvailabilityExceptions[$exceptionDate] = true;
    } elseif ((string) $row['staff_id'] === $staffId) {
        $staffAvailabilityExceptions[$exceptionDate] = true;
    }
}

$globalAvailabilityExceptionDates = array_keys($globalAvailabilityExceptions);
$staffAvailabilityExceptionDates = array_keys($staffAvailabilityExceptions);

$calendarDays = [];

foreach ($monthBookingRows as $row) {
    if (!is_array($row) || empty($row['booking_date'])) {
        continue;
    }

    $calendarDate = (string) $row['booking_date'];
    $calendarDays[$calendarDate] ??= [
        'has_reserved' => false,
        'has_staff_block' => false,
        'has_staff_date_block' => false,
        'has_staff_time_block' => false,
        'has_global_block' => false,
        'has_global_date_block' => false,
        'has_global_time_block' => false,
        'has_rescheduled' => false,
        'max_reschedule_count' => 0,
    ];
    $calendarDays[$calendarDate]['has_reserved'] = true;
    $bookingRescheduleCount = max(0, (int) ($row['reschedule_count'] ?? 0));

    if ($bookingRescheduleCount > 0 || !empty($row['rescheduled_at'])) {
        $calendarDays[$calendarDate]['has_rescheduled'] = true;
        $calendarDays[$calendarDate]['max_reschedule_count'] = max(
            (int) ($calendarDays[$calendarDate]['max_reschedule_count'] ?? 0),
            $bookingRescheduleCount
        );
    }
}

foreach ($monthBlockedDateRows as $row) {
    if (!is_array($row) || empty($row['date'])) {
        continue;
    }

    $calendarDate = (string) $row['date'];
    $calendarDays[$calendarDate] ??= [
        'has_reserved' => false,
        'has_staff_block' => false,
        'has_staff_date_block' => false,
        'has_staff_time_block' => false,
        'has_global_block' => false,
        'has_global_date_block' => false,
        'has_global_time_block' => false,
        'has_rescheduled' => false,
        'max_reschedule_count' => 0,
    ];

    if (empty($row['staff_id'])) {
        $calendarDays[$calendarDate]['has_global_block'] = true;
        $calendarDays[$calendarDate]['has_global_date_block'] = true;
    } elseif ((string) $row['staff_id'] === $staffId) {
        $calendarDays[$calendarDate]['has_staff_block'] = true;
        $calendarDays[$calendarDate]['has_staff_date_block'] = true;
    }
}

foreach ($monthBlockedTimeRows as $row) {
    if (!is_array($row) || empty($row['date'])) {
        continue;
    }

    $calendarDate = (string) $row['date'];
    $calendarDays[$calendarDate] ??= [
        'has_reserved' => false,
        'has_staff_block' => false,
        'has_staff_date_block' => false,
        'has_staff_time_block' => false,
        'has_global_block' => false,
        'has_global_date_block' => false,
        'has_global_time_block' => false,
        'has_rescheduled' => false,
        'max_reschedule_count' => 0,
    ];

    if (empty($row['staff_id'])) {
        $calendarDays[$calendarDate]['has_global_block'] = true;
        $calendarDays[$calendarDate]['has_global_time_block'] = true;
    } elseif ((string) $row['staff_id'] === $staffId) {
        $calendarDays[$calendarDate]['has_staff_block'] = true;
        $calendarDays[$calendarDate]['has_staff_time_block'] = true;
    }
}

foreach (staff_service_settings_date_range($monthStart, $monthEnd) as $calendarDate) {
    if (!staff_service_settings_is_global_rule_blocked(
        $calendarDate,
        $blockSettings,
        $globalAvailabilityExceptionDates,
        $staffAvailabilityExceptionDates
    )) {
        continue;
    }

    $calendarDays[$calendarDate] ??= [
        'has_reserved' => false,
        'has_staff_block' => false,
        'has_staff_date_block' => false,
        'has_staff_time_block' => false,
        'has_global_block' => false,
        'has_global_date_block' => false,
        'has_global_time_block' => false,
        'has_rescheduled' => false,
        'max_reschedule_count' => 0,
    ];
    $calendarDays[$calendarDate]['has_global_block'] = true;
    $calendarDays[$calendarDate]['has_global_date_block'] = true;
    $calendarDays[$calendarDate]['has_global_rule_block'] = true;
}

$hasGlobalDateBlock = staff_service_settings_has_date_block($blockedDateRows, null);
$hasGlobalRuleBlock = staff_service_settings_is_global_rule_blocked(
    $date,
    $blockSettings,
    $globalAvailabilityExceptionDates,
    $staffAvailabilityExceptionDates
);
$hasStaffDateBlock = staff_service_settings_has_date_block($blockedDateRows, $staffId);
$globalBlockedTimes = staff_service_settings_time_set($blockedTimeRows, null);
$staffBlockedTimes = staff_service_settings_time_set($blockedTimeRows, $staffId);
$servicesById = staff_service_settings_service_map($servicesRows, $staff, $calendarSettings);
$fallbackBookingSettings = staff_service_settings_fallback_settings($staff, $calendarSettings);
$occupiedIntervals = staff_service_settings_occupied_intervals($bookingRows, $servicesById, $fallbackBookingSettings);

$services = [];

foreach ($servicesRows as $service) {
    if (!is_array($service) || empty($service['id'])) {
        continue;
    }

    $serviceId = (string) $service['id'];
    $effective = isset($servicesById[$serviceId]['settings']) && is_array($servicesById[$serviceId]['settings'])
        ? $servicesById[$serviceId]['settings']
        : staff_service_settings_effective_settings($service, $staff, $calendarSettings);
    $times = staff_service_settings_slots($availability, $effective['duration'], $effective['break'], $date);
    $slots = [];

    foreach ($times as $time) {
        if ($date === date('Y-m-d') && $effective['buffer'] > 0) {
            $minAllowedMinutes = ((int) date('H') * 60) + (int) date('i') + $effective['buffer'];

            if (staff_service_settings_time_to_minutes($time) < $minAllowedMinutes) {
                continue;
            }
        }

        $slots[] = staff_service_settings_slot_status(
            $time,
            $occupiedIntervals,
            $serviceId,
            $effective,
            $hasGlobalDateBlock,
            $hasGlobalRuleBlock,
            $hasStaffDateBlock,
            $globalBlockedTimes,
            $staffBlockedTimes,
            $effective['buffer'],
            $date
        );
    }

    $services[] = [
        'service_id' => $serviceId,
        'name' => (string) ($service['name'] ?? 'Usługa'),
        'duration' => $effective['duration'],
        'break' => $effective['break'],
        'buffer' => $effective['buffer'],
        'settings_source' => $effective['settings_source'],
        'slots' => $slots,
    ];
}

staff_service_settings_json([
    'success' => true,
    'date' => $date,
    'calendar_days' => $calendarDays,
    'services' => $services,
]);
