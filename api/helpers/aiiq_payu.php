<?php
declare(strict_types=1);

function aiiq_payu_debug(string $tag, $data = null): void
{
    $line = date('Y-m-d H:i:s') . ' [' . $tag . ']';

    if ($data !== null) {
        if (is_array($data)) {
            unset(
                $data['client_secret'],
                $data['second_key'],
                $data['AI_IQ_PAYU_CLIENT_SECRET'],
                $data['AI_IQ_PAYU_SECOND_KEY'],
                $data['access_token'],
                $data['token'],
                $data['authorization']
            );
            $line .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $line .= ' ' . (string) $data;
        }
    }

    error_log($line);
}

function aiiq_payu_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string) $value);

    return $value !== '' ? $value : $default;
}

function aiiq_payu_default_base_url(string $env): string
{
    return $env === 'production'
        ? 'https://secure.payu.com'
        : 'https://secure.snd.payu.com';
}

function aiiq_payu_config(): array
{
    $env = strtolower(aiiq_payu_env('AI_IQ_PAYU_ENV', 'sandbox'));
    $currency = strtoupper(aiiq_payu_env('AI_IQ_PAYU_CURRENCY', 'PLN'));
    $baseUrl = aiiq_payu_default_base_url($env);

    $config = [
        'env' => $env,
        'pos_id' => aiiq_payu_env('AI_IQ_PAYU_POS_ID'),
        'client_id' => aiiq_payu_env('AI_IQ_PAYU_CLIENT_ID'),
        'client_secret' => aiiq_payu_env('AI_IQ_PAYU_CLIENT_SECRET'),
        'second_key' => aiiq_payu_env('AI_IQ_PAYU_SECOND_KEY'),
        'currency' => $currency,
        'auth_url' => aiiq_payu_env('AI_IQ_PAYU_AUTH_URL', $baseUrl . '/pl/standard/user/oauth/authorize'),
        'order_url' => aiiq_payu_env('AI_IQ_PAYU_ORDER_URL', $baseUrl . '/api/v2_1/orders'),
    ];

    $missing = [];

    foreach (['env', 'pos_id', 'client_id', 'client_secret', 'second_key', 'auth_url', 'order_url'] as $field) {
        if ($config[$field] === '') {
            $missing[] = 'AI_IQ_PAYU_' . strtoupper($field);
        }
    }

    if ($env !== '' && !in_array($env, ['sandbox', 'production'], true)) {
        $missing[] = 'AI_IQ_PAYU_ENV';
    }

    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $missing[] = 'AI_IQ_PAYU_CURRENCY';
    }

    if ($missing) {
        aiiq_payu_debug('AI_IQ_PAYU_CONFIG_MISSING', [
            'missing' => $missing,
            'env' => $env,
        ]);

        return [
            'success' => false,
            'error' => 'Brak konfiguracji PayU platformy AI-IQ.',
            'missing' => $missing,
        ];
    }

    return [
        'success' => true,
        'config' => $config,
    ];
}

function aiiq_payu_http_request(
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

function aiiq_payu_safe_text($value, int $maxLength = 240): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = trim((string) $value);

    if ($text === '') {
        return '';
    }

    if (strlen($text) > $maxLength) {
        return substr($text, 0, $maxLength) . '...';
    }

    return $text;
}

function aiiq_payu_response_diagnostics(array $result, array $payu): array
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $status = is_array($data['status'] ?? null) ? $data['status'] : [];

    return [
        'http_code' => (int) ($result['http_code'] ?? 0),
        'curl_error' => (($result['error'] ?? '') !== '') ? aiiq_payu_safe_text($result['error']) : '',
        'status' => aiiq_payu_safe_text($status['status'] ?? ($data['status'] ?? '')),
        'code' => aiiq_payu_safe_text($status['code'] ?? ($data['code'] ?? '')),
        'status_code' => aiiq_payu_safe_text($status['statusCode'] ?? ($data['statusCode'] ?? '')),
        'status_desc' => aiiq_payu_safe_text($status['statusDesc'] ?? ($data['statusDesc'] ?? '')),
        'code_literal' => aiiq_payu_safe_text($status['codeLiteral'] ?? ($data['codeLiteral'] ?? '')),
        'description' => aiiq_payu_safe_text($status['description'] ?? ($data['description'] ?? '')),
        'order_id_set' => aiiq_payu_safe_text($data['orderId'] ?? '') !== '',
        'redirect_uri_set' => aiiq_payu_safe_text($data['redirectUri'] ?? '') !== '',
        'location_header_set' => aiiq_payu_safe_text($result['location'] ?? '') !== '',
        'env' => aiiq_payu_safe_text($payu['env'] ?? ''),
    ];
}

