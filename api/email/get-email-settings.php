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
        'error' => 'Nie udało się wczytać konfiguracji systemu.'
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
    . '&select=' . rawurlencode('smtp_host,smtp_port,smtp_encryption,smtp_auth,smtp_username,from_email,from_name,reply_to_email,reply_to_name,admin_notify_email,send_client_confirmation,send_admin_notification,is_active,smtp_password')
    . '&is_active=eq.true'
    . '&limit=1';

$emailSettingsRes = supabaseRequest($emailSettingsUrl, $serviceRoleKey, $schema);

if (!$emailSettingsRes['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać ustawień e-mail.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$emailSettings = $emailSettingsRes['json'][0] ?? null;
$smtp = null;

if (is_array($emailSettings)) {
    $smtp = [
        'smtp_host' => (string) ($emailSettings['smtp_host'] ?? ''),
        'smtp_port' => $emailSettings['smtp_port'] ?? null,
        'smtp_encryption' => (string) ($emailSettings['smtp_encryption'] ?? ''),
        'smtp_auth' => !empty($emailSettings['smtp_auth']),
        'smtp_username' => (string) ($emailSettings['smtp_username'] ?? ''),
        'from_email' => (string) ($emailSettings['from_email'] ?? ''),
        'from_name' => (string) ($emailSettings['from_name'] ?? ''),
        'reply_to_email' => (string) ($emailSettings['reply_to_email'] ?? ''),
        'reply_to_name' => (string) ($emailSettings['reply_to_name'] ?? ''),
        'admin_notify_email' => (string) ($emailSettings['admin_notify_email'] ?? ''),
        'send_client_confirmation' => !empty($emailSettings['send_client_confirmation']),
        'send_admin_notification' => !empty($emailSettings['send_admin_notification']),
        'is_active' => !empty($emailSettings['is_active']),
        'has_smtp_password' => !empty($emailSettings['smtp_password']),
    ];
}

/**
 * 2. EMAIL TEMPLATES
 */
$emailTemplatesUrl = $supabaseUrl
    . '/rest/v1/email_templates'
    . '?tenant_id=eq.' . urlencode($tenantId)
    . '&select=' . rawurlencode('template_key,subject,body_html,service_name,is_enabled');

$emailTemplatesRes = supabaseRequest($emailTemplatesUrl, $serviceRoleKey, $schema);

if (!$emailTemplatesRes['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać szablonów e-mail.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$templates = $emailTemplatesRes['json'] ?? [];

$clientTemplate = null;
$adminTemplate = null;

foreach ($templates as $tpl) {
    if (!is_array($tpl)) {
        continue;
    }

    $template = [
        'template_key' => (string) ($tpl['template_key'] ?? ''),
        'subject' => (string) ($tpl['subject'] ?? ''),
        'body_html' => (string) ($tpl['body_html'] ?? ''),
        'service_name' => (string) ($tpl['service_name'] ?? ''),
        'is_enabled' => !empty($tpl['is_enabled']),
    ];

    if (($tpl['template_key'] ?? '') === 'booking_client_confirmation') {
        $clientTemplate = $template;
    }
    if (($tpl['template_key'] ?? '') === 'booking_admin_notification') {
        $adminTemplate = $template;
    }
}

/**
 * RESPONSE
 */
echo json_encode([
    'success' => true,
    'data' => [
        'smtp' => $smtp,
        'client_template' => $clientTemplate,
        'admin_template' => $adminTemplate,
    ]
], JSON_UNESCAPED_UNICODE);
