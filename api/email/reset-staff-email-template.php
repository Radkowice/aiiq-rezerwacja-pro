<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

function reset_staff_email_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus = 400,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $staffId = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && trim($stage) !== '') {
        $details['stage'] = trim($stage);
    }

    $context = [
        'action_key' => 'staff_email_template_reset',
        'endpoint' => '/api/email/reset-staff-email-template.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    $tenantId = trim((string) ($tenantId ?? ($_SESSION['user']['tenant_id'] ?? '')));
    if ($tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    $userId = trim((string) ($_SESSION['user']['id'] ?? ''));
    if ($userId !== '') {
        $context['user_id'] = $userId;
    }

    $staffId = trim((string) ($staffId ?? ''));
    if ($staffId !== '') {
        $context['staff_id'] = $staffId;
    }

    security_log_event($eventKey, $context);
}

function reset_staff_email_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reset_staff_email_normalize_staff_ref($value): string
{
    if (is_array($value) || is_object($value)) {
        return '';
    }

    $staffRef = trim((string) ($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function reset_staff_email_resolve_staff_ref(
    $value,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): string {
    $staffRef = reset_staff_email_normalize_staff_ref($value);

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
        reset_staff_email_security_event('staff_email_template_reset_staff_lookup_failed', 'staff_lookup_failed', 500, 'error', 'medium', $tenantId, null, 'staff_lookup');
        reset_staff_email_json(['success' => false, 'error' => 'Nie udało się sprawdzić pracownika.'], 500);
    }

    $rows = json_decode((string) $response, true);

    if (!is_array($rows)) {
        reset_staff_email_security_event('staff_email_template_reset_staff_lookup_failed', 'staff_lookup_failed', 500, 'error', 'medium', $tenantId, null, 'staff_lookup_response');
        reset_staff_email_json(['success' => false, 'error' => 'Nieprawidłowa odpowiedź bazy danych.'], 500);
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

function reset_staff_email_public_staff(array $row, string $tenantId, string $refSecret): array
{
    $staffId = trim((string) ($row['id'] ?? ''));
    unset($row['id'], $row['staff_id'], $row['tenant_id'], $row['company_id'], $row['user_id']);

    if ($staffId !== '') {
        $row['staff_ref'] = public_response_staff_ref($tenantId, $staffId, $refSecret);
    }

    return $row;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reset_staff_email_security_event('staff_email_template_reset_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    header('Allow: POST');
    reset_staff_email_json(['success' => false, 'error' => 'Metoda niedozwolona.'], 405);
}

if (empty($_SESSION['user']['tenant_id'])) {
    reset_staff_email_security_event('staff_email_template_reset_unauthorized', 'unauthorized', 401, 'denied', 'medium');
    reset_staff_email_json(['success' => false, 'error' => 'Brak autoryzacji.'], 401);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    reset_staff_email_security_event('staff_email_template_reset_invalid_json', 'invalid_json', 400, 'failed', 'low', $tenantId);
    reset_staff_email_json(['success' => false, 'error' => 'Brak danych wejściowych.'], 400);
}

$staffRef = reset_staff_email_normalize_staff_ref($input['staff_ref'] ?? null);

if ($staffRef === '') {
    reset_staff_email_security_event('staff_email_template_reset_staff_ref_missing', 'staff_ref_missing', 422, 'failed', 'low', $tenantId);
    reset_staff_email_json(['success' => false, 'error' => 'Wybierz pracownika, aby edytować jego szablon e-mail.'], 422);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    reset_staff_email_security_event('staff_email_template_reset_env_missing', 'env_missing', 500, 'error', 'high', $tenantId, null, 'supabase_config');
    reset_staff_email_json(['success' => false, 'error' => 'Brak konfiguracji Supabase.'], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    reset_staff_email_security_event('staff_email_template_reset_tenant_denied', 'tenant_denied', 403, 'denied', 'high', $tenantId);
    reset_staff_email_json(['success' => false, 'error' => 'Sesja nie pasuje do domeny.'], 403);
}

if (!tenant_has_feature($tenantId, 'staff_module')) {
    reset_staff_email_security_event('staff_email_template_reset_feature_denied', 'feature_denied', 403, 'denied', 'medium', $tenantId, null, 'staff_module');
    reset_staff_email_json([
        'success' => false,
        'error' => 'Szablony e-mail pracowników są dostępne w wyższym planie.',
        'upgrade_required' => true,
    ], 403);
}

$refSecret = public_response_ref_secret($supabaseKey);
$staffId = reset_staff_email_resolve_staff_ref($staffRef, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret);

if ($staffId === '') {
    reset_staff_email_security_event('staff_email_template_reset_staff_not_found', 'staff_not_found', 404, 'failed', 'medium', $tenantId);
    reset_staff_email_json(['success' => false, 'error' => 'Nie znaleziono pracownika.'], 404);
}

$url = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&select=id,display_name,email,email_subject,email_heading,email_body';

$headers = supabaseHeaders($supabaseKey, $schema);
$headers[] = 'Prefer: return=representation';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_POSTFIELDS => json_encode([
        'email_subject' => null,
        'email_heading' => null,
        'email_body' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
    reset_staff_email_security_event('staff_email_template_reset_failed', 'template_reset_failed', 500, 'error', 'medium', $tenantId, $staffId, 'staff_profile_patch');
    reset_staff_email_json(['success' => false, 'error' => 'Nie udało się zapisać zmian. Spróbuj ponownie.'], 500);
}

$rows = json_decode((string) $response, true);

if (!is_array($rows) || empty($rows[0])) {
    reset_staff_email_security_event('staff_email_template_reset_staff_not_found', 'staff_not_found', 404, 'failed', 'medium', $tenantId, $staffId, 'patch_response');
    reset_staff_email_json(['success' => false, 'error' => 'Nie znaleziono pracownika.'], 404);
}

$staff = reset_staff_email_public_staff($rows[0], $tenantId, $refSecret);
$staff['has_custom_template'] = false;

reset_staff_email_security_event('staff_email_template_reset_success', 'staff_email_template_reset_success', 200, 'success', 'low', $tenantId, $staffId);

reset_staff_email_json([
    'success' => true,
    'message' => 'Pracownik ponownie używa globalnego szablonu e-mail.',
    'staff' => $staff,
]);
