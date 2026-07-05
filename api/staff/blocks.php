<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();


function staff_blocks_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus = 400,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $staffAccountId = null,
    ?string $staffId = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    $context = [
        'action_key' => 'staff_blocks',
        'endpoint' => '/api/staff/blocks.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'actor_type' => 'staff',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    if ($tenantId !== null && $tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    if ($staffAccountId !== null && $staffAccountId !== '') {
        $context['staff_account_id'] = $staffAccountId;
    }

    if ($staffId !== null && $staffId !== '') {
        $context['staff_id'] = $staffId;
    }

    security_log_event($eventKey, $context);
}

function staff_blocks_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_blocks_request(
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

function staff_blocks_clear_session(): void
{
    unset($_SESSION['staff_user']);
}

function staff_blocks_fail(string $message, array $result): void
{
    global $tenantId, $accountId, $staffId;

    staff_blocks_security_event(
        'staff_blocks_failed',
        'supabase_failed',
        500,
        'failed',
        'high',
        is_string($tenantId ?? null) ? $tenantId : null,
        is_string($accountId ?? null) ? $accountId : null,
        is_string($staffId ?? null) ? $staffId : null,
        'supabase'
    );

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $details = trim((string) ($data['message'] ?? $data['details'] ?? $result['error'] ?? $result['response'] ?? ''));

    staff_blocks_json([
        'success' => false,
        'error' => $details !== '' ? $message . ': ' . substr($details, 0, 400) : $message,
        'httpCode' => $result['httpCode'] ?? 500,
    ], 500);
}

function staff_blocks_validate_date(string $date): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        staff_blocks_json([
            'success' => false,
            'error' => 'Nieprawidłowa data.',
        ], 400);
    }
}

function staff_blocks_validate_time(string $time): void
{
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        staff_blocks_json([
            'success' => false,
            'error' => 'Nieprawidłowa godzina.',
        ], 400);
    }
}

function staff_blocks_has_booking(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId,
    string $date,
    ?string $time = null
): bool {
    $url = $supabaseUrl
        . '/rest/v1/bookings?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&booking_date=eq.' . rawurlencode($date);

    if ($time !== null) {
        $url .= '&booking_time=eq.' . rawurlencode($time);
    }

    $url .= '&limit=1';

    $result = staff_blocks_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_blocks_fail('Nie udało się sprawdzić rezerwacji', $result);
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

    return !empty($rows[0]['id']);
}

function staff_blocks_delete_times(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId,
    string $date
): void {
    $url = $supabaseUrl
        . '/rest/v1/blocked_times?tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&date=eq.' . rawurlencode($date);

    $result = staff_blocks_request('DELETE', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_blocks_fail('Nie udało się uporządkować blokad godzin', $result);
    }
}

function staff_blocks_fetch_staff_display_name(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId
): string {
    $sessionName = trim((string) ($_SESSION['staff_user']['display_name'] ?? ''));

    if ($sessionName !== '') {
        return $sessionName;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles?select=display_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&limit=1';

    $result = staff_blocks_request('GET', $url, $supabaseKey, $schema);

    if (
        $result['response'] !== false
        && $result['error'] === ''
        && $result['httpCode'] >= 200
        && $result['httpCode'] < 300
    ) {
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $displayName = trim((string) ($rows[0]['display_name'] ?? ''));

        if ($displayName !== '') {
            $_SESSION['staff_user']['display_name'] = $displayName;
            return $displayName;
        }
    }

    return 'Pracownik';
}

function staff_blocks_build_notification_message(string $staffName, string $type, string $date, ?string $time): string
{
    if ($type === 'staff_block_day_created') {
        return $staffName . ' zablokował dzień ' . $date . '.';
    }

    if ($type === 'staff_block_day_removed') {
        return $staffName . ' odblokował dzień ' . $date . '.';
    }

    if ($type === 'staff_block_time_created') {
        return $staffName . ' zablokował godzinę ' . (string) $time . ' w dniu ' . $date . '.';
    }

    return $staffName . ' odblokował godzinę ' . (string) $time . ' w dniu ' . $date . '.';
}

function staff_blocks_write_admin_notification(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId,
    string $type,
    string $date,
    ?string $time
): void {
    if (!tenant_has_feature($tenantId, 'admin_staff_notifications')) {
        return;
    }

    $staffName = staff_blocks_fetch_staff_display_name($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId);
    $message = staff_blocks_build_notification_message($staffName, $type, $date, $time);

    $payload = [
        'tenant_id' => $tenantId,
        'staff_id' => $staffId,
        'type' => $type,
        'event_date' => $date,
        'event_time' => $time,
        'message' => $message,
        'is_read' => false,
    ];

    $result = staff_blocks_request(
        'POST',
        $supabaseUrl . '/rest/v1/tenant_admin_notifications',
        $supabaseKey,
        $schema,
        $payload,
        ['Prefer: return=minimal']
    );

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
        error_log('Staff block admin notification was not saved: ' . json_encode([
            'http_code' => (int) ($result['httpCode'] ?? 0),
            'has_error' => trim((string) ($result['error'] ?? '')) !== '',
            'has_response' => trim((string) ($result['response'] ?? '')) !== '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['POST', 'DELETE'], true)) {
    header('Allow: POST, DELETE');
    staff_blocks_security_event('staff_blocks_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    staff_blocks_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], 405);
}

$staffSession = $_SESSION['staff_user'] ?? null;

if (!is_array($staffSession) || empty($staffSession['account_id']) || empty($staffSession['tenant_id']) || empty($staffSession['staff_id'])) {
    staff_blocks_security_event('staff_blocks_unauthorized', 'unauthorized', 401, 'denied', 'medium');
    staff_blocks_json([
        'success' => false,
        'error' => 'Brak aktywnej sesji personelu.',
    ], 401);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_blocks_security_event('staff_blocks_env_missing', 'env_missing', 500, 'failed', 'high');
    staff_blocks_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ], 500);
}

$hostTenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);
$tenantId = (string) ($staffSession['tenant_id'] ?? '');
$staffId = (string) ($staffSession['staff_id'] ?? '');
$accountId = (string) ($staffSession['account_id'] ?? '');

if (!$hostTenantId || !hash_equals($tenantId, (string) $hostTenantId)) {
    staff_blocks_security_event('staff_blocks_session_invalidated', 'tenant_mismatch', 401, 'denied', 'medium', $tenantId, $accountId, $staffId);
    staff_blocks_clear_session();
    staff_blocks_json([
        'success' => false,
        'error' => 'Sesja personelu nie pasuje do domeny.',
    ], 401);
}

require_tenant_feature($tenantId, 'staff_blocks');

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($accountId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&limit=1';

$accountResult = staff_blocks_request('GET', $accountUrl, $supabaseKey, $schema);
$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];

if ($accountResult['response'] === false || $accountResult['error'] !== '' || $accountResult['httpCode'] >= 400) {
    staff_blocks_fail('Nie udało się sprawdzić sesji personelu', $accountResult);
}

if (empty($accountRows[0]['id'])) {
    staff_blocks_security_event('staff_blocks_session_invalidated', 'inactive_staff_account', 401, 'denied', 'medium', $tenantId, $accountId, $staffId);
    staff_blocks_clear_session();
    staff_blocks_json([
        'success' => false,
        'error' => 'Sesja personelu jest nieaktywna.',
    ], 401);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_blocks_security_event('staff_blocks_invalid_json', 'invalid_json', 400, 'failed', 'medium', $tenantId, $accountId, $staffId);
    staff_blocks_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON.',
    ], 400);
}

