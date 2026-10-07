<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function company_lookup_cache_dir(): string
{
    $apiRoot = dirname(__DIR__);
    $applicationRoot = dirname($apiRoot);

    return $applicationRoot . '/data/cache/company-lookup';
}

function company_lookup_positive_cache_ttl(): int
{
    return 900;
}

function company_lookup_max_provider_body_bytes(): int
{
    return 65536;
}

function company_lookup_normalize_nip(string $value): string
{
    $value = trim($value);

    if ($value === '' || strlen($value) > 40) {
        return '';
    }

    if (preg_match('/^[0-9\s-]+$/', $value) !== 1) {
        return '';
    }

    return preg_replace('/\D+/', '', $value) ?? '';
}

function company_lookup_is_valid_polish_nip(string $value): bool
{
    $nip = company_lookup_normalize_nip($value);

    if (preg_match('/^[0-9]{10}$/', $nip) !== 1) {
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

function company_lookup_clean_text($value, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $cleaned = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

    if (!is_string($cleaned)) {
        return '';
    }

    $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned));

    if (!is_string($cleaned)) {
        return '';
    }

    $maxLength = max(1, $maxLength);

    if (function_exists('mb_substr')) {
        return mb_substr($cleaned, 0, $maxLength, 'UTF-8');
    }

    return substr($cleaned, 0, $maxLength);
}

function company_lookup_cache_ready(): bool
{
    $dir = company_lookup_cache_dir();

    if (
        !is_dir($dir)
        || is_link($dir)
        || !is_readable($dir)
        || !is_writable($dir)
    ) {
        return false;
    }

    return realpath($dir) !== false;
}

function company_lookup_cache_key(string $nip): ?string
{
    try {
        $hash = security_hash_value(
            $nip,
            'company_lookup_cache_nip_v1'
        );
    } catch (Throwable $e) {
        return null;
    }

    if (
        !is_string($hash)
        || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
    ) {
        return null;
    }

    return $hash;
}

function company_lookup_cache_file(string $cacheKey): ?string
{
    if (preg_match('/^[a-f0-9]{64}$/', $cacheKey) !== 1) {
        return null;
    }

    return company_lookup_cache_dir()
        . DIRECTORY_SEPARATOR
        . $cacheKey
        . '.json';
}

