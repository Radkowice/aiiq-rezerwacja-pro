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
                $data['AI_IQ_PAYU_SECOND_KEY']
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
    $env = strtolower(aiiq_payu_env('AI_IQ_PAYU_ENV'));
    $currency = strtoupper(aiiq_payu_env('AI_IQ_PAYU_CURRENCY'));

    $config = [
        'env' => $env,
        'pos_id' => aiiq_payu_env('AI_IQ_PAYU_POS_ID'),
        'client_id' => aiiq_payu_env('AI_IQ_PAYU_CLIENT_ID'),
        'client_secret' => aiiq_payu_env('AI_IQ_PAYU_CLIENT_SECRET'),
        'second_key' => aiiq_payu_env('AI_IQ_PAYU_SECOND_KEY'),
        'currency' => $currency,
        'auth_url' => aiiq_payu_env('AI_IQ_PAYU_AUTH_URL'),
        'order_url' => aiiq_payu_env('AI_IQ_PAYU_ORDER_URL'),
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
        aiiq_payu_debug('AI_IQ_PAYU_TOKEN_ERROR', [
            'http_code' => $result['http_code'],
            'curl_error' => $result['error'] !== '',
            'effective_url' => $result['effective_url'],
            'env' => $payu['env'] ?? '',
        ]);

        return [
            'success' => false,
            'error' => 'Nie udało się pobrać tokena PayU platformy.',
        ];
    }

    $token = (string) ($result['data']['access_token'] ?? '');

    if ($token === '') {
        aiiq_payu_debug('AI_IQ_PAYU_TOKEN_EMPTY', [
            'http_code' => $result['http_code'],
            'env' => $payu['env'] ?? '',
        ]);

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

    aiiq_payu_debug('AI_IQ_PAYU_CREATE_ORDER_RESPONSE', [
        'http_code' => $result['http_code'],
        'curl_error' => $result['error'] !== '',
        'status_code' => $statusCode,
        'order_id_set' => $orderId !== '',
        'redirect_uri_set' => $redirectUri !== '',
        'env' => $payu['env'] ?? '',
    ]);

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
