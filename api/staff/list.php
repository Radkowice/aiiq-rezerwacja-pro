<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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

staff_list_json([
    'success' => true,
    'staff' => $staff
]);
