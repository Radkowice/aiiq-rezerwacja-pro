<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

function staff_public_availability_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_public_availability_request(string $url, string $key, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
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

function staff_public_availability_feature_locked(): void
{
    staff_public_availability_json([
        'success' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny w planie Pro. Twój abonament Pro wygasł albo konto działa w planie Free. Opłać abonament Pro, aby odzyskać dostęp do funkcji personelu.',
    ], 403);
}

function staff_public_availability_is_uuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

function staff_public_availability_resolve_staff_ref(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffRef,
    string $secret
): ?string {
    if (preg_match('/^st_[a-f0-9]{32,64}$/', $staffRef) !== 1) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator personelu'
        ], 400);
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&limit=500';

    $result = staff_public_availability_request($url, $supabaseKey, $schema);

    if ($result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić personelu'
        ], 500);
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $candidateId = trim((string) ($row['id'] ?? ''));

        if ($candidateId !== '' && hash_equals(public_response_staff_ref($tenantId, $candidateId, $secret), $staffRef)) {
            return $candidateId;
        }
    }

    return null;
}

function staff_public_availability_resolve_service_ref(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $serviceRef,
    string $secret
): ?string {
    if (preg_match('/^svc_[a-f0-9]{32,64}$/', $serviceRef) !== 1) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator usługi'
        ], 400);
    }

    $url = $supabaseUrl
        . '/rest/v1/tenant_services'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&limit=500';

    $result = staff_public_availability_request($url, $supabaseKey, $schema);

    if ($result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić usługi'
        ], 500);
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $candidateId = trim((string) ($row['id'] ?? ''));

        if ($candidateId !== '' && hash_equals(public_response_service_ref($tenantId, $candidateId, $secret), $serviceRef)) {
            return $candidateId;
        }
    }

    return null;
}

function staff_public_availability_resolve_booking_ref(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $bookingRef,
    string $date,
    string $secret
): ?string {
    if (preg_match('/^bk_[a-f0-9]{32,64}$/', $bookingRef) !== 1) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator rezerwacji'
        ], 400);
    }

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=500';

    if ($date !== '') {
        $url .= '&booking_date=eq.' . rawurlencode($date);
    }

    $result = staff_public_availability_request($url, $supabaseKey, $schema);

    if ($result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić rezerwacji'
        ], 500);
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $candidateId = trim((string) ($row['id'] ?? ''));

        if ($candidateId !== '' && hash_equals(public_response_booking_ref($tenantId, $candidateId, $secret), $bookingRef)) {
            return $candidateId;
        }
    }

    return null;
}

function staff_public_availability_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
}

function staff_public_availability_ranges_overlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

function staff_public_availability_interval_end(int $start, array $settings): int
{
    return $start
        + max(1, (int) ($settings['consultation_duration'] ?? 60))
        + max(0, (int) ($settings['consultation_break'] ?? 0));
}

function staff_public_availability_nullable_int(array $row, string $key): ?int
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return (int) $row[$key];
}

function staff_public_availability_slots(array $availability, array $settings, ?string $date = null): array
{
    $duration = max(1, (int) ($settings['consultation_duration'] ?? 60));
    $break = max(0, (int) ($settings['consultation_break'] ?? 0));
    $weekday = null;

    if ($date !== null && $date !== '') {
        $timestamp = strtotime($date . ' 00:00:00');
        if ($timestamp !== false) {
            $weekday = (int) date('N', $timestamp);
        }
    }

    $slots = [];

    foreach ($availability as $entry) {
        if (!is_array($entry) || empty($entry['is_active'])) {
            continue;
        }

        if ($weekday !== null && (int) ($entry['weekday'] ?? 0) !== $weekday) {
            continue;
        }

        $start = (string) ($entry['start_time'] ?? '');
        $end = (string) ($entry['end_time'] ?? '');

        if (!preg_match('/^\d{2}:\d{2}/', $start) || !preg_match('/^\d{2}:\d{2}/', $end)) {
            continue;
        }

        $current = staff_public_availability_time_to_minutes(substr($start, 0, 5));
        $endMinutes = staff_public_availability_time_to_minutes(substr($end, 0, 5));

        while ($current + $duration <= $endMinutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
            $current += $duration + $break;
        }
    }

    $slots = array_values(array_unique($slots));
    sort($slots);

    return $slots;
}

