<?php
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_once __DIR__ . '/../helpers/security.php';

if (in_array($method, ['POST', 'DELETE'], true)) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();

    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
        booking_blocked_security_event('booking_blocked_unauthorized', [
            'reason' => 'auth_required',
            'response_status' => 401,
            'result' => 'denied',
            'severity' => 'medium',
        ]);
        json_response(['success' => false, 'error' => 'Brak autoryzacji'], 401);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (in_array($method, ['POST', 'DELETE'], true)) {
    require_once __DIR__ . '/../helpers/csrf.php';
    require_csrf_token();
}

require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/public_response.php';

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    booking_blocked_security_event('booking_blocked_env_missing', [
        'reason' => 'supabase_env_missing',
        'response_status' => 500,
        'result' => 'error',
        'severity' => 'high',
    ]);
    json_response(['success' => false, 'error' => 'Brak konfiguracji Supabase'], 500);
}

$REF_SECRET = public_response_ref_secret($SUPABASE_KEY);

$TENANT_ID = in_array($method, ['POST', 'DELETE'], true)
    ? (string)($_SESSION['user']['tenant_id'] ?? '')
    : (string)(getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA) ?: '');

if ($TENANT_ID === '') {
    booking_blocked_security_event('booking_blocked_tenant_denied', [
        'reason' => 'tenant_not_resolved',
        'response_status' => 400,
        'result' => 'denied',
        'severity' => 'medium',
    ]);
    json_response(['success' => false, 'error' => 'Nie rozpoznano tenant'], 400);
}

if (in_array($method, ['POST', 'DELETE'], true)
    && !session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA)
) {
    booking_blocked_security_event('booking_blocked_forbidden', [
        'reason' => 'tenant_mismatch',
        'response_status' => 403,
        'result' => 'denied',
        'severity' => 'medium',
    ]);
    json_response(['success' => false, 'error' => 'Brak autoryzacji'], 403);
}

if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    booking_blocked_security_event('booking_blocked_method_not_allowed', [
        'reason' => 'method_not_allowed',
        'response_status' => 405,
        'result' => 'denied',
        'severity' => 'low',
    ]);
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

