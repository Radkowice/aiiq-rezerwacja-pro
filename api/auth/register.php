<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/system_subscription_mail.php';
require_once __DIR__ . '/../helpers/aiiq_payu.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/activation_link.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/security.php';

start_secure_session();
ini_set('display_errors', '0');
error_reporting(E_ALL);

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';
$REGISTER_BASE_DOMAIN = 'rezerwacja-ai-iq.pl';
$REGISTER_SECURITY_CONTEXT = [];

function register_security_context(array $context): void
{
    global $REGISTER_SECURITY_CONTEXT;

    foreach (['tenant_id', 'user_id', 'email', 'phone'] as $key) {
        $value = trim((string) ($context[$key] ?? ''));
        if ($value !== '') {
            $REGISTER_SECURITY_CONTEXT[$key] = $value;
        }
    }
}

function register_security_event(string $eventKey, string $reason, int $statusCode, string $result = 'failed', string $severity = 'medium', array $context = []): void
{
    global $REGISTER_SECURITY_CONTEXT;

    $merged = array_merge($REGISTER_SECURITY_CONTEXT, $context);
    $details = ['reason' => $reason];

    if (isset($context['stage']) && is_scalar($context['stage'])) {
        $details['stage'] = (string) $context['stage'];
    }

    security_log_event($eventKey, [
        'action_key' => 'auth_register',
        'severity' => $severity,
        'actor_type' => 'tenant_user',
        'tenant_id' => (string) ($merged['tenant_id'] ?? ''),
        'user_id' => (string) ($merged['user_id'] ?? ''),
        'email' => (string) ($merged['email'] ?? ''),
        'phone' => (string) ($merged['phone'] ?? ''),
        'ip_address' => security_client_ip(),
        'endpoint' => '/api/auth/register.php',
        'http_method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
        'response_status' => $statusCode,
        'result' => $result,
        'details' => $details,
    ]);
}

function register_debug_enabled(): bool
{
    $flag = strtolower(trim((string) (getenv('REGISTER_DEBUG_LOG') ?: '')));

    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

function register_debug_sanitize($value)
{
    if (is_array($value)) {
        $safe = [];
        foreach ($value as $key => $item) {
            $keyString = is_int($key) ? (string) $key : strtolower((string) $key);
            if (preg_match('/(password|token|secret|authorization|cookie|session|payload|raw|response|email|phone|tax|nip|address|tenant_id|user_id|payment_id|order_id|ext_order_id)/i', $keyString)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            $safe[$key] = register_debug_sanitize($item);
        }
        return $safe;
    }

    if (is_string($value)) {
        $value = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', '[uuid]', $value) ?? $value;
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value) ?? $value;
        $value = preg_replace('/\b(?:\+?48)?[ -]?[1-9][0-9](?:[ -]?[0-9]){7}\b/', '[phone]', $value) ?? $value;

        return mb_substr($value, 0, 180);
    }

    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    return '[redacted]';
}

function register_debug($label, $data = null): void
{
    if (!register_debug_enabled()) {
        return;
    }

    $safeLabel = preg_replace('/[^A-Z0-9_:-]/i', '_', (string) $label) ?: 'debug';
    $line = date('c') . " [$safeLabel]";

    if ($data !== null) {
        $line .= ' ' . json_encode(register_debug_sanitize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $line .= PHP_EOL;

    @file_put_contents('/var/www/data/register-debug.log', $line, FILE_APPEND | LOCK_EX);
}

function register_store_subscription_return_handoff(string $tenantId, string $paymentId): void
{
    $_SESSION['subscription_payment_return_handoff'] = [
        'tenant_id' => $tenantId,
        'payment_id' => $paymentId,
        'created_at' => time(),
    ];
}

function register_debug_result(string $label, array $result): void
{
    $data = $result['data'] ?? null;

    register_debug($label, [
        'ok' => $result['ok'] ?? false,
        'httpCode' => $result['httpCode'] ?? null,
        'recordCount' => is_array($data) ? count($data) : null,
    ]);
}

function register_write_json_handle($handle, array $data): void
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        return;
    }

    rewind($handle);
    @ftruncate($handle, 0);
    @fwrite($handle, $encoded);
    @fflush($handle);
}

function register_blacklist_contains(string $blacklistFile, string $ip): bool
{
    $handle = @fopen($blacklistFile, 'c+');

    if ($handle === false) {
        return false;
    }

    $isBlocked = false;

    if (@flock($handle, LOCK_EX)) {
        rewind($handle);
        $rawBlacklist = (string) stream_get_contents($handle);
        $blacklist = json_decode($rawBlacklist, true);

        if (trim($rawBlacklist) === '') {
            $blacklist = [];
            register_write_json_handle($handle, $blacklist);
        }

        $isBlocked = is_array($blacklist) && in_array($ip, $blacklist, true);
        @flock($handle, LOCK_UN);
    }

    @fclose($handle);

    return $isBlocked;
}

function register_increment_ban_counter(string $banFile, string $ip): int
{
    $handle = @fopen($banFile, 'c+');

    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if ($handle !== false) {
            @fclose($handle);
        }
        return 0;
    }

    rewind($handle);
    $banData = json_decode((string) stream_get_contents($handle), true);

    if (!is_array($banData)) {
        $banData = [];
    }

    $banData[$ip] = max(0, (int) ($banData[$ip] ?? 0)) + 1;
    $count = $banData[$ip];
    register_write_json_handle($handle, $banData);

    @flock($handle, LOCK_UN);
    @fclose($handle);

    return $count;
}