function staff_public_availability_effective_min_notice_minutes(array $service, array $calendar): int
{
    $serviceBuffer = staff_public_availability_nullable_int($service, 'booking_buffer_minutes');
    $globalBuffer = staff_public_availability_nullable_int($calendar, 'booking_buffer');

    if ($serviceBuffer !== null && $serviceBuffer > 0) {
        return max(0, $serviceBuffer);
    }

    return max(0, (int) ($globalBuffer ?? 0));
}

function staff_public_availability_effective_settings(array $service, array $staff, array $calendar): array
{
    $serviceDuration = staff_public_availability_nullable_int($service, 'duration_minutes');
    $serviceBreak = staff_public_availability_nullable_int($service, 'break_minutes');

    $staffDuration = staff_public_availability_nullable_int($staff, 'service_duration_minutes');
    $staffBreak = staff_public_availability_nullable_int($staff, 'service_break_minutes');

    return [
        'consultation_duration' => max(1, (int) ($serviceDuration ?? $staffDuration ?? (int) ($calendar['consultation_duration'] ?? 60))),
        'consultation_break' => max(0, (int) ($serviceBreak ?? $staffBreak ?? (int) ($calendar['consultation_break'] ?? 0))),
        'booking_buffer' => staff_public_availability_effective_min_notice_minutes($service, $calendar),
    ];
}

function staff_public_availability_service_settings_map(
    array $bookings,
    string $tenantId,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    array $staff,
    array $calendar
): array {
    $serviceIds = [];

    foreach ($bookings as $booking) {
        if (!is_array($booking) || empty($booking['service_id'])) {
            continue;
        }

        $serviceIds[(string) $booking['service_id']] = true;
    }

    if (empty($serviceIds)) {
        return [];
    }

    $serviceUrl = $supabaseUrl
        . '/rest/v1/tenant_services'
        . '?select=id,duration_minutes,break_minutes,booking_buffer_minutes'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=in.(' . implode(',', array_map('rawurlencode', array_keys($serviceIds))) . ')';

    $serviceResult = staff_public_availability_request($serviceUrl, $supabaseKey, $schema);

    if ($serviceResult['error'] !== '' || $serviceResult['httpCode'] >= 400) {
        return [];
    }

    $settingsByService = [];
    $serviceRows = is_array($serviceResult['data'] ?? null) ? $serviceResult['data'] : [];

    foreach ($serviceRows as $serviceRow) {
        if (!is_array($serviceRow) || empty($serviceRow['id'])) {
            continue;
        }

        $settingsByService[(string) $serviceRow['id']] = staff_public_availability_effective_settings($serviceRow, $staff, $calendar);
    }

    return $settingsByService;
}

function staff_public_availability_occupied_intervals(array $bookings, array $settingsByService, array $fallbackSettings): array
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

        $serviceId = trim((string) ($booking['service_id'] ?? ''));
        $settings = isset($settingsByService[$serviceId]) && is_array($settingsByService[$serviceId])
            ? $settingsByService[$serviceId]
            : $fallbackSettings;
        $start = staff_public_availability_time_to_minutes($time);

        $intervals[] = [
            'start' => $start,
            'end' => staff_public_availability_interval_end($start, $settings),
        ];
    }

    return $intervals;
}

function staff_public_availability_slot_is_free(string $time, array $candidateSettings, array $occupiedIntervals): bool
{
    $candidateStart = staff_public_availability_time_to_minutes($time);
    $candidateEnd = staff_public_availability_interval_end($candidateStart, $candidateSettings);

    foreach ($occupiedIntervals as $interval) {
        if (!is_array($interval)) {
            continue;
        }

        if (staff_public_availability_ranges_overlap(
            $candidateStart,
            $candidateEnd,
            (int) ($interval['start'] ?? 0),
            (int) ($interval['end'] ?? 0)
        )) {
            return false;
        }
    }

    return true;
}

