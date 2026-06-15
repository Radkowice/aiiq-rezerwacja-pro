<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/_common.php';

$context = services_require_context(['GET']);

$supabaseUrl = $context['supabaseUrl'];
$supabaseKey = $context['supabaseKey'];
$schema = $context['schema'];
$tenantId = $context['tenantId'];

$servicesUrl = $supabaseUrl
    . '/rest/v1/tenant_services'
    . '?select=' . rawurlencode(services_select_fields())
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&order=sort_order.asc'
    . '&order=name.asc';

$servicesResult = services_request('GET', $servicesUrl, $supabaseKey, $schema);

if ($servicesResult['response'] === false || $servicesResult['error'] !== '') {
    services_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($servicesResult['httpCode'] < 200 || $servicesResult['httpCode'] >= 300) {
    services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać usług'
    ], $servicesResult['httpCode'] > 0 ? $servicesResult['httpCode'] : 500);
}

$serviceRows = is_array($servicesResult['data'] ?? null) ? $servicesResult['data'] : [];

if (empty($serviceRows)) {
    services_json([
        'success' => true,
        'services' => []
    ]);
}

$relationsUrl = $supabaseUrl
    . '/rest/v1/tenant_service_staff'
    . '?select=service_id,staff_id'
    . '&tenant_id=eq.' . rawurlencode($tenantId);

$relationsResult = services_request('GET', $relationsUrl, $supabaseKey, $schema);

if ($relationsResult['response'] === false || $relationsResult['error'] !== '') {
    services_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($relationsResult['httpCode'] < 200 || $relationsResult['httpCode'] >= 300) {
    services_json([
        'success' => false,
        'error' => 'Nie udało się pobrać przypisań pracowników'
    ], $relationsResult['httpCode'] > 0 ? $relationsResult['httpCode'] : 500);
}

$serviceIds = [];

foreach ($serviceRows as $serviceRow) {
    if (is_array($serviceRow) && !empty($serviceRow['id'])) {
        $serviceIds[(string) $serviceRow['id']] = true;
    }
}

$staffIdsByService = [];
$allStaffIds = [];
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

$staffById = [];

if (!empty($allStaffIds)) {
    $staffIdList = implode(',', array_map('rawurlencode', array_keys($allStaffIds)));

    $staffUrl = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id,display_name,email,phone,is_active,visible_on_front'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=in.(' . $staffIdList . ')';

    $staffResult = services_request('GET', $staffUrl, $supabaseKey, $schema);

    if ($staffResult['response'] === false || $staffResult['error'] !== '') {
        services_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300) {
        services_json([
            'success' => false,
            'error' => 'Nie udało się pobrać pracowników przypisanych do usług'
        ], $staffResult['httpCode'] > 0 ? $staffResult['httpCode'] : 500);
    }

    $staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];

    foreach ($staffRows as $staffRow) {
        if (!is_array($staffRow) || empty($staffRow['id'])) {
            continue;
        }

        $staffById[(string) $staffRow['id']] = [
            'id' => (string) ($staffRow['id'] ?? ''),
            'display_name' => (string) ($staffRow['display_name'] ?? ''),
            'email' => (string) ($staffRow['email'] ?? ''),
            'phone' => (string) ($staffRow['phone'] ?? ''),
            'is_active' => (bool) ($staffRow['is_active'] ?? false),
            'visible_on_front' => (bool) ($staffRow['visible_on_front'] ?? false),
        ];
    }
}

$services = [];

foreach ($serviceRows as $serviceRow) {
    if (!is_array($serviceRow) || empty($serviceRow['id'])) {
        continue;
    }

    $serviceId = (string) $serviceRow['id'];
    $staffIds = array_keys($staffIdsByService[$serviceId] ?? []);
    $staff = [];

    foreach ($staffIds as $staffId) {
        if (isset($staffById[$staffId])) {
            $staff[] = $staffById[$staffId];
        }
    }

    $services[] = services_normalize_record($serviceRow, $staffIds, $staff);
}

services_json([
    'success' => true,
    'services' => $services
]);
