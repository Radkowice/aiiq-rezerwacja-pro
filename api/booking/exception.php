<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

function exception_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function exception_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $staffId = null,
    ?string $stage = null
): void {
    $details = [
        'reason' => $reason,
    ];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    $context = [
        'action_key' => 'booking_exception',
        'endpoint' => '/api/booking/exception.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    if ($tenantId !== null && trim($tenantId) !== '') {
        $context['tenant_id'] = $tenantId;
    }

    if ($staffId !== null && trim($staffId) !== '') {
        $context['staff_id'] = $staffId;
    }

    security_log_event($eventKey, $context);
}

function exception_supabase_request(
    string $method,
    string $url,
    string $apiKey,
    string $schema,
    ?array $payload = null,
    array $headers = []
): array {
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

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'response' => (string)$response,
        'error' => $error,
        'httpCode' => $httpCode,
        'data' => json_decode((string)$response, true),
    ];
}

function exception_normalize_staff_ref($value): string
{
    $staffRef = trim((string)($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function exception_resolve_staff_ref(
    $value,
    string $tenantId,
    string $supabaseUrl,
    string $apiKey,
    string $schema,
    string $refSecret
): ?string {
    $staffRef = exception_normalize_staff_ref($value);

    if ($staffRef === '') {
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true';

    $result = exception_supabase_request('GET', $url, $apiKey, $schema);
    $rows = is_array($result['data']) ? $result['data'] : [];

    if ($result['error'] !== '' || $result['httpCode'] >= 400) {
        exception_security_event(
            'booking_exception_staff_lookup_failed',
            'staff_lookup_failed',
            400,
            'failed',
            'medium',
            $tenantId,
            null,
            'staff_ref_lookup'
        );
        exception_json([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik.',
        ], 400);
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

    exception_security_event(
        'booking_exception_staff_ref_invalid',
        'staff_ref_invalid',
        400,
        'failed',
        'medium',
        $tenantId,
        null,
        'staff_ref_resolve'
    );
    exception_json([
        'success' => false,
        'error' => 'Nieprawidłowy pracownik.',
    ], 400);
}

function exception_resolve_staff_request_id(
    $staffRefValue,
    $staffIdValue,
    string $tenantId,
    string $supabaseUrl,
    string $apiKey,
    string $schema,
    string $refSecret
): ?string {
    if (exception_normalize_staff_ref($staffRefValue) !== '') {
        return exception_resolve_staff_ref($staffRefValue, $tenantId, $supabaseUrl, $apiKey, $schema, $refSecret);
    }

    if (exception_normalize_staff_ref($staffIdValue) !== '') {
        exception_security_event(
            'booking_exception_legacy_id_rejected',
            'legacy_staff_id_rejected',
            400,
            'failed',
            'medium',
            $tenantId,
            null,
            'staff_request'
        );
        exception_json([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik.',
        ], 400);
    }

    return null;
}

function exception_public_staff_ref(?string $staffId, string $tenantId, string $refSecret): ?string
{
    return $staffId !== null && $staffId !== ''
        ? public_response_staff_ref($tenantId, $staffId, $refSecret)
        : null;
}

function exception_staff_filter(?string $staffId): string
{
    return $staffId === null
        ? '&staff_id=is.null'
        : '&staff_id=eq.' . rawurlencode($staffId);
}

function exception_ensure_staff_belongs_to_tenant(
    ?string $staffId,
    string $tenantId,
    string $supabaseUrl,
    string $apiKey,
    string $schema
): void {
    if ($staffId === null) {
        return;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&is_active=eq.true'
        . '&limit=1';

    $result = exception_supabase_request('GET', $url, $apiKey, $schema);
    $rows = is_array($result['data']) ? $result['data'] : [];

    if ($result['error'] !== '' || $result['httpCode'] >= 400) {
        exception_security_event(
            'booking_exception_staff_lookup_failed',
            'staff_lookup_failed',
            500,
            'error',
            'medium',
            $tenantId,
            $staffId,
            'staff_tenant_check'
        );
        exception_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić pracownika',
        ], 500);
    }

    if (empty($rows[0]['id'])) {
        exception_security_event(
            'booking_exception_staff_not_found',
            'staff_not_found',
            404,
            'failed',
            'medium',
            $tenantId,
            $staffId,
            'staff_tenant_check'
        );
        exception_json([
            'success' => false,
            'error' => 'Nie znaleziono pracownika',
        ], 404);
    }
}

function exception_find_existing(
    string $tenantId,
    string $date,
    ?string $staffId,
    string $supabaseUrl,
    string $apiKey,
    string $schema
): ?array {
    $url = $supabaseUrl
        . '/rest/v1/availability_exceptions?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . exception_staff_filter($staffId)
        . '&limit=1';

    $result = exception_supabase_request('GET', $url, $apiKey, $schema);

    if ($result['error'] !== '' || $result['httpCode'] >= 400) {
        exception_security_event(
            'booking_exception_lookup_failed',
            'exception_lookup_failed',
            500,
            'error',
            'medium',
            $tenantId,
            $staffId,
            'find_existing'
        );
        exception_json([
            'success' => false,
            'error' => 'Błąd odczytu wyjątku dostępności',
        ], 500);
    }

    $rows = is_array($result['data']) ? $result['data'] : [];

    return !empty($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function exception_fail_supabase(
    string $message,
    array $result,
    string $eventKey = 'booking_exception_supabase_failed',
    string $reason = 'supabase_failed',
    ?string $tenantId = null,
    ?string $staffId = null,
    string $stage = 'supabase'
): void {
    $data = is_array($result['data']) ? $result['data'] : [];
    $details = trim((string)($data['message'] ?? $data['details'] ?? $result['error'] ?? $result['response'] ?? ''));

    exception_security_event(
        $eventKey,
        $reason,
        500,
        'error',
        'medium',
        $tenantId,
        $staffId,
        $stage
    );

    exception_json([
        'success' => false,
        'error' => $details !== '' ? $message . ': ' . substr($details, 0, 400) : $message,
        'httpCode' => $result['httpCode'] ?? 500,
    ], 500);
}

if (!in_array($method, ['POST', 'DELETE'], true)) {
    header('Allow: POST, DELETE');
    exception_security_event(
        'booking_exception_method_not_allowed',
        'method_not_allowed',
        405,
        'failed',
        'low'
    );
    exception_json([
        'success' => false,
        'error' => 'Metoda niedozwolona',
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    exception_security_event(
        'booking_exception_unauthorized',
        'unauthorized',
        401,
        'denied',
        'medium'
    );
    exception_json([
        'success' => false,
        'error' => 'Brak autoryzacji',
    ], 401);
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$TENANT_ID = (string)($_SESSION['user']['tenant_id'] ?? '');
$SUPABASE_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($TENANT_ID === '') {
    exception_security_event(
        'booking_exception_session_invalid',
        'session_invalid',
        400,
        'failed',
        'medium'
    );
    exception_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja',
    ], 400);
}

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    exception_security_event(
        'booking_exception_env_missing',
        'env_missing',
        500,
        'error',
        'high',
        $TENANT_ID
    );
    exception_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase',
    ], 500);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA)) {
    exception_security_event(
        'booking_exception_tenant_denied',
        'tenant_mismatch',
        401,
        'denied',
        'medium',
        $TENANT_ID
    );
    exception_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny',
    ], 401);
}

$REF_SECRET = public_response_ref_secret($SUPABASE_KEY);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$date = trim((string)($input['date'] ?? ''));
$staffId = exception_resolve_staff_request_id(
    $input['staff_ref'] ?? null,
    $input['staff_id'] ?? null,
    $TENANT_ID,
    $SUPABASE_URL,
    $SUPABASE_KEY,
    $SUPABASE_SCHEMA,
    $REF_SECRET
);

if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    exception_security_event(
        'booking_exception_validation_failed',
        'invalid_date',
        400,
        'failed',
        'low',
        $TENANT_ID,
        $staffId,
        'date'
    );
    exception_json([
        'success' => false,
        'error' => 'Nieprawidłowa data',
    ], 400);
}

