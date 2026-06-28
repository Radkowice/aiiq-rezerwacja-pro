<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/booking_availability.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

date_default_timezone_set('Europe/Warsaw');

function availability_month_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function availability_month_error(string $message, string $code = 'availability_month_error', int $statusCode = 400): void
{
    availability_month_json([
        'success' => false,
        'message' => $message,
        'code' => $code,
    ], $statusCode);
}

function availability_month_request(string $method, string $url, string $key, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => supabaseHeaders($key, $schema),
        CURLOPT_CONNECTTIMEOUT => 5,
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
        'data' => json_decode((string) $response, true),
    ];
}

function availability_month_fetch_rows(string $supabaseUrl, string $key, string $schema, string $table, array $query): ?array
{
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . $table . '?' . implode('&', $query);
    $result = availability_month_request('GET', $url, $key, $schema);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        return null;
    }

    return is_array($result['data'] ?? null) ? $result['data'] : [];
}

function availability_month_fetch_single(string $supabaseUrl, string $key, string $schema, string $table, array $query): ?array
{
    $rows = availability_month_fetch_rows($supabaseUrl, $key, $schema, $table, array_merge($query, ['limit=1']));
    return is_array($rows[0] ?? null) ? $rows[0] : null;
}

function availability_month_is_service_ref(string $value): bool
{
    return preg_match('/^svc_[a-f0-9]{32,64}$/', $value) === 1;
}

function availability_month_service_id_from_ref(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    string $serviceRef,
    string $refSecret
): string {
    if (!availability_month_is_service_ref($serviceRef)) {
        availability_month_error('Wybrana usĹ‚uga jest niedostÄ™pna.', 'service_not_found', 404);
    }

    $serviceRows = availability_month_fetch_rows($supabaseUrl, $key, $schema, 'tenant_services', [
        'select=id',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'is_active=eq.true',
        'visible_on_front=eq.true',
    ]);

    if (!is_array($serviceRows)) {
        availability_month_error('Nie udaĹ‚o siÄ™ sprawdziÄ‡ usĹ‚ugi.', 'service_lookup_failed', 500);
    }

    foreach ($serviceRows as $serviceRow) {
        if (!is_array($serviceRow) || empty($serviceRow['id'])) {
            continue;
        }

        $serviceId = (string) $serviceRow['id'];
        $expectedRef = public_response_service_ref($tenantId, $serviceId, $refSecret);

        if (hash_equals($expectedRef, $serviceRef)) {
            return $serviceId;
        }
    }

    availability_month_error('Wybrana usĹ‚uga jest niedostÄ™pna.', 'service_not_found', 404);
}

function availability_month_validate_token(string $token): void
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        availability_month_error('Link do przełożenia rezerwacji jest nieprawidłowy.', 'invalid_token', 400);
    }
}

function availability_month_validate_month(string $month): void
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        availability_month_error('Wybierz poprawny miesiąc.', 'invalid_month', 400);
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('Europe/Warsaw'));

    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m') !== $month) {
        availability_month_error('Wybierz poprawny miesiąc.', 'invalid_month', 400);
    }
}

function availability_month_normalize_time(string $time): string
{
    $time = substr(trim($time), 0, 5);
    return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '';
}

function availability_month_booking_start(string $date, string $time): ?DateTimeImmutable
{
    $time = availability_month_normalize_time($time);

    if ($date === '' || $time === '') {
        return null;
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone('Europe/Warsaw'));
    return $start instanceof DateTimeImmutable ? $start : null;
}

function availability_month_timestamp_is_future(string $value): bool
{
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    try {
        return new DateTimeImmutable($value) > new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
    } catch (Throwable $e) {
        return false;
    }
}

function availability_month_reschedule_limit_reached(array $booking): bool
{
    return max(0, (int) ($booking['reschedule_count'] ?? 0)) >= 3;
}

