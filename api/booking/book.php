<?php

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/booking_context_cache.php';
require_once __DIR__ . '/../helpers/booking_postprocess_queue.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../helpers/google_calendar.php';
require_once __DIR__ . '/../helpers/booking_mail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

start_secure_session();
date_default_timezone_set('Europe/Warsaw');

$bookingSlotLockHandle = null;
$bookingGlobalSemaphoreHandle = null;
$bookingGlobalSemaphoreAcquired = false;

function json_response(array $payload, int $status = 200): void
{
    global $bookingSlotLockHandle, $bookingGlobalSemaphoreHandle, $bookingGlobalSemaphoreAcquired;

    if (isset($bookingSlotLockHandle)) {
        booking_release_slot_lock($bookingSlotLockHandle);
        $bookingSlotLockHandle = null;
    }

    if ($bookingGlobalSemaphoreAcquired) {
        booking_release_global_semaphore($bookingGlobalSemaphoreHandle);
        $bookingGlobalSemaphoreHandle = null;
        $bookingGlobalSemaphoreAcquired = false;
    }

    if (ob_get_length()) {
        ob_clean();
    }

    if (function_exists('booking_supabase_request_summary')) {
        $cacheTotals = function_exists('booking_context_cache_metric_totals')
            ? booking_context_cache_metric_totals()
            : ['hits' => 0, 'misses' => 0, 'http_requests_avoided' => 0];

        debug_log('BOOK_SUPABASE_REQUEST_SUMMARY', [
            'critical_total' => booking_supabase_request_total_for_phase('critical'),
            'post_insert_total' => booking_supabase_request_total_for_phase('post_insert'),
            'total' => booking_supabase_request_total(),
            'requests' => booking_supabase_request_summary(),
            'cache_hits' => (int)($cacheTotals['hits'] ?? 0),
            'cache_misses' => (int)($cacheTotals['misses'] ?? 0),
            'http_requests_avoided' => (int)($cacheTotals['http_requests_avoided'] ?? 0),
            'cache' => function_exists('booking_context_cache_metrics')
                ? booking_context_cache_metrics()
                : [],
        ]);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function booking_payment_handoff_store(string $bookingId, string $tenantId): void
{
    $bookingId = trim($bookingId);
    $tenantId = trim($tenantId);

    if ($bookingId === '' || $tenantId === '') {
        unset($_SESSION['booking_payment_handoff']);
        return;
    }

    $_SESSION['booking_payment_handoff'] = [
        'booking_id' => $bookingId,
        'tenant_id' => $tenantId,
        'created_at' => time(),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

function debug_log(string $label, $data): void
{
    @file_put_contents(
        '/var/www/data/debug.log',
        date('Y-m-d H:i:s') . " [{$label}] " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n",
        FILE_APPEND
    );
}

function booking_supabase_endpoint_label(string $url): string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

    if (preg_match('#^/rest/v1/[a-zA-Z0-9_]+$#', $path) === 1) {
        return $path;
    }

    return 'supabase';
}

function booking_supabase_request_phase(?string $phase = null): string
{
    static $currentPhase = 'critical';

    if ($phase !== null) {
        $currentPhase = $phase === 'post_insert' ? 'post_insert' : 'critical';
    }

    return $currentPhase;
}

function booking_supabase_request_store(?array $entry = null): array
{
    static $summary = [];

    if ($entry === null) {
        return $summary;
    }

    $method = strtoupper(substr(trim((string)($entry['method'] ?? 'GET')), 0, 10));
    $stage = substr(trim((string)($entry['stage'] ?? 'unknown')), 0, 80) ?: 'unknown';
    $endpoint = booking_supabase_endpoint_label((string)($entry['url'] ?? ''));
    $status = (int)($entry['status'] ?? 0);
    $phase = ($entry['phase'] ?? '') === 'post_insert' ? 'post_insert' : 'critical';
    $key = $phase . '|' . $method . '|' . $stage . '|' . $endpoint;

    if (!isset($summary[$key])) {
        $summary[$key] = [
            'phase' => $phase,
            'method' => $method,
            'stage' => $stage,
            'endpoint' => $endpoint,
            'count' => 0,
            'statuses' => [],
        ];
    }

    $summary[$key]['count']++;
    $statusKey = (string)$status;
    $summary[$key]['statuses'][$statusKey] = ($summary[$key]['statuses'][$statusKey] ?? 0) + 1;

    return $summary;
}

function booking_supabase_request_record(string $method, string $url, string $stage, int $status): void
{
    booking_supabase_request_store([
        'method' => $method,
        'url' => $url,
        'stage' => $stage,
        'status' => $status,
        'phase' => booking_supabase_request_phase(),
    ]);
}

function booking_supabase_request_summary(): array
{
    return array_values(booking_supabase_request_store());
}

function booking_supabase_request_total(): int
{
    $total = 0;

    foreach (booking_supabase_request_store() as $entry) {
        $total += (int)($entry['count'] ?? 0);
    }

    return $total;
}

function booking_supabase_request_total_for_phase(string $phase): int
{
    $phase = $phase === 'post_insert' ? 'post_insert' : 'critical';
    $total = 0;

    foreach (booking_supabase_request_store() as $entry) {
        if (($entry['phase'] ?? 'critical') === $phase) {
            $total += (int)($entry['count'] ?? 0);
        }
    }

    return $total;
}

function booking_supabase_request_count_for_stage(string $stage): int
{
    $count = 0;

    foreach (booking_supabase_request_store() as $entry) {
        if (($entry['stage'] ?? '') === $stage) {
            $count += (int)($entry['count'] ?? 0);
        }
    }

    return $count;
}

function booking_log_supabase_transient_error(array $context): void
{
    $curlError = trim(preg_replace('/[\r\n]+/', ' ', (string) ($context['curl_error'] ?? '')) ?? '');
    $curlError = preg_replace('#https?://\S+#i', '[url]', $curlError) ?? '';
    $tenantId = trim((string) ($context['tenant_id'] ?? ''));
    $httpCode = (int) ($context['http_code'] ?? 0);
    $curlErrno = (int) ($context['curl_errno'] ?? 0);
    $jsonValid = !empty($context['json_valid']);

    if ($curlErrno === 28) {
        $reason = 'curl_timeout';
    } elseif ($curlErrno !== 0 || $curlError !== '') {
        $reason = 'curl_transport_error';
    } elseif ($httpCode === 429) {
        $reason = 'http_429';
    } elseif ($httpCode >= 500) {
        $reason = 'http_5xx';
    } elseif ($httpCode === 0) {
        $reason = 'http_0_no_response';
    } elseif (!$jsonValid) {
        $reason = 'invalid_json';
    } else {
        $reason = 'other_transient_error';
    }

    $logContext = [
        'stage' => substr(trim((string) ($context['stage'] ?? 'unknown')), 0, 80) ?: 'unknown',
        'method' => strtoupper(substr(trim((string) ($context['method'] ?? 'GET')), 0, 10)),
        'endpoint' => booking_supabase_endpoint_label((string) ($context['endpoint'] ?? '')),
        'reason' => $reason,
        'http_code' => $httpCode,
        'curl_errno' => $curlErrno,
        'curl_error' => $curlError !== '' ? substr($curlError, 0, 240) : '',
        'attempt' => max(1, (int) ($context['attempt'] ?? 1)),
        'max_attempts' => max(1, (int) ($context['max_attempts'] ?? 1)),
        'json_valid' => $jsonValid,
        'duration_ms' => max(0, (int) ($context['duration_ms'] ?? 0)),
    ];

    if ($tenantId !== '') {
        $logContext['tenant_id'] = substr($tenantId, 0, 128);
    }

    debug_log('BOOK_SUPABASE_TRANSIENT_ERROR', $logContext);
}

function booking_supabase_stage_uses_request_cache(string $stage): bool
{
    return in_array($stage, [
        'calendar_settings',
        'calendar_form_fields',
        'tenant_branding',
        'service',
        'service_durations',
        'service_staff',
        'staff',
        'staff_availability',
        'subscription',
        'service_settings',
        'payu_settings',
        'email_templates',
    ], true);
}

function booking_supabase_retry_delay_us(int $attempt, int $httpCode): int
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

function booking_debug_log_service(array $data): void
{
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: '');
    $appEnv = strtolower((string) getenv('APP_ENV'));
    $isDebugEnvironment = stripos((string) $schema, '_beta') !== false;
    $isDebugEnvironment = $isDebugEnvironment
        || stripos($schema, 'dev') !== false
        || in_array($appEnv, ['dev', 'development', 'local'], true);

    if (!$isDebugEnvironment) {
        return;
    }

    try {
        $dir = '/var/www/logs';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (!is_dir($dir)) {
            error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: katalog logów nie istnieje po próbie utworzenia');
            return;
        }

        if (!is_writable($dir)) {
            error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: katalog logów nie jest zapisywalny');
            return;
        }

        $payload = array_merge([
            'timestamp' => date(DATE_ATOM),
        ], $data);

        $written = file_put_contents(
            $dir . '/rezerwacje-service-debug.log',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );

        if ($written === false) {
            error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: nie udało się zapisać wpisu logu');
        }
    } catch (Throwable $e) {
        error_log('BOOK_SERVICE_DEBUG_LOG_ERROR: ' . $e->getMessage());
        // Debug techniczny nie może przerywać rezerwacji.
    }
}

function booking_load_test_ip_is_allowed(string $remoteAddress): bool
{
    $remoteAddress = trim($remoteAddress);
    $remotePacked = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
        ? @inet_pton($remoteAddress)
        : false;

    if ($remotePacked === false) {
        return false;
    }

    $configuredIps = trim((string) (getenv('BOOKING_LOAD_TEST_ALLOW_IPS') ?: ''));

    if ($configuredIps === '') {
        return false;
    }

    foreach (explode(',', $configuredIps) as $configuredIp) {
        $configuredIp = trim($configuredIp);
        $configuredPacked = filter_var($configuredIp, FILTER_VALIDATE_IP) !== false
            ? @inet_pton($configuredIp)
            : false;

        if (
            $configuredPacked !== false
            && strlen($configuredPacked) === strlen($remotePacked)
            && hash_equals($configuredPacked, $remotePacked)
        ) {
            return true;
        }
    }

    return false;
}

function is_valid_international_phone(string $phone): bool
{
    $phone = trim($phone);

    if ($phone === '') {
        return false;
    }

    if (!preg_match('/^\+?[0-9\s-]+$/', $phone)) {
        return false;
    }

    if (substr_count($phone, '+') > 1) {
        return false;
    }

    if (str_contains($phone, '+') && !str_starts_with($phone, '+')) {
        return false;
    }

    $digits = preg_replace('/\D+/', '', $phone);

    if (!is_string($digits)) {
        return false;
    }

    if (str_starts_with($phone, '+48')) {
        return strlen($digits) === 11 && str_starts_with($digits, '48');
    }

    if (str_starts_with($phone, '+')) {
        return false;
    }

    return strlen($digits) === 9;
}

function supabase_headers(string $key, string $schema, bool $minimal = false): array
{
    $headers = [
        "apikey: {$key}",
        "Authorization: Bearer {$key}",
        'Content-Type: application/json',
        'Accept: application/json',
        "Accept-Profile: {$schema}",
        "Content-Profile: {$schema}",
    ];

    $headers[] = $minimal ? 'Prefer: return=minimal' : 'Prefer: return=representation';

    return $headers;
}

function supabase_insert(string $url, array $payload, array $headers, string $stage = 'unknown', string $tenantId = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $durationMs = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
    curl_close($ch);

    booking_supabase_request_record('POST', $url, $stage, $httpCode);

    $jsonValid = false;

    if ($response !== false && $response !== '') {
        json_decode((string) $response, true);
        $jsonValid = json_last_error() === JSON_ERROR_NONE;
    }

    $expectsJsonResponse = $stage !== 'blocked_times_insert';
    $invalidJsonResponse = $expectsJsonResponse
        && $httpCode >= 200
        && $httpCode < 300
        && $response !== false
        && !$jsonValid;
    $temporary = $response === false
        || trim((string) $error) !== ''
        || $httpCode === 0
        || $httpCode === 429
        || $httpCode >= 500
        || $invalidJsonResponse;

    if ($temporary) {
        booking_log_supabase_transient_error([
            'stage' => $stage,
            'method' => 'POST',
            'endpoint' => $url,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'curl_error' => $error,
            'attempt' => 1,
            'max_attempts' => 1,
            'json_valid' => $jsonValid,
            'duration_ms' => $durationMs,
            'tenant_id' => $tenantId,
        ]);
    }

    return [
        'response' => $response,
        'error' => $error,
        'httpCode' => $httpCode,
    ];
}

function supabase_select(string $url, array $headers, string $stage = 'unknown', string $tenantId = ''): array
{
    static $requestCache = [];

    $maxAttempts = 3;
    $response = false;
    $error = '';
    $httpCode = 0;
    $decoded = null;
    $jsonValid = false;
    $jsonError = JSON_ERROR_NONE;
    $temporary = false;
    $attempt = 0;
    $requestCacheKey = '';
    $persistentCacheKey = '';

    if (booking_supabase_stage_uses_request_cache($stage)) {
        $requestCacheKey = hash(
            'sha256',
            $url . "\n" . json_encode($headers, JSON_UNESCAPED_SLASHES)
        );

        if (array_key_exists($requestCacheKey, $requestCache)) {
            return $requestCache[$requestCacheKey];
        }
    }

    if (
        function_exists('booking_context_cache_stage_is_allowed')
        && booking_context_cache_stage_is_allowed($stage)
    ) {
        $persistentCacheKey = booking_context_cache_key($stage, [
            'url' => $url,
            'schema' => (string)(getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro'),
        ]);
        $persistentResult = booking_context_cache_read($stage, $persistentCacheKey);

        if (is_array($persistentResult)) {
            if ($requestCacheKey !== '') {
                $requestCache[$requestCacheKey] = $persistentResult;
            }

            return $persistentResult;
        }
    }

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $durationMs = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        curl_close($ch);

        booking_supabase_request_record('GET', $url, $stage, $httpCode);

        $decoded = null;
        $jsonValid = false;
        $jsonError = JSON_ERROR_NONE;

        if ($response !== false && $response !== '') {
            $decoded = json_decode((string) $response, true);
            $jsonError = json_last_error();
            $jsonValid = $jsonError === JSON_ERROR_NONE;
        } else {
            $jsonError = JSON_ERROR_SYNTAX;
        }

        $temporary = $response === false
            || trim((string) $error) !== ''
            || $httpCode === 0
            || $httpCode === 429
            || $httpCode >= 500
            || ($httpCode >= 200 && $httpCode < 300 && !$jsonValid);

        if ($temporary) {
            booking_log_supabase_transient_error([
                'stage' => $stage,
                'method' => 'GET',
                'endpoint' => $url,
                'http_code' => $httpCode,
                'curl_errno' => $curlErrno,
                'curl_error' => $error,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'json_valid' => $jsonValid,
                'duration_ms' => $durationMs,
                'tenant_id' => $tenantId,
            ]);
        }

        if (!$temporary || $attempt === $maxAttempts) {
            break;
        }

        usleep(booking_supabase_retry_delay_us($attempt, $httpCode));
    }

    $result = [
        'response' => $response,
        'data' => $decoded,
        'error' => $error,
        'httpCode' => $httpCode,
        'temporary' => $temporary,
        'jsonValid' => $jsonValid,
        'jsonError' => $jsonError,
        'attempts' => $attempt,
    ];

    if (
        $requestCacheKey !== ''
        && !$temporary
        && $httpCode >= 200
        && $httpCode < 300
        && $jsonValid
        && is_array($decoded)
    ) {
        $requestCache[$requestCacheKey] = $result;
    }

    if (
        $persistentCacheKey !== ''
        && !$temporary
        && $httpCode >= 200
        && $httpCode < 300
        && $jsonValid
        && is_array($decoded)
    ) {
        booking_context_cache_write(
            $stage,
            $persistentCacheKey,
            $result,
            booking_context_cache_default_ttl(),
            max(1, $attempt)
        );
    }

    return $result;
}

function supabase_select_is_temporary(array $result): bool
{
    $httpCode = (int)($result['httpCode'] ?? 0);

    if (!empty($result['temporary'])) {
        return true;
    }

    if ($httpCode === 0 || $httpCode === 429 || $httpCode >= 500) {
        return true;
    }

    if (trim((string)($result['error'] ?? '')) !== '') {
        return true;
    }

    return array_key_exists('jsonValid', $result)
        && ($result['jsonValid'] ?? true) === false
        && $httpCode >= 200
        && $httpCode < 300;
}

function booking_temporary_unavailable(string $message = ''): void
{
    json_response([
        'success' => false,
        'error' => 'temporary_unavailable',
        'message' => $message !== '' ? $message : 'Nie udało się chwilowo sprawdzić dostępności. Spróbuj ponownie za moment.',
    ], 503);
}

function booking_tenant_has_feature_or_temporary(string $tenantId, string $featureKey): bool
{
    $context = plan_features_get_context($tenantId, 'booking_log_supabase_transient_error');

    if (!empty($context['temporary_error'])) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić uprawnień konta. Spróbuj ponownie za moment.');
    }

    $features = is_array($context['features'] ?? null) ? $context['features'] : [];

    return !empty($features[$featureKey]);
}

function booking_insert_error_text(array $result): string
{
    $parts = [];
    $response = (string)($result['response'] ?? '');
    $decoded = $response !== '' ? json_decode($response, true) : null;

    if (is_array($decoded)) {
        foreach (['code', 'message', 'details', 'hint', 'error'] as $key) {
            if (!empty($decoded[$key]) && !is_array($decoded[$key]) && !is_object($decoded[$key])) {
                $parts[] = (string)$decoded[$key];
            }
        }
    }

    if ($response !== '') {
        $parts[] = $response;
    }

    if (!empty($result['error'])) {
        $parts[] = (string)$result['error'];
    }

    return strtolower(implode(' ', $parts));
}

function booking_insert_error_is_conflict(array $result): bool
{
    $httpCode = (int)($result['httpCode'] ?? 0);

    if ($httpCode === 409) {
        return true;
    }

    $errorText = booking_insert_error_text($result);

    foreach ([
        '23505',
        'duplicate key',
        'unique constraint',
        'violates unique',
        'conflict',
        'already exists',
        'slot',
        'termin',
    ] as $needle) {
        if ($errorText !== '' && str_contains($errorText, $needle)) {
            return true;
        }
    }

    return false;
}

function booking_insert_error_is_temporary(array $result): bool
{
    $httpCode = (int)($result['httpCode'] ?? 0);

    if ($httpCode === 0 || $httpCode === 429 || $httpCode >= 500) {
        return true;
    }

    return trim((string)($result['error'] ?? '')) !== '';
}

function booking_insert_error_response(array $result): void
{
    $httpCode = (int)($result['httpCode'] ?? 0);

    if (booking_insert_error_is_conflict($result)) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest już niedostępna',
        ], 409);
    }

    if ($httpCode === 429) {
        json_response([
            'success' => false,
            'error' => 'temporary_unavailable',
            'message' => 'W tym momencie system obsługuje dużo rezerwacji. Spróbuj ponownie za chwilę.',
        ], 429);
    }

    if (booking_insert_error_is_temporary($result)) {
        json_response([
            'success' => false,
            'error' => 'temporary_unavailable',
            'message' => 'W tym momencie system obsługuje dużo rezerwacji. Spróbuj ponownie za chwilę.',
        ], 503);
    }

    json_response([
        'success' => false,
        'error' => 'Nie udało się zapisać rezerwacji. Spróbuj ponownie.',
    ], 500);
}

