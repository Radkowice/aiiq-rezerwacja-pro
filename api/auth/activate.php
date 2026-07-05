<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/system_subscription_mail.php';
require_once __DIR__ . '/../helpers/activation_link.php';
require_once __DIR__ . '/../helpers/security.php';

$SUPABASE_URL = rtrim((string) getenv('SUPABASE_URL'), '/');
$SUPABASE_KEY = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$SCHEMA = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');
$CENTRAL_LOGIN_URL = 'https://rezerwacja-ai-iq.pl/logowanie.html';
$ACTIVATION_SECURITY_CONTEXT = [];

function activation_security_context(array $context): void
{
    global $ACTIVATION_SECURITY_CONTEXT;

    foreach (['tenant_id', 'user_id', 'email'] as $key) {
        $value = trim((string) ($context[$key] ?? ''));
        if ($value !== '') {
            $ACTIVATION_SECURITY_CONTEXT[$key] = $value;
        }
    }
}

function activation_security_event(string $eventKey, string $reason, int $statusCode, string $result = 'failed', string $severity = 'medium', array $context = []): void
{
    global $ACTIVATION_SECURITY_CONTEXT;

    $merged = array_merge($ACTIVATION_SECURITY_CONTEXT, $context);
    $details = ['reason' => $reason];

    if (isset($context['stage']) && is_scalar($context['stage'])) {
        $details['stage'] = (string) $context['stage'];
    }

    security_log_event($eventKey, [
        'action_key' => 'auth_activate_account',
        'severity' => $severity,
        'actor_type' => 'tenant_user',
        'tenant_id' => (string) ($merged['tenant_id'] ?? ''),
        'user_id' => (string) ($merged['user_id'] ?? ''),
        'email' => (string) ($merged['email'] ?? ''),
        'ip_address' => security_client_ip(),
        'endpoint' => '/api/auth/activate.php',
        'http_method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'response_status' => $statusCode,
        'result' => $result,
        'details' => $details,
    ]);
}

function activation_redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

function activation_redirect_error(string $reason = 'invalid', array $context = [], string $eventKey = 'auth_activation_failed', int $statusCode = 400): void
{
    activation_security_event($eventKey, $reason, $statusCode, 'failed', $statusCode >= 500 ? 'high' : 'medium', $context);
    activation_redirect('https://rezerwacja-ai-iq.pl/aktywacja-link.html');
}

