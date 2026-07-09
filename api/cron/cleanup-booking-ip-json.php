<?php
declare(strict_types=1);

const CLEANUP_BOOKING_IP_RATE_LIMIT_RETENTION_SECONDS = 3600;
const CLEANUP_BOOKING_IP_BAN_COUNTER_RETENTION_SECONDS = 86400;
const CLEANUP_BOOKING_IP_BLACKLIST_RETENTION_SECONDS = 86400;

function cleanup_booking_ip_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function cleanup_booking_ip_response(array $payload, int $statusCode = 200): void
{
    if (!cleanup_booking_ip_is_cli()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

function cleanup_booking_ip_empty_result(): array
{
    return [
        'success' => false,
        'checked_files' => 0,
        'cleaned_entries' => 0,
        'reset_files' => 0,
        'backup_files' => 0,
        'errors' => [],
    ];
}

function cleanup_booking_ip_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string) $value);

    return $value !== '' ? $value : $default;
}

function cleanup_booking_ip_int_env(string $key, int $default): int
{
    $value = cleanup_booking_ip_env($key);

    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return $default;
    }

    return max(1, (int) $value);
}

function cleanup_booking_ip_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function cleanup_booking_ip_request_token(): string
{
    $cronSecret = cleanup_booking_ip_header('X-Cron-Secret');

    if ($cronSecret !== '') {
        return $cronSecret;
    }

    $authorization = cleanup_booking_ip_header('Authorization');

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function cleanup_booking_ip_expected_token(): string
{
    $specific = cleanup_booking_ip_env('CLEANUP_BOOKING_IP_JSON_CRON_SECRET');

    if ($specific !== '') {
        return $specific;
    }

    $legacy = cleanup_booking_ip_env('CLEANUP_CRON_TOKEN');

    if ($legacy !== '') {
        return $legacy;
    }

    return cleanup_booking_ip_env('CRON_SECRET');
}

function cleanup_booking_ip_authorize(): bool
{
    if (cleanup_booking_ip_is_cli()) {
        return true;
    }

    $expected = cleanup_booking_ip_expected_token();
    $provided = cleanup_booking_ip_request_token();

    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function cleanup_booking_ip_data_dir(): string
{
    return __DIR__ . '/../data';
}

function cleanup_booking_ip_add_error(array &$result, string $file, string $error): void
{
    $result['errors'][] = [
        'file' => $file,
        'error' => $error,
    ];
}

function cleanup_booking_ip_encode(array $data, string $format): string
{
    if ($format === 'object' && count($data) === 0) {
        return '{}';
    }

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $encoded !== false ? $encoded : ($format === 'object' ? '{}' : '[]');
}

function cleanup_booking_ip_write_json($handle, array $data, string $format): bool
{
    $json = cleanup_booking_ip_encode($data, $format);

    rewind($handle);

    if (!@ftruncate($handle, 0)) {
        return false;
    }

    if (@fwrite($handle, $json) === false) {
        return false;
    }

    return @fflush($handle);
}

function cleanup_booking_ip_backup_invalid_json(string $path, string $raw): bool
{
    $timestamp = date('YmdHis');
    $backupPath = $path . '.invalid-' . $timestamp . '.bak';
    $attempt = 0;

    while (file_exists($backupPath) && $attempt < 10) {
        $attempt++;
        $backupPath = $path . '.invalid-' . $timestamp . '-' . $attempt . '.bak';
    }

    if (file_exists($backupPath)) {
        return false;
    }

    if (@file_put_contents($backupPath, $raw, LOCK_EX) === false) {
        return false;
    }

    @chmod($backupPath, 0660);

    return true;
}

function cleanup_booking_ip_acquire_cron_lock(array &$result)
{
    $dataDir = cleanup_booking_ip_data_dir();

    if (!is_dir($dataDir) && !@mkdir($dataDir, 0770, true) && !is_dir($dataDir)) {
        cleanup_booking_ip_add_error($result, 'cron', 'data_dir_unavailable');
        return false;
    }

    $lockFile = $dataDir . '/cleanup-booking-ip-json.lock';
    $handle = @fopen($lockFile, 'c');

    if (!is_resource($handle)) {
        cleanup_booking_ip_add_error($result, 'cron', 'lock_open_failed');
        return false;
    }

    @chmod($lockFile, 0660);

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return null;
    }

    return $handle;
}

function cleanup_booking_ip_release_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function cleanup_booking_ip_array_is_list(array $data): bool
{
    $expected = 0;

    foreach (array_keys($data) as $key) {
        if ($key !== $expected) {
            return false;
        }

        $expected++;
    }

    return true;
}

function cleanup_booking_ip_read_json(
    $handle,
    string $path,
    string $fileKey,
    string $format,
    array &$result,
    bool &$changed
): array {
    $changed = false;
    rewind($handle);
    $raw = (string) stream_get_contents($handle);
    $trimmed = trim($raw);

    if ($trimmed === '') {
        $changed = true;
        return [];
    }

    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        if (!cleanup_booking_ip_backup_invalid_json($path, $raw)) {
            cleanup_booking_ip_add_error($result, $fileKey, 'backup_failed');
            return [];
        }

        $result['backup_files']++;
        $result['reset_files']++;
        $changed = true;
        return [];
    }

    if ($format === 'list') {
        if (!cleanup_booking_ip_array_is_list($decoded)) {
            if (!cleanup_booking_ip_backup_invalid_json($path, $raw)) {
                cleanup_booking_ip_add_error($result, $fileKey, 'backup_failed');
                return [];
            }

            $result['backup_files']++;
            $result['reset_files']++;
            $changed = true;
            return [];
        }

        return $decoded;
    }

    if (cleanup_booking_ip_array_is_list($decoded)) {
        if (count($decoded) === 0) {
            $changed = true;
            return [];
        }

        if (!cleanup_booking_ip_backup_invalid_json($path, $raw)) {
            cleanup_booking_ip_add_error($result, $fileKey, 'backup_failed');
            return [];
        }

        $result['backup_files']++;
        $result['reset_files']++;
        $changed = true;
        return [];
    }

    return $decoded;
}

