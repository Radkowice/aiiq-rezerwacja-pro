<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = getTenantIdFromHost($supabaseUrl, $serviceRoleKey, $schema);

if (!$tenantId) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Nie znaleziono klienta dla tej domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$url = $supabaseUrl
    . '/rest/v1/tenant_branding'
    . '?select=tenant_id,client_name,service_title_front,logo_url_front,favicon_url_front,calendar_front_style,calendar_form_fields'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => supabaseHeaders($serviceRoleKey, $schema),
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false || $curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać brandingu'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data[0])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Nie znaleziono brandingu klienta'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = $data[0];

echo json_encode([
    'success' => true,
    'tenant_id' => $tenantId,
    'branding' => [
        'client_name' => $row['client_name'] ?? '',
        'service_title_front' => $row['service_title_front'] ?? '',
        'logo_url_front' => $row['logo_url_front'] ?? '',
        'favicon_url_front' => trim((string)($row['favicon_url_front'] ?? '')),
        'calendar_front_style' => is_array($row['calendar_front_style'] ?? null)
            ? $row['calendar_front_style']
            : [],
        'calendar_form_fields' => is_array($row['calendar_form_fields'] ?? null)
            ? $row['calendar_form_fields']
            : [],
    ],
], JSON_UNESCAPED_UNICODE);