function availability_month_range_limits(array $calendar): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw'));
    $startOffset = max(0, (int) ($calendar['booking_start_month_offset'] ?? 0));
    $monthRange = max(1, (int) ($calendar['booking_month_range'] ?? 1));
    $minMonth = $today->modify('first day of this month')->modify('+' . $startOffset . ' months');
    $maxDate = $minMonth->modify('+' . ($monthRange - 1) . ' months')->modify('last day of this month');
    $minDate = $minMonth > $today ? $minMonth : $today;

    return [$minDate, $maxDate];
}

function availability_month_dates_for_month(string $month, array $calendar): array
{
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('Europe/Warsaw'));

    if (!$monthStart instanceof DateTimeImmutable) {
        return [];
    }

    $monthEnd = $monthStart->modify('last day of this month');
    [$minDate, $maxDate] = availability_month_range_limits($calendar);
    $start = $monthStart < $minDate ? $minDate : $monthStart;
    $end = $monthEnd > $maxDate ? $maxDate : $monthEnd;

    if ($start > $end) {
        return [];
    }

    $dates = [];
    $cursor = $start;

    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $dates;
}

function availability_month_index_blocked_times(array $rows): array
{
    $map = [];

    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['date']) || empty($row['time'])) {
            continue;
        }

        $date = (string) $row['date'];
        $map[$date] ??= [];
        $map[$date][] = [
            'time' => substr((string) $row['time'], 0, 5),
            'staff_id' => (string) ($row['staff_id'] ?? ''),
        ];
    }

    return $map;
}

function availability_month_index_bookings(array $rows): array
{
    $map = [];

    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['booking_date'])) {
            continue;
        }

        $date = (string) $row['booking_date'];
        $map[$date] ??= [];
        $map[$date][] = $row;
    }

    return $map;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    availability_month_error('Metoda niedozwolona.', 'method_not_allowed', 405);
}

