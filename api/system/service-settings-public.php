<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/tenant.php';

$supabaseUrl = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$serviceRoleKey = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'public';

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
        'error' => 'Brak funkcji getTenantIdFromHost.'
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
            'details' => $result['json'] ?: $result['body'],
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

    if ($payuResult['ok']) {
        $payuIntegration = $payuResult['json'][0] ?? null;
        $payuEnabled = !empty($payuIntegration['enabled']);
    }

    $settings['payment_required_configured'] = !empty($settings['payment_required']);
    $settings['payment_provider_enabled'] = $payuEnabled;
    $settings['payment_required'] = !empty($settings['payment_required']) && $payuEnabled;

    sendPublicServiceJson([
        'success' => true,
        'service' => $settings,
    ]);

} catch (Throwable $e) {
    sendPublicServiceJson([
        'success' => false,
        'error' => 'Błąd pobierania danych usługi.',
        'details' => $e->getMessage(),
    ], 500);
}