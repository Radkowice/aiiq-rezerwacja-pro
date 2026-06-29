<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/aiiq_payu.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function subscription_payu_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_payu_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function subscription_payu_request(string $method, string $url, array $headers, ?array $payload = null): array
{
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
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
        'ok' => $response !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $curlError ?: null,
        'data' => is_array($decoded) ? $decoded : null,
        'raw' => $response,
    ];
}

function subscription_payu_fetch_single(string $supabaseUrl, array $headers, string $table, string $query): ?array
{
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . rawurlencode($table)
        . '?' . $query
        . '&limit=1';

    $result = subscription_payu_request('GET', $url, $headers);

    if (!$result['ok']) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_FETCH_ERROR', [
            'table' => $table,
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null,
        ]);

        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_insert_payment(string $supabaseUrl, array $headers, array $payload): ?array
{
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/tenant_subscription_payments';
    $insertHeaders = $headers;
    $insertHeaders[] = 'Prefer: return=representation';
    $result = subscription_payu_request('POST', $url, $insertHeaders, $payload);

    if (!$result['ok']) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYMENT_INSERT_ERROR', [
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null,
        ]);

        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_update_payment(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $paymentId,
    array $payload
): bool {
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/tenant_subscription_payments'
        . '?id=eq.' . rawurlencode($paymentId)
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = subscription_payu_request('PATCH', $url, $headers, $payload);

    if ($result['ok']) {
        return true;
    }

    if (array_key_exists('payu_status', $payload)) {
        $retryPayload = $payload;
        unset($retryPayload['payu_status']);

        $retry = subscription_payu_request('PATCH', $url, $headers, $retryPayload);

        if ($retry['ok']) {
            aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYMENT_UPDATE_RETRIED_WITHOUT_PAYU_STATUS', [
                'http_code' => $result['http_code'],
            ]);

            return true;
        }
    }

    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYMENT_UPDATE_ERROR', [
        'http_code' => $result['http_code'],
        'has_error' => $result['error'] !== null,
    ]);

    return false;
}

function subscription_payu_extract_id(?array $row): string
{
    if (!is_array($row)) {
        return '';
    }

    $id = $row['id'] ?? '';

    return is_scalar($id) ? trim((string) $id) : '';
}

function subscription_payu_public_base_url(): string
{
    $scheme = 'https';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0] ?? ''));

        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
        }
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? '';

    $host = trim(explode(',', (string) $host)[0] ?? '');

    if ($host === '' || !preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
        return '';
    }

    return $scheme . '://' . $host;
}

function subscription_payu_normalize_plan_code(?string $planCode): string
{
    $planCode = strtolower(trim((string) $planCode));

    return $planCode === 'biznes' ? 'business' : $planCode;
}

