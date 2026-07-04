<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_invite_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_invite_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    bool $returnRepresentation = false
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if ($returnRepresentation) {
        $headers[] = 'Prefer: return=representation';
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

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

function staff_invite_fail_database(string $stage = 'unknown'): void
{
    staff_invite_json([
        'success' => false,
        'error' => 'Nie udało się przygotować zaproszenia.'
    ], 500);
}

function staff_invite_current_origin(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '' || preg_match('/[\r\n]/', $host)) {
        staff_invite_json([
            'success' => false,
            'error' => 'Nie udało się ustalić domeny aplikacji.'
        ], 500);
    }

    return 'https://' . $host;
}

function staff_invite_provider_name(string $supabaseUrl, string $supabaseKey, string $schema, string $tenantId): string
{
    $fallback = 'Usługodawca';

    $companyUrl = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?select=company_full_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $companyResult = staff_invite_request('GET', $companyUrl, $supabaseKey, $schema);

    if ($companyResult['error'] === '' && $companyResult['httpCode'] >= 200 && $companyResult['httpCode'] < 300) {
        $companyRows = is_array($companyResult['data'] ?? null) ? $companyResult['data'] : [];
        $companyRow = is_array($companyRows[0] ?? null) ? $companyRows[0] : [];
        $companyFullName = trim((string) ($companyRow['company_full_name'] ?? ''));

        if ($companyFullName !== '') {
            return $companyFullName;
        }
    }

    $brandingUrl = $supabaseUrl
        . '/rest/v1/tenant_branding'
        . '?select=client_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $brandingResult = staff_invite_request('GET', $brandingUrl, $supabaseKey, $schema);

    if ($brandingResult['error'] === '' && $brandingResult['httpCode'] >= 200 && $brandingResult['httpCode'] < 300) {
        $brandingRows = is_array($brandingResult['data'] ?? null) ? $brandingResult['data'] : [];
        $brandingRow = is_array($brandingRows[0] ?? null) ? $brandingRows[0] : [];
        $clientName = trim((string) ($brandingRow['client_name'] ?? ''));

        if ($clientName !== '') {
            return $clientName;
        }
    }

    return $fallback;
}


