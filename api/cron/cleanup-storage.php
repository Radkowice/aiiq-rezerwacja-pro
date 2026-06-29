<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/supabase.php';

const CLEANUP_ASSET_RETENTION_DAYS = 30;
const CLEANUP_LOG_RETENTION_DAYS = 30;
const CLEANUP_LOG_ROTATE_MAX_BYTES = 5242880;
const CLEANUP_LOG_ROTATE_KEEP_BYTES = 1048576;

function cleanup_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanup_base_result(bool $dryRun): array
{
    return [
        'success' => false,
        'dry_run' => $dryRun,
        'checked_files' => 0,
        'checked_directories' => 0,
        'protected_files' => 0,
        'deleted_files' => 0,
        'deleted_directories' => 0,
        'rotated_files' => 0,
        'skipped_files' => 0,
        'skipped_directories' => 0,
        'errors' => [],
        'candidates' => [],
        'deleted' => [],
        'rotated' => [],
        'skipped' => [],
    ];
}

function cleanup_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string) $value);

    return $value !== '' ? $value : $default;
}

function cleanup_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function cleanup_get_token_from_request(): string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if ($authorization === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authorization = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    $headerSecret = trim((string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? ''));

    if ($headerSecret !== '') {
        return $headerSecret;
    }

    $secret = trim((string) ($_GET['secret'] ?? ''));

    if ($secret !== '') {
        return $secret;
    }

    return trim((string) ($_GET['token'] ?? ''));
}

function cleanup_expected_token(): string
{
    $specific = cleanup_env('CLEANUP_STORAGE_CRON_SECRET');

    if ($specific !== '') {
        return $specific;
    }

    $legacy = cleanup_env('CLEANUP_CRON_TOKEN');

    if ($legacy !== '') {
        return $legacy;
    }

    return cleanup_env('CRON_SECRET');
}

function cleanup_authorize(): void
{
    if (cleanup_is_cli()) {
        return;
    }

    $expectedToken = cleanup_expected_token();

    if ($expectedToken === '') {
        cleanup_response([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }

    $providedToken = cleanup_get_token_from_request();

    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        cleanup_response([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }
}

function cleanup_count_by_key(array $items, string $key): array
{
    $summary = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $value = trim((string) ($item[$key] ?? ''));

        if ($value === '') {
            $value = 'unknown';
        }

        $summary[$value] = ($summary[$value] ?? 0) + 1;
    }

    ksort($summary);

    return $summary;
}

function cleanup_public_result(array $result): array
{
    $public = $result;

    $candidates = is_array($result['candidates'] ?? null) ? $result['candidates'] : [];
    $deleted = is_array($result['deleted'] ?? null) ? $result['deleted'] : [];
    $rotated = is_array($result['rotated'] ?? null) ? $result['rotated'] : [];
    $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];
    $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];

    unset(
        $public['candidates'],
        $public['deleted'],
        $public['rotated'],
        $public['skipped'],
        $public['errors']
    );

    $public['candidates_count'] = count($candidates);
    $public['deleted_count'] = count($deleted);
    $public['rotated_count'] = count($rotated);
    $public['skipped_count'] = count($skipped);
    $public['errors_count'] = count($errors);

    $public['candidates_summary'] = cleanup_count_by_key($candidates, 'type');
    $public['deleted_summary'] = cleanup_count_by_key($deleted, 'type');
    $public['rotated_summary'] = cleanup_count_by_key($rotated, 'type');
    $public['skipped_summary'] = cleanup_count_by_key($skipped, 'reason');
    $public['errors_summary'] = cleanup_count_by_key($errors, 'error');

    return $public;
}

function cleanup_public_response(array $result, int $statusCode = 200): void
{
    cleanup_response(cleanup_public_result($result), $statusCode);
}

function cleanup_is_dry_run(): bool
{
    $value = strtolower(trim((string) ($_GET['dry_run'] ?? '')));

    return !in_array($value, ['0', 'false', 'no'], true);
}

