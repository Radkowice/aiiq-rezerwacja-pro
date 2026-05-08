<?php
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (in_array($method, ['POST', 'DELETE'], true)) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();
 
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    respond([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$isAdmin = isset($_GET['admin']) || isset($_SERVER['HTTP_X_ADMIN']);

if ($isAdmin && !isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Brak dostępu'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

require_once __DIR__ . '/../system/tenant.php';
$TENANT_ID = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

if (!$TENANT_ID) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nie rozpoznano tenant z domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($method, ['POST', 'DELETE'], true)
    && !session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA)
) {
    respond([
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ], 403);
}

/**
 * Uniwersalny request do Supabase / PostgREST.
 */
function supabase_request(string $method, string $url, string $apiKey, string $schema, array $headers = [], ?array $payload = null): array
{
    $ch = curl_init($url);

    $baseHeaders = [
        "apikey: {$apiKey}",
        "Authorization: Bearer {$apiKey}",
        "Accept-Profile: {$schema}",
        "Content-Profile: {$schema}"
    ];

    $allHeaders = array_merge($baseHeaders, $headers);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'response' => $response,
        'error' => $error,
        'httpCode' => $httpCode
    ];
}

function cleanup_past_blocks_in_supabase(string $supabaseUrl, string $apiKey, string $schema, string $tenantId): void
{
    $today = date('Y-m-d');

    $urlDates = $supabaseUrl
        . '/rest/v1/blocked_dates?tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=lt.' . rawurlencode($today);

    $deleteDates = supabase_request('DELETE', $urlDates, $apiKey, $schema);

    if ($deleteDates['error']) {
        error_log('cleanup blocked_dates error: request_failed');
    } elseif (($deleteDates['httpCode'] ?? 0) >= 400) {
        error_log('cleanup blocked_dates error: http_' . (string)($deleteDates['httpCode'] ?? 0));
    }

    $urlTimes = $supabaseUrl
        . '/rest/v1/blocked_times?tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=lt.' . rawurlencode($today);

    $deleteTimes = supabase_request('DELETE', $urlTimes, $apiKey, $schema);

    if ($deleteTimes['error']) {
        error_log('cleanup blocked_times error: request_failed');
    } elseif (($deleteTimes['httpCode'] ?? 0) >= 400) {
        error_log('cleanup blocked_times error: http_' . (string)($deleteTimes['httpCode'] ?? 0));
    }
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function supabase_error_response(string $label, array $result, int $status = 500): void
{
    respond([
        'success' => false,
        'error' => $label
    ], $status);
}

// =======================
// DELETE › usuwanie blokady
// =======================
if ($method === 'DELETE') {
    $input = json_input();

    $date = trim((string)($input['date'] ?? ''));
    $time = trim((string)($input['time'] ?? ''));
    $deleteAllTimes = filter_var($input['deleteAllTimes'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$date) {
        respond([
            'success' => false,
            'error' => 'Brak daty'
        ], 400);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond([
            'success' => false,
            'error' => 'Nieprawidłowa data.'
        ], 400);
    }

    if ($time !== '' && $time !== 'all' && !preg_match('/^\d{2}:\d{2}$/', $time)) {
        respond([
            'success' => false,
            'error' => 'Nieprawidłowa godzina.'
        ], 400);
    }

    if ($deleteAllTimes) {
        $table = 'blocked_times';
        $query = 'tenant_id=eq.' . rawurlencode($TENANT_ID)
            . '&date=eq.' . rawurlencode($date);
    } else {
        $isTimeBlock = !empty($time) && $time !== 'all';
        $table = $isTimeBlock ? 'blocked_times' : 'blocked_dates';

        $query = 'tenant_id=eq.' . rawurlencode($TENANT_ID)
            . '&date=eq.' . rawurlencode($date);

        if ($isTimeBlock) {
            $query .= '&time=eq.' . rawurlencode($time);
        }
    }

    $url = $SUPABASE_URL . "/rest/v1/{$table}?{$query}";

    $result = supabase_request('DELETE', $url, $SUPABASE_KEY, $SUPABASE_SCHEMA);

    if ($result['error']) {
        supabase_error_response('CURL delete error', $result);
    }

    if ($result['httpCode'] >= 400) {
        supabase_error_response('Supabase delete error', $result);
    }

    respond([
        'success' => true
    ]);
}

// =======================
// POST › zapis ustawień lub blokady
// =======================
if ($method === 'POST') {
    $input = json_input();
    $action = trim((string)($input['action'] ?? ''));

    if ($action !== '' && $action !== 'saveBlockSettings') {
        respond([
            'success' => false,
            'error' => 'Nieprawidłowa akcja.'
        ], 400);
    }

    // zapis ustawień globalnych
    if ($action === 'saveBlockSettings') {
        $payload = [
            'tenant_id' => $TENANT_ID,
            'block_saturdays' => filter_var($input['block_saturdays'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'block_sundays' => filter_var($input['block_sundays'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'block_holidays' => filter_var($input['block_holidays'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];

        $checkUrl = $SUPABASE_URL
            . '/rest/v1/block_settings?select=id&tenant_id=eq.'
            . rawurlencode($TENANT_ID)
            . '&limit=1';

        $check = supabase_request('GET', $checkUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);

        if ($check['error']) {
            supabase_error_response('CURL settings read error', $check);
        }

        if ($check['httpCode'] >= 400) {
            supabase_error_response('Supabase settings read error', $check);
        }

        $checkData = json_decode($check['response'], true);
        if (!is_array($checkData)) {
            $checkData = [];
        }

        $hasExisting = is_array($checkData)
            && !empty($checkData)
            && !empty($checkData[0]['id']);

        if ($hasExisting) {
            $url = $SUPABASE_URL . '/rest/v1/block_settings?tenant_id=eq.' . rawurlencode($TENANT_ID);
            $methodCurl = 'PATCH';
        } else {
            $url = $SUPABASE_URL . '/rest/v1/block_settings';
            $methodCurl = 'POST';
        }

        $save = supabase_request(
            $methodCurl,
            $url,
            $SUPABASE_KEY,
            $SUPABASE_SCHEMA,
            [
                'Content-Type: application/json',
                'Prefer: return=representation'
            ],
            $payload
        );

        if ($save['error']) {
            supabase_error_response('CURL settings save error', $save);
        }

        if ($save['httpCode'] >= 400) {
            supabase_error_response('Supabase settings save error', $save);
        }

        respond([
            'success' => true,
            'blockSettings' => [
                'block_saturdays' => $payload['block_saturdays'],
                'block_sundays' => $payload['block_sundays'],
                'block_holidays' => $payload['block_holidays']
            ]
        ]);
    }

    // zwykłe blokady daty / godziny
    $date = trim((string)($input['date'] ?? ''));
    $time = trim((string)($input['time'] ?? ''));
    $allDay = filter_var($input['allDay'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$date) {
        respond([
            'success' => false,
            'error' => 'Brak daty'
        ], 400);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond([
            'success' => false,
            'error' => 'Nieprawidłowa data.'
        ], 400);
    }

    if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) {
        respond([
            'success' => false,
            'error' => 'Nieprawidłowa godzina.'
        ], 400);
    }

    $isTimeBlock = !$allDay && !empty($time);
    $table = $isTimeBlock ? 'blocked_times' : 'blocked_dates';

    $payload = [
        'tenant_id' => $TENANT_ID,
        'date' => $date
    ];

    if ($isTimeBlock) {
        $payload['time'] = $time;
    }

    $url = $SUPABASE_URL . "/rest/v1/{$table}";

    $insert = supabase_request(
        'POST',
        $url,
        $SUPABASE_KEY,
        $SUPABASE_SCHEMA,
        [
            'Content-Type: application/json',
            'Prefer: return=minimal,resolution=ignore-duplicates'
        ],
        $payload
    );

    if ($insert['error']) {
        supabase_error_response('CURL insert error', $insert);
    }

    if ($insert['httpCode'] >= 400) {
        supabase_error_response('Supabase insert error', $insert);
    }

    respond([
        'success' => true
    ]);
}

// =======================
// GET › pobieranie blokad + ustawień
// =======================
cleanup_past_blocks_in_supabase(
    $SUPABASE_URL,
    $SUPABASE_KEY,
    $SUPABASE_SCHEMA,
    $TENANT_ID
);

$blockedDates = [];
$blockedTimes = [];
$blockSettings = [
    'block_saturdays' => false,
    'block_sundays' => false,
    'block_holidays' => false
];

// całe dni
$urlDates = $SUPABASE_URL . '/rest/v1/blocked_dates?select=date&tenant_id=eq.' . rawurlencode($TENANT_ID);
$getDates = supabase_request('GET', $urlDates, $SUPABASE_KEY, $SUPABASE_SCHEMA);

if ($getDates['error']) {
    supabase_error_response('CURL dates error', $getDates);
}

if ($getDates['httpCode'] >= 400) {
    supabase_error_response('Supabase dates error', $getDates);
}

$dataDates = json_decode($getDates['response'], true);
if (!is_array($dataDates)) {
    $dataDates = [];
}

foreach ($dataDates as $row) {
    if (!empty($row['date'])) {
        $blockedDates[] = $row['date'];
    }
}

// godziny
$urlTimes = $SUPABASE_URL . '/rest/v1/blocked_times?select=date,time&tenant_id=eq.' . rawurlencode($TENANT_ID);
$getTimes = supabase_request('GET', $urlTimes, $SUPABASE_KEY, $SUPABASE_SCHEMA);

if ($getTimes['error']) {
    supabase_error_response('CURL times error', $getTimes);
}

if ($getTimes['httpCode'] >= 400) {
    supabase_error_response('Supabase times error', $getTimes);
}

$dataTimes = json_decode($getTimes['response'], true);
if (!is_array($dataTimes)) {
    $dataTimes = [];
}

foreach ($dataTimes as $row) {
    $date = $row['date'] ?? null;
    $time = $row['time'] ?? null;

    if ($date && $time) {
        if (!isset($blockedTimes[$date])) {
            $blockedTimes[$date] = [];
        }
        $blockedTimes[$date][] = $time;
    }
}

// ustawienia globalne blokad
$urlSettings = $SUPABASE_URL
    . '/rest/v1/block_settings?select=block_saturdays,block_sundays,block_holidays&tenant_id=eq.'
    . rawurlencode($TENANT_ID)
    . '&limit=1';

$getSettings = supabase_request('GET', $urlSettings, $SUPABASE_KEY, $SUPABASE_SCHEMA);

// Uwaga: błąd odczytu block_settings NIE może wywalić całego panelu admina.
// Poprzednia wersja działała właśnie dlatego, że w razie problemu zwracała dalej blockedDates/blockedTimes,
// a checkboxy po prostu zostawały na false.
if (!$getSettings['error'] && $getSettings['httpCode'] < 400) {
    $dataSettings = json_decode($getSettings['response'], true);

    if (is_array($dataSettings) && !empty($dataSettings[0])) {
        $row = $dataSettings[0];

        $blockSettings = [
            'block_saturdays' => !empty($row['block_saturdays']),
            'block_sundays' => !empty($row['block_sundays']),
            'block_holidays' => !empty($row['block_holidays'])
        ];
    }
}

foreach ($blockedTimes as $date => $times) {
    $blockedTimes[$date] = array_values(array_unique($times));
}

// ===== GENEROWANIE GODZIN Z SETTINGS =====
$settingsUrl = $SUPABASE_URL
    . '/rest/v1/calendar_settings?select=work_start,work_end,consultation_duration,consultation_break&tenant_id=eq.'
    . rawurlencode($TENANT_ID)
    . '&limit=1';

$getSettings2 = supabase_request('GET', $settingsUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);

$workingHours = [];

if (!$getSettings2['error'] && $getSettings2['httpCode'] < 400) {
    $dataSettings2 = json_decode($getSettings2['response'], true);

    if (is_array($dataSettings2) && !empty($dataSettings2[0])) {
        $s = $dataSettings2[0];

        $start = $s['work_start'] ?? '10:00';
        $end = $s['work_end'] ?? '16:00';
        $duration = (int)($s['consultation_duration'] ?? 60);
        $break = (int)($s['consultation_break'] ?? 0);

        $startParts = explode(':', $start);
        $endParts = explode(':', $end);

        $current = ((int)$startParts[0] * 60) + (int)$startParts[1];
        $endMinutes = ((int)$endParts[0] * 60) + (int)$endParts[1];

        while ($current + $duration <= $endMinutes) {
            $h = floor($current / 60);
            $m = $current % 60;

            $workingHours[] = sprintf('%02d:%02d', $h, $m);

            $current += $duration + $break;
        }
    }
}

$availabilityExceptions = [];

$urlExceptions = $SUPABASE_URL
    . '/rest/v1/availability_exceptions?select=date,allow_booking&tenant_id=eq.'
    . rawurlencode($TENANT_ID)
    . '&allow_booking=eq.true';

$getExceptions = supabase_request('GET', $urlExceptions, $SUPABASE_KEY, $SUPABASE_SCHEMA);

if (!$getExceptions['error'] && $getExceptions['httpCode'] < 400) {
    $dataExceptions = json_decode($getExceptions['response'], true);

    if (is_array($dataExceptions)) {
        foreach ($dataExceptions as $row) {
            if (!empty($row['date']) && !empty($row['allow_booking'])) {
                $availabilityExceptions[] = $row['date'];
            }
        }
    }
}

$availabilityExceptions = array_values(array_unique($availabilityExceptions));

respond([
    'blockedDates' => array_values(array_unique($blockedDates)),
    'blockedTimes' => $blockedTimes,
    'availabilityExceptions' => $availabilityExceptions,
    'blockSettings' => $blockSettings,
    'minDate' => date('Y-m-d'),
    'maxDate' => date('Y-m-t'),
    'workingHours' => $workingHours
]);