function fetch_single_record(
    string $baseUrl,
    array $headers,
    string $table,
    string $query,
    string $stage = 'unknown',
    string $tenantId = ''
): ?array
{
    $url = rtrim($baseUrl, '/') . "/rest/v1/{$table}?{$query}&limit=1";
    $res = supabase_select($url, $headers, $stage, $tenantId);

    if (($res['httpCode'] ?? 0) !== 200 || empty($res['data'][0]) || !is_array($res['data'][0])) {
        return null;
    }

    return $res['data'][0];
}

function fetch_single_record_result(
    string $baseUrl,
    array $headers,
    string $table,
    string $query,
    string $stage = 'unknown',
    string $tenantId = ''
): array
{
    $url = rtrim($baseUrl, '/') . "/rest/v1/{$table}?{$query}&limit=1";
    $res = supabase_select($url, $headers, $stage, $tenantId);
    $temporary = supabase_select_is_temporary($res);
    $httpCode = (int)($res['httpCode'] ?? 0);
    $data = $res['data'] ?? null;
    $row = is_array($data) && isset($data[0]) && is_array($data[0])
        ? $data[0]
        : null;

    return [
        'ok' => !$temporary && $httpCode === 200 && is_array($data),
        'found' => $row !== null,
        'row' => $row,
        'temporary' => $temporary,
        'httpCode' => $httpCode,
        'error' => (string)($res['error'] ?? ''),
    ];
}

