<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/security.php';

function cron_payments_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (PHP_SAPI === 'cli') {
        exit($statusCode >= 400 ? 1 : 0);
    }

    exit;
}

function cron_payments_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string)$value);
    return $value !== '' ? $value : $default;
}

function cron_payments_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function cron_payments_security_event(
    string $eventKey,
    int $responseStatus,
    string $result,
    string $reason,
    string $severity = 'medium',
    string $stage = '',
    array $extraDetails = []
): void {
    $details = $extraDetails;
    $details['reason'] = $reason;

    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'cron_pending_payments',
        'endpoint' => '/api/cron/check-pending-payments.php',
        'http_method' => cron_payments_is_cli()
            ? 'CLI'
            : strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')),
        'actor_type' => 'system',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

function cron_payments_request_secret(): string
{
    $headerSecret = trim((string)($_SERVER['HTTP_X_CRON_SECRET'] ?? ''));

    if ($headerSecret !== '') {
        return $headerSecret;
    }

    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if ($authorization === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authorization = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string)$matches[1]);
    }

    return '';
}

function cron_payments_expected_secret(): string
{
    $secret = cron_payments_env('CHECK_PENDING_PAYMENTS_CRON_SECRET');

    if ($secret !== '') {
        return $secret;
    }

    $secret = cron_payments_env('PAYMENTS_CRON_SECRET');

    if ($secret !== '') {
        return $secret;
    }

    return cron_payments_env('CRON_SECRET');
}

