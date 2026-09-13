<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/crypto.php';

function payu_debug(string $tag, $data = null): void
{
    $line = date('Y-m-d H:i:s') . ' [' . $tag . ']';

    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $line .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $line .= ' ' . (string) $data;
        }
    }

    error_log($line);
}

function payu_get_base_url(string $mode): string
{
    return $mode === 'production'
        ? 'https://secure.payu.com'
        : 'https://secure.snd.payu.com';
}

function payu_supabase_request(
    string $url,
    string $method,
    string $key,
    string $schema,
    ?array $body = null,
    array $extraHeaders = []
): array {
    $headers = array_merge(
        supabaseHeaders($key, $schema),
        [
            'Accept: application/json',
        ],
        $extraHeaders
    );

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $curlError,
        'data' => json_decode((string) $response, true),
    ];
}

function payu_get_integration(string $tenantId): ?array
{
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_ENV_MISSING');
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/tenant_integrations'
        . '?select=tenant_id,provider,enabled,mode,settings,secrets'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&provider=eq.payu'
        . '&limit=1';

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('PAYU_INTEGRATION_FETCH_ERROR', [
            'http_code' => $result['http_code'],
            'error' => $result['error'] !== '',
        ]);
        return null;
    }

    $row = $result['data'][0] ?? null;

    if (!$row || empty($row['enabled'])) {
        payu_debug('PAYU_INTEGRATION_DISABLED_OR_MISSING');
        return null;
    }

    $settings = is_array($row['settings'] ?? null) ? $row['settings'] : [];
    $storedSecrets = is_array($row['secrets'] ?? null) ? $row['secrets'] : [];

    try {
        $secrets = decrypt_json_secret($storedSecrets);
    } catch (Throwable $e) {
        payu_debug('PAYU_SECRET_DECRYPT_ERROR', $e->getMessage());
        return null;
    }

    $mode = (string) ($row['mode'] ?? 'sandbox');

    if (!in_array($mode, ['sandbox', 'production'], true)) {
        $mode = 'sandbox';
    }

    $posId = trim((string) ($settings['pos_id'] ?? ''));
    $clientId = trim((string) ($settings['client_id'] ?? ''));
    $clientSecret = trim((string) ($secrets['client_secret'] ?? ''));
    $secondKey = trim((string) ($secrets['second_key'] ?? ''));

    if ($posId === '' || $clientId === '' || $clientSecret === '') {
        payu_debug('PAYU_CONFIG_INCOMPLETE', [
            'pos_id' => $posId !== '',
            'client_id' => $clientId !== '',
            'client_secret' => $clientSecret !== '',
            'second_key' => $secondKey !== '',
        ]);
        return null;
    }

    return [
        'mode' => $mode,
        'base_url' => payu_get_base_url($mode),
        'pos_id' => $posId,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'second_key' => $secondKey,
    ];
}

function payu_http_request(
    string $url,
    string $method,
    array $headers = [],
    $body = null,
    bool $followRedirects = false
): array {
    $responseHeaders = [];

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_HEADER => false,
        CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $header = trim($header);

            if ($header === '' || strpos($header, ':') === false) {
                return $length;
            }

            [$name, $value] = explode(':', $header, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name !== '') {
                $responseHeaders[$name] = $value;
            }

            return $length;
        },
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $curlError,
        'effective_url' => $effectiveUrl,
        'headers' => $responseHeaders,
        'location' => $responseHeaders['location'] ?? '',
        'data' => json_decode((string) $response, true),
    ];
}

function payu_get_access_token(array $payu): array
{
    $url = $payu['base_url'] . '/pl/standard/user/oauth/authorize';

    $body = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $payu['client_id'],
        'client_secret' => $payu['client_secret'],
    ]);

    $result = payu_http_request(
        $url,
        'POST',
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        $body
    );

    $debugPayload = [
        'url' => $url,
        'mode' => $payu['mode'] ?? null,
        'base_url' => $payu['base_url'] ?? null,
        'client_id_set' => !empty($payu['client_id']),
        'client_secret_set' => !empty($payu['client_secret']),
        'http_code' => $result['http_code'],
        'curl_error' => $result['error'] !== '',
        'effective_url' => $result['effective_url'],
    ];

    if ($result['error'] || $result['http_code'] < 200 || $result['http_code'] >= 300) {
        payu_debug('PAYU_TOKEN_ERROR', $debugPayload);

        return [
            'success' => false,
            'error' => 'Nie udało się pobrać tokena PayU.',
        ];
    }

    $token = (string) ($result['data']['access_token'] ?? '');

    if ($token === '') {
        payu_debug('PAYU_TOKEN_EMPTY', $debugPayload);

        return [
            'success' => false,
            'error' => 'PayU nie zwróciło access_token.',
        ];
    }

    return [
        'success' => true,
        'access_token' => $token,
    ];
}

