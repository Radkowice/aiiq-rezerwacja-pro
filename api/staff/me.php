<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_me_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_me_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema
): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'response' => $response,
        'error' => $curlError,
        'httpCode' => $httpCode,
        'data' => json_decode((string) $response, true),
    ];
}

function staff_me_clear_session(): void
{
    unset($_SESSION['staff_user']);
}

function staff_me_fetch_company(string $supabaseUrl, string $supabaseKey, string $schema, string $tenantId): array
{
    $settingsUrl = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?select=company_full_name,company_tax_id,company_address,company_email,company_phone'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $settingsResult = staff_me_request('GET', $settingsUrl, $supabaseKey, $schema);
    $settings = [];

    if (
        $settingsResult['response'] !== false
        && $settingsResult['error'] === ''
        && $settingsResult['httpCode'] >= 200
        && $settingsResult['httpCode'] < 300
    ) {
        $settingsRows = is_array($settingsResult['data'] ?? null) ? $settingsResult['data'] : [];
        $settings = is_array($settingsRows[0] ?? null) ? $settingsRows[0] : [];
    }

    $companyName = trim((string) ($settings['company_full_name'] ?? ''));

    $brandingUrl = $supabaseUrl
        . '/rest/v1/tenant_branding'
        . '?select=client_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $brandingResult = staff_me_request('GET', $brandingUrl, $supabaseKey, $schema);

    if (
        $brandingResult['response'] !== false
        && $brandingResult['error'] === ''
        && $brandingResult['httpCode'] >= 200
        && $brandingResult['httpCode'] < 300
    ) {
        $brandingRows = is_array($brandingResult['data'] ?? null) ? $brandingResult['data'] : [];
        $branding = is_array($brandingRows[0] ?? null) ? $brandingRows[0] : [];
        $clientName = trim((string) ($branding['client_name'] ?? ''));

        if ($companyName === '' && $clientName !== '') {
            $companyName = $clientName;
        }
    }

    if ($companyName === '') {
        $companyName = 'Usługodawca';
    }

    return [
        'name' => $companyName,
        'address' => trim((string) ($settings['company_address'] ?? '')),
        'nip' => trim((string) ($settings['company_tax_id'] ?? '')),
        'phone' => trim((string) ($settings['company_phone'] ?? '')),
        'email' => trim((string) ($settings['company_email'] ?? '')),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_me_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$staffSession = $_SESSION['staff_user'] ?? null;

if (!is_array($staffSession) || empty($staffSession['account_id']) || empty($staffSession['tenant_id']) || empty($staffSession['staff_id'])) {
    staff_me_json([
        'success' => false,
        'error' => 'Niezalogowany personel.'
    ], 401);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_me_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$hostTenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);
$sessionTenantId = (string) ($staffSession['tenant_id'] ?? '');

if (!$hostTenantId || !hash_equals($sessionTenantId, (string) $hostTenantId)) {
    staff_me_clear_session();
    staff_me_json([
        'success' => false,
        'error' => 'Sesja personelu nie pasuje do domeny.'
    ], 401);
}

require_tenant_feature(
    $sessionTenantId,
    'staff_module',
    'Panel personelu jest dostępny w planie Pro.'
);

$accountId = (string) ($staffSession['account_id'] ?? '');
$staffId = (string) ($staffSession['staff_id'] ?? '');

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,tenant_id,staff_id,email,is_active'
    . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&id=eq.' . rawurlencode($accountId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$accountResult = staff_me_request('GET', $accountUrl, $supabaseKey, $schema);

if (
    $accountResult['response'] === false
    || $accountResult['error'] !== ''
    || $accountResult['httpCode'] < 200
    || $accountResult['httpCode'] >= 300
) {
    staff_me_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić sesji personelu.'
    ], 500);
}

$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];
$account = is_array($accountRows[0] ?? null) ? $accountRows[0] : null;

if (!is_array($account) || empty($account['id']) || !filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    staff_me_clear_session();
    staff_me_json([
        'success' => false,
        'error' => 'Sesja personelu jest nieaktywna.'
    ], 401);
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,display_name,email,is_active'
    . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_me_request('GET', $staffUrl, $supabaseKey, $schema);

if (
    $staffResult['response'] === false
    || $staffResult['error'] !== ''
    || $staffResult['httpCode'] < 200
    || $staffResult['httpCode'] >= 300
) {
    staff_me_json([
        'success' => false,
        'error' => 'Nie udało się pobrać profilu personelu.'
    ], 500);
}

$staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = is_array($staffRows[0] ?? null) ? $staffRows[0] : null;

if (!is_array($staff) || empty($staff['id']) || !filter_var($staff['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
    staff_me_clear_session();
    staff_me_json([
        'success' => false,
        'error' => 'Profil personelu jest nieaktywny.'
    ], 401);
}

$displayName = trim((string) ($staff['display_name'] ?? ''));

if ($displayName === '') {
    $displayName = (string) ($account['email'] ?? '');
}

$_SESSION['staff_user']['email'] = (string) ($account['email'] ?? '');
$_SESSION['staff_user']['display_name'] = $displayName;

$company = staff_me_fetch_company($supabaseUrl, $supabaseKey, $schema, $sessionTenantId);

staff_me_json([
    'success' => true,
    'company' => $company,
    'staff' => [
        'tenant_id' => $sessionTenantId,
        'staff_id' => $staffId,
        'email' => (string) ($account['email'] ?? ''),
        'display_name' => $displayName,
    ],
]);
