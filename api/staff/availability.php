<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_availability_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        http_response_code(500);
        echo '{"success":false,"error":"Nie udało się przygotować odpowiedzi JSON"}';
        exit;
    }

    echo $json;
    exit;
}

function staff_availability_require_admin_session(): array
{
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user)
        || empty($user['id'])
        || empty($user['tenant_id'])
    ) {
        staff_availability_json([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], 401);
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));

    if (!in_array($role, ['admin', 'administrator'], true)) {
        staff_availability_json([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], 401);
    }

    return $user;
}

function staff_availability_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    bool $returnRepresentation = false
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);
    $headers = array_values(array_filter($headers, static function (string $header) use ($returnRepresentation): bool {
        return !$returnRepresentation || stripos($header, 'Prefer:') !== 0;
    }));

    if ($returnRepresentation) {
        $headers[] = 'Prefer: return=representation';
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
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($jsonPayload === false) {
            curl_close($ch);
            staff_availability_json([
                'success' => false,
                'error' => 'Nie udało się przygotować danych JSON'
            ], 500);
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'response' => $response,
        'error' => $curlError,
        'httpCode' => $httpCode,
    ];
}

function staff_availability_select_fields(): string
{
    return implode(',', [
        'id',
        'staff_id',
        'weekday',
        'start_time',
        'end_time',
        'is_active',
        'created_at',
        'updated_at',
    ]);
}

function staff_availability_safe_record(array $row): array
{
    $record = [];
    $publicFields = [
        'weekday',
        'start_time',
        'end_time',
        'is_active',
        'created_at',
        'updated_at',
    ];

    foreach ($publicFields as $field) {
        if (array_key_exists($field, $row)) {
            $record[$field] = $row[$field];
        }
    }

    return $record;
}

function staff_availability_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
}

function staff_availability_validate_entries(array $availability): array
{
    $normalized = [];
    $seen = [];
    $byWeekday = [];

    foreach ($availability as $entry) {
        if (!is_array($entry)) {
            staff_availability_json([
                'success' => false,
                'error' => 'Nieprawidłowy wpis grafiku'
            ], 422);
        }

        $weekday = $entry['weekday'] ?? null;

        if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
            staff_availability_json([
                'success' => false,
                'error' => 'Nieprawidłowy dzień tygodnia'
            ], 422);
        }

        $startTime = trim((string) ($entry['start_time'] ?? ''));
        $endTime = trim((string) ($entry['end_time'] ?? ''));

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)
        ) {
            staff_availability_json([
                'success' => false,
                'error' => 'Nieprawidłowy format czasu'
            ], 422);
        }

        $startMinutes = staff_availability_time_to_minutes($startTime);
        $endMinutes = staff_availability_time_to_minutes($endTime);

        if ($startMinutes >= $endMinutes) {
            staff_availability_json([
                'success' => false,
                'error' => 'Godzina rozpoczęcia musi być wcześniejsza niż godzina zakończenia'
            ], 422);
        }

        $isActive = $entry['is_active'] ?? true;

        if (!is_bool($isActive)) {
            staff_availability_json([
                'success' => false,
                'error' => 'Nieprawidłowa wartość is_active'
            ], 422);
        }

        $duplicateKey = $weekday . '|' . $startTime . '|' . $endTime;

        if (isset($seen[$duplicateKey])) {
            staff_availability_json([
                'success' => false,
                'error' => 'Zduplikowany wpis grafiku'
            ], 422);
        }

        $seen[$duplicateKey] = true;

        foreach ($byWeekday[$weekday] ?? [] as $range) {
            if ($startMinutes < $range['end'] && $endMinutes > $range['start']) {
                staff_availability_json([
                    'success' => false,
                    'error' => 'Nakładające się przedziały grafiku'
                ], 422);
            }
        }

        $byWeekday[$weekday][] = [
            'start' => $startMinutes,
            'end' => $endMinutes,
        ];

        $normalized[] = [
            'weekday' => $weekday,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_active' => $isActive,
        ];
    }

    usort($normalized, static function (array $left, array $right): int {
        return [$left['weekday'], $left['start_time']] <=> [$right['weekday'], $right['start_time']];
    });

    return $normalized;
}

function staff_availability_ensure_staff_exists(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId
): void {
    $staffUrl = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&limit=1';

    $staffResult = staff_availability_request('GET', $staffUrl, $supabaseKey, $schema);

    if ($staffResult['response'] === false || $staffResult['error'] !== '') {
        staff_availability_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić pracownika'
        ], $staffResult['httpCode'] > 0 ? $staffResult['httpCode'] : 500);
    }

    $staffRows = json_decode((string) $staffResult['response'], true);

    if (!is_array($staffRows)) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowa odpowiedź bazy danych'
        ], 500);
    }

    if (empty($staffRows[0]['id'])) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nie znaleziono pracownika'
        ], 404);
    }
}

