<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function admin_bootstrap_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_bootstrap_request(string $url, string $supabaseKey, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $response !== false && $error === '' && $httpCode >= 200 && $httpCode < 300,
        'error' => $error,
        'httpCode' => $httpCode,
        'data' => json_decode((string) $response, true),
    ];
}

function admin_bootstrap_first_row(array $result): ?array
{
    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
    $row = $rows[0] ?? null;

    return is_array($row) ? $row : null;
}

function admin_bootstrap_sections(array $planContext): array
{
    $features = is_array($planContext['features'] ?? null) ? $planContext['features'] : [];

    return [
        'rezerwacje' => true,
        'blokady' => true,
        'personel' => !empty($features['staff_module']),
        'usluga-platnosci' => !empty($features['online_payments']),
        'email' => true,
        'integracje' => true,
        'dokumenty_prawne' => !empty($features['legal_documents']),
        'informacje' => true,
        'ustawienia' => true,
        'moje_konto' => true,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$sessionUser = $_SESSION['user'] ?? null;

if (!is_array($sessionUser) || empty($sessionUser['id']) || empty($sessionUser['tenant_id'])) {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Niezalogowany'
    ], 401);
}

$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));

if (!in_array($role, ['admin', 'administrator'], true)) {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Brak uprawnień administratora'
    ], 403);
}

$userId = (string) ($sessionUser['id'] ?? '');
$tenantId = (string) ($sessionUser['tenant_id'] ?? '');
$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($userId === '' || $tenantId === '') {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

if ($supabaseUrl === '' || $supabaseKey === '') {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 401);
}

$planContext = plan_features_get_context($tenantId);

$userResult = admin_bootstrap_request(
    $supabaseUrl
        . '/rest/v1/users?select=email,role'
        . '&id=eq.' . rawurlencode($userId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1',
    $supabaseKey,
    $schema
);

if (!$userResult['ok']) {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Błąd Supabase przy pobieraniu użytkownika'
    ], $userResult['httpCode'] ?: 500);
}

$user = admin_bootstrap_first_row($userResult);

if (!$user) {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Nie znaleziono użytkownika'
    ], 404);
}

$brandingResult = admin_bootstrap_request(
    $supabaseUrl
        . '/rest/v1/tenant_branding?select=client_name,client_number,admin_theme,service_title_front,logo_url_front,favicon_url_front,reservations_style,calendar_front_style,calendar_form_fields'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1',
    $supabaseKey,
    $schema
);

if (!$brandingResult['ok']) {
    admin_bootstrap_json([
        'success' => false,
        'error' => 'Błąd Supabase przy pobieraniu brandingu'
    ], $brandingResult['httpCode'] ?: 500);
}

$branding = admin_bootstrap_first_row($brandingResult);

if (is_array($branding)) {
    $branding['logo_url_front'] = branding_asset_public_url((string)($branding['logo_url_front'] ?? ''), $tenantId, 'logo');
    $branding['favicon_url_front'] = branding_asset_public_url((string)($branding['favicon_url_front'] ?? ''), $tenantId, 'favicon');
}

admin_bootstrap_json([
    'success' => true,
    'user' => $user,
    'branding' => $branding,
    'public_identity' => public_response_identity(is_array($branding) ? $branding : null),
    'plan_context' => $planContext,
    'sections' => admin_bootstrap_sections($planContext),
]);
