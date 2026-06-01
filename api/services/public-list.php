<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

function public_services_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function public_services_request(string $url, string $key, string $schema): array
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

function public_services_price($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function public_services_nullable_int($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (int) $value : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    public_services_json([
        'success' => false,
        'error' => 'Metoda niedozwolona',
    ], 405);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    public_services_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase',
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    public_services_json([
        'success' => false,
        'error' => 'Nie znaleziono klienta dla tej domeny',
    ], 404);
}

$tenantId = (string) $tenantId;

if (!tenant_has_feature($tenantId, 'multiple_services')) {
    public_services_json([
        'success' => true,
        'services' => [],
    ]);
}

$globalPaymentsEnabled = false;
$payuEnabled = false;
$onlinePaymentsEnabled = tenant_has_feature($tenantId, 'online_payments') && tenant_has_feature($tenantId, 'payu');

$serviceSettingsUrl = $supabaseUrl
    . '/rest/v1/tenant_service_settings'
    . '?select=payment_required'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$serviceSettingsResult = public_services_request($serviceSettingsUrl, $supabaseKey, $schema);

if ($serviceSettingsResult['response'] === false || $serviceSettingsResult['error'] !== '') {
    public_services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać ustawień płatności',
    ], 500);
}

if ($serviceSettingsResult['httpCode'] < 200 || $serviceSettingsResult['httpCode'] >= 300) {
    public_services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać ustawień płatności',
    ], 500);
}

$serviceSettingsRows = is_array($serviceSettingsResult['data'] ?? null) ? $serviceSettingsResult['data'] : [];
$serviceSettings = $serviceSettingsRows[0] ?? null;

if (is_array($serviceSettings)) {
    $globalPaymentsEnabled = !empty($serviceSettings['payment_required']);
}

$payuUrl = $supabaseUrl
    . '/rest/v1/tenant_integrations'
    . '?select=enabled'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&provider=eq.payu'
    . '&limit=1';

$payuResult = public_services_request($payuUrl, $supabaseKey, $schema);

if ($payuResult['response'] === false || $payuResult['error'] !== '') {
    public_services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać ustawień płatności',
    ], 500);
}

if ($payuResult['httpCode'] < 200 || $payuResult['httpCode'] >= 300) {
    public_services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać ustawień płatności',
    ], 500);
}

$payuRows = is_array($payuResult['data'] ?? null) ? $payuResult['data'] : [];
$payuIntegration = $payuRows[0] ?? null;

if (is_array($payuIntegration)) {
    $payuEnabled = !empty($payuIntegration['enabled']);
}

$serviceSelect = implode(',', [
    'id',
    'name',
    'description',
    'duration_minutes',
    'break_minutes',
    'booking_buffer_minutes',
    'price_amount',
    'price_currency',
    'payments_enabled',
    'sort_order',
]);

$servicesUrl = $supabaseUrl
    . '/rest/v1/tenant_services'
    . '?select=' . rawurlencode($serviceSelect)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&is_active=eq.true'
    . '&visible_on_front=eq.true'
    . '&order=sort_order.asc'
    . '&order=name.asc';

$servicesResult = public_services_request($servicesUrl, $supabaseKey, $schema);

if ($servicesResult['response'] === false || $servicesResult['error'] !== '') {
    public_services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać usług',
    ], 500);
}

if ($servicesResult['httpCode'] < 200 || $servicesResult['httpCode'] >= 300) {
    public_services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać usług',
    ], 500);
}

$serviceRows = is_array($servicesResult['data'] ?? null) ? $servicesResult['data'] : [];

if (empty($serviceRows)) {
    public_services_json([
        'success' => true,
        'services' => [],
    ]);
}

$serviceIds = [];

foreach ($serviceRows as $serviceRow) {
    if (is_array($serviceRow) && !empty($serviceRow['id'])) {
        $serviceIds[(string) $serviceRow['id']] = true;
    }
}

$staffIdsByService = [];
$allStaffIds = [];

