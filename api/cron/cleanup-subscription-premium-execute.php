<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function subscription_cleanup_execute_cron_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_cleanup_execute_cron_header(string $name): string
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

function subscription_cleanup_execute_cron_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function subscription_cleanup_execute_cron_request_secret(): string
{
    $headerSecret = subscription_cleanup_execute_cron_header('X-Cron-Secret');

    if ($headerSecret !== '') {
        return $headerSecret;
    }

    $authorization = subscription_cleanup_execute_cron_header('Authorization');

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function subscription_cleanup_execute_cron_configured_secret(): string
{
    return trim((string) (
        getenv('SUBSCRIPTION_CLEANUP_CRON_SECRET')
        ?: getenv('CRON_SECRET')
        ?: ''
    ));
}

function subscription_cleanup_execute_cron_is_list(array $value): bool
{
    $expectedKey = 0;

    foreach ($value as $key => $_value) {
        if ($key !== $expectedKey) {
            return false;
        }

        $expectedKey++;
    }

    return true;
}

function subscription_cleanup_execute_cron_rpc(
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

    $options = [
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
        CURLOPT_TIMEOUT => 60,
    ];

    if (!curl_setopt_array($ch, $options)) {
        curl_close($ch);
        throw new RuntimeException('Nie udało się skonfigurować żądania RPC.');
    }

    $raw = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($raw === false || $curlErrno !== 0 || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Żądanie RPC nie powiodło się.');
    }

    $decoded = json_decode((string) $raw, true);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_array($decoded)
        || subscription_cleanup_execute_cron_is_list($decoded)
    ) {
        throw new RuntimeException('Odpowiedź RPC ma nieprawidłowy format.');
    }

    return $decoded;
}

function subscription_cleanup_execute_cron_is_non_negative_integer($value): bool
{
    return is_int($value) && $value >= 0;
}

function subscription_cleanup_execute_cron_is_uuid(string $value): bool
{
    return preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
        $value
    ) === 1;
}

function subscription_cleanup_execute_cron_is_business_date($value): bool
{
    if (!is_string($value)) {
        return false;
    }

    if ($value === '') {
        return true;
    }

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

function subscription_cleanup_execute_cron_claim_is_valid(array $claim): bool
{
    if (
        ($claim['success'] ?? false) !== true
        || ($claim['mode'] ?? '') !== 'execute'
        || ($claim['policy_version'] ?? '') !== 'premium_cleanup_v1'
        || !subscription_cleanup_execute_cron_is_non_negative_integer($claim['claimed'] ?? null)
        || !subscription_cleanup_execute_cron_is_non_negative_integer(
            $claim['recovered_expired_leases'] ?? null
        )
        || !subscription_cleanup_execute_cron_is_business_date($claim['business_date'] ?? null)
        || !is_array($claim['run_ids'] ?? null)
        || !subscription_cleanup_execute_cron_is_list($claim['run_ids'])
        || count($claim['run_ids']) !== $claim['claimed']
    ) {
        return false;
    }

    foreach ($claim['run_ids'] as $runId) {
        if (!is_string($runId) || !subscription_cleanup_execute_cron_is_uuid($runId)) {
            return false;
        }
    }

    return true;
}

function subscription_cleanup_execute_cron_process_is_valid(
    array $process,
    int $claimed,
    int $recoveredExpiredLeases
): bool {
    foreach (['processed', 'succeeded', 'skipped', 'failed'] as $field) {
        if (!subscription_cleanup_execute_cron_is_non_negative_integer($process[$field] ?? null)) {
            return false;
        }
    }

    if (
        ($process['success'] ?? false) !== true
        || ($process['execute'] ?? false) !== true
        || ($process['policy_version'] ?? '') !== 'premium_cleanup_v1'
        || !is_array($process['counters'] ?? null)
    ) {
        return false;
    }

    $processed = $process['processed'];
    $resultTotal = $process['succeeded'] + $process['skipped'] + $process['failed'];

    return $processed === $resultTotal
        && $processed <= 10
        && $processed <= $claimed + $recoveredExpiredLeases;
}

function subscription_cleanup_execute_cron_public_counters(array $counters): array
{
    $publicCounters = [];
    $sensitiveFragments = [
        'run_id',
        'tenant_id',
        'token',
        'secret',
        'email',
        'phone',
        'person',
    ];

    foreach ($counters as $name => $value) {
        if (
            !is_string($name)
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)
            || !subscription_cleanup_execute_cron_is_non_negative_integer($value)
        ) {
            continue;
        }

        $sensitive = false;

        foreach ($sensitiveFragments as $fragment) {
            if (str_contains($name, $fragment)) {
                $sensitive = true;
                break;
            }
        }

        if ($sensitive) {
            continue;
        }

        $publicCounters[$name] = $value;
    }

    return $publicCounters;
}

