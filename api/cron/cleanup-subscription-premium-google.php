<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/google_calendar.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function subscription_cleanup_google_cron_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_cleanup_google_cron_header(string $name): string
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

function subscription_cleanup_google_cron_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function subscription_cleanup_google_cron_request_secret(): string
{
    $headerSecret = subscription_cleanup_google_cron_header('X-Cron-Secret');

    if ($headerSecret !== '') {
        return $headerSecret;
    }

    $authorization = subscription_cleanup_google_cron_header('Authorization');

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function subscription_cleanup_google_cron_configured_secret(): string
{
    return trim((string) (
        getenv('SUBSCRIPTION_CLEANUP_CRON_SECRET')
        ?: getenv('CRON_SECRET')
        ?: ''
    ));
}

function subscription_cleanup_google_cron_rpc(
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
        CURLOPT_TIMEOUT => 30,
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

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new RuntimeException('Odpowiedź RPC ma nieprawidłowy format.');
    }

    return $decoded;
}

function subscription_cleanup_google_cron_is_non_negative_number($value): bool
{
    if (is_int($value)) {
        return $value >= 0;
    }

    return is_float($value) && is_finite($value) && $value >= 0;
}

function subscription_cleanup_google_cron_is_list(array $value): bool
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

function subscription_cleanup_google_cron_is_uuid(string $value): bool
{
    return preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
        $value
    ) === 1;
}

function subscription_cleanup_google_cron_tenant_id($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $tenantId = trim($value);

    if (
        $tenantId === ''
        || strlen($tenantId) > 128
        || preg_match('/[\x00-\x1F\x7F]/', $tenantId)
    ) {
        return null;
    }

    return $tenantId;
}

function subscription_cleanup_google_cron_normalize_task($task): ?array
{
    if (!is_array($task)) {
        return null;
    }

    $externalRunId = is_string($task['external_run_id'] ?? null)
        ? trim($task['external_run_id'])
        : '';
    $cleanupRunId = is_string($task['cleanup_run_id'] ?? null)
        ? trim($task['cleanup_run_id'])
        : '';
    $tenantId = subscription_cleanup_google_cron_tenant_id($task['tenant_id'] ?? null);

    if (
        !subscription_cleanup_google_cron_is_uuid($externalRunId)
        || !subscription_cleanup_google_cron_is_uuid($cleanupRunId)
        || $tenantId === null
        || ($task['stage'] ?? null) !== 'google_calendar_disconnect'
    ) {
        return null;
    }

    return [
        'external_run_id' => $externalRunId,
        'tenant_id' => $tenantId,
    ];
}

function subscription_cleanup_google_cron_normalize_claim(array $claim): ?array
{
    foreach (['claimed', 'skipped', 'recovered_expired_leases'] as $field) {
        if (
            !array_key_exists($field, $claim)
            || !subscription_cleanup_google_cron_is_non_negative_number($claim[$field])
        ) {
            return null;
        }
    }

    if (
        ($claim['success'] ?? false) !== true
        || ($claim['stage'] ?? '') !== 'google_calendar_disconnect'
        || !is_array($claim['tasks'] ?? null)
        || !subscription_cleanup_google_cron_is_list($claim['tasks'])
    ) {
        return null;
    }

    $tasks = [];

    foreach ($claim['tasks'] as $task) {
        $normalizedTask = subscription_cleanup_google_cron_normalize_task($task);

        if ($normalizedTask === null) {
            return null;
        }

        $tasks[] = $normalizedTask;
    }

    return [
        'claimed' => $claim['claimed'],
        'skipped' => $claim['skipped'],
        'recovered_expired_leases' => $claim['recovered_expired_leases'],
        'tasks' => $tasks,
    ];
}

