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
    . '/rest/v1/tenant_legal_documents'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&is_enabled=eq.true'
    . '&select=tenant_id,terms_title,terms_content,privacy_title,privacy_content,is_enabled,updated_at'
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
        'error' => 'Nie udało się pobrać dokumentów prawnych'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data[0])) {
    echo json_encode([
        'success' => true,
        'enabled' => false,
        'documents' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = $data[0];

echo json_encode([
    'success' => true,
    'enabled' => !empty($row['is_enabled']),
        'documents' => [
        'terms_title' => (string) ($row['terms_title'] ?? 'Regulamin rezerwacji'),
        'terms_content' => (string) ($row['terms_content'] ?? ''),
        'privacy_title' => (string) ($row['privacy_title'] ?? 'Polityka prywatności'),
        'privacy_content' => (string) ($row['privacy_content'] ?? ''),
        'updated_at' => $row['updated_at'] ?? null,
        'links' => [
            'terms' => '/dokumenty/regulamin.html',
            'privacy' => '/dokumenty/polityka-prywatnosci.html',
        ],
    ],
], JSON_UNESCAPED_UNICODE);