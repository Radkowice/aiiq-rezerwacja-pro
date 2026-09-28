<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/booking_postprocess_queue.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/booking_context_cache.php';
require_once __DIR__ . '/../helpers/booking_postprocess.php';
require_once __DIR__ . '/../helpers/security.php';


const BOOKING_POSTPROCESS_INTENT_CLAIM_LIMIT = 5;
const BOOKING_POSTPROCESS_INTENT_LEASE_SECONDS = 120;

function booking_postprocess_worker_id(): string
{
    $host = function_exists('gethostname') ? trim((string)gethostname()) : '';
    $hostRef = substr(hash('sha256', $host !== '' ? $host : 'unknown-host'), 0, 16);
    $pid = function_exists('getmypid') ? max(0, (int)getmypid()) : 0;

    return 'booking-postprocess:h-' . $hostRef . ':p-' . $pid;
}

function booking_postprocess_worker_uuid(string $value): bool
{
    return preg_match(
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
        $value
    ) === 1;
}

function booking_postprocess_worker_record_intent_result(
    string $intentId,
    string $claimToken,
    bool $success,
    ?string $errorCode
): bool {
    $rpc = payment_lifecycle_v3_rpc('booking_postprocess_intent_record_result', [
        'p_intent_id' => $intentId,
        'p_claim_token' => $claimToken,
        'p_success' => $success,
        'p_error_code' => $errorCode,
    ]);

    if (($rpc['ok'] ?? null) !== true || !is_array($rpc['data'] ?? null)) {
        return false;
    }

    $data = $rpc['data'];
    if (($data['recorded'] ?? null) !== true) {
        return false;
    }

    $status = (string)($data['status'] ?? '');
    if ($success) {
        return $status === 'materialized';
    }

    return $status === 'pending';
}