function fetch_public_staff_for_booking(string $baseUrl, array $headers, string $tenantId, string $staffId): ?array
{
    if ($staffId === '') {
        return null;
    }

    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&is_active=eq.true'
        . '&select=id,display_name,service_name,service_duration_minutes,service_break_minutes,booking_buffer_minutes,service_price,payments_enabled,email_subject,email_heading,email_body';

    return fetch_single_record($baseUrl, $headers, 'staff_profiles', $query);
}

function fetch_public_staff_for_booking_result(string $baseUrl, array $headers, string $tenantId, string $staffId): array
{
    if ($staffId === '') {
        return [
            'ok' => true,
            'found' => false,
            'row' => null,
            'temporary' => false,
            'httpCode' => 200,
            'error' => '',
        ];
    }

    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&is_active=eq.true'
        . '&select=id,display_name,service_name,service_duration_minutes,service_break_minutes,booking_buffer_minutes,service_price,payments_enabled,email_subject,email_heading,email_body';

    return fetch_single_record_result($baseUrl, $headers, 'staff_profiles', $query, 'staff', $tenantId);
}

function fetch_public_service_for_booking(string $baseUrl, array $headers, string $tenantId, string $serviceId): ?array
{
    if ($serviceId === '') {
        return null;
    }

    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($serviceId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&select=id,name,description,duration_minutes,break_minutes,booking_buffer_minutes,price_amount,price_currency,payments_enabled';

    return fetch_single_record($baseUrl, $headers, 'tenant_services', $query);
}

function fetch_public_service_for_booking_result(string $baseUrl, array $headers, string $tenantId, string $serviceId): array
{
    if ($serviceId === '') {
        return [
            'ok' => true,
            'found' => false,
            'row' => null,
            'temporary' => false,
            'httpCode' => 200,
            'error' => '',
        ];
    }

    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($serviceId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&select=id,name,description,duration_minutes,break_minutes,booking_buffer_minutes,price_amount,price_currency,payments_enabled';

    return fetch_single_record_result($baseUrl, $headers, 'tenant_services', $query, 'service', $tenantId);
}

function fetch_service_staff_ids_for_booking(string $baseUrl, array $headers, string $tenantId, string $serviceId): ?array
{
    if ($serviceId === '') {
        return [];
    }

    $query = 'select=staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=eq.' . rawurlencode($serviceId);

    $url = rtrim($baseUrl, '/') . '/rest/v1/tenant_service_staff?' . $query;
    $result = supabase_select($url, $headers, 'service_staff', $tenantId);

    if (($result['httpCode'] ?? 0) !== 200 || !is_array($result['data'])) {
        return null;
    }

    $staffIds = [];

    foreach ($result['data'] as $row) {
        if (!is_array($row) || empty($row['staff_id'])) {
            continue;
        }

        $staffIds[(string) $row['staff_id']] = true;
    }

    return array_keys($staffIds);
}

function fetch_service_staff_ids_for_booking_result(string $baseUrl, array $headers, string $tenantId, string $serviceId): array
{
    if ($serviceId === '') {
        return [
            'ok' => true,
            'staffIds' => [],
            'temporary' => false,
            'httpCode' => 200,
            'error' => '',
        ];
    }

    $query = 'select=staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=eq.' . rawurlencode($serviceId);

    $url = rtrim($baseUrl, '/') . '/rest/v1/tenant_service_staff?' . $query;
    $result = supabase_select($url, $headers, 'service_staff', $tenantId);
    $temporary = supabase_select_is_temporary($result);

    if (($result['httpCode'] ?? 0) !== 200 || !is_array($result['data'])) {
        return [
            'ok' => false,
            'staffIds' => [],
            'temporary' => $temporary,
            'httpCode' => (int)($result['httpCode'] ?? 0),
            'error' => (string)($result['error'] ?? ''),
        ];
    }

    $staffIds = [];

    foreach ($result['data'] as $row) {
        if (!is_array($row) || empty($row['staff_id'])) {
            continue;
        }

        $staffIds[(string) $row['staff_id']] = true;
    }

    return [
        'ok' => true,
        'staffIds' => array_keys($staffIds),
        'temporary' => false,
        'httpCode' => (int)($result['httpCode'] ?? 0),
        'error' => '',
    ];
}

function booking_nullable_int(array $row, string $key): ?int
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return (int) $row[$key];
}

function booking_nullable_float(array $row, string $key): ?float
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return is_numeric($row[$key]) ? (float) $row[$key] : null;
}

function booking_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
}

function booking_ranges_overlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

function booking_interval_end(int $start, int $duration, int $break): int
{
    return $start + max(1, $duration) + max(0, $break);
}

function booking_effective_min_notice_minutes(?int $serviceBufferMinutes, int $globalBookingBuffer): int
{
    if ($serviceBufferMinutes !== null && $serviceBufferMinutes > 0) {
        return max(0, $serviceBufferMinutes);
    }

    return max(0, $globalBookingBuffer);
}

function booking_slot_respects_buffer(string $date, string $time, int $bufferMinutes): bool
{
    $bufferMinutes = max(0, $bufferMinutes);

    if ($bufferMinutes <= 0) {
        return true;
    }

    $timezone = new DateTimeZone('Europe/Warsaw');
    $slotTime = substr($time, 0, 5);
    $slotDateTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $date . ' ' . $slotTime,
        $timezone
    );

    if (!$slotDateTime instanceof DateTimeImmutable || $slotDateTime->format('Y-m-d H:i') !== $date . ' ' . $slotTime) {
        return false;
    }

    $now = new DateTimeImmutable('now', $timezone);
    $minAllowedDateTime = $now->modify('+' . $bufferMinutes . ' minutes');

    return $slotDateTime >= $minAllowedDateTime;
}

function fetch_staff_availability_for_booking_result(
    string $baseUrl,
    array $headers,
    string $tenantId,
    string $staffId,
    string $date
): array {
    $weekday = (int) (new DateTimeImmutable($date))->format('N');
    $query = 'select=weekday,start_time,end_time,is_active'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&weekday=eq.' . rawurlencode((string) $weekday)
        . '&is_active=eq.true';

    $url = rtrim($baseUrl, '/') . '/rest/v1/staff_availability?' . $query;
    $result = supabase_select($url, $headers, 'staff_availability', $tenantId);

    if (($result['httpCode'] ?? 0) !== 200 || !is_array($result['data'])) {
        return [
            'ok' => false,
            'temporary' => supabase_select_is_temporary($result),
            'rows' => [],
        ];
    }

    return [
        'ok' => true,
        'temporary' => false,
        'rows' => $result['data'],
    ];
}

