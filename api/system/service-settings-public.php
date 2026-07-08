<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/../helpers/plan_features.php';

$supabaseUrl = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$serviceRoleKey = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendPublicServiceJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function publicServiceSupabaseRequest(
    string $method,
    string $url,
    string $serviceRoleKey,
    string $schema
): array {
    $ch = curl_init($url);

    $headers = [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'error' => $curlError,
        'body' => $response,
        'json' => json_decode((string) $response, true),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendPublicServiceJson([
            'success' => false,
            'error' => 'Metoda niedozwolona.'
        ], 405);
    }

if (!function_exists('getTenantIdFromHost')) {
    sendPublicServiceJson([
        'success' => false,
        'error' => 'Nie udało się ustalić tenanta dla domeny.'
    ], 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $serviceRoleKey, $schema);

if (!$tenantId) {
    sendPublicServiceJson([
        'success' => false,
        'error' => 'Nie znaleziono tenanta dla tej domeny.'
    ], 404);
}

$tenantId = (string) $tenantId;

    $url = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?tenant_id=eq.' . urlencode($tenantId)
        . '&select=service_name,service_description,price_amount,price_currency,payment_required,payment_message'
        . '&limit=1';

    $result = publicServiceSupabaseRequest(
        'GET',
        $url,
        $serviceRoleKey,
        $schema
    );

    if (!$result['ok']) {
        sendPublicServiceJson([
            'success' => false,
            'error' => 'Nie udało się pobrać danych usługi.',
        ], 500);
    }

       $settings = $result['json'][0] ?? null;

    if (!$settings) {
        sendPublicServiceJson([
            'success' => true,
            'service' => null,
        ]);
    }

    $payuUrl = $supabaseUrl
        . '/rest/v1/tenant_integrations'
        . '?tenant_id=eq.' . urlencode($tenantId)
        . '&provider=eq.payu'
        . '&select=enabled'
        . '&limit=1';

    $payuResult = publicServiceSupabaseRequest(
        'GET',
        $payuUrl,
        $serviceRoleKey,
        $schema
    );

    $payuEnabled = false;
    $onlinePaymentsEnabled = tenant_has_feature($tenantId, 'online_payments') && tenant_has_feature($tenantId, 'payu');

    if ($payuResult['ok']) {
        $payuIntegration = $payuResult['json'][0] ?? null;
        $payuEnabled = !empty($payuIntegration['enabled']);
    }
    
    $calendarUrl = $supabaseUrl
    . '/rest/v1/calendar_settings'
    . '?tenant_id=eq.' . urlencode($tenantId)
    . '&select=calendar_enabled,work_start,work_end,consultation_duration,consultation_break,booking_buffer,booking_start_month_offset,booking_month_range'
    . '&limit=1';

$calendarResult = publicServiceSupabaseRequest(
    'GET',
    $calendarUrl,
    $serviceRoleKey,
    $schema
);

$calendarSettings = [];

if ($calendarResult['ok']) {
    $calendarSettings = $calendarResult['json'][0] ?? [];
    if (!is_array($calendarSettings)) {
        $calendarSettings = [];
    }
}

$settings['calendar_enabled'] = (bool)($calendarSettings['calendar_enabled'] ?? false);
$settings['work_start'] = (string)($calendarSettings['work_start'] ?? '09:00');
$settings['work_end'] = (string)($calendarSettings['work_end'] ?? '17:00');
$settings['consultation_duration'] = (int)($calendarSettings['consultation_duration'] ?? 60);
$settings['consultation_break'] = (int)($calendarSettings['consultation_break'] ?? 0);
$settings['booking_buffer'] = (int)($calendarSettings['booking_buffer'] ?? 0);
$settings['booking_start_month_offset'] = (int)($calendarSettings['booking_start_month_offset'] ?? 0);
$settings['booking_month_range'] = (int)($calendarSettings['booking_month_range'] ?? 1);

    $settings['payment_required_configured'] = !empty($settings['payment_required']);
    $settings['payment_provider_enabled'] = $onlinePaymentsEnabled && $payuEnabled;
    $settings['payment_required'] = !empty($settings['payment_required']) && $onlinePaymentsEnabled && $payuEnabled;

    if (!$onlinePaymentsEnabled) {
        $settings['price_amount'] = null;
        $settings['payment_message'] = '';
    }

    sendPublicServiceJson([
        'success' => true,
        'service' => $settings,
    ]);

} catch (Throwable $e) {
    sendPublicServiceJson([
        'success' => false,
        'error' => 'Błąd pobierania danych usługi.',
    ], 500);
}