function booking_postprocess_worker_materialize_intents(): array
{
    $stats = [
        'claimed' => 0,
        'materialized' => 0,
        'deferred' => 0,
        'errors' => 0,
    ];

    $claimRpc = payment_lifecycle_v3_rpc('booking_postprocess_intent_claim', [
        'p_worker_id' => booking_postprocess_worker_id(),
        'p_limit' => BOOKING_POSTPROCESS_INTENT_CLAIM_LIMIT,
        'p_lease_seconds' => BOOKING_POSTPROCESS_INTENT_LEASE_SECONDS,
    ]);

    if (($claimRpc['ok'] ?? null) !== true || !is_array($claimRpc['data'] ?? null)) {
        $stats['errors']++;
        booking_postprocess_worker_log('INTENT_CLAIM_FAILED');
        return $stats;
    }

    $claimData = $claimRpc['data'];
    $claimed = $claimData['claimed'] ?? null;
    $items = $claimData['items'] ?? null;

    if (!is_int($claimed)
        || $claimed < 0
        || $claimed > BOOKING_POSTPROCESS_INTENT_CLAIM_LIMIT
        || !is_array($items)
        || count($items) !== $claimed
    ) {
        $stats['errors']++;
        booking_postprocess_worker_log('INTENT_CLAIM_MALFORMED');
        return $stats;
    }

    $stats['claimed'] = $claimed;
    $seenIntentIds = [];
    $seenClaimTokens = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            $stats['errors']++;
            booking_postprocess_worker_log('INTENT_ITEM_MALFORMED');
            continue;
        }

        $keys = array_keys($item);
        sort($keys, SORT_STRING);
        $expectedKeys = [
            'attempt_count',
            'booking_id',
            'claim_token',
            'intent_id',
            'lease_expires_at',
            'tenant_id',
        ];
        sort($expectedKeys, SORT_STRING);

        $intentId = trim((string)($item['intent_id'] ?? ''));
        $tenantId = trim((string)($item['tenant_id'] ?? ''));
        $bookingId = trim((string)($item['booking_id'] ?? ''));
        $claimToken = trim((string)($item['claim_token'] ?? ''));
        $attemptCount = $item['attempt_count'] ?? null;
        $leaseExpiresAt = trim((string)($item['lease_expires_at'] ?? ''));

        $shapeValid = $keys === $expectedKeys
            && booking_postprocess_worker_uuid($intentId)
            && booking_postprocess_queue_identifier_is_valid($tenantId)
            && booking_postprocess_worker_uuid($bookingId)
            && booking_postprocess_worker_uuid($claimToken)
            && is_int($attemptCount)
            && $attemptCount >= 0
            && $leaseExpiresAt !== ''
            && !isset($seenIntentIds[strtolower($intentId)])
            && !isset($seenClaimTokens[strtolower($claimToken)]);

        if (!$shapeValid) {
            $stats['errors']++;
            booking_postprocess_worker_log('INTENT_ITEM_MALFORMED');
            continue;
        }

        try {
            $lease = new DateTimeImmutable($leaseExpiresAt);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($lease <= $now) {
                $stats['errors']++;
                booking_postprocess_worker_log('INTENT_LEASE_INVALID');
                continue;
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            booking_postprocess_worker_log('INTENT_LEASE_INVALID');
            continue;
        }

        $seenIntentIds[strtolower($intentId)] = true;
        $seenClaimTokens[strtolower($claimToken)] = true;
        $intentRef = substr(hash('sha256', 'booking-postprocess-intent|' . $intentId), 0, 16);

        $queued = booking_postprocess_queue_enqueue($bookingId, $tenantId);
        if ($queued) {
            if (!booking_postprocess_worker_record_intent_result(
                $intentId,
                $claimToken,
                true,
                null
            )) {
                $stats['errors']++;
                booking_postprocess_worker_log('INTENT_RECORD_FAILED', [
                    'intent_ref' => $intentRef,
                    'attempt' => $attemptCount,
                ]);
                continue;
            }

            $stats['materialized']++;
            booking_postprocess_worker_log('INTENT_MATERIALIZED', [
                'intent_ref' => $intentRef,
                'attempt' => $attemptCount,
            ]);
            continue;
        }

        if (!booking_postprocess_worker_record_intent_result(
            $intentId,
            $claimToken,
            false,
            'queue.materialization.failed'
        )) {
            $stats['errors']++;
            booking_postprocess_worker_log('INTENT_RECORD_FAILED', [
                'intent_ref' => $intentRef,
                'attempt' => $attemptCount,
            ]);
            continue;
        }

        $stats['deferred']++;
        booking_postprocess_worker_log('INTENT_DEFERRED', [
            'intent_ref' => $intentRef,
            'attempt' => $attemptCount + 1,
        ]);
    }

    return $stats;
}


