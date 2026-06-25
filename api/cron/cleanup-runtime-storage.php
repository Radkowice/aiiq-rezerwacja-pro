<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/booking_postprocess_queue.php';
require_once __DIR__ . '/../helpers/booking_context_cache.php';

const CLEANUP_RUNTIME_QUEUE_DONE_RETENTION_SECONDS = 1209600; // 14 days
const CLEANUP_RUNTIME_QUEUE_FAILED_RETENTION_SECONDS = 2592000; // 30 days
const CLEANUP_RUNTIME_QUEUE_PROCESSING_RECOVERY_SECONDS = 1800; // 30 minutes
const CLEANUP_RUNTIME_QUEUE_PENDING_FAIL_SECONDS = 604800; // 7 days
const CLEANUP_RUNTIME_CACHE_RETENTION_SECONDS = 86400; // 1 day
const CLEANUP_RUNTIME_TMP_RETENTION_SECONDS = 86400; // 1 day
const CLEANUP_RUNTIME_LOCK_RETENTION_SECONDS = 86400; // 1 day

function cleanup_runtime_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function cleanup_runtime_response(array $payload, int $statusCode = 200): void
{
    if (!cleanup_runtime_is_cli()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

function cleanup_runtime_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string)$value);

    return $value !== '' ? $value : $default;
}

function cleanup_runtime_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                return trim((string)$value);
            }
        }
    }

    return '';
}

function cleanup_runtime_token_from_request(): string
{
    $cronSecret = cleanup_runtime_header('X-Cron-Secret');

    if ($cronSecret !== '') {
        return $cronSecret;
    }

    $authorization = cleanup_runtime_header('Authorization');

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string)$matches[1]);
    }

    return trim((string)($_GET['token'] ?? ''));
}