function staff_public_availability_blocked_time_overlaps(string $time, array $candidateSettings, array $blockedTimes): bool
{
    $candidateStart = staff_public_availability_time_to_minutes($time);
    $candidateEnd = staff_public_availability_interval_end($candidateStart, $candidateSettings);

    foreach ($blockedTimes as $blockedTime) {
        $blockTime = substr((string) $blockedTime, 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $blockTime)) {
            continue;
        }

        $blockStart = staff_public_availability_time_to_minutes($blockTime);
        $blockEnd = $blockStart + 60;

        if (staff_public_availability_ranges_overlap($candidateStart, $candidateEnd, $blockStart, $blockEnd)) {
            return true;
        }
    }

    return false;
}

function staff_public_availability_slot_respects_min_notice(string $date, string $time, int $minNoticeMinutes): bool
{
    $minNoticeMinutes = max(0, $minNoticeMinutes);

    if ($minNoticeMinutes <= 0) {
        return true;
    }

    $timezone = new DateTimeZone('Europe/Warsaw');
    $slotTime = substr($time, 0, 5);
    $slotDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $slotTime, $timezone);

    if (!$slotDateTime instanceof DateTimeImmutable || $slotDateTime->format('Y-m-d H:i') !== $date . ' ' . $slotTime) {
        return false;
    }

    $now = new DateTimeImmutable('now', $timezone);
    $minAllowedDateTime = $now->modify('+' . $minNoticeMinutes . ' minutes');

    return $slotDateTime >= $minAllowedDateTime;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_public_availability_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Nie znaleziono klienta dla tej domeny'
    ], 404);
}

$tenantId = (string) $tenantId;

if (!tenant_has_feature($tenantId, 'staff_module')) {
    staff_public_availability_feature_locked();
}

$staffRef = trim((string) ($_GET['staff_ref'] ?? ''));
$staffId = '';
$date = trim((string) ($_GET['date'] ?? ''));
$serviceRef = trim((string) ($_GET['service_ref'] ?? ''));
$serviceId = '';
$excludeBookingRef = trim((string) ($_GET['exclude_booking_ref'] ?? $_GET['booking_ref'] ?? ''));
$excludeBookingId = '';
$legacyExcludeBookingId = trim((string) ($_GET['exclude_booking_id'] ?? $_GET['reservation_id'] ?? ''));
$ignoreBookingBuffer = in_array(strtolower(trim((string) ($_GET['ignore_booking_buffer'] ?? ''))), ['1', 'true', 'yes'], true);

if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Nieprawidłowy format daty'
    ], 400);
}

$refSecret = public_response_ref_secret($supabaseKey);

if ($staffRef !== '') {
    $staffId = staff_public_availability_resolve_staff_ref($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffRef, $refSecret) ?? '';

    if ($staffId === '') {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik.'
        ], 404);
    }
} else {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Wybierz osobę obsługującą.'
    ], 400);
}

if ($serviceRef !== '') {
    $serviceId = staff_public_availability_resolve_service_ref($supabaseUrl, $supabaseKey, $schema, $tenantId, $serviceRef, $refSecret) ?? '';

    if ($serviceId === '') {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowa usługa.'
        ], 404);
    }
} else {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Nieprawidłowa usługa.'
    ], 400);
}

if ($legacyExcludeBookingId !== '') {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Nieprawidłowa rezerwacja do wykluczenia'
    ], 400);
}

if ($excludeBookingRef !== '') {
    $excludeBookingId = staff_public_availability_resolve_booking_ref($supabaseUrl, $supabaseKey, $schema, $tenantId, $excludeBookingRef, $date, $refSecret) ?? '';

    if ($excludeBookingId === '') {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nie znaleziono rezerwacji do wykluczenia'
        ], 404);
    }
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,display_name,service_duration_minutes,service_break_minutes'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&limit=1';

$staffResult = staff_public_availability_request($staffUrl, $supabaseKey, $schema);

if ($staffResult['error'] !== '' || $staffResult['httpCode'] >= 400) {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić personelu'
    ], 500);
}

$staff = is_array($staffResult['data'] ?? null) ? ($staffResult['data'][0] ?? null) : null;

