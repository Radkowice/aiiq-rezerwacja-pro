<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function system_settings_security_event(string $eventKey, string $reason, int $responseStatus = 200, string $result = 'success', string $severity = 'medium', string $stage = ''): void
{
    $details = ['reason' => $reason];
    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'system_settings',
        'endpoint' => '/api/system/settings.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'actor_type' => 'tenant_user',
        'tenant_id' => (string) ($_SESSION['user']['tenant_id'] ?? ''),
        'user_id' => (string) ($_SESSION['user']['id'] ?? ''),
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

$SUPABASE_URL = rtrim((string) getenv('SUPABASE_URL'), '/');
$SUPABASE_KEY = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$SCHEMA       = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function rest_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    array $extraHeaders = []
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if (!empty($extraHeaders)) {
        $headers = array_merge($headers, $extraHeaders);
    }

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [$response, $error, $httpCode];
}

function default_calendar_settings(): array
{
    return [
        'calendar_enabled'           => false,
        'work_start'                 => '09:00',
        'work_end'                   => '17:00',
        'consultation_duration'      => 60,
        'consultation_break'         => 0,
        'booking_buffer'             => 0,
        'booking_start_month_offset' => 0,
        'booking_month_range'        => 1,
    ];
}

function normalize_settings_payload(array $input): array
{
    $payload = [];

    if (array_key_exists('calendar_enabled', $input)) {
        $payload['calendar_enabled'] = (bool) $input['calendar_enabled'];
    }

    $timeFields = [
        'work_start',
        'work_end',
    ];

    foreach ($timeFields as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $value = trim((string) $input[$field]);

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            $payload[$field] = $value;
        }
    }

    $integerFields = [
        'consultation_duration',
        'consultation_break',
        'booking_buffer',
        'booking_start_month_offset',
        'booking_month_range',
    ];

    foreach ($integerFields as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $payload[$field] = max(0, (int) $input[$field]);
    }

    if (isset($payload['consultation_duration']) && $payload['consultation_duration'] < 1) {
        $payload['consultation_duration'] = 60;
    }

    if (isset($payload['booking_month_range']) && $payload['booking_month_range'] < 1) {
        $payload['booking_month_range'] = 1;
    }

    return $payload;
}

function fetch_calendar_settings(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): array {
    $url = $supabaseUrl
        . '/rest/v1/calendar_settings?select=*'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    [$response, $error, $httpCode] = rest_request('GET', $url, $supabaseKey, $schema);

    if ($error !== '') {
        return [null, 'Błąd połączenia z bazą: ' . $error, 500];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [null, 'Błąd odczytu ustawień', $httpCode];
    }

    $rows = json_decode((string) $response, true);

    if (!is_array($rows) || empty($rows)) {
        return [null, null, 200];
    }

    return [$rows[0], null, 200];
}

function insert_calendar_settings(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    array $payload
): array {
    $url = $supabaseUrl . '/rest/v1/calendar_settings';

    [$response, $error, $httpCode] = rest_request(
        'POST',
        $url,
        $supabaseKey,
        $schema,
        $payload,
        ['Prefer: return=representation']
    );

    if ($error !== '') {
        return [null, 'Błąd połączenia z bazą: ' . $error, 500];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [null, 'Nie udało się utworzyć ustawień', $httpCode];
    }

    $rows = json_decode((string) $response, true);

    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
        return [$rows[0], null, $httpCode];
    }

    return [null, null, $httpCode];
}

function update_calendar_settings(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    array $payload
): array {
    $url = $supabaseUrl
        . '/rest/v1/calendar_settings?tenant_id=eq.' . rawurlencode($tenantId);

    [$response, $error, $httpCode] = rest_request(
        'PATCH',
        $url,
        $supabaseKey,
        $schema,
        $payload,
        ['Prefer: return=representation']
    );

    if ($error !== '') {
        return [null, 'Błąd połączenia z bazą: ' . $error, 500];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [null, 'Nie udało się zaktualizować ustawień', $httpCode];
    }

    $rows = json_decode((string) $response, true);

    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
        return [$rows[0], null, $httpCode];
    }

    return [null, null, $httpCode];
}

/*
|--------------------------------------------------------------------------
| GET = odczyt ustawień tylko dla zalogowanego admina
|--------------------------------------------------------------------------
*/
if ($method === 'GET') {
    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Brak autoryzacji'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tenantId = (string) $_SESSION['user']['tenant_id'];

    [$settings, $fetchError, $fetchStatus] = fetch_calendar_settings(
        $SUPABASE_URL,
        $SUPABASE_KEY,
        $SCHEMA,
        $tenantId
    );

    if ($fetchError !== null) {
        http_response_code($fetchStatus);
        echo json_encode([
            'success' => false,
            'error'   => $fetchError
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(public_response_sanitize([
        'success'  => true,
        'settings' => $settings ?? default_calendar_settings()
    ]), JSON_UNESCAPED_UNICODE);

    exit;
}
/*
|--------------------------------------------------------------------------
| POST/PATCH = zapis z panelu admina po sesji
|--------------------------------------------------------------------------
*/
if ($method === 'POST' || $method === 'PATCH') {
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Brak sesji'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

    if ($tenantId === '') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Nieprawidłowa sesja.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Nieprawidłowy JSON'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = normalize_settings_payload($data);
    $payload['tenant_id'] = $tenantId;

    [$existing, $fetchError, $fetchStatus] = fetch_calendar_settings(
        $SUPABASE_URL,
        $SUPABASE_KEY,
        $SCHEMA,
        $tenantId
    );

    if ($fetchError !== null) {
        http_response_code($fetchStatus);
        echo json_encode([
            'success' => false,
            'error'   => $fetchError
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($existing === null) {
        [$savedSettings, $saveError, $saveStatus] = insert_calendar_settings(
            $SUPABASE_URL,
            $SUPABASE_KEY,
            $SCHEMA,
            $payload
        );
    } else {
        $updatePayload = $payload;
        unset($updatePayload['tenant_id']);

        [$savedSettings, $saveError, $saveStatus] = update_calendar_settings(
            $SUPABASE_URL,
            $SUPABASE_KEY,
            $SCHEMA,
            $tenantId,
            $updatePayload
        );
    }

    if ($saveError !== null) {
        system_settings_security_event('system_settings_save_failed', 'save_failed', $saveStatus, 'failed', 'medium', 'supabase_save');
        http_response_code($saveStatus);
        echo json_encode([
            'success' => false,
            'error'   => $saveError
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($savedSettings === null) {
        [$savedSettings, $freshError, $freshStatus] = fetch_calendar_settings(
            $SUPABASE_URL,
            $SUPABASE_KEY,
            $SCHEMA,
            $tenantId
        );

        if ($freshError !== null) {
            http_response_code($freshStatus);
            echo json_encode([
                'success' => false,
                'error'   => $freshError
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    system_settings_security_event('system_settings_save_success', 'system_settings_save_success', 200, 'success', 'medium');

    echo json_encode(public_response_sanitize([
    'success'  => true,
    'settings' => $savedSettings ?? default_calendar_settings()
]), JSON_UNESCAPED_UNICODE);

    exit;
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'error'   => 'Metoda niedozwolona'
], JSON_UNESCAPED_UNICODE);