function register_add_to_blacklist(string $blacklistFile, string $ip): void
{
    $handle = @fopen($blacklistFile, 'c+');

    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if ($handle !== false) {
            @fclose($handle);
        }
        return;
    }

    rewind($handle);
    $blacklist = json_decode((string) stream_get_contents($handle), true);

    if (!is_array($blacklist)) {
        $blacklist = [];
    }

    if (!in_array($ip, $blacklist, true)) {
        $blacklist[] = $ip;
        register_write_json_handle($handle, array_values($blacklist));
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function register_antibot_reject(string $eventKey, string $reason, int $statusCode, string $severity = 'medium'): void
{
    register_security_event($eventKey, $reason, $statusCode, 'blocked', $severity);

    json_response([
        'success' => false,
        'error' => 'Nie udało się wysłać formularza rejestracji. Odśwież stronę i spróbuj ponownie.',
    ], $statusCode);
}

function register_apply_antibot_guards(array $data): void
{
    $website = trim((string) ($data['website'] ?? ''));
    $formStartedAtRaw = trim((string) ($data['form_started_at'] ?? ''));
    $formFillTimeRaw = trim((string) ($data['form_fill_time_ms'] ?? ''));
    $ip = security_client_ip() ?? 'unknown';

    $blacklistFile = __DIR__ . '/../data/blacklist.json';

    if (register_blacklist_contains($blacklistFile, $ip)) {
        register_antibot_reject('auth_register_rate_limited', 'blacklisted_ip', 403, 'high');
    }

    $rateFile = __DIR__ . '/../data/rate_limit_register.json';
    $now = time();
    $limit = 3;
    $window = 60;
    $rateHandle = @fopen($rateFile, 'c+');

    if ($rateHandle !== false && @flock($rateHandle, LOCK_EX)) {
        rewind($rateHandle);
        $rateData = json_decode((string) stream_get_contents($rateHandle), true);

        if (!is_array($rateData)) {
            $rateData = [];
        }

        if (!isset($rateData[$ip]) || !is_array($rateData[$ip])) {
            $rateData[$ip] = [];
        }

        $rateData[$ip] = array_values(array_filter(
            $rateData[$ip],
            static function ($timestamp) use ($now, $window): bool {
                return ($now - (int) $timestamp) < $window;
            }
        ));

        if (count($rateData[$ip]) >= $limit) {
            @flock($rateHandle, LOCK_UN);
            @fclose($rateHandle);

            $banCount = register_increment_ban_counter(
                __DIR__ . '/../data/ban_counter_register.json',
                $ip
            );

            if ($banCount >= 5) {
                register_add_to_blacklist($blacklistFile, $ip);
            }

            register_antibot_reject('auth_register_rate_limited', 'ip_rate_limited', 429);
        }

        $rateData[$ip][] = $now;
        register_write_json_handle($rateHandle, $rateData);
        @flock($rateHandle, LOCK_UN);
    }

    if ($rateHandle !== false) {
        @fclose($rateHandle);
    }

    if (!isset($_SESSION['last_register_time'])) {
        $_SESSION['last_register_time'] = 0;
    }

    if (time() - (int) $_SESSION['last_register_time'] < 10) {
        register_antibot_reject('auth_register_rate_limited', 'session_throttle', 429);
    }

    $_SESSION['last_register_time'] = time();

    if ($website !== '') {
        register_antibot_reject('auth_register_bot_blocked', 'honeypot_triggered', 400);
    }

    $formStartedAt = ctype_digit($formStartedAtRaw) ? (int) $formStartedAtRaw : 0;
    $hasClientFillTime = ctype_digit($formFillTimeRaw);
    $formSubmittedAt = (int) round(microtime(true) * 1000);
    $serverElapsedMs = $formSubmittedAt - $formStartedAt;

    if ($formStartedAt <= 0 || !$hasClientFillTime || $serverElapsedMs < 0) {
        register_antibot_reject('auth_register_bot_blocked', 'invalid_form_timing', 400);
    }

    $formFillTimeMs = (int) $formFillTimeRaw;
    $minimumFillTimeMs = 3000;
    $maximumFormAgeMs = 1000 * 60 * 60 * 6;

    if ($formFillTimeMs < $minimumFillTimeMs || $serverElapsedMs < $minimumFillTimeMs) {
        register_antibot_reject('auth_register_bot_blocked', 'form_too_fast', 400);
    }

    if ($formFillTimeMs > $maximumFormAgeMs || $serverElapsedMs > $maximumFormAgeMs) {
        register_antibot_reject('auth_register_bot_blocked', 'form_too_old', 400);
    }
}

register_debug('START');

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    register_security_event('auth_register_env_missing', 'env_missing', 500, 'failed', 'high');
    register_debug('CONFIG_MISSING', [
        'SUPABASE_URL' => $SUPABASE_URL !== '',
        'SUPABASE_KEY' => $SUPABASE_KEY !== ''
    ]);

    json_response([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));

    if ($action === 'check_subdomain') {
        $subdomainSlug = normalize_subdomain_slug($_GET['slug'] ?? '');
        $availability = check_subdomain_availability($subdomainSlug);

        json_response([
            'success' => true,
            'available' => $availability['available'],
            'slug' => $subdomainSlug,
            'domain' => $availability['domain'],
            'message' => $availability['message'],
        ]);
    }

    if ($action === 'plan_prices') {
        $pricePlanCode = trim((string) ($_GET['plan'] ?? ''));

        if (!is_public_registration_price_plan($pricePlanCode)) {
            json_response([
                'success' => false,
                'error' => 'Nieprawidłowy plan cennika.',
                'prices' => [],
            ], 400);
        }

        $prices = register_fetch_public_paid_plan_prices($pricePlanCode);

        if (empty($prices['ok'])) {
            json_response([
                'success' => false,
                'error' => $prices['error'] ?? 'Nie udało się pobrać cennika wybranego planu.',
                'prices' => [],
            ], 503);
        }

        json_response([
            'success' => true,
            'prices' => $prices['prices'],
        ]);
    }

    $domain = normalize_domain(
        $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? ''
    );

    register_debug('REGISTER_STATUS_GET');

    if ($domain === '' || !is_valid_domain($domain)) {
        json_response([
            'success' => false,
            'registration_allowed' => false,
            'error' => 'Nie udało się ustalić poprawnej domeny rejestracji'
        ], 400);
    }

    $domainExists = supabase_request(
        'GET',
        '/rest/v1/tenant_domains?select=id,tenant_id,domain,is_active&domain=eq.'
        . rawurlencode($domain)
        . '&is_active=eq.true&limit=1'
    );

    register_debug_result('REGISTER_STATUS_DOMAIN_CHECK', $domainExists);

    if (!$domainExists['ok']) {
        json_response([
            'success' => false,
            'registration_allowed' => false,
            'error' => 'Nie udało się sprawdzić dostępności rejestracji'
        ], 500);
    }

    if (!empty($domainExists['data'])) {
        json_response([
            'success' => true,
            'registration_allowed' => false,
            'redirect_to' => '/logowanie.html',
            'message' => 'Dla tej domeny konto jest już utworzone.'
        ]);
    }

    json_response([
        'success' => true,
        'registration_allowed' => true,
        'message' => 'Rejestracja jest dostępna dla tej domeny.'
    ]);
}

if ($method !== 'POST') {
    register_security_event('auth_register_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    register_security_event('auth_register_invalid_json', 'invalid_json', 400, 'failed', 'low');
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], 400);
}

register_apply_antibot_guards($data);

$email = filter_var(trim((string)($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$password = (string)($data['password'] ?? '');
$passwordConfirm = (string)($data['password_confirm'] ?? '');
$termsAccepted = ($data['terms_accepted'] ?? null) === true;
$privacyAccepted = ($data['privacy_accepted'] ?? null) === true;
$clientName = trim((string)($data['client_name'] ?? ''));
$subdomainSlug = normalize_subdomain_slug($data['subdomain_slug'] ?? '');
$planCode = normalize_registration_plan_code($data['plan_code'] ?? 'free');
$billingPeriod = normalize_registration_billing_period($data['billing_period'] ?? 'monthly');
$selectedPaidPlanPrice = null;
$customDomainRequested = false;

if (array_key_exists('custom_domain_requested', $data)) {
    if (!is_bool($data['custom_domain_requested'])) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowa wartość żądania własnej domeny.'
        ], 400);
    }

    $customDomainRequested = $data['custom_domain_requested'];
}

$companyFullName = trim((string)($data['company_full_name'] ?? ''));
$companyOwnerName = trim((string)($data['company_owner_name'] ?? ''));
$companyTaxIdRaw = trim((string)($data['company_tax_id'] ?? ''));
$companyTaxId = normalize_digits($companyTaxIdRaw);