exception_ensure_staff_belongs_to_tenant($staffId, $TENANT_ID, $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

if ($method === 'DELETE') {
    $url = $SUPABASE_URL
        . '/rest/v1/availability_exceptions?tenant_id=eq.'
        . rawurlencode($TENANT_ID)
        . '&date=eq.'
        . rawurlencode($date)
        . exception_staff_filter($staffId);

    $result = exception_supabase_request('DELETE', $url, $SUPABASE_KEY, $SUPABASE_SCHEMA);

    if ($result['error'] !== '' || $result['httpCode'] >= 400) {
        exception_fail_supabase(
            'Błąd usuwania wyjątku dostępności',
            $result,
            'booking_exception_delete_failed',
            'delete_failed',
            $TENANT_ID,
            $staffId,
            'delete_exception'
        );
    }

    exception_security_event(
        'booking_exception_delete_success',
        'booking_exception_delete_success',
        200,
        'success',
        'medium',
        $TENANT_ID,
        $staffId,
        'delete_exception'
    );

    exception_json([
        'success' => true,
        'date' => $date,
        'staff_ref' => exception_public_staff_ref($staffId, $TENANT_ID, $REF_SECRET),
    ]);
}

$payload = [
    'tenant_id' => $TENANT_ID,
    'date' => $date,
    'staff_id' => $staffId,
    'allow_booking' => true,
];

$existing = exception_find_existing($TENANT_ID, $date, $staffId, $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA);

if ($existing !== null) {
    $url = $SUPABASE_URL
        . '/rest/v1/availability_exceptions?tenant_id=eq.'
        . rawurlencode($TENANT_ID)
        . '&date=eq.'
        . rawurlencode($date)
        . exception_staff_filter($staffId);
    $methodForSave = 'PATCH';
} else {
    $url = $SUPABASE_URL . '/rest/v1/availability_exceptions';
    $methodForSave = 'POST';
}

$result = exception_supabase_request(
    $methodForSave,
    $url,
    $SUPABASE_KEY,
    $SUPABASE_SCHEMA,
    $payload,
    ['Prefer: return=representation']
);

if ($result['error'] !== '' || $result['httpCode'] >= 400) {
    exception_fail_supabase(
        'Błąd zapisu wyjątku dostępności',
        $result,
        'booking_exception_save_failed',
        'save_failed',
        $TENANT_ID,
        $staffId,
        strtolower($methodForSave)
    );
}

$cleanupUrl = $SUPABASE_URL
    . '/rest/v1/blocked_dates?tenant_id=eq.'
    . rawurlencode($TENANT_ID)
    . '&date=eq.'
    . rawurlencode($date)
    . exception_staff_filter($staffId);
exception_supabase_request('DELETE', $cleanupUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);

exception_security_event(
    $methodForSave === 'PATCH' ? 'booking_exception_update_success' : 'booking_exception_create_success',
    $methodForSave === 'PATCH' ? 'booking_exception_update_success' : 'booking_exception_create_success',
    200,
    'success',
    'medium',
    $TENANT_ID,
    $staffId,
    strtolower($methodForSave)
);

exception_json([
    'success' => true,
    'date' => $date,
    'staff_ref' => exception_public_staff_ref($staffId, $TENANT_ID, $REF_SECRET),
]);