if (!is_array($staff) || empty($staff['id'])) {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Nie znaleziono osoby'
    ], 404);
}

$staffRefForResponse = public_response_staff_ref($tenantId, (string) $staff['id'], $refSecret);

$selectedService = null;

if ($serviceId !== '') {
    $serviceUrl = $supabaseUrl
        . '/rest/v1/tenant_services'
        . '?select=id,duration_minutes,break_minutes,booking_buffer_minutes'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($serviceId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&limit=1';

    $serviceResult = staff_public_availability_request($serviceUrl, $supabaseKey, $schema);

    if ($serviceResult['error'] !== '' || $serviceResult['httpCode'] >= 400) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić usługi'
        ], 500);
    }

    $selectedService = is_array($serviceResult['data'] ?? null) ? ($serviceResult['data'][0] ?? null) : null;

    if (!is_array($selectedService) || empty($selectedService['id'])) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nie znaleziono usługi'
        ], 404);
    }

    $relationUrl = $supabaseUrl
        . '/rest/v1/tenant_service_staff'
        . '?select=staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=eq.' . rawurlencode($serviceId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&limit=1';

    $relationResult = staff_public_availability_request($relationUrl, $supabaseKey, $schema);

    if ($relationResult['error'] !== '' || $relationResult['httpCode'] >= 400) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić przypisania personelu'
        ], 500);
    }

    $relationRows = is_array($relationResult['data'] ?? null) ? $relationResult['data'] : [];

    if (empty($relationRows)) {
        staff_public_availability_json([
            'success' => false,
            'error' => 'Wybrana osoba nie obsługuje tej usługi'
        ], 409);
    }
}

$availabilityUrl = $supabaseUrl
    . '/rest/v1/staff_availability'
    . '?select=weekday,start_time,end_time,is_active'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&order=weekday.asc'
    . '&order=start_time.asc';

$availabilityResult = staff_public_availability_request($availabilityUrl, $supabaseKey, $schema);

if ($availabilityResult['error'] !== '' || $availabilityResult['httpCode'] >= 400) {
    staff_public_availability_json([
        'success' => false,
        'error' => 'Nie udało się pobrać dostępności'
    ], 500);
}

$availability = is_array($availabilityResult['data'] ?? null) ? $availabilityResult['data'] : [];

$calendarUrl = $supabaseUrl
    . '/rest/v1/calendar_settings'
    . '?select=consultation_duration,consultation_break,booking_buffer'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$calendarResult = staff_public_availability_request($calendarUrl, $supabaseKey, $schema);
$calendarSettings = is_array($calendarResult['data'] ?? null) ? ($calendarResult['data'][0] ?? []) : [];

$effectiveSettings = staff_public_availability_effective_settings(
    is_array($selectedService) ? $selectedService : [],
    $staff,
    is_array($calendarSettings) ? $calendarSettings : []
);

$availableTimes = [];

