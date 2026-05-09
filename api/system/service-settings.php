<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();
if (!isset($_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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
if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendServiceSettingsJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeServiceText($value, int $maxLength = 500): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return $value;
    }

    if (strlen($value) > $maxLength) {
        return substr($value, 0, $maxLength);
    }

    return $value;
}

function requireServiceText(array $input, string $key, string $label, int $maxLength = 500): string
{
    $value = normalizeServiceText($input[$key] ?? null, $maxLength);

    if ($value === null) {
        sendServiceSettingsJson([
            'success' => false,
            'error' => 'Uzupełnij wymagane dane firmy',
            'field' => $key,
            'label' => $label
        ], 422);
    }

    return $value;
}

function validateCompanyEmail(string $email): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendServiceSettingsJson([
            'success' => false,
            'error' => 'Podaj poprawny adres e-mail firmy',
            'field' => 'company_email',
            'label' => 'E-mail firmy'
        ], 422);
    }
}

function validateCompanyContactPhone(string $phone): void
{
    if ($phone === '' || !preg_match('/^[0-9\s+\-()]+$/', $phone)) {
        sendServiceSettingsJson([
            'success' => false,
            'error' => 'Telefon firmowy może zawierać tylko cyfry, spacje, plus, minus i nawiasy',
            'field' => 'company_phone',
            'label' => 'Telefon firmowy'
        ], 422);
    }
}

function serviceSettingsSupabaseRequest(
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

if ($method === 'GET') {
    $url = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?tenant_id=eq.' . urlencode($tenantId)
        . '&select=*'
        . '&limit=1';

    $result = serviceSettingsSupabaseRequest(
        'GET',
        $url,
        $serviceRoleKey,
        $schema
    );

    if (!$result['ok']) {
        sendServiceSettingsJson([
            'success' => false,
            'error' => 'Nie udało się pobrać ustawień usługi',
        ], 500);
    }

    $settings = $result['json'][0] ?? null;

    sendServiceSettingsJson([
        'success' => true,
        'settings' => $settings,
    ]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        sendServiceSettingsJson([
            'success' => false,
            'error' => 'Brak danych wejściowych'
        ], 400);
    }
    
    
    $section = (string) ($input['section'] ?? '');

    if ($section === 'company_contact') {
        $forbiddenFields = [
            'client_name',
            'company_full_name',
            'company_owner_name',
            'company_tax_id',
            'client_number',
            'company_id',
            'tenant_id',
            'user_email',
            'user_role',
            'email',
            'role',
        ];

        foreach ($forbiddenFields as $field) {
            if (array_key_exists($field, $input)) {
                sendServiceSettingsJson([
                    'success' => false,
                    'error' => 'Tego pola nie można zapisać w zakładce Informacje',
                    'field' => $field,
                ], 400);
            }
        }

        $companyAddress = requireServiceText($input, 'company_address', 'Adres firmy', 500);
        $companyEmail = requireServiceText($input, 'company_email', 'E-mail firmy', 255);
        $companyPhone = requireServiceText($input, 'company_phone', 'Telefon firmowy', 50);

        validateCompanyEmail($companyEmail);
        validateCompanyContactPhone($companyPhone);

        $payload = [[
            'tenant_id' => $tenantId,
            'company_address' => $companyAddress,
            'company_email' => $companyEmail,
            'company_phone' => $companyPhone,
            'updated_at' => gmdate('c'),
        ]];

        $url = $supabaseUrl
            . '/rest/v1/tenant_service_settings'
            . '?on_conflict=tenant_id';

        $result = serviceSettingsSupabaseRequest(
            'POST',
            $url,
            $serviceRoleKey,
            $schema,
            $payload
        );

        if (!$result['ok']) {
            sendServiceSettingsJson([
                'success' => false,
                'error' => 'Nie udało się zapisać danych firmy',
            ], 500);
        }

        $saved = $result['json'][0] ?? $payload[0];

        sendServiceSettingsJson([
            'success' => true,
            'message' => 'Dane firmy zostały zapisane.',
            'settings' => $saved,
        ]);
    }
$isCompanyInfoSave = array_key_exists('company_full_name', $input)
    || array_key_exists('company_owner_name', $input)
    || array_key_exists('company_tax_id', $input)
    || array_key_exists('company_address', $input)
    || array_key_exists('company_email', $input)
    || array_key_exists('company_phone', $input);

$companyPayload = [];

if ($isCompanyInfoSave) {
    $companyFullName = requireServiceText($input, 'company_full_name', 'Pełna nazwa firmy', 255);
    $companyOwnerName = requireServiceText($input, 'company_owner_name', 'Imię i nazwisko', 150);
    $companyTaxId = requireServiceText($input, 'company_tax_id', 'NIP', 50);
    $companyAddress = requireServiceText($input, 'company_address', 'Adres firmy', 500);
    $companyEmail = requireServiceText($input, 'company_email', 'E-mail firmy', 255);
    $companyPhone = requireServiceText($input, 'company_phone', 'Telefon firmy', 50);

    validateCompanyEmail($companyEmail);

  }

    $priceAmountRaw = str_replace(',', '.', (string) ($input['price_amount'] ?? '0'));
    $priceAmount = is_numeric($priceAmountRaw) ? (float) $priceAmountRaw : 0.0;

    if ($priceAmount < 0) {
        sendServiceSettingsJson([
            'success' => false,
            'error' => 'Cena nie może być ujemna.'
        ], 422);
    }

    $paymentTimeLimitValue = (int) ($input['payment_time_limit_value'] ?? 48);

    if ($paymentTimeLimitValue <= 0) {
        $paymentTimeLimitValue = 48;
    }

    $paymentTimeLimitUnit = trim((string) ($input['payment_time_limit_unit'] ?? 'hours'));

    if (!in_array($paymentTimeLimitUnit, ['hours', 'days'], true)) {
        $paymentTimeLimitUnit = 'hours';
    }

    $priceCurrency = trim((string) ($input['price_currency'] ?? 'PLN'));

    if ($priceCurrency !== 'PLN') {
        $priceCurrency = 'PLN';
    }
$payload = [[
    'tenant_id' => $tenantId,

    'service_name' => normalizeServiceText($input['service_name'] ?? null, 255),
    'service_description' => normalizeServiceText($input['service_description'] ?? null, 1500),
    'price_amount' => $priceAmount,
    'price_currency' => $priceCurrency,

    'payment_required' => !empty($input['payment_required']),
    'payment_time_limit_value' => $paymentTimeLimitValue,
    'payment_time_limit_unit' => $paymentTimeLimitUnit,
    'payment_message' => normalizeServiceText($input['payment_message'] ?? null, 1500),

    'updated_at' => gmdate('c'),
]];

$payload[0] = array_merge($payload[0], $companyPayload);

    $url = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?on_conflict=tenant_id';

    $result = serviceSettingsSupabaseRequest(
        'POST',
        $url,
        $serviceRoleKey,
        $schema,
        $payload
    );

    if (!$result['ok']) {
        sendServiceSettingsJson([
            'success' => false,
            'error' => 'Nie udało się zapisać ustawień usługi',
        ], 500);
    }

    $saved = $result['json'][0] ?? $payload[0];

    sendServiceSettingsJson([
        'success' => true,
        'message' => 'Ustawienia usługi zostały zapisane.',
        'settings' => $saved,
    ]);
}

sendServiceSettingsJson([
    'success' => false,
    'error' => 'Metoda niedozwolona'
], 405);