function staff_availability_normalize_staff_ref($value): string
{
    $staffRef = trim((string) ($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function staff_availability_resolve_staff_ref(
    $value,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): ?string {
    $staffRef = staff_availability_normalize_staff_ref($value);

    if ($staffRef === '') {
        return null;
    }

    $staffUrl = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $staffResult = staff_availability_request('GET', $staffUrl, $supabaseKey, $schema);

    if ($staffResult['response'] === false || $staffResult['error'] !== ''
        || $staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300
    ) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik.',
        ], 400);
    }

    $staffRows = json_decode((string) $staffResult['response'], true);

    if (!is_array($staffRows)) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik.',
        ], 400);
    }

    foreach ($staffRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string) ($row['id'] ?? ''));

        if ($staffId === '') {
            continue;
        }

        $expectedRef = public_response_staff_ref($tenantId, $staffId, $refSecret);

        if (hash_equals($expectedRef, $staffRef)) {
            return $staffId;
        }
    }

    staff_availability_json([
        'success' => false,
        'error' => 'Nieprawidłowy pracownik.',
    ], 400);
}

function staff_availability_resolve_staff_request_id(
    $staffRefValue,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): string {
    if (staff_availability_normalize_staff_ref($staffRefValue) !== '') {
        return (string) staff_availability_resolve_staff_ref(
            $staffRefValue,
            $supabaseUrl,
            $supabaseKey,
            $schema,
            $tenantId,
            $refSecret
        );
    }

    return '';
}

function staff_availability_read(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId
): array {
    $availabilityUrl = $supabaseUrl
        . '/rest/v1/staff_availability'
        . '?select=' . rawurlencode(staff_availability_select_fields())
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&order=weekday.asc'
        . '&order=start_time.asc';

    $availabilityResult = staff_availability_request('GET', $availabilityUrl, $supabaseKey, $schema);

    if ($availabilityResult['response'] === false || $availabilityResult['error'] !== '') {
        staff_availability_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($availabilityResult['httpCode'] < 200 || $availabilityResult['httpCode'] >= 300) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nie udało się pobrać grafiku pracownika'
        ], $availabilityResult['httpCode'] > 0 ? $availabilityResult['httpCode'] : 500);
    }

    $availability = json_decode((string) $availabilityResult['response'], true);

    if (!is_array($availability)) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowa odpowiedź bazy danych'
        ], 500);
    }

    return array_map('staff_availability_safe_record', $availability);
}

function staff_availability_read_for_weekday(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId,
    int $weekday
): array {
    $availabilityUrl = $supabaseUrl
        . '/rest/v1/staff_availability'
        . '?select=' . rawurlencode(staff_availability_select_fields())
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&weekday=eq.' . rawurlencode((string) $weekday)
        . '&is_active=eq.true'
        . '&order=start_time.asc';

    $availabilityResult = staff_availability_request('GET', $availabilityUrl, $supabaseKey, $schema);

    if ($availabilityResult['response'] === false || $availabilityResult['error'] !== '') {
        staff_availability_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($availabilityResult['httpCode'] < 200 || $availabilityResult['httpCode'] >= 300) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nie udało się pobrać grafiku pracownika'
        ], $availabilityResult['httpCode'] > 0 ? $availabilityResult['httpCode'] : 500);
    }

    $availability = json_decode((string) $availabilityResult['response'], true);

    if (!is_array($availability)) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nieprawidłowa odpowiedź bazy danych'
        ], 500);
    }

    return array_map('staff_availability_safe_record', $availability);
}

