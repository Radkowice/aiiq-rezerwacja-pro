<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_common.php';

function services_save_text($value, string $field, int $maxLength, bool $required = false): ?string
{
    if (is_array($value) || is_object($value)) {
        services_json([
            'success' => false,
            'error' => 'Nieprawidłowe dane wejściowe',
            'field' => $field,
        ], 422);
    }

    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        if ($required) {
            services_json([
                'success' => false,
                'error' => 'Nazwa usługi jest wymagana',
                'field' => $field,
            ], 422);
        }

        return null;
    }

    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        services_json([
            'success' => false,
            'error' => 'Pole ma zbyt dużo znaków',
            'field' => $field,
        ], 422);
    }

    return $text;
}

function services_save_integer($value, string $field, int $min, int $max): int
{
    if (is_bool($value) || is_array($value) || is_object($value)
        || (!is_int($value) && !preg_match('/^-?\d+$/', (string) $value))
    ) {
        services_json([
            'success' => false,
            'error' => $field . ' musi być liczbą całkowitą',
            'field' => $field,
        ], 422);
    }

    $integer = (int) $value;

    if ($integer < $min || $integer > $max) {
        services_json([
            'success' => false,
            'error' => $field . ' ma nieprawidłową wartość',
            'field' => $field,
        ], 422);
    }

    return $integer;
}

function services_save_price($value): ?float
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }

    if (is_bool($value) || is_array($value) || is_object($value)) {
        services_json([
            'success' => false,
            'error' => 'price_amount musi być liczbą',
            'field' => 'price_amount',
        ], 422);
    }

    $normalized = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;

    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
        services_json([
            'success' => false,
            'error' => 'Cena może mieć maksymalnie 2 miejsca po przecinku',
            'field' => 'price_amount',
        ], 422);
    }

    $price = (float) $normalized;

    if ($price < 0) {
        services_json([
            'success' => false,
            'error' => 'Cena nie może być ujemna',
            'field' => 'price_amount',
        ], 422);
    }

    return round($price, 2);
}

function services_save_boolean($value, string $field): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) && in_array($value, [0, 1], true)) {
        return $value === 1;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['true', '1'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0'], true)) {
            return false;
        }
    }

    if (!is_bool($value)) {
        services_json([
            'success' => false,
            'error' => $field . ' musi być wartością logiczną',
            'field' => $field,
        ], 422);
    }

    return (bool) $value;
}

function services_save_staff_ids($value): array
{
    if (!is_array($value)) {
        services_json([
            'success' => false,
            'error' => 'staff_ids musi być tablicą',
            'field' => 'staff_ids',
        ], 422);
    }

    $staffIds = [];

    foreach ($value as $staffId) {
        if (!services_is_uuid($staffId)) {
            services_json([
                'success' => false,
                'error' => 'staff_ids zawiera nieprawidłowy identyfikator',
                'field' => 'staff_ids',
            ], 422);
        }

        $staffIds[(string) $staffId] = true;
    }

    return array_keys($staffIds);
}

function services_save_fetch_staff(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    array $staffIds
): array {
    if (empty($staffIds)) {
        return [];
    }

    $staffIdList = implode(',', array_map('rawurlencode', $staffIds));

    $staffUrl = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id,display_name,email,phone,is_active,visible_on_front'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
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
            'error' => 'Nie udało się sprawdzić pracowników'
        ], $staffResult['httpCode'] > 0 ? $staffResult['httpCode'] : 500);
    }

    $staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
    $staffById = [];

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

    foreach ($staffIds as $staffId) {
        if (!isset($staffById[$staffId])) {
            services_json([
                'success' => false,
                'error' => 'Wybrany pracownik nie istnieje, jest nieaktywny albo należy do innego tenanta',
                'field' => 'staff_ids',
            ], 422);
        }
    }

    return $staffById;
}

