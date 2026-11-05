<?php

require_once __DIR__ . '/../helpers/supabase.php';

function tenant_debug_log(string $label, $data): void
{
    $enabled = strtolower(trim((string) getenv('TENANT_DEBUG_LOG')));

    if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
        return;
    }

    if (is_array($data)) {
        $blockedKeys = [
            'url',
            'response',
            'body',
            'json',
            'error',
            'curlError',
            'details',
            'debug',
            'host',
            'hosts',
            'checked_hosts',
            'domain',
            'matched_domain',
            'tenant_id',
            'candidates',
            'HTTP_X_ORIGINAL_HOST',
            'HTTP_HOST',
            'SERVER_NAME',
            'authorization',
            'cookie',
            'payload',
        ];

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

function tenant_lookup_retry_delay_us(int $attempt, int $httpCode): int
{
    $baseDelays = [150000, 300000, 600000];
    $baseDelay = $baseDelays[$attempt - 1] ?? 600000;

    if ($httpCode !== 429 && $httpCode < 500) {
        return $baseDelay;
    }

    $jitterMax = $attempt <= 1 ? 100000 : 200000;

    try {
        return $baseDelay + random_int(0, $jitterMax);
    } catch (Throwable $e) {
        return $baseDelay;
    }
}

/**
 * Odczyt poświadczeń tylko dla rozpoznawania domen.
 * Nie wolno używać service_role jako fallbacku przy braku JWT.
 * Ścieżka wskazuje PLIK w podmontowanym tylko-do-odczytu KATALOGU.
 */
function tenant_lookup_runtime_credentials(): ?array
{
    $anonKey = trim((string) getenv('SUPABASE_ANON_KEY'));
    $tokenPath = trim((string) getenv('REZERWIA_DOMAIN_LOOKUP_JWT_FILE'));

    if ($anonKey === '' || $tokenPath === '' || $tokenPath[0] !== '/'
        || strlen($tokenPath) > 512 || str_contains($tokenPath, "\0")) {
        return null;
    }

    // Blokada błędnej konfiguracji podającej pełny klucz zamiast anon.
    $serviceKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    if ($serviceKey !== '' && hash_equals($serviceKey, $anonKey)) {
        return null;
    }

    clearstatcache(true, $tokenPath);
    $st = @lstat($tokenPath);
    if (!is_array($st)
        || ($st['mode'] & 0170000) !== 0100000
        || ($st['mode'] & 0777) !== 0640
        || $st['uid'] !== 0
        || $st['gid'] !== 82
        || !is_readable($tokenPath)) {
        return null;
    }

    // Rotator wymienia plik atomowo; odczyt ograniczamy do 4 KiB.
    $token = @file_get_contents($tokenPath, false, null, 0, 4097);
    if (!is_string($token) || $token === '' || strlen($token) > 4096
        || $token !== trim($token)) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $parts[1]) !== 1) {
        return null;
    }

    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    $claims = is_string($payload) ? json_decode($payload, true) : null;
    if (!is_array($claims)
        || ($claims['role'] ?? null) !== 'rezerwia_domain_lookup_runtime'
        || !is_string($claims['sub'] ?? null)
        || !is_int($claims['iat'] ?? null)
        || !is_int($claims['exp'] ?? null)
        || $claims['exp'] - $claims['iat'] !== 3600
        || $claims['iat'] > time() + 60
        || $claims['exp'] <= time() + 60) {
        return null;
    }

    // Kontrola claims lokalnie NIE zastępuje weryfikacji podpisu po stronie Supabase.
    return ['anon_key' => $anonKey, 'token' => $token];
}