function staff_availability_read_calendar_interval(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): array {
    $calendarUrl = $supabaseUrl
        . '/rest/v1/calendar_settings'
        . '?select=consultation_duration,consultation_break'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $calendarResult = staff_availability_request('GET', $calendarUrl, $supabaseKey, $schema);

    if ($calendarResult['response'] === false || $calendarResult['error'] !== '') {
        staff_availability_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($calendarResult['httpCode'] < 200 || $calendarResult['httpCode'] >= 300) {
        staff_availability_json([
            'success' => false,
            'error' => 'Nie udało się pobrać ustawień kalendarza'
        ], $calendarResult['httpCode'] > 0 ? $calendarResult['httpCode'] : 500);
    }

    $calendarRows = json_decode((string) $calendarResult['response'], true);
    $calendarSettings = is_array($calendarRows) && is_array($calendarRows[0] ?? null)
        ? $calendarRows[0]
        : [];

    return [
        'duration' => max(1, (int) ($calendarSettings['consultation_duration'] ?? 60)),
        'break' => max(0, (int) ($calendarSettings['consultation_break'] ?? 0)),
    ];
}

function staff_availability_generate_slots(array $availability, int $duration, int $break): array
{
    $slots = [];
    $duration = max(1, $duration);
    $break = max(0, $break);

    foreach ($availability as $entry) {
        if (!is_array($entry) || empty($entry['is_active'])) {
            continue;
        }

        $start = substr((string) ($entry['start_time'] ?? ''), 0, 5);
        $end = substr((string) ($entry['end_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            continue;
        }

        $current = staff_availability_time_to_minutes($start);
        $endMinutes = staff_availability_time_to_minutes($end);

        while ($current + $duration <= $endMinutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
            $current += $duration + $break;
        }
    }

    $slots = array_values(array_unique($slots));
    sort($slots);

    return $slots;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    staff_availability_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$adminUser = staff_availability_require_admin_session();


$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');
$refSecret = public_response_ref_secret($supabaseKey);

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_availability_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_availability_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($adminUser['tenant_id'] ?? '');

if ($tenantId === '') {
    staff_availability_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

if (!tenant_has_feature($tenantId, 'staff_module')) {
    staff_availability_json([
        'success' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny w planie Pro. Twój abonament Pro wygasł albo konto działa w planie Free. Opłać abonament Pro, aby odzyskać dostęp do funkcji personelu.',
    ], 403);
}

if ($method === 'GET') {
    $staffId = staff_availability_resolve_staff_request_id(
        $_GET['staff_ref'] ?? null,
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $tenantId,
        $refSecret
    );
    $mode = trim((string) ($_GET['mode'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));

    if ($staffId === '') {
        staff_availability_json([
            'success' => false,
            'error' => 'Brak pracownika'
        ], 400);
    }

    staff_availability_ensure_staff_exists($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId);

    if ($mode === 'slots') {
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            staff_availability_json([
                'success' => false,
                'error' => 'Nieprawidłowy format daty'
            ], 400);
        }

        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
            staff_availability_json([
                'success' => false,
                'error' => 'Nieprawidłowy format daty'
            ], 400);
        }

        $weekday = (int) $dateObject->format('N');
        $availability = staff_availability_read_for_weekday(
            $supabaseUrl,
            $supabaseKey,
            $schema,
            $tenantId,
            $staffId,
            $weekday
        );
        $interval = staff_availability_read_calendar_interval($supabaseUrl, $supabaseKey, $schema, $tenantId);

        staff_availability_json([
            'success' => true,
            'staff_ref' => public_response_staff_ref($tenantId, $staffId, $refSecret),
            'date' => $date,
            'weekday' => $weekday,
            'availability' => $availability,
            'workingHours' => staff_availability_generate_slots($availability, $interval['duration'], $interval['break'])
        ]);
    }

    staff_availability_json([
        'success' => true,
        'staff_ref' => public_response_staff_ref($tenantId, $staffId, $refSecret),
        'availability' => staff_availability_read($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId)
    ]);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_availability_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON'
    ], 400);
}

$staffId = staff_availability_resolve_staff_request_id(
    $input['staff_ref'] ?? null,
    $supabaseUrl,
    $supabaseKey,
    $schema,
    $tenantId,
    $refSecret
);

if ($staffId === '') {
    staff_availability_json([
        'success' => false,
        'error' => 'Brak pracownika'
    ], 400);
}

if (!array_key_exists('availability', $input) || !is_array($input['availability'])) {
    staff_availability_json([
        'success' => false,
        'error' => 'Availability musi być tablicą'
    ], 422);
}

staff_availability_ensure_staff_exists($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId);

$availability = staff_availability_validate_entries($input['availability']);

$deleteUrl = $supabaseUrl
    . '/rest/v1/staff_availability'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId);

$deleteResult = staff_availability_request('DELETE', $deleteUrl, $supabaseKey, $schema);

if ($deleteResult['response'] === false || $deleteResult['error'] !== '') {
    staff_availability_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($deleteResult['httpCode'] < 200 || $deleteResult['httpCode'] >= 300) {
    staff_availability_json([
        'success' => false,
        'error' => 'Nie udało się usunąć starego grafiku'
    ], $deleteResult['httpCode'] > 0 ? $deleteResult['httpCode'] : 500);
}

if (empty($availability)) {
    staff_availability_json([
        'success' => true,
        'staff_ref' => public_response_staff_ref($tenantId, $staffId, $refSecret),
        'availability' => []
    ]);
}

$rowsToInsert = array_map(static function (array $entry) use ($tenantId, $staffId): array {
    return [
        'tenant_id' => $tenantId,
        'staff_id' => $staffId,
        'weekday' => $entry['weekday'],
        'start_time' => $entry['start_time'],
        'end_time' => $entry['end_time'],
        'is_active' => $entry['is_active'],
    ];
}, $availability);

$insertUrl = $supabaseUrl
    . '/rest/v1/staff_availability'
    . '?select=' . rawurlencode(staff_availability_select_fields());

$insertResult = staff_availability_request('POST', $insertUrl, $supabaseKey, $schema, $rowsToInsert, true);

if ($insertResult['response'] === false || $insertResult['error'] !== '') {
    staff_availability_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($insertResult['httpCode'] < 200 || $insertResult['httpCode'] >= 300) {
    staff_availability_json([
        'success' => false,
        'error' => 'Nie udało się zapisać grafiku pracownika'
    ], $insertResult['httpCode'] > 0 ? $insertResult['httpCode'] : 500);
}

$insertedAvailability = json_decode((string) $insertResult['response'], true);

if (!is_array($insertedAvailability)) {
    staff_availability_json([
        'success' => false,
        'error' => 'Nieprawidłowa odpowiedź bazy danych'
    ], 500);
}

staff_availability_json([
    'success' => true,
    'staff_ref' => public_response_staff_ref($tenantId, $staffId, $refSecret),
    'availability' => array_map('staff_availability_safe_record', $insertedAvailability)
]);