function payu_create_order(array $payu, array $orderPayload): array
{
    $tokenResult = payu_get_access_token($payu);

    if (empty($tokenResult['success'])) {
        return [
            'success' => false,
            'error' => $tokenResult['error'] ?? 'Nie udało się pobrać tokena PayU.',
        ];
    }

    $token = $tokenResult['access_token'];

    $url = $payu['base_url'] . '/api/v2_1/orders';

    $result = payu_http_request(
        $url,
        'POST',
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        json_encode($orderPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        false
    );

    $data = is_array($result['data']) ? $result['data'] : [];

    payu_debug('PAYU_CREATE_ORDER_RESPONSE', [
        'http_code' => $result['http_code'],
        'error' => $result['error'] !== '',
        'location_set' => !empty($result['location']),
        'status_code' => (string) ($data['status']['statusCode'] ?? ''),
        'order_id_set' => !empty($data['orderId']),
        'redirect_uri_set' => !empty($data['redirectUri']),
    ]);

    if ($result['error']) {
        return [
            'success' => false,
            'error' => 'Błąd połączenia z PayU.',
        ];
    }

    $statusCode = (string) ($data['status']['statusCode'] ?? '');
    $redirectUri = (string) ($data['redirectUri'] ?? '');
    $orderId = (string) ($data['orderId'] ?? '');
    $location = (string) ($result['location'] ?? '');

    if ($redirectUri === '' && $location !== '') {
        $redirectUri = $location;
    }

    if ($orderId === '' && !empty($orderPayload['extOrderId'])) {
        $orderId = (string) $orderPayload['extOrderId'];
    }

    if (
        in_array($result['http_code'], [200, 201, 302], true)
        && $redirectUri !== ''
    ) {
        return [
            'success' => true,
            'order_id' => $orderId,
            'redirect_uri' => $redirectUri,
            'raw' => $data,
            'http_code' => $result['http_code'],
        ];
    }

    return [
        'success' => false,
        'error' => 'PayU nie utworzyło zamówienia.',
        'status_code' => $statusCode,
        'http_code' => $result['http_code'],
        'location' => $location,
    ];
}

function payu_parse_minor_amount($value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (!is_string($value)) {
        return null;
    }

    $text = trim($value);

    if ($text === '' || preg_match('/^\d+$/', $text) !== 1) {
        return null;
    }

    $normalized = ltrim($text, '0');

    if ($normalized === '') {
        return null;
    }

    $max = (string) PHP_INT_MAX;

    if (
        strlen($normalized) > strlen($max)
        || (
            strlen($normalized) === strlen($max)
            && strcmp($normalized, $max) > 0
        )
    ) {
        return null;
    }

    return (int) $normalized;
}

function payu_retrieve_order(array $payu, string $orderId): array
{
    $orderId = trim($orderId);

    if (
        $orderId === ''
        || strlen($orderId) > 128
        || preg_match('/^[A-Za-z0-9_-]+$/', $orderId) !== 1
    ) {
        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'local_order_id_invalid',
            'request_attempted' => false,
            'http_code' => 0,
            'response_sha256' => hash('sha256', ''),
        ];
    }

    $tokenResult = payu_get_access_token($payu);

    if (empty($tokenResult['success'])) {
        return [
            'success' => false,
            'result_kind' => 'transport_failure',
            'error_code' => 'access_token_unavailable',
            'request_attempted' => false,
            'http_code' => 0,
            'response_sha256' => hash('sha256', ''),
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'result_kind' => 'transport_failure',
            'error_code' => 'curl_missing',
            'request_attempted' => false,
            'http_code' => 0,
            'response_sha256' => hash('sha256', ''),
        ];
    }

    $url = rtrim((string) ($payu['base_url'] ?? ''), '/')
        . '/api/v2_1/orders/'
        . rawurlencode($orderId);

    if (!preg_match(
        '#^https://secure(?:\.snd)?\.payu\.com/api/v2_1/orders/[A-Za-z0-9_%.-]+$#',
        $url
    )) {
        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'provider_url_invalid',
            'request_attempted' => false,
            'http_code' => 0,
            'response_sha256' => hash('sha256', ''),
        ];
    }

    $last = null;

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'success' => false,
                'result_kind' => 'transport_failure',
                'error_code' => 'curl_init_error',
                'request_attempted' => false,
                'http_code' => 0,
                'response_sha256' => hash('sha256', ''),
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . (string) $tokenResult['access_token'],
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        $rawResponse = is_string($response) ? $response : '';
        $decoded = json_decode($rawResponse, true);
        $jsonValid = json_last_error() === JSON_ERROR_NONE;

        $last = [
            'http_code' => $httpCode,
            'error' => $curlError,
            'response' => $rawResponse,
            'data' => $decoded,
            'json_valid' => $jsonValid,
            'request_attempted' => true,
        ];

        $retryable = $curlError !== ''
            || $httpCode === 0
            || in_array($httpCode, [500, 502, 503, 504], true);

        if ($retryable && $attempt < 2) {
            payu_debug('PAYU_RETRIEVE_ORDER_RETRY', [
                'attempt' => $attempt,
                'http_code' => $httpCode,
                'transport_error' => $curlError !== '',
                'mode' => (string) ($payu['mode'] ?? ''),
            ]);

            continue;
        }

        break;
    }

    $result = is_array($last) ? $last : [];
    $httpCode = (int) ($result['http_code'] ?? 0);
    $transportError = (string) ($result['error'] ?? '');
    $rawResponse = (string) ($result['response'] ?? '');
    $responseSha256 = hash('sha256', $rawResponse);

    if ($transportError !== '' || $httpCode === 0) {
        payu_debug('PAYU_RETRIEVE_ORDER_TRANSPORT_ERROR', [
            'http_code' => $httpCode,
            'transport_error' => true,
            'mode' => (string) ($payu['mode'] ?? ''),
        ]);

        return [
            'success' => false,
            'result_kind' => 'transport_failure',
            'error_code' => 'transport_error',
            'request_attempted' => !empty($result['request_attempted']),
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    if ($httpCode >= 500 && $httpCode <= 599) {
        return [
            'success' => false,
            'result_kind' => 'transport_failure',
            'error_code' => 'provider_http_' . $httpCode,
            'request_attempted' => true,
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'provider_http_' . $httpCode,
            'request_attempted' => true,
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    if (
        empty($result['json_valid'])
        || !is_array($result['data'] ?? null)
    ) {
        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'malformed_response',
            'request_attempted' => true,
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    $data = $result['data'];

    $statusBlock = is_array($data['status'] ?? null)
        ? $data['status']
        : [];

    $statusCode = strtoupper(
        trim((string) ($statusBlock['statusCode'] ?? ''))
    );

    if ($statusCode !== 'SUCCESS') {
        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'provider_status_not_success',
            'request_attempted' => true,
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    $orders = $data['orders'] ?? null;

    if (
        !is_array($orders)
        || count($orders) !== 1
        || !is_array($orders[0] ?? null)
    ) {
        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'orders_cardinality_invalid',
            'request_attempted' => true,
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    $order = $orders[0];

    $providerOrderId = trim((string) ($order['orderId'] ?? ''));
    $extOrderId = trim((string) ($order['extOrderId'] ?? ''));
    $currency = strtoupper(trim((string) ($order['currencyCode'] ?? '')));
    $payuStatus = strtoupper(trim((string) ($order['status'] ?? '')));
    $amountMinor = payu_parse_minor_amount($order['totalAmount'] ?? null);

    if (
        $providerOrderId === ''
        || strlen($providerOrderId) > 128
        || preg_match('/^[A-Za-z0-9_-]+$/', $providerOrderId) !== 1
        || $extOrderId === ''
        || strlen($extOrderId) > 160
        || preg_match('/^[A-Za-z0-9_-]+$/', $extOrderId) !== 1
        || $currency === ''
        || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        || $amountMinor === null
        || !in_array(
            $payuStatus,
            [
                'NEW',
                'PENDING',
                'WAITING_FOR_CONFIRMATION',
                'COMPLETED',
                'CANCELED',
            ],
            true
        )
    ) {
        payu_debug('PAYU_RETRIEVE_ORDER_CONTRACT_INVALID', [
            'http_code' => $httpCode,
            'order_id_set' => $providerOrderId !== '',
            'ext_order_id_set' => $extOrderId !== '',
            'currency_valid' => preg_match('/^[A-Z]{3}$/', $currency) === 1,
            'amount_valid' => $amountMinor !== null,
            'status' => substr($payuStatus, 0, 80),
            'mode' => (string) ($payu['mode'] ?? ''),
        ]);

        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'provider_contract_invalid',
            'request_attempted' => true,
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    if (!hash_equals($orderId, $providerOrderId)) {
        return [
            'success' => false,
            'result_kind' => 'result_unknown',
            'error_code' => 'requested_order_id_mismatch',
            'request_attempted' => true,
            'http_code' => $httpCode,
            'response_sha256' => $responseSha256,
        ];
    }

    payu_debug('PAYU_RETRIEVE_ORDER_RESPONSE', [
        'http_code' => $httpCode,
        'status' => $payuStatus,
        'order_id_match' => true,
        'ext_order_id_set' => true,
        'amount_valid' => true,
        'currency_valid' => true,
        'mode' => (string) ($payu['mode'] ?? ''),
    ]);

    return [
        'success' => true,
        'result_kind' => 'provider_result',
        'error_code' => '',
        'request_attempted' => true,
        'order_id' => $providerOrderId,
        'ext_order_id' => $extOrderId,
        'payu_status' => $payuStatus,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'http_code' => $httpCode,
        'response_sha256' => $responseSha256,
    ];
}