function subscription_payu_valid_email(?string $email): string
{
    $email = trim((string) $email);

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function subscription_payu_split_buyer_name(?string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', (string) $name));

    if ($name === '') {
        return [
            'firstName' => '',
            'lastName' => '',
        ];
    }

    $parts = explode(' ', $name);
    $firstName = trim((string) array_shift($parts));
    $lastName = trim(implode(' ', $parts));

    return [
        'firstName' => $firstName,
        'lastName' => $lastName,
    ];
}

function subscription_payu_build_buyer(?string $email, ?string $ownerName): array
{
    return [
        'email' => subscription_payu_valid_email($email),
        'language' => 'pl',
    ];
}


function subscription_payu_store_return_handoff(string $tenantId, string $paymentId): void
{
    $_SESSION['subscription_payment_return_handoff'] = [
        'tenant_id' => $tenantId,
        'payment_id' => $paymentId,
        'created_at' => time(),
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        subscription_payu_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
        subscription_payu_json(401, [
            'success' => false,
            'error' => 'Brak autoryzacji.',
        ]);
    }

    $userId = (string) $_SESSION['user']['id'];
    $tenantId = (string) $_SESSION['user']['tenant_id'];
    $sessionRole = strtolower(trim((string) ($_SESSION['user']['role'] ?? '')));

    if (!in_array($sessionRole, ['admin', 'administrator'], true)) {
        subscription_payu_json(403, [
            'success' => false,
            'error' => 'Brak uprawnień administratora.',
        ]);
    }

    $input = subscription_payu_input();
    $billingPeriod = strtolower(trim((string) ($input['billing_period'] ?? '')));
    $paymentType = strtolower(trim((string) ($input['payment_type'] ?? '')));

    if (!in_array($billingPeriod, ['monthly', 'yearly'], true)) {
        subscription_payu_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy okres rozliczeniowy.',
        ]);
    }

    if (!in_array($paymentType, ['subscription_upgrade', 'subscription_renewal'], true)) {
        subscription_payu_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy typ płatności abonamentu.',
        ]);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_payu_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji Supabase.',
        ]);
    }

    if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
        subscription_payu_json(401, [
            'success' => false,
            'error' => 'Sesja nie pasuje do domeny.',
        ]);
    }

    $headers = supabaseHeaders($supabaseKey, $schema);
    $headers[] = 'Content-Type: application/json';

    $user = subscription_payu_fetch_single(
        $supabaseUrl,
        $headers,
        'users',
        'select=id,email,tenant_id,role,is_active'
            . '&id=eq.' . rawurlencode($userId)
            . '&tenant_id=eq.' . rawurlencode($tenantId)
    );

    if (!is_array($user)) {
        subscription_payu_json(401, [
            'success' => false,
            'error' => 'Konto administratora jest nieaktywne albo nie istnieje.',
        ]);
    }

    $userIsActive = filter_var($user['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$userIsActive) {
        subscription_payu_json(401, [
            'success' => false,
            'error' => 'Konto administratora jest nieaktywne albo nie istnieje.',
        ]);
    }

    $dbRole = strtolower(trim((string) ($user['role'] ?? $sessionRole)));

    if (!in_array($dbRole, ['admin', 'administrator'], true)) {
        subscription_payu_json(403, [
            'success' => false,
            'error' => 'Brak uprawnień administratora.',
        ]);
    }

    $companySettings = subscription_payu_fetch_single(
        $supabaseUrl,
        $headers,
        'tenant_service_settings',
        'select=company_full_name,company_owner_name,company_email'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
    );

    $subscription = subscription_payu_fetch_single(
        $supabaseUrl,
        $headers,
        'tenant_subscriptions',
        'select=tenant_id,plan_code,status,billing_period,current_period_end'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
    );

    if (!is_array($subscription)) {
        subscription_payu_json(404, [
            'success' => false,
            'error' => 'Nie znaleziono abonamentu klienta.',
        ]);
    }

    $currentPlanCode = subscription_payu_normalize_plan_code((string) ($subscription['plan_code'] ?? 'free'));

    if ($paymentType === 'subscription_upgrade' && $currentPlanCode !== 'free') {
        subscription_payu_json(409, [
            'success' => false,
            'error' => 'Upgrade do planu Pro jest dostępny tylko z planu Free.',
        ]);
    }

    if ($paymentType === 'subscription_renewal' && $currentPlanCode !== 'pro') {
        subscription_payu_json(409, [
            'success' => false,
            'error' => 'Przedłużenie jest dostępne tylko dla planu Pro.',
        ]);
    }

    $price = subscription_payu_fetch_single(
        $supabaseUrl,
        $headers,
        'subscription_plan_prices',
        'select=plan_code,plan_name,billing_period,amount,currency,is_active'
            . '&plan_code=eq.pro'
            . '&billing_period=eq.' . rawurlencode($billingPeriod)
            . '&is_active=eq.true'
    );

    if (!is_array($price) || !isset($price['amount']) || $price['amount'] === null || $price['amount'] === '') {
        subscription_payu_json(503, [
            'success' => false,
            'error' => 'Nie udało się pobrać aktualnej ceny planu Pro. Spróbuj ponownie później.',
        ]);
    }

    $amount = (float) $price['amount'];

    if ($amount <= 0) {
        subscription_payu_json(503, [
            'success' => false,
            'error' => 'Nie udało się pobrać aktualnej ceny planu Pro. Spróbuj ponownie później.',
        ]);
    }

    $payuConfigResult = aiiq_payu_config();

    if (empty($payuConfigResult['success'])) {
        subscription_payu_json(503, [
            'success' => false,
            'error' => 'Płatność za plan Pro jest chwilowo niedostępna. Spróbuj ponownie później.',
        ]);
    }

    $payu = $payuConfigResult['config'];
    $currency = strtoupper(trim((string) ($price['currency'] ?? '')));

    if ($currency === '') {
        $currency = (string) $payu['currency'];
    }

    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        subscription_payu_json(503, [
            'success' => false,
            'error' => 'Nie udało się pobrać aktualnej ceny planu Pro. Spróbuj ponownie później.',
        ]);
    }

    $payuCurrency = strtoupper(trim((string) ($payu['currency'] ?? '')));

    if ($currency !== $payuCurrency) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_CURRENCY_MISMATCH', [
            'price_currency' => $currency,
            'payu_currency' => $payuCurrency,
        ]);

        subscription_payu_json(503, [
            'success' => false,
            'error' => 'Konfiguracja ceny planu Pro jest chwilowo niedostępna. Spróbuj ponownie później.',
        ]);
    }

    $publicBaseUrl = subscription_payu_public_base_url();

    if ($publicBaseUrl === '') {
        subscription_payu_json(500, [
            'success' => false,
            'error' => 'Nie udało się ustalić publicznego adresu aplikacji.',
        ]);
    }

    $buyerEmail = subscription_payu_valid_email((string) ($_SESSION['user']['email'] ?? ''));

    if ($buyerEmail === '') {
        $buyerEmail = subscription_payu_valid_email((string) ($user['email'] ?? ''));
    }

    if ($buyerEmail === '') {
        $buyerEmail = subscription_payu_valid_email((string) ($companySettings['company_email'] ?? ''));
    }

    if ($buyerEmail === '') {
        subscription_payu_json(422, [
            'success' => false,
            'error' => 'Nie udało się ustalić adresu e-mail kupującego. Uzupełnij dane administratora albo e-mail firmowy i spróbuj ponownie.',
        ]);
    }

    $buyer = subscription_payu_build_buyer($buyerEmail, (string) ($companySettings['company_owner_name'] ?? ''));
    $now = gmdate('c');
    $paymentRow = subscription_payu_insert_payment($supabaseUrl, $headers, [
        'tenant_id' => $tenantId,
        'payment_type' => $paymentType,
        'plan_code' => 'pro',
        'billing_period' => $billingPeriod,
        'amount' => $amount,
        'currency' => $currency,
        'status' => 'pending',
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $paymentId = subscription_payu_extract_id($paymentRow);

    if ($paymentId === '') {
        subscription_payu_json(500, [
            'success' => false,
            'error' => 'Nie udało się przygotować płatności abonamentu.',
        ]);
    }

    $timestamp = (string) time();
    $extOrderId = 'subscription-' . $timestamp . '-' . bin2hex(random_bytes(12));
    $amountInMinorUnits = (int) round($amount * 100);
    $periodLabel = $billingPeriod === 'yearly' ? 'roczny' : 'miesieczny';
    $description = 'AI-IQ Rezerwacja Pro - plan Pro ' . $periodLabel;

    $orderPayload = [
        'notifyUrl' => $publicBaseUrl . '/api/subscriptions/payu-notify.php',
        'continueUrl' => $publicBaseUrl . '/platnosc-abonament-powrot.html',
        'customerIp' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'merchantPosId' => $payu['pos_id'],
        'description' => $description,
        'currencyCode' => $currency,
        'totalAmount' => (string) $amountInMinorUnits,
        'extOrderId' => $extOrderId,
        'buyer' => $buyer,
        'products' => [
            [
                'name' => $description,
                'unitPrice' => (string) $amountInMinorUnits,
                'quantity' => '1',
            ],
        ],
    ];

    $created = aiiq_payu_create_order($payu, $orderPayload);

    if (empty($created['success'])) {
        subscription_payu_update_payment($supabaseUrl, $headers, $tenantId, $paymentId, [
            'status' => 'failed',
            'payu_ext_order_id' => $extOrderId,
            'payu_status' => (string) ($created['payu_status'] ?? 'CREATE_ORDER_FAILED'),
            'updated_at' => gmdate('c'),
        ]);

        subscription_payu_json(500, [
            'success' => false,
            'error' => 'Nie udało się utworzyć płatności PayU za plan Pro.',
        ]);
    }

    $paymentUrl = (string) ($created['redirect_uri'] ?? '');
    $payuOrderId = (string) ($created['order_id'] ?? '');
    $payuStatus = (string) ($created['payu_status'] ?? '');

    if ($paymentUrl === '') {
        subscription_payu_update_payment($supabaseUrl, $headers, $tenantId, $paymentId, [
            'status' => 'failed',
            'payu_order_id' => $payuOrderId,
            'payu_ext_order_id' => $extOrderId,
            'payu_status' => $payuStatus !== '' ? $payuStatus : 'REDIRECT_URI_MISSING',
            'updated_at' => gmdate('c'),
        ]);

        subscription_payu_json(500, [
            'success' => false,
            'error' => 'PayU nie zwróciło linku do płatności.',
        ]);
    }

    $updated = subscription_payu_update_payment($supabaseUrl, $headers, $tenantId, $paymentId, [
        'payu_order_id' => $payuOrderId,
        'payu_ext_order_id' => $extOrderId,
        'payment_url' => $paymentUrl,
        'payu_status' => $payuStatus,
        'updated_at' => gmdate('c'),
    ]);

    if (!$updated) {
        subscription_payu_json(500, [
            'success' => false,
            'error' => 'Zamówienie PayU utworzone, ale nie udało się zapisać danych płatności abonamentu.',
        ]);
    }

    subscription_payu_store_return_handoff($tenantId, $paymentId);

    $responsePayload = [
        'success' => true,
        'payment_url' => $paymentUrl,
        'amount' => $amount,
        'currency' => $currency,
        'billing_period' => $billingPeriod,
    ];

    subscription_payu_json(200, $responsePayload);
} catch (Throwable $e) {
    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_CREATE_ORDER_FATAL', [
        'exception_type' => get_class($e),
    ]);

    subscription_payu_json(500, [
        'success' => false,
        'error' => 'Błąd tworzenia płatności abonamentu.',
    ]);
}