function booking_blocked_security_event(string $eventKey, array $options = []): void
{
    $reason = trim((string)($options['reason'] ?? $eventKey));
    $stage = trim((string)($options['stage'] ?? ''));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $details = [
        'reason' => $reason !== '' ? $reason : $eventKey,
    ];

    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    $context = [
        'action_key' => 'booking_blocked',
        'endpoint' => '/api/booking/blocked.php',
        'http_method' => $method,
        'actor_type' => in_array($method, ['POST', 'DELETE'], true) ? 'tenant_user' : 'public',
        'severity' => $options['severity'] ?? 'medium',
        'response_status' => $options['response_status'] ?? null,
        'result' => $options['result'] ?? null,
        'details' => $details,
    ];

    $tenantId = trim((string)($options['tenant_id'] ?? ($GLOBALS['TENANT_ID'] ?? '')));
    if ($tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    $staffId = trim((string)($options['staff_id'] ?? ''));
    if ($staffId !== '') {
        $context['staff_id'] = $staffId;
    }

    security_log_event($eventKey, $context);
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

function normalize_staff_ref($value): string
{
    $staffRef = trim((string)($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function resolve_staff_ref(
    $value,
    string $tenantId,
    string $supabaseUrl,
    string $apiKey,
    string $schema,
    string $refSecret
): ?string {
    $staffRef = normalize_staff_ref($value);

    if ($staffRef === '') {
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true';

    $result = supabase_request('GET', $url, $apiKey, $schema);
    $rows = is_array($result['data']) ? $result['data'] : [];

    if (!$result['ok']) {
        booking_blocked_security_event('booking_blocked_staff_lookup_failed', [
            'reason' => 'staff_lookup_failed',
            'stage' => 'resolve_staff_ref',
            'response_status' => 400,
            'result' => 'failed',
            'severity' => 'medium',
            'tenant_id' => $tenantId,
        ]);
        json_response(['success' => false, 'error' => 'Nieprawidłowy pracownik.'], 400);
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string)($row['id'] ?? ''));

        if ($staffId === '') {
            continue;
        }

        $expectedRef = public_response_staff_ref($tenantId, $staffId, $refSecret);

        if (hash_equals($expectedRef, $staffRef)) {
            return $staffId;
        }
    }

    booking_blocked_security_event('booking_blocked_staff_ref_invalid', [
        'reason' => 'staff_ref_invalid',
        'stage' => 'resolve_staff_ref',
        'response_status' => 400,
        'result' => 'failed',
        'severity' => 'medium',
        'tenant_id' => $tenantId,
    ]);
    json_response(['success' => false, 'error' => 'Nieprawidłowy pracownik.'], 400);
}

function resolve_staff_request_id(
    $staffRefValue,
    $staffIdValue,
    string $tenantId,
    string $supabaseUrl,
    string $apiKey,
    string $schema,
    string $refSecret
): ?string {
    if (normalize_staff_ref($staffRefValue) !== '') {
        return resolve_staff_ref($staffRefValue, $tenantId, $supabaseUrl, $apiKey, $schema, $refSecret);
    }

    if (normalize_staff_ref($staffIdValue) !== '') {
        booking_blocked_security_event('booking_blocked_legacy_id_rejected', [
            'reason' => 'legacy_staff_id_rejected',
            'stage' => 'resolve_staff_request_id',
            'response_status' => 400,
            'result' => 'failed',
            'severity' => 'medium',
            'tenant_id' => $tenantId,
        ]);
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik.',
        ], 400);
    }

    return null;
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
        booking_blocked_security_event('booking_blocked_staff_not_found', [
            'reason' => 'staff_not_found',
            'stage' => 'ensure_staff_belongs_to_tenant',
            'response_status' => 404,
            'result' => 'failed',
            'severity' => 'medium',
            'tenant_id' => $tenantId,
            'staff_id' => $staffId ?? '',
        ]);
        json_response(['success' => false, 'error' => 'Nie znaleziono pracownika'], 404);
    }
}

function fail_supabase(string $label, array $result): void
{
    booking_blocked_security_event('booking_blocked_supabase_failed', [
        'reason' => 'supabase_failed',
        'stage' => preg_replace('/[^a-zA-Z0-9_.-]+/', '_', strtolower($label)) ?: 'supabase',
        'response_status' => 500,
        'result' => 'error',
        'severity' => 'medium',
    ]);

    // Never expose database/provider diagnostics or SQL constraint names to clients.
    // Log only a bounded, non-sensitive classification and HTTP status.
    error_log('blocked.php supabase failure stage=' . preg_replace('/[^a-zA-Z0-9_.-]+/', '_', strtolower($label))
        . ' status=' . (int)($result['status'] ?? 0));
    json_response(['success' => false, 'error' => 'Operacja chwilowo niedostępna.'], 503);
}

function blocked_valid_date($value): bool
{
    if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $parsed !== false && $parsed->format('Y-m-d') === $value;
}

function blocked_valid_time($value): bool
{
    return is_string($value)
        && preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $value) === 1;
}

function blocked_require_staff_feature(?string $staffId, string $tenantId): void
{
    if ($staffId === null) {
        return;
    }
    require_once __DIR__ . '/../helpers/plan_features.php';
    $context = plan_features_get_context($tenantId);
    if (!empty($context['temporary_error'])) {
        json_response(['success' => false, 'error' => 'Operacja chwilowo niedostępna.'], 503);
    }
    $features = is_array($context['features'] ?? null) ? $context['features'] : [];
    if (($features['staff_blocks'] ?? null) !== true) {
        json_response(['success' => false, 'error' => 'Blokady pracowników są niedostępne w tym planie.'], 403);
    }
}