$companyAddress = trim((string)($data['company_address'] ?? ''));

$companyEmailRaw = trim((string)($data['company_email'] ?? ''));

$companyPhoneRaw = trim((string)($data['company_phone'] ?? ''));
$companyPhone = normalize_polish_phone($companyPhoneRaw);

$companyEmail = $companyEmailRaw !== ''
    ? filter_var($companyEmailRaw, FILTER_VALIDATE_EMAIL)
    : $email;

register_security_context([
    'email' => is_string($email) ? $email : '',
    'phone' => $companyPhone,
]);

if ($planCode === '') {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy plan rejestracji.'
    ], 400);
}

if (is_paid_public_registration_plan($planCode) && $billingPeriod === '') {
    json_response([
        'success' => false,
        'error' => 'Wybierz miesięczny albo roczny okres abonamentu.'
    ], 400);
}

if ($customDomainRequested && $planCode !== 'vip') {
    json_response([
        'success' => false,
        'error' => 'Żądanie własnej domeny jest dostępne wyłącznie dla planu VIP.'
    ], 400);
}

if (
    $customDomainRequested
    && !plan_features_public_registration_custom_domain_enabled($planCode)
) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się potwierdzić dostępności własnej domeny dla planu VIP. Spróbuj ponownie później.'
    ], 503);
}

if (!is_valid_subdomain_slug($subdomainSlug)) {
    json_response([
        'success' => false,
        'error' => 'Adres panelu musi mieć od 3 do 63 znaków i może zawierać tylko małe litery, cyfry oraz pojedyncze myślniki.'
    ], 400);
}

$domain = $subdomainSlug . '.' . $REGISTER_BASE_DOMAIN;

if (!$email || !is_valid_register_password($password)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy email lub hasło. Hasło musi mieć minimum 8 znaków, małą literę, dużą literę, cyfrę oraz znak specjalny.'
    ], 400);
}

if ($passwordConfirm === '' || !hash_equals($password, $passwordConfirm)) {
    json_response([
        'success' => false,
        'error' => 'Hasła muszą być identyczne.'
    ], 400);
}

if (!$termsAccepted || !$privacyAccepted) {
    json_response([
        'success' => false,
        'error' => 'Zaakceptuj Regulamin oraz Politykę prywatności.'
    ], 400);
}

if ($clientName === '') {
    json_response([
        'success' => false,
        'error' => 'Nazwa publiczna / marka jest wymagana'
    ], 400);
}

if (!is_valid_company_name($companyFullName)) {
    json_response(['success' => false, 'error' => 'Podaj poprawną pełną nazwę firmy.'], 400);
}

if (!is_valid_person_name($companyOwnerName)) {
    json_response(['success' => false, 'error' => 'Podaj poprawne imię i nazwisko osoby kontaktowej.'], 400);
}

if (!is_valid_polish_nip($companyTaxId)) {
    json_response(['success' => false, 'error' => 'Podaj poprawny NIP.'], 400);
}

if (!is_valid_company_address($companyAddress)) {
    json_response(['success' => false, 'error' => 'Podaj poprawny adres firmy: ulica, numer, miasto i kod pocztowy XX-XXX.'], 400);
}

if ($companyEmailRaw !== '' && !filter_var($companyEmailRaw, FILTER_VALIDATE_EMAIL)) {
    json_response(['success' => false, 'error' => 'Podaj poprawny e-mail firmy.'], 400);
}

if (!is_valid_polish_phone($companyPhone)) {
    json_response(['success' => false, 'error' => 'Podaj poprawny numer telefonu, np. 123456789 lub +48 123-456-789.'], 400);
}

if (is_paid_public_registration_plan($planCode)) {
    $selectedPaidPlanPrice = register_fetch_plan_price($planCode, $billingPeriod);

    if (!is_array($selectedPaidPlanPrice)) {
        json_response([
            'success' => false,
            'error' => 'Nie udało się pobrać aktualnej ceny wybranego planu. Spróbuj ponownie później.'
        ], 503);
    }
}

if ($domain === '') {
    json_response([
        'success' => false,
        'error' => 'Nie udało się przygotować adresu panelu.'
    ], 400);
}

if (!is_valid_domain($domain)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy adres panelu.'
    ], 400);
}

register_debug('CHECK_RESERVED_SUBDOMAIN_BEFORE');

$reservedSubdomain = supabase_request(
    'GET',
    '/rest/v1/reserved_subdomain_names?select=*'
);

register_debug_result('CHECK_RESERVED_SUBDOMAIN_AFTER', $reservedSubdomain);

if (!$reservedSubdomain['ok']) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się sprawdzić dostępności adresu panelu. Spróbuj ponownie.'
    ], 500);
}

if (is_reserved_subdomain_slug($reservedSubdomain['data'] ?? [], $subdomainSlug)) {
    register_security_event('auth_register_reserved_subdomain', 'reserved_subdomain', 409, 'blocked', 'medium');
    json_response([
        'success' => false,
        'error' => 'Ta nazwa adresu jest zarezerwowana. Wybierz inną.'
    ], 409);
}

register_debug('CHECK_EMAIL_EXISTS_BEFORE');

$emailExists = supabase_request(
    'GET',
    '/rest/v1/users?select=id,email&email=eq.' . rawurlencode($email) . '&limit=1'
);

register_debug_result('CHECK_EMAIL_EXISTS_AFTER', $emailExists);

if (!$emailExists['ok']) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się sprawdzić dostępności adresu e-mail. Spróbuj ponownie.'
    ], 500);
}

if ($emailExists['ok'] && !empty($emailExists['data'])) {
    register_security_event('auth_register_email_exists', 'email_exists', 409, 'blocked', 'medium');
    json_response([
        'success' => false,
        'error' => 'Email jest już zarejestrowany'
    ], 409);
}

register_debug('CHECK_DOMAIN_EXISTS_BEFORE');

$domainExists = supabase_request(
    'GET',
    '/rest/v1/tenant_domains?select=id,domain&domain=eq.' . rawurlencode($domain) . '&limit=1'
);

register_debug_result('CHECK_DOMAIN_EXISTS_AFTER', $domainExists);

if (!$domainExists['ok']) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się sprawdzić dostępności adresu panelu. Spróbuj ponownie.'
    ], 500);
}

if ($domainExists['ok'] && !empty($domainExists['data'])) {
    register_security_event('auth_register_domain_exists', 'domain_exists', 409, 'blocked', 'medium');
    json_response([
        'success' => false,
        'error' => 'Ten adres panelu jest już zajęty. Wybierz inną nazwę.'
    ], 409);
}

$createdBranding = false;
$createdUser = false;
$createdDomain = false;
$createdSubscription = false;
$createdServiceSettings = false;
$createdConsent = false;
$createdConsentId = '';
$createdActivationToken = false;
$createdSubscriptionPayment = false;