function staff_slot_matches_schedule(
    array $availabilityRows,
    string $time,
    int $duration,
    int $break
): bool {

    $slotMinutes = booking_time_to_minutes($time);
    $duration = max(1, $duration);
    $break = max(0, $break);

    foreach ($availabilityRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $start = substr((string)($row['start_time'] ?? ''), 0, 5);
        $end = substr((string)($row['end_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            continue;
        }

        $current = booking_time_to_minutes($start);
        $endMinutes = booking_time_to_minutes($end);

        while ($current + $duration <= $endMinutes) {
            if ($current === $slotMinutes) {
                return true;
            }

            $current += $duration + $break;
        }
    }

    return false;
}

function staff_slot_is_free(
    string $baseUrl,
    array $headers,
    string $tenantId,
    string $staffId,
    string $date,
    string $time,
    int $candidateDuration,
    int $candidateBreak,
    array $knownServicesById = []
): ?bool
{
    $query = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
        . '&booking_date=eq.' . rawurlencode($date)
        . '&select=id,booking_time,service_id';

    $bookingsUrl = rtrim($baseUrl, '/') . '/rest/v1/bookings?' . $query;
    $bookingsResult = supabase_select($bookingsUrl, $headers, 'bookings_availability', $tenantId);

    if (($bookingsResult['httpCode'] ?? 0) !== 200 || !is_array($bookingsResult['data'])) {
        return supabase_select_is_temporary($bookingsResult) ? null : false;
    }

    $bookings = $bookingsResult['data'];
    $serviceIds = [];

    foreach ($bookings as $booking) {
        if (!is_array($booking) || empty($booking['service_id'])) {
            continue;
        }

        $serviceIds[(string) $booking['service_id']] = true;
    }

    $settingsByServiceId = [];

    foreach ($knownServicesById as $knownServiceId => $knownService) {
        if (!is_array($knownService)) {
            continue;
        }

        $knownServiceId = trim((string)$knownServiceId);

        if ($knownServiceId === '') {
            continue;
        }

        $settingsByServiceId[$knownServiceId] = [
            'duration' => max(1, (int)($knownService['duration_minutes'] ?? $candidateDuration)),
            'break' => max(0, (int)($knownService['break_minutes'] ?? $candidateBreak)),
        ];

        unset($serviceIds[$knownServiceId]);
    }

    if (!empty($serviceIds)) {
        $serviceUrl = rtrim($baseUrl, '/') . '/rest/v1/tenant_services'
            . '?select=id,duration_minutes,break_minutes'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&id=in.(' . implode(',', array_map('rawurlencode', array_keys($serviceIds))) . ')';

        $serviceResult = supabase_select($serviceUrl, $headers, 'service_durations', $tenantId);

        if (($serviceResult['httpCode'] ?? 0) !== 200 || !is_array($serviceResult['data'])) {
            if (supabase_select_is_temporary($serviceResult)) {
                return null;
            }

            return false;
        }

        foreach ($serviceResult['data'] as $serviceRow) {
            if (!is_array($serviceRow) || empty($serviceRow['id'])) {
                continue;
            }

            $settingsByServiceId[(string) $serviceRow['id']] = [
                'duration' => max(1, (int) ($serviceRow['duration_minutes'] ?? $candidateDuration)),
                'break' => max(0, (int) ($serviceRow['break_minutes'] ?? $candidateBreak)),
            ];
        }
    }

    $candidateStart = booking_time_to_minutes($time);
    $candidateEnd = booking_interval_end($candidateStart, $candidateDuration, $candidateBreak);

    foreach ($bookings as $booking) {
        if (!is_array($booking)) {
            continue;
        }

        $existingTime = substr((string) ($booking['booking_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $existingTime)) {
            continue;
        }

        $serviceId = trim((string) ($booking['service_id'] ?? ''));
        $existingSettings = $settingsByServiceId[$serviceId] ?? [
            'duration' => $candidateDuration,
            'break' => $candidateBreak,
        ];

        $existingStart = booking_time_to_minutes($existingTime);
        $existingEnd = booking_interval_end(
            $existingStart,
            (int) ($existingSettings['duration'] ?? $candidateDuration),
            (int) ($existingSettings['break'] ?? $candidateBreak)
        );

        if (booking_ranges_overlap($candidateStart, $candidateEnd, $existingStart, $existingEnd)) {
            return false;
        }
    }

    return true;
}

function booking_global_slot_is_available(string $baseUrl, array $headers, string $tenantId, string $date, string $time, string $staffId = ''): ?bool
{
    $staffBlockFilter = $staffId === ''
        ? '&staff_id=is.null'
        : '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

    $blockedDateQuery = 'tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . $staffBlockFilter
        . '&select=date';

    $blockedDateResult = fetch_single_record_result(
        $baseUrl,
        $headers,
        'blocked_dates',
        $blockedDateQuery,
        'blocked_dates',
        $tenantId
    );

    if (!empty($blockedDateResult['temporary'])) {
        return null;
    }

    if (!empty($blockedDateResult['found'])) {
        return false;
    }

    $blockedTimesUrl = rtrim($baseUrl, '/') . '/rest/v1/blocked_times?select=time'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . $staffBlockFilter;

    $blockedTimesResult = supabase_select($blockedTimesUrl, $headers, 'blocked_times', $tenantId);

    if (($blockedTimesResult['httpCode'] ?? 0) !== 200 || !is_array($blockedTimesResult['data'])) {
        return supabase_select_is_temporary($blockedTimesResult) ? null : false;
    }

    foreach ($blockedTimesResult['data'] as $row) {
        if (!is_array($row) || empty($row['time'])) {
            continue;
        }

        $blockedTime = substr((string) $row['time'], 0, 5);

        if ($blockedTime === 'all' || $blockedTime === $time) {
            return false;
        }
    }

    return true;
}

function booking_global_semaphore_dir(): string
{
    return booking_runtime_data_root() . '/cache/booking-semaphore';
}

// Globalny limit równoległych ścieżek book.php; nie zastępuje locka per slot.
// Nie zastępuje też unikalnego indeksu w bazie, który powinien być ostatnią bramką.
function booking_acquire_global_semaphore(int $limit = 3, int $timeoutMs = 40000, int $pollIntervalUs = 150000)
{
    $semaphoreDir = booking_global_semaphore_dir();

    if (!is_dir($semaphoreDir) && !@mkdir($semaphoreDir, 0775, true) && !is_dir($semaphoreDir)) {
        return false;
    }

    if (!is_writable($semaphoreDir)) {
        return false;
    }

    $limit = max(1, $limit);
    $deadline = microtime(true) + (max(1, $timeoutMs) / 1000);
    $pollIntervalUs = max(100000, min(200000, $pollIntervalUs));

    do {
        for ($index = 0; $index < $limit; $index++) {
            $lockFile = $semaphoreDir . DIRECTORY_SEPARATOR . 'book-global-' . $index . '.lock';
            $handle = @fopen($lockFile, 'c+');

            if (!is_resource($handle)) {
                continue;
            }

            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            @fclose($handle);
        }

        usleep($pollIntervalUs);
    } while (microtime(true) < $deadline);

    return false;
}

function booking_release_global_semaphore($semaphoreHandle): void
{
    if (!is_resource($semaphoreHandle)) {
        return;
    }

    @flock($semaphoreHandle, LOCK_UN);
    @fclose($semaphoreHandle);
}

function booking_slot_lock_key(string $tenantId, string $serviceId, string $staffId, string $date, string $time): string
{
    $parts = [
        'tenant_id' => trim($tenantId),
        'service_id' => trim($serviceId) !== '' ? trim($serviceId) : 'global',
        'staff_id' => trim($staffId) !== '' ? trim($staffId) : 'global',
        'booking_date' => trim($date),
        'booking_time' => substr(trim($time), 0, 5),
    ];

    return 'booking-slot-' . hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '.lock';
}

function booking_slot_lock_dir(): string
{
    return booking_runtime_data_root() . '/cache/booking-locks';
}

function booking_acquire_slot_lock(string $lockKey, int $timeoutMs = 6000)
{
    $lockDir = booking_slot_lock_dir();

    if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
        return false;
    }

    if (!is_writable($lockDir)) {
        return false;
    }

    $lockFile = $lockDir . DIRECTORY_SEPARATOR . $lockKey;
    $handle = @fopen($lockFile, 'c+');

    if (!is_resource($handle)) {
        return false;
    }

    $deadline = microtime(true) + (max(1, $timeoutMs) / 1000);

    do {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }

        usleep(75000);
    } while (microtime(true) < $deadline);

    @fclose($handle);

    return false;
}

function booking_release_slot_lock($lockHandle): void
{
    if (!is_resource($lockHandle)) {
        return;
    }

    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
}

function booking_slot_taken_response(): void
{
    json_response([
        'success' => false,
        'error' => 'slot_taken',
        'message' => 'Ten termin został właśnie zarezerwowany przez inną osobę. Wybierz inną godzinę.'
    ], 409);
}

function booking_quick_slot_is_free(string $baseUrl, array $headers, string $tenantId, string $staffId, string $date, string $time): ?bool
{
    $query = 'select=id,payment_status,status'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&booking_date=eq.' . rawurlencode($date)
        . '&booking_time=eq.' . rawurlencode($time);

    $query .= $staffId !== ''
        ? '&staff_id=eq.' . rawurlencode($staffId)
        : '&staff_id=is.null';

    $url = rtrim($baseUrl, '/') . '/rest/v1/bookings?' . $query . '&limit=1';
    $result = supabase_select($url, $headers, 'bookings_availability_check', $tenantId);

    if (($result['httpCode'] ?? 0) !== 200 || !is_array($result['data'] ?? null)) {
        return null;
    }

    return empty($result['data']);
}

function booking_subscription_allows_staff(?string $planCode, ?string $status, ?string $currentPeriodEnd = null): bool
{
    $planValue = strtolower(trim((string) $planCode));
    $planValue = $planValue === 'biznes' ? 'business' : $planValue;
    $statusValue = strtolower(trim((string) $status));

    if (!in_array($planValue, ['pro', 'vip', 'business'], true)
        || !in_array($statusValue, ['active', 'trial'], true)) {
        return false;
    }

    $periodEndValue = trim((string) $currentPeriodEnd);

    if ($periodEndValue === '') {
        return true;
    }

    try {
        $periodEnd = (new DateTimeImmutable($periodEndValue, new DateTimeZone('Europe/Warsaw')))->setTime(0, 0, 0);
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw')))->setTime(0, 0, 0);

        return $periodEnd >= $today;
    } catch (Throwable $e) {
        return false;
    }
}

function calculate_payment_expires_at(int $value, string $unit): string
{
    if ($value <= 0) {
        $value = 48;
    }

    $unit = in_array($unit, ['hours', 'days'], true) ? $unit : 'hours';

    $date = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

    if ($unit === 'days') {
        $date = $date->modify('+' . $value . ' days');
    } else {
        $date = $date->modify('+' . $value . ' hours');
    }

    return $date->format(DateTimeInterface::ATOM);
}

function generateBookingManageToken(): string
{
    return bin2hex(random_bytes(32));
}

function calculateBookingManageTokenExpiresAt(string $date, string $time): string
{
    $bookingStart = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $date . ' ' . $time,
        new DateTimeZone('Europe/Warsaw')
    );

    if (!$bookingStart instanceof DateTimeImmutable) {
        throw new RuntimeException('Nie udało się wyliczyć ważności tokenu rezerwacji.');
    }

    return $bookingStart->format(DateTimeInterface::ATOM);
}

function bookingPublicBaseUrl(): string
{
    $scheme = 'https';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0] ?? ''));

        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
        }
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? '';

    $host = trim(explode(',', (string) $host)[0] ?? '');

    if ($host === '' || !preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
        return '';
    }

    return $scheme . '://' . $host;
}

function bookingManageTokenIsActive(string $expiresAt): bool
{
    $expiresAt = trim($expiresAt);

    if ($expiresAt === '') {
        return false;
    }

    try {
        $expires = new DateTimeImmutable($expiresAt);
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

        return $expires > $now;
    } catch (Throwable $e) {
        return false;
    }
}

function bookingBuildRescheduleUrl(string $token, string $expiresAt): string
{
    $token = trim($token);

    if ($token === '' || !bookingManageTokenIsActive($expiresAt)) {
        return '';
    }

    $baseUrl = bookingPublicBaseUrl();

    if ($baseUrl === '') {
        return '';
    }

    return $baseUrl . '/przeloz-rezerwacje.html?token=' . rawurlencode($token);
}

function replacePlaceholders(string $text, array $data): string
{
    return str_replace(array_keys($data), array_values($data), $text);
}

function getSystemFooter(): string
{
  return
    '<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">' .
        'Obsługiwane przez <a href="https://ai-iq.pl" target="_blank" style="color:#607284;text-decoration:none;font-weight:600;">AI-IQ</a> | Inteligentne automatyzacje' .
    '</div>';
}

function buildFooter(string $plan, string $mode, string $custom): string
{
    if ($plan === 'basic') {
        return getSystemFooter();
    }

    if ($plan === 'pro') {
        return $mode === 'none' ? '' : getSystemFooter();
    }

    if ($plan === 'premium') {
        if ($mode === 'custom' && trim($custom) !== '') {
            return $custom;
        }
        if ($mode === 'none') {
            return '';
        }
        return getSystemFooter();
    }

    return getSystemFooter();
}

