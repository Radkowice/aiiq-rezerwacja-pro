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

    // gdy proxy poda kilka hostów po przecinku
    if (strpos($host, ',') !== false) {
        $parts = array_map('trim', explode(',', $host));
        $host = $parts[0] ?? '';
    }

    // usuń port
    $host = preg_replace('/:\d+$/', '', $host);

    // usuń końcową kropkę
    $host = rtrim($host, '.');

    return $host;
}

function host_candidates(): array
{
    $rawHosts = [
        $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '',
        $_SERVER['HTTP_HOST'] ?? '',
        $_SERVER['SERVER_NAME'] ?? '',
    ];

    $candidates = [];

    foreach ($rawHosts as $raw) {
        $host = normalize_host($raw);

        if ($host === '') {
            continue;
        }

        $candidates[] = $host;

        if (str_starts_with($host, 'www.')) {
            $candidates[] = substr($host, 4);
        } else {
            $candidates[] = 'www.' . $host;
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates)));

    tenant_debug_log('HOST_CANDIDATES', [
        'HTTP_X_FORWARDED_HOST' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null,
        'HTTP_HOST'             => $_SERVER['HTTP_HOST'] ?? null,
        'SERVER_NAME'           => $_SERVER['SERVER_NAME'] ?? null,
        'candidates'            => $candidates,
    ]);

    return $candidates;
}

function getTenantIdFromHost(string $SUPABASE_URL, string $SUPABASE_KEY, string $SCHEMA): ?string
{

    $hosts = host_candidates();

    if (empty($hosts)) {
        tenant_debug_log('TENANT_ERROR', 'Brak hosta w $_SERVER');
        return null;
    }

    foreach ($hosts as $host) {
        $url = rtrim($SUPABASE_URL, '/') . '/rest/v1/tenant_domains'
            . '?select=tenant_id,domain,is_active'
            . '&domain=eq.' . rawurlencode($host)
            . '&is_active=eq.true'
            . '&limit=1';

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

        $decodedResponse = json_decode((string) $response, true);
        $responseCount = is_array($decodedResponse) ? count($decodedResponse) : 0;

        tenant_debug_log('TENANT_LOOKUP', [
            'host' => $host,
            'httpCode' => $httpCode,
            'has_error' => $curlError !== '',
            'found' => is_array($decodedResponse) && !empty($decodedResponse[0]['tenant_id']),
            'response_count' => $responseCount,
        ]);


        if ($response === false || $curlError) {
            continue;
        }

        if ($httpCode >= 400) {
            continue;
        }

        $data = json_decode($response, true);

        if (is_array($data) && !empty($data[0]['tenant_id'])) {
            tenant_debug_log('TENANT_FOUND', [
                'host' => $host,
                'tenant_id' => $data[0]['tenant_id'],
                'matched_domain' => $data[0]['domain'] ?? null,
                'found' => true,
            ]);

            return $data[0]['tenant_id'];
        }
    }

    tenant_debug_log('TENANT_NOT_FOUND', [
        'checked_hosts' => $hosts,
        'found' => false,
    ]);

    return null;
}