if ($date !== '') {
    $staffBlockFilter = '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

    $blockedDateUrl = $supabaseUrl
        . '/rest/v1/blocked_dates'
        . '?select=date'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . $staffBlockFilter
        . '&limit=1';

    $blockedDateResult = staff_public_availability_request($blockedDateUrl, $supabaseKey, $schema);
    $blockedDates = is_array($blockedDateResult['data'] ?? null) ? $blockedDateResult['data'] : [];

    if (!empty($blockedDates)) {
        staff_public_availability_json([
            'success' => true,
            'staff' => [
                'staff_ref' => $staffRefForResponse,
                'display_name' => (string) ($staff['display_name'] ?? ''),
            ],
            'availability' => array_map(static function (array $row): array {
                return [
                    'weekday' => (int) ($row['weekday'] ?? 0),
                    'start_time' => substr((string) ($row['start_time'] ?? ''), 0, 5),
                    'end_time' => substr((string) ($row['end_time'] ?? ''), 0, 5),
                    'is_active' => !empty($row['is_active']),
                ];
            }, $availability),
            'availableTimes' => [],
        ]);
    }

    $availableTimes = staff_public_availability_slots($availability, $effectiveSettings, $date);

    $blockedTimeUrl = $supabaseUrl
        . '/rest/v1/blocked_times'
        . '?select=time,staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . $staffBlockFilter;

    $blockedTimeResult = staff_public_availability_request($blockedTimeUrl, $supabaseKey, $schema);

    if ($blockedTimeResult['error'] === '' && $blockedTimeResult['httpCode'] < 400) {
        $globalBlockedTimes = [];
        $staffBlockedTimes = [];
        $timeRows = is_array($blockedTimeResult['data'] ?? null) ? $blockedTimeResult['data'] : [];

        foreach ($timeRows as $timeRow) {
            if (!is_array($timeRow) || empty($timeRow['time'])) {
                continue;
            }

            $blockedTime = substr((string) $timeRow['time'], 0, 5);

            if ($blockedTime === 'all') {
                $availableTimes = [];
                break;
            }

            $rowStaffId = trim((string) ($timeRow['staff_id'] ?? ''));

            if ($rowStaffId === '') {
                $globalBlockedTimes[] = $blockedTime;
            } else {
                $staffBlockedTimes[] = $blockedTime;
            }
        }

        if (!empty($globalBlockedTimes) || !empty($staffBlockedTimes)) {
            $globalBlockedTimes = array_values(array_unique($globalBlockedTimes));
            $staffBlockedTimes = array_values(array_unique($staffBlockedTimes));

            $availableTimes = array_values(array_filter(
                $availableTimes,
                static function (string $time) use ($effectiveSettings, $globalBlockedTimes, $staffBlockedTimes): bool {
                    return !staff_public_availability_blocked_time_overlaps($time, $effectiveSettings, $globalBlockedTimes)
                        && !staff_public_availability_blocked_time_overlaps($time, $effectiveSettings, $staffBlockedTimes);
                }
            ));
        }
    }

    if (!$ignoreBookingBuffer && (int) ($effectiveSettings['booking_buffer'] ?? 0) > 0) {
        $minNoticeMinutes = (int) $effectiveSettings['booking_buffer'];

        $availableTimes = array_values(array_filter($availableTimes, static function (string $time) use ($date, $minNoticeMinutes): bool {
            return staff_public_availability_slot_respects_min_notice($date, $time, $minNoticeMinutes);
        }));
    }

    $bookingUrl = $supabaseUrl
        . '/rest/v1/bookings'
        . '?select=id,booking_time,service_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&booking_date=eq.' . rawurlencode($date);

    if ($excludeBookingId !== '') {
        $bookingUrl .= '&id=neq.' . rawurlencode($excludeBookingId);
    }

    $bookingResult = staff_public_availability_request($bookingUrl, $supabaseKey, $schema);

    if ($bookingResult['error'] === '' && $bookingResult['httpCode'] < 400) {
        $bookings = is_array($bookingResult['data'] ?? null) ? $bookingResult['data'] : [];
        $settingsByService = staff_public_availability_service_settings_map(
            $bookings,
            $tenantId,
            $supabaseUrl,
            $supabaseKey,
            $schema,
            $staff,
            is_array($calendarSettings) ? $calendarSettings : []
        );
        $occupiedIntervals = staff_public_availability_occupied_intervals($bookings, $settingsByService, $effectiveSettings);

        if (!empty($occupiedIntervals)) {
            $availableTimes = array_values(array_filter(
                $availableTimes,
                static function (string $time) use ($effectiveSettings, $occupiedIntervals): bool {
                    return staff_public_availability_slot_is_free($time, $effectiveSettings, $occupiedIntervals);
                }
            ));
        }
    }
}

staff_public_availability_json([
    'success' => true,
    'staff' => [
        'staff_ref' => $staffRefForResponse,
        'display_name' => (string) ($staff['display_name'] ?? ''),
    ],
    'availability' => array_map(static function (array $row): array {
        return [
            'weekday' => (int) ($row['weekday'] ?? 0),
            'start_time' => substr((string) ($row['start_time'] ?? ''), 0, 5),
            'end_time' => substr((string) ($row['end_time'] ?? ''), 0, 5),
            'is_active' => !empty($row['is_active']),
        ];
    }, $availability),
    'availableTimes' => $availableTimes,
]);
