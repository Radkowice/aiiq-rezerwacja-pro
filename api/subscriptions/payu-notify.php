<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/aiiq_payu.php';

function subscription_payu_notify_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_payu_notify_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function subscription_payu_notify_parse_signature(string $header): array
{
    $result = [];

    foreach (explode(';', $header) as $part) {
        $part = trim($part);

        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $part, 2);
        $key = strtolower(trim($key));
        $value = trim($value);

        if ($key !== '') {
            $result[$key] = $value;
        }
    }

    return $result;
}

function subscription_payu_notify_verify_signature(string $rawBody, string $secondKey, string $signatureHeader): bool
{
    if ($rawBody === '' || $secondKey === '' || $signatureHeader === '') {
        return false;
    }

    $signature = subscription_payu_notify_parse_signature($signatureHeader);
    $incomingSignature = strtolower((string) ($signature['signature'] ?? ''));
    $algorithm = strtolower((string) ($signature['algorithm'] ?? 'md5'));

    if ($incomingSignature === '' || $algorithm !== 'md5') {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SIGNATURE_UNSUPPORTED', [
            'algorithm' => $algorithm,
            'signature_set' => $incomingSignature !== '',
        ]);

        return false;
    }

    $expectedSignature = md5($rawBody . $secondKey);

    return hash_equals($expectedSignature, $incomingSignature);
}

function subscription_payu_notify_request(string $method, string $url, array $headers, ?array $payload = null): array
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

    return [
        'ok' => $response !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $curlError ?: null,
        'data' => json_decode((string) $response, true),
        'raw' => $response,
    ];
}

function subscription_payu_notify_safe_payload(array $payload): array
{
    $blockedKeys = [
        'authorization',
        'access_token',
        'client_secret',
        'second_key',
        'token',
        'AI_IQ_PAYU_CLIENT_SECRET',
        'AI_IQ_PAYU_SECOND_KEY',
        'payment_url',
        'paymentUrl',
        'redirectUri',
    ];

    foreach ($payload as $key => $value) {
        if (in_array((string) $key, $blockedKeys, true)) {
            unset($payload[$key]);
            continue;
        }

        if (is_array($value)) {
            $payload[$key] = subscription_payu_notify_safe_payload($value);
        }
    }

    return $payload;
}

function subscription_payu_notify_find_payment(
    string $supabaseUrl,
    array $headers,
    string $orderId,
    string $extOrderId
): ?array {
    $filters = [];

    if ($orderId !== '') {
        $filters[] = 'payu_order_id.eq.' . rawurlencode($orderId);
        $filters[] = 'payu_ext_order_id.eq.' . rawurlencode($orderId);
    }

    if ($extOrderId !== '') {
        $filters[] = 'payu_ext_order_id.eq.' . rawurlencode($extOrderId);
    }

    $filters = array_values(array_unique($filters));

    if (!$filters) {
        return null;
    }

    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscription_payments'
        . '?select=id,tenant_id,payment_type,plan_code,billing_period,amount,currency,status,payu_order_id,payu_ext_order_id,paid_at,processed_at,subscription_period_start,subscription_period_end'
        . '&or=(' . implode(',', $filters) . ')'
        . '&limit=1';

    $result = subscription_payu_notify_request('GET', $url, $headers);

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PAYMENT_FETCH_ERROR', [
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null,
            'order_id_set' => $orderId !== '',
            'ext_order_id_set' => $extOrderId !== '',
        ]);

        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_notify_fetch_subscription(string $supabaseUrl, array $headers, string $tenantId): ?array
{
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscriptions'
        . '?select=tenant_id,current_period_end,grace_period_days'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $result = subscription_payu_notify_request('GET', $url, $headers);

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SUBSCRIPTION_FETCH_ERROR', [
            'tenant_id' => $tenantId,
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null,
        ]);

        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_notify_update_payment(
    string $supabaseUrl,
    array $headers,
    string $paymentId,
    string $tenantId,
    array $payload
): bool {
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscription_payments'
        . '?id=eq.' . rawurlencode($paymentId)
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = subscription_payu_notify_request('PATCH', $url, $headers, $payload);

    if ($result['ok']) {
        return true;
    }

    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PAYMENT_UPDATE_ERROR', [
        'payment_id' => $paymentId,
        'tenant_id' => $tenantId,
        'http_code' => $result['http_code'],
        'has_error' => $result['error'] !== null,
    ]);

    return false;
}