function aiiq_payu_url_diagnostics(string $url): array
{
    $parts = parse_url($url);

    if (!is_array($parts)) {
        return [
            'auth_url_host' => '',
            'auth_url_path' => '',
        ];
    }

    return [
        'auth_url_host' => aiiq_payu_safe_text($parts['host'] ?? ''),
        'auth_url_path' => aiiq_payu_safe_text($parts['path'] ?? ''),
    ];
}

function aiiq_payu_token_request_diagnostics(array $result, array $payu): array
{
    $clientId = (string) ($payu['client_id'] ?? '');
    $clientSecret = (string) ($payu['client_secret'] ?? '');

    return array_merge(
        aiiq_payu_response_diagnostics($result, $payu),
        aiiq_payu_url_diagnostics((string) ($payu['auth_url'] ?? '')),
        [
            'client_id_set' => $clientId !== '',
            'client_id_length' => strlen($clientId),
            'client_secret_set' => $clientSecret !== '',
            'client_secret_length' => strlen($clientSecret),
        ]
    );
}

function aiiq_payu_get_access_token(array $payu): array
{
    $body = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $payu['client_id'],
        'client_secret' => $payu['client_secret'],
    ]);

    $result = aiiq_payu_http_request(
        $payu['auth_url'],
        'POST',
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        $body
    );

    if ($result['error'] || $result['http_code'] < 200 || $result['http_code'] >= 300) {
        aiiq_payu_debug('AI_IQ_PAYU_TOKEN_ERROR', aiiq_payu_token_request_diagnostics($result, $payu));

        return [
            'success' => false,
            'error' => 'Nie udało się pobrać tokena PayU platformy.',
        ];
    }

    $token = (string) ($result['data']['access_token'] ?? '');

    if ($token === '') {
        aiiq_payu_debug('AI_IQ_PAYU_TOKEN_EMPTY', aiiq_payu_token_request_diagnostics($result, $payu));

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

function aiiq_payu_create_order(array $payu, array $orderPayload): array
{
    $tokenResult = aiiq_payu_get_access_token($payu);

    if (empty($tokenResult['success'])) {
        return [
            'success' => false,
            'error' => $tokenResult['error'] ?? 'Nie udało się pobrać tokena PayU platformy.',
        ];
    }

    $result = aiiq_payu_http_request(
        $payu['order_url'],
        'POST',
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $tokenResult['access_token'],
        ],
        json_encode($orderPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        false
    );

    $data = is_array($result['data']) ? $result['data'] : [];
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

    $diagnostics = aiiq_payu_response_diagnostics($result, $payu);
    $diagnostics['order_id_set'] = $orderId !== '';
    $diagnostics['redirect_uri_set'] = $redirectUri !== '';

    aiiq_payu_debug('AI_IQ_PAYU_CREATE_ORDER_RESPONSE', $diagnostics);

    if (
        !$result['error']
        && in_array($result['http_code'], [200, 201, 302], true)
        && $redirectUri !== ''
    ) {
        return [
            'success' => true,
            'order_id' => $orderId,
            'redirect_uri' => $redirectUri,
            'payu_status' => $statusCode,
            'http_code' => $result['http_code'],
        ];
    }

    return [
        'success' => false,
        'error' => $result['error'] ? 'Błąd połączenia z PayU.' : 'PayU nie utworzyło zamówienia.',
        'payu_status' => $statusCode,
        'http_code' => $result['http_code'],
    ];
}
