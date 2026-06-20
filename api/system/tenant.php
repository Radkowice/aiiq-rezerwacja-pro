<?php

require_once __DIR__ . '/../helpers/supabase.php';

function tenant_debug_log(string $label, $data): void
{
    if (is_array($data)) {
        $blockedKeys = ['url', 'response', 'body', 'json', 'error', 'curlError', 'details', 'debug'];

        foreach ($blockedKeys as $key) {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
                $data['omitted'] = true;
            }
        }
    }

    @file_put_contents(
        '/var/www/data/debug-tenant.log',
        date('Y-m-d H:i:s') . " [{$label}] " .
        (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) .
        "\n",
        FILE_APPEND
    );
}

function normalize_host(?string $host): string
{
    $host = strtolower(trim((string)$host));

    if ($host === '') {
        return '';
    }

    // usuń port
    $host = preg_replace('/:\d+$/', '', $host);

    return $host;
}

function tenant_host_is_valid(string $host): bool
{
    if ($host === '' || strlen($host) > 253) {
        return false;
    }

    if (str_contains($host, '/')
        || str_contains($host, '\\')
        || str_contains($host, ',')
        || preg_match('/[\s\x00-\x1F\x7F]/', $host) === 1) {
        return false;
    }

    if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1
        || $host[0] === '.'
        || $host[0] === '-'
        || str_ends_with($host, '.')
        || str_ends_with($host, '-')) {
        return false;
    }

    $segments = explode('.', $host);

    foreach ($segments as $segment) {
        if ($segment === ''
            || strlen($segment) > 63
            || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $segment) !== 1) {
            return false;
        }
    }

    return true;
}

function host_candidates(): array
{
    $rawHosts = [
        $_SERVER['HTTP_X_ORIGINAL_HOST'] ?? '',
        $_SERVER['HTTP_HOST'] ?? '',
        $_SERVER['SERVER_NAME'] ?? '',
    ];

    $candidates = [];

    foreach ($rawHosts as $raw) {
        $host = normalize_host($raw);

        if (!tenant_host_is_valid($host)) {
            continue;
        }

        $candidates[] = $host;

        if (str_starts_with($host, 'www.')) {
            $alternateHost = substr($host, 4);
        } else {
            $alternateHost = 'www.' . $host;
        }

        if (tenant_host_is_valid($alternateHost)) {
            $candidates[] = $alternateHost;
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates)));

    tenant_debug_log('HOST_CANDIDATES', [
        'HTTP_X_ORIGINAL_HOST'  => $_SERVER['HTTP_X_ORIGINAL_HOST'] ?? null,
        'HTTP_HOST'             => $_SERVER['HTTP_HOST'] ?? null,
        'SERVER_NAME'           => $_SERVER['SERVER_NAME'] ?? null,
        'X_FORWARDED_HOST_IGNORED' => trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')) !== '',
        'candidates'            => $candidates,
    ]);

    return $candidates;
}

function tenant_lookup_http_code(array $result): int
{
    $httpCode = (int)($result['http_code'] ?? 0);

    return $httpCode === 429 ? 429 : 503;
}

function tenant_lookup_request(string $url, string $SUPABASE_KEY, string $SCHEMA): array
{
    $attempts = 2;
    $lastResult = [
        'ok' => false,
        'retryable' => true,
        'response' => false,
        'curl_error' => '',
        'http_code' => 0,
        'data' => null,
        'json_valid' => false,
    ];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => supabaseHeaders($SUPABASE_KEY, $SCHEMA),
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $response, true);
        $jsonValid = json_last_error() === JSON_ERROR_NONE;
        $retryable = $response === false
            || $curlError !== ''
            || $httpCode === 429
            || $httpCode >= 500
            || $httpCode === 0;

        $lastResult = [
            'ok' => $response !== false
                && $curlError === ''
                && $httpCode >= 200
                && $httpCode < 300
                && $jsonValid
                && is_array($data),
            'retryable' => $retryable,
            'response' => $response,
            'curl_error' => $curlError,
            'http_code' => $httpCode,
            'data' => $data,
            'json_valid' => $jsonValid,
        ];

        if ($lastResult['ok'] || !$retryable || $attempt === $attempts) {
            break;
        }

        usleep(150000);
    }

    return $lastResult;
}

function getTenantLookupFromHost(string $SUPABASE_URL, string $SUPABASE_KEY, string $SCHEMA): array
{

    $hosts = host_candidates();

    if (empty($hosts)) {
        tenant_debug_log('TENANT_ERROR', 'Brak hosta w $_SERVER');
        return [
            'status' => 'technical_error',
            'http_code' => 503,
            'message' => 'Nie udało się rozpoznać hosta.',
        ];
    }

    $technicalError = null;

    foreach ($hosts as $host) {
        $url = rtrim($SUPABASE_URL, '/') . '/rest/v1/tenant_domains'
            . '?select=tenant_id,domain,is_active'
            . '&domain=eq.' . rawurlencode($host)
            . '&is_active=eq.true'
            . '&limit=1';

        $result = tenant_lookup_request($url, $SUPABASE_KEY, $SCHEMA);
        $decodedResponse = is_array($result['data'] ?? null) ? $result['data'] : null;
        $responseCount = is_array($decodedResponse) ? count($decodedResponse) : 0;

        tenant_debug_log('TENANT_LOOKUP', [
            'host' => $host,
            'httpCode' => $result['http_code'] ?? 0,
            'has_error' => ($result['curl_error'] ?? '') !== '',
            'found' => is_array($decodedResponse) && !empty($decodedResponse[0]['tenant_id']),
            'response_count' => $responseCount,
        ]);

        if (!$result['ok']) {
            $technicalError = [
                'status' => 'technical_error',
                'http_code' => tenant_lookup_http_code($result),
                'message' => 'Nie udało się potwierdzić domeny kalendarza.',
            ];
            continue;
        }

        $data = $decodedResponse;

        if (is_array($data) && !empty($data[0]['tenant_id'])) {
            tenant_debug_log('TENANT_FOUND', [
                'host' => $host,
                'tenant_id' => $data[0]['tenant_id'],
                'matched_domain' => $data[0]['domain'] ?? null,
                'found' => true,
            ]);

            return [
                'status' => 'found',
                'tenant_id' => (string) $data[0]['tenant_id'],
                'host' => $host,
            ];
        }
    }

    if (is_array($technicalError)) {
        tenant_debug_log('TENANT_TECHNICAL_ERROR', [
            'checked_hosts' => $hosts,
            'http_code' => $technicalError['http_code'] ?? 503,
        ]);

        return $technicalError;
    }

    tenant_debug_log('TENANT_NOT_FOUND', [
        'checked_hosts' => $hosts,
        'found' => false,
    ]);

    return [
        'status' => 'not_found',
    ];
}

function getTenantIdFromHost(string $SUPABASE_URL, string $SUPABASE_KEY, string $SCHEMA): ?string
{
    $lookup = getTenantLookupFromHost($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA);

    return ($lookup['status'] ?? '') === 'found' && !empty($lookup['tenant_id'])
        ? (string) $lookup['tenant_id']
        : null;
}
