<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();


function staff_delete_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus = 400,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $staffId = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    $context = [
        'action_key' => 'staff_delete',
        'endpoint' => '/api/staff/delete.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    if ($tenantId !== null && $tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    if ($staffId !== null && $staffId !== '') {
        $context['staff_id'] = $staffId;
    }

    security_log_event($eventKey, $context);
}

function staff_delete_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function staff_delete_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    bool $returnRepresentation = false
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if ($returnRepresentation) {
        $headers[] = 'Prefer: return=representation';
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
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

function staff_delete_fail(
    string $message = 'Nie udało się usunąć pracownika. Odśwież listę personelu i spróbuj ponownie.',
    string $code = 'staff_delete_technical_error',
    int $statusCode = 500
): void
{
    global $tenantId, $staffId;

    staff_delete_security_event(
        'staff_delete_failed',
        $code,
        $statusCode,
        'failed',
        'high',
        is_string($tenantId ?? null) ? $tenantId : null,
        is_string($staffId ?? null) ? $staffId : null,
        'technical'
    );

    staff_delete_json([
        'success' => false,
        'error' => $message,
        'code' => $code,
        'reason' => $code,
    ], $statusCode);
}

function staff_delete_fetch_rows(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $table,
    string $query
): array {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . $query;
    $result = staff_delete_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '') {
        staff_delete_fail();
    }

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        staff_delete_fail();
    }

    $rows = json_decode((string) $result['response'], true);

    if (!is_array($rows)) {
        staff_delete_fail();
    }

    return $rows;
}

function staff_delete_has_service_assignments(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId
): bool {
    $query = 'select=staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&limit=1';

    return !empty(staff_delete_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'tenant_service_staff', $query));
}

function staff_delete_has_future_bookings(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId
): bool {
    date_default_timezone_set('Europe/Warsaw');

    $today = date('Y-m-d');
    $currentTime = date('H:i');
    $futureFilter = '(booking_date.gt.' . $today
        . ',and(booking_date.eq.' . $today
        . ',booking_time.gte.' . $currentTime . '))';

    $query = 'select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&or=' . rawurlencode($futureFilter)
        . '&limit=1';

    return !empty(staff_delete_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'bookings', $query));
}

function staff_delete_detach_past_bookings(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId
): void {
    date_default_timezone_set('Europe/Warsaw');

    $today = date('Y-m-d');
    $currentTime = date('H:i');
    $pastFilter = '(booking_date.lt.' . $today
        . ',and(booking_date.eq.' . $today
        . ',booking_time.lt.' . $currentTime . '))';

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&or=' . rawurlencode($pastFilter);

    $result = staff_delete_request('PATCH', $url, $supabaseKey, $schema, ['staff_id' => null]);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_delete_fail(
            'Nie udało się usunąć pracownika, ponieważ system wykrył dodatkowe powiązane dane. Sprawdź przypisane usługi, grafik, blokady lub rezerwacje i spróbuj ponownie.',
            'delete_failed_related_data'
        );
    }
}

function staff_delete_related_records(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId
): void {
    $tables = [
        'tenant_service_staff',
        'staff_availability',
        'staff_invites',
        'staff_password_reset_tokens',
        'staff_accounts',
        'blocked_dates',
        'blocked_times',
        'availability_exceptions',
        'tenant_admin_notifications',
    ];

    foreach ($tables as $table) {
        $url = $supabaseUrl
            . '/rest/v1/' . $table
            . '?tenant_id=eq.' . rawurlencode($tenantId)
            . '&staff_id=eq.' . rawurlencode($staffId);

        $result = staff_delete_request('DELETE', $url, $supabaseKey, $schema);

        if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
            staff_delete_fail(
                'Nie udało się usunąć pracownika, ponieważ system wykrył dodatkowe powiązane dane. Sprawdź przypisane usługi, grafik, blokady lub rezerwacje i spróbuj ponownie.',
                'delete_failed_related_data'
            );
        }
    }
}