function cleanup_runtime_authorized(): bool
{
    if (cleanup_runtime_is_cli()) {
        return true;
    }

    $expected = cleanup_runtime_env('CLEANUP_RUNTIME_CRON_SECRET');

    if ($expected === '') {
        $expected = cleanup_runtime_env('CLEANUP_CRON_TOKEN');
    }

    $provided = cleanup_runtime_token_from_request();

    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function cleanup_runtime_secret_configured(): bool
{
    return cleanup_runtime_env('CLEANUP_RUNTIME_CRON_SECRET') !== ''
        || cleanup_runtime_env('CLEANUP_CRON_TOKEN') !== '';
}

function cleanup_runtime_is_dry_run(): bool
{
    global $argv;

    if (cleanup_runtime_is_cli()) {
        return is_array($argv ?? null) && in_array('--dry-run', $argv, true);
    }

    $value = strtolower(trim((string)($_GET['dry_run'] ?? '')));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function cleanup_runtime_empty_result(): array
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

function cleanup_runtime_base_result(bool $dryRun): array
{
    return [
        'success' => false,
        'dry_run' => $dryRun,
        'checked' => 0,
        'deleted' => 0,
        'moved_to_failed' => 0,
        'recovered' => 0,
        'skipped' => 0,
        'errors' => [],
        'steps' => [],
    ];
}

function cleanup_runtime_add_step(array &$summary, string $step, array $result): void
{
    $stepResult = cleanup_runtime_empty_result();

    foreach (['checked', 'deleted', 'moved_to_failed', 'recovered', 'skipped'] as $key) {
        $stepResult[$key] = max(0, (int)($result[$key] ?? 0));
        $summary[$key] += $stepResult[$key];
    }

    $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
    $stepResult['errors'] = count($errors);
    $summary['steps'][$step] = $stepResult;

    foreach ($errors as $error) {
        if (!is_array($error)) {
            continue;
        }

        $summary['errors'][] = array_merge(['step' => $step], cleanup_runtime_sanitize_error($error));
    }
}

function cleanup_runtime_sanitize_error(array $error): array
{
    $allowed = ['category', 'directory', 'path'];
    $sanitized = [];

    foreach ($allowed as $key) {
        if (!array_key_exists($key, $error) || !is_scalar($error[$key])) {
            continue;
        }

        $value = trim((string)$error[$key]);
        $value = str_replace(["\r", "\n"], '', $value);

        if ($value !== '') {
            $sanitized[$key] = substr($value, 0, 500);
        }
    }

    return $sanitized !== [] ? $sanitized : ['category' => 'unknown'];
}

function cleanup_runtime_data_dir(): string
{
    return function_exists('booking_runtime_data_root')
        ? booking_runtime_data_root()
        : dirname(dirname(booking_context_cache_dir()));
}

function cleanup_runtime_legacy_data_dir(): string
{
    return dirname(dirname(__DIR__)) . '/data';
}

function cleanup_runtime_log_run(array $summary): array
{
    $logDir = booking_postprocess_queue_root();
    $logPath = $logDir . '/cleanup-runtime-storage.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }

    if (!is_dir($logDir)) {
        return [
            'log_written' => false,
            'log_path' => $logPath,
        ];
    }

    $line = date(DATE_ATOM)
        . ' dry_run=' . (!empty($summary['dry_run']) ? 'true' : 'false')
        . ' checked=' . (int)($summary['checked'] ?? 0)
        . ' deleted=' . (int)($summary['deleted'] ?? 0)
        . ' moved_to_failed=' . (int)($summary['moved_to_failed'] ?? 0)
        . ' recovered=' . (int)($summary['recovered'] ?? 0)
        . ' skipped=' . (int)($summary['skipped'] ?? 0)
        . ' errors=' . count($summary['errors'] ?? [])
        . PHP_EOL;

    return [
        'log_written' => @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) !== false,
        'log_path' => $logPath,
    ];
}

function cleanup_runtime_path_is_inside(string $path, string $basePath): bool
{
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

    return $path !== ''
        && $basePath !== ''
        && ($path === $basePath || str_starts_with($path, $basePath . DIRECTORY_SEPARATOR));
}

function cleanup_runtime_cleanup_lock_directory(
    string $directory,
    string $namePattern,
    int $olderThanSeconds,
    bool $dryRun
): array {
    $result = cleanup_runtime_empty_result();

    if (!is_dir($directory)) {
        $result['skipped']++;
        return $result;
    }

    $basePath = realpath($directory);

    if ($basePath === false || !is_dir($basePath)) {
        $result['errors'][] = ['category' => 'base_realpath_failed', 'path' => $directory];
        return $result;
    }

    $files = @glob($basePath . DIRECTORY_SEPARATOR . '*.lock');

    if (!is_array($files)) {
        $result['errors'][] = ['category' => 'glob_failed', 'path' => $basePath];
        return $result;
    }

    $threshold = time() - max(1, $olderThanSeconds);

    foreach ($files as $file) {
        $result['checked']++;
        $realPath = realpath($file);

        if (
            $realPath === false
            || !is_file($realPath)
            || !cleanup_runtime_path_is_inside($realPath, $basePath)
            || preg_match($namePattern, basename($realPath)) !== 1
        ) {
            $result['skipped']++;
            continue;
        }

        $modifiedAt = @filemtime($realPath);

        if (!is_int($modifiedAt) || $modifiedAt >= $threshold) {
            $result['skipped']++;
            continue;
        }

        $handle = @fopen($realPath, 'c');

        if (!is_resource($handle)) {
            $result['skipped']++;
            continue;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            $result['skipped']++;
            continue;
        }

        if ($dryRun) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            $result['deleted']++;
            continue;
        }

        if (@unlink($realPath)) {
            $result['deleted']++;
        } else {
            $result['errors'][] = [
                'category' => 'delete_failed',
                'path' => $realPath,
            ];
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    return $result;
}

if (!cleanup_runtime_is_cli() && !in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    cleanup_runtime_response([
        'success' => false,
        'error' => 'method_not_allowed',
    ], 405);
}

if (!cleanup_runtime_is_cli() && !cleanup_runtime_secret_configured()) {
    cleanup_runtime_response([
        'success' => false,
        'error' => 'missing_cron_secret',
    ], 500);
}

if (!cleanup_runtime_authorized()) {
    cleanup_runtime_response([
        'success' => false,
        'error' => 'unauthorized',
    ], 401);
}

$dryRun = cleanup_runtime_is_dry_run();
$summary = cleanup_runtime_base_result($dryRun);

$recovered = booking_postprocess_queue_recover_stale_processing(
    CLEANUP_RUNTIME_QUEUE_PROCESSING_RECOVERY_SECONDS,
    $dryRun
);
cleanup_runtime_add_step($summary, 'queue_processing_recovery', [
    'recovered' => $recovered,
]);

cleanup_runtime_add_step(
    $summary,
    'queue_pending_to_failed',
    booking_postprocess_queue_fail_stale_pending(CLEANUP_RUNTIME_QUEUE_PENDING_FAIL_SECONDS, $dryRun)
);

cleanup_runtime_add_step(
    $summary,
    'queue_done_cleanup',
    booking_postprocess_queue_cleanup_done(CLEANUP_RUNTIME_QUEUE_DONE_RETENTION_SECONDS, $dryRun)
);

cleanup_runtime_add_step(
    $summary,
    'queue_failed_cleanup',
    booking_postprocess_queue_cleanup_failed(CLEANUP_RUNTIME_QUEUE_FAILED_RETENTION_SECONDS, $dryRun)
);

cleanup_runtime_add_step(
    $summary,
    'queue_tmp_cleanup',
    booking_postprocess_queue_cleanup_tmp(CLEANUP_RUNTIME_TMP_RETENTION_SECONDS, $dryRun)
);

cleanup_runtime_add_step(
    $summary,
    'booking_context_cache_cleanup',
    booking_context_cache_cleanup(CLEANUP_RUNTIME_CACHE_RETENTION_SECONDS, $dryRun)
);

$cacheRoot = cleanup_runtime_data_dir() . '/cache';

cleanup_runtime_add_step(
    $summary,
    'booking_slot_locks_cleanup',
    cleanup_runtime_cleanup_lock_directory(
        $cacheRoot . '/booking-locks',
        '/^booking-slot-[a-f0-9]{64}\.lock$/',
        CLEANUP_RUNTIME_LOCK_RETENTION_SECONDS,
        $dryRun
    )
);

cleanup_runtime_add_step(
    $summary,
    'booking_semaphore_locks_cleanup',
    cleanup_runtime_cleanup_lock_directory(
        $cacheRoot . '/booking-semaphore',
        '/^book-global-[0-9]+\.lock$/',
        CLEANUP_RUNTIME_LOCK_RETENTION_SECONDS,
        $dryRun
    )
);

$legacyDataDir = cleanup_runtime_legacy_data_dir();

if (rtrim($legacyDataDir, '/\\') !== rtrim(cleanup_runtime_data_dir(), '/\\')) {
    $legacyCacheRoot = $legacyDataDir . '/cache';

    cleanup_runtime_add_step(
        $summary,
        'legacy_booking_slot_locks_cleanup',
        cleanup_runtime_cleanup_lock_directory(
            $legacyCacheRoot . '/booking-locks',
            '/^booking-slot-[a-f0-9]{64}\.lock$/',
            CLEANUP_RUNTIME_LOCK_RETENTION_SECONDS,
            $dryRun
        )
    );

    cleanup_runtime_add_step(
        $summary,
        'legacy_booking_semaphore_locks_cleanup',
        cleanup_runtime_cleanup_lock_directory(
            $legacyCacheRoot . '/booking-semaphore',
            '/^book-global-[0-9]+\.lock$/',
            CLEANUP_RUNTIME_LOCK_RETENTION_SECONDS,
            $dryRun
        )
    );
}

$summary['success'] = count($summary['errors']) === 0;
$summary = array_merge($summary, cleanup_runtime_log_run($summary));

cleanup_runtime_response($summary, $summary['success'] ? 200 : 500);
