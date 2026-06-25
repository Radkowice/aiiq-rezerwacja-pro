<?php
declare(strict_types=1);

function booking_postprocess_queue_root(): string
{
    $apiRoot = dirname(__DIR__);
    $applicationRoot = dirname($apiRoot);
    $frontendRoot = $applicationRoot . DIRECTORY_SEPARATOR . 'html';

    if (basename($apiRoot) === 'api' && is_dir($frontendRoot)) {
        return $frontendRoot . '/data/queue/booking-postprocess';
    }

    return $applicationRoot . '/data/queue/booking-postprocess';
}

function booking_postprocess_queue_directories(): array
{
    $root = booking_postprocess_queue_root();

    return [
        'root' => $root,
        'pending' => $root . '/pending',
        'processing' => $root . '/processing',
        'done' => $root . '/done',
        'failed' => $root . '/failed',
    ];
}

function booking_postprocess_queue_last_error_store(?array $error = null): array
{
    static $lastError = ['reason' => 'unknown'];

    if ($error !== null) {
        $lastError = $error;
    }

    return $lastError;
}

function booking_postprocess_queue_set_last_error(string $reason, array $context = []): void
{
    $allowedReasons = [
        'invalid_booking_id',
        'invalid_tenant_id',
        'ensure_directories_failed',
        'job_validation_failed',
        'json_encode_failed',
        'tempnam_failed',
        'file_put_contents_failed',
        'rename_failed',
        'chmod_failed',
        'unknown',
    ];
    $allowedContextKeys = ['booking_id', 'tenant_id', 'job_id', 'target_dir', 'target_path', 'directory'];
    $reason = in_array($reason, $allowedReasons, true) ? $reason : 'unknown';
    $error = ['reason' => $reason];

    foreach ($allowedContextKeys as $key) {
        if (!array_key_exists($key, $context) || !is_scalar($context[$key])) {
            continue;
        }

        $value = trim((string)$context[$key]);
        $value = str_replace(["\r", "\n"], '', $value);

        if ($value !== '') {
            $error[$key] = substr($value, 0, 500);
        }
    }

    booking_postprocess_queue_last_error_store($error);
}

function booking_postprocess_queue_last_error(): array
{
    return booking_postprocess_queue_last_error_store();
}

function booking_postprocess_queue_ensure_directories(): bool
{
    foreach (booking_postprocess_queue_directories() as $name => $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            booking_postprocess_queue_set_last_error('ensure_directories_failed', [
                'directory' => (string)$name,
                'target_dir' => $directory,
            ]);
            return false;
        }

        if (!@chmod($directory, 0770) && !is_writable($directory)) {
            booking_postprocess_queue_set_last_error('chmod_failed', [
                'directory' => (string)$name,
                'target_dir' => $directory,
            ]);
            return false;
        }
    }

    return true;
}

function booking_postprocess_queue_identifier_is_valid(string $value): bool
{
    return preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $value) === 1;
}

function booking_postprocess_queue_job_id(string $bookingId): string
{
    return hash('sha256', 'booking-postprocess|' . trim($bookingId));
}

function booking_postprocess_queue_job_file(string $directory, string $jobId): ?string
{
    if (preg_match('/^[a-f0-9]{64}$/', $jobId) !== 1) {
        return null;
    }

    $directories = booking_postprocess_queue_directories();

    if (!isset($directories[$directory]) || $directory === 'root') {
        return null;
    }

    return $directories[$directory] . '/' . $jobId . '.json';
}

