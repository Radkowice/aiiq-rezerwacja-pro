<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

function register_debug($label, $data = null): void
{
    $line = date('c') . " [$label]";
    if ($data !== null) {
        $line .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;

    @file_put_contents('/var/www/data/register-debug.log', $line, FILE_APPEND);
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

register_debug('START');

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
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
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], 400);
}

$email = filter_var(trim((string)($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$password = (string)($data['password'] ?? '');
$clientName = trim((string)($data['client_name'] ?? ''));

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

// DOMAIN TYLKO Z REQUESTU, NIE Z FORMULARZA
$domain = normalize_domain(
    $_SERVER['HTTP_X_FORWARDED_HOST']
    ?? $_SERVER['HTTP_HOST']
    ?? ''
);

if (!$email || !is_valid_register_password($password)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy email lub hasło. Hasło musi mieć minimum 8 znaków, małą literę, dużą literę, cyfrę oraz znak specjalny.'
    ], 400);
}

if ($clientName === '') {
    json_response([
        'success' => false,
        'error' => 'Nazwa publiczna / marka jest wymagana'
    ], 400);
}

if (!is_valid_company_name($companyFullName)) {
    send_json(false, 'Podaj poprawną pełną nazwę firmy.');
}

if (!is_valid_person_name($companyOwnerName)) {
    send_json(false, 'Podaj poprawne imię i nazwisko osoby kontaktowej.');
}

if (!is_valid_polish_nip($companyTaxId)) {
    send_json(false, 'Podaj poprawny NIP.');
}

if (!is_valid_company_address($companyAddress)) {
    send_json(false, 'Podaj poprawny adres firmy: ulica, numer, miasto i kod pocztowy XX-XXX.');
}

if ($companyEmailRaw !== '' && !filter_var($companyEmailRaw, FILTER_VALIDATE_EMAIL)) {
    send_json(false, 'Podaj poprawny e-mail firmy.');
}

if (!is_valid_polish_phone($companyPhone)) {
    send_json(false, 'Podaj poprawny numer telefonu, np. 123456789 lub +48 123-456-789.');
}

if ($domain === '') {
    json_response([
        'success' => false,
        'error' => 'Nie udało się ustalić domeny rejestracji'
    ], 400);
}

if (!is_valid_domain($domain)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowa domena systemowa'
    ], 400);
}

register_debug('CHECK_EMAIL_EXISTS_BEFORE');

$emailExists = supabase_request(
    'GET',
    '/rest/v1/users?select=id,email&email=eq.' . rawurlencode($email) . '&limit=1'
);

register_debug_result('CHECK_EMAIL_EXISTS_AFTER', $emailExists);

if ($emailExists['ok'] && !empty($emailExists['data'])) {
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

if ($domainExists['ok'] && !empty($domainExists['data'])) {
    json_response([
        'success' => false,
        'error' => 'Ta domena jest już przypisana do innego klienta'
    ], 409);
}

$createdBranding = false;
$createdUser = false;
$createdDomain = false;
$createdServiceSettings = false;

try {
    register_debug('GENERATE_IDS_BEFORE');

    $tenantId = gen_uuid_v4();
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
    'bg_color'     => '#eef1f5',
    'card_color'   => '#ffffff',
    'table_color'  => '#f8fafc',
    'header_color' => '#e2e8f0',
    'border_color' => '#cbd5e1',
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
        'is_active'     => true,
        'created_at'    => date('c'),
    ];

    register_debug('INSERT_USER_BEFORE');

    $userInsert = supabase_request('POST', '/rest/v1/users', $userPayload);

    register_debug_result('INSERT_USER_AFTER', $userInsert);

    if (!$userInsert['ok']) {
        throw new Exception('Nie udało się utworzyć użytkownika: ' . $userInsert['error']);
    }

    $createdUser = true;

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

    // 4. USTAWIENIA USŁUGI + DANE FIRMY / DANE DO FV
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

    register_debug('SUCCESS', [
        'created' => true
    ]);

    json_response([
        'success'       => true,
        'message'       => 'Konto zostało utworzone',
        'tenant_id'     => $tenantId,
        'company_id'    => $companyId,
        'client_number' => $clientNumber,
        'domain'        => $domain
    ], 201);

} catch (Throwable $e) {
    register_debug('EXCEPTION', [
        'createdBranding' => $createdBranding,
        'createdUser' => $createdUser,
        'createdDomain' => $createdDomain,
        'createdServiceSettings' => $createdServiceSettings
    ]);

    if (!empty($tenantId) && $createdServiceSettings) {
        $rollbackServiceSettings = supabase_request('DELETE', '/rest/v1/tenant_service_settings?tenant_id=eq.' . rawurlencode($tenantId));
        register_debug_result('ROLLBACK_SERVICE_SETTINGS', $rollbackServiceSettings);
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
        'error'   => $e->getMessage()
    ], 500);
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function supabase_request(string $method, string $path, ?array $payload = null): array
{
    global $SUPABASE_URL;

    $ch = curl_init($SUPABASE_URL . $path);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => supabase_headers($method === 'DELETE'),
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
