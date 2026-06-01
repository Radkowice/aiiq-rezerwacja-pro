<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_common.php';

$context = services_require_context(['POST']);

$supabaseUrl = $context['supabaseUrl'];
$supabaseKey = $context['supabaseKey'];
$schema = $context['schema'];
$tenantId = $context['tenantId'];

require_tenant_feature($tenantId, 'multiple_services');

$input = services_read_json_input();

$serviceId = trim((string) ($input['id'] ?? ''));

if (!services_is_uuid($serviceId)) {
    services_json([
        'success' => false,
        'error' => 'Nieprawidłowy identyfikator usługi',
        'field' => 'id',
    ], 422);
}

$existingUrl = $supabaseUrl
    . '/rest/v1/tenant_services'
    . '?select=id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($serviceId)
    . '&limit=1';

$existingResult = services_request('GET', $existingUrl, $supabaseKey, $schema);

if ($existingResult['response'] === false || $existingResult['error'] !== '') {
    services_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($existingResult['httpCode'] < 200 || $existingResult['httpCode'] >= 300) {
    services_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić usługi'
    ], $existingResult['httpCode'] > 0 ? $existingResult['httpCode'] : 500);
}

$existingRows = is_array($existingResult['data'] ?? null) ? $existingResult['data'] : [];

if (empty($existingRows[0]['id'])) {
    services_json([
        'success' => false,
        'error' => 'Nie znaleziono usługi'
    ], 404);
}

$payload = [
    'is_active' => false,
    'visible_on_front' => false,
    'updated_at' => gmdate('c'),
];

$serviceUrl = $supabaseUrl
    . '/rest/v1/tenant_services'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($serviceId)
    . '&select=' . rawurlencode(services_select_fields());

$serviceResult = services_request('PATCH', $serviceUrl, $supabaseKey, $schema, $payload, true);

if ($serviceResult['response'] === false || $serviceResult['error'] !== '') {
    services_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($serviceResult['httpCode'] < 200 || $serviceResult['httpCode'] >= 300) {
    services_json([
        'success' => false,
        'error' => 'Nie udało się wyłączyć usługi'
    ], $serviceResult['httpCode'] > 0 ? $serviceResult['httpCode'] : 500);
}

$savedRows = is_array($serviceResult['data'] ?? null) ? $serviceResult['data'] : [];

if (empty($savedRows[0]) || !is_array($savedRows[0])) {
    services_json([
        'success' => false,
        'error' => 'Nieprawidłowa odpowiedź bazy danych'
    ], 500);
}

services_json([
    'success' => true,
    'service' => services_normalize_record($savedRows[0])
]);
