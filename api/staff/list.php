<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_list_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function staff_list_require_admin_session(): array
{
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user)
        || empty($user['id'])
        || empty($user['tenant_id'])
    ) {
        staff_list_json([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], 401);
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));

    if (!in_array($role, ['admin', 'administrator'], true)) {
        staff_list_json([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], 401);
    }

    return $user;
}

function staff_list_supabase_request(
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

function staff_list_truthy(mixed $value): bool
{
    return $value === true
        || $value === 1
        || $value === '1'
        || strtolower(trim((string) $value)) === 'true';
}

function staff_list_has_password(mixed $value): bool
{
    return trim((string) ($value ?? '')) !== '';
}

function staff_list_format_datetime_label(?string $value): string
{
    $timestamp = $value ? strtotime($value) : false;

    if ($timestamp === false) {
        return '';
    }

    return gmdate('d.m.Y H:i', $timestamp);
}

function staff_list_default_invite_context(): array
{
    return [
        'invite_status' => 'none',
        'invite_status_label' => 'Zaproszenie nie zostało jeszcze wysłane.',
        'invite_expires_at' => null,
        'invite_expires_at_label' => '',
        'staff_account_active' => false,
        'staff_account_has_password' => false,
    ];
}

function staff_list_build_invite_context(array $account = null, array $invites = []): array
{
    $context = staff_list_default_invite_context();

    if (is_array($account)) {
        $context['staff_account_active'] = staff_list_truthy($account['is_active'] ?? false);
        $context['staff_account_has_password'] = staff_list_has_password($account['password_hash'] ?? '');

        if ($context['staff_account_active'] && $context['staff_account_has_password']) {
            $context['invite_status'] = 'activated';
            $context['invite_status_label'] = 'Aktywowano konto.';
            return $context;
        }
    }

    $latestOpenInvite = null;
    $latestAcceptedInvite = null;

    foreach ($invites as $invite) {
        if (!is_array($invite)) {
            continue;
        }

        $acceptedAt = trim((string) ($invite['accepted_at'] ?? ''));
        $revokedAt = trim((string) ($invite['revoked_at'] ?? ''));

        if ($acceptedAt !== '' && $latestAcceptedInvite === null) {
            $latestAcceptedInvite = $invite;
        }

        if ($acceptedAt === '' && $revokedAt === '' && $latestOpenInvite === null) {
            $latestOpenInvite = $invite;
        }
    }

    if ($latestAcceptedInvite !== null) {
        $context['invite_status'] = 'activated';
        $context['invite_status_label'] = 'Aktywowano konto.';
        return $context;
    }

    if ($latestOpenInvite === null) {
        return $context;
    }

    $expiresAt = trim((string) ($latestOpenInvite['expires_at'] ?? ''));
    $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
    $expiresLabel = staff_list_format_datetime_label($expiresAt);

    $context['invite_expires_at'] = $expiresAt !== '' ? $expiresAt : null;
    $context['invite_expires_at_label'] = $expiresLabel;

    if ($expiresTs !== false && $expiresTs < time()) {
        $context['invite_status'] = 'expired';
        $context['invite_status_label'] = 'Link aktywacyjny wygasł.';
        return $context;
    }

    $context['invite_status'] = 'sent';
    $context['invite_status_label'] = $expiresLabel !== ''
        ? 'Wysłano zaproszenie. Link ważny do ' . $expiresLabel . '.'
        : 'Wysłano zaproszenie.';

    return $context;
}

function staff_list_index_accounts_by_staff_id(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): array {
    $url = $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?select=' . rawurlencode('staff_id,password_hash,is_active')
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = staff_list_supabase_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false
        || $result['error'] !== ''
        || $result['httpCode'] < 200
        || $result['httpCode'] >= 300
    ) {
        return [];
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
    $indexed = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string) ($row['staff_id'] ?? ''));

        if ($staffId !== '') {
            $indexed[$staffId] = $row;
        }
    }

    return $indexed;
}

function staff_list_index_invites_by_staff_id(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): array {
    $url = $supabaseUrl
        . '/rest/v1/staff_invites'
        . '?select=' . rawurlencode('staff_id,expires_at,accepted_at,revoked_at')
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&order=expires_at.desc';

    $result = staff_list_supabase_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false
        || $result['error'] !== ''
        || $result['httpCode'] < 200
        || $result['httpCode'] >= 300
    ) {
        return [];
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
    $indexed = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string) ($row['staff_id'] ?? ''));

        if ($staffId === '') {
            continue;
        }

        if (!isset($indexed[$staffId])) {
            $indexed[$staffId] = [];
        }

        $indexed[$staffId][] = $row;
    }

    return $indexed;
}


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_list_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$adminUser = staff_list_require_admin_session();

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_list_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_list_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($adminUser['tenant_id'] ?? '');

if ($tenantId === '') {
    staff_list_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

if (!tenant_has_feature($tenantId, 'staff_module')) {
    staff_list_json([
        'success' => false,
        'code' => 'staff_panel_requires_pro',
        'feature' => 'staff_module',
        'upgrade_required' => true,
        'error' => 'Panel pracownika jest dostępny w planie Pro. Twój abonament Pro wygasł albo konto działa w planie Free. Opłać abonament Pro, aby odzyskać dostęp do funkcji personelu.',
    ], 403);
}

$select = implode(',', [
    'id',
    'display_name',
    'email',
    'phone',
    'description',
    'color',
    'sort_order',
    'is_active',
    'visible_on_front',
    'service_name',
    'service_description',
    'service_duration_minutes',
    'service_break_minutes',
    'booking_buffer_minutes',
    'service_price',
    'payments_enabled',
    'email_subject',
    'email_heading',
    'email_body',
    'created_at',
    'updated_at',
]);

$url = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=' . rawurlencode($select)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&order=sort_order.asc'
    . '&order=display_name.asc';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false || $curlError !== '') {
    staff_list_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($httpCode < 200 || $httpCode >= 300) {
    staff_list_json([
        'success' => false,
        'error' => 'Nie udało się pobrać pracowników'
    ], $httpCode > 0 ? $httpCode : 500);
}

$staff = json_decode((string) $response, true);

if (!is_array($staff)) {
    staff_list_json([
        'success' => false,
        'error' => 'Nieprawidłowa odpowiedź bazy danych'
    ], 500);
}

$accountsByStaffId = staff_list_index_accounts_by_staff_id($supabaseUrl, $supabaseKey, $schema, $tenantId);
$invitesByStaffId = staff_list_index_invites_by_staff_id($supabaseUrl, $supabaseKey, $schema, $tenantId);

foreach ($staff as $index => $person) {
    if (!is_array($person)) {
        continue;
    }

    $staffId = trim((string) ($person['id'] ?? ''));

    if ($staffId === '') {
        $staff[$index] = array_merge($person, staff_list_default_invite_context());
        continue;
    }

    $inviteContext = staff_list_build_invite_context(
        $accountsByStaffId[$staffId] ?? null,
        $invitesByStaffId[$staffId] ?? []
    );

    $staff[$index] = array_merge($person, $inviteContext);
}

staff_list_json([
    'success' => true,
    'staff' => $staff
]);
