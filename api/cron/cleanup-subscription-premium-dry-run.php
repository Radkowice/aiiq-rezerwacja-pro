<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function subscription_cleanup_cron_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_cleanup_cron_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0) {
                    return trim((string) $value);
                }
            }
        }
    }

    return '';
}

function subscription_cleanup_cron_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function subscription_cleanup_cron_request_secret(): string
{
    $headerSecret = subscription_cleanup_cron_header('X-Cron-Secret');

    if ($headerSecret !== '') {
        return $headerSecret;
    }

    $authorization = subscription_cleanup_cron_header('Authorization');

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function subscription_cleanup_cron_configured_secret(): string
{
    return trim((string) (
        getenv('SUBSCRIPTION_CLEANUP_CRON_SECRET')
        ?: getenv('CRON_SECRET')
        ?: ''
    ));
}

function subscription_cleanup_cron_rpc(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $rpcName,
    array $payload
): array {
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($encodedPayload === false) {
        throw new RuntimeException('Nie udało się zakodować żądania RPC.');
    }

    $url = rtrim($supabaseUrl, '/') . '/rest/v1/rpc/' . rawurlencode($rpcName);
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Nie udało się zainicjować żądania RPC.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $supabaseKey,
            'Authorization: Bearer ' . $supabaseKey,
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
        ],
        CURLOPT_POSTFIELDS => $encodedPayload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlError !== 0 || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Żądanie RPC nie powiodło się.');
    }

    $decoded = json_decode((string) $raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new RuntimeException('Odpowiedź RPC ma nieprawidłowy format.');
    }

    return $decoded;
}

function subscription_cleanup_cron_is_non_negative_number($value): bool
{
    if (is_int($value)) {
        return $value >= 0;
    }

    return is_float($value) && is_finite($value) && $value >= 0;
}

function subscription_cleanup_cron_claim_is_valid(array $claim): bool
{
    return ($claim['success'] ?? false) === true
        && ($claim['mode'] ?? '') === 'dry_run'
        && array_key_exists('claimed', $claim)
        && subscription_cleanup_cron_is_non_negative_number($claim['claimed']);
}

function subscription_cleanup_cron_process_is_valid(array $process): bool
{
    foreach (['processed', 'succeeded', 'skipped', 'failed'] as $field) {
        if (
            !array_key_exists($field, $process)
            || !subscription_cleanup_cron_is_non_negative_number($process[$field])
        ) {
            return false;
        }
    }

    return ($process['success'] ?? false) === true
        && ($process['dry_run'] ?? false) === true
        && isset($process['counters'])
        && is_array($process['counters']);
}

function subscription_cleanup_cron_public_counters(array $counters): array
{
    $publicCounters = [];

    foreach ($counters as $name => $value) {
        if (
            !is_string($name)
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)
            || !subscription_cleanup_cron_is_non_negative_number($value)
            || preg_match('/(^|_)(run_ids?|tenant_ids?|token|secret|email|phone|person)(_|$)/', $name)
        ) {
            continue;
        }

        $publicCounters[$name] = $value;
    }

    return $publicCounters;
}

try {
    $isCli = subscription_cleanup_cron_is_cli();

    if (!$isCli && !in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        subscription_cleanup_cron_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    if (!$isCli) {
        $cronSecret = subscription_cleanup_cron_configured_secret();

        if ($cronSecret === '') {
            subscription_cleanup_cron_json(500, [
                'success' => false,
                'error' => 'Brak konfiguracji crona.',
            ]);
        }

        $requestSecret = subscription_cleanup_cron_request_secret();

        if ($requestSecret === '' || !hash_equals($cronSecret, $requestSecret)) {
            subscription_cleanup_cron_json(401, [
                'success' => false,
                'error' => 'unauthorized',
            ]);
        }
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_cleanup_cron_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji Supabase.',
        ]);
    }

    $claim = subscription_cleanup_cron_rpc(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        'subscription_cleanup_claim',
        [
            'p_mode' => 'dry_run',
            'p_policy_version' => 'premium_cleanup_v1',
            'p_limit' => 50,
            'p_lease_minutes' => 15,
        ]
    );

    if (!subscription_cleanup_cron_claim_is_valid($claim)) {
        subscription_cleanup_cron_json(500, [
            'success' => false,
            'error' => 'Nie udało się zarezerwować zadań dry-run cleanupu danych premium.',
        ]);
    }

    $process = subscription_cleanup_cron_rpc(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        'subscription_cleanup_process_dry_run',
        [
            'p_limit' => 50,
        ]
    );

    if (!subscription_cleanup_cron_process_is_valid($process)) {
        subscription_cleanup_cron_json(500, [
            'success' => false,
            'error' => 'Nie udało się przetworzyć dry-run cleanupu danych premium.',
        ]);
    }

    $recoveredExpiredLeases = subscription_cleanup_cron_is_non_negative_number(
        $claim['recovered_expired_leases'] ?? null
    ) ? $claim['recovered_expired_leases'] : 0;
    $businessDate = is_string($claim['business_date'] ?? null)
        ? trim($claim['business_date'])
        : '';
    $publicCounters = subscription_cleanup_cron_public_counters($process['counters']);

    subscription_cleanup_cron_json(200, [
        'success' => true,
        'dry_run' => true,
        'claim' => [
            'claimed' => $claim['claimed'],
            'recovered_expired_leases' => $recoveredExpiredLeases,
            'business_date' => $businessDate,
            'policy_version' => 'premium_cleanup_v1',
        ],
        'process' => [
            'processed' => $process['processed'],
            'succeeded' => $process['succeeded'],
            'skipped' => $process['skipped'],
            'failed' => $process['failed'],
            'counters' => (object) $publicCounters,
        ],
    ]);
} catch (Throwable $e) {
    subscription_cleanup_cron_json(500, [
        'success' => false,
        'error' => 'Błąd crona dry-run cleanupu danych premium.',
    ]);
}