$month = trim((string) ($_GET['month'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));
$isRescheduleMode = $token !== '';

availability_month_validate_month($month);

if ($isRescheduleMode) {
    availability_month_validate_token($token);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    availability_month_error('Nie udało się wczytać konfiguracji systemu.', 'configuration_error', 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    availability_month_error('Nie znaleziono klienta dla tej domeny.', 'tenant_not_found', 404);
}

$tenantId = (string) $tenantId;
$booking = [];
$serviceId = '';
$staffId = '';
$bookingId = '';

if ($isRescheduleMode) {
    if (!tenant_has_feature($tenantId, 'reschedule_booking')) {
        availability_month_error('Funkcja przełożenia rezerwacji jest dostępna w wyższych planach.', 'feature_unavailable', 403);
    }

    $booking = availability_month_fetch_single($supabaseUrl, $supabaseKey, $schema, 'bookings', [
        'select=' . rawurlencode('id,booking_date,booking_time,service_id,staff_id,reschedule_count,manage_token_expires_at'),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'manage_token=eq.' . rawurlencode($token),
    ]);

    if (!$booking) {
        availability_month_error('Nie znaleziono rezerwacji albo link jest nieprawidłowy.', 'booking_not_found', 404);
    }

    if (availability_month_reschedule_limit_reached($booking)) {
        availability_month_error('Nie możesz już samodzielnie zmienić terminu tej rezerwacji. Skontaktuj się z obsługą.', 'reschedule_limit_reached', 409);
    }

    if (!availability_month_timestamp_is_future((string) ($booking['manage_token_expires_at'] ?? ''))) {
        availability_month_error('Nie można już przełożyć tej rezerwacji, ponieważ termin już się rozpoczął lub minął.', 'token_expired', 410);
    }

    $bookingStart = availability_month_booking_start((string) ($booking['booking_date'] ?? ''), (string) ($booking['booking_time'] ?? ''));

    if (!$bookingStart || $bookingStart <= new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'))) {
        availability_month_error('Nie można już przełożyć tej rezerwacji, ponieważ termin już się rozpoczął lub minął.', 'booking_already_started', 410);
    }

    $staffId = trim((string) ($booking['staff_id'] ?? ''));
    $serviceId = trim((string) ($booking['service_id'] ?? ''));
    $bookingId = (string) ($booking['id'] ?? '');
} else {
    $staffId = '';
    $serviceRef = trim((string) ($_GET['service_ref'] ?? ''));

    if ($serviceRef !== '') {
        $serviceId = availability_month_service_id_from_ref(
            $supabaseUrl,
            $supabaseKey,
            $schema,
            $tenantId,
            $serviceRef,
            public_response_ref_secret($supabaseKey)
        );
    } else {
        availability_month_error('Wybrana usługa jest niedostępna.', 'service_not_found', 404);
    }

    $bookingId = '';
}

$calendar = availability_month_fetch_single($supabaseUrl, $supabaseKey, $schema, 'calendar_settings', [
    'select=' . rawurlencode('work_start,work_end,consultation_duration,consultation_break,booking_buffer,booking_start_month_offset,booking_month_range'),
    'tenant_id=eq.' . rawurlencode($tenantId),
]) ?? [];

$dates = availability_month_dates_for_month($month, $calendar);

$service = [];

if ($serviceId !== '') {
    $serviceQuery = [
        'select=' . rawurlencode('id,duration_minutes,break_minutes,booking_buffer_minutes'),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=eq.' . rawurlencode($serviceId),
    ];

    if (!$isRescheduleMode) {
        $serviceQuery[] = 'is_active=eq.true';
        $serviceQuery[] = 'visible_on_front=eq.true';
    }

    $service = availability_month_fetch_single($supabaseUrl, $supabaseKey, $schema, 'tenant_services', $serviceQuery) ?? [];

    if (!$isRescheduleMode && empty($service['id'])) {
        availability_month_error('Wybrana usługa jest niedostępna.', 'service_not_found', 404);
    }
}

$staff = [];

if ($staffId !== '') {
    $staffQuery = [
        'select=' . rawurlencode('id,service_duration_minutes,service_break_minutes,booking_buffer_minutes,is_active'),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=eq.' . rawurlencode($staffId),
        'is_active=eq.true',
    ];

    $staff = availability_month_fetch_single($supabaseUrl, $supabaseKey, $schema, 'staff_profiles', $staffQuery) ?? [];

    if (empty($staff['id'])) {
        availability_month_error('Osoba obsługująca tę rezerwację jest niedostępna.', 'staff_not_found', 404);
    }

    if ($serviceId !== '') {
        $relation = availability_month_fetch_single($supabaseUrl, $supabaseKey, $schema, 'tenant_service_staff', [
            'select=staff_id',
            'tenant_id=eq.' . rawurlencode($tenantId),
            'service_id=eq.' . rawurlencode($serviceId),
            'staff_id=eq.' . rawurlencode($staffId),
        ]);

        if (!$relation) {
            availability_month_error('Osoba obsługująca nie obsługuje tej usługi.', 'staff_service_mismatch', 409);
        }
    }
}

$settings = booking_availability_effective_settings($service, $staff, $calendar);

if ($staffId !== '') {
    $ranges = availability_month_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'staff_availability', [
        'select=weekday,start_time,end_time,is_active',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'staff_id=eq.' . rawurlencode($staffId),
        'is_active=eq.true',
    ]);

    if (!is_array($ranges)) {
        availability_month_error('Nie udało się pobrać dostępności osoby obsługującej.', 'availability_error', 500);
    }

    $staffFilter = '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';
} else {
    $ranges = [[
        'start_time' => substr((string) ($calendar['work_start'] ?? '09:00'), 0, 5),
        'end_time' => substr((string) ($calendar['work_end'] ?? '17:00'), 0, 5),
        'is_active' => true,
    ]];
    $staffFilter = '&staff_id=is.null';
}

$monthStart = $month . '-01';
$monthEnd = (new DateTimeImmutable($monthStart, new DateTimeZone('Europe/Warsaw')))->modify('last day of this month')->format('Y-m-d');

$blockSettingsRow = availability_month_fetch_single($supabaseUrl, $supabaseKey, $schema, 'block_settings', [
    'select=' . rawurlencode('block_saturdays,block_sundays,block_holidays'),
    'tenant_id=eq.' . rawurlencode($tenantId),
]) ?? [];
$blockSettings = [
    'block_saturdays' => !empty($blockSettingsRow['block_saturdays'] ?? false),
    'block_sundays' => !empty($blockSettingsRow['block_sundays'] ?? false),
    'block_holidays' => !empty($blockSettingsRow['block_holidays'] ?? false),
];

$blockedDateRows = availability_month_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'blocked_dates', [
    'select=date,staff_id',
    'tenant_id=eq.' . rawurlencode($tenantId),
    'date=gte.' . rawurlencode($monthStart),
    'date=lte.' . rawurlencode($monthEnd) . $staffFilter,
]) ?? [];
$blockedDates = array_values(array_unique(array_map(static fn (array $row): string => (string) ($row['date'] ?? ''), $blockedDateRows)));

