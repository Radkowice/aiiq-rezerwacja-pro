<?php
declare(strict_types=1);

function booking_context_cache_dir(): string
{
    return __DIR__ . '/../../data/cache/booking-context';
}

function booking_context_cache_default_ttl(): int
{
    return 12;
}

function booking_context_cache_global_plan_ttl(): int
{
    return 180;
}

function booking_context_cache_allowed_stages(): array
{
    return [
        'tenant_lookup',
        'calendar_settings',
        'tenant_branding',
        'plan_context',
        'plan_global_limits',
        'service',
        'service_durations',
        'service_staff',
        'staff',
        'staff_availability',
        'service_settings',
        'payu_settings',
        'email_templates',
    ];
}

function booking_context_cache_stage_is_allowed(string $stage): bool
{
    return in_array($stage, booking_context_cache_allowed_stages(), true);
}

function booking_context_cache_ensure_dir(): bool
{
    $dir = booking_context_cache_dir();

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    @chmod($dir, 0770);
    booking_context_cache_maybe_cleanup();

    return true;
}

function booking_context_cache_maybe_cleanup(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        if (random_int(1, 200) !== 1) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $dir = booking_context_cache_dir();
    $files = @glob($dir . '/*');

    if (!is_array($files)) {
        return;
    }

    $oldestAllowed = time() - 300;

    foreach ($files as $file) {
        if (!is_string($file) || !is_file($file)) {
            continue;
        }

        $name = basename($file);

        if (preg_match('/^(?:[a-f0-9]{64}\.json|\.tmp-[a-zA-Z0-9._-]+)$/', $name) !== 1) {
            continue;
        }

        $modifiedAt = @filemtime($file);

        if (is_int($modifiedAt) && $modifiedAt < $oldestAllowed) {
            @unlink($file);
        }
    }
}

function booking_context_cache_key(string $stage, array $parameters): string
{
    $normalizedStage = strtolower(trim($stage));
    $encoded = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return hash('sha256', $normalizedStage . "\n" . (is_string($encoded) ? $encoded : ''));
}

function booking_context_cache_file(string $cacheKey): ?string
{
    if (preg_match('/^[a-f0-9]{64}$/', $cacheKey) !== 1) {
        return null;
    }

    return booking_context_cache_dir() . '/' . $cacheKey . '.json';
}

function booking_context_cache_metrics_store(?array $entry = null): array
{
    static $metrics = [];

    if ($entry === null) {
        return $metrics;
    }

    $stage = substr(trim((string)($entry['stage'] ?? 'unknown')), 0, 80) ?: 'unknown';

    if (!isset($metrics[$stage])) {
        $metrics[$stage] = [
            'stage' => $stage,
            'hits' => 0,
            'misses' => 0,
            'http_requests_avoided' => 0,
        ];
    }

    if (($entry['outcome'] ?? '') === 'hit') {
        $metrics[$stage]['hits']++;
        $metrics[$stage]['http_requests_avoided'] += max(0, (int)($entry['saved_requests'] ?? 0));
    } else {
        $metrics[$stage]['misses']++;
    }

    return $metrics;
}

function booking_context_cache_metrics(): array
{
    return array_values(booking_context_cache_metrics_store());
}

function booking_context_cache_metric_totals(): array
{
    $totals = [
        'hits' => 0,
        'misses' => 0,
        'http_requests_avoided' => 0,
    ];

    foreach (booking_context_cache_metrics_store() as $entry) {
        $totals['hits'] += (int)($entry['hits'] ?? 0);
        $totals['misses'] += (int)($entry['misses'] ?? 0);
        $totals['http_requests_avoided'] += (int)($entry['http_requests_avoided'] ?? 0);
    }

    return $totals;
}

function booking_context_cache_read(string $stage, string $cacheKey, bool $recordMiss = true): ?array
{
    if (!booking_context_cache_stage_is_allowed($stage)) {
        return null;
    }

    $file = booking_context_cache_file($cacheKey);

    if ($file === null || !is_file($file)) {
        if ($recordMiss) {
            booking_context_cache_metrics_store(['stage' => $stage, 'outcome' => 'miss']);
        }
        return null;
    }

    $raw = @file_get_contents($file);
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $valid = is_array($decoded)
        && ($decoded['version'] ?? null) === 1
        && ($decoded['stage'] ?? null) === $stage
        && isset($decoded['expires_at'], $decoded['payload'])
        && is_int($decoded['expires_at'])
        && $decoded['expires_at'] >= time()
        && is_array($decoded['payload']);

    if (!$valid) {
        if ($recordMiss) {
            booking_context_cache_metrics_store(['stage' => $stage, 'outcome' => 'miss']);
        }

        if (is_array($decoded) && isset($decoded['expires_at']) && (int)$decoded['expires_at'] < time()) {
            @unlink($file);
        }

        return null;
    }

    booking_context_cache_metrics_store([
        'stage' => $stage,
        'outcome' => 'hit',
        'saved_requests' => max(0, (int)($decoded['saved_requests'] ?? 1)),
    ]);

    return $decoded['payload'];
}

function booking_context_cache_acquire_lock(string $cacheKey, int $timeoutMs = 5000)
{
    if (!booking_context_cache_ensure_dir()) {
        return null;
    }

    $cacheFile = booking_context_cache_file($cacheKey);

    if ($cacheFile === null) {
        return null;
    }

    $lockFile = substr($cacheFile, 0, -5) . '.lock';
    $handle = @fopen($lockFile, 'c');

    if (!is_resource($handle)) {
        return null;
    }

    @chmod($lockFile, 0660);
    $deadline = microtime(true) + (max(1, $timeoutMs) / 1000);

    do {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    @fclose($handle);
    return null;
}

function booking_context_cache_release_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function booking_context_cache_write(
    string $stage,
    string $cacheKey,
    array $payload,
    ?int $ttlSeconds = null,
    int $savedRequests = 1
): void {
    if (!booking_context_cache_stage_is_allowed($stage) || !booking_context_cache_ensure_dir()) {
        return;
    }

    $target = booking_context_cache_file($cacheKey);

    if ($target === null) {
        return;
    }

    $cache = [
        'version' => 1,
        'stage' => $stage,
        'expires_at' => time() + max(1, $ttlSeconds ?? booking_context_cache_default_ttl()),
        'saved_requests' => max(0, $savedRequests),
        'payload' => $payload,
    ];
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($json)) {
        return;
    }

    $temporary = @tempnam(booking_context_cache_dir(), '.tmp-');

    if (!is_string($temporary) || $temporary === '') {
        return;
    }

    @chmod($temporary, 0660);
    $written = @file_put_contents($temporary, $json, LOCK_EX);

    if ($written === false || $written !== strlen($json)) {
        @unlink($temporary);
        return;
    }

    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        return;
    }

    @chmod($target, 0660);
}