function activation_request(string $method, string $path, ?array $payload = null): array
{
    global $SUPABASE_URL, $SUPABASE_KEY, $SCHEMA;

    $headers = supabaseHeaders($SUPABASE_KEY, $SCHEMA);

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($SUPABASE_URL . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function activation_is_valid_domain(string $domain): bool
{
    $domain = strtolower(trim($domain));

    if ($domain === '' || strlen($domain) > 253 || preg_match('/[\x00-\x20\x7f\/\\\\:?#]/', $domain)) {
        return false;
    }

    return preg_match(
        '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
        $domain
    ) === 1;
}

function activation_is_uuid(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        trim($value)
    ) === 1;
}

function activation_fetch_user(string $tenantId, string $userId): ?array
{
    $result = activation_request(
        'GET',
        '/rest/v1/users?select=id,tenant_id,email,role,is_active&id=eq.' . rawurlencode($userId)
        . '&tenant_id=eq.' . rawurlencode($tenantId) . '&limit=1'
    );

    if (!$result['ok'] || !is_array($result['data'][0] ?? null)) {
        return null;
    }

    return $result['data'][0];
}

function activation_fetch_active_domain(string $tenantId): string
{
    $result = activation_request(
        'GET',
        '/rest/v1/tenant_domains?select=domain&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true&order=is_primary.desc&limit=1'
    );
    $domain = strtolower(trim((string) ($result['data'][0]['domain'] ?? '')));

    return $result['ok'] && activation_is_valid_domain($domain) ? $domain : '';
}

function activation_redirect_already_active(string $tenantId): void
{
    $domain = activation_fetch_active_domain($tenantId);

    if ($domain !== '') {
        activation_redirect('https://' . $domain . '/konto-aktywne.html');
    }

    activation_redirect('https://rezerwacja-ai-iq.pl/konto-aktywne.html');
}

function activation_active_tenant_from_ref(string $token, string $ref): string
{
    $context = activation_link_parse_ref($token, $ref);

    if (!is_array($context)) {
        return '';
    }

    $tenantId = trim((string) ($context['tenant_id'] ?? ''));
    $userId = trim((string) ($context['user_id'] ?? ''));
    $user = activation_fetch_user($tenantId, $userId);

    if (
        !is_array($user)
        || !filter_var($user['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
    ) {
        return '';
    }

    return $tenantId;
}

function activation_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function activation_format_date_pl(?string $date): string
{
    $date = trim((string) $date);

    if ($date === '') {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format('d.m.Y');
    } catch (Throwable $e) {
        return $date;
    }
}

function activation_fetch_subscription(string $tenantId): ?array
{
    $result = activation_request(
        'GET',
        '/rest/v1/tenant_subscriptions?select=tenant_id,plan_code,plan_name,billing_period,status,amount,currency,current_period_start,current_period_end,next_payment_due_at,last_payment_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1'
    );

    if (!$result['ok'] || !is_array($result['data'][0] ?? null)) {
        return null;
    }

    return $result['data'][0];
}

function activation_fetch_initial_payment(string $tenantId): array
{
    $result = activation_request(
        'GET',
        '/rest/v1/tenant_subscription_payments?select=id,tenant_id,payment_type,plan_code,billing_period,amount,currency,status,paid_at,processed_at,subscription_period_start,subscription_period_end'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&payment_type=eq.subscription_initial'
        . '&status=eq.paid'
        . '&order=created_at.desc'
        . '&limit=1'
    );

    if (!$result['ok'] || !is_array($result['data'][0] ?? null)) {
        return [];
    }

    return $result['data'][0];
}

function activation_fetch_mail_context(string $tenantId, string $adminEmail, string $domain): array
{
    $context = [
        'recipient_email' => $adminEmail,
        'company_name' => '',
        'panel_domain' => $domain,
    ];

    $settingsResult = activation_request(
        'GET',
        '/rest/v1/tenant_service_settings?select=company_full_name,company_email'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1'
    );

    if ($settingsResult['ok'] && is_array($settingsResult['data'][0] ?? null)) {
        $settings = $settingsResult['data'][0];
        $context['company_name'] = trim((string) ($settings['company_full_name'] ?? ''));

        $companyEmail = trim((string) ($settings['company_email'] ?? ''));

        if (!filter_var($context['recipient_email'], FILTER_VALIDATE_EMAIL) && filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
            $context['recipient_email'] = $companyEmail;
        }
    }

    return $context;
}

function activation_build_fallback_pro_mail_html(array $payment, array $subscription, array $context): string
{
    $companyName = trim((string) ($context['company_name'] ?? ''));
    $panelDomain = trim((string) ($context['panel_domain'] ?? ''));
    $panelUrl = function_exists('system_subscription_mail_admin_login_url')
        ? system_subscription_mail_admin_login_url($panelDomain)
        : ($panelDomain !== '' ? 'https://' . $panelDomain . '/logowanie.html' : 'https://rezerwacja-ai-iq.pl/logowanie.html');

    $periodEnd = activation_format_date_pl((string) ($subscription['current_period_end'] ?? ($payment['subscription_period_end'] ?? '')));
    $billingPeriod = strtolower(trim((string) ($subscription['billing_period'] ?? ($payment['billing_period'] ?? ''))));
    $billingLabel = $billingPeriod === 'yearly' ? 'roczny' : ($billingPeriod === 'monthly' ? 'miesięczny' : 'aktywny');

    $safeCompany = activation_escape($companyName !== '' ? $companyName : 'Twoja firma');
    $safePanelUrl = activation_escape($panelUrl);
    $safeBilling = activation_escape($billingLabel);
    $safePeriodEnd = activation_escape($periodEnd !== '' ? $periodEnd : 'zgodnie z opłaconą subskrypcją');

    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f3f6fb;font-family:Arial,sans-serif;color:#0f172a;">'
        . '<div style="max-width:640px;margin:0 auto;padding:28px;">'
        . '<div style="background:#ffffff;border:1px solid #dbe5f3;border-radius:18px;padding:28px;">'
        . '<div style="text-align:center;margin-bottom:22px;">'
        . '<div style="display:inline-block;background:#0f172a;color:#fff;border-radius:999px;padding:10px 18px;font-weight:700;">AI-IQ</div>'
        . '<div style="font-size:42px;line-height:1;margin:18px 0 0;" aria-hidden="true">💳</div>'
        . '<h1 style="margin:22px 0 8px;font-size:26px;line-height:1.25;">Plan Pro jest aktywny</h1>'
        . '<p style="margin:0;color:#475569;line-height:1.5;">Konto administratora zostało aktywowane. Możesz zalogować się do panelu i korzystać z funkcji planu Pro.</p>'
        . '</div>'
        . '<div style="border:1px solid #dbe5f3;border-radius:14px;overflow:hidden;margin:22px 0;">'
        . '<div style="padding:14px 16px;border-bottom:1px solid #dbe5f3;"><span style="color:#64748b;">Firma</span><br><strong>' . $safeCompany . '</strong></div>'
        . '<div style="padding:14px 16px;border-bottom:1px solid #dbe5f3;"><span style="color:#64748b;">Plan</span><br><strong>Pro — ' . $safeBilling . '</strong></div>'
        . '<div style="padding:14px 16px;"><span style="color:#64748b;">Abonament ważny do</span><br><strong>' . $safePeriodEnd . '</strong></div>'
        . '</div>'
        . '<p style="text-align:center;margin:28px 0;">'
        . '<a href="' . $safePanelUrl . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:999px;padding:14px 24px;font-weight:700;">Przejdź do panelu</a>'
        . '</p>'
        . '<p style="margin:20px 0 0;color:#64748b;font-size:13px;line-height:1.5;text-align:center;">To wiadomość systemowa AI-IQ Rezerwacja Pro. Prosimy nie odpowiadać na tę wiadomość.</p>'
        . '</div></div></body></html>';
}

function activation_send_pro_activated_mail(string $tenantId, string $adminEmail, string $domain): void
{
    if (!function_exists('sendSystemMail')) {
        error_log('AI-IQ activation: sendSystemMail unavailable.');
        return;
    }

    $subscription = activation_fetch_subscription($tenantId);

    if (!is_array($subscription)) {
        error_log('AI-IQ activation: subscription not found for activated tenant.');
        return;
    }

    $planCode = strtolower(trim((string) ($subscription['plan_code'] ?? '')));
    $status = strtolower(trim((string) ($subscription['status'] ?? '')));

    if ($planCode !== 'pro' || $status !== 'active') {
        return;
    }

    $payment = activation_fetch_initial_payment($tenantId);
    $context = activation_fetch_mail_context($tenantId, $adminEmail, $domain);
    $recipient = trim((string) ($context['recipient_email'] ?? ''));

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('AI-IQ activation: missing recipient for Pro activation mail.');
        return;
    }

    $subject = 'Plan Pro aktywny w AI-IQ Rezerwacja Pro';

    if (function_exists('buildSubscriptionProActivatedMailHtml')) {
        $html = (string) buildSubscriptionProActivatedMailHtml($payment, $subscription, $context);
    } else {
        $html = activation_build_fallback_pro_mail_html($payment, $subscription, $context);
    }

    if (trim($html) === '') {
        error_log('AI-IQ activation: empty Pro activation mail body.');
        return;
    }

    if (!sendSystemMail($recipient, $subject, $html)) {
        error_log('AI-IQ activation: Pro activation mail send failed.');
    }
}

function activation_send_account_activated_mail(string $tenantId, string $adminEmail, string $domain): void
{
    if (!function_exists('sendSystemMail')) {
        error_log('AI-IQ activation: sendSystemMail unavailable.');
        return;
    }

    $subscription = activation_fetch_subscription($tenantId);
    $planCode = strtolower(trim((string) ($subscription['plan_code'] ?? 'free')));
    $status = strtolower(trim((string) ($subscription['status'] ?? '')));

    if ($planCode === 'pro' && $status === 'active') {
        activation_send_pro_activated_mail($tenantId, $adminEmail, $domain);
        return;
    }

    $context = activation_fetch_mail_context($tenantId, $adminEmail, $domain);
    $recipient = trim((string) ($context['recipient_email'] ?? ''));

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('AI-IQ activation: missing recipient for account activation mail.');
        return;
    }

    $context['plan'] = $planCode === 'pro' ? 'Pro' : 'Free';

    if (!function_exists('buildAccountActivatedMailHtml')) {
        error_log('AI-IQ activation: account activation mail builder unavailable.');
        return;
    }

    $html = (string) buildAccountActivatedMailHtml($context);

    if (trim($html) === '') {
        error_log('AI-IQ activation: empty account activation mail body.');
        return;
    }

    if (!sendSystemMail($recipient, 'Konto administratora aktywne w AI-IQ Rezerwacja Pro', $html)) {
        error_log('AI-IQ activation: account activation mail send failed.');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    activation_redirect_error('method_not_allowed', [], 'auth_activation_method_not_allowed', 405);
}

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    activation_redirect_error('env_missing', [], 'auth_activation_env_missing', 500);
}

$token = trim((string) ($_GET['token'] ?? ''));
$activationRef = trim((string) ($_GET['ref'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/i', $token) !== 1) {
    activation_redirect_error('token_format_invalid', [], 'auth_activation_token_format_invalid', 400);
}

$tokenHash = hash('sha256', $token);
$now = gmdate('c');
$tokenResult = activation_request(
    'GET',
    '/rest/v1/user_activation_tokens?select=id,tenant_id,user_id'
    . '&token_hash=eq.' . rawurlencode($tokenHash)
    . '&used_at=is.null&revoked_at=is.null'
    . '&expires_at=gt.' . rawurlencode($now)
    . '&limit=1'
);

if (!$tokenResult['ok'] || empty($tokenResult['data'][0])) {
    $historicalTokenResult = activation_request(
        'GET',
        '/rest/v1/user_activation_tokens?select=id,tenant_id,user_id,used_at,revoked_at,expires_at'
        . '&token_hash=eq.' . rawurlencode($tokenHash)
        . '&limit=1'
    );
    if ($historicalTokenResult['ok'] && is_array($historicalTokenResult['data'][0] ?? null)) {
        $historicalTokenState = $historicalTokenResult['data'][0];
        $historicalTokenId = trim((string) ($historicalTokenState['id'] ?? ''));
        $historicalTenantId = trim((string) ($historicalTokenState['tenant_id'] ?? ''));
        $historicalUserId = trim((string) ($historicalTokenState['user_id'] ?? ''));
        activation_security_context([
            'tenant_id' => $historicalTenantId,
            'user_id' => $historicalUserId,
        ]);

        if (
            $historicalTokenId !== ''
            && $historicalTenantId !== ''
            && activation_is_uuid($historicalUserId)
        ) {
            $historicalTokenUser = activation_fetch_user($historicalTenantId, $historicalUserId);

            if (
                is_array($historicalTokenUser)
                && filter_var($historicalTokenUser['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ) {
                activation_redirect_already_active($historicalTenantId);
            }
        }
    }

    $refTenantId = activation_active_tenant_from_ref($token, $activationRef);
    unset($token, $tokenHash, $activationRef);

    if ($refTenantId !== '') {
        activation_redirect_already_active($refTenantId);
    }

    activation_redirect_error('token_not_found_or_expired', [], 'auth_activation_token_not_found_or_expired', 400);
}

unset($token, $tokenHash, $activationRef);

$activationState = $tokenResult['data'][0];
$stateId = trim((string) ($activationState['id'] ?? ''));
$tenantId = trim((string) ($activationState['tenant_id'] ?? ''));
$userId = trim((string) ($activationState['user_id'] ?? ''));
activation_security_context([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
]);

if (
    $stateId === ''
    || $tenantId === ''
    || !activation_is_uuid($userId)
) {
    activation_redirect_error('token_state_invalid', [], 'auth_activation_token_state_invalid', 400);
}

$user = activation_fetch_user($tenantId, $userId);
if (!is_array($user)) {
    activation_redirect_error('user_not_found', [], 'auth_activation_user_not_found', 404);
}

$adminEmail = trim((string) ($user['email'] ?? ''));
activation_security_context(['email' => $adminEmail]);
$wasActive = filter_var($user['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($wasActive) {
    activation_redirect_already_active($tenantId);
}

$activateUserResult = activation_request(
    'PATCH',
    '/rest/v1/users?id=eq.' . rawurlencode($userId) . '&tenant_id=eq.' . rawurlencode($tenantId),
    ['is_active' => true]
);
if (!$activateUserResult['ok']) {
    activation_redirect_error('user_activation_update_failed', [], 'auth_activation_user_update_failed', 500);
}

$markUsedResult = activation_request(
    'PATCH',
    '/rest/v1/user_activation_tokens?id=eq.' . rawurlencode($stateId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId)
    . '&used_at=is.null&revoked_at=is.null',
    ['used_at' => $now]
);
if (!$markUsedResult['ok'] || empty($markUsedResult['data'])) {
    activation_redirect_error('token_mark_used_failed', [], 'auth_activation_token_mark_used_failed', 500);
}

$revokeOtherTokensResult = activation_request(
    'PATCH',
    '/rest/v1/user_activation_tokens?tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId)
    . '&used_at=is.null&revoked_at=is.null&id=neq.' . rawurlencode($stateId),
    ['revoked_at' => $now]
);
if (!$revokeOtherTokensResult['ok']) {
    activation_redirect_error('token_revoke_others_failed', [], 'auth_activation_token_revoke_failed', 500);
}

$domain = activation_fetch_active_domain($tenantId);

if ($domain === '') {
    activation_redirect_error('domain_unavailable', [], 'auth_activation_domain_unavailable', 500);
}

if (!$wasActive) {
    activation_send_account_activated_mail($tenantId, $adminEmail, $domain);
}

activation_security_event('auth_activation_success', 'account_activated', 302, 'success', 'low', [
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'email' => $adminEmail,
]);

activation_redirect('https://' . $domain . '/logowanie.html?activated=1');