function staff_invite_normalize_staff_ref($value): string
{
    $staffRef = trim((string) ($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function staff_invite_resolve_staff_ref(
    $value,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): ?string {
    $staffRef = staff_invite_normalize_staff_ref($value);

    if ($staffRef === '') {
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = staff_invite_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        staff_invite_fail_database('resolve_staff_ref');
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string) ($row['id'] ?? ''));

        if ($staffId !== '' && hash_equals(public_response_staff_ref($tenantId, $staffId, $refSecret), $staffRef)) {
            return $staffId;
        }
    }

    return null;
}

function staff_invite_resolve_staff_request_id(
    array $input,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): string {
    $staffRef = staff_invite_normalize_staff_ref($input['staff_ref'] ?? null);

    if ($staffRef !== '') {
        return staff_invite_resolve_staff_ref($staffRef, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret) ?? '';
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_invite_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    staff_invite_json([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$role = (string) ($_SESSION['user']['role'] ?? '');

if (!in_array($role, ['admin', 'administrator'], true)) {
    staff_invite_json([
        'success' => false,
        'error' => 'Brak uprawnień'
    ], 403);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_invite_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_invite_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');
$adminUserId = (string) ($_SESSION['user']['id'] ?? '');

if ($tenantId === '' || $adminUserId === '') {
    staff_invite_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

require_tenant_feature($tenantId, 'staff_module');

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_invite_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON'
    ], 400);
}

$refSecret = public_response_ref_secret($supabaseKey);
$staffId = staff_invite_resolve_staff_request_id($input, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret);

if ($staffId === '') {
    staff_invite_json([
        'success' => false,
        'error' => 'Brak pracownika'
    ], 400);
}

if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $staffId)) {
    staff_invite_json([
        'success' => false,
        'error' => 'Nieprawidłowy pracownik'
    ], 400);
}

$staffUrl = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=id,display_name,email'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$staffResult = staff_invite_request('GET', $staffUrl, $supabaseKey, $schema);

if ($staffResult['response'] === false || $staffResult['error'] !== '' || $staffResult['httpCode'] >= 500) {
    staff_invite_fail_database('load_staff_profile');
}

if ($staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300) {
    staff_invite_fail_database('load_staff_profile');
}

$staffRows = is_array($staffResult['data'] ?? null) ? $staffResult['data'] : [];
$staff = is_array($staffRows[0] ?? null) ? $staffRows[0] : null;

if (!is_array($staff) || empty($staff['id'])) {
    staff_invite_json([
        'success' => false,
        'error' => 'Pracownik nie istnieje'
    ], 404);
}

$email = strtolower(trim((string) ($staff['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    staff_invite_json([
        'success' => false,
        'error' => 'Pracownik nie ma poprawnego adresu e-mail.'
    ], 400);
}

$accountUrl = $supabaseUrl
    . '/rest/v1/staff_accounts'
    . '?select=id,staff_id,email,password_hash,is_active'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&limit=1';

$accountResult = staff_invite_request('GET', $accountUrl, $supabaseKey, $schema);

if ($accountResult['response'] === false || $accountResult['error'] !== '' || $accountResult['httpCode'] < 200 || $accountResult['httpCode'] >= 300) {
    staff_invite_fail_database('load_staff_account');
}

$accountRows = is_array($accountResult['data'] ?? null) ? $accountResult['data'] : [];
$account = is_array($accountRows[0] ?? null) ? $accountRows[0] : null;

if (!is_array($account)) {
    $accountByEmailUrl = $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?select=id,staff_id,email,password_hash,is_active'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&email=eq.' . rawurlencode($email)
        . '&limit=1';

    $accountByEmailResult = staff_invite_request('GET', $accountByEmailUrl, $supabaseKey, $schema);

    if ($accountByEmailResult['response'] === false || $accountByEmailResult['error'] !== '' || $accountByEmailResult['httpCode'] < 200 || $accountByEmailResult['httpCode'] >= 300) {
        staff_invite_fail_database('load_staff_account_by_email');
    }

    $accountByEmailRows = is_array($accountByEmailResult['data'] ?? null) ? $accountByEmailResult['data'] : [];
    $accountByEmail = is_array($accountByEmailRows[0] ?? null) ? $accountByEmailRows[0] : null;

    if (is_array($accountByEmail)) {
        $accountByEmailStaffId = trim((string) ($accountByEmail['staff_id'] ?? ''));

        if ($accountByEmailStaffId !== '' && hash_equals($accountByEmailStaffId, $staffId)) {
            $account = $accountByEmail;
        } else {
            staff_invite_json([
                'success' => false,
                'error' => 'Konto personelu dla tego adresu e-mail już istnieje.'
            ], 409);
        }
    }
}

$accountId = is_array($account) ? trim((string) ($account['id'] ?? '')) : '';
$accountHasPassword = is_array($account) && trim((string) ($account['password_hash'] ?? '')) !== '';
$accountIsActive = is_array($account) && filter_var($account['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($accountIsActive && $accountHasPassword) {
    staff_invite_json([
        'success' => false,
        'error' => 'Pracownik ma już aktywne konto.'
    ], 409);
}

if ($accountHasPassword) {
    staff_invite_json([
        'success' => false,
        'error' => 'Konto pracownika już istnieje.'
    ], 409);
}

$now = gmdate('c');
$revokeUrl = $supabaseUrl
    . '/rest/v1/staff_invites'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&staff_id=eq.' . rawurlencode($staffId)
    . '&accepted_at=is.null'
    . '&revoked_at=is.null';

$revokeResult = staff_invite_request('PATCH', $revokeUrl, $supabaseKey, $schema, [
    'revoked_at' => $now,
]);

if ($revokeResult['response'] === false || $revokeResult['error'] !== '' || $revokeResult['httpCode'] < 200 || $revokeResult['httpCode'] >= 300) {
    staff_invite_fail_database('revoke_existing_invites');
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = gmdate('c', time() + 48 * 60 * 60);

$invitePayload = [
    'tenant_id' => $tenantId,
    'staff_id' => $staffId,
    'email' => $email,
    'token_hash' => $tokenHash,
    'expires_at' => $expiresAt,
    'created_by_user_id' => $adminUserId,
];

$inviteUrl = $supabaseUrl . '/rest/v1/staff_invites';
$inviteResult = staff_invite_request('POST', $inviteUrl, $supabaseKey, $schema, $invitePayload);

if ($inviteResult['response'] === false || $inviteResult['error'] !== '' || $inviteResult['httpCode'] < 200 || $inviteResult['httpCode'] >= 300) {
    staff_invite_fail_database('insert_invite');
}

if ($accountId === '') {
    $accountWriteStage = 'create_staff_account';
    $accountPayload = [
        'tenant_id' => $tenantId,
        'staff_id' => $staffId,
        'email' => $email,
        'password_hash' => null,
        'is_active' => true,
    ];

    $accountWriteResult = staff_invite_request('POST', $supabaseUrl . '/rest/v1/staff_accounts', $supabaseKey, $schema, $accountPayload);
} else {
    $accountWriteStage = 'update_staff_account';
    $accountPatchUrl = $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId);

    $accountWriteResult = staff_invite_request('PATCH', $accountPatchUrl, $supabaseKey, $schema, [
        'email' => $email,
        'is_active' => true,
    ]);
}

if ($accountWriteResult['response'] === false || $accountWriteResult['error'] !== '' || $accountWriteResult['httpCode'] < 200 || $accountWriteResult['httpCode'] >= 300) {
    staff_invite_fail_database($accountWriteStage);
}

$inviteLink = staff_invite_current_origin()
    . '/panel-pracownika/akceptuj-zaproszenie.html?token='
    . rawurlencode($token);

$safeInviteLink = htmlspecialchars($inviteLink, ENT_QUOTES, 'UTF-8');
$providerName = staff_invite_provider_name($supabaseUrl, $supabaseKey, $schema, $tenantId);
$staffDisplayName = trim((string) ($staff['display_name'] ?? ''));

if ($staffDisplayName === '') {
    $staffDisplayName = $email;
}

$safeProviderName = htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8');
$safeStaffName = htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8');

$mailMessage = ''
    . '<p style="margin:0 0 14px;">' . $safeProviderName . ' zaprasza Cię do panelu pracownika.</p>'
    . '<p style="margin:0 0 10px;">Zaproszenie dotyczy konta pracownika: <strong>' . $safeStaffName . '</strong>.</p>'
    . '<p style="margin:0 0 10px;">Kliknij poniższy przycisk, aby ustawić hasło i aktywować dostęp:</p>'
    . '<div style="text-align:center; margin:26px 0;">'
    . '<a href="' . $safeInviteLink . '" style="background:#212d45;color:#ffffff;padding:13px 22px;'
    . 'text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;">'
    . 'Ustaw hasło'
    . '</a>'
    . '</div>'
    . '<p style="margin:0 0 10px;">Link jest ważny przez <strong>48 godzin</strong>.</p>'
    . '<p style="margin:10px 0 0;">Jeśli nie spodziewasz się tego zaproszenia, zignoruj tę wiadomość.</p>';

$mailHtml = buildSystemMailLayout(
    'Zaproszenie do panelu pracownika',
    'To wiadomość systemowa dotycząca dostępu do panelu pracownika.',
    $mailMessage,
    'Nie odpowiadaj na tę wiadomość. Skrzynka nie jest monitorowana.'
);

if (!sendSystemMail($email, 'Zaproszenie do panelu pracownika', $mailHtml)) {
    staff_invite_json([
        'success' => false,
        'error' => 'Nie udało się wysłać zaproszenia.'
    ], 500);
}

staff_invite_json([
    'success' => true,
    'message' => 'Zaproszenie zostało wysłane.'
]);