function tenant_lookup_request(
    string $url,
    string $anonKey,
    string $accessToken,
    string $SCHEMA,
    string $host,
    ?callable $diagnosticLogger = null
): array
{
    static $requestCache = [];

    $attempts = 3;
    $requestCacheKey = hash(
        'sha256',
        $url . "\n" . $SCHEMA . "\n" . $host . "\n" . hash('sha256', $accessToken)
    );

    if (array_key_exists($requestCacheKey, $requestCache)) {
        return $requestCache[$requestCacheKey];
    }

    $lastResult = [
        'ok' => false,
        'retryable' => true,
        'response' => false,
        'curl_error' => '',
        'http_code' => 0,
        'data' => null,
        'json_valid' => false,
    ];

    $body = json_encode(['p_domain' => $host], JSON_THROW_ON_ERROR);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $anonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept-Profile: ' . $SCHEMA,
        'Content-Profile: ' . $SCHEMA,
    ];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $durationMs = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        curl_close($ch);

        if (function_exists('booking_supabase_request_record')) {
            booking_supabase_request_record('POST', $url, 'tenant_lookup', $httpCode);
        }

        $data = json_decode((string) $response, true);
        $jsonValid = json_last_error() === JSON_ERROR_NONE;
        $validRow = is_array($data) && count($data) === 1
            && array_is_list($data)
            && is_array($data[0])
            && count($data[0]) === 1
            && is_string($data[0]['tenant_id'] ?? null)
            && $data[0]['tenant_id'] !== '';
        $validPayload = is_array($data) && ($data === [] || $validRow);

        $retryable = $response === false
            || $curlError !== ''
            || $httpCode === 429
            || $httpCode >= 500
            || $httpCode === 0
            || ($httpCode >= 200 && $httpCode < 300 && !$validPayload);

        $lastResult = [
            'ok' => $response !== false
                && $curlError === ''
                && $httpCode === 200
                && $jsonValid
                && $validPayload,
            'retryable' => $retryable,
            'response' => $response,
            'curl_error' => $curlError,
            'http_code' => $httpCode,
            'data' => $validPayload ? $data : null,
            'json_valid' => $jsonValid,
        ];

        if ($retryable && $diagnosticLogger !== null) {
            try {
                $diagnosticLogger([
                    'stage' => 'tenant_lookup',
                    'method' => 'POST',
                    'endpoint' => '/rest/v1/rpc/rezerwia_domain_lookup_active',
                    'http_code' => $httpCode,
                    'curl_errno' => $curlErrno,
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'json_valid' => $jsonValid,
                    'duration_ms' => $durationMs,
                ]);
            } catch (Throwable $e) {
                // Diagnostyka nie może zmieniać przebiegu rozpoznawania tenanta.
            }
        }

        if ($lastResult['ok'] || !$retryable || $attempt === $attempts) {
            break;
        }

        usleep(tenant_lookup_retry_delay_us($attempt, $httpCode));
    }

    if (!empty($lastResult['ok'])) {
        $requestCache[$requestCacheKey] = $lastResult;
    }

    return $lastResult;
}

function getTenantLookupFromHost(
    string $SUPABASE_URL,
    string $SUPABASE_KEY,
    string $SCHEMA,
    ?callable $diagnosticLogger = null
): array
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

    // Argument SUPABASE_KEY pozostaje wyłącznie dla zgodności starszych wywołań.
    // Nie używamy go do autoryzacji ani nie przechodzimy awaryjnie na service_role.
    $credentials = tenant_lookup_runtime_credentials();
    if ($credentials === null) {
        tenant_debug_log('TENANT_RUNTIME_AUTH_UNAVAILABLE', ['available' => false]);
        return [
            'status' => 'technical_error',
            'http_code' => 503,
            'message' => 'Nie udało się potwierdzić domeny kalendarza.',
        ];
    }

    $persistentCacheKey = '';
    $requestCountBefore = function_exists('booking_supabase_request_count_for_stage')
        ? booking_supabase_request_count_for_stage('tenant_lookup')
        : 0;

    if (function_exists('booking_context_cache_key')) {
        $persistentCacheKey = booking_context_cache_key('tenant_lookup', [
            'hosts' => $hosts,
            'supabase_url' => rtrim($SUPABASE_URL, '/'),
            'schema' => $SCHEMA,
            'lookup_runtime' => 'domain-lookup-rpc-v1',
        ]);
        $cachedLookup = booking_context_cache_read('tenant_lookup', $persistentCacheKey);

        if (
            is_array($cachedLookup)
            && ($cachedLookup['status'] ?? '') === 'found'
            && !empty($cachedLookup['tenant_id'])
        ) {
            return $cachedLookup;
        }
    }

    $technicalError = null;

    foreach ($hosts as $host) {
        $url = rtrim($SUPABASE_URL, '/') . '/rest/v1/rpc/rezerwia_domain_lookup_active';

        $result = tenant_lookup_request(
            $url,
            $credentials['anon_key'],
            $credentials['token'],
            $SCHEMA,
            $host,
            $diagnosticLogger
        );
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

            $lookup = [
                'status' => 'found',
                'tenant_id' => (string) $data[0]['tenant_id'],
                'host' => $host,
            ];

            if ($persistentCacheKey !== '') {
                $requestCountAfter = function_exists('booking_supabase_request_count_for_stage')
                    ? booking_supabase_request_count_for_stage('tenant_lookup')
                    : $requestCountBefore + 1;
                booking_context_cache_write(
                    'tenant_lookup',
                    $persistentCacheKey,
                    $lookup,
                    booking_context_cache_default_ttl(),
                    max(1, $requestCountAfter - $requestCountBefore)
                );
            }

            return $lookup;
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