function booking_postprocess_worker_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result,
    string $severity = 'medium',
    string $stage = ''
): void {
    $details = [
        'reason' => $reason,
    ];

    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'booking_postprocess_worker',
        'endpoint' => '/api/cron/process-booking-postprocess-queue.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : ''),
        'actor_type' => 'system',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

function booking_postprocess_worker_log(string $event, array $context = []): void
{
    $allowed = [];

    foreach (['job_ref', 'intent_ref', 'attempt', 'result', 'failed_tasks', 'error_code', 'http_code', 'processed', 'recovered', 'intent_claimed', 'intent_materialized', 'intent_deferred', 'intent_errors'] as $key) {
        if (array_key_exists($key, $context) && (is_scalar($context[$key]) || is_array($context[$key]))) {
            $allowed[$key] = $context[$key];
        }
    }

    $line = date(DATE_ATOM) . ' [' . substr($event, 0, 80) . '] '
        . json_encode($allowed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . PHP_EOL;
    @file_put_contents(
        booking_postprocess_queue_root() . '/worker.log',
        $line,
        FILE_APPEND | LOCK_EX
    );
}

function booking_postprocess_worker_error_code(Throwable $error): string
{
    $message = trim($error->getMessage());

    if ($message !== '' && preg_match('/^[a-zA-Z0-9_:-]{1,120}$/', $message) === 1) {
        return $message;
    }

    return substr(get_class($error), 0, 120);
}

function booking_postprocess_worker_response(array $payload, int $statusCode = 200): void
{
    if (
        PHP_SAPI === 'cli'
        && $statusCode === 200
        && $payload === [
            'success' => true,
            'processed' => 0,
            'completed' => 0,
            'retried' => 0,
            'failed' => 0,
            'recovered' => 0,
            'intent_claimed' => 0,
            'intent_materialized' => 0,
            'intent_deferred' => 0,
            'intent_errors' => 0,
        ]
    ) {
        exit;
    }

    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

function booking_postprocess_worker_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

function booking_postprocess_worker_authorized(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $expected = trim((string)getenv('BOOKING_POSTPROCESS_CRON_SECRET'));
    $provided = booking_postprocess_worker_header('X-Cron-Secret');

    if ($provided === '') {
        $authorization = booking_postprocess_worker_header('Authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $provided = trim((string)$matches[1]);
        }
    }

    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function booking_postprocess_worker_mark_unfinished_failed(array $job): array
{
    foreach ($job['tasks'] as $task => $status) {
        if (!in_array($status, ['done', 'skipped'], true)) {
            $job['tasks'][$task] = 'failed';
        }
    }

    return $job;
}

if (!booking_postprocess_worker_authorized()) {
    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_unauthorized',
        'unauthorized',
        401,
        'denied',
        'high',
        'auth'
    );

    booking_postprocess_worker_response(['success' => false, 'error' => 'unauthorized'], 401);
}

if (!booking_postprocess_queue_ensure_directories()) {
    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_queue_unavailable',
        'queue_unavailable',
        500,
        'error',
        'high',
        'queue'
    );

    booking_postprocess_worker_response([
        'success' => false,
        'error' => 'queue_unavailable',
        'processed' => 0,
    ], 500);
}

$workerLockResult = booking_postprocess_queue_try_acquire_worker_lock();
$workerLockStatus = (string)($workerLockResult['status'] ?? 'error');
$workerLock = $workerLockResult['handle'] ?? null;

if ($workerLockStatus === 'busy') {
    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_already_running',
        'worker_already_running',
        200,
        'skipped',
        'low',
        'lock'
    );

    booking_postprocess_worker_response([
        'success' => true,
        'message' => 'worker_already_running',
        'processed' => 0,
    ]);
}

if ($workerLockStatus !== 'acquired' || !is_resource($workerLock)) {
    $lockError = (string)($workerLockResult['error_category'] ?? '');

    if (!in_array($lockError, ['queue_unavailable', 'worker_lock_open_failed'], true)) {
        $lockError = 'worker_lock_open_failed';
    }

    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_lock_failed',
        $lockError,
        500,
        'error',
        'high',
        'lock'
    );

    booking_postprocess_worker_response([
        'success' => false,
        'error' => $lockError,
        'processed' => 0,
    ], 500);
}

$processed = 0;
$completed = 0;
$retried = 0;
$failed = 0;
$recovered = 0;
$intentClaimed = 0;
$intentMaterialized = 0;
$intentDeferred = 0;
$intentErrors = 0;