$blockedTimeRows = availability_month_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'blocked_times', [
    'select=date,time,staff_id',
    'tenant_id=eq.' . rawurlencode($tenantId),
    'date=gte.' . rawurlencode($monthStart),
    'date=lte.' . rawurlencode($monthEnd) . $staffFilter,
]) ?? [];
$blockedTimes = availability_month_index_blocked_times($blockedTimeRows);

$exceptionRows = availability_month_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'availability_exceptions', [
    'select=date,allow_booking,staff_id',
    'tenant_id=eq.' . rawurlencode($tenantId),
    'date=gte.' . rawurlencode($monthStart),
    'date=lte.' . rawurlencode($monthEnd) . $staffFilter,
    'allow_booking=eq.true',
]) ?? [];
$availabilityExceptions = array_values(array_unique(array_map(static fn (array $row): string => (string) ($row['date'] ?? ''), $exceptionRows)));

$bookingQuery = [
    'select=id,booking_date,booking_time,service_id',
    'tenant_id=eq.' . rawurlencode($tenantId),
    'booking_date=gte.' . rawurlencode($monthStart),
    'booking_date=lte.' . rawurlencode($monthEnd),
];
$bookingQuery[] = $staffId !== '' ? 'staff_id=eq.' . rawurlencode($staffId) : 'staff_id=is.null';
$bookingRows = availability_month_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'bookings', $bookingQuery) ?? [];
$bookingsByDate = availability_month_index_bookings($bookingRows);
$ignoredBlockedTimes = [];

if ($staffId === '') {
    $oldDate = trim((string) ($booking['booking_date'] ?? ''));
    $oldTime = availability_month_normalize_time((string) ($booking['booking_time'] ?? ''));

    if ($oldDate !== '' && $oldTime !== '') {
        $ignoredBlockedTimes[$oldDate] = [$oldTime];
    }
}

$serviceIds = [];

foreach ($bookingRows as $row) {
    if (is_array($row) && !empty($row['service_id'])) {
        $serviceIds[(string) $row['service_id']] = true;
    }
}

$settingsByService = [];

if (!empty($serviceIds)) {
    $serviceRows = availability_month_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'tenant_services', [
        'select=' . rawurlencode('id,duration_minutes,break_minutes,booking_buffer_minutes'),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=in.(' . implode(',', array_map('rawurlencode', array_keys($serviceIds))) . ')',
    ]) ?? [];

    foreach ($serviceRows as $row) {
        if (is_array($row) && !empty($row['id'])) {
            $settingsByService[(string) $row['id']] = booking_availability_effective_settings($row, $staff, $calendar);
        }
    }
}

$days = [];

foreach ($dates as $date) {
    $occupied = booking_availability_occupied_intervals($bookingsByDate[$date] ?? [], $settingsByService, $settings, $bookingId);
    $times = booking_availability_times_for_day(
        $date,
        $ranges,
        $settings,
        $blockSettings,
        $blockedDates,
        $blockedTimes,
        $availabilityExceptions,
        $occupied,
        $ignoredBlockedTimes
    );

    $days[$date] = [
        'available' => count($times) > 0,
        'times_count' => count($times),
    ];
}

availability_month_json([
    'success' => true,
    'month' => $month,
    'days' => $days,
]);
