<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_common.php';

function services_delete_fail(
    string $message,
    string $code,
    int $statusCode = 409,
    array $extra = []
): void {
    services_json(array_merge([
        'success' => false,
        'error' => $message,
        'code' => $code,
        'reason' => $code,
    ], $extra), $statusCode);
}

function services_delete_fetch_rows(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $table,
    string $query
): array {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . $query;
    $result = services_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '') {
        services_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        services_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić powiązań usługi'
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    return is_array($result['data'] ?? null) ? $result['data'] : [];
}

function services_delete_block_message(): string
{
    return 'Nie można usunąć tej usługi. Usługę możesz usunąć dopiero wtedy, gdy nie będzie miała przypisanych rezerwacji ani pracowników.';
}

function services_delete_has_staff(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $serviceId
): bool {
    $query = 'select=staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=eq.' . rawurlencode($serviceId)
        . '&limit=1';

    return !empty(services_delete_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'tenant_service_staff', $query));
}

function services_delete_has_active_bookings(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $serviceId
): bool {
    date_default_timezone_set('Europe/Warsaw');

    $today = date('Y-m-d');
    $currentTime = date('H:i');
    $futureFilter = '(booking_date.gt.' . $today
        . ',and(booking_date.eq.' . $today
        . ',booking_time.gte.' . $currentTime . '))';

    $query = 'select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=eq.' . rawurlencode($serviceId)
        . '&or=' . rawurlencode($futureFilter)
        . '&status=not.in.' . rawurlencode('(cancelled,canceled,deleted,payment_overdue)')
        . '&limit=1';

    return !empty(services_delete_fetch_rows($supabaseUrl, $supabaseKey, $schema, 'bookings', $query));
}

$context = services_require_context(['POST']);

$supabaseUrl = $context['supabaseUrl'];
$supabaseKey = $context['supabaseKey'];
$schema = $context['schema'];
$tenantId = $context['tenantId'];

require_tenant_feature($tenantId, 'multiple_services');

$input = services_read_json_input();
$serviceId = trim((string) ($input['id'] ?? ''));
$checkOnly = !empty($input['check_only']);

if (!services_is_uuid($serviceId)) {
    services_json([
        'success' => false,
        'error' => 'Nieprawidłowy identyfikator usługi',
        'field' => 'id',
        'code' => 'invalid_service_id',
        'reason' => 'invalid_service_id',
    ], 422);
}

$serviceRows = services_delete_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'tenant_services',
    'select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($serviceId)
        . '&limit=1'
);

if (empty($serviceRows[0]['id'])) {
    services_json([
        'success' => false,
        'error' => 'Nie znaleziono usługi',
        'code' => 'service_not_found',
        'reason' => 'service_not_found',
    ], 404);
}

if (services_delete_has_staff($supabaseUrl, $supabaseKey, $schema, $tenantId, $serviceId)) {
    services_delete_fail(services_delete_block_message(), 'service_has_staff', 409, [
        'has_staff' => true,
        'has_active_bookings' => false,
    ]);
}

if (services_delete_has_active_bookings($supabaseUrl, $supabaseKey, $schema, $tenantId, $serviceId)) {
    services_delete_fail(services_delete_block_message(), 'service_has_active_bookings', 409, [
        'has_staff' => false,
        'has_active_bookings' => true,
    ]);
}

if ($checkOnly) {
    services_json([
        'success' => true,
        'can_delete' => true,
        'has_staff' => false,
        'has_active_bookings' => false,
    ]);
}

$deleteUrl = $supabaseUrl
    . '/rest/v1/tenant_services'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($serviceId);

$deleteResult = services_request('DELETE', $deleteUrl, $supabaseKey, $schema);

if ($deleteResult['response'] === false || $deleteResult['error'] !== '') {
    services_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($deleteResult['httpCode'] < 200 || $deleteResult['httpCode'] >= 300) {
    services_json([
        'success' => false,
        'error' => 'Nie udało się usunąć usługi. Sprawdź, czy usługa nie ma powiązanych rezerwacji lub pracowników.',
        'code' => 'service_delete_failed',
        'reason' => 'service_delete_failed',
    ], $deleteResult['httpCode'] > 0 ? $deleteResult['httpCode'] : 500);
}

services_json([
    'success' => true,
    'message' => 'Usługa została usunięta.',
    'deleted' => true,
    'service_id' => $serviceId,
    'has_staff' => false,
    'has_active_bookings' => false,
]);
