<?php
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (in_array($method, ['POST', 'DELETE'], true)) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();

    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
        json_response(['success' => false, 'error' => 'Brak autoryzacji'], 401);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../system/tenant.php';

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    json_response(['success' => false, 'error' => 'Brak konfiguracji Supabase'], 500);
}

$TENANT_ID = in_array($method, ['POST', 'DELETE'], true)
    ? (string)($_SESSION['user']['tenant_id'] ?? '')
    : (string)(getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA) ?: '');

if ($TENANT_ID === '') {
    json_response(['success' => false, 'error' => 'Nie rozpoznano tenant'], 400);
}

if (in_array($method, ['POST', 'DELETE'], true)
    && !session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA)
) {
    json_response(['success' => false, 'error' => 'Brak autoryzacji'], 403);
}

if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    json_response(['success' => false, 'error' => 'Metoda niedozwolona'], 405);
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function supabase_request(string $method, string $url, string $apiKey, string $schema, ?array $payload = null, array $headers = []): array
{
    $baseHeaders = [
        'apikey: ' . $apiKey,
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error,
        'body' => (string)$body,
        'data' => json_decode((string)$body, true),
    ];
}

function normalize_staff_id($value): ?string
{
    $staffId = trim((string)($value ?? ''));

    if ($staffId === '' || $staffId === 'null' || $staffId === 'undefined') {
        return null;
    }

    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $staffId)) {
        json_response(['success' => false, 'error' => 'Nieprawidłowy pracownik'], 400);
    }

    return $staffId;
}

function staff_filter(?string $staffId): string
{
    return $staffId === null
        ? '&staff_id=is.null'
        : '&staff_id=eq.' . rawurlencode($staffId);
}

function read_staff_filter(?string $staffId): string
{
    return $staffId === null
        ? '&staff_id=is.null'
        : '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';
}

function ensure_staff_belongs_to_tenant(?string $staffId, string $tenantId, string $supabaseUrl, string $apiKey, string $schema): void
{
    if ($staffId === null) {
        return;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&is_active=eq.true'
        . '&limit=1';

    $result = supabase_request('GET', $url, $apiKey, $schema);
    $rows = is_array($result['data']) ? $result['data'] : [];

    if (!$result['ok'] || empty($rows[0]['id'])) {
        json_response(['success' => false, 'error' => 'Nie znaleziono pracownika'], 404);
    }
}

function fail_supabase(string $label, array $result): void
{
    $data = is_array($result['data']) ? $result['data'] : [];
    $message = trim((string)($data['message'] ?? $data['details'] ?? $result['error'] ?? $result['body'] ?? ''));
    json_response([
        'success' => false,
        'error' => $message !== '' ? $label . ': ' . substr($message, 0, 400) : $label,
        'httpCode' => $result['status'] ?? 500,
    ], 500);
}

function push_time(array &$map, string $date, string $time): void
{
    if (!isset($map[$date])) {
        $map[$date] = [];
    }
    $map[$date][] = $time;
}

if ($method === 'DELETE') {
    $input = json_input();
    $date = trim((string)($input['date'] ?? ''));
    $time = trim((string)($input['time'] ?? ''));
    $deleteAllTimes = filter_var($input['deleteAllTimes'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $staffId = normalize_staff_id($input['staff_id'] ?? null);

    ensure_staff_belongs_to_tenant($staffId, $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'error' => 'Nieprawidłowa data'], 400);
    }

    $table = (!$deleteAllTimes && $time !== '' && $time !== 'all') ? 'blocked_times' : ($deleteAllTimes ? 'blocked_times' : 'blocked_dates');
    $query = 'tenant_id=eq.' . rawurlencode($TENANT_ID) . '&date=eq.' . rawurlencode($date) . staff_filter($staffId);

    if ($table === 'blocked_times' && !$deleteAllTimes) {
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            json_response(['success' => false, 'error' => 'Nieprawidłowa godzina'], 400);
        }
        $query .= '&time=eq.' . rawurlencode($time);
    }

    $result = supabase_request('DELETE', $SUPABASE_URL . '/rest/v1/' . $table . '?' . $query, $SUPABASE_KEY, $SUPABASE_SCHEMA);
    if (!$result['ok']) {
        fail_supabase('Błąd usuwania blokady', $result);
    }

    json_response(['success' => true]);
}