if (!empty($serviceIds)) {
    $serviceIdList = implode(',', array_map('rawurlencode', array_keys($serviceIds)));

    $relationsUrl = $supabaseUrl
        . '/rest/v1/tenant_service_staff'
        . '?select=service_id,staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=in.(' . $serviceIdList . ')';

    $relationsResult = public_services_request($relationsUrl, $supabaseKey, $schema);

    if ($relationsResult['response'] === false || $relationsResult['error'] !== '') {
        public_services_json([
            'success' => false,
            'error' => 'Nie udało się pobrać przypisań personelu',
        ], 500);
    }

    if ($relationsResult['httpCode'] < 200 || $relationsResult['httpCode'] >= 300) {
        public_services_json([
            'success' => false,
            'error' => 'Nie udało się pobrać przypisań personelu',
        ], 500);
    }

    $relationRows = is_array($relationsResult['data'] ?? null) ? $relationsResult['data'] : [];

    foreach ($relationRows as $relationRow) {
        if (!is_array($relationRow)) {
            continue;
        }

        $serviceId = (string) ($relationRow['service_id'] ?? '');
        $staffId = (string) ($relationRow['staff_id'] ?? '');

        if ($serviceId === '' || $staffId === '' || !isset($serviceIds[$serviceId])) {
            continue;
        }

        $staffIdsByService[$serviceId] ??= [];
        $staffIdsByService[$serviceId][$staffId] = true;
        $allStaffIds[$staffId] = true;
    }
}

$staffById = [];

if (!empty($allStaffIds)) {
    $staffIdList = implode(',', array_map('rawurlencode', array_keys($allStaffIds)));
    $staffSelect = implode(',', [
        'id',
        'display_name',
        'description',
        'color',
        'sort_order',
    ]);

    // Pracownik jest widoczny przy usłudze, jeśli jest aktywny i przypisany relacją tenant_service_staff.
    $staffUrl = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=' . rawurlencode($staffSelect)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=in.(' . $staffIdList . ')'
        . '&is_active=eq.true'
        . '&order=sort_order.asc'
        . '&order=display_name.asc';

    $staffResult = public_services_request($staffUrl, $supabaseKey, $schema);

    if ($staffResult['response'] === false || $staffResult['error'] !== '') {
        public_services_json([
            'success' => false,
            'error' => 'Nie udało się pobrać personelu',
        ], 500);
    }

    if ($staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300) {
        public_services_json([
            'success' => false,
            'error' => 'Nie udało się pobrać personelu',
        ], 500);
    }

    $staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];

    foreach ($staffRows as $staffRow) {
        if (!is_array($staffRow) || empty($staffRow['id'])) {
            continue;
        }

        $staffById[(string) $staffRow['id']] = [
            'id' => (string) ($staffRow['id'] ?? ''),
            'display_name' => (string) ($staffRow['display_name'] ?? ''),
            'description' => (string) ($staffRow['description'] ?? ''),
            'color' => (string) ($staffRow['color'] ?? ''),
            'sort_order' => public_services_nullable_int($staffRow['sort_order'] ?? null) ?? 0,
        ];
    }
}

$services = [];

foreach ($serviceRows as $serviceRow) {
    if (!is_array($serviceRow) || empty($serviceRow['id'])) {
        continue;
    }

    $serviceId = (string) $serviceRow['id'];
    $assignedStaff = [];

    foreach (array_keys($staffIdsByService[$serviceId] ?? []) as $staffId) {
        if (isset($staffById[$staffId])) {
            $assignedStaff[] = $staffById[$staffId];
        }
    }

    usort($assignedStaff, static function (array $a, array $b): int {
        $sortCompare = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));

        if ($sortCompare !== 0) {
            return $sortCompare;
        }

        return strcmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
    });

    $priceAmount = public_services_price($serviceRow['price_amount'] ?? null);
    $servicePaymentsEnabled = !empty($serviceRow['payments_enabled']);
    $paymentRequired = $onlinePaymentsEnabled
        && $payuEnabled
        && $globalPaymentsEnabled
        && $servicePaymentsEnabled
        && $priceAmount !== null
        && $priceAmount > 0;

    $services[] = [
        'id' => $serviceId,
        'name' => (string) ($serviceRow['name'] ?? ''),
        'description' => (string) ($serviceRow['description'] ?? ''),
        'price_amount' => $priceAmount,
        'price_currency' => (string) ($serviceRow['price_currency'] ?? 'PLN'),
        'payments_enabled' => $servicePaymentsEnabled,
        'payment_required' => $paymentRequired,
        'duration' => public_services_nullable_int($serviceRow['duration_minutes'] ?? null),
        'break_minutes' => public_services_nullable_int($serviceRow['break_minutes'] ?? null),
        'booking_buffer_minutes' => public_services_nullable_int($serviceRow['booking_buffer_minutes'] ?? null),
        'sort_order' => public_services_nullable_int($serviceRow['sort_order'] ?? null) ?? 0,
        'assigned_staff' => $assignedStaff,
    ];
}

public_services_json([
    'success' => true,
    'services' => $services,
]);