function cron_payments_require_authorization(): void
{
    if (cron_payments_is_cli()) {
        return;
    }

    $expectedSecret = cron_payments_expected_secret();

    if ($expectedSecret === '') {
        cron_payments_security_event(
            'cron_payments_secret_missing_env',
            401,
            'denied',
            'cron_secret_missing',
            'high',
            'auth'
        );

        cron_payments_response([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }

    $providedSecret = cron_payments_request_secret();

    if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        cron_payments_security_event(
            'cron_payments_unauthorized',
            401,
            'denied',
            'unauthorized',
            'medium',
            'auth'
        );

        cron_payments_response([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }
}

function cron_payments_trace(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    return substr(hash('sha256', $value), 0, 16);
}

function cron_payments_get_supabase_config(): array
{
    $supabaseUrl = rtrim(cron_payments_env('SUPABASE_URL'), '/');
    $supabaseKey = cron_payments_env('SUPABASE_SERVICE_ROLE_KEY');
    $schema = cron_payments_env('SUPABASE_DB_SCHEMA', 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        throw new RuntimeException('Brak konfiguracji Supabase.');
    }

    if ($schema !== 'rezerwacja_pro') {
        throw new RuntimeException('Nieprawidłowa konfiguracja schematu Supabase.');
    }

    return [$supabaseUrl, $supabaseKey, $schema];
}

function cron_payments_fetch_records(string $query): array
{
    [$supabaseUrl, $supabaseKey, $schema] = cron_payments_get_supabase_config();

    $url = $supabaseUrl . '/rest/v1/bookings?' . $query;
    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('CRON_PAYMENTS_FETCH_ERROR', [
            'query_trace' => cron_payments_trace($query),
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null && $result['error'] !== '',
            'has_response' => $result['response'] !== null && $result['response'] !== '',
        ]);

        throw new RuntimeException('Nie udało się pobrać rezerwacji do obsługi płatności.');
    }

    return is_array($result['data']) ? $result['data'] : [];
}

function cron_payments_process_reminders(DateTimeImmutable $now): array
{
    $warsaw = new DateTimeZone('Europe/Warsaw');
    $localNow = $now->setTimezone($warsaw);

    if ($localNow->format('H:i:s') < '09:00:00') {
        return [
            'checked' => 0,
            'queued' => 0,
            'feature_skipped' => 0,
            'state_skipped' => 0,
            'failed' => 0,
            'window' => 'before_09_00_europe_warsaw',
        ];
    }

    $todayStart = $localNow->setTime(0, 0, 0);

    $query = http_build_query([
        'select' => 'id,tenant_id',
        'payment_lifecycle_version' => 'eq.1',
        'payment_required' => 'eq.true',
        'status' => 'eq.pending_payment',
        'payment_status' => 'eq.pending',
        'payment_resolution_required' => 'eq.false',
        'payment_started_at' => 'lt.' . $todayStart->format(DATE_ATOM),
        'payment_expires_at' => 'gt.' . $now->format(DATE_ATOM),
        'payment_url' => 'not.is.null',
        'or' => '('
            . 'payment_reminder_sent_at.is.null,'
            . 'payment_reminder_sent_at.lt.' . $todayStart->format(DATE_ATOM)
            . ')',
        'order' => 'payment_started_at.asc',
        'limit' => '100',
    ]);

    $records = cron_payments_fetch_records($query);

    $checked = count($records);
    $queued = 0;
    $featureSkipped = 0;
    $stateSkipped = 0;
    $failed = 0;
    $featureCache = [];

    foreach ($records as $booking) {
        $bookingId = trim((string)($booking['id'] ?? ''));
        $tenantId = trim((string)($booking['tenant_id'] ?? ''));

        if ($bookingId === '' || $tenantId === '') {
            $failed++;
            continue;
        }

        try {
            if (!array_key_exists($tenantId, $featureCache)) {
                $featureCache[$tenantId] = tenant_has_feature(
                    $tenantId,
                    'payment_reminders'
                );
            }
        } catch (Throwable $e) {
            $failed++;

            payu_debug('CRON_PAYMENTS_REMINDER_FEATURE_CHECK_ERROR', [
                'tenant_trace' => cron_payments_trace($tenantId),
                'error_class' => get_class($e),
                'message_trace' => cron_payments_trace($e->getMessage()),
            ]);

            continue;
        }

        if ($featureCache[$tenantId] !== true) {
            $featureSkipped++;
            continue;
        }

        $rpc = payment_lifecycle_v3_rpc(
            'booking_payment_enqueue_due_reminder',
            [
                'p_tenant_id' => $tenantId,
                'p_booking_id' => $bookingId,
            ]
        );

        if (($rpc['ok'] ?? false) !== true || !is_array($rpc['data'] ?? null)) {
            $failed++;

            payu_debug('CRON_PAYMENTS_REMINDER_RPC_ERROR', [
                'tenant_trace' => cron_payments_trace($tenantId),
                'booking_trace' => cron_payments_trace($bookingId),
                'error_kind' => (string)($rpc['error_kind'] ?? 'unknown'),
                'error_code' => (string)($rpc['error'] ?? 'unknown'),
                'http_status' => (int)($rpc['status'] ?? 0),
            ]);

            continue;
        }

        $data = $rpc['data'];

        if (
            ($data['enqueued'] ?? false) === true
            && ($data['status'] ?? '') === 'queued'
        ) {
            $queued++;
            continue;
        }

        $stateSkipped++;
    }

    return [
        'checked' => $checked,
        'queued' => $queued,
        'feature_skipped' => $featureSkipped,
        'state_skipped' => $stateSkipped,
        'failed' => $failed,
        'window' => 'daily_after_09_00_europe_warsaw',
    ];
}

function cron_payments_process_expired(): array
{
    $rpc = payment_lifecycle_v3_rpc(
        'booking_payment_finalize_expiry',
        [
            'p_limit' => 50,
        ]
    );

    if (($rpc['ok'] ?? false) !== true || !is_array($rpc['data'] ?? null)) {
        payu_debug('CRON_PAYMENTS_EXPIRY_RPC_ERROR', [
            'error_kind' => (string)($rpc['error_kind'] ?? 'unknown'),
            'error_code' => (string)($rpc['error'] ?? 'unknown'),
            'http_status' => (int)($rpc['status'] ?? 0),
        ]);

        return [
            'checked' => 0,
            'expired' => 0,
            'reconciliation_required' => 0,
            'skipped' => 0,
            'failed' => 1,
        ];
    }

    $data = $rpc['data'];

    return [
        'checked' => (int)($data['checked'] ?? 0),
        'expired' => (int)($data['expired'] ?? 0),
        'reconciliation_required' =>
            (int)($data['reconciliation_required'] ?? 0),
        'skipped' => (int)($data['skipped'] ?? 0),
        'failed' => 0,
    ];
}

function cron_payments_failed_count(array $reminders, array $expired): int
{
    return (int)($reminders['failed'] ?? 0)
        + (int)($expired['failed'] ?? 0);
}

function cron_payments_has_activity(array $reminders, array $expired): bool
{
    $activityCounters = [
        (int)($reminders['checked'] ?? 0),
        (int)($reminders['queued'] ?? 0),
        (int)($reminders['feature_skipped'] ?? 0),
        (int)($reminders['state_skipped'] ?? 0),
        (int)($expired['checked'] ?? 0),
        (int)($expired['expired'] ?? 0),
        (int)($expired['reconciliation_required'] ?? 0),
        (int)($expired['skipped'] ?? 0),
    ];

    return array_sum($activityCounters) > 0;
}

function cron_payments_security_summary(
    array $reminders,
    array $expired
): array {
    return [
        'reminders_checked' => (int)($reminders['checked'] ?? 0),
        'reminders_queued' => (int)($reminders['queued'] ?? 0),
        'reminders_feature_skipped' =>
            (int)($reminders['feature_skipped'] ?? 0),
        'reminders_state_skipped' =>
            (int)($reminders['state_skipped'] ?? 0),
        'reminders_failed' => (int)($reminders['failed'] ?? 0),

        'expired_checked' => (int)($expired['checked'] ?? 0),
        'expired_finalized' => (int)($expired['expired'] ?? 0),
        'expired_reconciliation_required' =>
            (int)($expired['reconciliation_required'] ?? 0),
        'expired_skipped' => (int)($expired['skipped'] ?? 0),
        'expired_failed' => (int)($expired['failed'] ?? 0),
    ];
}

try {
    if (!cron_payments_is_cli()) {
        $requestMethod = strtoupper(
            (string)($_SERVER['REQUEST_METHOD'] ?? '')
        );

        if ($requestMethod !== 'POST') {
            header('Allow: POST');

            cron_payments_security_event(
                'cron_payments_method_not_allowed',
                405,
                'denied',
                'method_not_allowed',
                'low',
                'method'
            );

            cron_payments_response([
                'success' => false,
                'error' => 'method_not_allowed',
            ], 405);
        }
    }

    cron_payments_require_authorization();

    $now = new DateTimeImmutable(
        'now',
        new DateTimeZone('UTC')
    );

    $reminders = cron_payments_process_reminders($now);
    $expired = cron_payments_process_expired();

    payu_debug('CRON_PAYMENTS_DONE', [
        'reminders' => $reminders,
        'expired' => $expired,
    ]);

    $failedCount = cron_payments_failed_count(
        $reminders,
        $expired
    );

    $securitySummary = cron_payments_security_summary(
        $reminders,
        $expired
    );

    if ($failedCount > 0) {
        cron_payments_security_event(
            'cron_payments_run_partial_failure',
            200,
            'partial_failure',
            'cron_payments_run_partial_failure',
            'medium',
            'completed_with_errors',
            $securitySummary
        );
    } elseif (cron_payments_has_activity($reminders, $expired)) {
        cron_payments_security_event(
            'cron_payments_run_activity',
            200,
            'success',
            'cron_payments_run_activity',
            'low',
            'completed_with_activity',
            $securitySummary
        );
    }

    cron_payments_response([
        'success' => $failedCount === 0,
        'now' => $now->format(DATE_ATOM),
        'reminders' => $reminders,
        'expired' => $expired,
    ]);

} catch (Throwable $e) {
    payu_debug('CRON_PAYMENTS_FATAL', [
        'error_class' => get_class($e),
        'message_trace' => cron_payments_trace($e->getMessage()),
    ]);

    cron_payments_security_event(
        'cron_payments_fatal',
        500,
        'error',
        'cron_payments_fatal',
        'high',
        'fatal'
    );

    cron_payments_response([
        'success' => false,
        'error' => 'Błąd obsługi płatności oczekujących.',
    ], 500);
}