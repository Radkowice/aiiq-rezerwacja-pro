<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

function staff_public_list_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_public_list_request(string $url, string $key, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => supabaseHeaders($key, $schema),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

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

function staff_public_list_feature_locked(): void
{
    staff_public_list_json([
        'success' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny w planie Pro. Twój abonament Pro wygasł albo konto działa w planie Free. Opłać abonament Pro, aby odzyskać dostęp do funkcji personelu.',
    ], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_public_list_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_public_list_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    staff_public_list_json([
        'success' => false,
        'error' => 'Nie znaleziono klienta dla tej domeny'
    ], 404);
}

$tenantId = (string) $tenantId;
$refSecret = public_response_ref_secret($supabaseKey);

if (!tenant_has_feature($tenantId, 'staff_module')) {
    staff_public_list_feature_locked();
}

$select = implode(',', [
    'id',
    'display_name',
    'description',
    'color',
    'sort_order',
    'service_name',
    'service_description',
    'service_duration_minutes',
    'service_break_minutes',
    'booking_buffer_minutes',
    'service_price',
    'payments_enabled',
]);

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=' . rawurlencode($select)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&is_active=eq.true'
    . '&visible_on_front=eq.true'
    . '&order=sort_order.asc'
    . '&order=display_name.asc';

$staffResult = staff_public_list_request($staffUrl, $supabaseKey, $schema);

if ($staffResult['error'] !== '' || $staffResult['httpCode'] >= 400) {
    staff_public_list_json([
        'success' => false,
        'error' => 'Nie udało się pobrać personelu'
    ], 500);
}

$rows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = [];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $staffId = (string) ($row['id'] ?? '');
    $staffRef = $staffId !== ''
        ? public_response_staff_ref($tenantId, $staffId, $refSecret)
        : '';

    $staff[] = [
        'id' => $staffRef,
        'staff_ref' => $staffRef,
        'display_name' => (string) ($row['display_name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'color' => (string) ($row['color'] ?? ''),
        'service_name' => array_key_exists('service_name', $row) && $row['service_name'] !== null
            ? (string) $row['service_name']
            : null,
        'service_description' => array_key_exists('service_description', $row) && $row['service_description'] !== null
            ? (string) $row['service_description']
            : null,
        'service_duration_minutes' => array_key_exists('service_duration_minutes', $row) && $row['service_duration_minutes'] !== null
            ? (int) $row['service_duration_minutes']
            : null,
        'service_break_minutes' => array_key_exists('service_break_minutes', $row) && $row['service_break_minutes'] !== null
            ? (int) $row['service_break_minutes']
            : null,
        'booking_buffer_minutes' => array_key_exists('booking_buffer_minutes', $row) && $row['booking_buffer_minutes'] !== null
            ? (int) $row['booking_buffer_minutes']
            : null,
        'service_price' => array_key_exists('service_price', $row) && $row['service_price'] !== null
            ? (string) $row['service_price']
            : null,
        'payments_enabled' => array_key_exists('payments_enabled', $row) && $row['payments_enabled'] !== null
            ? (bool) $row['payments_enabled']
            : null,
    ];
}

staff_public_list_json([
    'success' => true,
    'staff_enabled' => count($staff) > 0,
    'staff' => $staff
]);