$date = trim((string) ($input['date'] ?? ''));
$time = trim((string) ($input['time'] ?? ''));
$allDay = filter_var($input['allDay'] ?? false, FILTER_VALIDATE_BOOLEAN);

staff_blocks_validate_date($date);

if (!$allDay) {
    staff_blocks_validate_time($time);
}

if ($method === 'DELETE') {
    $table = $allDay ? 'blocked_dates' : 'blocked_times';
    $url = $supabaseUrl
        . '/rest/v1/' . $table
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&date=eq.' . rawurlencode($date);

    if (!$allDay) {
        $url .= '&time=eq.' . rawurlencode($time);
    }

    $result = staff_blocks_request('DELETE', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_blocks_fail('Nie udało się usunąć blokady personelu', $result);
    }

    staff_blocks_write_admin_notification(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $tenantId,
        $staffId,
        $allDay ? 'staff_block_day_removed' : 'staff_block_time_removed',
        $date,
        $allDay ? null : $time
    );

    // TODO: staff block notification delivery for admin beyond the in-panel notification list.
    staff_blocks_security_event('staff_blocks_delete_success', $allDay ? 'staff_block_day_removed' : 'staff_block_time_removed', 200, 'success', 'medium', $tenantId, $accountId, $staffId);

    staff_blocks_json([
        'success' => true,
        'action' => $allDay ? 'unblock_day' : 'unblock_time',
        'date' => $date,
        'time' => $allDay ? null : $time,
    ]);
}

if (staff_blocks_has_booking($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId, $date, $allDay ? null : $time)) {
    staff_blocks_security_event('staff_blocks_conflict', 'booking_conflict', 409, 'failed', 'medium', $tenantId, $accountId, $staffId);
    staff_blocks_json([
        'success' => false,
        'error' => $allDay
            ? 'Nie można zablokować dnia, w którym masz rezerwację.'
            : 'Nie można zablokować terminu, który jest zajęty rezerwacją.',
    ], 409);
}

$table = $allDay ? 'blocked_dates' : 'blocked_times';
$payload = [
    'tenant_id' => $tenantId,
    'staff_id' => $staffId,
    'date' => $date,
];

if (!$allDay) {
    $payload['time'] = $time;
}

if ($allDay) {
    staff_blocks_delete_times($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId, $date);
}

$result = staff_blocks_request(
    'POST',
    $supabaseUrl . '/rest/v1/' . $table,
    $supabaseKey,
    $schema,
    $payload,
    ['Prefer: return=minimal,resolution=ignore-duplicates']
);

if ($result['response'] === false || $result['error'] !== '' || ($result['httpCode'] >= 400 && $result['httpCode'] !== 409)) {
    staff_blocks_fail('Nie udało się zapisać blokady personelu', $result);
}

staff_blocks_write_admin_notification(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    $tenantId,
    $staffId,
    $allDay ? 'staff_block_day_created' : 'staff_block_time_created',
    $date,
    $allDay ? null : $time
);

// TODO: staff block notification delivery for admin beyond the in-panel notification list.
staff_blocks_security_event('staff_blocks_create_success', $allDay ? 'staff_block_day_created' : 'staff_block_time_created', 200, 'success', 'medium', $tenantId, $accountId, $staffId);

staff_blocks_json([
    'success' => true,
    'action' => $allDay ? 'block_day' : 'block_time',
    'date' => $date,
    'time' => $allDay ? null : $time,
]);