if ($method === 'POST') {
    $input = json_input();
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'saveBlockSettings') {
        $payload = [
            'tenant_id' => $TENANT_ID,
            'block_saturdays' => filter_var($input['block_saturdays'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'block_sundays' => filter_var($input['block_sundays'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'block_holidays' => filter_var($input['block_holidays'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        $result = supabase_request(
            'POST',
            $SUPABASE_URL . '/rest/v1/block_settings?on_conflict=tenant_id',
            $SUPABASE_KEY,
            $SUPABASE_SCHEMA,
            $payload,
            ['Prefer: resolution=merge-duplicates,return=representation']
        );

        if (!$result['ok']) {
            fail_supabase('Błąd zapisu ustawień blokad', $result);
        }

        json_response(['success' => true, 'blockSettings' => $payload]);
    }

    $date = trim((string)($input['date'] ?? ''));
    $time = trim((string)($input['time'] ?? ''));
    $allDay = filter_var($input['allDay'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $staffId = normalize_staff_id($input['staff_id'] ?? null);

    ensure_staff_belongs_to_tenant($staffId, $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'error' => 'Nieprawidłowa data'], 400);
    }

    $isTimeBlock = !$allDay && $time !== '';
    if ($isTimeBlock && !preg_match('/^\d{2}:\d{2}$/', $time)) {
        json_response(['success' => false, 'error' => 'Nieprawidłowa godzina'], 400);
    }

    $table = $isTimeBlock ? 'blocked_times' : 'blocked_dates';
    $payload = ['tenant_id' => $TENANT_ID, 'date' => $date, 'staff_id' => $staffId];
    if ($isTimeBlock) {
        $payload['time'] = $time;
    }

    $result = supabase_request('POST', $SUPABASE_URL . '/rest/v1/' . $table, $SUPABASE_KEY, $SUPABASE_SCHEMA, $payload, ['Prefer: return=minimal,resolution=ignore-duplicates']);
    if (!$result['ok'] && (int)$result['status'] !== 409) {
        fail_supabase('Nie udało się zapisać blokady', $result);
    }

    json_response(['success' => true]);
}

$requestedStaffId = normalize_staff_id($_GET['staff_id'] ?? null);
error_log('blocked.php GET staff_id: ' . json_encode($requestedStaffId));
ensure_staff_belongs_to_tenant($requestedStaffId, $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

$globalBlockedDates = [];
$staffBlockedDates = [];
$blockedDates = [];
$blockedDateScopes = [];

$datesUrl = $SUPABASE_URL
    . '/rest/v1/blocked_dates?select=date,staff_id'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . read_staff_filter($requestedStaffId);
$datesResult = supabase_request('GET', $datesUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);
if (!$datesResult['ok']) {
    fail_supabase('Błąd pobierania zablokowanych dni', $datesResult);
}

foreach ((is_array($datesResult['data']) ? $datesResult['data'] : []) as $row) {
    $date = (string)($row['date'] ?? '');
    if ($date === '') {
        continue;
    }

    $blockedDates[] = $date;
    if (empty($row['staff_id'])) {
        $globalBlockedDates[] = $date;
        $blockedDateScopes[$date] = isset($blockedDateScopes[$date]) && $blockedDateScopes[$date] !== 'global' ? 'both' : 'global';
    } else {
        $staffBlockedDates[] = $date;
        $blockedDateScopes[$date] = isset($blockedDateScopes[$date]) && $blockedDateScopes[$date] !== 'staff' ? 'both' : 'staff';
    }
}

$blockedTimes = [];
$globalBlockedTimes = [];
$staffBlockedTimes = [];
$blockedTimeScopes = [];

$timesUrl = $SUPABASE_URL
    . '/rest/v1/blocked_times?select=date,time,staff_id'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . read_staff_filter($requestedStaffId);
$timesResult = supabase_request('GET', $timesUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);
if (!$timesResult['ok']) {
    fail_supabase('Błąd pobierania zablokowanych godzin', $timesResult);
}

foreach ((is_array($timesResult['data']) ? $timesResult['data'] : []) as $row) {
    $date = (string)($row['date'] ?? '');
    $time = substr((string)($row['time'] ?? ''), 0, 5);
    if ($date === '' || $time === '') {
        continue;
    }

    push_time($blockedTimes, $date, $time);
    if (!isset($blockedTimeScopes[$date])) {
        $blockedTimeScopes[$date] = [];
    }

    if (empty($row['staff_id'])) {
        push_time($globalBlockedTimes, $date, $time);
        $blockedTimeScopes[$date][$time] = isset($blockedTimeScopes[$date][$time]) && $blockedTimeScopes[$date][$time] !== 'global' ? 'both' : 'global';
    } else {
        push_time($staffBlockedTimes, $date, $time);
        $blockedTimeScopes[$date][$time] = isset($blockedTimeScopes[$date][$time]) && $blockedTimeScopes[$date][$time] !== 'staff' ? 'both' : 'staff';
    }
}

foreach ([$blockedTimes, $globalBlockedTimes, $staffBlockedTimes] as &$map) {
    foreach ($map as $date => $times) {
        $map[$date] = array_values(array_unique($times));
    }
}
unset($map);

$blockSettings = ['block_saturdays' => false, 'block_sundays' => false, 'block_holidays' => false];
$settingsUrl = $SUPABASE_URL
    . '/rest/v1/block_settings?select=block_saturdays,block_sundays,block_holidays'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';
$settingsResult = supabase_request('GET', $settingsUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);
if ($settingsResult['ok'] && !empty($settingsResult['data'][0]) && is_array($settingsResult['data'][0])) {
    $row = $settingsResult['data'][0];
    $blockSettings = [
        'block_saturdays' => !empty($row['block_saturdays']),
        'block_sundays' => !empty($row['block_sundays']),
        'block_holidays' => !empty($row['block_holidays']),
    ];
}

$workingHours = [];
$calendarUrl = $SUPABASE_URL
    . '/rest/v1/calendar_settings?select=work_start,work_end,consultation_duration,consultation_break'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';
$calendarResult = supabase_request('GET', $calendarUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);
if ($calendarResult['ok'] && !empty($calendarResult['data'][0]) && is_array($calendarResult['data'][0])) {
    $row = $calendarResult['data'][0];
    $start = substr((string)($row['work_start'] ?? '10:00'), 0, 5);
    $end = substr((string)($row['work_end'] ?? '16:00'), 0, 5);
    $duration = max(1, (int)($row['consultation_duration'] ?? 60));
    $break = max(0, (int)($row['consultation_break'] ?? 0));

    if (preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end)) {
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $current = ($startHour * 60) + $startMinute;
        $endMinutes = ($endHour * 60) + $endMinute;

        while ($current + $duration <= $endMinutes) {
            $workingHours[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
            $current += $duration + $break;
        }
    }
}

$globalAvailabilityExceptions = [];
$staffAvailabilityExceptions = [];
$exceptionsUrl = $SUPABASE_URL
    . '/rest/v1/availability_exceptions?select=date,allow_booking,staff_id'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&allow_booking=eq.true'
    . read_staff_filter($requestedStaffId);
$exceptionsResult = supabase_request('GET', $exceptionsUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);
error_log('blocked.php availability exceptions status: ' . json_encode([
    'httpCode' => $exceptionsResult['status'] ?? null,
    'hasError' => !$exceptionsResult['ok'],
]));

if ($exceptionsResult['ok']) {
    foreach ((is_array($exceptionsResult['data']) ? $exceptionsResult['data'] : []) as $row) {
        $date = (string)($row['date'] ?? '');
        if ($date === '') {
            continue;
        }

        if (empty($row['staff_id'])) {
            $globalAvailabilityExceptions[] = $date;
        } else {
            $staffAvailabilityExceptions[] = $date;
        }
    }
}

json_response([
    'success' => true,
    'blockedDates' => array_values(array_unique($blockedDates)),
    'blockedTimes' => $blockedTimes,
    'globalBlockedDates' => array_values(array_unique($globalBlockedDates)),
    'staffBlockedDates' => array_values(array_unique($staffBlockedDates)),
    'globalBlockedTimes' => $globalBlockedTimes,
    'staffBlockedTimes' => $staffBlockedTimes,
    'blockedDateScopes' => $blockedDateScopes,
    'blockedTimeScopes' => $blockedTimeScopes,
    'availabilityExceptions' => array_values(array_unique($globalAvailabilityExceptions)),
    'globalAvailabilityExceptions' => array_values(array_unique($globalAvailabilityExceptions)),
    'staffAvailabilityExceptions' => array_values(array_unique($staffAvailabilityExceptions)),
    'blockSettings' => $blockSettings,
    'minDate' => date('Y-m-d'),
    'maxDate' => date('Y-m-t'),
    'workingHours' => $workingHours,
]);
