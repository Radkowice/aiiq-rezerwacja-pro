<?php
header('Content-Type: application/json; charset=utf-8');

session_start();

if (!isset($_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) $_SESSION['user']['tenant_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Brak danych wejściowych'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$supabaseUrl = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$serviceRoleKey = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'public';

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase w ENV'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Dane SMTP
 */
$smtpHost = trim((string) ($input['smtp_host'] ?? ''));
$smtpPort = (int) ($input['smtp_port'] ?? 587);
$smtpUsername = trim((string) ($input['smtp_user'] ?? ''));
$smtpPassword = trim((string) ($input['smtp_pass'] ?? ''));
$fromEmail = trim((string) ($input['smtp_email'] ?? ''));
$fromName = trim((string) ($input['smtp_name'] ?? ''));
$replyToEmail = trim((string) ($input['reply_to_email'] ?? $fromEmail));
$replyToName = trim((string) ($input['reply_to_name'] ?? $fromName));
$adminNotifyEmail = trim((string) ($input['admin_notify_email'] ?? $fromEmail));

$smtpEncryption = trim((string) ($input['smtp_encryption'] ?? 'tls'));
if (!in_array($smtpEncryption, ['none', 'ssl', 'tls'], true)) {
    $smtpEncryption = 'tls';
}

$smtpAuth = isset($input['smtp_auth']) ? (bool) $input['smtp_auth'] : true;
$isActive = isset($input['is_active']) ? (bool) $input['is_active'] : true;
$sendClientConfirmation = isset($input['send_client_confirmation']) ? (bool) $input['send_client_confirmation'] : true;
$sendAdminNotification = isset($input['send_admin_notification']) ? (bool) $input['send_admin_notification'] : true;

/**
 * Template klienta
 */
$clientSubject = trim((string) ($input['mail_subject'] ?? ''));
$clientIntroHtml = trim((string) ($input['mail_body'] ?? ''));
$serviceName = trim((string) ($input['service_name'] ?? ''));

/**
 * Template admina
 */
$adminSubject = trim((string) ($input['admin_mail_subject'] ?? ''));
$adminIntroHtml = trim((string) ($input['admin_mail_body'] ?? ''));

function supabaseRequest(
    string $method,
    string $url,
    string $serviceRoleKey,
    string $schema,
    ?array $payload = null
): array {
    $ch = curl_init($url);

    $headers = [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: resolution=merge-duplicates,return=representation',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'error' => $curlError,
        'body' => $response,
        'json' => json_decode((string) $response, true),
    ];
}

// =====================
// ODCZYT AKTUALNEGO email_settings DLA TENANTA
// =====================
$emailSettingsReadUrl = $supabaseUrl
    . '/rest/v1/email_settings'
    . '?tenant_id=eq.' . urlencode($tenantId)
    . '&is_active=eq.true'
    . '&limit=1';

$emailSettingsReadResult = supabaseRequest(
    'GET',
    $emailSettingsReadUrl,
    $serviceRoleKey,
    $schema
);

if (!$emailSettingsReadResult['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się odczytać obecnych ustawień email_settings',
        'details' => $emailSettingsReadResult['json'] ?: $emailSettingsReadResult['body'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$existingEmailSettings = $emailSettingsReadResult['json'][0] ?? null;
$existingSmtpPassword = $existingEmailSettings['smtp_password'] ?? '';

$newSmtpPassword = trim((string) ($input['smtp_pass'] ?? ''));
$smtpPasswordToSave = $newSmtpPassword !== '' ? $newSmtpPassword : $existingSmtpPassword;

$emailSettingsPayload = [[
    'tenant_id' => $tenantId,
    'smtp_host' => $smtpHost,
    'smtp_port' => $smtpPort,
    'smtp_encryption' => $smtpEncryption,
    'smtp_auth' => $smtpAuth,
    'smtp_username' => $smtpUsername,
    'smtp_password' => $smtpPasswordToSave,
    'from_email' => $fromEmail,
    'from_name' => $fromName,
    'reply_to_email' => $replyToEmail,
    'reply_to_name' => $replyToName,
    'admin_notify_email' => $adminNotifyEmail,
    'send_client_confirmation' => $sendClientConfirmation,
    'send_admin_notification' => $sendAdminNotification,
    'is_active' => $isActive,
]];

$emailClientTemplatePayload = [[
    'tenant_id' => $tenantId,
    'template_key' => 'booking_client_confirmation',
    'subject' => $clientSubject,
    'body_html' => $clientIntroHtml,
    'service_name' => $serviceName,
    'is_enabled' => true,
]];

$emailSettingsUrl = $supabaseUrl . '/rest/v1/email_settings?on_conflict=tenant_id';
$emailTemplatesUrl = $supabaseUrl . '/rest/v1/email_templates?on_conflict=tenant_id,template_key';

$emailSettingsResult = supabaseRequest(
    'POST',
    $emailSettingsUrl,
    $serviceRoleKey,
    $schema,
    $emailSettingsPayload
);

if (!$emailSettingsResult['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać email_settings',
        'details' => $emailSettingsResult['json'] ?: $emailSettingsResult['body'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$emailClientTemplateResult = supabaseRequest(
    'POST',
    $emailTemplatesUrl,
    $serviceRoleKey,
    $schema,
    $emailClientTemplatePayload
);

if (!$emailClientTemplateResult['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać szablonu email klienta',
        'details' => $emailClientTemplateResult['json'] ?: $emailClientTemplateResult['body'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Ustawienia email zapisane do bazy',
    'saved' => [
        'email_settings' => true,
        'client_template' => true,
        'service_name' => $serviceName,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

echo json_encode([
    'success' => true,
    'message' => 'Ustawienia email zapisane do bazy',
    'debug' => [
        'tenant_id' => $tenantId,
        'service_name_received' => $input['service_name'] ?? null,
        'service_name_trimmed' => $serviceName,
        'client_template_payload' => $emailClientTemplatePayload,
        'client_template_response' => $emailClientTemplateResult['json'],
        'client_template_raw_body' => $emailClientTemplateResult['body'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;