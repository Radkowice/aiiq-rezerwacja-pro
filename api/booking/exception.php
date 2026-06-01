<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
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

function exception_normalize_staff_id($value): ?string
{
    $staffId = trim((string)($value ?? ''));

    if ($staffId === '' || $staffId === 'null' || $staffId === 'undefined') {
        return null;
    }

    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $staffId)) {
        exception_json([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik',
        ], 400);
    }

    return $staffId;
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
        exception_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić pracownika',
        ], 500);
    }

    if (empty($rows[0]['id'])) {
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
        exception_json([
            'success' => false,
            'error' => 'Błąd odczytu wyjątku dostępności',
        ], 500);
    }

    $rows = is_array($result['data']) ? $result['data'] : [];

    return !empty($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function exception_fail_supabase(string $message, array $result): void
{
    $data = is_array($result['data']) ? $result['data'] : [];
    $details = trim((string)($data['message'] ?? $data['details'] ?? $result['error'] ?? $result['response'] ?? ''));

    exception_json([
        'success' => false,
        'error' => $details !== '' ? $message . ': ' . substr($details, 0, 400) : $message,
        'httpCode' => $result['httpCode'] ?? 500,
    ], 500);
}

if (!in_array($method, ['POST', 'DELETE'], true)) {
    header('Allow: POST, DELETE');
    exception_json([
        'success' => false,
        'error' => 'Metoda niedozwolona',
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
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
    exception_json([
        'success' => false,
        'error' => 'Brak tenant_id w sesji',
    ], 400);
}

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    exception_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase',
    ], 500);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_SCHEMA)) {
    exception_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny',
    ], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$date = trim((string)($input['date'] ?? ''));
$staffId = exception_normalize_staff_id($input['staff_id'] ?? null);

if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
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
        exception_fail_supabase('Błąd usuwania wyjątku dostępności', $result);
    }

    exception_json([
        'success' => true,
        'date' => $date,
        'staff_id' => $staffId,
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
    exception_fail_supabase('Błąd zapisu wyjątku dostępności', $result);
}

$cleanupUrl = $SUPABASE_URL
    . '/rest/v1/blocked_dates?tenant_id=eq.'
    . rawurlencode($TENANT_ID)
    . '&date=eq.'
    . rawurlencode($date)
    . exception_staff_filter($staffId);
exception_supabase_request('DELETE', $cleanupUrl, $SUPABASE_KEY, $SUPABASE_SCHEMA);

exception_json([
    'success' => true,
    'date' => $date,
    'staff_id' => $staffId,
]);
