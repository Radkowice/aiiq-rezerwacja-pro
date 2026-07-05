<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();

function email_settings_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus = 400,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $userId = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && trim($stage) !== '') {
        $details['stage'] = trim($stage);
    }

    $context = [
        'action_key' => 'email_settings_save',
        'endpoint' => '/api/email/save-email-settings.php',
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

    $userId = trim((string) ($userId ?? ($_SESSION['user']['id'] ?? '')));
    if ($userId !== '') {
        $context['user_id'] = $userId;
    }

    security_log_event($eventKey, $context);
}

function email_settings_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function email_settings_text($value, int $maxLength, bool $required = false): ?string
{
    if (is_array($value) || is_object($value)) {
        email_settings_security_event('email_settings_validation_failed', 'validation_failed', 422, 'failed', 'low', null, null, 'text_type');
        email_settings_json([
            'success' => false,
            'error' => 'Nieprawidłowe dane wejściowe.'
        ], 422);
    }

    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        if ($required) {
            email_settings_security_event('email_settings_validation_failed', 'validation_failed', 422, 'failed', 'low', null, null, 'required_field');
            email_settings_json([
                'success' => false,
                'error' => 'Uzupełnij wymagane pola.'
            ], 422);
        }

        return null;
    }

    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        email_settings_security_event('email_settings_validation_failed', 'validation_failed', 422, 'failed', 'low', null, null, 'text_length');
        email_settings_json([
            'success' => false,
            'error' => 'Wpisany tekst jest zbyt długi.'
        ], 422);
    }

    return $text;
}