// R3: all mutating block operations must be executed in one privileged SQL
// transaction, with live user/tenant/plan checks performed by that RPC.
function blocked_r3_mutate(
    string $operation,
    ?string $date,
    ?string $time,
    ?string $staffId,
    ?array $settings = null
): array {
    $actorId = (string)($_SESSION['user']['id'] ?? '');
    $payload = [
        'p_tenant_id' => $GLOBALS['TENANT_ID'],
        'p_actor_id' => $actorId,
        'p_operation' => $operation,
        'p_date' => $date,
        'p_time' => $time,
        'p_staff_id' => $staffId,
        'p_settings' => $settings,
    ];
    $result = supabase_request(
        'POST',
        $GLOBALS['SUPABASE_URL'] . '/rest/v1/rpc/booking_manual_block_apply_r3',
        $GLOBALS['SUPABASE_KEY'],
        $GLOBALS['SUPABASE_SCHEMA'],
        $payload
    );
    if (!$result['ok'] || !is_array($result['data'])) {
        fail_supabase('Blokady SQL R3 niedostępne', $result);
    }
    $data = $result['data'];
    $code = $data['code'] ?? null;
    $ok = $data['ok'] ?? null;
    $affected = $data['affected'] ?? null;
    if (!is_string($code) || !is_bool($ok) || !is_int($affected) || $affected < 0) {
        fail_supabase('Nieprawidłowy kontrakt RPC R3', $result);
    }
    if (!$ok) {
        $errors = [
            'invalid_request' => [400, 'Nieprawidłowe dane wejściowe.'],
            'forbidden' => [403, 'Brak uprawnień.'],
            'feature_denied' => [403, 'Funkcja niedostępna w tym planie.'],
            'feature_unverified' => [503, 'Nie można potwierdzić uprawnień planu.'],
            'staff_unavailable' => [409, 'Pracownik jest niedostępny.'],
            'slot_unavailable' => [409, 'Termin nie jest dostępny.'],
        ];
        if (!isset($errors[$code])) {
            fail_supabase('Nieznany wynik RPC R3', $result);
        }
        [$status, $message] = $errors[$code];
        json_response(['success' => false, 'code' => $code, 'error' => $message], $status);
    }
    if (!in_array($code, ['created','already_exists','deleted','not_found','saved'], true)) {
        fail_supabase('Nieznany pozytywny wynik RPC R3', $result);
    }
    return [
        'success' => true,
        'code' => $code,
        'affected' => $affected,
        'deleted_count' => $operation === 'clear_times' || str_starts_with($operation, 'remove_')
            ? $affected : 0,
        'removed_times' => isset($data['removed_times']) && is_int($data['removed_times'])
            ? $data['removed_times'] : 0,
    ];
}

function push_time(array &$map, string $date, string $time): void
{
    if (!isset($map[$date])) {
        $map[$date] = [];
    }
    $map[$date][] = $time;
}

// Defense in depth: session authority must be rechecked against the live tenant user.
// The service_role key is only transport and never grants an operator permission.
if (in_array($method, ['POST', 'DELETE'], true)) {
    $actorId = (string) ($_SESSION['user']['id'] ?? '');

    if (preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/iD', $actorId) !== 1) {
        booking_blocked_security_event('booking_blocked_unauthorized', [
            'reason' => 'actor_identity_invalid',
            'response_status' => 401,
            'result' => 'denied',
            'severity' => 'high',
        ]);
        json_response(['success' => false, 'error' => 'Brak autoryzacji'], 401);
    }

    $actorUrl = $SUPABASE_URL . '/rest/v1/users?select=id,tenant_id,role,is_active'
        . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
        . '&id=eq.' . rawurlencode($actorId)
        . '&limit=2';
    $actorResult = supabase_request('GET', $actorUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);

    if (!$actorResult['ok'] || !is_array($actorResult['data'])) {
        booking_blocked_security_event('booking_blocked_auth_check_unavailable', [
            'reason' => 'actor_lookup_failed',
            'response_status' => 503,
            'result' => 'error',
            'severity' => 'high',
        ]);
        json_response(['success' => false, 'error' => 'Operacja chwilowo niedostępna'], 503);
    }

    $actors = $actorResult['data'];
    $actor = $actors[0] ?? null;
    if (count($actors) !== 1 || !is_array($actor)
        || ($actor['id'] ?? null) !== $actorId
        || ($actor['tenant_id'] ?? null) !== $TENANT_ID
        || ($actor['is_active'] ?? null) !== true
        || ($actor['role'] ?? null) !== 'administrator') {
        booking_blocked_security_event('booking_blocked_forbidden', [
            'reason' => 'actor_not_active_administrator',
            'response_status' => 403,
            'result' => 'denied',
            'severity' => 'high',
        ]);
        json_response(['success' => false, 'error' => 'Brak uprawnień'], 403);
    }
}

