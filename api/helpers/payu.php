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
            'error' => $result['error'],
            'response' => $result['response'],
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
        'curl_error' => $result['error'],
        'effective_url' => $result['effective_url'],
        'response' => $result['data'] ?: $result['response'],
    ];

    if ($result['error'] || $result['http_code'] < 200 || $result['http_code'] >= 300) {
        payu_debug('PAYU_TOKEN_ERROR', $debugPayload);

        return [
            'success' => false,
            'error' => 'Nie udało się pobrać tokena PayU.',
            'details' => $debugPayload,
        ];
    }

    $token = (string) ($result['data']['access_token'] ?? '');

    if ($token === '') {
        payu_debug('PAYU_TOKEN_EMPTY', $debugPayload);

        return [
            'success' => false,
            'error' => 'PayU nie zwróciło access_token.',
            'details' => $debugPayload,
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
            'details' => $tokenResult['details'] ?? null,
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
        'error' => $result['error'],
        'location' => $result['location'] ?? '',
        'data' => $data,
        'raw' => $result['response'],
    ]);

    if ($result['error']) {
        return [
            'success' => false,
            'error' => 'Błąd połączenia z PayU.',
            'details' => $result['error'],
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
        'details' => $data ?: $result['response'],
    ];
}