function staff_delete_normalize_staff_ref($value): string
{
    $staffRef = trim((string) ($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function staff_delete_resolve_staff_ref(
    $value,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): ?string {
    $staffRef = staff_delete_normalize_staff_ref($value);

    if ($staffRef === '') {
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = staff_delete_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        staff_delete_fail();
    }

    $rows = json_decode((string) $result['response'], true);

    if (!is_array($rows)) {
        staff_delete_fail();
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string) ($row['id'] ?? ''));

        if ($staffId !== '' && hash_equals(public_response_staff_ref($tenantId, $staffId, $refSecret), $staffRef)) {
            return $staffId;
        }
    }

    return null;
}

function staff_delete_resolve_staff_request_id(
    array $input,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): string {
    $staffRef = staff_delete_normalize_staff_ref($input['staff_ref'] ?? null);

    if ($staffRef !== '') {
        return staff_delete_resolve_staff_ref($staffRef, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret) ?? '';
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_delete_security_event('staff_delete_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    staff_delete_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    staff_delete_security_event('staff_delete_unauthorized', 'unauthorized', 401, 'denied', 'medium');
    staff_delete_json([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$role = (string) ($_SESSION['user']['role'] ?? '');

if ($role !== 'administrator') {
    staff_delete_security_event('staff_delete_forbidden', 'forbidden', 403, 'denied', 'medium');
    staff_delete_json([
        'success' => false,
        'error' => 'Brak uprawnień'
    ], 403);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_delete_security_event('staff_delete_env_missing', 'env_missing', 500, 'failed', 'high');
    staff_delete_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_delete_security_event('staff_delete_tenant_denied', 'tenant_mismatch', 403, 'denied', 'medium');
    staff_delete_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

if ($tenantId === '') {
    staff_delete_security_event('staff_delete_session_invalid', 'session_invalid', 401, 'denied', 'medium');
    staff_delete_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

require_tenant_feature($tenantId, 'staff_module');

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_delete_security_event('staff_delete_invalid_json', 'invalid_json', 400, 'failed', 'medium', $tenantId);
    staff_delete_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON'
    ], 400);
}

$refSecret = public_response_ref_secret($supabaseKey);
$staffId = staff_delete_resolve_staff_request_id($input, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret);

if ($staffId === '') {
    staff_delete_security_event('staff_delete_ref_missing_or_invalid', 'staff_ref_missing_or_invalid', 400, 'failed', 'medium', $tenantId);
    staff_delete_json([
        'success' => false,
        'error' => 'Nie udało się usunąć pracownika. Odśwież listę personelu i spróbuj ponownie.',
    ], 400);
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,display_name,is_active'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_delete_request('GET', $staffUrl, $supabaseKey, $schema);

if ($staffResult['response'] === false || $staffResult['error'] !== '') {
    staff_delete_fail();
}

if ($staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300) {
    staff_delete_fail();
}

$staffRows = json_decode((string) $staffResult['response'], true);

if (!is_array($staffRows) || empty($staffRows[0]) || !is_array($staffRows[0])) {
    staff_delete_security_event('staff_delete_not_found', 'staff_not_found', 404, 'failed', 'medium', $tenantId, $staffId);
    staff_delete_json([
        'success' => false,
        'error' => 'Nie udało się usunąć pracownika. Odśwież listę personelu i spróbuj ponownie.',
        'code' => 'staff_not_found',
        'reason' => 'staff_not_found',
    ], 404);
}

$staff = $staffRows[0];

if (!empty($staff['is_active'])) {
    staff_delete_security_event('staff_delete_active_blocked', 'staff_active', 409, 'failed', 'medium', $tenantId, $staffId);
    staff_delete_json([
        'success' => false,
        'error' => 'Ten pracownik jest aktywny. Najpierw odznacz opcję „Aktywny”, zapisz zmiany i spróbuj ponownie.',
        'code' => 'staff_active',
        'reason' => 'staff_active',
    ], 409);
}

if (staff_delete_has_service_assignments($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId)) {
    staff_delete_security_event('staff_delete_has_services', 'staff_has_services', 409, 'failed', 'medium', $tenantId, $staffId);
    staff_delete_json([
        'success' => false,
        'error' => 'Ten pracownik ma przypisane usługi. Najpierw odłącz go od usług, a potem spróbuj ponownie.',
        'code' => 'staff_has_services',
        'reason' => 'staff_has_services',
        'has_services' => true,
    ], 409);
}

if (staff_delete_has_future_bookings($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId)) {
    staff_delete_security_event('staff_delete_has_future_bookings', 'staff_has_future_bookings', 409, 'failed', 'medium', $tenantId, $staffId);
    staff_delete_json([
        'success' => false,
        'error' => 'Ten pracownik ma zaplanowane rezerwacje. Najpierw zmień obsługę tych terminów albo anuluj rezerwacje.',
        'code' => 'staff_has_future_bookings',
        'reason' => 'staff_has_future_bookings',
        'has_future_bookings' => true,
    ], 409);
}

staff_delete_detach_past_bookings($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId);

staff_delete_related_records($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId);

$deleteUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId);

$deleteResult = staff_delete_request('DELETE', $deleteUrl, $supabaseKey, $schema);

if ($deleteResult['response'] === false || $deleteResult['error'] !== '' || $deleteResult['httpCode'] >= 400) {
    staff_delete_fail(
        'Nie udało się usunąć pracownika, ponieważ system wykrył dodatkowe powiązane dane. Sprawdź przypisane usługi, grafik, blokady lub rezerwacje i spróbuj ponownie.',
        'delete_failed_related_data'
    );
}

staff_delete_security_event('staff_delete_success', 'staff_delete_success', 200, 'success', 'medium', $tenantId, $staffId);

staff_delete_json([
    'success' => true,
    'message' => 'Pracownik został usunięty.',
    'deleted' => true,
    'has_services' => false,
    'has_future_bookings' => false,
]);
