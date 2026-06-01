<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

function staff_email_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function staff_email_is_uuid($value): bool
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
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

$staffId = trim((string) ($_GET['staff_id'] ?? ''));

if ($staffId !== '' && !staff_email_is_uuid($staffId)) {
    staff_email_json(['success' => false, 'error' => 'Nieprawidłowy identyfikator pracownika.'], 422);
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