function booking_postprocess_queue_job_is_valid(array $job): bool
{
    $expectedJobKeys = [
        'version', 'job_id', 'booking_id', 'tenant_id', 'created_at', 'attempt',
        'next_attempt_at', 'tasks',
    ];
    $expectedTaskKeys = ['google_calendar', 'client_email', 'admin_email'];
    $allowedTaskStates = ['pending', 'retry', 'done', 'skipped', 'failed'];
    $tasks = $job['tasks'] ?? null;

    $jobKeys = array_keys($job);
    sort($jobKeys, SORT_STRING);
    sort($expectedJobKeys, SORT_STRING);

    if (
        $jobKeys !== $expectedJobKeys
        || ($job['version'] ?? null) !== 1
        || !booking_postprocess_queue_identifier_is_valid((string)($job['booking_id'] ?? ''))
        || !booking_postprocess_queue_identifier_is_valid((string)($job['tenant_id'] ?? ''))
        || preg_match('/^[a-f0-9]{64}$/', (string)($job['job_id'] ?? '')) !== 1
        || !hash_equals(
            booking_postprocess_queue_job_id((string)($job['booking_id'] ?? '')),
            (string)($job['job_id'] ?? '')
        )
        || !is_int($job['attempt'] ?? null)
        || (int)$job['attempt'] < 0
        || !is_string($job['created_at'] ?? null)
        || !is_string($job['next_attempt_at'] ?? null)
        || !is_array($tasks)
    ) {
        return false;
    }

    $taskKeys = array_keys($tasks);
    sort($taskKeys, SORT_STRING);
    sort($expectedTaskKeys, SORT_STRING);

    if ($taskKeys !== $expectedTaskKeys) {
        return false;
    }

    foreach ($expectedTaskKeys as $task) {
        if (!in_array($tasks[$task] ?? null, $allowedTaskStates, true)) {
            return false;
        }
    }

    return true;
}

function booking_postprocess_queue_write_job(string $directory, array $job): bool
{
    if (!booking_postprocess_queue_job_is_valid($job)) {
        booking_postprocess_queue_set_last_error('job_validation_failed', [
            'booking_id' => $job['booking_id'] ?? '',
            'tenant_id' => $job['tenant_id'] ?? '',
            'job_id' => $job['job_id'] ?? '',
            'directory' => $directory,
        ]);
        return false;
    }

    if (!booking_postprocess_queue_ensure_directories()) {
        return false;
    }

    $target = booking_postprocess_queue_job_file($directory, (string)$job['job_id']);

    if ($target === null) {
        booking_postprocess_queue_set_last_error('unknown', [
            'booking_id' => $job['booking_id'] ?? '',
            'tenant_id' => $job['tenant_id'] ?? '',
            'job_id' => $job['job_id'] ?? '',
            'directory' => $directory,
        ]);
        return false;
    }

    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($json)) {
        booking_postprocess_queue_set_last_error('json_encode_failed', [
            'booking_id' => $job['booking_id'] ?? '',
            'tenant_id' => $job['tenant_id'] ?? '',
            'job_id' => $job['job_id'] ?? '',
            'target_path' => $target,
            'target_dir' => dirname($target),
        ]);
        return false;
    }

    $temporary = @tempnam(dirname($target), '.tmp-');

    if (!is_string($temporary) || $temporary === '') {
        booking_postprocess_queue_set_last_error('tempnam_failed', [
            'booking_id' => $job['booking_id'] ?? '',
            'tenant_id' => $job['tenant_id'] ?? '',
            'job_id' => $job['job_id'] ?? '',
            'target_path' => $target,
            'target_dir' => dirname($target),
        ]);
        return false;
    }

    @chmod($temporary, 0660);
    $written = @file_put_contents($temporary, $json, LOCK_EX);

    if ($written === false || $written !== strlen($json)) {
        booking_postprocess_queue_set_last_error('file_put_contents_failed', [
            'booking_id' => $job['booking_id'] ?? '',
            'tenant_id' => $job['tenant_id'] ?? '',
            'job_id' => $job['job_id'] ?? '',
            'target_path' => $target,
            'target_dir' => dirname($target),
        ]);
        @unlink($temporary);
        return false;
    }

    if (!@rename($temporary, $target)) {
        booking_postprocess_queue_set_last_error('rename_failed', [
            'booking_id' => $job['booking_id'] ?? '',
            'tenant_id' => $job['tenant_id'] ?? '',
            'job_id' => $job['job_id'] ?? '',
            'target_path' => $target,
            'target_dir' => dirname($target),
        ]);
        @unlink($temporary);
        return false;
    }

    @chmod($target, 0660);
    return true;
}