try {
    $isCli = subscription_cleanup_execute_cron_is_cli();

    if (!$isCli && !in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        subscription_cleanup_execute_cron_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    if (!$isCli) {
        $cronSecret = subscription_cleanup_execute_cron_configured_secret();

        if ($cronSecret === '') {
            subscription_cleanup_execute_cron_json(500, [
                'success' => false,
                'error' => 'Brak konfiguracji crona.',
            ]);
        }

        $requestSecret = subscription_cleanup_execute_cron_request_secret();

        if ($requestSecret === '' || !hash_equals($cronSecret, $requestSecret)) {
            subscription_cleanup_execute_cron_json(401, [
                'success' => false,
                'error' => 'unauthorized',
            ]);
        }
    }

    $supabaseUrl = rtrim(trim((string) getenv('SUPABASE_URL')), '/');
    $supabaseKey = trim((string) (
        getenv('SUPABASE_SERVICE_ROLE_KEY')
        ?: getenv('SUPABASE_KEY')
        ?: ''
    ));
    $schema = trim((string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro'));

    if ($supabaseUrl === '' || $supabaseKey === '' || $schema === '') {
        subscription_cleanup_execute_cron_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji Supabase.',
        ]);
    }

    try {
        $claim = subscription_cleanup_execute_cron_rpc(
            $supabaseUrl,
            $supabaseKey,
            $schema,
            'subscription_cleanup_claim',
            [
                'p_mode' => 'execute',
                'p_policy_version' => 'premium_cleanup_v1',
                'p_limit' => 10,
                'p_lease_minutes' => 15,
            ]
        );
    } catch (Throwable $e) {
        subscription_cleanup_execute_cron_json(500, [
            'success' => false,
            'error' => 'Nie udało się zarezerwować zadań execute cleanupu danych premium.',
        ]);
    }

    if (!subscription_cleanup_execute_cron_claim_is_valid($claim)) {
        subscription_cleanup_execute_cron_json(500, [
            'success' => false,
            'error' => 'Nie udało się zarezerwować zadań execute cleanupu danych premium.',
        ]);
    }

    try {
        $process = subscription_cleanup_execute_cron_rpc(
            $supabaseUrl,
            $supabaseKey,
            $schema,
            'subscription_cleanup_process_execute',
            [
                'p_limit' => 10,
            ]
        );
    } catch (Throwable $e) {
        subscription_cleanup_execute_cron_json(500, [
            'success' => false,
            'error' => 'Nie udało się wykonać cleanupu danych premium.',
        ]);
    }

    if (!subscription_cleanup_execute_cron_process_is_valid(
        $process,
        $claim['claimed'],
        $claim['recovered_expired_leases']
    )) {
        subscription_cleanup_execute_cron_json(500, [
            'success' => false,
            'error' => 'Nie udało się wykonać cleanupu danych premium.',
        ]);
    }

    $publicCounters = subscription_cleanup_execute_cron_public_counters($process['counters']);

    subscription_cleanup_execute_cron_json(200, [
        'success' => true,
        'execute' => true,
        'claim' => [
            'claimed' => $claim['claimed'],
            'recovered_expired_leases' => $claim['recovered_expired_leases'],
            'business_date' => $claim['business_date'],
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
    subscription_cleanup_execute_cron_json(500, [
        'success' => false,
        'error' => 'Błąd crona execute cleanupu danych premium.',
    ]);
}