function services_save_decode_rpc_result($data)
{
    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        $data = $data[0];
    }

    if (!is_array($data)) {
        return null;
    }

    if (isset($data['success'], $data['service_id'])) {
        return $data;
    }

    foreach ($data as $value) {
        if (!is_array($value)) {
            continue;
        }

        $decoded = services_save_decode_rpc_result($value);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function services_save_count_active_services(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): int {
    $url = $supabaseUrl
        . '/rest/v1/tenant_services'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true';

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
            'error' => 'Nie udało się sprawdzić limitu usług'
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    return is_array($result['data'] ?? null) ? count($result['data']) : 0;
}

function services_save_enforce_services_limit(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): void {
    $context = plan_features_get_context($tenantId);
    $limit = $context['limits']['services_count'] ?? null;

    if ($limit === null) {
        return;
    }

    $limit = (int) $limit;

    if ($limit <= 0) {
        services_json([
            'success' => false,
            'error' => 'Twój obecny plan nie obejmuje dodawania kolejnych usług. Potrzebujesz większego limitu? Napisz do producenta.',
            'limit_reached' => true,
            'limit_type' => 'services_count',
        ], 403);
    }

    $activeServicesCount = services_save_count_active_services($supabaseUrl, $supabaseKey, $schema, $tenantId);

    if ($activeServicesCount >= $limit) {
        services_json([
            'success' => false,
            'error' => 'Osiągnąłeś limit w swoim planie: maksymalnie ' . $limit . ' usług. Potrzebujesz większego limitu? Napisz do producenta: kontakt@ai-iq.pl',
            'limit_reached' => true,
            'limit_type' => 'services_count',
        ], 403);
    }
}

$context = services_require_context(['POST']);

$supabaseUrl = $context['supabaseUrl'];
$supabaseKey = $context['supabaseKey'];
$schema = $context['schema'];
$tenantId = $context['tenantId'];

require_tenant_feature($tenantId, 'multiple_services');

$input = services_read_json_input();
$serviceId = trim((string) ($input['id'] ?? ''));
$isUpdate = $serviceId !== '';

if ($isUpdate && !services_is_uuid($serviceId)) {
    services_json([
        'success' => false,
        'error' => 'Nieprawidłowy identyfikator usługi',
        'field' => 'id',
    ], 422);
}

$name = services_save_text($input['name'] ?? null, 'name', 160, true);
$description = services_save_text($input['description'] ?? null, 'description', 2000);
$durationMinutes = services_save_integer($input['duration_minutes'] ?? 60, 'duration_minutes', 1, 1440);
$breakMinutes = services_save_integer($input['break_minutes'] ?? 0, 'break_minutes', 0, 1440);
$bookingBufferMinutes = services_save_integer($input['booking_buffer_minutes'] ?? 0, 'booking_buffer_minutes', 0, 10080);
$priceAmount = services_save_price($input['price_amount'] ?? null);
$priceCurrency = strtoupper(trim((string) ($input['price_currency'] ?? 'PLN')));

if (!preg_match('/^[A-Z]{3}$/', $priceCurrency)) {
    services_json([
        'success' => false,
        'error' => 'Waluta musi mieć 3 znaki',
        'field' => 'price_currency',
    ], 422);
}

$paymentsEnabled = services_save_boolean($input['payments_enabled'] ?? false, 'payments_enabled');

if ($paymentsEnabled && ($priceAmount === null || (float) $priceAmount <= 0)) {
    services_json([
        'success' => false,
        'error' => 'Podaj kwotę usługi większą od 0, jeśli płatność jest włączona',
        'field' => 'price_amount',
    ], 422);
}

$paymentMessage = services_save_text($input['payment_message'] ?? null, 'payment_message', 2000);
$isActive = services_save_boolean($input['is_active'] ?? true, 'is_active');
$visibleOnFront = services_save_boolean($input['visible_on_front'] ?? true, 'visible_on_front');
$sortOrder = services_save_integer($input['sort_order'] ?? 0, 'sort_order', -1000000, 1000000);
$staffIds = services_save_staff_ids($input['staff_ids'] ?? []);
$staffById = services_save_fetch_staff($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffIds);

$existingIsActive = false;

if ($isUpdate) {
    $existingUrl = $supabaseUrl
        . '/rest/v1/tenant_services'
        . '?select=id,is_active'
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

    $existingIsActive = !empty($existingRows[0]['is_active']);
}

if ($isActive && (!$isUpdate || !$existingIsActive)) {
    services_save_enforce_services_limit($supabaseUrl, $supabaseKey, $schema, $tenantId);
}

$rpcPayload = [
    'p_tenant_id' => $tenantId,
    'p_service_id' => $isUpdate ? $serviceId : null,
    'p_name' => $name,
    'p_description' => $description,
    'p_duration_minutes' => $durationMinutes,
    'p_break_minutes' => $breakMinutes,
    'p_booking_buffer_minutes' => $bookingBufferMinutes,
    'p_price_amount' => $priceAmount,
    'p_price_currency' => $priceCurrency,
    'p_payments_enabled' => $paymentsEnabled,
    'p_payment_message' => $paymentMessage,
    'p_is_active' => $isActive,
    'p_visible_on_front' => $visibleOnFront,
    'p_sort_order' => $sortOrder,
    'p_staff_ids' => $staffIds,
];

$rpcUrl = $supabaseUrl . '/rest/v1/rpc/save_tenant_service_with_staff';
$rpcResult = services_request(
    'POST',
    $rpcUrl,
    $supabaseKey,
    $schema,
    $rpcPayload,
    false,
    [
        'X-Tenant-Id: ' . $tenantId,
        'X-App-Tenant-Id: ' . $tenantId,
    ]
);

if ($rpcResult['response'] === false || $rpcResult['error'] !== '') {
    services_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($rpcResult['httpCode'] < 200 || $rpcResult['httpCode'] >= 300) {
    services_json([
        'success' => false,
        'error' => 'Nie udało się zapisać usługi',
    ], $rpcResult['httpCode'] > 0 ? $rpcResult['httpCode'] : 500);
}

$rpcData = $rpcResult['data'] ?? null;
$rpcDecoded = services_save_decode_rpc_result($rpcData);
$serviceIdFromRpc = is_array($rpcDecoded) ? (string) ($rpcDecoded['service_id'] ?? '') : '';

if (!is_array($rpcDecoded) || ($rpcDecoded['success'] ?? null) !== true || !services_is_uuid($serviceIdFromRpc)) {
    services_json([
        'success' => false,
        'error' => 'Nie udało się zapisać usługi',
    ], 500);
}

services_json([
    'success' => true,
    'service_id' => $serviceIdFromRpc
]);
