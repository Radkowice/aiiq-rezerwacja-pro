<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/booking_postprocess_queue.php';
require_once __DIR__ . '/../helpers/booking_context_cache.php';
require_once __DIR__ . '/../helpers/booking_postprocess.php';
require_once __DIR__ . '/../helpers/security.php';


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

    foreach (['job_ref', 'attempt', 'result', 'failed_tasks', 'error_code', 'http_code', 'processed', 'recovered'] as $key) {
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

try {
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
]);

if ($failed > 0) {
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
    'success' => true,
    'processed' => $processed,
    'completed' => $completed,
    'retried' => $retried,
    'failed' => $failed,
    'recovered' => $recovered,
]);