try {
    $intentStats = booking_postprocess_worker_materialize_intents();
    $intentClaimed = (int)($intentStats['claimed'] ?? 0);
    $intentMaterialized = (int)($intentStats['materialized'] ?? 0);
    $intentDeferred = (int)($intentStats['deferred'] ?? 0);
    $intentErrors = (int)($intentStats['errors'] ?? 0);

    $recovered = booking_postprocess_queue_recover_stale_processing(300);

    for ($index = 0; $index < 5; $index++) {
        $claimed = booking_postprocess_queue_claim_due_job();

        if (!is_array($claimed)) {
            break;
        }

        $job = $claimed['job'];
        $processingFile = (string)$claimed['file'];
        $jobRef = substr((string)$job['job_id'], 0, 16);
        $processed++;

        try {
            $result = booking_postprocess_execute_job($job);
            $job['tasks'] = is_array($result['tasks'] ?? null)
                ? $result['tasks']
                : $job['tasks'];

            if (!empty($result['success'])) {
                if (booking_postprocess_queue_complete_job($job, $processingFile)) {
                    $completed++;
                    booking_postprocess_worker_log('JOB_DONE', [
                        'job_ref' => $jobRef,
                        'attempt' => $job['attempt'],
                        'result' => 'done',
                    ]);
                } else {
                    $job = booking_postprocess_worker_mark_unfinished_failed($job);
                    booking_postprocess_queue_retry_job($job, $processingFile);
                    $retried++;
                }
            } else {
                $failedTasks = [];

                foreach ($job['tasks'] as $task => $status) {
                    if (!in_array($status, ['done', 'skipped'], true)) {
                        $failedTasks[] = $task;
                    }
                }

                $willFailPermanently = ((int)$job['attempt'] + 1) >= 5;
                booking_postprocess_queue_retry_job($job, $processingFile);

                if ($willFailPermanently) {
                    $failed++;
                } else {
                    $retried++;
                }

                booking_postprocess_worker_log(
                    $willFailPermanently ? 'JOB_FAILED' : 'JOB_RETRY',
                    [
                        'job_ref' => $jobRef,
                        'attempt' => (int)$job['attempt'] + 1,
                        'result' => $willFailPermanently ? 'failed' : 'retry',
                        'failed_tasks' => $failedTasks,
                    ]
                );
            }
        } catch (Throwable $e) {
            $job = booking_postprocess_worker_mark_unfinished_failed($job);
            $willFailPermanently = ((int)$job['attempt'] + 1) >= 5;
            booking_postprocess_queue_retry_job($job, $processingFile);

            if ($willFailPermanently) {
                $failed++;
            } else {
                $retried++;
            }

            booking_postprocess_worker_log(
                $willFailPermanently ? 'JOB_EXCEPTION_FAILED' : 'JOB_EXCEPTION_RETRY',
                [
                    'job_ref' => $jobRef,
                    'attempt' => (int)$job['attempt'] + 1,
                    'result' => $willFailPermanently ? 'failed' : 'retry',
                    'error_code' => booking_postprocess_worker_error_code($e),
                ]
            );
        }

        try {
            usleep(random_int(200000, 500000));
        } catch (Throwable $e) {
            usleep(300000);
        }
    }
} finally {
    booking_postprocess_queue_release_worker_lock($workerLock);
}

booking_postprocess_worker_log('WORKER_DONE', [
    'processed' => $processed,
    'recovered' => $recovered,
    'intent_claimed' => $intentClaimed,
    'intent_materialized' => $intentMaterialized,
    'intent_deferred' => $intentDeferred,
    'intent_errors' => $intentErrors,
]);

if ($intentErrors > 0) {
    booking_postprocess_worker_security_event(
        'booking_postprocess_intent_recovery_failed',
        'postprocess_intent_recovery_failed',
        500,
        'error',
        'high',
        'intent_recovery'
    );
} elseif ($failed > 0) {
    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_run_failed',
        'jobs_failed_permanently',
        200,
        'failed',
        'high',
        'jobs'
    );
} elseif ($retried > 0) {
    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_retry_scheduled',
        'jobs_scheduled_for_retry',
        200,
        'success',
        'medium',
        'retry'
    );
} elseif ($recovered > 0) {
    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_stale_recovered',
        'stale_processing_recovered',
        200,
        'success',
        'medium',
        'recovery'
    );
} elseif ($processed > 0) {
    booking_postprocess_worker_security_event(
        'booking_postprocess_worker_run_success',
        'worker_run_success',
        200,
        'success',
        'low',
        'run'
    );
}

booking_postprocess_worker_response([
    'success' => $intentErrors === 0,
    'processed' => $processed,
    'completed' => $completed,
    'retried' => $retried,
    'failed' => $failed,
    'recovered' => $recovered,
    'intent_claimed' => $intentClaimed,
    'intent_materialized' => $intentMaterialized,
    'intent_deferred' => $intentDeferred,
    'intent_errors' => $intentErrors,
], $intentErrors === 0 ? 200 : 500);