try {
    register_debug('GENERATE_IDS_BEFORE');

    $tenantId = gen_uuid_v4();
    register_security_context(['tenant_id' => $tenantId]);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $companyId = generate_unique_company_id();
    $clientNumber = generate_client_number();

    register_debug('GENERATE_IDS_AFTER', [
        'created' => true
    ]);

    // 1. GŁÓWNY REKORD FIRMY / TENANT
    $brandingPayload = [
        'tenant_id'     => $tenantId,
        'client_name'   => $clientName,
        'client_number' => $clientNumber,
        'admin_theme'   => 'gray',
        'company_id'    => $companyId,
        'created_at'    => date('c'),
        'reservations_style' => [
    'bg_color'     => '#e5ebf2',
    'card_color'   => '#f8fafc',
    'table_color'  => '#eef2f7',
    'header_color' => '#cbd5e1',
    'border_color' => '#94a3b8',
    'radius'       => '16'
],
'calendar_front_style' => [
    'bg_color'      => '#eef1f5',
    'card_color'    => '#ffffff',
    'cell_color'    => '#2c9641',
    'active_color'  => '#2563eb',
    'blocked_color' => '#cbd5e1',
    'border_color'  => '#cbd5e1',
    'text_color'    => '#1e293b',
    'radius'        => '16',
    'width'         => '600'
],
    ];

    register_debug('INSERT_TENANT_BRANDING_BEFORE');

    $brandingInsert = supabase_request('POST', '/rest/v1/tenant_branding', $brandingPayload);

    register_debug_result('INSERT_TENANT_BRANDING_AFTER', $brandingInsert);

    if (!$brandingInsert['ok']) {
        throw new Exception('Nie udało się utworzyć tenant_branding: ' . $brandingInsert['error']);
    }

    $createdBranding = true;

    // 2. ADMIN USER
    $userPayload = [
        'tenant_id'     => $tenantId,
        'email'         => $email,
        'password_hash' => $passwordHash,
        'role'          => 'administrator',
        'is_active'     => false,
        'created_at'    => date('c'),
    ];

    register_debug('INSERT_USER_BEFORE');

    $userInsert = supabase_request('POST', '/rest/v1/users', $userPayload);

    register_debug_result('INSERT_USER_AFTER', $userInsert);

    if (!$userInsert['ok']) {
        throw new Exception('Nie udało się utworzyć użytkownika: ' . $userInsert['error']);
    }

    $createdUser = true;
    $userId = extract_inserted_id($userInsert['data'] ?? null);

    if ($userId === '') {
        throw new Exception('Nie udało się ustalić identyfikatora utworzonego użytkownika');
    }

    register_security_context(['user_id' => $userId]);

    // 3. MAPOWANIE DOMENY -> TENANT
    $domainPayload = [
        'tenant_id'  => $tenantId,
        'domain'     => $domain,
        'is_primary' => true,
        'is_active'  => true,
        'created_at' => date('c'),
    ];

    register_debug('INSERT_DOMAIN_BEFORE');

    $domainInsert = supabase_request('POST', '/rest/v1/tenant_domains', $domainPayload);

    register_debug_result('INSERT_DOMAIN_AFTER', $domainInsert);

    if (!$domainInsert['ok']) {
        throw new Exception('Nie udało się utworzyć tenant_domains: ' . $domainInsert['error']);
    }

    $createdDomain = true;

    // 4. ABONAMENT
    $subscriptionPayload = is_paid_public_registration_plan($planCode)
        ? [
            'tenant_id' => $tenantId,
            'plan_code' => $planCode,
            'plan_name' => public_registration_plan_name($planCode),
            'billing_period' => $billingPeriod,
            'status' => 'payment_due',
            'amount' => (float) ($selectedPaidPlanPrice['amount'] ?? 0),
            'currency' => (string) ($selectedPaidPlanPrice['currency'] ?? 'PLN'),
            'current_period_start' => date('Y-m-d'),
            'current_period_end' => null,
            'next_payment_due_at' => date('Y-m-d'),
            'grace_period_days' => 0,
            'reminder_count' => 0,
            'notes' => 'Wpis abonamentu utworzony automatycznie przy bezpośredniej rejestracji planu płatnego. Aktywacja po płatności PayU.',
        ]
        : [
            'tenant_id' => $tenantId,
            'plan_code' => 'free',
            'plan_name' => 'Free',
            'billing_period' => 'monthly',
            'status' => 'active',
            'amount' => 0.00,
            'currency' => 'PLN',
            'current_period_start' => date('Y-m-d'),
            'current_period_end' => null,
            'next_payment_due_at' => null,
            'grace_period_days' => 0,
            'reminder_count' => 0,
            'notes' => 'Wpis abonamentu utworzony automatycznie przy rejestracji Free.',
        ];

    register_debug('INSERT_TENANT_SUBSCRIPTION_BEFORE');

    $subscriptionInsert = supabase_request(
        'POST',
        '/rest/v1/tenant_subscriptions',
        $subscriptionPayload
    );

    register_debug_result('INSERT_TENANT_SUBSCRIPTION_AFTER', $subscriptionInsert);

    if (!$subscriptionInsert['ok']) {
        throw new Exception('Nie udało się utworzyć tenant_subscriptions: ' . $subscriptionInsert['error']);
    }

    $createdSubscription = true;

    // 5. USTAWIENIA USŁUGI + DANE FIRMY / DANE DO FV
    $serviceSettingsPayload = [
        'tenant_id' => $tenantId,

        'company_full_name' => $companyFullName,
        'company_owner_name' => $companyOwnerName,
        'company_tax_id' => $companyTaxId,
        'company_address' => $companyAddress,
        'company_email' => $companyEmail,
        'company_phone' => $companyPhone,

        'service_name' => '',
        'service_description' => '',
        'price_amount' => 0,
        'price_currency' => 'PLN',

        'payment_required' => false,
        'payment_time_limit_value' => 48,
        'payment_time_limit_unit' => 'hours',
        'payment_message' => '',

        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];

    register_debug('INSERT_SERVICE_SETTINGS_BEFORE');

    $serviceSettingsInsert = supabase_request(
        'POST',
        '/rest/v1/tenant_service_settings',
        $serviceSettingsPayload
    );

    register_debug_result('INSERT_SERVICE_SETTINGS_AFTER', $serviceSettingsInsert);

    if (!$serviceSettingsInsert['ok']) {
        throw new Exception('Nie udało się utworzyć tenant_service_settings: ' . $serviceSettingsInsert['error']);
    }

    $createdServiceSettings = true;

    // 6. ZGODY REJESTRACYJNE
    $acceptedAt = date('c');
    $consentPayload = [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'terms_version' => 'platform_terms_v2',
        'privacy_version' => 'platform_privacy_v2',
        'terms_accepted_at' => $acceptedAt,
        'privacy_accepted_at' => $acceptedAt,
        'ip_address' => get_registration_ip_address(),
        'user_agent' => get_registration_user_agent(),
    ];

    $consentInsert = supabase_request(
        'POST',
        '/rest/v1/registration_consents',
        $consentPayload
    );

    if (!$consentInsert['ok']) {
        throw new Exception('Nie udało się zapisać zgód rejestracyjnych: ' . $consentInsert['error']);
    }

    $createdConsent = true;
    $createdConsentId = extract_inserted_id($consentInsert['data'] ?? null);

    if ($createdConsentId === '') {
        throw new Exception('Nie udało się ustalić identyfikatora zgody rejestracyjnej');
    }

    // 7. AKTYWACJA / PŁATNOŚĆ
    $paymentUrl = '';

    if (is_paid_public_registration_plan($planCode)) {
        $payment = register_create_initial_paid_plan_payment(
            $tenantId,
            $planCode,
            $billingPeriod,
            $selectedPaidPlanPrice,
            $customDomainRequested,
            [
                'email' => $email,
                'owner_name' => $companyOwnerName,
                'company_name' => $companyFullName !== '' ? $companyFullName : $clientName,
            ]
        );

        if (empty($payment['success']) || empty($payment['payment_url'])) {
            throw new Exception($payment['error'] ?? 'Nie udało się przygotować płatności PayU.');
        }

        $createdSubscriptionPayment = true;
        $paymentUrl = (string) $payment['payment_url'];
    } else {
        $activationToken = bin2hex(random_bytes(32));
        $activationTokenHash = hash('sha256', $activationToken);
        $activationCreatedAt = gmdate('c');
        $activationExpiresAt = gmdate('c', time() + (48 * 60 * 60));

        $activationTokenPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => $email,
            'token_hash' => $activationTokenHash,
            'expires_at' => $activationExpiresAt,
            'used_at' => null,
            'revoked_at' => null,
            'created_at' => $activationCreatedAt,
            'ip_address' => get_registration_ip_address(),
            'user_agent' => get_registration_user_agent(),
        ];

        $activationTokenInsert = supabase_request(
            'POST',
            '/rest/v1/user_activation_tokens',
            $activationTokenPayload
        );

        if (!$activationTokenInsert['ok']) {
            throw new Exception('Nie udało się przygotować aktywacji konta.');
        }

        $createdActivationToken = true;

        $activationRef = activation_link_build_ref($activationToken, $tenantId, $userId);

        if ($activationRef === '') {
            throw new Exception('Nie udało się przygotować odnośnika aktywacyjnego.');
        }

        $activationUrl = 'https://rezerwacja-ai-iq.pl/api/auth/activate.php?token=' . rawurlencode($activationToken)
            . '&ref=' . rawurlencode($activationRef);
        $registrationMailHtml = buildRegistrationConfirmationMailHtml([
            'company_name' => $companyFullName !== '' ? $companyFullName : $clientName,
            'plan' => 'Free',
            'panel_domain' => $domain,
            'activation_url' => $activationUrl,
            'activation_expires_label' => 'przez 48 godzin',
        ]);

        if (!sendSystemMail($email, 'Potwierdzenie rejestracji w RezerwIQ', $registrationMailHtml)) {
            throw new Exception('Nie udało się wysłać wiadomości aktywacyjnej.');
        }

        unset($activationToken, $activationRef, $activationUrl);
    }

    register_debug('SUCCESS', [
        'created' => true
    ]);

    $responsePayload = [
        'success' => true,
        'message' => is_paid_public_registration_plan($planCode)
            ? 'Rejestracja została przyjęta.'
            : 'Rejestracja została przyjęta. Sprawdź skrzynkę e-mail.',
    ];

    if (is_paid_public_registration_plan($planCode) && $paymentUrl !== '') {
        $responsePayload['payment_url'] = $paymentUrl;
    }

    register_security_event('auth_register_success', 'registration_accepted', 201, 'success', 'low', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'email' => is_string($email) ? $email : '',
        'phone' => $companyPhone,
        'stage' => is_paid_public_registration_plan($planCode) ? 'paid_plan_payment_created' : 'activation_mail_sent',
    ]);

    json_response($responsePayload, 201);

} catch (Throwable $e) {
    register_security_event('auth_register_failed', 'registration_failed', 500, 'failed', 'high', [
        'tenant_id' => (string) ($tenantId ?? ''),
        'user_id' => (string) ($userId ?? ''),
        'email' => is_string($email ?? '') ? (string) $email : '',
        'phone' => (string) ($companyPhone ?? ''),
        'stage' => 'exception',
    ]);

    register_debug('EXCEPTION', [
        'createdBranding' => $createdBranding,
        'createdUser' => $createdUser,
        'createdDomain' => $createdDomain,
        'createdSubscription' => $createdSubscription,
        'createdServiceSettings' => $createdServiceSettings,
        'createdActivationToken' => $createdActivationToken,
        'createdSubscriptionPayment' => $createdSubscriptionPayment,
        'exception_type' => get_class($e),
        'exception_message' => '[redacted]'
    ]);

    if (!empty($tenantId) && $createdSubscriptionPayment) {
        supabase_request('DELETE', '/rest/v1/tenant_subscription_payments?tenant_id=eq.' . rawurlencode($tenantId));
    }

    if (!empty($tenantId) && $createdActivationToken) {
        supabase_request('DELETE', '/rest/v1/user_activation_tokens?tenant_id=eq.' . rawurlencode($tenantId));
    }

    if (!empty($tenantId) && !empty($userId) && $createdConsent && $createdConsentId !== '') {
        $rollbackConsent = supabase_request(
            'DELETE',
            '/rest/v1/registration_consents?select=id&id=eq.' . rawurlencode($createdConsentId)
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&user_id=eq.' . rawurlencode($userId)
                . '&retention_until=is.null'
                . '&evidence_ended_at=is.null'
                . '&legal_hold=eq.false',
            null,
            false
        );
        register_debug_result('ROLLBACK_REGISTRATION_CONSENT', $rollbackConsent);

        $rollbackConsentData = $rollbackConsent['data'] ?? null;
        $rollbackConsentId = extract_inserted_id($rollbackConsentData);
        $rollbackConsentComplete = $rollbackConsent['ok']
            && is_array($rollbackConsentData)
            && count($rollbackConsentData) === 1
            && $rollbackConsentId !== ''
            && hash_equals($createdConsentId, $rollbackConsentId);

        if (!$rollbackConsentComplete) {
            register_debug('ROLLBACK_REGISTRATION_CONSENT_INCOMPLETE', [
                'success' => false,
                'reason' => $rollbackConsent['ok'] ? 'delete_not_confirmed' : 'delete_failed',
            ]);
            register_security_event(
                'auth_register_rollback_incomplete',
                $rollbackConsent['ok'] ? 'registration_consent_delete_not_confirmed' : 'registration_consent_delete_failed',
                500,
                'failed',
                'high',
                ['stage' => 'registration_consent_rollback']
            );
        }
    } elseif ($createdConsent) {
        register_debug('ROLLBACK_REGISTRATION_CONSENT_INCOMPLETE', [
            'success' => false,
            'reason' => 'missing_consent_identity',
        ]);
        register_security_event(
            'auth_register_rollback_incomplete',
            'registration_consent_identity_missing',
            500,
            'failed',
            'high',
            ['stage' => 'registration_consent_rollback']
        );
    }

    if (!empty($tenantId) && $createdServiceSettings) {
        $rollbackServiceSettings = supabase_request('DELETE', '/rest/v1/tenant_service_settings?tenant_id=eq.' . rawurlencode($tenantId));
        register_debug_result('ROLLBACK_SERVICE_SETTINGS', $rollbackServiceSettings);
    }

    if (!empty($tenantId) && $createdSubscription) {
        $rollbackSubscription = supabase_request('DELETE', '/rest/v1/tenant_subscriptions?tenant_id=eq.' . rawurlencode($tenantId));
        register_debug_result('ROLLBACK_TENANT_SUBSCRIPTION', $rollbackSubscription);
    }

    if (!empty($tenantId) && $createdDomain) {
        $rollbackDomain = supabase_request('DELETE', '/rest/v1/tenant_domains?tenant_id=eq.' . rawurlencode($tenantId));
        register_debug_result('ROLLBACK_DOMAIN', $rollbackDomain);
    }

    if (!empty($tenantId) && $createdUser) {
        $rollbackUser = supabase_request('DELETE', '/rest/v1/users?tenant_id=eq.' . rawurlencode($tenantId));
        register_debug_result('ROLLBACK_USER', $rollbackUser);
    }

    if (!empty($tenantId) && $createdBranding) {
        $rollbackBranding = supabase_request('DELETE', '/rest/v1/tenant_branding?tenant_id=eq.' . rawurlencode($tenantId));
        register_debug_result('ROLLBACK_BRANDING', $rollbackBranding);
    }

    json_response([
        'success' => false,
        'error'   => 'Nie udało się utworzyć konta. Spróbuj ponownie.'
    ], 500);
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function supabase_headers(bool $minimal = false): array
{
    global $SUPABASE_KEY, $SUPABASE_DB_SCHEMA;

    return [
        "apikey: {$SUPABASE_KEY}",
        "Authorization: Bearer {$SUPABASE_KEY}",
        'Content-Type: application/json',
        'Accept: application/json',
        "Accept-Profile: {$SUPABASE_DB_SCHEMA}",
        "Content-Profile: {$SUPABASE_DB_SCHEMA}",
        $minimal ? 'Prefer: return=minimal' : 'Prefer: return=representation',
    ];
}

function supabase_request(
    string $method,
    string $path,
    ?array $payload = null,
    ?bool $minimalResponse = null
): array
{
    global $SUPABASE_URL;

    $minimalResponse = $minimalResponse ?? ($method === 'DELETE');

    $ch = curl_init($SUPABASE_URL . $path);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => supabase_headers($minimalResponse),
        CURLOPT_TIMEOUT        => 20,
    ];

    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);

    return [
        'ok'       => !$curlError && $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'error'    => $curlError ?: ($decoded['message'] ?? $decoded['error'] ?? (string)$response),
        'data'     => $decoded,
        'raw'      => $response,
    ];
}