function company_lookup_cache_read(string $cacheKey): ?array
{
    if (!company_lookup_cache_ready()) {
        return null;
    }

    $file = company_lookup_cache_file($cacheKey);

    if (
        $file === null
        || !is_file($file)
        || is_link($file)
    ) {
        return null;
    }

    $size = @filesize($file);

    if (
        !is_int($size)
        || $size < 1
        || $size > 32768
    ) {
        return null;
    }

    $raw = @file_get_contents($file);

    if (!is_string($raw) || $raw === '') {
        return null;
    }

    try {
        $decoded = json_decode(
            $raw,
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {
        return null;
    }

    if (
        !is_array($decoded)
        || ($decoded['version'] ?? null) !== 1
        || ($decoded['state'] ?? null) !== 'found'
        || !isset($decoded['expires_at'])
        || !is_int($decoded['expires_at'])
        || !isset($decoded['data'])
        || !is_array($decoded['data'])
    ) {
        return null;
    }

    if ($decoded['expires_at'] < time()) {
        @unlink($file);
        return null;
    }

    return $decoded['data'];
}

function company_lookup_cache_write(
    string $cacheKey,
    array $data
): bool {
    if (!company_lookup_cache_ready()) {
        return false;
    }

    $target = company_lookup_cache_file($cacheKey);

    if ($target === null || is_link($target)) {
        return false;
    }

    $payload = [
        'version' => 1,
        'state' => 'found',
        'expires_at' => time() + company_lookup_positive_cache_ttl(),
        'data' => $data,
    ];

    try {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {
        return false;
    }

    if (
        !is_string($json)
        || strlen($json) > 32768
    ) {
        return false;
    }

    $temporary = @tempnam(
        company_lookup_cache_dir(),
        '.tmp-'
    );

    if (!is_string($temporary) || $temporary === '') {
        return false;
    }

    if (is_link($temporary)) {
        @unlink($temporary);
        return false;
    }

    @chmod($temporary, 0660);

    $written = @file_put_contents(
        $temporary,
        $json,
        LOCK_EX
    );

    if (
        $written === false
        || $written !== strlen($json)
    ) {
        @unlink($temporary);
        return false;
    }

    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        return false;
    }

    @chmod($target, 0660);

    return true;
}

function company_lookup_cache_acquire_lock(
    string $cacheKey,
    int $timeoutMs = 2000
) {
    if (!company_lookup_cache_ready()) {
        return null;
    }

    if (preg_match('/^[a-f0-9]{64}$/', $cacheKey) !== 1) {
        return null;
    }

    $lockFile = company_lookup_cache_dir()
        . DIRECTORY_SEPARATOR
        . $cacheKey
        . '.lock';

    if (is_link($lockFile)) {
        return null;
    }

    $handle = @fopen($lockFile, 'c');

    if (!is_resource($handle)) {
        return null;
    }

    @chmod($lockFile, 0660);

    $deadline = microtime(true)
        + (max(1, min($timeoutMs, 5000)) / 1000);

    do {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            @touch($lockFile);
            return $handle;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    @fclose($handle);

    return null;
}

function company_lookup_cache_release_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function company_lookup_cache_maybe_cleanup(): void
{
    static $checked = false;

    if ($checked || !company_lookup_cache_ready()) {
        return;
    }

    $checked = true;

    try {
        if (random_int(1, 100) !== 1) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $threshold = time() - 86400;

    try {
        $iterator = new FilesystemIterator(
            company_lookup_cache_dir(),
            FilesystemIterator::SKIP_DOTS
        );
    } catch (Throwable $e) {
        return;
    }

    $checkedFiles = 0;

    foreach ($iterator as $fileInfo) {
        if (++$checkedFiles > 300) {
            break;
        }

        if ($fileInfo->isLink()) {
            continue;
        }

        $name = $fileInfo->getFilename();

        if (
            preg_match(
                '/^[a-f0-9]{64}\.(?:json|lock)$/',
                $name
            ) !== 1
        ) {
            continue;
        }

        $modifiedAt = $fileInfo->getMTime();

        if ($modifiedAt >= $threshold) {
            continue;
        }

        $path = $fileInfo->getPathname();

        if (str_ends_with($name, '.json')) {
            @unlink($path);
            continue;
        }

        $handle = @fopen($path, 'c');

        if (!is_resource($handle)) {
            continue;
        }

        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            clearstatcache(true, $path);
            $currentMtime = @filemtime($path);

            if (
                is_int($currentMtime)
                && $currentMtime < $threshold
            ) {
                @unlink($path);
            }

            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }
}

function company_lookup_provider_budget_check(
    ?string $requestId = null
): array {
    try {
        $scopeHash = security_hash_value(
            'provider=skanfirmy|operation=regon_lookup|version=1',
            'company_lookup_provider_budget_scope'
        );

        if (
            !is_string($scopeHash)
            || preg_match('/^[a-f0-9]{64}$/', $scopeHash) !== 1
        ) {
            throw new RuntimeException(
                'Invalid provider budget scope hash.'
            );
        }

        $payload = [
            'p_action_key' => 'company_lookup_provider_budget',
            'p_scope_hash' => $scopeHash,
            'p_scope_type' => 'application_composite',
            'p_ip_address' => null,
            'p_ip_hash' => null,
            'p_email_hash' => null,
            'p_tenant_hash' => null,
            'p_user_hash' => null,
            'p_user_agent_hash' => null,
            'p_endpoint' => '/api/auth/company-lookup.php',
            'p_http_method' => 'POST',
            'p_request_id' => $requestId !== null
                ? substr(trim($requestId), 0, 100)
                : null,
            'p_session_hash' => null,
            'p_metadata' => [
                'provider' => 'skanfirmy',
                'operation' => 'regon_lookup',
            ],
        ];

        $rpcResult = security_supabase_rpc(
            'security_rate_limit_check',
            $payload
        );

        if (empty($rpcResult['ok'])) {
            return [
                'ok' => false,
                'allowed' => false,
                'retry_after_seconds' => null,
                'error' => 'rate_limit_unavailable',
            ];
        }

        $raw = $rpcResult['data'];

        $data = (
            is_array($raw)
            && isset($raw[0])
            && is_array($raw[0])
        )
            ? $raw[0]
            : (is_array($raw) ? $raw : []);

        if (($data['rule_found'] ?? false) !== true) {
            return [
                'ok' => false,
                'allowed' => false,
                'retry_after_seconds' => null,
                'error' => 'rate_limit_rule_missing',
            ];
        }

        return [
            'ok' => true,
            'allowed' => ($data['allowed'] ?? false) === true,
            'retry_after_seconds' => (
                isset($data['retry_after_seconds'])
                && is_numeric($data['retry_after_seconds'])
            )
                ? max(0, (int) $data['retry_after_seconds'])
                : null,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'allowed' => false,
            'retry_after_seconds' => null,
            'error' => 'rate_limit_error',
        ];
    }
}

function company_lookup_ip_is_public(string $ip): bool
{
    $ip = trim($ip);

    if (
        filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE
            | FILTER_FLAG_NO_RES_RANGE
        ) === false
    ) {
        return false;
    }

    if (
        filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4
        ) !== false
    ) {
        $packed = @inet_pton($ip);

        if (!is_string($packed) || strlen($packed) !== 4) {
            return false;
        }

        $parts = unpack('Naddress', $packed);

        if (
            !is_array($parts)
            || !isset($parts['address'])
        ) {
            return false;
        }

        $address = (int) $parts['address'];

        $cgnatStart = (100 << 24) | (64 << 16);
        $cgnatEnd = (100 << 24) | (127 << 16) | 0xFFFF;

        if (
            $address >= $cgnatStart
            && $address <= $cgnatEnd
        ) {
            return false;
        }
    }

    return true;
}

function company_lookup_resolve_public_ip(
    string $host
): ?string {
    if ($host !== 'skanfirmy.pl') {
        return null;
    }

    if (!function_exists('dns_get_record')) {
        return null;
    }

    $records = @dns_get_record(
        $host,
        DNS_A | DNS_AAAA
    );

    if (!is_array($records)) {
        return null;
    }

    $addresses = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $ip = '';

        if (
            isset($record['ip'])
            && is_string($record['ip'])
        ) {
            $ip = trim($record['ip']);
        } elseif (
            isset($record['ipv6'])
            && is_string($record['ipv6'])
        ) {
            $ip = trim($record['ipv6']);
        }

        if (
            $ip !== ''
            && company_lookup_ip_is_public($ip)
        ) {
            $addresses[$ip] = true;
        }
    }

    if (!$addresses) {
        return null;
    }

    $addresses = array_keys($addresses);

    sort($addresses, SORT_STRING);

    return $addresses[0] ?? null;
}

function company_lookup_normalize_provider_payload(
    array $payload,
    string $expectedNip
): ?array {
    $dane = $payload['dane'] ?? null;

    if (!is_array($dane)) {
        return null;
    }

    $topLevelNip = company_lookup_normalize_nip(
        is_string($payload['nip'] ?? null)
            ? $payload['nip']
            : ''
    );

    $dataNip = company_lookup_normalize_nip(
        is_string($dane['nip'] ?? null)
            ? $dane['nip']
            : ''
    );

    if (
        $topLevelNip === ''
        && $dataNip === ''
    ) {
        return null;
    }

    if (
        $topLevelNip !== ''
        && !hash_equals($expectedNip, $topLevelNip)
    ) {
        return null;
    }

    if (
        $dataNip !== ''
        && !hash_equals($expectedNip, $dataNip)
    ) {
        return null;
    }

    $legalName = company_lookup_clean_text(
        $dane['nazwa'] ?? '',
        255
    );

    if ($legalName === '') {
        return null;
    }

    $regon = preg_replace(
        '/\D+/',
        '',
        is_string($dane['regon'] ?? null)
            ? $dane['regon']
            : ''
    ) ?? '';

    if (
        $regon !== ''
        && preg_match('/^[0-9]{9}(?:[0-9]{5})?$/', $regon) !== 1
    ) {
        $regon = '';
    }

    $streetName = company_lookup_clean_text(
        $dane['ulica'] ?? '',
        160
    );

    $buildingNumber = company_lookup_clean_text(
        $dane['nrNieruchomosci'] ?? '',
        40
    );

    $unitNumber = company_lookup_clean_text(
        $dane['nrLokalu'] ?? '',
        40
    );

    $city = company_lookup_clean_text(
        $dane['miejscowosc'] ?? '',
        120
    );

    $postalCode = company_lookup_clean_text(
        $dane['kodPocztowy'] ?? '',
        10
    );

    if (
        $postalCode !== ''
        && preg_match('/^[0-9]{2}-[0-9]{3}$/', $postalCode) !== 1
    ) {
        $postalCode = '';
    }

    $type = strtoupper(
        company_lookup_clean_text(
            $dane['typ'] ?? '',
            4
        )
    );

    $street = trim(
        implode(
            ' ',
            array_values(
                array_filter(
                    [$streetName, $buildingNumber],
                    static fn (string $value): bool => $value !== ''
                )
            )
        )
    );

    if ($street !== '' && $unitNumber !== '') {
        $street .= ' lok. ' . $unitNumber;
    }

    $addressComplete = (
        $street !== ''
        && $postalCode !== ''
        && $city !== ''
    );

    return [
        'legal_name' => $legalName,
        'regon' => $regon,
        'street' => $street,
        'postal_code' => $postalCode,
        'city' => $city,
        'company_type' => $type,
        'address_complete' => $addressComplete,
    ];
}

function company_lookup_provider_request(
    string $nip
): array {
    if (!company_lookup_is_valid_polish_nip($nip)) {
        return [
            'ok' => false,
            'state' => 'invalid_nip',
            'data' => null,
            'error' => 'invalid_nip',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'curl_unavailable',
        ];
    }

    $host = 'skanfirmy.pl';
    $resolvedIp = company_lookup_resolve_public_ip($host);

    if ($resolvedIp === null) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_dns_unavailable',
        ];
    }

    $url = 'https://'
        . $host
        . '/regon/'
        . rawurlencode($nip)
        . '?format=json';

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'curl_init_error',
        ];
    }

    $body = '';
    $responseHeaders = [];
    $tooLarge = false;

    $resolveTarget = str_contains($resolvedIp, ':')
        ? '[' . $resolvedIp . ']'
        : $resolvedIp;

    $options = [
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_USERAGENT => 'Rezerwia-Company-Lookup/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Cache-Control: no-cache',
        ],
        CURLOPT_RESOLVE => [
            $host . ':443:' . $resolveTarget,
        ],
        CURLOPT_WRITEFUNCTION => static function (
            $curl,
            string $chunk
        ) use (&$body, &$tooLarge): int {
            $chunkLength = strlen($chunk);

            if (
                strlen($body) + $chunkLength
                > company_lookup_max_provider_body_bytes()
            ) {
                $tooLarge = true;
                return 0;
            }

            $body .= $chunk;

            return $chunkLength;
        },
        CURLOPT_HEADERFUNCTION => static function (
            $curl,
            string $header
        ) use (&$responseHeaders): int {
            $length = strlen($header);
            $header = trim($header);

            if (
                $header === ''
                || strpos($header, ':') === false
            ) {
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

    if (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    if (defined('CURLOPT_REDIR_PROTOCOLS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }

    if (defined('CURLOPT_PROXY')) {
        $options[CURLOPT_PROXY] = '';
    }

    if (defined('CURLOPT_NOPROXY')) {
        $options[CURLOPT_NOPROXY] = '*';
    }

    if (
        defined('CURLOPT_SSLVERSION')
        && defined('CURL_SSLVERSION_TLSv1_2')
    ) {
        $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
    }

    curl_setopt_array($ch, $options);

    $executed = curl_exec($ch);
    $httpCode = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );
    $effectiveUrl = (string) curl_getinfo(
        $ch,
        CURLINFO_EFFECTIVE_URL
    );
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($tooLarge) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_response_too_large',
        ];
    }

    if ($executed === false || $curlError !== '') {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_transport_failure',
        ];
    }

    $effectiveParts = parse_url($effectiveUrl);

    if (
        !is_array($effectiveParts)
        || strtolower((string) ($effectiveParts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($effectiveParts['host'] ?? '')) !== $host
        || (string) ($effectiveParts['path'] ?? '') !== '/regon/' . $nip
        || (string) ($effectiveParts['query'] ?? '') !== 'format=json'
    ) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_destination_mismatch',
        ];
    }

    if ($httpCode === 404) {
        return [
            'ok' => true,
            'state' => 'not_found',
            'data' => null,
            'error' => '',
        ];
    }

    if ($httpCode === 400) {
        return [
            'ok' => false,
            'state' => 'invalid_nip',
            'data' => null,
            'error' => 'provider_rejected_nip',
        ];
    }

    if ($httpCode === 429) {
        return [
            'ok' => false,
            'state' => 'rate_limited',
            'data' => null,
            'error' => 'provider_rate_limited',
        ];
    }

    if ($httpCode !== 200) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_http_error',
        ];
    }

    $contentType = strtolower(
        trim((string) ($responseHeaders['content-type'] ?? ''))
    );

    if (
        $contentType === ''
        || !str_starts_with(
            $contentType,
            'application/json'
        )
    ) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_content_type_invalid',
        ];
    }

    if ($body === '') {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_empty_response',
        ];
    }

    try {
        $decoded = json_decode(
            $body,
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_invalid_json',
        ];
    }

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_invalid_payload',
        ];
    }

    $normalized = company_lookup_normalize_provider_payload(
        $decoded,
        $nip
    );

    if ($normalized === null) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'error' => 'provider_schema_mismatch',
        ];
    }

    return [
        'ok' => true,
        'state' => 'found',
        'data' => $normalized,
        'error' => '',
    ];
}