function cleanup_log_run(bool $dryRun, int $candidates, int $deleted, int $errors): void
{
    $logFile = '/var/www/data/cleanup-storage.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $line = date('Y-m-d H:i:s')
        . ' dry_run=' . ($dryRun ? 'true' : 'false')
        . ' candidates=' . $candidates
        . ' deleted=' . $deleted
        . ' errors=' . $errors
        . PHP_EOL;

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function cleanup_supabase_request(string $url, string $key, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => supabaseHeaders($key, $schema),
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $response !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300 && is_array($decoded),
        'http_code' => $httpCode,
        'error' => $curlError,
        'data' => is_array($decoded) ? $decoded : null,
    ];
}

function cleanup_public_path_from_url(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $path = parse_url($value, PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return '';
    }

    return '/' . ltrim($path, '/');
}

function cleanup_fetch_protected_asset_paths(string $supabaseUrl, string $supabaseKey, string $schema): array
{
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_branding?select=logo_url_front,favicon_url_front';

    $result = cleanup_supabase_request($url, $supabaseKey, $schema);

    if (!$result['ok']) {
        throw new RuntimeException('Nie udało się pobrać aktualnych ścieżek brandingu z Supabase.');
    }

    $protected = [];

    foreach ($result['data'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach (['logo_url_front', 'favicon_url_front'] as $field) {
            $path = cleanup_public_path_from_url((string) ($row[$field] ?? ''));

            if ($path !== '') {
                $protected[$path] = true;
            }
        }
    }

    return $protected;
}

function cleanup_is_path_inside(string $path, string $basePath): bool
{
    return $basePath !== ''
        && $path !== ''
        && str_starts_with($path, $basePath . DIRECTORY_SEPARATOR);
}

function cleanup_directory_is_empty(string $path): bool
{
    try {
        $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        return !$iterator->valid();
    } catch (Throwable $e) {
        return false;
    }
}

function cleanup_scan_asset_directory(
    string $baseDir,
    string $publicPrefix,
    array $protectedPaths,
    int $olderThan,
    bool $dryRun,
    array &$result
): void {
    $basePath = realpath($baseDir);

    if ($basePath === false || !is_dir($basePath)) {
        $result['skipped'][] = [
            'path' => $baseDir,
            'reason' => 'directory_not_found',
        ];
        $result['skipped_files']++;
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $realPath = realpath($path);

        if ($item->isDir()) {
            continue;
        }

        $result['checked_files']++;

        if ($realPath === false || !cleanup_is_path_inside($realPath, $basePath)) {
            $result['skipped'][] = [
                'path' => $path,
                'reason' => 'outside_base_path',
            ];
            $result['skipped_files']++;
            continue;
        }

        if (!$item->isFile()) {
            $result['skipped'][] = [
                'path' => $realPath,
                'reason' => 'not_a_file',
            ];
            $result['skipped_files']++;
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($basePath) + 1));
        $publicPath = rtrim($publicPrefix, '/') . '/' . ltrim($relative, '/');

        if (isset($protectedPaths[$publicPath])) {
            $result['protected_files']++;
            $result['skipped'][] = [
                'path' => $publicPath,
                'reason' => 'protected_current_branding',
            ];
            $result['skipped_files']++;
            continue;
        }

        $modifiedAt = @filemtime($realPath);

        if ($modifiedAt === false || $modifiedAt >= $olderThan) {
            $result['skipped'][] = [
                'path' => $publicPath,
                'reason' => 'too_new',
            ];
            $result['skipped_files']++;
            continue;
        }

        $candidate = [
            'type' => 'asset_file',
            'path' => $publicPath,
            'real_path' => $realPath,
            'modified_at' => date(DATE_ATOM, $modifiedAt),
        ];

        $result['candidates'][] = $candidate;

        if ($dryRun) {
            continue;
        }

        if (@unlink($realPath)) {
            $result['deleted'][] = $candidate;
            $result['deleted_files']++;
        } else {
            $result['errors'][] = [
                'path' => $publicPath,
                'error' => 'delete_failed',
            ];
        }
    }
}