function gen_uuid_v4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

function extract_inserted_id($data): string
{
    if (!is_array($data)) {
        return '';
    }

    if (isset($data['id']) && is_scalar($data['id'])) {
        return trim((string) $data['id']);
    }

    if (isset($data[0]) && is_array($data[0]) && isset($data[0]['id']) && is_scalar($data[0]['id'])) {
        return trim((string) $data[0]['id']);
    }

    return '';
}

function get_registration_ip_address(): ?string
{
    return security_client_ip();
}

function get_registration_user_agent(): ?string
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($userAgent === '' || preg_match('/[\x00-\x1F\x7F]/', $userAgent)) {
        return null;
    }

    return $userAgent;
}

function generate_unique_company_id(): string
{
    register_debug('GENERATE_COMPANY_ID_START');

    for ($i = 0; $i < 30; $i++) {
        $companyId = (string) random_int(10000, 99999);

        register_debug('GENERATE_COMPANY_ID_TRY', [
            'attempt' => $i + 1
        ]);

        $check = supabase_request(
            'GET',
            '/rest/v1/tenant_branding?select=tenant_id,company_id&company_id=eq.' . rawurlencode($companyId) . '&limit=1'
        );

        register_debug_result('GENERATE_COMPANY_ID_CHECK', $check);

        if ($check['ok'] && empty($check['data'])) {
            register_debug('GENERATE_COMPANY_ID_SUCCESS', [
                'created' => true
            ]);
            return $companyId;
        }
    }

    throw new Exception('Nie udało się wygenerować unikalnego numeru firmy');
}