function booking_postprocess_queue_enqueue(string $bookingId, string $tenantId): bool
{
    $bookingId = trim($bookingId);
    $tenantId = trim($tenantId);
    booking_postprocess_queue_set_last_error('unknown', [
        'booking_id' => $bookingId,
        'tenant_id' => $tenantId,
    ]);

    if (!booking_postprocess_queue_identifier_is_valid($bookingId)) {
        booking_postprocess_queue_set_last_error('invalid_booking_id', [
            'booking_id' => $bookingId,
            'tenant_id' => $tenantId,
        ]);
        return false;
    }

    if (!booking_postprocess_queue_identifier_is_valid($tenantId)) {
        booking_postprocess_queue_set_last_error('invalid_tenant_id', [
            'booking_id' => $bookingId,
            'tenant_id' => $tenantId,
        ]);
        return false;
    }

    if (!booking_postprocess_queue_ensure_directories()) {
        return false;
    }

    $jobId = booking_postprocess_queue_job_id($bookingId);

    foreach (['pending', 'processing', 'done'] as $directory) {
        $existing = booking_postprocess_queue_job_file($directory, $jobId);

        if ($existing !== null && is_file($existing)) {
            return true;
        }
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))
        ->format(DateTimeInterface::ATOM);

    return booking_postprocess_queue_write_job('pending', [
        'version' => 1,
        'job_id' => $jobId,
        'booking_id' => $bookingId,
        'tenant_id' => $tenantId,
        'created_at' => $now,
        'attempt' => 0,
        'next_attempt_at' => $now,
        'tasks' => [
            'google_calendar' => 'pending',
            'client_email' => 'pending',
            'admin_email' => 'pending',
        ],
    ]);
}

function booking_postprocess_queue_read_file(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    $job = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

    return is_array($job) && booking_postprocess_queue_job_is_valid($job) ? $job : null;
}

function booking_postprocess_queue_job_is_due(array $job, ?DateTimeImmutable $now = null): bool
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

    try {
        return new DateTimeImmutable((string)$job['next_attempt_at']) <= $now;
    } catch (Throwable $e) {
        return true;
    }
}

function booking_postprocess_queue_acquire_worker_lock()
{
    if (!booking_postprocess_queue_ensure_directories()) {
        return null;
    }

    $lockFile = booking_postprocess_queue_root() . '/worker.lock';
    $handle = @fopen($lockFile, 'c');

    if (!is_resource($handle)) {
        return null;
    }

    @chmod($lockFile, 0660);

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return null;
    }

    return $handle;
}

function booking_postprocess_queue_release_worker_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function booking_postprocess_queue_claim_due_job(): ?array
{
    if (!booking_postprocess_queue_ensure_directories()) {
        return null;
    }

    $files = @glob(booking_postprocess_queue_directories()['pending'] . '/*.json');

    if (!is_array($files)) {
        return null;
    }

    sort($files, SORT_STRING);
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

    foreach ($files as $pendingFile) {
        $job = booking_postprocess_queue_read_file($pendingFile);

        if (!is_array($job) || !booking_postprocess_queue_job_is_due($job, $now)) {
            continue;
        }

        $processingFile = booking_postprocess_queue_job_file('processing', (string)$job['job_id']);

        if ($processingFile !== null && @rename($pendingFile, $processingFile)) {
            return [
                'job' => $job,
                'file' => $processingFile,
            ];
        }
    }

    return null;
}

function booking_postprocess_queue_recover_stale_processing(int $olderThanSeconds = 300, bool $dryRun = false): int
{
    if (!booking_postprocess_queue_ensure_directories()) {
        return 0;
    }

    $files = @glob(booking_postprocess_queue_directories()['processing'] . '/*.json');

    if (!is_array($files)) {
        return 0;
    }

    $recovered = 0;
    $threshold = time() - max(60, $olderThanSeconds);

    foreach ($files as $processingFile) {
        $modifiedAt = @filemtime($processingFile);
        $job = is_int($modifiedAt) && $modifiedAt < $threshold
            ? booking_postprocess_queue_read_file($processingFile)
            : null;

        if (!is_array($job)) {
            continue;
        }

        $pendingFile = booking_postprocess_queue_job_file('pending', (string)$job['job_id']);
        $doneFile = booking_postprocess_queue_job_file('done', (string)$job['job_id']);
        $failedFile = booking_postprocess_queue_job_file('failed', (string)$job['job_id']);

        if (
            ($pendingFile !== null && is_file($pendingFile))
            || ($doneFile !== null && is_file($doneFile))
            || ($failedFile !== null && is_file($failedFile))
        ) {
            if (!$dryRun) {
                @unlink($processingFile);
            }
            continue;
        }

        if ($dryRun) {
            $recovered++;
            continue;
        }

        if ($pendingFile !== null && @rename($processingFile, $pendingFile)) {
            $recovered++;
        }
    }

    return $recovered;
}