function subscription_cleanup_google_cron_normalize_prepare(
    array $prepare,
    string $externalRunId,
    string $tenantId
): ?array {
    if (($prepare['success'] ?? false) !== true || !is_bool($prepare['ready'] ?? null)) {
        return null;
    }

    if ($prepare['ready'] === false) {
        $status = is_string($prepare['status'] ?? null)
            ? trim($prepare['status'])
            : '';

        if (!in_array($status, ['skipped', 'cancelled'], true)) {
            return null;
        }

        return [
            'ready' => false,
            'status' => $status,
        ];
    }

    $preparedExternalRunId = is_string($prepare['external_run_id'] ?? null)
        ? trim($prepare['external_run_id'])
        : '';
    $preparedTenantId = subscription_cleanup_google_cron_tenant_id($prepare['tenant_id'] ?? null);
    $integrationExists = $prepare['integration_exists'] ?? null;
    $integrationUpdatedAt = $prepare['integration_updated_at'] ?? null;

    if (
        !subscription_cleanup_google_cron_is_uuid($preparedExternalRunId)
        || $preparedExternalRunId !== $externalRunId
        || $preparedTenantId === null
        || $preparedTenantId !== $tenantId
        || !is_bool($integrationExists)
    ) {
        return null;
    }

    if ($integrationExists) {
        if (!is_string($integrationUpdatedAt)) {
            return null;
        }

        $integrationUpdatedAt = trim($integrationUpdatedAt);

        if (
            $integrationUpdatedAt === ''
            || strlen($integrationUpdatedAt) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $integrationUpdatedAt)
        ) {
            return null;
        }
    } else {
        if ($integrationUpdatedAt !== null && !is_string($integrationUpdatedAt)) {
            return null;
        }

        $integrationUpdatedAt = is_string($integrationUpdatedAt)
            ? trim($integrationUpdatedAt)
            : '';

        if ($integrationUpdatedAt !== '') {
            return null;
        }
    }

    return [
        'ready' => true,
        'integration_exists' => $integrationExists,
        'integration_updated_at' => $integrationUpdatedAt,
    ];
}

function subscription_cleanup_google_cron_map_disconnect_result(
    string $externalRunId,
    array $disconnectResult
): array {
    $finishPayload = [
        'p_external_run_id' => $externalRunId,
        'p_outcome' => 'failed',
        'p_reason' => 'google_calendar_disconnect_failed',
        'p_google_token_revoked' => false,
        'p_already_disconnected' => false,
        'p_http_code' => null,
    ];
    $counter = 'failed';

    if (($disconnectResult['success'] ?? null) === true) {
        if (!is_bool($disconnectResult['already_disconnected'] ?? null)) {
            return [
                'payload' => $finishPayload,
                'counter' => $counter,
            ];
        }

        if ($disconnectResult['already_disconnected'] === true) {
            $finishPayload['p_outcome'] = 'succeeded';
            $finishPayload['p_reason'] = 'already_disconnected';
            $finishPayload['p_already_disconnected'] = true;
            $counter = 'already_disconnected';
        } else {
            $finishPayload['p_outcome'] = 'succeeded';
            $finishPayload['p_reason'] = 'disconnected';
            $finishPayload['p_google_token_revoked'] = (bool) (
                $disconnectResult['google_token_revoked'] ?? false
            );
            $counter = 'succeeded';
        }

        return [
            'payload' => $finishPayload,
            'counter' => $counter,
        ];
    }

    if (($disconnectResult['success'] ?? null) !== false) {
        return [
            'payload' => $finishPayload,
            'counter' => $counter,
        ];
    }

    $reason = is_string($disconnectResult['reason'] ?? null)
        ? trim($disconnectResult['reason'])
        : '';

    if ($reason === 'integration_changed_concurrently') {
        $finishPayload['p_outcome'] = 'skipped';
        $finishPayload['p_reason'] = 'integration_changed_concurrently';
        $counter = 'concurrently_changed';

        return [
            'payload' => $finishPayload,
            'counter' => $counter,
        ];
    }

    $allowedFailureReasons = [
        'google_revoke_temporary_failure',
        'integration_lookup_failed',
        'integration_secrets_unavailable',
        'integration_state_invalid',
        'local_disconnect_failed',
        'google_calendar_disconnect_failed',
        'missing_supabase_config',
    ];

    if (in_array($reason, $allowedFailureReasons, true)) {
        $finishPayload['p_reason'] = $reason;
        $httpCode = $disconnectResult['http_code'] ?? null;

        if (is_int($httpCode) && $httpCode >= 100 && $httpCode <= 599) {
            $finishPayload['p_http_code'] = $httpCode;
        }
    }

    return [
        'payload' => $finishPayload,
        'counter' => $counter,
    ];
}

