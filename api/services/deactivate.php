<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_common.php';

function services_deactivate_service_id_from_ref(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $serviceRef,
    string $refSecret
): string {
    $normalizedRef = trim($serviceRef);

    if ($normalizedRef === '') {
        services_json([
            'success' => false,
            'error' => 'Nieprawidłowa usługa.',
        ], 400);
    }

    $servicesUrl = $supabaseUrl
        . '/rest/v1/tenant_services'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId);

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
            'error' => 'Nie udało się sprawdzić usługi'
        ], $servicesResult['httpCode'] > 0 ? $servicesResult['httpCode'] : 500);
    }

    foreach ((is_array($servicesResult['data'] ?? null) ? $servicesResult['data'] : []) as $serviceRow) {
        if (!is_array($serviceRow) || empty($serviceRow['id'])) {
            continue;
        }

        $serviceId = (string) $serviceRow['id'];
        $expectedRef = public_response_service_ref($tenantId, $serviceId, $refSecret);

        if (hash_equals($expectedRef, $normalizedRef)) {
            return $serviceId;
        }
    }

    services_json([
        'success' => false,
        'error' => 'Nieprawidłowa usługa.',
    ], 400);
}

$context = services_require_context(['POST']);

$supabaseUrl = $context['supabaseUrl'];
$supabaseKey = $context['supabaseKey'];
$schema = $context['schema'];
$tenantId = $context['tenantId'];
$refSecret = public_response_ref_secret($supabaseKey);
$serviceSecurityContext = services_security_context($context, [
    'action_key' => 'service_deactivate',
    'endpoint' => '/api/services/deactivate.php',
]);

require_tenant_feature($tenantId, 'multiple_services');

$input = services_read_json_input();

$serviceRefInput = $input['service_ref'] ?? '';

if (is_array($serviceRefInput) || is_object($serviceRefInput)) {
    services_security_event('service_deactivate_ref_invalid', array_merge($serviceSecurityContext, [
        'severity' => 'medium',
        'response_status' => 400,
        'result' => 'failed',
        'reason' => 'service_ref_invalid',
    ]));
    services_json([
        'success' => false,
        'error' => 'Nieprawidłowa usługa.',
    ], 400);
}

$serviceRef = trim((string) $serviceRefInput);
$serviceId = $serviceRef !== ''
    ? services_deactivate_service_id_from_ref($supabaseUrl, $supabaseKey, $schema, $tenantId, $serviceRef, $refSecret)
    : '';

if (!services_is_uuid($serviceId)) {
    services_security_event('service_deactivate_ref_invalid', array_merge($serviceSecurityContext, [
        'severity' => 'medium',
        'response_status' => 422,
        'result' => 'failed',
        'reason' => 'service_ref_invalid',
    ]));
    services_json([
        'success' => false,
        'error' => 'Nieprawidłowa usługa.',
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
    services_security_event('service_deactivate_not_found', array_merge($serviceSecurityContext, [
        'severity' => 'medium',
        'response_status' => 404,
        'result' => 'failed',
        'reason' => 'service_not_found',
    ]));
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
    services_security_event('service_deactivate_failed', array_merge($serviceSecurityContext, [
        'severity' => 'high',
        'response_status' => 500,
        'result' => 'error',
        'reason' => 'supabase_error',
        'stage' => 'deactivate_service',
    ]));
    services_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($serviceResult['httpCode'] < 200 || $serviceResult['httpCode'] >= 300) {
    services_security_event('service_deactivate_failed', array_merge($serviceSecurityContext, [
        'severity' => 'high',
        'response_status' => $serviceResult['httpCode'] > 0 ? $serviceResult['httpCode'] : 500,
        'result' => 'failed',
        'reason' => 'supabase_rejected',
        'stage' => 'deactivate_service',
    ]));
    services_json([
        'success' => false,
        'error' => 'Nie udało się wyłączyć usługi'
    ], $serviceResult['httpCode'] > 0 ? $serviceResult['httpCode'] : 500);
}

$savedRows = is_array($serviceResult['data'] ?? null) ? $serviceResult['data'] : [];

if (empty($savedRows[0]) || !is_array($savedRows[0])) {
    services_security_event('service_deactivate_failed', array_merge($serviceSecurityContext, [
        'severity' => 'high',
        'response_status' => 500,
        'result' => 'error',
        'reason' => 'invalid_db_response',
        'stage' => 'deactivate_service',
    ]));
    services_json([
        'success' => false,
        'error' => 'Nieprawidłowa odpowiedź bazy danych'
    ], 500);
}

$serviceRef = public_response_service_ref($tenantId, $serviceId, $refSecret);
$service = services_normalize_record($savedRows[0]);
unset($service['id'], $service['staff_ids'], $service['service_ref']);

services_security_event('service_deactivate_success', array_merge($serviceSecurityContext, [
    'severity' => 'medium',
    'response_status' => 200,
    'result' => 'success',
    'reason' => 'service_deactivate_success',
]));

services_json([
    'success' => true,
    'service_ref' => $serviceRef,
    'service' => $service
]);
