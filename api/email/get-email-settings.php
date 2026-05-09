<?php
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';
header('Content-Type: application/json; charset=utf-8');

start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) $_SESSION['user']['tenant_id'];

$supabaseUrl = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$serviceRoleKey = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'public';

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function supabaseRequest(string $url, string $serviceRoleKey, string $schema): array {
    $ch = curl_init($url);

    $headers = [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Profile: ' . $schema,
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'ok' => $error === '' && $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'error' => $error,
        'json' => json_decode((string)$response, true),
    ];
}

/**
 * 1. EMAIL SETTINGS (SMTP)
 */
$emailSettingsUrl = $supabaseUrl
    . '/rest/v1/email_settings'
    . '?tenant_id=eq.' . urlencode($tenantId)
    . '&is_active=eq.true'
    . '&limit=1';

$emailSettingsRes = supabaseRequest($emailSettingsUrl, $serviceRoleKey, $schema);

if (!$emailSettingsRes['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd pobierania email_settings',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$emailSettings = $emailSettingsRes['json'][0] ?? null;

if ($emailSettings) {
    $emailSettings['has_smtp_password'] = !empty($emailSettings['smtp_password']);
    unset($emailSettings['smtp_password']);
}

/**
 * 2. EMAIL TEMPLATES
 */
$emailTemplatesUrl = $supabaseUrl
    . '/rest/v1/email_templates'
    . '?tenant_id=eq.' . urlencode($tenantId);

$emailTemplatesRes = supabaseRequest($emailTemplatesUrl, $serviceRoleKey, $schema);

if (!$emailTemplatesRes['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd pobierania email_templates',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$templates = $emailTemplatesRes['json'] ?? [];

$clientTemplate = null;
$adminTemplate = null;

foreach ($templates as $tpl) {
    if (($tpl['template_key'] ?? '') === 'booking_client_confirmation') {
        $clientTemplate = $tpl;
    }
    if (($tpl['template_key'] ?? '') === 'booking_admin_notification') {
        $adminTemplate = $tpl;
    }
}

/**
 * RESPONSE
 */
echo json_encode([
    'success' => true,
    'data' => [
        'smtp' => $emailSettings,
        'client_template' => $clientTemplate,
        'admin_template' => $adminTemplate,
    ]
], JSON_UNESCAPED_UNICODE);