try {
    $isCli = subscription_cleanup_google_cron_is_cli();

    if (!$isCli && !in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        subscription_cleanup_google_cron_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    if (!$isCli) {
        $cronSecret = subscription_cleanup_google_cron_configured_secret();

        if ($cronSecret === '') {
            subscription_cleanup_google_cron_json(500, [
                'success' => false,
                'error' => 'Brak konfiguracji crona.',
            ]);
        }

        $requestSecret = subscription_cleanup_google_cron_request_secret();

        if ($requestSecret === '' || !hash_equals($cronSecret, $requestSecret)) {
            subscription_cleanup_google_cron_json(401, [
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
        subscription_cleanup_google_cron_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji Supabase.',
        ]);
    }

    $claimResult = subscription_cleanup_google_cron_rpc(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        'subscription_cleanup_external_claim_google',
        [
            'p_limit' => 10,
            'p_lease_minutes' => 15,
        ]
    );
    $claim = subscription_cleanup_google_cron_normalize_claim($claimResult);

    if ($claim === null) {
        throw new RuntimeException('Odpowiedź claim Google ma nieprawidłowy format.');
    }

    $process = [
        'prepared' => 0,
        'succeeded' => 0,
        'already_disconnected' => 0,
        'concurrently_changed' => 0,
        'failed' => 0,
        'finalized_by_prepare' => 0,
        'finalized_as_skipped' => 0,
        'finalized_as_cancelled' => 0,
    ];

    foreach ($claim['tasks'] as $task) {
        $externalRunId = $task['external_run_id'];
        $tenantId = $task['tenant_id'];
        $prepareResult = subscription_cleanup_google_cron_rpc(
            $supabaseUrl,
            $supabaseKey,
            $schema,
            'subscription_cleanup_external_prepare_google',
            [
                'p_external_run_id' => $externalRunId,
            ]
        );
        $prepare = subscription_cleanup_google_cron_normalize_prepare(
            $prepareResult,
            $externalRunId,
            $tenantId
        );

        if ($prepare === null) {
            throw new RuntimeException('Odpowiedź prepare Google ma nieprawidłowy format.');
        }

        if ($prepare['ready'] === false) {
            $process['finalized_by_prepare']++;

            if ($prepare['status'] === 'skipped') {
                $process['finalized_as_skipped']++;
            } else {
                $process['finalized_as_cancelled']++;
            }

            continue;
        }

        $process['prepared']++;
        $integrationExists = $prepare['integration_exists'];
        $integrationUpdatedAt = $prepare['integration_updated_at'];
        $disconnectResult = google_calendar_disconnect(
            $tenantId,
            $integrationExists,
            $integrationExists ? $integrationUpdatedAt : null
        );
        $mappedResult = subscription_cleanup_google_cron_map_disconnect_result(
            $externalRunId,
            $disconnectResult
        );
        $finishResult = subscription_cleanup_google_cron_rpc(
            $supabaseUrl,
            $supabaseKey,
            $schema,
            'subscription_cleanup_external_finish_google',
            $mappedResult['payload']
        );

        if (($finishResult['success'] ?? false) !== true) {
            throw new RuntimeException('Odpowiedź finish Google ma nieprawidłowy format.');
        }

        if ($mappedResult['counter'] === 'already_disconnected') {
            $process['succeeded']++;
            $process['already_disconnected']++;
        } elseif ($mappedResult['counter'] === 'succeeded') {
            $process['succeeded']++;
        } elseif ($mappedResult['counter'] === 'concurrently_changed') {
            $process['concurrently_changed']++;
        } else {
            $process['failed']++;
        }
    }

    subscription_cleanup_google_cron_json(200, [
        'success' => true,
        'stage' => 'google_calendar_disconnect',
        'claim' => [
            'claimed' => $claim['claimed'],
            'skipped' => $claim['skipped'],
            'recovered_expired_leases' => $claim['recovered_expired_leases'],
        ],
        'process' => $process,
    ]);
} catch (Throwable $e) {
    subscription_cleanup_google_cron_json(500, [
        'success' => false,
        'error' => 'Błąd crona cleanupu Google Calendar.',
    ]);
}
