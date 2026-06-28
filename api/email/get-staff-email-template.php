<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

function staff_email_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_email_normalize_staff_ref($value): string
{
    if (is_array($value) || is_object($value)) {
        return '';
    }

    $staffRef = trim((string) ($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function staff_email_resolve_staff_ref(
    $value,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): string {
    $staffRef = staff_email_normalize_staff_ref($value);

    if ($staffRef === '') {
        return '';
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId);

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

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        staff_email_json(['success' => false, 'error' => 'Nie udało się sprawdzić pracownika.'], 500);
    }

    $rows = json_decode((string) $response, true);

    if (!is_array($rows)) {
        staff_email_json(['success' => false, 'error' => 'Nieprawidłowa odpowiedź bazy danych.'], 500);
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string) ($row['id'] ?? ''));

        if ($staffId !== '' && hash_equals(public_response_staff_ref($tenantId, $staffId, $refSecret), $staffRef)) {
            return $staffId;
        }
    }

    return '';
}

function staff_email_public_staff(array $row, string $tenantId, string $refSecret): array
{
    $staffId = trim((string) ($row['id'] ?? ''));
    unset($row['id'], $row['staff_id'], $row['tenant_id'], $row['company_id'], $row['user_id']);

    if ($staffId !== '') {
        $row['staff_ref'] = public_response_staff_ref($tenantId, $staffId, $refSecret);
    }

    return $row;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_email_json(['success' => false, 'error' => 'Metoda niedozwolona.'], 405);
}

if (empty($_SESSION['user']['tenant_id'])) {
    staff_email_json(['success' => false, 'error' => 'Brak autoryzacji.'], 401);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_email_json(['success' => false, 'error' => 'Brak konfiguracji Supabase.'], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_email_json(['success' => false, 'error' => 'Sesja nie pasuje do domeny.'], 403);
}

$refSecret = public_response_ref_secret($supabaseKey);
$staffRef = staff_email_normalize_staff_ref($_GET['staff_ref'] ?? null);
$staffId = '';

if ($staffRef !== '') {
    $staffId = staff_email_resolve_staff_ref($staffRef, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret);

    if ($staffId === '') {
        staff_email_json(['success' => false, 'error' => 'Nie znaleziono pracownika.'], 404);
    }
}

$select = 'id,display_name,email,is_active,email_subject,email_heading,email_body';
$url = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?select=' . rawurlencode($select)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&is_active=eq.true';

if ($staffId !== '') {
    $url .= '&id=eq.' . rawurlencode($staffId) . '&limit=1';
} else {
    $url .= '&order=display_name.asc';
}

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

if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
    staff_email_json(['success' => false, 'error' => 'Nie udało się pobrać szablonów pracowników.'], 500);
}

$rows = json_decode((string) $response, true);

if (!is_array($rows)) {
    staff_email_json(['success' => false, 'error' => 'Nieprawidłowa odpowiedź bazy danych.'], 500);
}

foreach ($rows as &$row) {
    $row['has_custom_template'] = trim((string) ($row['email_subject'] ?? '')) !== ''
        || trim((string) ($row['email_heading'] ?? '')) !== ''
        || trim((string) ($row['email_body'] ?? '')) !== '';
    $row = staff_email_public_staff($row, $tenantId, $refSecret);
}
unset($row);

if ($staffId !== '') {
    staff_email_json([
        'success' => true,
        'staff' => $rows[0] ?? null,
    ]);
}

staff_email_json([
    'success' => true,
    'staff' => $rows,
]);
