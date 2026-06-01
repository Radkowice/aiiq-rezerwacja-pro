<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_deactivate_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function staff_deactivate_request(
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

function staff_deactivate_public_record(array $row): array
{
    $allowedFields = [
        'id',
        'display_name',
        'email',
        'phone',
        'description',
        'color',
        'sort_order',
        'is_active',
        'visible_on_front',
        'created_at',
        'updated_at',
    ];

    $record = [];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $row)) {
            $record[$field] = $row[$field];
        }
    }

    return $record;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_deactivate_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$role = (string) ($_SESSION['user']['role'] ?? '');

if ($role !== 'administrator') {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Brak uprawnień'
    ], 403);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

if ($tenantId === '') {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

require_tenant_feature($tenantId, 'staff_module');

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON'
    ], 400);
}

$staffId = trim((string) ($input['id'] ?? ''));

if ($staffId === '') {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Brak id pracownika'
    ], 400);
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_deactivate_request('GET', $staffUrl, $supabaseKey, $schema);

if ($staffResult['response'] === false || $staffResult['error'] !== '') {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić pracownika'
    ], $staffResult['httpCode'] > 0 ? $staffResult['httpCode'] : 500);
}

$staffRows = json_decode((string) $staffResult['response'], true);

if (!is_array($staffRows)) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Nieprawidłowa odpowiedź bazy danych'
    ], 500);
}

if (empty($staffRows[0]['id'])) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Nie znaleziono pracownika'
    ], 404);
}

$payload = [
    'is_active' => false,
    'visible_on_front' => false,
];

$url = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&select=id,display_name,email,phone,description,color,sort_order,is_active,visible_on_front,created_at,updated_at';

$result = staff_deactivate_request('PATCH', $url, $supabaseKey, $schema, $payload, true);

if ($result['response'] === false || $result['error'] !== '') {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Nie udało się dezaktywować pracownika'
    ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
}

$savedRows = json_decode((string) $result['response'], true);

if (!is_array($savedRows) || empty($savedRows[0]) || !is_array($savedRows[0])) {
    staff_deactivate_json([
        'success' => false,
        'error' => 'Nieprawidłowa odpowiedź bazy danych'
    ], 500);
}

staff_deactivate_json([
    'success' => true,
    'staff' => staff_deactivate_public_record($savedRows[0])
]);
