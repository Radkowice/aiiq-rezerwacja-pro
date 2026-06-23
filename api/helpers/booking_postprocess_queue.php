<?php
declare(strict_types=1);

function booking_postprocess_queue_root(): string
{
    return __DIR__ . '/../../data/queue/booking-postprocess';
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

function booking_postprocess_queue_ensure_directories(): bool
{
    foreach (booking_postprocess_queue_directories() as $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        @chmod($directory, 0770);
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
    if (!booking_postprocess_queue_job_is_valid($job) || !booking_postprocess_queue_ensure_directories()) {
        return false;
    }

    $target = booking_postprocess_queue_job_file($directory, (string)$job['job_id']);

    if ($target === null) {
        return false;
    }

    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($json)) {
        return false;
    }

    $temporary = @tempnam(dirname($target), '.tmp-');

    if (!is_string($temporary) || $temporary === '') {
        return false;
    }

    @chmod($temporary, 0660);
    $written = @file_put_contents($temporary, $json, LOCK_EX);

    if ($written === false || $written !== strlen($json)) {
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

function booking_postprocess_queue_enqueue(string $bookingId, string $tenantId): bool
{
    $bookingId = trim($bookingId);
    $tenantId = trim($tenantId);

    if (
        !booking_postprocess_queue_identifier_is_valid($bookingId)
        || !booking_postprocess_queue_identifier_is_valid($tenantId)
        || !booking_postprocess_queue_ensure_directories()
    ) {
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

function booking_postprocess_queue_recover_stale_processing(int $olderThanSeconds = 300): int
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
            @unlink($processingFile);
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