function email_settings_request(
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    email_settings_security_event('email_settings_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    header('Allow: POST');
    email_settings_json([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

if (empty($_SESSION['user']['tenant_id'])) {
    email_settings_security_event('email_settings_unauthorized', 'unauthorized', 401, 'denied', 'medium');
    email_settings_json([
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ], 401);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$userId = (string) ($_SESSION['user']['id'] ?? '');
$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    email_settings_security_event('email_settings_invalid_json', 'invalid_json', 400, 'failed', 'low', $tenantId, $userId);
    email_settings_json([
        'success' => false,
        'error' => 'Brak danych wejściowych.'
    ], 400);
}

$section = (string) ($input['section'] ?? 'all');

if (!in_array($section, ['all', 'smtp', 'global_template'], true)) {
    email_settings_security_event('email_settings_validation_failed', 'validation_failed', 422, 'failed', 'low', $tenantId, $userId, 'section');
    email_settings_json([
        'success' => false,
        'error' => 'Nieprawidłowa sekcja zapisu.'
    ], 422);
}

$supabaseUrl = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$serviceRoleKey = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$schema = getenv('SUPABASE_DB_SCHEMA') ?: 'public';

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    email_settings_security_event('email_settings_env_missing', 'env_missing', 500, 'error', 'high', $tenantId, $userId, 'supabase_config');
    email_settings_json([
        'success' => false,
        'error' => 'Nie udało się wczytać konfiguracji systemu.'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    email_settings_security_event('email_settings_tenant_denied', 'tenant_denied', 401, 'denied', 'high', $tenantId, $userId);
    email_settings_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.'
    ], 401);
}

if ($section === 'all' || $section === 'smtp') {
    $smtpHost = email_settings_text($input['smtp_host'] ?? null, 255, true);
    $smtpPort = (int) ($input['smtp_port'] ?? 587);
    $smtpUsername = email_settings_text($input['smtp_user'] ?? null, 255, true);
    $fromEmail = email_settings_text($input['smtp_email'] ?? null, 255, true);
    $fromName = email_settings_text($input['smtp_name'] ?? null, 255, true);

    if ($smtpPort <= 0 || $smtpPort > 65535) {
        email_settings_security_event('email_settings_validation_failed', 'validation_failed', 422, 'failed', 'low', $tenantId, $userId, 'smtp_port');
        email_settings_json([
            'success' => false,
            'error' => 'Podaj poprawny port SMTP.'
        ], 422);
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
        email_settings_security_event('email_settings_validation_failed', 'validation_failed', 422, 'failed', 'low', $tenantId, $userId, 'smtp_email');
        email_settings_json([
            'success' => false,
            'error' => 'Podaj poprawny adres e-mail.'
        ], 422);
    }

    $emailSettingsReadUrl = $supabaseUrl
        . '/rest/v1/email_settings'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&limit=1';

    $emailSettingsReadResult = email_settings_request('GET', $emailSettingsReadUrl, $serviceRoleKey, $schema);

    if (!$emailSettingsReadResult['ok']) {
        email_settings_security_event('email_settings_current_fetch_failed', 'current_settings_fetch_failed', 500, 'error', 'medium', $tenantId, $userId, 'smtp');
        email_settings_json([
            'success' => false,
            'error' => 'Nie udało się odczytać obecnych ustawień SMTP.'
        ], 500);
    }

    $existingEmailSettings = $emailSettingsReadResult['json'][0] ?? [];
    $newSmtpPassword = trim((string) ($input['smtp_pass'] ?? ''));
    $smtpPasswordToSave = $newSmtpPassword !== ''
        ? $newSmtpPassword
        : (string) ($existingEmailSettings['smtp_password'] ?? '');

    $emailSettingsPayload = [[
        'tenant_id' => $tenantId,
        'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort,
        'smtp_encryption' => $input['smtp_encryption'] ?? ($existingEmailSettings['smtp_encryption'] ?? 'tls'),
        'smtp_auth' => isset($input['smtp_auth']) ? (bool) $input['smtp_auth'] : true,
        'smtp_username' => $smtpUsername,
        'smtp_password' => $smtpPasswordToSave,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'reply_to_email' => $input['reply_to_email'] ?? $fromEmail,
        'reply_to_name' => $input['reply_to_name'] ?? $fromName,
        'admin_notify_email' => $input['admin_notify_email'] ?? $fromEmail,
        'send_client_confirmation' => isset($input['send_client_confirmation']) ? (bool) $input['send_client_confirmation'] : true,
        'send_admin_notification' => isset($input['send_admin_notification']) ? (bool) $input['send_admin_notification'] : true,
        'is_active' => isset($input['is_active']) ? (bool) $input['is_active'] : true,
    ]];

    $emailSettingsUrl = $supabaseUrl . '/rest/v1/email_settings?on_conflict=tenant_id';
    $emailSettingsResult = email_settings_request('POST', $emailSettingsUrl, $serviceRoleKey, $schema, $emailSettingsPayload);

    if (!$emailSettingsResult['ok']) {
        email_settings_security_event('email_settings_smtp_save_failed', 'smtp_save_failed', 500, 'error', 'medium', $tenantId, $userId, 'smtp');
        email_settings_json([
            'success' => false,
            'error' => 'Nie udało się zapisać ustawień SMTP.'
        ], 500);
    }
}

if ($section === 'all' || $section === 'global_template') {
    $clientSubject = email_settings_text($input['mail_subject'] ?? null, 255, true);
    $clientIntroHtml = email_settings_text($input['mail_body'] ?? null, 10000, true);
    $serviceName = email_settings_text($input['service_name'] ?? null, 255);

    $adminSubject = email_settings_text($input['admin_mail_subject'] ?? null, 255) ?: 'Nowa rezerwacja: {date} o godz. {time}';
    $adminIntroHtml = email_settings_text($input['admin_mail_body'] ?? null, 10000)
        ?: "Nowa rezerwacja została zapisana w systemie.\n\n"
            . "Klient: {name}\n"
            . "Email: {email}\n"
            . "Telefon: {phone}\n\n"
            . "Termin: {date} o godz. {time}\n\n"
            . "Uwagi:\n"
            . "{message}";

    $emailTemplatesUrl = $supabaseUrl . '/rest/v1/email_templates?on_conflict=tenant_id,template_key';

    $emailClientTemplateResult = email_settings_request(
        'POST',
        $emailTemplatesUrl,
        $serviceRoleKey,
        $schema,
        [[
            'tenant_id' => $tenantId,
            'template_key' => 'booking_client_confirmation',
            'subject' => $clientSubject,
            'body_html' => $clientIntroHtml,
            'service_name' => $serviceName,
            'is_enabled' => true,
        ]]
    );

    if (!$emailClientTemplateResult['ok']) {
        email_settings_security_event('email_settings_template_save_failed', 'template_save_failed', 500, 'error', 'medium', $tenantId, $userId, 'client_template');
        email_settings_json([
            'success' => false,
            'error' => 'Nie udało się zapisać globalnego szablonu e-mail.'
        ], 500);
    }

    $emailAdminTemplateResult = email_settings_request(
        'POST',
        $emailTemplatesUrl,
        $serviceRoleKey,
        $schema,
        [[
            'tenant_id' => $tenantId,
            'template_key' => 'booking_admin_notification',
            'subject' => $adminSubject,
            'body_html' => $adminIntroHtml,
            'service_name' => $serviceName,
            'is_enabled' => true,
        ]]
    );

    if (!$emailAdminTemplateResult['ok']) {
        email_settings_security_event('email_settings_template_save_failed', 'template_save_failed', 500, 'error', 'medium', $tenantId, $userId, 'admin_template');
        email_settings_json([
            'success' => false,
            'error' => 'Nie udało się zapisać globalnego szablonu e-mail.'
        ], 500);
    }
}

email_settings_security_event('email_settings_save_success', 'email_settings_save_success', 200, 'success', 'low', $tenantId, $userId, $section);

email_settings_json([
    'success' => true,
    'message' => 'Ustawienia e-mail zapisane.',
]);