function company_lookup_find(
    string $rawNip,
    ?string $requestId = null
): array {
    $nip = company_lookup_normalize_nip($rawNip);

    if (!company_lookup_is_valid_polish_nip($nip)) {
        return [
            'ok' => false,
            'state' => 'invalid_nip',
            'data' => null,
            'retry_after_seconds' => null,
            'source' => 'local',
            'error' => 'invalid_nip',
        ];
    }

    $cacheKey = company_lookup_cache_key($nip);

    if ($cacheKey === null) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'data' => null,
            'retry_after_seconds' => null,
            'source' => 'local',
            'error' => 'cache_key_unavailable',
        ];
    }

    company_lookup_cache_maybe_cleanup();

    $cached = company_lookup_cache_read($cacheKey);

    if (is_array($cached)) {
        return [
            'ok' => true,
            'state' => 'found',
            'data' => $cached,
            'retry_after_seconds' => null,
            'source' => 'cache',
            'error' => '',
        ];
    }

    $lock = company_lookup_cache_acquire_lock(
        $cacheKey,
        2000
    );

    if (!is_resource($lock)) {
        return [
            'ok' => false,
            'state' => 'busy',
            'data' => null,
            'retry_after_seconds' => 2,
            'source' => 'local',
            'error' => 'lookup_lock_unavailable',
        ];
    }

    try {
        $cached = company_lookup_cache_read($cacheKey);

        if (is_array($cached)) {
            return [
                'ok' => true,
                'state' => 'found',
                'data' => $cached,
                'retry_after_seconds' => null,
                'source' => 'cache',
                'error' => '',
            ];
        }

        $budget = company_lookup_provider_budget_check(
            $requestId
        );

        if (empty($budget['ok'])) {
            return [
                'ok' => false,
                'state' => 'unavailable',
                'data' => null,
                'retry_after_seconds' => null,
                'source' => 'local',
                'error' => 'provider_budget_unavailable',
            ];
        }

        if (empty($budget['allowed'])) {
            return [
                'ok' => false,
                'state' => 'rate_limited',
                'data' => null,
                'retry_after_seconds' => (
                    isset($budget['retry_after_seconds'])
                    && is_numeric($budget['retry_after_seconds'])
                )
                    ? max(
                        0,
                        (int) $budget['retry_after_seconds']
                    )
                    : null,
                'source' => 'local',
                'error' => 'provider_budget_exhausted',
            ];
        }

        $providerResult = company_lookup_provider_request(
            $nip
        );

        if (
            !empty($providerResult['ok'])
            && ($providerResult['state'] ?? '') === 'found'
            && is_array($providerResult['data'] ?? null)
        ) {
            company_lookup_cache_write(
                $cacheKey,
                $providerResult['data']
            );
        }

        return [
            'ok' => !empty($providerResult['ok']),
            'state' => (string) (
                $providerResult['state']
                ?? 'unavailable'
            ),
            'data' => is_array(
                $providerResult['data'] ?? null
            )
                ? $providerResult['data']
                : null,
            'retry_after_seconds' => null,
            'source' => 'provider',
            'error' => (string) (
                $providerResult['error']
                ?? 'provider_error'
            ),
        ];
    } finally {
        company_lookup_cache_release_lock($lock);
    }
}