function generate_client_number(): string
{
    register_debug('GENERATE_CLIENT_NUMBER_START');

    for ($i = 0; $i < 30; $i++) {
        $clientNumber = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        register_debug('GENERATE_CLIENT_NUMBER_TRY', [
            'attempt' => $i + 1
        ]);

        $check = supabase_request(
            'GET',
            '/rest/v1/tenant_branding?select=client_number&client_number=eq.' . rawurlencode($clientNumber) . '&limit=1'
        );

        register_debug_result('GENERATE_CLIENT_NUMBER_CHECK', $check);

        if ($check['ok'] && empty($check['data'])) {
            register_debug('GENERATE_CLIENT_NUMBER_SUCCESS', [
                'created' => true
            ]);
            return $clientNumber;
        }
    }

    throw new Exception('Nie udało się wygenerować unikalnego numeru klienta');
}

function normalize_domain($domain): string
{
    $domain = strtolower(trim((string)$domain));

    if ($domain === '') {
        return '';
    }

    if (strpos($domain, ',') !== false) {
        $parts = array_map('trim', explode(',', $domain));
        $domain = $parts[0] ?? '';
    }

    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = preg_replace('/:\d+$/', '', $domain);

    return rtrim($domain, '.');
}

function is_valid_domain(string $domain): bool
{
    return (bool) preg_match(
        '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
        $domain
    );
}

function normalize_subdomain_slug($slug): string
{
    if (!is_string($slug)) {
        return '';
    }

    return strtolower(trim($slug));
}

function is_valid_subdomain_slug(string $slug): bool
{
    $length = strlen($slug);

    if ($length < 3 || $length > 63) {
        return false;
    }

    if (str_starts_with($slug, '-') || str_ends_with($slug, '-') || str_contains($slug, '--')) {
        return false;
    }

    return (bool) preg_match('/^[a-z0-9-]+$/', $slug);
}

function is_reserved_subdomain_slug($rows, string $slug): bool
{
    if (!is_array($rows)) {
        return false;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach ($row as $value) {
            if (!is_string($value)) {
                continue;
            }

            if (hash_equals($slug, strtolower(trim($value)))) {
                return true;
            }
        }
    }

    return false;
}