function buildClientEmailHtml(
    string $introHtml,
    string $companyName,
    string $emailHeading,
    string $footerHtml,
    string $name,
    string $email,
    string $date,
    string $time,
    string $note,
    string $bookedServiceName = '',
    string $staffDisplayName = '',
    string $rescheduleUrl = ''
): string {
    $serviceRow = trim($bookedServiceName) !== ''
        ? '<p style="margin:0 0 12px 0;font-size:16px;"><strong>🛠️ Usługa:</strong> ' . htmlspecialchars($bookedServiceName, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    $staffRow = trim($staffDisplayName) !== ''
        ? '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👥 Osoba obsługująca:</strong> ' . htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    $rescheduleUrl = trim($rescheduleUrl);
    $rescheduleSection = $rescheduleUrl !== ''
        ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;text-align:center;">' .
            '<h2 style="margin:0 0 10px 0;font-size:20px;color:#17324d;">Chcesz zmienić termin?</h2>' .
            '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#4f6478;">Jeśli ten termin Ci nie pasuje, możesz przełożyć rezerwację na inny dostępny termin.</p>' .
            '<a href="' . htmlspecialchars($rescheduleUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 20px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">Przełóż rezerwację</a>' .
            '<p style="margin:14px 0 0 0;font-size:13px;line-height:1.5;color:#607284;">Link jest ważny do momentu rozpoczęcia rezerwacji.</p>' .
          '</div>'
        : '';

    return
        '<div style="margin:0;padding:0;background:#f4f7fb;">' .
            '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">' .

                '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">' .
                    '<div style="font-size:42px;line-height:1;margin-bottom:12px;">✓</div>' .
                    '<h1 style="margin:0;font-size:28px;">Rezerwacja potwierdzona</h1>' .
                    '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($emailHeading, ENT_QUOTES, 'UTF-8') . ' | ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' .
                '</div>' .

                '<div style="padding:32px 24px;">' .
                    $introHtml .

                    '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>' .
                        $serviceRow .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📅 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>' .
                        $staffRow .
                        '<p style="margin:0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>' .
                    '</div>' .

                    (
                        trim($note) !== ''
                            ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                                '<p style="margin:0;font-size:16px;"><strong>💬 Twoja wiadomość:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' .
                              '</div>'
                            : ''
                    ) .

                    $rescheduleSection .

                    '<p style="font-size:14px;line-height:1.6;color:#4f6478;">W razie pytań po prostu odpowiedz na tę wiadomość.</p>' .
                '</div>' .

                $footerHtml .

            '</div>' .
        '</div>';
}

function buildAdminEmailHtml(
    string $introHtml,
    string $companyName,
    string $footerHtml,
    string $name,
    string $email,
    string $phone,
    string $date,
    string $time,
    string $note,
    string $staffDisplayName = ''
): string {
    $staffRow = trim($staffDisplayName) !== ''
        ? '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👥 Personel:</strong> ' . htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    return
        '<div style="margin:0;padding:0;background:#f4f7fb;">' .
            '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">' .

                '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">' .
                    '<div style="font-size:42px;line-height:1;margin-bottom:12px;">📬</div>' .
                    '<h1 style="margin:0;font-size:28px;">Nowa rezerwacja</h1>' .
                    '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' .
                '</div>' .

                '<div style="padding:32px 24px;">' .
                    $introHtml .

                    '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📞 Telefon:</strong> ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</p>' .
                        '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📅 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>' .
                        $staffRow .
                        '<p style="margin:0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>' .
                    '</div>' .

                    (
                        trim($note) !== ''
                            ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                                '<p style="margin:0;font-size:16px;"><strong>💬 Wiadomość klienta:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' .
                              '</div>'
                            : ''
                    ) .

                '</div>' .

                $footerHtml .

            '</div>' .
        '</div>';
}

function configureMailer(PHPMailer $mail, array $emailSettings): void
{
    $smtpHost = trim((string) ($emailSettings['smtp_host'] ?? ''));
    $smtpPort = (int) ($emailSettings['smtp_port'] ?? 587);

    $smtpUser = trim((string) (
        $emailSettings['smtp_user']
        ?? $emailSettings['smtp_username']
        ?? ''
    ));

    $smtpPass = (string) (
        $emailSettings['smtp_pass']
        ?? $emailSettings['smtp_password']
        ?? ''
    );

    $fromEmail = trim((string) (
        $emailSettings['smtp_email']
        ?? $emailSettings['from_email']
        ?? ''
    ));

    $fromName = trim((string) (
        $emailSettings['smtp_name']
        ?? $emailSettings['from_name']
        ?? ''
    ));

    if ($smtpHost === '') {
        throw new Exception('Brak smtp_host w email_settings');
    }

    if ($fromEmail === '') {
        throw new Exception('Brak smtp_email/from_email w email_settings');
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Nieprawidłowy adres nadawcy SMTP');
    }

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->Port = $smtpPort > 0 ? $smtpPort : 587;
    $mail->SMTPAuth = $smtpUser !== '' || $smtpPass !== '';
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    $encryption = strtolower(trim((string) ($emailSettings['smtp_encryption'] ?? 'tls')));

    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls' || $encryption === '') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'none') {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : $fromEmail);

    $replyToEmail = trim((string) ($emailSettings['reply_to_email'] ?? ''));

    if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $replyToName = trim((string) ($emailSettings['reply_to_name'] ?? ''));
        $mail->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $replyToEmail);
    }
}

// ENV
$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    json_response([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase',
    ], 500);
}

function booking_context_has_feature(array $bookingContext, string $featureKey): bool
{
    $features = is_array($bookingContext['plan_context']['features'] ?? null)
        ? $bookingContext['plan_context']['features']
        : [];

    return !empty($features[$featureKey]);
}

$TENANT_ID = '';

// Input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

if (!is_array($input) || empty($input)) {
    json_response([
        'success' => false,
        'error' => 'Brak danych',
    ], 400);
}

$date  = trim((string) ($input['date'] ?? ''));
$time  = trim((string) ($input['time'] ?? ''));
$name  = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$note  = trim((string) ($input['note'] ?? $input['message'] ?? ''));
$staffId = trim((string) ($input['staff_id'] ?? ''));
$serviceId = trim((string) ($input['service_id'] ?? ''));

$website = trim((string) ($input['website'] ?? ''));
$formStartedAtRaw = trim((string) ($input['form_started_at'] ?? ''));
$formFillTimeRaw = trim((string) ($input['form_fill_time_ms'] ?? ''));
$termsAcceptedRaw = $input['terms_accepted'] ?? null;

// Anty-spam / blacklist / rate limit
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bookingLoadTestIpAllowed = booking_load_test_ip_is_allowed($ip);

// Kontrolowany wyjątek testowy: po testach obciążeniowych ENV powinien być pusty lub nieustawiony.
if (!$bookingLoadTestIpAllowed) {
$blacklistFile = __DIR__ . '/../data/blacklist.json';
if (!file_exists($blacklistFile)) {
    @file_put_contents($blacklistFile, json_encode([], JSON_UNESCAPED_UNICODE));
}
$blacklist = json_decode(@file_get_contents($blacklistFile), true);
if (!is_array($blacklist)) {
    $blacklist = [];
}

if (in_array($ip, $blacklist, true)) {
    json_response([
        'success' => false,
        'error' => 'Dostęp zablokowany',
    ], 403);
}

$rateFile = __DIR__ . '/../data/rate_limit_book.json';
if (!file_exists($rateFile)) {
    @file_put_contents($rateFile, json_encode([], JSON_UNESCAPED_UNICODE));
}

$rateData = json_decode(@file_get_contents($rateFile), true);
if (!is_array($rateData)) {
    $rateData = [];
}

$now = time();
$limit = 3;
$window = 60;

if (!isset($rateData[$ip]) || !is_array($rateData[$ip])) {
    $rateData[$ip] = [];
}

$rateData[$ip] = array_values(array_filter($rateData[$ip], function ($t) use ($now, $window) {
    return ($now - (int) $t) < $window;
}));

if (count($rateData[$ip]) >= $limit) {
    $banFile = __DIR__ . '/../data/ban_counter.json';
    if (!file_exists($banFile)) {
        @file_put_contents($banFile, json_encode([], JSON_UNESCAPED_UNICODE));
    }

    $banData = json_decode(@file_get_contents($banFile), true);
    if (!is_array($banData)) {
        $banData = [];
    }

    if (!isset($banData[$ip])) {
        $banData[$ip] = 0;
    }

    $banData[$ip]++;
    @file_put_contents($banFile, json_encode($banData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    if ($banData[$ip] >= 5) {
        $blacklist[] = $ip;
        @file_put_contents(
            $blacklistFile,
            json_encode(array_values(array_unique($blacklist)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    json_response([
        'success' => false,
        'error' => 'Za dużo prób. Spróbuj za chwilę.',
    ], 429);
}

$rateData[$ip][] = $now;
@file_put_contents($rateFile, json_encode($rateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if (!isset($_SESSION['last_booking_time'])) {
    $_SESSION['last_booking_time'] = 0;
}

if (time() - (int) $_SESSION['last_booking_time'] < 10) {
    json_response([
        'success' => false,
        'error' => 'Poczekaj chwilę przed kolejną próbą',
    ], 429);
}

$_SESSION['last_booking_time'] = time();

// Cicha pułapka na boty — człowiek tego pola nie widzi.
if ($website !== '') {
    debug_log('BOOK_BOT_HONEYPOT_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'honeypot_triggered' => true,
    ]);

    json_response([
        'success' => false,
        'error' => 'Nie udało się zapisać rezerwacji. Spróbuj ponownie za chwilę.',
    ], 400);
}

// Zgoda regulaminu musi być potwierdzona również na backendzie.
$termsAccepted = in_array($termsAcceptedRaw, [true, 1, '1', 'true', 'on', 'yes'], true);

if (!$termsAccepted) {
    json_response([
        'success' => false,
        'error' => 'Zaakceptuj regulamin i politykę prywatności.',
    ], 400);
}

// Minimalny czas wypełnienia formularza.
// Date.now() z frontu wysyła milisekundy.
$formStartedAt = ctype_digit($formStartedAtRaw) ? (int) $formStartedAtRaw : 0;
$formSubmittedAt = (int) round(microtime(true) * 1000);
$hasClientFillTime = ctype_digit($formFillTimeRaw);

if ($formStartedAt <= 0) {
    debug_log('BOOK_BOT_MISSING_FORM_STARTED_AT', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
    ]);

    json_response([
        'success' => false,
        'error' => 'Odśwież stronę i spróbuj ponownie.',
    ], 400);
}

$formFillTimeMs = $hasClientFillTime
    ? (int) $formFillTimeRaw
    : $formSubmittedAt - $formStartedAt;

$formFillTimeSource = $hasClientFillTime
    ? 'form_fill_time_ms'
    : 'server_minus_client_started_at';

if ($formFillTimeMs < 3000) {
    debug_log('BOOK_BOT_TOO_FAST_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'form_fill_time_ms' => $formFillTimeMs,
        'source' => $formFillTimeSource,
    ]);

    json_response([
        'success' => false,
        'error' => 'Formularz został wysłany zbyt szybko. Spróbuj ponownie.',
    ], 400);
}

if ($formFillTimeMs > 1000 * 60 * 60 * 6) {
    debug_log('BOOK_FORM_TOO_OLD_BLOCKED', [
        'ip' => $ip,
        'tenant_id' => $TENANT_ID,
        'form_fill_time_ms' => $formFillTimeMs,
    ]);

    json_response([
        'success' => false,
        'error' => 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.',
    ], 400);
}

if ($date === '' || $time === '' || $name === '') {
    json_response([
        'success' => false,
        'error' => 'Brak wymaganych danych',
    ], 400);
}

if (mb_strlen($name) > 120) {
    json_response([
        'success' => false,
        'error' => 'Imię i nazwisko jest za długie.'
    ], 400);
}

if ($email !== '' && mb_strlen($email) > 190) {
    json_response([
        'success' => false,
        'error' => 'Adres e-mail jest za długi.'
    ], 400);
}

if (mb_strlen($note) > 1000) {
    json_response([
        'success' => false,
        'error' => 'Wiadomość jest za długa.'
    ], 400);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy format daty',
    ], 400);
}

$dateCheck = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateCheck || $dateCheck->format('Y-m-d') !== $date) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowa data',
    ], 400);
}