function booking_postprocess_queue_backoff_seconds(int $attempt): int
{
    $delays = [30, 120, 600, 1800];
    return $delays[max(0, min(count($delays) - 1, $attempt - 1))];
}

function booking_postprocess_queue_retry_job(array $job, string $processingFile): bool
{
    $job['attempt'] = max(0, (int)$job['attempt']) + 1;

    foreach ($job['tasks'] as $task => $status) {
        if ($status === 'failed') {
            $job['tasks'][$task] = 'retry';
        }
    }

    if ($job['attempt'] >= 5) {
        $job['tasks'] = array_map(
            static fn(string $status): string => in_array($status, ['done', 'skipped'], true) ? $status : 'failed',
            $job['tasks']
        );
        $written = booking_postprocess_queue_write_job('failed', $job);

        if ($written) {
            @unlink($processingFile);
        }

        return false;
    }

    $job['next_attempt_at'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))
        ->modify('+' . booking_postprocess_queue_backoff_seconds($job['attempt']) . ' seconds')
        ->format(DateTimeInterface::ATOM);
    $written = booking_postprocess_queue_write_job('pending', $job);

    if ($written) {
        @unlink($processingFile);
    }

    return $written;
}

function booking_postprocess_queue_complete_job(array $job, string $processingFile): bool
{
    $written = booking_postprocess_queue_write_job('done', $job);

    if ($written) {
        @unlink($processingFile);
    }

    return $written;
}

function booking_postprocess_queue_cleanup_result(): array
{
    return [
        'checked' => 0,
        'deleted' => 0,
        'moved_to_failed' => 0,
        'recovered' => 0,
        'skipped' => 0,
        'errors' => [],
    ];
}

function booking_postprocess_queue_path_is_inside(string $path, string $basePath): bool
{
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

    return $path !== ''
        && $basePath !== ''
        && ($path === $basePath || str_starts_with($path, $basePath . DIRECTORY_SEPARATOR));
}

function booking_postprocess_queue_file_is_safe(string $file, string $baseDirectory, string $namePattern): bool
{
    $basePath = realpath($baseDirectory);
    $realPath = realpath($file);

    if ($basePath === false || $realPath === false || !is_file($realPath)) {
        return false;
    }

    if (!booking_postprocess_queue_path_is_inside($realPath, $basePath)) {
        return false;
    }

    return preg_match($namePattern, basename($realPath)) === 1;
}

function booking_postprocess_queue_file_is_older_than(string $file, int $olderThanSeconds): bool
{
    $modifiedAt = @filemtime($file);

    return is_int($modifiedAt) && $modifiedAt < (time() - max(1, $olderThanSeconds));
}

function booking_postprocess_queue_cleanup_json_directory(
    string $directory,
    int $olderThanSeconds,
    bool $dryRun = false
): array {
    $result = booking_postprocess_queue_cleanup_result();

    if (!booking_postprocess_queue_ensure_directories()) {
        $result['errors'][] = ['category' => 'queue_unavailable', 'directory' => $directory];
        return $result;
    }

    $directories = booking_postprocess_queue_directories();
    $baseDirectory = $directories[$directory] ?? '';

    if ($baseDirectory === '' || $directory === 'root') {
        $result['errors'][] = ['category' => 'invalid_directory', 'directory' => $directory];
        return $result;
    }

    $files = @glob($baseDirectory . '/*.json');

    if (!is_array($files)) {
        $result['errors'][] = ['category' => 'glob_failed', 'directory' => $directory];
        return $result;
    }

    foreach ($files as $file) {
        $result['checked']++;

        if (!booking_postprocess_queue_file_is_safe($file, $baseDirectory, '/^[a-f0-9]{64}\.json$/')) {
            $result['skipped']++;
            continue;
        }

        if (!booking_postprocess_queue_file_is_older_than($file, $olderThanSeconds)) {
            $result['skipped']++;
            continue;
        }

        if ($dryRun) {
            $result['deleted']++;
            continue;
        }

        if (@unlink($file)) {
            $result['deleted']++;
        } else {
            $result['errors'][] = [
                'category' => 'delete_failed',
                'directory' => $directory,
                'path' => $file,
            ];
        }
    }

    return $result;
}