function check_subdomain_availability(string $slug): array
{
    global $REGISTER_BASE_DOMAIN;

    if (!is_valid_subdomain_slug($slug)) {
        return [
            'available' => false,
            'domain' => '',
            'message' => 'Nazwa musi mieć od 3 do 63 znaków i może zawierać tylko małe litery, cyfry oraz pojedyncze myślniki.'
        ];
    }

    $domain = $slug . '.' . $REGISTER_BASE_DOMAIN;

    if (!is_valid_domain($domain)) {
        return [
            'available' => false,
            'domain' => $domain,
            'message' => 'Nieprawidłowy adres panelu.'
        ];
    }

    $reservedSubdomain = supabase_request(
        'GET',
        '/rest/v1/reserved_subdomain_names?select=*'
    );

    if (!$reservedSubdomain['ok']) {
        return [
            'available' => false,
            'domain' => $domain,
            'message' => 'Nie udało się sprawdzić dostępności adresu. Spróbuj ponownie.'
        ];
    }

    if (is_reserved_subdomain_slug($reservedSubdomain['data'] ?? [], $slug)) {
        return [
            'available' => false,
            'domain' => $domain,
            'message' => 'Ta nazwa adresu jest zarezerwowana. Wybierz inną.'
        ];
    }

    $domainExists = supabase_request(
        'GET',
        '/rest/v1/tenant_domains?select=id,domain&domain=eq.' . rawurlencode($domain) . '&limit=1'
    );

    if (!$domainExists['ok']) {
        return [
            'available' => false,
            'domain' => $domain,
            'message' => 'Nie udało się sprawdzić dostępności adresu. Spróbuj ponownie.'
        ];
    }

    if (!empty($domainExists['data'])) {
        return [
            'available' => false,
            'domain' => $domain,
            'message' => 'Ten adres panelu jest już zajęty. Wybierz inną nazwę.'
        ];
    }

    return [
        'available' => true,
        'domain' => $domain,
        'message' => 'Adres ' . $domain . ' jest dostępny.'
    ];
}


function normalize_registration_billing_period($value): string
{
    $period = strtolower(trim((string) $value));
    return in_array($period, ['monthly', 'yearly'], true) ? $period : '';
}

function register_format_price_row(array $row, string $expectedPlanCode): ?array
{
    $period = strtolower(trim((string) ($row['billing_period'] ?? '')));
    $planCode = strtolower(trim((string) ($row['plan_code'] ?? '')));
    $amount = $row['amount'] ?? null;
    $currency = strtoupper(trim((string) ($row['currency'] ?? '')));

    if (
        !is_paid_public_registration_plan($expectedPlanCode)
        || $planCode !== $expectedPlanCode
        || !in_array($period, ['monthly', 'yearly'], true)
    ) {
        return null;
    }

    if (
        ($row['is_active'] ?? null) !== true
        || !is_numeric($amount)
        || (float) $amount <= 0
        || preg_match('/^[A-Z]{3}$/', $currency) !== 1
    ) {
        return null;
    }

    return [
        'plan_code' => $planCode,
        'plan_name' => trim((string) ($row['plan_name'] ?? '')) ?: public_registration_plan_name($planCode),
        'billing_period' => $period,
        'amount' => (float) $amount,
        'currency' => $currency,
        'is_active' => true,
    ];
}