if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy format godziny',
    ], 400);
}

if ($serviceId !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $serviceId)) {
    json_response([
        'success' => false,
        'error' => 'Wybrana usługa jest niedostępna.',
    ], 404);
}

if ($staffId !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $staffId)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy personel',
    ], 400);
}

try {
$bookingGlobalSemaphoreHandle = booking_acquire_global_semaphore(3, 40000, 150000);

if (!is_resource($bookingGlobalSemaphoreHandle)) {
    json_response([
        'success' => false,
        'error' => 'temporary_unavailable',
        'message' => 'W tym momencie system obsługuje dużo rezerwacji. Spróbuj ponownie za chwilę.'
    ], 503);
}

$bookingGlobalSemaphoreAcquired = true;

// Tenant po domenie
$tenantLookup = getTenantLookupFromHost(
    $SUPABASE_URL,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA,
    'booking_log_supabase_transient_error'
);
$tenantLookupStatus = (string)($tenantLookup['status'] ?? '');

if ($tenantLookupStatus === 'found' && !empty($tenantLookup['tenant_id'])) {
    $TENANT_ID = (string)$tenantLookup['tenant_id'];
    debug_log('BOOK_TENANT_FINAL', $TENANT_ID);
} elseif ($tenantLookupStatus === 'not_found') {
    debug_log('BOOK_TENANT_NOT_FOUND', [
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'server_name' => $_SERVER['SERVER_NAME'] ?? null,
    ]);

    json_response([
        'success' => false,
        'error' => 'tenant_not_found',
        'message' => 'Ten adres nie jest zarejestrowany w AI-IQ Rezerwacja Pro.',
    ], 404);
} else {
    $tenantLookupHttpCode = (int)($tenantLookup['http_code'] ?? 503);
    $tenantLookupResponseCode = $tenantLookupHttpCode === 429 ? 429 : 503;

    debug_log('BOOK_TENANT_TECHNICAL_ERROR', [
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'server_name' => $_SERVER['SERVER_NAME'] ?? null,
        'lookup_status' => $tenantLookupStatus,
        'http_code' => $tenantLookupHttpCode,
    ]);

    json_response([
        'success' => false,
        'error' => 'temporary_unavailable',
        'message' => 'Nie udało się chwilowo potwierdzić domeny kalendarza. Spróbuj ponownie za moment.',
    ], $tenantLookupResponseCode);
}

$bookingContext = [
    'tenant_id' => $TENANT_ID,
    'matched_domain' => (string)($tenantLookup['host'] ?? ''),
    'calendar_settings' => [],
    'tenant_branding' => [],
    'plan_context' => [],
    'selected_service' => null,
    'service_staff_ids' => [],
    'staff' => null,
    'staff_availability' => [],
    'service_settings' => null,
    'public_integrations' => [],
    'email_template' => null,
];

$headers = supabase_headers($SUPABASE_KEY, $SUPABASE_DB_SCHEMA, false);
$minimalHeaders = supabase_headers($SUPABASE_KEY, $SUPABASE_DB_SCHEMA, true);

$calendarSettingsUrl = $SUPABASE_URL
    . '/rest/v1/calendar_settings'
    . '?select=calendar_enabled,consultation_duration,consultation_break,booking_buffer'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';

$calendarSettingsResult = supabase_select($calendarSettingsUrl, $headers, 'calendar_settings', $TENANT_ID);

if (supabase_select_is_temporary($calendarSettingsResult)) {
    booking_temporary_unavailable('Nie udało się chwilowo pobrać ustawień kalendarza. Spróbuj ponownie za moment.');
}

$calendarSettingsRow = $calendarSettingsResult['data'][0] ?? [];
$bookingContext['calendar_settings'] = is_array($calendarSettingsRow) ? $calendarSettingsRow : [];

$calendarEnabled = !empty($calendarSettingsRow['calendar_enabled']);

if ($calendarEnabled !== true) {
    json_response([
        'success' => false,
        'error' => 'Kalendarz rezerwacji jest obecnie wyłączony.',
    ], 403);
}

$formFieldsUrl = $SUPABASE_URL
    . '/rest/v1/tenant_branding'
    . '?select=calendar_form_fields,client_name,email_footer_mode,email_footer_custom'
    . '&tenant_id=eq.' . rawurlencode($TENANT_ID)
    . '&limit=1';

$formFieldsResult = supabase_select($formFieldsUrl, $headers, 'tenant_branding', $TENANT_ID);
$tenantBrandingRow = is_array($formFieldsResult['data'][0] ?? null)
    ? $formFieldsResult['data'][0]
    : [];
$bookingContext['tenant_branding'] = $tenantBrandingRow;
$formFields = is_array($tenantBrandingRow['calendar_form_fields'] ?? null)
    ? $tenantBrandingRow['calendar_form_fields']
    : [];

$requireEmail = true;
$requirePhone = ($formFields['show_phone'] ?? true) !== false;

if ($requireEmail) {
    if ($email === '') {
        json_response([
            'success' => false,
            'error' => 'Brak adresu e-mail',
        ], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy adres e-mail',
        ], 400);
    }
} else {
    $email = '';
}

if ($requirePhone) {
    if ($phone === '') {
        json_response([
            'success' => false,
            'error' => 'Brak numeru telefonu',
        ], 400);
    }

    if (!is_valid_international_phone($phone)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy numer telefonu',
        ], 400);
    }

    $phone = trim(preg_replace('/\s+/', ' ', $phone) ?? '');
} else {
    $phone = '';
}

$planContext = plan_features_get_context($TENANT_ID, 'booking_log_supabase_transient_error');

if (!empty($planContext['temporary_error'])) {
    booking_temporary_unavailable('Nie udało się chwilowo sprawdzić uprawnień konta. Spróbuj ponownie za moment.');
}

$bookingContext['plan_context'] = $planContext;

$staffDisplayName = '';
$staffServiceName = '';
$staffServicePrice = null;
$staffPaymentsEnabled = null;
$staffEmailSubject = '';
$staffEmailHeading = '';
$staffEmailBody = '';
$globalBookingBuffer = max(0, (int) ($calendarSettingsRow['booking_buffer'] ?? 0));
$selectedService = null;
$selectedServiceStaffIds = [];

if ($serviceId !== '') {
    if (!booking_context_has_feature($bookingContext, 'multiple_services')) {
        json_response([
            'success' => false,
            'error' => 'Wiele usług jest dostępne w wersji Pro.',
            'feature' => 'multiple_services',
            'upgrade_required' => true,
        ], 403);
    }

    $selectedServiceResult = fetch_public_service_for_booking_result($SUPABASE_URL, $headers, $TENANT_ID, $serviceId);

    if (!empty($selectedServiceResult['temporary'])) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić wybranej usługi. Spróbuj ponownie za moment.');
    }

    $selectedService = $selectedServiceResult['row'] ?? null;
    $bookingContext['selected_service'] = $selectedService;

    if (!is_array($selectedService) || empty($selectedService['id'])) {
        json_response([
            'success' => false,
            'error' => 'Wybrana usługa jest niedostępna.',
        ], 404);
    }

    $selectedServiceStaffResult = fetch_service_staff_ids_for_booking_result($SUPABASE_URL, $headers, $TENANT_ID, $serviceId);

    if (!empty($selectedServiceStaffResult['temporary'])) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić personelu dla wybranej usługi. Spróbuj ponownie za moment.');
    }

    if (empty($selectedServiceStaffResult['ok'])) {
        json_response([
            'success' => false,
            'error' => 'Nie udało się sprawdzić personelu dla wybranej usługi.',
        ], 500);
    }

    $selectedServiceStaffIds = $selectedServiceStaffResult['staffIds'];
    $bookingContext['service_staff_ids'] = $selectedServiceStaffIds;

    if (!empty($selectedServiceStaffIds)) {
        if ($staffId === '') {
            json_response([
                'success' => false,
                'error' => 'Wybierz osobę obsługującą tę usługę.',
            ], 400);
        }

        if (!in_array($staffId, $selectedServiceStaffIds, true)) {
            json_response([
                'success' => false,
                'error' => 'Wybrana osoba nie obsługuje tej usługi.',
            ], 422);
        }
    } elseif ($staffId !== '') {
        json_response([
            'success' => false,
            'error' => 'Ta usługa nie wymaga wyboru personelu.',
        ], 422);
    }
}

