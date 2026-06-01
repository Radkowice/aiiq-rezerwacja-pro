<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
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

function staff_public_list_subscription_allows_staff(?string $planCode, ?string $status): bool
{
    $planValue = strtolower(trim((string) $planCode));
    $statusValue = strtolower(trim((string) $status));

    return in_array($planValue, ['pro', 'vip', 'business'], true)
        && in_array($statusValue, ['active', 'trial'], true);
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

$subscriptionUrl = $supabaseUrl
    . '/rest/v1/tenant_subscriptions'
    . '?select=plan_code,status'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$subscriptionResult = staff_public_list_request($subscriptionUrl, $supabaseKey, $schema);

if ($subscriptionResult['error'] !== '' || $subscriptionResult['httpCode'] >= 400) {
    staff_public_list_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić abonamentu'
    ], 500);
}

$subscription = is_array($subscriptionResult['data'] ?? null)
    ? ($subscriptionResult['data'][0] ?? null)
    : null;

$planCode = is_array($subscription) ? (string) ($subscription['plan_code'] ?? 'free') : 'free';
$status = is_array($subscription) ? (string) ($subscription['status'] ?? '') : '';

if (!staff_public_list_subscription_allows_staff($planCode, $status)) {
    staff_public_list_json([
        'success' => true,
        'staff_enabled' => false,
        'staff' => []
    ]);
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

    $staff[] = [
        'id' => (string) ($row['id'] ?? ''),
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
