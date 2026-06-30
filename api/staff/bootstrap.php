<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_bootstrap_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_bootstrap_request(string $url, string $supabaseKey, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 20,
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

function staff_bootstrap_first_row(array $result): array
{
    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
    $row = $rows[0] ?? [];

    return is_array($row) ? $row : [];
}

function staff_bootstrap_clear_session(): void
{
    unset($_SESSION['staff_user']);
}

function staff_bootstrap_fetch_company(string $supabaseUrl, string $supabaseKey, string $schema, string $tenantId): array
{
    $settingsResult = staff_bootstrap_request(
        $supabaseUrl
            . '/rest/v1/tenant_service_settings'
            . '?select=company_full_name,company_tax_id,company_address,company_email,company_phone'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&limit=1',
        $supabaseKey,
        $schema
    );

    $settings = staff_bootstrap_first_row($settingsResult);
    $companyName = trim((string) ($settings['company_full_name'] ?? ''));

    $brandingResult = staff_bootstrap_request(
        $supabaseUrl
            . '/rest/v1/tenant_branding?select=client_name'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&limit=1',
        $supabaseKey,
        $schema
    );

    $branding = staff_bootstrap_first_row($brandingResult);
    $clientName = trim((string) ($branding['client_name'] ?? ''));

    if ($companyName === '' && $clientName !== '') {
        $companyName = $clientName;
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
    staff_bootstrap_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

$staffSession = $_SESSION['staff_user'] ?? null;

if (!is_array($staffSession) || empty($staffSession['account_id']) || empty($staffSession['tenant_id']) || empty($staffSession['staff_id'])) {
    staff_bootstrap_json([
        'success' => false,
        'error' => 'Niezalogowany personel.'
    ], 401);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_bootstrap_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ], 500);
}

$sessionTenantId = (string) ($staffSession['tenant_id'] ?? '');
$hostTenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$hostTenantId || !hash_equals($sessionTenantId, (string) $hostTenantId)) {
    staff_bootstrap_clear_session();
    staff_bootstrap_json([
        'success' => false,
        'error' => 'Sesja personelu nie pasuje do domeny.'
    ], 401);
}

require_tenant_feature(
    $sessionTenantId,
    'staff_module',
    'Panel pracownika jest dostępny dla kont z aktywnym planem Pro. To konto działa obecnie w planie Free albo abonament Pro wygasł. Opłać abonament Pro, aby odzyskać dostęp do panelu pracownika.'
);

$accountId = (string) ($staffSession['account_id'] ?? '');
$staffId = (string) ($staffSession['staff_id'] ?? '');

$accountResult = staff_bootstrap_request(
    $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?select=id,tenant_id,staff_id,email,is_active'
        . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
        . '&id=eq.' . rawurlencode($accountId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&limit=1',
    $supabaseKey,
    $schema
);

if (!$accountResult['ok']) {
    staff_bootstrap_json([
        'success' => false,
        'error' => 'Nie udało się sprawdzić sesji personelu.'
    ], $accountResult['httpCode'] ?: 500);
}

$account = staff_bootstrap_first_row($accountResult);

if (empty($account['id']) || !filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    staff_bootstrap_clear_session();
    staff_bootstrap_json([
        'success' => false,
        'error' => 'Sesja personelu jest nieaktywna.'
    ], 401);
}

$staffResult = staff_bootstrap_request(
    $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id,display_name,email,is_active'
        . '&tenant_id=eq.' . rawurlencode($sessionTenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&limit=1',
    $supabaseKey,
    $schema
);

if (!$staffResult['ok']) {
    staff_bootstrap_json([
        'success' => false,
        'error' => 'Nie udało się pobrać profilu personelu.'
    ], $staffResult['httpCode'] ?: 500);
}

$staff = staff_bootstrap_first_row($staffResult);

if (empty($staff['id']) || !filter_var($staff['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
    staff_bootstrap_clear_session();
    staff_bootstrap_json([
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

$planContext = plan_features_get_context($sessionTenantId);
$company = staff_bootstrap_fetch_company($supabaseUrl, $supabaseKey, $schema, $sessionTenantId);
$refSecret = public_response_ref_secret($supabaseKey);

staff_bootstrap_json([
    'success' => true,
    'company' => $company,
    'staff' => [
        'staff_ref' => public_response_staff_ref($sessionTenantId, $staffId, $refSecret),
        'email' => (string) ($account['email'] ?? ''),
        'display_name' => $displayName,
    ],
    'plan_context' => $planContext,
    'access' => [
        'staff_panel' => true,
    ],
]);