if ($staffId !== '') {
    if (!booking_context_has_feature($bookingContext, 'staff_module')) {
        json_response([
            'success' => false,
            'error' => 'Rezerwacja do pracownika jest dostępna w wersji Pro.',
            'feature' => 'staff_module',
            'upgrade_required' => true,
        ], 403);
    }

    $subscriptionContext = $bookingContext['plan_context'];

    $planCode = (string) ($subscriptionContext['subscription_plan_code'] ?? 'free');
    $subscriptionStatus = (string) ($subscriptionContext['status'] ?? '');
    $currentPeriodEnd = (string) ($subscriptionContext['current_period_end'] ?? '');

    if (!booking_subscription_allows_staff($planCode, $subscriptionStatus, $currentPeriodEnd)) {
        json_response([
            'success' => false,
            'error' => 'Personel jest niedostępny',
        ], 403);
    }

    $staffResult = fetch_public_staff_for_booking_result($SUPABASE_URL, $headers, $TENANT_ID, $staffId);

    if (!empty($staffResult['temporary'])) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić personelu. Spróbuj ponownie za moment.');
    }

    $staffRow = $staffResult['row'] ?? null;
    $bookingContext['staff'] = $staffRow;

    if (!is_array($staffRow) || empty($staffRow['id'])) {
        json_response([
            'success' => false,
            'error' => 'Wybrana osoba jest niedostępna',
        ], 404);
    }

    $staffDisplayName = trim((string) ($staffRow['display_name'] ?? ''));
    $staffServiceName = trim((string) ($staffRow['service_name'] ?? ''));
    $staffEmailSubject = trim((string) ($staffRow['email_subject'] ?? ''));
    $staffEmailHeading = trim((string) ($staffRow['email_heading'] ?? ''));
    $staffEmailBody = trim((string) ($staffRow['email_body'] ?? ''));
    $staffServicePrice = booking_nullable_float($staffRow, 'service_price');
    $staffPaymentsEnabled = array_key_exists('payments_enabled', $staffRow) && $staffRow['payments_enabled'] !== null
        ? filter_var($staffRow['payments_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        : null;

    $staffDuration = booking_nullable_int($staffRow, 'service_duration_minutes');
    $staffBreak = booking_nullable_int($staffRow, 'service_break_minutes');
    $serviceDuration = is_array($selectedService) ? booking_nullable_int($selectedService, 'duration_minutes') : null;
    $serviceBreak = is_array($selectedService) ? booking_nullable_int($selectedService, 'break_minutes') : null;
    $serviceBuffer = is_array($selectedService) ? booking_nullable_int($selectedService, 'booking_buffer_minutes') : null;

    $effectiveDuration = max(1, $serviceDuration ?? $staffDuration ?? (int)($calendarSettingsRow['consultation_duration'] ?? 60));
    $effectiveBreak = max(0, $serviceBreak ?? $staffBreak ?? (int)($calendarSettingsRow['consultation_break'] ?? 0));
    $effectiveBuffer = booking_effective_min_notice_minutes($serviceBuffer, $globalBookingBuffer);

    if (!booking_slot_respects_buffer($date, $time, $effectiveBuffer)) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest już niedostępna',
        ], 409);
    }

    $globalSlotAvailable = booking_global_slot_is_available($SUPABASE_URL, $headers, $TENANT_ID, $date, $time, $staffId);

    if ($globalSlotAvailable === null) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić blokad terminu. Spróbuj ponownie za moment.');
    }

    if ($globalSlotAvailable === false) {
        booking_slot_taken_response();
    }

    $staffAvailabilityResult = fetch_staff_availability_for_booking_result(
        $SUPABASE_URL,
        $headers,
        $TENANT_ID,
        $staffId,
        $date
    );

    if (!empty($staffAvailabilityResult['temporary'])) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić grafiku personelu. Spróbuj ponownie za moment.');
    }

    if (empty($staffAvailabilityResult['ok'])) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest niedostępna dla tej osoby',
        ], 409);
    }

    $bookingContext['staff_availability'] = $staffAvailabilityResult['rows'];
    $staffSlotMatchesSchedule = staff_slot_matches_schedule(
        $bookingContext['staff_availability'],
        $time,
        $effectiveDuration,
        $effectiveBreak
    );

    if ($staffSlotMatchesSchedule === false) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest niedostępna dla tej osoby',
        ], 409);
    }

    $knownServicesById = [];

    $contextService = $bookingContext['selected_service'] ?? null;

    if (is_array($contextService) && !empty($contextService['id'])) {
        $knownServicesById[(string)$contextService['id']] = $contextService;
    }

    $staffSlotIsFree = staff_slot_is_free(
        $SUPABASE_URL,
        $headers,
        $TENANT_ID,
        $staffId,
        $date,
        $time,
        $effectiveDuration,
        $effectiveBreak,
        $knownServicesById
    );

    if ($staffSlotIsFree === null) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić zajętości terminu. Spróbuj ponownie za moment.');
    }

    if ($staffSlotIsFree === false) {
        booking_slot_taken_response();
    }
} else {
    if (!booking_slot_respects_buffer(
        $date,
        $time,
        booking_effective_min_notice_minutes(
            is_array($selectedService) ? booking_nullable_int($selectedService, 'booking_buffer_minutes') : null,
            $globalBookingBuffer
        )
    )) {
        json_response([
            'success' => false,
            'error' => 'Wybrana godzina jest już niedostępna',
        ], 409);
    }

    $globalSlotAvailable = booking_global_slot_is_available($SUPABASE_URL, $headers, $TENANT_ID, $date, $time);

    if ($globalSlotAvailable === null) {
        booking_temporary_unavailable('Nie udało się chwilowo sprawdzić blokad terminu. Spróbuj ponownie za moment.');
    }

    if ($globalSlotAvailable === false) {
        booking_slot_taken_response();
    }
}

$googleEventDurationMinutes = max(1, (int) (
    (is_array($selectedService) ? booking_nullable_int($selectedService, 'duration_minutes') : null)
    ?? ($effectiveDuration ?? null)
    ?? (int) ($calendarSettingsRow['consultation_duration'] ?? 60)
));

// Ustawienia usługi i płatności
$tenantQuery = 'tenant_id=eq.' . rawurlencode($TENANT_ID);

$serviceSettings = fetch_single_record(
    $SUPABASE_URL,
    $headers,
    'tenant_service_settings',
    $tenantQuery . '&select=service_name,payment_required,price_amount,price_currency,payment_time_limit_value,payment_time_limit_unit,company_full_name,company_email',
    'service_settings',
    $TENANT_ID
);
$bookingContext['service_settings'] = $serviceSettings;

$paymentRequired = false;
$paymentStatus = 'not_required';
$paymentProvider = null;
$paymentAmount = null;
$paymentCurrency = 'PLN';
$paymentExpiresAt = null;

$paymentRequiredConfigured = false;
$payuEnabled = false;
$globalServiceName = '';
$configuredAmount = 0.0;
$configuredCurrency = 'PLN';
$paymentLimitValue = 48;
$paymentLimitUnit = 'hours';
$globalPaymentRequiredConfigured = false;

if (is_array($serviceSettings)) {
    $globalServiceName = trim((string) ($serviceSettings['service_name'] ?? ''));
    $globalPaymentRequiredConfigured = !empty($serviceSettings['payment_required']);
    $paymentRequiredConfigured = $globalPaymentRequiredConfigured;
    $configuredAmount = isset($serviceSettings['price_amount'])
        ? (float) $serviceSettings['price_amount']
        : 0.0;

    $configuredCurrency = trim((string) ($serviceSettings['price_currency'] ?? 'PLN'));

    if ($configuredCurrency === '') {
        $configuredCurrency = 'PLN';
    }

    $paymentLimitValue = (int) ($serviceSettings['payment_time_limit_value'] ?? 48);
    $paymentLimitUnit = (string) ($serviceSettings['payment_time_limit_unit'] ?? 'hours');
}

if (is_array($selectedService)) {
    $globalServiceName = trim((string) ($selectedService['name'] ?? ''));
    $paymentRequiredConfigured = !empty($selectedService['payments_enabled']);
    $configuredAmount = booking_nullable_float($selectedService, 'price_amount') ?? 0.0;
    $configuredCurrency = trim((string) ($selectedService['price_currency'] ?? 'PLN'));

    if ($configuredCurrency === '') {
        $configuredCurrency = 'PLN';
    }
}

$effectivePaymentRequiredConfigured = $paymentRequiredConfigured;
$effectivePaymentAmount = $configuredAmount;
$effectivePaymentCurrency = $configuredCurrency;

if ($staffId !== '' && !is_array($selectedService)) {
    if ($staffPaymentsEnabled === true) {
        $effectivePaymentRequiredConfigured = $globalPaymentRequiredConfigured;
    } elseif ($staffPaymentsEnabled === false) {
        $effectivePaymentRequiredConfigured = false;
    }

    if ($staffServicePrice !== null && $staffServicePrice > 0) {
        $effectivePaymentAmount = $staffServicePrice;
    }
}

if (
    !booking_context_has_feature($bookingContext, 'online_payments')
    || !booking_context_has_feature($bookingContext, 'payu')
) {
    $effectivePaymentRequiredConfigured = false;
}

if ($effectivePaymentRequiredConfigured && $effectivePaymentAmount <= 0) {
    json_response([
        'success' => false,
        'error' => 'Brak poprawnej kwoty płatności dla wybranej usługi.',
    ], 422);
}

if ($effectivePaymentRequiredConfigured) {
    $payuIntegration = fetch_single_record(
        $SUPABASE_URL,
        $headers,
        'tenant_integrations',
        $tenantQuery . '&provider=eq.payu&select=enabled',
        'payu_settings',
        $TENANT_ID
    );

    if (is_array($payuIntegration)) {
        $payuEnabled = !empty($payuIntegration['enabled']);
    }
}

$bookingContext['public_integrations']['payu_enabled'] = $payuEnabled;

$paymentRequiredConfigured = $effectivePaymentRequiredConfigured;
$paymentRequired = $paymentRequiredConfigured && $payuEnabled;

if ($paymentRequired) {
    $paymentStatus = 'pending';
    $paymentProvider = 'payu';
    $paymentAmount = $effectivePaymentAmount > 0 ? $effectivePaymentAmount : null;
    $paymentCurrency = $effectivePaymentCurrency;
    $paymentExpiresAt = calculate_payment_expires_at($paymentLimitValue, $paymentLimitUnit);
}