function cleanup_booking_ip_process_rate_limit(
    array $data,
    int $retentionSeconds,
    int $now,
    int &$cleanedEntries
): array {
    $cleaned = [];
    $threshold = $now - max(1, $retentionSeconds);

    foreach ($data as $key => $timestamps) {
        if (!is_string($key) || $key === '' || !is_array($timestamps)) {
            $cleanedEntries++;
            continue;
        }

        $kept = [];

        foreach ($timestamps as $timestamp) {
            if (!is_scalar($timestamp) || !is_numeric($timestamp)) {
                $cleanedEntries++;
                continue;
            }

            $timestamp = (int) $timestamp;

            if ($timestamp <= $threshold) {
                $cleanedEntries++;
                continue;
            }

            $kept[] = $timestamp;
        }

        if (count($kept) === 0) {
            $cleanedEntries++;
            continue;
        }

        $cleaned[$key] = array_values($kept);
    }

    return $cleaned;
}

function cleanup_booking_ip_count_values(array $data): int
{
    return count($data);
}

function cleanup_booking_ip_process_file(array $definition, array &$result): void
{
    $path = $definition['path'];
    $fileKey = $definition['key'];
    $format = $definition['format'];
    $retention = max(1, (int) $definition['retention']);
    $mode = $definition['mode'];
    $now = time();
    $handle = @fopen($path, 'c+');

    $result['checked_files']++;

    if (!is_resource($handle)) {
        cleanup_booking_ip_add_error($result, $fileKey, 'open_failed');
        return;
    }

    @chmod($path, 0660);

    if (!@flock($handle, LOCK_EX)) {
        @fclose($handle);
        cleanup_booking_ip_add_error($result, $fileKey, 'lock_failed');
        return;
    }

    clearstatcache(true, $path);
    $mtime = is_file($path) ? @filemtime($path) : false;
    $changed = false;
    $errorsBeforeRead = count($result['errors']);
    $resetFilesBeforeRead = (int) $result['reset_files'];
    $data = cleanup_booking_ip_read_json($handle, $path, $fileKey, $format, $result, $changed);

    if (count($result['errors']) > $errorsBeforeRead) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
        return;
    }

    if ($mode === 'rate_limit') {
        $removed = 0;
        $cleaned = cleanup_booking_ip_process_rate_limit($data, $retention, $now, $removed);

        if ($removed > 0 || $cleaned !== $data) {
            $data = $cleaned;
            $changed = true;
            $result['cleaned_entries'] += $removed;
        }
    } elseif (is_int($mtime) && $mtime < ($now - $retention)) {
        $removed = cleanup_booking_ip_count_values($data);

        if ($removed > 0 || $changed) {
            $result['cleaned_entries'] += $removed;
        }

        $data = [];
        $changed = true;

        if ((int) $result['reset_files'] === $resetFilesBeforeRead) {
            $result['reset_files']++;
        }
    }

    if ($changed && !cleanup_booking_ip_write_json($handle, $data, $format)) {
        cleanup_booking_ip_add_error($result, $fileKey, 'write_failed');
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function cleanup_booking_ip_log_run(array $result, string $status): void
{
    $logFile = cleanup_booking_ip_data_dir() . '/cleanup-booking-ip-json.log';
    $line = date(DATE_ATOM)
        . ' status=' . preg_replace('/[^a-z0-9_\-]/i', '_', $status)
        . ' success=' . (!empty($result['success']) ? 'true' : 'false')
        . ' checked_files=' . (int) ($result['checked_files'] ?? 0)
        . ' cleaned_entries=' . (int) ($result['cleaned_entries'] ?? 0)
        . ' reset_files=' . (int) ($result['reset_files'] ?? 0)
        . ' backup_files=' . (int) ($result['backup_files'] ?? 0)
        . ' errors=' . count($result['errors'] ?? [])
        . PHP_EOL;

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

if (!cleanup_booking_ip_is_cli() && !in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    $result = cleanup_booking_ip_empty_result();
    $result['errors'][] = ['error' => 'method_not_allowed'];
    cleanup_booking_ip_response($result, 405);
}

if (!cleanup_booking_ip_authorize()) {
    $result = cleanup_booking_ip_empty_result();
    $result['errors'][] = ['error' => 'unauthorized'];
    cleanup_booking_ip_response($result, 401);
}

$result = cleanup_booking_ip_empty_result();
$cronLock = cleanup_booking_ip_acquire_cron_lock($result);

if ($cronLock === null) {
    $result['success'] = true;
    cleanup_booking_ip_log_run($result, 'already_running');
    cleanup_booking_ip_response($result);
}

if ($cronLock === false) {
    $result['success'] = false;
    cleanup_booking_ip_log_run($result, 'lock_failed');
    cleanup_booking_ip_response($result, 500);
}

$statusCode = 200;

try {
    $dataDir = cleanup_booking_ip_data_dir();
    $definitions = [
        [
            'key' => 'rate_limit_book',
            'path' => $dataDir . '/rate_limit_book.json',
            'format' => 'object',
            'mode' => 'rate_limit',
            'retention' => cleanup_booking_ip_int_env(
                'CLEANUP_BOOKING_IP_RATE_LIMIT_RETENTION_SECONDS',
                CLEANUP_BOOKING_IP_RATE_LIMIT_RETENTION_SECONDS
            ),
        ],
        [
            'key' => 'ban_counter',
            'path' => $dataDir . '/ban_counter.json',
            'format' => 'object',
            'mode' => 'mtime_reset',
            'retention' => cleanup_booking_ip_int_env(
                'CLEANUP_BOOKING_IP_BAN_COUNTER_RETENTION_SECONDS',
                CLEANUP_BOOKING_IP_BAN_COUNTER_RETENTION_SECONDS
            ),
        ],
        [
            'key' => 'blacklist',
            'path' => $dataDir . '/blacklist.json',
            'format' => 'list',
            'mode' => 'mtime_reset',
            'retention' => cleanup_booking_ip_int_env(
                'CLEANUP_BOOKING_IP_BLACKLIST_RETENTION_SECONDS',
                CLEANUP_BOOKING_IP_BLACKLIST_RETENTION_SECONDS
            ),
        ],
    ];

    foreach ($definitions as $definition) {
        cleanup_booking_ip_process_file($definition, $result);
    }

    $result['success'] = count($result['errors']) === 0;
    cleanup_booking_ip_log_run($result, $result['success'] ? 'ok' : 'error');
    $statusCode = $result['success'] ? 200 : 500;
} catch (Throwable $e) {
    cleanup_booking_ip_add_error($result, 'cron', 'fatal');
    $result['success'] = false;
    cleanup_booking_ip_log_run($result, 'fatal');
    $statusCode = 500;
} finally {
    cleanup_booking_ip_release_lock($cronLock);
}

cleanup_booking_ip_response($result, $statusCode);