if ($method === 'DELETE') {
    $input = json_input();
    if (!is_string($input['date'] ?? null)
        || (isset($input['time']) && $input['time'] !== null && !is_string($input['time']))
        || (isset($input['deleteAllTimes']) && !is_bool($input['deleteAllTimes']))) {
        json_response(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe'], 400);
    }
    $date = $input['date'];
    $time = trim((string)($input['time'] ?? ''));
    $deleteAllTimes = $input['deleteAllTimes'] ?? false;
    if (!blocked_valid_date($date)) {
        json_response(['success' => false, 'error' => 'Nieprawidłowa data'], 400);
    }
    $staffId = resolve_staff_request_id(
        $input['staff_ref'] ?? null,
        $input['staff_id'] ?? null,
        $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY,
        $SUPABASE_SCHEMA, $REF_SECRET
    );
    blocked_require_staff_feature($staffId, $TENANT_ID);
    ensure_staff_belongs_to_tenant($staffId, $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

    if ($deleteAllTimes) {
        $operation = 'clear_times';
        $sqlTime = null;
    } elseif ($time !== '' && $time !== 'all') {
        if (!blocked_valid_time($time)) {
            json_response(['success' => false, 'error' => 'Nieprawidłowa godzina'], 400);
        }
        $operation = 'remove_time';
        $sqlTime = $time;
    } else {
        $operation = 'remove_day';
        $sqlTime = null;
    }
    $result = blocked_r3_mutate($operation, $date, $sqlTime, $staffId);
    // Bulk clear is allowed to affect zero rows. Single unblocks are not.
    if (!$deleteAllTimes && $result['code'] === 'not_found') {
        json_response([
            'success' => false,
            'code' => 'not_found',
            'error' => 'Nie znaleziono ręcznej blokady do usunięcia.'
        ], 409);
    }
    booking_blocked_security_event('booking_blocked_delete_success', [
        'reason' => 'booking_blocked_delete_success',
        'stage' => $operation,
        'response_status' => 200,
        'result' => 'success',
        'severity' => 'medium',
        'staff_id' => $staffId ?? '',
    ]);
    json_response($result);
}

if ($method === 'POST') {
    $input = json_input();
    if (isset($input['action']) && !is_string($input['action'])) {
        json_response(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe'], 400);
    }
    if (trim((string)($input['action'] ?? '')) === 'saveBlockSettings') {
        $settings = [];
        foreach (['block_saturdays','block_sundays','block_holidays'] as $field) {
            if (!array_key_exists($field, $input) || !is_bool($input[$field])) {
                json_response(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe'], 400);
            }
            $settings[$field] = $input[$field];
        }
        $result = blocked_r3_mutate('save_settings', null, null, null, $settings);
        booking_blocked_security_event('booking_block_settings_save_success', [
            'reason' => 'booking_block_settings_save_success',
            'stage' => 'block_settings',
            'response_status' => 200,
            'result' => 'success',
            'severity' => 'medium',
        ]);
        json_response(['success' => true, 'code' => $result['code'], 'blockSettings' => $settings]);
    }

    if (!is_string($input['date'] ?? null)
        || (isset($input['time']) && !is_string($input['time']))
        || (isset($input['allDay']) && !is_bool($input['allDay']))) {
        json_response(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe'], 400);
    }
    $date = $input['date'];
    $time = trim((string)($input['time'] ?? ''));
    $allDay = $input['allDay'] ?? false;
    if (!blocked_valid_date($date)) {
        json_response(['success' => false, 'error' => 'Nieprawidłowa data'], 400);
    }
    $staffId = resolve_staff_request_id(
        $input['staff_ref'] ?? null,
        $input['staff_id'] ?? null,
        $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY,
        $SUPABASE_SCHEMA, $REF_SECRET
    );
    blocked_require_staff_feature($staffId, $TENANT_ID);
    ensure_staff_belongs_to_tenant($staffId, $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

    $isTimeBlock = !$allDay && $time !== '';
    if ($isTimeBlock && !blocked_valid_time($time)) {
        json_response(['success' => false, 'error' => 'Nieprawidłowa godzina'], 400);
    }
    $operation = $isTimeBlock ? 'put_time' : 'replace_times_with_day';
    $result = blocked_r3_mutate($operation, $date, $isTimeBlock ? $time : null, $staffId);
    booking_blocked_security_event('booking_blocked_create_success', [
        'reason' => 'booking_blocked_create_success',
        'stage' => $operation,
        'response_status' => 200,
        'result' => 'success',
        'severity' => 'medium',
        'staff_id' => $staffId ?? '',
    ]);
    if ($result['code'] === 'already_exists') {
        $result['message'] = 'Ta blokada już istniała.';
    }
    json_response($result);
}

$requestedStaffId = resolve_staff_request_id(
    $_GET['staff_ref'] ?? null,
    $_GET['staff_id'] ?? null,
    $TENANT_ID,
    $SUPABASE_URL,
    $SUPABASE_KEY,
    $SUPABASE_SCHEMA,
    $REF_SECRET
);
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
if (!$settingsResult['ok'] || !is_array($settingsResult['data'])) {
    fail_supabase('Błąd odczytu ustawień blokad', $settingsResult);
}
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
if (!$calendarResult['ok'] || !is_array($calendarResult['data'])) {
    fail_supabase('Błąd odczytu ustawień kalendarza', $calendarResult);
}
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

if (!$exceptionsResult['ok']) {
    fail_supabase('Błąd pobierania wyjątków dostępności', $exceptionsResult);
}
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
