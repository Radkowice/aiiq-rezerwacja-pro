<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Niezalogowany'
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
        'error' => 'Nieprawidłowa sesja'
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

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$planContext = plan_features_get_context('');

try {
    $resolvedPlanContext = plan_features_get_context($tenantId);

    if (is_array($resolvedPlanContext)) {
        $planContext = $resolvedPlanContext;
    }
} catch (Throwable $e) {
    $planContext = plan_features_get_context('');
}

$headers = supabaseHeaders($supabaseKey, $schema);

$userUrl = $supabaseUrl
    . '/rest/v1/users?select=email,role'
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
        'error' => 'Błąd połączenia z Supabase',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($userHttpCode !== 200) {
    http_response_code($userHttpCode ?: 500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd Supabase przy pobieraniu użytkownika',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userData = json_decode((string) $userResponse, true);

if (!is_array($userData) || empty($userData[0])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Nie znaleziono użytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$brandingUrl = $supabaseUrl
    . '/rest/v1/tenant_branding?select=client_name,client_number,admin_theme,service_title_front,logo_url_front,favicon_url_front,reservations_style,calendar_front_style,calendar_form_fields'
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
        'error' => 'Błąd połączenia z Supabase przy pobieraniu brandingu',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($brandingHttpCode !== 200) {
    http_response_code($brandingHttpCode ?: 500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd Supabase przy pobieraniu brandingu',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$brandingData = json_decode((string) $brandingResponse, true);
$branding = is_array($brandingData) && is_array($brandingData[0] ?? null)
    ? $brandingData[0]
    : null;

if (is_array($branding)) {
    $branding['logo_url_front'] = branding_asset_public_url((string)($branding['logo_url_front'] ?? ''), $tenantId, 'logo');
    $branding['favicon_url_front'] = branding_asset_public_url((string)($branding['favicon_url_front'] ?? ''), $tenantId, 'favicon');
}

$payload = [
    'success' => true,
    'user' => $userData[0],
    'branding' => $branding,
    'public_identity' => public_response_identity(is_array($branding) ? $branding : null),
    'plan_context' => $planContext
];

echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE);