function register_fetch_public_paid_plan_prices(string $planCode): array
{
    if (!is_public_registration_price_plan($planCode)) {
        return [
            'ok' => false,
            'error' => 'Nieprawidłowy plan cennika.',
            'prices' => [],
        ];
    }

    $result = supabase_request(
        'GET',
        '/rest/v1/subscription_plan_prices?select=plan_code,plan_name,billing_period,amount,currency,is_active&plan_code=eq.'
            . rawurlencode($planCode)
            . '&is_active=eq.true&billing_period=in.(monthly,yearly)&order=sort_order.asc,billing_period.asc'
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'error' => 'Cennik wybranego planu jest chwilowo niedostępny.',
            'prices' => [],
        ];
    }

    $prices = [];

    foreach (($result['data'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $price = register_format_price_row($row, $planCode);

        if ($price !== null) {
            $prices[] = $price;
        }
    }

    return [
        'ok' => count($prices) > 0,
        'error' => count($prices) > 0 ? '' : 'Brak aktywnej ceny wybranego planu.',
        'prices' => $prices,
    ];
}

function register_fetch_plan_price(string $planCode, string $billingPeriod): ?array
{
    if (
        !is_paid_public_registration_plan($planCode)
        || !in_array($billingPeriod, ['monthly', 'yearly'], true)
    ) {
        return null;
    }

    $result = supabase_request(
        'GET',
        '/rest/v1/subscription_plan_prices?select=plan_code,plan_name,billing_period,amount,currency,is_active&plan_code=eq.'
            . rawurlencode($planCode)
            . '&billing_period=eq.' . rawurlencode($billingPeriod)
            . '&is_active=eq.true&limit=1'
    );

    if (!$result['ok'] || !is_array($result['data'][0] ?? null)) {
        return null;
    }

    return register_format_price_row($result['data'][0], $planCode);
}

function register_public_base_url(): string
{
    $host = normalize_domain($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');

    if ($host === '' || !is_valid_domain($host)) {
        $host = 'rezerwacja-ai-iq.pl';
    }

    return 'https://' . $host;
}

function register_valid_email(string $email): string
{
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function register_create_initial_paid_plan_payment(
    string $tenantId,
    string $planCode,
    string $billingPeriod,
    ?array $price,
    bool $customDomainRequested,
    array $buyerContext
): array
{
    if (
        $tenantId === ''
        || !is_paid_public_registration_plan($planCode)
        || !in_array($billingPeriod, ['monthly', 'yearly'], true)
        || !is_array($price)
        || ($price['plan_code'] ?? '') !== $planCode
        || ($price['billing_period'] ?? '') !== $billingPeriod
        || ($customDomainRequested && $planCode !== 'vip')
    ) {
        return ['success' => false, 'error' => 'Nieprawidłowe dane płatności abonamentu.'];
    }

    $amount = (float) ($price['amount'] ?? 0);
    $currency = strtoupper(trim((string) ($price['currency'] ?? 'PLN')));

    if ($amount <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
        return ['success' => false, 'error' => 'Nieprawidłowa cena abonamentu.'];
    }

    $payuConfigResult = aiiq_payu_config();

    if (empty($payuConfigResult['success'])) {
        return ['success' => false, 'error' => 'Płatność PayU jest chwilowo niedostępna.'];
    }

    $payu = $payuConfigResult['config'];
    $payuCurrency = strtoupper(trim((string) ($payu['currency'] ?? '')));

    if ($currency !== $payuCurrency) {
        aiiq_payu_debug('AI_IQ_REGISTER_PAID_PLAN_CURRENCY_MISMATCH', [
            'plan_code' => $planCode,
            'price_currency' => $currency,
            'payu_currency' => $payuCurrency,
        ]);

        return ['success' => false, 'error' => 'Konfiguracja ceny abonamentu jest chwilowo niedostępna.'];
    }

    $now = gmdate('c');
    $paymentInsert = supabase_request('POST', '/rest/v1/tenant_subscription_payments', [
        'tenant_id' => $tenantId,
        'payment_type' => 'subscription_initial',
        'plan_code' => $planCode,
        'billing_period' => $billingPeriod,
        'amount' => $amount,
        'currency' => $currency,
        'custom_domain_requested' => $customDomainRequested,
        'status' => 'pending',
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if (!$paymentInsert['ok']) {
        return ['success' => false, 'error' => 'Nie udało się zapisać płatności abonamentu.'];
    }

    $paymentId = extract_inserted_id($paymentInsert['data'] ?? null);

    if ($paymentId === '') {
        return ['success' => false, 'error' => 'Nie udało się ustalić identyfikatora płatności abonamentu.'];
    }

    $timestamp = (string) time();
    $extOrderId = 'subscription-' . $timestamp . '-' . bin2hex(random_bytes(12));
    $amountInMinorUnits = (int) round($amount * 100);
    $periodLabel = $billingPeriod === 'yearly' ? 'roczny' : 'miesięczny';
    $planName = public_registration_plan_name($planCode);
    $description = 'RezerwIQ - rejestracja plan ' . $planName . ' ' . $periodLabel;
    $publicBaseUrl = register_public_base_url();
    $buyerEmail = register_valid_email((string) ($buyerContext['email'] ?? ''));

    if ($buyerEmail === '') {
        return ['success' => false, 'error' => 'Nie udało się ustalić adresu e-mail kupującego.'];
    }

    $orderPayload = [
        'notifyUrl' => $publicBaseUrl . '/api/subscriptions/payu-notify.php',
        'continueUrl' => $publicBaseUrl . '/platnosc-abonament-powrot.html',
        'customerIp' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'merchantPosId' => $payu['pos_id'],
        'description' => $description,
        'currencyCode' => $currency,
        'totalAmount' => (string) $amountInMinorUnits,
        'extOrderId' => $extOrderId,
        'buyer' => [
            'email' => $buyerEmail,
            'language' => 'pl',
        ],
        'products' => [[
            'name' => $description,
            'unitPrice' => (string) $amountInMinorUnits,
            'quantity' => '1',
        ]],
    ];

    $buyerName = trim((string) ($buyerContext['owner_name'] ?? ''));
    if ($buyerName !== '') {
        $orderPayload['buyer']['firstName'] = mb_substr($buyerName, 0, 80);
    }

    $created = aiiq_payu_create_order($payu, $orderPayload);

    if (empty($created['success'])) {
        supabase_request('PATCH', '/rest/v1/tenant_subscription_payments?id=eq.' . rawurlencode($paymentId) . '&tenant_id=eq.' . rawurlencode($tenantId), [
            'status' => 'failed',
            'payu_ext_order_id' => $extOrderId,
            'payu_status' => (string) ($created['payu_status'] ?? 'CREATE_ORDER_FAILED'),
            'updated_at' => gmdate('c'),
        ]);
        supabase_request('DELETE', '/rest/v1/tenant_subscription_payments?id=eq.' . rawurlencode($paymentId) . '&tenant_id=eq.' . rawurlencode($tenantId));

        return ['success' => false, 'error' => 'Nie udało się utworzyć płatności PayU za abonament.'];
    }

    $paymentUrl = (string) ($created['redirect_uri'] ?? '');
    $payuOrderId = (string) ($created['order_id'] ?? '');
    $payuStatus = (string) ($created['payu_status'] ?? '');

    if ($paymentUrl === '') {
        supabase_request('DELETE', '/rest/v1/tenant_subscription_payments?id=eq.' . rawurlencode($paymentId) . '&tenant_id=eq.' . rawurlencode($tenantId));
        return ['success' => false, 'error' => 'PayU nie zwróciło linku do płatności.'];
    }

    $paymentUpdate = supabase_request('PATCH', '/rest/v1/tenant_subscription_payments?id=eq.' . rawurlencode($paymentId) . '&tenant_id=eq.' . rawurlencode($tenantId), [
        'payu_order_id' => $payuOrderId,
        'payu_ext_order_id' => $extOrderId,
        'payment_url' => $paymentUrl,
        'payu_status' => $payuStatus,
        'updated_at' => gmdate('c'),
    ]);

    if (!$paymentUpdate['ok']) {
        supabase_request('DELETE', '/rest/v1/tenant_subscription_payments?id=eq.' . rawurlencode($paymentId) . '&tenant_id=eq.' . rawurlencode($tenantId));
        return ['success' => false, 'error' => 'Zamówienie PayU utworzone, ale nie udało się zapisać danych płatności.'];
    }

    register_store_subscription_return_handoff($tenantId, $paymentId);

    return [
        'success' => true,
        'payment_url' => $paymentUrl,
    ];
}

function normalize_registration_plan_code($value): string
{
    $planCode = strtolower(trim((string) $value));

    if ($planCode === '') {
        return 'free';
    }

    if (in_array($planCode, ['free', 'pro', 'vip'], true)) {
        return $planCode;
    }

    return '';
}

function is_paid_public_registration_plan(string $planCode): bool
{
    return in_array($planCode, ['pro', 'vip'], true);
}

function is_public_registration_price_plan(string $planCode): bool
{
    return in_array($planCode, ['pro', 'vip'], true);
}

function public_registration_plan_name(string $planCode): string
{
    return $planCode === 'vip' ? 'VIP' : 'Pro';
}

function normalize_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function normalize_polish_phone(string $value): string
{
    $digits = normalize_digits($value);

    if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
        $digits = substr($digits, 2);
    }

    return $digits;
}

function is_valid_polish_phone(string $phone): bool
{
    return (bool) preg_match('/^[1-9][0-9]{8}$/', $phone);
}

function is_valid_person_name(string $name): bool
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

    if (mb_strlen($name) < 5 || mb_strlen($name) > 120) {
        return false;
    }

    if (preg_match('/[0-9]/', $name)) {
        return false;
    }

    return (bool) preg_match('/^[\p{L}]+(?:[ -][\p{L}]+)+$/u', $name);
}

function is_valid_company_address(string $address): bool
{
    $address = trim(preg_replace('/\s+/', ' ', $address) ?? '');
    $parts = array_values(array_filter(array_map('trim', explode(',', $address))));

    if (mb_strlen($address) < 12 || mb_strlen($address) > 500) {
        return false;
    }

    if (count($parts) < 3) {
        return false;
    }

    $hasPostalCode = (bool) preg_match('/[0-9]{2}-[0-9]{3}/', $address);
    $hasStreetNumber = isset($parts[0]) && (bool) preg_match('/\p{L}{2,}.*[0-9]+|[0-9]+.*\p{L}{2,}/u', $parts[0]);

    $hasCity = false;

    foreach ($parts as $part) {
        if (preg_match('/[0-9]{2}-[0-9]{3}/', $part)) {
            continue;
        }

        if (preg_match('/^[\p{L}]+(?:[ -][\p{L}]+)*$/u', $part) && mb_strlen($part) >= 2) {
            $hasCity = true;
            break;
        }
    }

    return $hasPostalCode && $hasStreetNumber && $hasCity;
}

function is_valid_company_name(string $name): bool
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

    return mb_strlen($name) >= 2 && mb_strlen($name) <= 255;
}

function is_valid_polish_nip(string $nip): bool
{
    if (!preg_match('/^[0-9]{10}$/', $nip)) {
        return false;
    }

    $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;

    for ($i = 0; $i < 9; $i++) {
        $sum += ((int) $nip[$i]) * $weights[$i];
    }

    $checksum = $sum % 11;

    return $checksum !== 10 && $checksum === (int) $nip[9];
}

function is_valid_register_password(string $password): bool
{
    if (mb_strlen($password) < 8) {
        return false;
    }

    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }

    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return false;
    }

    return true;
}