function booking_postprocess_queue_cleanup_done(int $olderThanSeconds, bool $dryRun = false): array
{
    return booking_postprocess_queue_cleanup_json_directory('done', $olderThanSeconds, $dryRun);
}

function booking_postprocess_queue_cleanup_failed(int $olderThanSeconds, bool $dryRun = false): array
{
    return booking_postprocess_queue_cleanup_json_directory('failed', $olderThanSeconds, $dryRun);
}

function booking_postprocess_queue_fail_stale_pending(int $olderThanSeconds, bool $dryRun = false): array
{
    $result = booking_postprocess_queue_cleanup_result();

    if (!booking_postprocess_queue_ensure_directories()) {
        $result['errors'][] = ['category' => 'queue_unavailable', 'directory' => 'pending'];
        return $result;
    }

    $directories = booking_postprocess_queue_directories();
    $pendingDirectory = $directories['pending'];
    $files = @glob($pendingDirectory . '/*.json');

    if (!is_array($files)) {
        $result['errors'][] = ['category' => 'glob_failed', 'directory' => 'pending'];
        return $result;
    }

    foreach ($files as $file) {
        $result['checked']++;

        if (!booking_postprocess_queue_file_is_safe($file, $pendingDirectory, '/^[a-f0-9]{64}\.json$/')) {
            $result['skipped']++;
            continue;
        }

        if (!booking_postprocess_queue_file_is_older_than($file, $olderThanSeconds)) {
            $result['skipped']++;
            continue;
        }

        $job = booking_postprocess_queue_read_file($file);

        if (!is_array($job)) {
            $result['skipped']++;
            continue;
        }

        $failedFile = booking_postprocess_queue_job_file('failed', (string)$job['job_id']);

        if ($failedFile === null || is_file($failedFile)) {
            $result['skipped']++;
            continue;
        }

        if ($dryRun) {
            $result['moved_to_failed']++;
            continue;
        }

        $job['attempt'] = max(5, (int)$job['attempt']);

        foreach ($job['tasks'] as $task => $status) {
            if (!in_array($status, ['done', 'skipped'], true)) {
                $job['tasks'][$task] = 'failed';
            }
        }

        $written = booking_postprocess_queue_write_job('failed', $job);

        if ($written && @unlink($file)) {
            $result['moved_to_failed']++;
        } else {
            $result['errors'][] = [
                'category' => 'move_to_failed_failed',
                'directory' => 'pending',
                'path' => $file,
            ];
        }
    }

    return $result;
}

function booking_postprocess_queue_cleanup_tmp(int $olderThanSeconds, bool $dryRun = false): array
{
    $result = booking_postprocess_queue_cleanup_result();

    if (!booking_postprocess_queue_ensure_directories()) {
        $result['errors'][] = ['category' => 'queue_unavailable', 'directory' => 'queue'];
        return $result;
    }

    foreach (booking_postprocess_queue_directories() as $directory => $baseDirectory) {
        $files = @glob($baseDirectory . '/.tmp-*');

        if (!is_array($files)) {
            $result['errors'][] = ['category' => 'glob_failed', 'directory' => $directory];
            continue;
        }

        foreach ($files as $file) {
            $result['checked']++;

            if (!booking_postprocess_queue_file_is_safe($file, $baseDirectory, '/^\.tmp-[a-zA-Z0-9._-]+$/')) {
                $result['skipped']++;
                continue;
            }

            if (!booking_postprocess_queue_file_is_older_than($file, $olderThanSeconds)) {
                $result['skipped']++;
                continue;
            }

            if ($dryRun) {
                $result['deleted']++;
                continue;
            }

            if (@unlink($file)) {
                $result['deleted']++;
            } else {
                $result['errors'][] = [
                    'category' => 'delete_failed',
                    'directory' => $directory,
                    'path' => $file,
                ];
            }
        }
    }

    return $result;
}
