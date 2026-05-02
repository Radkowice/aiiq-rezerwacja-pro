<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Nie zalogowany'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string) ($_SESSION['user']['id'] ?? '');
$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($userId === '' || $tenantId === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawid³owa sesja'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($supabaseUrl === '' || $supabaseKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$headers = supabaseHeaders($supabaseKey, $schema);

$userUrl = $supabaseUrl
    . '/rest/v1/users?select=id,email,tenant_id,role'
    . '&id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$ch = curl_init($userUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 15,
]);

$userResponse = curl_exec($ch);
$userHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$userCurlError = curl_error($ch);
curl_close($ch);

if ($userCurlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'B³¹d po³¹czenia z Supabase',
        'debug' => $userCurlError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($userHttpCode !== 200) {
    http_response_code($userHttpCode ?: 500);
    echo json_encode([
        'success' => false,
        'error' => 'B³¹d Supabase przy pobieraniu u¿ytkownika',
        'debug' => $userResponse
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userData = json_decode((string) $userResponse, true);

if (!is_array($userData) || empty($userData[0])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Nie znaleziono u¿ytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$brandingUrl = $supabaseUrl
    . '/rest/v1/tenant_branding?select=client_name,client_number,admin_theme,company_id,service_title_front,logo_url_front,favicon_url_front,reservations_style,calendar_front_style,calendar_form_fields'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$ch = curl_init($brandingUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 15,
]);

$brandingResponse = curl_exec($ch);
$brandingHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$brandingCurlError = curl_error($ch);
curl_close($ch);

if ($brandingCurlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'B³¹d po³¹czenia z Supabase przy pobieraniu brandingu',
        'debug' => $brandingCurlError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($brandingHttpCode !== 200) {
    http_response_code($brandingHttpCode ?: 500);
    echo json_encode([
        'success' => false,
        'error' => 'B³¹d Supabase przy pobieraniu brandingu',
        'debug' => $brandingResponse
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$brandingData = json_decode((string) $brandingResponse, true);

echo json_encode([
    'success' => true,
    'user' => $userData[0],
    'branding' => is_array($brandingData) ? ($brandingData[0] ?? null) : null
], JSON_UNESCAPED_UNICODE);