debug_log('BOOK_PAYMENT_SETTINGS', [
    'payment_required_configured' => $paymentRequiredConfigured,
    'payu_enabled' => $payuEnabled,
    'payment_required' => $paymentRequired,
    'payment_status' => $paymentStatus,
    'payment_provider' => $paymentProvider,
    'payment_amount' => $paymentAmount,
    'payment_currency' => $paymentCurrency,
    'payment_expires_at' => $paymentExpiresAt,
]);

$serviceNameSnapshot = $globalServiceName;

if ($staffId !== '' && $staffServiceName !== '' && !is_array($selectedService)) {
    $serviceNameSnapshot = $staffServiceName;
}

$manageToken = '';
$manageTokenExpiresAt = '';

try {
    $manageToken = generateBookingManageToken();
    $manageTokenExpiresAt = calculateBookingManageTokenExpiresAt($date, $time);
} catch (Throwable $e) {
    debug_log('BOOK_MANAGE_TOKEN_ERROR', [
        'exception_type' => get_class($e),
        'tenant_id' => $TENANT_ID,
        'booking_date' => $date,
        'booking_time' => $time,
    ]);

    json_response([
        'success' => false,
        'error' => 'Nie udało się przygotować rezerwacji. Spróbuj ponownie.',
    ], 500);
}

$bookingSlotLockKey = booking_slot_lock_key($TENANT_ID, $serviceId, $staffId, $date, $time);
$bookingSlotLockHandle = booking_acquire_slot_lock($bookingSlotLockKey, 6000);

if (!is_resource($bookingSlotLockHandle)) {
    json_response([
        'success' => false,
        'error' => 'temporary_unavailable',
        'message' => 'Nie udało się chwilowo potwierdzić dostępności terminu. Spróbuj ponownie za moment.'
    ], 503);
}

$quickSlotIsFree = booking_quick_slot_is_free($SUPABASE_URL, $headers, $TENANT_ID, $staffId, $date, $time);

if ($quickSlotIsFree === null) {
    json_response([
        'success' => false,
        'error' => 'temporary_unavailable',
        'message' => 'Nie udało się chwilowo potwierdzić dostępności terminu. Spróbuj ponownie za moment.'
    ], 503);
}

if ($quickSlotIsFree === false) {
    booking_slot_taken_response();
}

// Zapis rezerwacji
$bookingPayload = [
    'tenant_id'    => $TENANT_ID,
    'booking_date' => $date,
    'booking_time' => $time,
    'name'         => $name,
    'email'        => $email,
    'phone'        => $phone,
    'notes'        => $note,
    'status'       => 'new',
    'source'       => 'www',
    'service_name_snapshot' => $serviceNameSnapshot !== '' ? $serviceNameSnapshot : null,

    'payment_required'   => $paymentRequired,
    'payment_status'     => $paymentStatus,
    'payment_provider'   => $paymentProvider,
    'payment_amount'     => $paymentAmount,
    'payment_currency'   => $paymentCurrency,
    'payment_expires_at' => $paymentExpiresAt,

    'manage_token' => $manageToken,
    'manage_token_expires_at' => $manageTokenExpiresAt,

    'created_at'   => date('c'),
    'updated_at'   => date('c'),
];

if ($staffId !== '') {
    $bookingPayload['staff_id'] = $staffId;
}

if ($serviceId !== '' && is_array($selectedService)) {
    $bookingPayload['service_id'] = $serviceId;
}

booking_debug_log_service([
    'event' => 'before_booking_insert',
    'tenant_id' => $TENANT_ID,
    'received_service_id' => $serviceId,
    'received_staff_id' => $staffId,
    'selected_service_found' => is_array($selectedService),
    'booking_payload_has_service_id' => array_key_exists('service_id', $bookingPayload),
    'booking_payload_service_id' => $bookingPayload['service_id'] ?? null,
    'service_name_snapshot' => $bookingPayload['service_name_snapshot'] ?? null,
    'booking_date' => $date,
    'booking_time' => $time,
    'payment_required' => $paymentRequired,
    'status' => $bookingPayload['status'] ?? null,
]);

$bookingResult = supabase_insert(
    $SUPABASE_URL . '/rest/v1/bookings',
    $bookingPayload,
    $headers,
    'bookings_insert',
    $TENANT_ID
);

$bookingRowsForDebug = json_decode((string)($bookingResult['response'] ?? ''), true);
$bookingIdForDebug = is_array($bookingRowsForDebug) && isset($bookingRowsForDebug[0]) && is_array($bookingRowsForDebug[0])
    ? (string)($bookingRowsForDebug[0]['id'] ?? '')
    : '';

booking_debug_log_service([
    'event' => 'after_booking_insert',
    'tenant_id' => $TENANT_ID,
    'received_service_id' => $serviceId,
    'received_staff_id' => $staffId,
    'selected_service_found' => is_array($selectedService),
    'booking_payload_has_service_id' => array_key_exists('service_id', $bookingPayload),
    'booking_payload_service_id' => $bookingPayload['service_id'] ?? null,
    'service_name_snapshot' => $bookingPayload['service_name_snapshot'] ?? null,
    'booking_date' => $date,
    'booking_time' => $time,
    'payment_required' => $paymentRequired,
    'status' => $bookingPayload['status'] ?? null,
    'insert_success' => !$bookingResult['error'] && $bookingResult['httpCode'] < 400,
    'insert_error' => $bookingResult['error'] ? substr((string) $bookingResult['error'], 0, 180) : null,
    'booking_id' => $bookingIdForDebug !== '' ? $bookingIdForDebug : null,
]);

debug_log('BOOK_BOOKINGS_RESPONSE', [
    'httpCode' => $bookingResult['httpCode'],
    'has_error' => $bookingResult['error'] !== '',
    'tenant_id' => $TENANT_ID,
]);

if ($bookingResult['error'] || $bookingResult['httpCode'] >= 400) {
    booking_insert_error_response($bookingResult);
}

$bookingRows = json_decode((string)($bookingResult['response'] ?? ''), true);
$createdBooking = is_array($bookingRows) && isset($bookingRows[0]) && is_array($bookingRows[0])
    ? $bookingRows[0]
    : [];

$bookingId = (string)($createdBooking['id'] ?? '');

debug_log('BOOK_CREATED_ID', $bookingId !== '' ? $bookingId : 'BRAK_ID');

// Blokada terminu dla starego trybu globalnego.
// Rezerwacje personelu blokujemy przez bookings.staff_id, bez założenia kolumny staff_id w blocked_times.
if ($staffId === '') {
    $blockPayload = [
        'tenant_id' => $TENANT_ID,
        'date'      => $date,
        'time'      => $time,
    ];

    $blockResult = supabase_insert(
        $SUPABASE_URL . '/rest/v1/blocked_times',
        $blockPayload,
        $minimalHeaders,
        'blocked_times_insert',
        $TENANT_ID
    );

    debug_log('BOOK_BLOCKED_TIMES_RESPONSE', [
        'httpCode' => $blockResult['httpCode'],
        'has_error' => $blockResult['error'] !== '',
        'booking_id' => $bookingId,
        'tenant_id' => $TENANT_ID,
        'date' => $date,
        'time' => $time,
    ]);

    if ($blockResult['error'] || $blockResult['httpCode'] >= 400) {
        debug_log('BOOK_BLOCKED_TIMES_AUXILIARY_FAILED', [
            'httpCode' => $blockResult['httpCode'],
            'error' => $blockResult['error'] ? substr((string) $blockResult['error'], 0, 180) : null,
            'booking_id' => $bookingId,
            'tenant_id' => $TENANT_ID,
            'date' => $date,
            'time' => $time,
        ]);
    }
}

} finally {
    if (isset($bookingSlotLockHandle)) {
        booking_release_slot_lock($bookingSlotLockHandle);
        $bookingSlotLockHandle = null;
    }

    if ($bookingGlobalSemaphoreAcquired) {
        booking_release_global_semaphore($bookingGlobalSemaphoreHandle);
        $bookingGlobalSemaphoreHandle = null;
        $bookingGlobalSemaphoreAcquired = false;
    }
}

booking_supabase_request_phase('post_insert');
$postprocessQueued = booking_postprocess_queue_enqueue($bookingId, $TENANT_ID);
$mailSentClient = false;
$mailSentAdmin = false;

if (!$postprocessQueued) {
    $postprocessQueueError = function_exists('booking_postprocess_queue_last_error')
        ? booking_postprocess_queue_last_error()
        : ['reason' => 'unknown'];
    $postprocessQueueReason = trim((string)($postprocessQueueError['reason'] ?? 'unknown'));
    $postprocessQueueLogContext = [
        'booking_id' => $bookingId,
        'tenant_id' => $TENANT_ID,
        'reason' => $postprocessQueueReason !== '' ? $postprocessQueueReason : 'unknown',
    ];

    foreach (['target_dir', 'target_path', 'directory', 'job_id'] as $postprocessQueueLogKey) {
        if (
            isset($postprocessQueueError[$postprocessQueueLogKey])
            && is_scalar($postprocessQueueError[$postprocessQueueLogKey])
            && trim((string)$postprocessQueueError[$postprocessQueueLogKey]) !== ''
        ) {
            $postprocessQueueLogContext[$postprocessQueueLogKey] = trim((string)$postprocessQueueError[$postprocessQueueLogKey]);
        }
    }

    debug_log('BOOK_POSTPROCESS_ENQUEUE_FAILED', $postprocessQueueLogContext);
}

if ($paymentRequired) {
    booking_payment_handoff_store($bookingId, $TENANT_ID);
} else {
    unset($_SESSION['booking_payment_handoff']);
}

// Finalna odpowiedź
json_response([
    'success' => true,
    'message' => 'Rezerwacja zapisana',

    'payment_required_configured' => $paymentRequiredConfigured,
    'payment_provider_enabled' => $payuEnabled,
    'payment_required' => $paymentRequired,
    'payment_status' => $paymentStatus,
    'payment_provider' => $paymentProvider,
    'payment_amount' => $paymentAmount,
    'payment_currency' => $paymentCurrency,
    'payment_expires_at' => $paymentExpiresAt,

    'mail_queued' => $postprocessQueued,
    'postprocess_queued' => $postprocessQueued,
], 200);