function cleanup_scan_empty_tenant_directories(string $baseDir, bool $dryRun, array &$result): void
{
    $basePath = realpath($baseDir);

    if ($basePath === false || !is_dir($basePath)) {
        $result['skipped'][] = [
            'type' => 'directory',
            'path' => $baseDir,
            'reason' => 'directory_not_found',
        ];
        $result['skipped_directories']++;
        return;
    }

    foreach (glob($basePath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $tenantDir) {
        if (!is_dir($tenantDir)) {
            continue;
        }

        $result['checked_directories']++;
        $tenantRealPath = realpath($tenantDir);

        if ($tenantRealPath === false || !cleanup_is_path_inside($tenantRealPath, $basePath)) {
            $result['skipped'][] = [
                'type' => 'directory',
                'path' => $tenantDir,
                'reason' => 'outside_base_path',
            ];
            $result['skipped_directories']++;
            continue;
        }

        if ($tenantRealPath === $basePath) {
            $result['skipped'][] = [
                'type' => 'directory',
                'path' => $tenantRealPath,
                'reason' => 'base_directory_protected',
            ];
            $result['skipped_directories']++;
            continue;
        }

        if (!cleanup_directory_is_empty($tenantRealPath)) {
            $result['skipped'][] = [
                'type' => 'directory',
                'path' => $tenantRealPath,
                'reason' => 'directory_not_empty',
            ];
            $result['skipped_directories']++;
            continue;
        }

        $candidate = [
            'type' => 'empty_directory',
            'path' => $tenantRealPath,
        ];

        $result['candidates'][] = $candidate;

        if ($dryRun) {
            continue;
        }

        if (@rmdir($tenantRealPath)) {
            $result['deleted'][] = $candidate;
            $result['deleted_directories']++;
        } else {
            $result['errors'][] = [
                'path' => $tenantRealPath,
                'error' => 'rmdir_failed',
            ];
        }
    }
}

function cleanup_scan_log_file(string $path, int $olderThan, bool $dryRun, array &$result): void
{
    if (!is_file($path)) {
        $result['skipped'][] = [
            'path' => $path,
            'reason' => 'file_not_found',
        ];
        $result['skipped_files']++;
        return;
    }

    $realPath = realpath($path);

    if ($realPath === false) {
        $result['skipped'][] = [
            'path' => $path,
            'reason' => 'realpath_failed',
        ];
        $result['skipped_files']++;
        return;
    }

    $result['checked_files']++;
    $modifiedAt = @filemtime($realPath);
    $sizeBytes = @filesize($realPath);

    if ($modifiedAt !== false && $modifiedAt < $olderThan) {
        $candidate = [
            'type' => 'technical_log',
            'path' => $realPath,
            'modified_at' => date(DATE_ATOM, $modifiedAt),
            'size_bytes' => is_int($sizeBytes) ? $sizeBytes : null,
        ];

        $result['candidates'][] = $candidate;

        if ($dryRun) {
            return;
        }

        if (@unlink($realPath)) {
            $result['deleted'][] = $candidate;
            $result['deleted_files']++;
            return;
        }

        $result['errors'][] = [
            'path' => $realPath,
            'error' => 'delete_failed',
        ];

        return;
    }

    if (!is_int($sizeBytes) || $sizeBytes <= CLEANUP_LOG_ROTATE_MAX_BYTES) {
        $result['skipped'][] = [
            'path' => $realPath,
            'reason' => $modifiedAt === false ? 'mtime_failed' : 'too_new_or_size_ok',
            'size_bytes' => is_int($sizeBytes) ? $sizeBytes : null,
        ];
        $result['skipped_files']++;
        return;
    }

    $candidate = [
        'type' => 'technical_log_rotate',
        'path' => $realPath,
        'modified_at' => $modifiedAt !== false ? date(DATE_ATOM, $modifiedAt) : null,
        'size_bytes' => $sizeBytes,
        'keep_bytes' => CLEANUP_LOG_ROTATE_KEEP_BYTES,
    ];

    $result['candidates'][] = $candidate;

    if ($dryRun) {
        return;
    }

    $handle = @fopen($realPath, 'rb');

    if ($handle === false) {
        $result['errors'][] = [
            'path' => $realPath,
            'error' => 'read_failed',
        ];
        return;
    }

    if (@fseek($handle, -CLEANUP_LOG_ROTATE_KEEP_BYTES, SEEK_END) !== 0) {
        @fclose($handle);
        $result['errors'][] = [
            'path' => $realPath,
            'error' => 'seek_failed',
        ];
        return;
    }

    $tail = @stream_get_contents($handle);
    @fclose($handle);

    if (!is_string($tail)) {
        $result['errors'][] = [
            'path' => $realPath,
            'error' => 'read_failed',
        ];
        return;
    }

    $header = '[log rotated by cleanup-storage at ' . date(DATE_ATOM) . ']' . PHP_EOL;

    if (@file_put_contents($realPath, $header . $tail, LOCK_EX) === false) {
        $result['errors'][] = [
            'path' => $realPath,
            'error' => 'rotate_failed',
        ];
        return;
    }

    $result['rotated'][] = $candidate;
    $result['rotated_files']++;
}

$dryRun = cleanup_is_dry_run();
$result = cleanup_base_result($dryRun);

cleanup_authorize();

$supabaseUrl = rtrim(cleanup_env('SUPABASE_URL'), '/');
$supabaseKey = cleanup_env('SUPABASE_SERVICE_ROLE_KEY');
$schema = cleanup_env('SUPABASE_DB_SCHEMA', 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    $result['errors'][] = [
        'error' => 'configuration_error',
    ];
    cleanup_log_run($dryRun, 0, 0, count($result['errors']));
    cleanup_public_response($result, 500);
}

try {
    $protectedPaths = cleanup_fetch_protected_asset_paths($supabaseUrl, $supabaseKey, $schema);
} catch (Throwable $e) {
    $result['errors'][] = [
        'error' => 'protected_assets_fetch_failed',
    ];
    cleanup_log_run($dryRun, 0, 0, count($result['errors']));
    cleanup_public_response($result, 500);
}

$assetOlderThan = time() - (CLEANUP_ASSET_RETENTION_DAYS * 86400);
$logOlderThan = time() - (CLEANUP_LOG_RETENTION_DAYS * 86400);

cleanup_scan_asset_directory(
    '/var/www/html/data/logo',
    '/data/logo',
    $protectedPaths,
    $assetOlderThan,
    $dryRun,
    $result
);

cleanup_scan_asset_directory(
    '/var/www/html/data/favicon',
    '/data/favicon',
    $protectedPaths,
    $assetOlderThan,
    $dryRun,
    $result
);

cleanup_scan_empty_tenant_directories('/var/www/html/data/logo', $dryRun, $result);
cleanup_scan_empty_tenant_directories('/var/www/html/data/favicon', $dryRun, $result);

$logFiles = [
    '/var/www/data/cleanup-storage.log',
    '/var/www/data/debug.log',
    '/var/www/data/debug-tenant.log',
    '/var/www/data/register-debug.log',
    '/var/www/data/google-calendar.log',
    '/var/www/logs/rezerwacje-service-debug.log',
];

foreach ($logFiles as $logFile) {
    cleanup_scan_log_file($logFile, $logOlderThan, $dryRun, $result);
}

$result['success'] = count($result['errors']) === 0;

cleanup_log_run(
    $dryRun,
    count($result['candidates']),
    (int) $result['deleted_files'],
    count($result['errors'])
);

cleanup_public_response($result, $result['success'] ? 200 : 500);