function subscription_payu_notify_save_subscription(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    array $payload,
    bool $exists
): bool {
    if ($exists) {
        $url = rtrim($supabaseUrl, '/')
            . '/rest/v1/tenant_subscriptions'
            . '?tenant_id=eq.' . rawurlencode($tenantId);

        $result = subscription_payu_notify_request('PATCH', $url, $headers, $payload);
    } else {
        $url = rtrim($supabaseUrl, '/') . '/rest/v1/tenant_subscriptions';
        $result = subscription_payu_notify_request('POST', $url, $headers, array_merge(['tenant_id' => $tenantId], $payload));
    }

    if ($result['ok']) {
        return true;
    }

    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SUBSCRIPTION_SAVE_ERROR', [
        'tenant_id' => $tenantId,
        'exists' => $exists,
        'http_code' => $result['http_code'],
        'has_error' => $result['error'] !== null,
    ]);

    return false;
}

function subscription_payu_notify_map_status(string $payuStatus): string
{
    return match (strtoupper(trim($payuStatus))) {
        'COMPLETED' => 'paid',
        'CANCELED' => 'canceled',
        'REJECTED' => 'failed',
        'EXPIRED' => 'expired',
        default => 'pending',
    };
}

function subscription_payu_notify_date_start(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }
}

function subscription_payu_notify_period(array $payment, ?array $subscription): array
{
    $billingPeriod = strtolower(trim((string) ($payment['billing_period'] ?? '')));
    $paymentType = strtolower(trim((string) ($payment['payment_type'] ?? '')));
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $start = $today;

    if ($paymentType === 'subscription_renewal' && is_array($subscription)) {
        $currentEnd = subscription_payu_notify_date_start($subscription['current_period_end'] ?? null);

        if ($currentEnd && $currentEnd > $today) {
            $start = $currentEnd;
        }
    }

    $end = match ($billingPeriod) {
        'monthly' => $start->modify('+1 month'),
        'yearly' => $start->modify('+1 year'),
        default => $start,
    };

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

function subscription_payu_notify_valid_id(string $value): bool
{
    return $value === '' || preg_match('/^[a-zA-Z0-9_-]{1,160}$/', $value) === 1;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        subscription_payu_notify_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_INVALID_JSON', [
            'body_length' => strlen($rawBody),
            'json_error' => json_last_error_msg(),
        ]);

        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy JSON.',
        ]);
    }

    $order = is_array($data['order'] ?? null) ? $data['order'] : [];
    $orderId = trim((string) ($order['orderId'] ?? ''));
    $extOrderId = trim((string) ($order['extOrderId'] ?? ''));
    $payuStatus = trim((string) ($order['status'] ?? ''));

    if (!subscription_payu_notify_valid_id($orderId) || !subscription_payu_notify_valid_id($extOrderId)) {
        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator płatności.',
        ]);
    }

    if ($orderId === '' && $extOrderId === '') {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_ORDER_ID_MISSING', [
            'body_length' => strlen($rawBody),
            'has_order' => is_array($data['order'] ?? null),
        ]);

        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Brak orderId/extOrderId.',
        ]);
    }

    $secondKey = aiiq_payu_env('AI_IQ_PAYU_SECOND_KEY');
    $signatureHeader = subscription_payu_notify_header('OpenPayu-Signature');

    if (!subscription_payu_notify_verify_signature($rawBody, $secondKey, $signatureHeader)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SIGNATURE_INVALID', [
            'order_id_set' => $orderId !== '',
            'ext_order_id_set' => $extOrderId !== '',
            'signature_header_set' => $signatureHeader !== '',
            'second_key_set' => $secondKey !== '',
        ]);

        subscription_payu_notify_json(401, [
            'success' => false,
            'error' => 'Nieprawidłowy podpis PayU.',
        ]);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji bazy danych.',
        ]);
    }

    $headers = supabaseHeaders($supabaseKey, $schema);
    $headers[] = 'Content-Type: application/json';

    $payment = subscription_payu_notify_find_payment($supabaseUrl, $headers, $orderId, $extOrderId);

    if (!is_array($payment)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PAYMENT_NOT_FOUND', [
            'order_id_set' => $orderId !== '',
            'ext_order_id_set' => $extOrderId !== '',
            'payu_status' => $payuStatus,
        ]);

        subscription_payu_notify_json(404, [
            'success' => false,
            'error' => 'Nie znaleziono płatności abonamentu.',
        ]);
    }

    $paymentId = trim((string) ($payment['id'] ?? ''));
    $tenantId = trim((string) ($payment['tenant_id'] ?? ''));

    if ($paymentId === '' || $tenantId === '') {
        subscription_payu_notify_json(422, [
            'success' => false,
            'error' => 'Nieprawidłowy rekord płatności abonamentu.',
        ]);
    }

    $mappedStatus = subscription_payu_notify_map_status($payuStatus);
    $now = gmdate('c');
    $safeNotify = subscription_payu_notify_safe_payload($data);
    $alreadyProcessed = strtolower(trim((string) ($payment['status'] ?? ''))) === 'paid'
        && trim((string) ($payment['processed_at'] ?? '')) !== '';

    if ($alreadyProcessed) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_ALREADY_PROCESSED', [
            'payment_id' => $paymentId,
            'tenant_id' => $tenantId,
            'payu_status' => $payuStatus,
        ]);

        subscription_payu_notify_json(200, [
            'success' => true,
            'status' => 'paid',
            'processed' => false,
            'idempotent' => true,
        ]);
    }

    if ($mappedStatus !== 'paid') {
        $updated = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
            'status' => $mappedStatus,
            'payu_status' => $payuStatus !== '' ? strtoupper($payuStatus) : 'UNKNOWN',
            'raw_notify' => $safeNotify,
            'updated_at' => $now,
        ]);

        if (!$updated) {
            subscription_payu_notify_json(500, [
                'success' => false,
                'error' => 'Nie udało się zaktualizować płatności abonamentu.',
            ]);
        }

        subscription_payu_notify_json(200, [
            'success' => true,
            'status' => $mappedStatus,
            'processed' => false,
        ]);
    }

    $billingPeriod = strtolower(trim((string) ($payment['billing_period'] ?? '')));

    if (!in_array($billingPeriod, ['monthly', 'yearly'], true)) {
        subscription_payu_notify_json(422, [
            'success' => false,
            'error' => 'Nieprawidłowy okres rozliczeniowy płatności.',
        ]);
    }

    $subscription = subscription_payu_notify_fetch_subscription($supabaseUrl, $headers, $tenantId);
    $storedPeriodStart = trim((string) ($payment['subscription_period_start'] ?? ''));
    $storedPeriodEnd = trim((string) ($payment['subscription_period_end'] ?? ''));
    $period = ($storedPeriodStart !== '' && $storedPeriodEnd !== '')
        ? ['start' => $storedPeriodStart, 'end' => $storedPeriodEnd]
        : subscription_payu_notify_period($payment, $subscription);
    $subscriptionExists = is_array($subscription);

    if ($storedPeriodStart !== '' && $storedPeriodEnd !== '') {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_RETRY_WITH_STORED_PERIOD', [
            'payment_id' => $paymentId,
            'tenant_id' => $tenantId,
            'billing_period' => $billingPeriod,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
        ]);
    }

    if ($storedPeriodStart === '' || $storedPeriodEnd === '') {
        $paymentPrepared = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
            'status' => 'paid',
            'payu_status' => 'COMPLETED',
            'paid_at' => $now,
            'raw_notify' => $safeNotify,
            'subscription_period_start' => $period['start'],
            'subscription_period_end' => $period['end'],
            'updated_at' => $now,
        ]);

        if (!$paymentPrepared) {
            subscription_payu_notify_json(500, [
                'success' => false,
                'error' => 'Nie udało się przygotować płatności abonamentu do przetworzenia.',
            ]);
        }
    }

    $subscriptionPayload = [
        'plan_code' => 'pro',
        'plan_name' => 'Pro',
        'billing_period' => $billingPeriod,
        'status' => 'active',
        'amount' => $payment['amount'] ?? null,
        'currency' => (string) ($payment['currency'] ?? 'PLN'),
        'current_period_start' => $period['start'],
        'current_period_end' => $period['end'],
        'next_payment_due_at' => $period['end'],
        'last_payment_at' => $now,
        'updated_at' => $now,
    ];

    if (!$subscriptionExists) {
        $subscriptionPayload['grace_period_days'] = 0;
    }

    $subscriptionSaved = subscription_payu_notify_save_subscription(
        $supabaseUrl,
        $headers,
        $tenantId,
        $subscriptionPayload,
        $subscriptionExists
    );

    if (!$subscriptionSaved) {
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Nie udało się zaktualizować abonamentu.',
        ]);
    }

    $paymentUpdated = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
        'status' => 'paid',
        'payu_status' => 'COMPLETED',
        'paid_at' => $payment['paid_at'] ?? $now,
        'processed_at' => $now,
        'raw_notify' => $safeNotify,
        'subscription_period_start' => $period['start'],
        'subscription_period_end' => $period['end'],
        'updated_at' => $now,
    ]);

    if (!$paymentUpdated) {
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Abonament został zaktualizowany, ale nie udało się oznaczyć płatności jako przetworzonej.',
        ]);
    }

    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PROCESSED', [
        'payment_id' => $paymentId,
        'tenant_id' => $tenantId,
        'billing_period' => $billingPeriod,
        'period_start' => $period['start'],
        'period_end' => $period['end'],
    ]);

    subscription_payu_notify_json(200, [
        'success' => true,
        'status' => 'paid',
        'processed' => true,
        'period_start' => $period['start'],
        'period_end' => $period['end'],
    ]);
} catch (Throwable $e) {
    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_FATAL', [
        'exception_type' => get_class($e),
    ]);

    subscription_payu_notify_json(500, [
        'success' => false,
        'error' => 'Błąd obsługi powiadomienia PayU.',
    ]);
}
