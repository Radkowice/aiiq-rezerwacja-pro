<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

function save_staff_email_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function save_staff_email_normalize_staff_ref($value): string
{
    if (is_array($value) || is_object($value)) {
        return '';
    }

    $staffRef = trim((string) ($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function save_staff_email_resolve_staff_ref(
    $value,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): string {
    $staffRef = save_staff_email_normalize_staff_ref($value);

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
        save_staff_email_json(['success' => false, 'error' => 'Nie udało się sprawdzić pracownika.'], 500);
    }

    $rows = json_decode((string) $response, true);

    if (!is_array($rows)) {
        save_staff_email_json(['success' => false, 'error' => 'Nieprawidłowa odpowiedź bazy danych.'], 500);
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

function save_staff_email_public_staff(array $row, string $tenantId, string $refSecret): array
{
    $staffId = trim((string) ($row['id'] ?? ''));
    unset($row['id'], $row['staff_id'], $row['tenant_id'], $row['company_id'], $row['user_id']);

    if ($staffId !== '') {
        $row['staff_ref'] = public_response_staff_ref($tenantId, $staffId, $refSecret);
    }

    return $row;
}

function save_staff_email_text($value, int $maxLength, bool $required = false): ?string
{
    if (is_array($value) || is_object($value)) {
        save_staff_email_json(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe.'], 422);
    }

    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        if ($required) {
            save_staff_email_json(['success' => false, 'error' => 'Uzupełnij temat i treść wiadomości.'], 422);
        }

        return null;
    }

    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        save_staff_email_json(['success' => false, 'error' => 'Wpisany tekst jest zbyt długi.'], 422);
    }

    return $text;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    save_staff_email_json(['success' => false, 'error' => 'Metoda niedozwolona.'], 405);
}

if (empty($_SESSION['user']['tenant_id'])) {
    save_staff_email_json(['success' => false, 'error' => 'Brak autoryzacji.'], 401);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    save_staff_email_json(['success' => false, 'error' => 'Brak danych wejściowych.'], 400);
}

$staffRef = save_staff_email_normalize_staff_ref($input['staff_ref'] ?? null);

if ($staffRef === '') {
    save_staff_email_json(['success' => false, 'error' => 'Wybierz pracownika, aby edytować jego szablon e-mail.'], 422);
}

$subject = save_staff_email_text($input['email_subject'] ?? null, 255, true);
$heading = save_staff_email_text($input['email_heading'] ?? null, 255, false);
$body = save_staff_email_text($input['email_body'] ?? null, 10000, true);

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    save_staff_email_json(['success' => false, 'error' => 'Brak konfiguracji Supabase.'], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    save_staff_email_json(['success' => false, 'error' => 'Sesja nie pasuje do domeny.'], 403);
}

if (!tenant_has_feature($tenantId, 'staff_module')) {
    save_staff_email_json([
        'success' => false,
        'error' => 'Szablony e-mail pracowników są dostępne w wyższym planie.',
        'upgrade_required' => true,
    ], 403);
}

$refSecret = public_response_ref_secret($supabaseKey);
$staffId = save_staff_email_resolve_staff_ref($staffRef, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret);

if ($staffId === '') {
    save_staff_email_json(['success' => false, 'error' => 'Nie znaleziono pracownika.'], 404);
}

$url = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&select=id,display_name,email,email_subject,email_heading,email_body';

$payload = [
    'email_subject' => $subject,
    'email_heading' => $heading,
    'email_body' => $body,
];

$headers = supabaseHeaders($supabaseKey, $schema);
$headers[] = 'Prefer: return=representation';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
    save_staff_email_json(['success' => false, 'error' => 'Nie udało się zapisać zmian. Spróbuj ponownie.'], 500);
}

$rows = json_decode((string) $response, true);

if (!is_array($rows) || empty($rows[0])) {
    save_staff_email_json(['success' => false, 'error' => 'Nie znaleziono pracownika.'], 404);
}

$staff = save_staff_email_public_staff($rows[0], $tenantId, $refSecret);
$staff['has_custom_template'] = true;

save_staff_email_json([
    'success' => true,
    'message' => 'Szablon e-mail pracownika został zapisany.',
    'staff' => $staff,
]);
