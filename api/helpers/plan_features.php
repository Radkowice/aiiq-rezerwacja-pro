<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';

function plan_features_all_keys(): array
{
    return [
        'staff_module',
        'multiple_services',
        'online_payments',
        'payu',
        'google_calendar',
        'legal_documents',
        'branding_logo',
        'branding_favicon',
        'branding_colors',
        'calendar_appearance',
        'staff_blocks',
        'admin_staff_notifications',
        'payment_reminders',
        'reschedule_booking',
    ];
}

function plan_features_map(): array
{
    $proFeatures = array_fill_keys(plan_features_all_keys(), true);

    return [
        'free' => array_fill_keys(plan_features_all_keys(), false),
        'pro' => $proFeatures,
        'vip' => $proFeatures,
        'business' => $proFeatures,
    ];
}

function plan_features_limits_map(): array
{
    return [
        'free' => [
            'services_count' => 1,
            'staff_count' => 0,
        ],
        'pro' => [
            'services_count' => 30,
            'staff_count' => 20,
        ],
        'vip' => [
            'services_count' => null,
            'staff_count' => null,
        ],
        'business' => [
            'services_count' => null,
            'staff_count' => null,
        ],
        'biznes' => [
            'services_count' => null,
            'staff_count' => null,
        ],
    ];
}

function plan_features_default_subscription(): array
{
    return [
        'tenant_id' => null,
        'plan_code' => 'free',
        'plan_name' => 'Free',
        'status' => 'active',
        'source' => 'fallback',
    ];
}

function plan_features_config(): array
{
    return [
        'supabase_url' => rtrim((string) getenv('SUPABASE_URL'), '/'),
        'supabase_key' => (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: ''),
        'schema' => (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro'),
    ];
}

function plan_features_retry_delay_us(int $attempt, int $httpCode): int
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

function plan_features_request(
    string $url,
    string $supabaseKey,
    string $schema,
    string $stage = 'plan_context',
    string $tenantId = '',
    ?callable $diagnosticLogger = null
): array
{
    static $requestCache = [];

    $maxAttempts = 3;
    $response = false;
    $curlError = '';
    $httpCode = 0;
    $data = null;
    $jsonValid = false;
    $temporary = false;
    $attempt = 0;
    $requestCacheKey = hash(
        'sha256',
        $url . "\n" . $schema . "\n" . hash('sha256', $supabaseKey)
    );

    if (array_key_exists($requestCacheKey, $requestCache)) {
        return $requestCache[$requestCacheKey];
    }

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $durationMs = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);

        curl_close($ch);

        if (function_exists('booking_supabase_request_record')) {
            booking_supabase_request_record('GET', $url, $stage, $httpCode);
        }

        $data = null;
        $jsonValid = false;

        if ($response !== false && $response !== '') {
            $data = json_decode((string) $response, true);
            $jsonValid = json_last_error() === JSON_ERROR_NONE;
        }

        $temporary = $response === false
            || $curlError !== ''
            || $httpCode === 0
            || $httpCode === 429
            || $httpCode >= 500
            || ($httpCode >= 200 && $httpCode < 300 && !$jsonValid);

        if ($temporary && $diagnosticLogger !== null) {
            try {
                $diagnosticLogger([
                    'stage' => $stage,
                    'method' => 'GET',
                    'endpoint' => $url,
                    'http_code' => $httpCode,
                    'curl_errno' => $curlErrno,
                    'curl_error' => $curlError,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'json_valid' => $jsonValid,
                    'duration_ms' => $durationMs,
                    'tenant_id' => $tenantId,
                ]);
            } catch (Throwable $e) {
                // Diagnostyka nie może zmieniać obliczania uprawnień planu.
            }
        }

        if (!$temporary || $attempt === $maxAttempts) {
            break;
        }

        usleep(plan_features_retry_delay_us($attempt, $httpCode));
    }

    $result = [
        'ok' => $response !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300 && $jsonValid,
        'http_code' => $httpCode,
        'error' => $curlError,
        'data' => $data,
        'raw' => $response,
        'temporary' => $temporary,
        'json_valid' => $jsonValid,
        'attempts' => $attempt,
    ];

    if (!empty($result['ok']) && is_array($data)) {
        $requestCache[$requestCacheKey] = $result;
    }

    return $result;
}

function plan_features_temporary_error_flag(?bool $value = null): bool
{
    static $flag = false;

    if ($value !== null) {
        $flag = $value;
    }

    return $flag;
}

function plan_features_log(string $message, array $context = []): void
{
    $safeContext = [];

    foreach ($context as $key => $value) {
        if (in_array($key, ['url', 'supabase_key', 'authorization', 'apikey', 'raw', 'response'], true)) {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $safeContext[$key] = $value;
        }
    }

    error_log('[plan_features] ' . $message . ($safeContext ? ' ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''));
}

function plan_features_storage_plan_code(string $planCode): string
{
    $planCode = strtolower(trim($planCode));

    return $planCode === 'business' ? 'biznes' : $planCode;
}

function plan_features_public_plan_code(string $planCode): string
{
    $planCode = strtolower(trim($planCode));

    return $planCode === 'biznes' ? 'business' : $planCode;
}

function plan_features_normalize_plan_code(?string $planCode): string
{
    $planCode = strtolower(trim((string) $planCode));

    if ($planCode === 'biznes') {
        return 'business';
    }

    if (!in_array($planCode, ['free', 'pro', 'vip', 'business'], true)) {
        return 'free';
    }

    return $planCode;
}

function plan_features_is_paid_plan_active(string $planCode, string $status): bool
{
    $planCode = plan_features_normalize_plan_code($planCode);
    $status = strtolower(trim($status));

    return in_array($planCode, ['pro', 'vip', 'business'], true)
        && in_array($status, ['active', 'trial'], true);
}

function plan_features_date_start(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Europe/Warsaw')))->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }
}

function plan_features_days_until(?DateTimeImmutable $date): ?int
{
    if (!$date) {
        return null;
    }

    $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw')))->setTime(0, 0, 0);
    $seconds = $date->getTimestamp() - $today->getTimestamp();

    return (int) floor($seconds / 86400);
}

function plan_features_grace_days(array $subscription): int
{
    $configured = is_numeric($subscription['grace_period_days'] ?? null)
        ? (int) $subscription['grace_period_days']
        : 0;

    return $configured > 0 ? $configured : 30;
}

function plan_features_access_state(array $subscription): array
{
    $subscriptionPlanCode = plan_features_normalize_plan_code((string) ($subscription['plan_code'] ?? 'free'));
    $status = strtolower(trim((string) ($subscription['status'] ?? 'active')));
    $periodEnd = plan_features_date_start($subscription['current_period_end'] ?? null);
    $periodDaysLeft = plan_features_days_until($periodEnd);
    $isPaidPlan = in_array($subscriptionPlanCode, ['pro', 'vip', 'business'], true);
    $periodAllowsAccess = $periodEnd !== null && $periodDaysLeft !== null && $periodDaysLeft >= 0;

    // Status payment_due/overdue oznacza problem z płatnością, ale jeśli istnieje
    // opłacony okres kończący się dziś lub później, funkcje Pro zostają aktywne
    // do końca okresu. Rejestracja Pro przed płatnością ma current_period_end=null,
    // więc nie dostaje dostępu Pro przed potwierdzeniem PayU.
    $basePaidActive = $isPaidPlan
        && in_array($status, ['active', 'trial'], true)
        && $periodAllowsAccess;
    $paymentAttentionActive = $isPaidPlan
        && in_array($status, ['payment_due', 'overdue'], true)
        && $periodAllowsAccess;
    $hasProAccess = $basePaidActive || $paymentAttentionActive;
    $proAccessExpired = $isPaidPlan
        && in_array($status, ['active', 'trial', 'payment_due', 'overdue'], true)
        && $periodEnd !== null
        && $periodDaysLeft !== null
        && $periodDaysLeft < 0;
    $graceDays = plan_features_grace_days($subscription);
    $dataGraceDaysLeft = null;
    $isInDataGracePeriod = false;

    if ($periodEnd && $proAccessExpired) {
        $dataGraceDaysLeft = plan_features_days_until($periodEnd->modify('+' . $graceDays . ' days'));
        $isInDataGracePeriod = $dataGraceDaysLeft !== null && $dataGraceDaysLeft >= 0;
    }

    return [
        'subscription_plan_code' => $subscriptionPlanCode,
        'status' => $status !== '' ? $status : 'active',
        'current_period_end' => trim((string) ($subscription['current_period_end'] ?? '')),
        'current_period_days_left' => $periodDaysLeft,
        'has_pro_access' => $hasProAccess,
        'is_paid_plan_active' => $hasProAccess,
        'pro_access_expired' => $proAccessExpired,
        'is_in_data_grace_period' => $isInDataGracePeriod,
        'data_grace_days_left' => $dataGraceDaysLeft !== null ? max(0, $dataGraceDaysLeft) : null,
        'data_grace_period_days' => $graceDays,
    ];
}

function plan_features_get_subscription_from_config(
    string $tenantId,
    array $config,
    ?callable $diagnosticLogger = null
): array
{
    $tenantId = trim($tenantId);

    if ($tenantId === '') {
        return plan_features_default_subscription();
    }

    if ($config['supabase_url'] === '' || $config['supabase_key'] === '') {
        return plan_features_default_subscription();
    }

    $url = $config['supabase_url']
        . '/rest/v1/tenant_subscriptions'
        . '?select=tenant_id,plan_code,plan_name,status,current_period_end,grace_period_days'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $result = plan_features_request(
        $url,
        $config['supabase_key'],
        $config['schema'],
        'plan_context',
        $tenantId,
        $diagnosticLogger
    );

    if (!empty($result['temporary'])) {
        plan_features_temporary_error_flag(true);

        return array_merge(plan_features_default_subscription(), [
            'source' => 'temporary_error',
            'temporary_error' => true,
        ]);
    }

    if (!$result['ok'] || !is_array($result['data'] ?? null) || empty($result['data'][0]) || !is_array($result['data'][0])) {
        return plan_features_default_subscription();
    }

    $subscription = $result['data'][0];
    $planCode = plan_features_normalize_plan_code((string) ($subscription['plan_code'] ?? 'free'));
    $status = strtolower(trim((string) ($subscription['status'] ?? '')));

    if ($status === '') {
        $status = 'active';
    }

    return [
        'tenant_id' => (string) ($subscription['tenant_id'] ?? $tenantId),
        'plan_code' => $planCode,
        'plan_name' => trim((string) ($subscription['plan_name'] ?? '')) ?: ucfirst($planCode),
        'status' => $status,
        'current_period_end' => trim((string) ($subscription['current_period_end'] ?? '')),
        'grace_period_days' => $subscription['grace_period_days'] ?? null,
        'source' => 'tenant_subscriptions',
    ];
}

function plan_features_get_subscription(string $tenantId, ?callable $diagnosticLogger = null): array
{
    return plan_features_get_subscription_from_config($tenantId, plan_features_config(), $diagnosticLogger);
}

function plan_features_fetch_single(
    string $table,
    string $query,
    array $config,
    string $tenantId = '',
    ?callable $diagnosticLogger = null
): ?array
{
    if ($config['supabase_url'] === '' || $config['supabase_key'] === '') {
        return null;
    }

    $url = $config['supabase_url'] . '/rest/v1/' . $table . '?' . $query . '&limit=1';
    $result = plan_features_request(
        $url,
        $config['supabase_key'],
        $config['schema'],
        'plan_context',
        $tenantId,
        $diagnosticLogger
    );

    if (!empty($result['temporary'])) {
        plan_features_temporary_error_flag(true);
    }

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        plan_features_log('database_read_failed', [
            'table' => $table,
            'http_code' => $result['http_code'] ?? null,
            'has_error' => !empty($result['error']),
        ]);

        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function plan_features_global_limits_select(): string
{
    return 'plan_code,plan_name,max_services,max_staff,staff_enabled,payments_enabled,google_calendar_enabled,legal_documents_enabled,branding_enabled,custom_domain_enabled,sms_enabled,service_payments_enabled,reminders_enabled,reschedule_enabled,is_active';
}

function plan_features_get_global_limits_dictionary(
    array $config,
    ?callable $diagnosticLogger = null
): ?array {
    if (
        $config['supabase_url'] === ''
        || $config['supabase_key'] === ''
        || !function_exists('booking_context_cache_key')
        || !function_exists('booking_context_cache_read')
        || !function_exists('booking_context_cache_write')
        || !function_exists('booking_context_cache_acquire_lock')
        || !function_exists('booking_context_cache_release_lock')
        || !function_exists('booking_context_cache_global_plan_ttl')
    ) {
        return null;
    }

    $stage = 'plan_global_limits';
    $cacheKey = booking_context_cache_key($stage, [
        'supabase_url' => $config['supabase_url'],
        'schema' => $config['schema'],
        'version' => 1,
    ]);
    $cached = booking_context_cache_read($stage, $cacheKey);

    if (is_array($cached['limits'] ?? null)) {
        return $cached['limits'];
    }

    $lockHandle = booking_context_cache_acquire_lock($cacheKey, 5000);

    if (!is_resource($lockHandle)) {
        return null;
    }

    try {
        $cached = booking_context_cache_read($stage, $cacheKey, false);

        if (is_array($cached['limits'] ?? null)) {
            return $cached['limits'];
        }

        $url = $config['supabase_url']
            . '/rest/v1/subscription_plan_limits'
            . '?select=' . rawurlencode(plan_features_global_limits_select())
            . '&is_active=eq.true';
        $result = plan_features_request(
            $url,
            $config['supabase_key'],
            $config['schema'],
            $stage,
            '',
            $diagnosticLogger
        );

        if (!$result['ok'] || !is_array($result['data'] ?? null)) {
            return null;
        }

        $dictionary = [];

        foreach ($result['data'] as $row) {
            if (!is_array($row) || empty($row['plan_code'])) {
                continue;
            }

            $rawPlanCode = strtolower(trim((string)$row['plan_code']));

            if (!in_array($rawPlanCode, ['free', 'pro', 'vip', 'business', 'biznes'], true)) {
                continue;
            }

            $storagePlanCode = $rawPlanCode === 'business' ? 'biznes' : $rawPlanCode;

            if (!isset($dictionary[$storagePlanCode])) {
                $dictionary[$storagePlanCode] = $row;
            }
        }

        if (empty($dictionary)) {
            return null;
        }

        booking_context_cache_write(
            $stage,
            $cacheKey,
            ['limits' => $dictionary],
            booking_context_cache_global_plan_ttl(),
            max(1, (int)($result['attempts'] ?? 1))
        );

        return $dictionary;
    } finally {
        booking_context_cache_release_lock($lockHandle);
    }
}

function plan_features_bool_or_null($value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
}

function plan_features_int_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return max(0, (int) $value);
}

function plan_features_limit_value(array $effective, string $key): ?int
{
    return array_key_exists($key, $effective) ? plan_features_int_or_null($effective[$key]) : null;
}

function plan_features_feature_value(array $effective, string $key, bool $fallback): bool
{
    if (!array_key_exists($key, $effective)) {
        return $fallback;
    }

    $value = plan_features_bool_or_null($effective[$key]);

    return $value ?? $fallback;
}

function plan_features_limits_from_effective(array $effective, array $fallbackLimits): array
{
    return [
        'services_count' => array_key_exists('max_services', $effective)
            ? plan_features_limit_value($effective, 'max_services')
            : ($fallbackLimits['services_count'] ?? null),
        'staff_count' => array_key_exists('max_staff', $effective)
            ? plan_features_limit_value($effective, 'max_staff')
            : ($fallbackLimits['staff_count'] ?? null),
    ];
}

function plan_features_features_from_effective(array $effective, string $effectivePlanCode, array $fallbackFeatures, array $limits): array
{
    $staffEnabled = plan_features_feature_value($effective, 'staff_enabled', $fallbackFeatures['staff_module'] ?? false);
    $paymentsEnabled = plan_features_feature_value($effective, 'payments_enabled', $fallbackFeatures['online_payments'] ?? false);
    $servicePaymentsEnabled = plan_features_feature_value($effective, 'service_payments_enabled', $fallbackFeatures['payu'] ?? $paymentsEnabled);
    $googleCalendarEnabled = plan_features_feature_value($effective, 'google_calendar_enabled', $fallbackFeatures['google_calendar'] ?? false);
    $legalDocumentsEnabled = plan_features_feature_value($effective, 'legal_documents_enabled', $fallbackFeatures['legal_documents'] ?? false);
    $brandingEnabled = plan_features_feature_value($effective, 'branding_enabled', $fallbackFeatures['branding_logo'] ?? false);
    $remindersEnabled = plan_features_feature_value($effective, 'reminders_enabled', $fallbackFeatures['payment_reminders'] ?? false);
    $rescheduleEnabled = plan_features_feature_value($effective, 'reschedule_enabled', $fallbackFeatures['reschedule_booking'] ?? false);
    $servicesLimit = $limits['services_count'] ?? null;
    $multipleServicesEnabled = $servicesLimit === null || (int) $servicesLimit > 1;

    if ($effectivePlanCode === 'free') {
        $multipleServicesEnabled = false;
    }

    return [
        'staff_module' => $staffEnabled,
        'multiple_services' => $multipleServicesEnabled,
        'online_payments' => $paymentsEnabled,
        'payu' => $servicePaymentsEnabled,
        'google_calendar' => $googleCalendarEnabled,
        'branding' => $brandingEnabled,
        'legal_documents' => $legalDocumentsEnabled,
        'branding_logo' => $brandingEnabled,
        'branding_favicon' => $brandingEnabled,
        'branding_colors' => $brandingEnabled,
        'calendar_appearance' => $brandingEnabled,
        'staff_blocks' => $staffEnabled,
        'admin_staff_notifications' => $staffEnabled,
        'payment_reminders' => $remindersEnabled,
        'reschedule_booking' => $rescheduleEnabled,
    ];
}

function plan_features_merge_override(array $globalLimits, ?array $override): array
{
    if (!is_array($override) || empty($override)) {
        return $globalLimits;
    }

    $fields = [
        'plan_code',
        'max_services',
        'max_staff',
        'staff_enabled',
        'payments_enabled',
        'google_calendar_enabled',
        'legal_documents_enabled',
        'branding_enabled',
        'custom_domain_enabled',
        'sms_enabled',
        'service_payments_enabled',
        'reminders_enabled',
        'reschedule_enabled',
    ];

    $effective = $globalLimits;

    foreach ($fields as $field) {
        if (array_key_exists($field, $override) && $override[$field] !== null) {
            $effective[$field] = $override[$field];
        }
    }

    return $effective;
}

function plan_features_get_context_fallback(
    string $tenantId,
    ?array $subscription = null,
    ?callable $diagnosticLogger = null
): array
{
    $subscription = is_array($subscription)
        ? $subscription
        : plan_features_get_subscription($tenantId, $diagnosticLogger);
    $accessState = plan_features_access_state($subscription);
    $subscriptionPlanCode = $accessState['subscription_plan_code'];
    $status = $accessState['status'];
    $hasProAccess = $accessState['has_pro_access'];
    $effectivePlanCode = $hasProAccess ? $subscriptionPlanCode : 'free';
    $featuresMap = plan_features_map();
    $limitsMap = plan_features_limits_map();

    return array_merge($accessState, [
        'plan_code' => $effectivePlanCode,
        'plan_name' => $effectivePlanCode === 'free'
            ? 'Free'
            : (trim((string) ($subscription['plan_name'] ?? '')) ?: ucfirst($effectivePlanCode)),
        'subscription_plan_code' => $subscriptionPlanCode,
        'status' => $status,
        'is_paid_plan_active' => $hasProAccess,
        'features' => $featuresMap[$effectivePlanCode] ?? $featuresMap['free'],
        'limits' => $limitsMap[$effectivePlanCode] ?? $limitsMap['free'],
        'source' => 'php_fallback',
    ]);
}

function plan_features_get_context_from_database(
    string $tenantId,
    array $config,
    ?callable $diagnosticLogger = null
): ?array
{
    $tenantId = trim($tenantId);

    if ($tenantId === '' || $config['supabase_url'] === '' || $config['supabase_key'] === '') {
        return null;
    }

    $subscription = plan_features_get_subscription_from_config($tenantId, $config, $diagnosticLogger);

    if (($subscription['source'] ?? '') !== 'tenant_subscriptions') {
        return null;
    }

    $accessState = plan_features_access_state($subscription);
    $subscriptionPlanCode = $accessState['subscription_plan_code'];
    $status = $accessState['status'];
    $hasProAccess = $accessState['has_pro_access'];
    $effectivePlanCode = $hasProAccess ? $subscriptionPlanCode : 'free';
    $storagePlanCode = plan_features_storage_plan_code($effectivePlanCode);
    $featuresMap = plan_features_map();
    $limitsMap = plan_features_limits_map();
    $fallbackFeatures = $featuresMap[$effectivePlanCode] ?? $featuresMap['free'];
    $fallbackLimits = $limitsMap[$effectivePlanCode] ?? $limitsMap['free'];

    $globalSelect = 'select=' . rawurlencode(plan_features_global_limits_select());
    $globalLimits = plan_features_get_global_limits_dictionary($config, $diagnosticLogger);
    $global = is_array($globalLimits[$storagePlanCode] ?? null)
        ? $globalLimits[$storagePlanCode]
        : plan_features_fetch_single(
            'subscription_plan_limits',
            $globalSelect
                . '&plan_code=eq.' . rawurlencode($storagePlanCode)
                . '&is_active=eq.true',
            $config,
            $tenantId,
            $diagnosticLogger
        );

    if (!is_array($global)) {
        return null;
    }

    $override = $hasProAccess
        ? plan_features_fetch_single(
            'tenant_plan_overrides',
            'select=' . rawurlencode('plan_code,max_services,max_staff,staff_enabled,payments_enabled,google_calendar_enabled,legal_documents_enabled,branding_enabled,custom_domain_enabled,sms_enabled,service_payments_enabled,reminders_enabled,reschedule_enabled,is_active')
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&is_active=eq.true',
            $config,
            $tenantId,
            $diagnosticLogger
        )
        : null;

    if (is_array($override) && $override['plan_code'] !== null && trim((string) $override['plan_code']) !== '') {
        $overridePlanCode = plan_features_normalize_plan_code((string) $override['plan_code']);
        $overrideStoragePlanCode = plan_features_storage_plan_code($overridePlanCode);

        if ($overrideStoragePlanCode !== $storagePlanCode) {
            $overrideGlobal = is_array($globalLimits[$overrideStoragePlanCode] ?? null)
                ? $globalLimits[$overrideStoragePlanCode]
                : plan_features_fetch_single(
                    'subscription_plan_limits',
                    $globalSelect
                        . '&plan_code=eq.' . rawurlencode($overrideStoragePlanCode)
                        . '&is_active=eq.true',
                    $config,
                    $tenantId,
                    $diagnosticLogger
                );

            if (is_array($overrideGlobal)) {
                $global = $overrideGlobal;
                $effectivePlanCode = $overridePlanCode;
                $storagePlanCode = $overrideStoragePlanCode;
                $fallbackFeatures = $featuresMap[$effectivePlanCode] ?? $featuresMap['free'];
                $fallbackLimits = $limitsMap[$effectivePlanCode] ?? $limitsMap['free'];
            }
        }
    }

    $effective = plan_features_merge_override($global, $override);
    $limits = plan_features_limits_from_effective($effective, $fallbackLimits);
    $features = plan_features_features_from_effective($effective, $effectivePlanCode, $fallbackFeatures, $limits);
    $effectiveStoragePlanCode = plan_features_storage_plan_code((string) ($effective['plan_code'] ?? $storagePlanCode));

    return array_merge($accessState, [
        'plan_code' => plan_features_public_plan_code($effectivePlanCode),
        'plan_name' => trim((string) ($effective['plan_name'] ?? '')) ?: ($effectivePlanCode === 'free' ? 'Free' : ucfirst($effectivePlanCode)),
        'subscription_plan_code' => plan_features_public_plan_code($subscriptionPlanCode),
        'effective_storage_plan_code' => $effectiveStoragePlanCode,
        'status' => $status,
        'is_paid_plan_active' => $hasProAccess,
        'features' => $features,
        'limits' => $limits,
        'source' => 'database',
        'override_active' => is_array($override),
    ]);
}

function plan_features_get_context(string $tenantId, ?callable $diagnosticLogger = null): array
{
    static $cache = [];

    $cacheKey = trim($tenantId);

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    plan_features_temporary_error_flag(false);

    $config = plan_features_config();
    $persistentCacheKey = '';
    $requestCountBefore = function_exists('booking_supabase_request_count_for_stage')
        ? booking_supabase_request_count_for_stage('plan_context')
        : 0;

    if (function_exists('booking_context_cache_key')) {
        $persistentCacheKey = booking_context_cache_key('plan_context', [
            'tenant_id' => trim($tenantId),
            'supabase_url' => $config['supabase_url'],
            'schema' => $config['schema'],
        ]);
        $cachedContext = booking_context_cache_read('plan_context', $persistentCacheKey);

        if (is_array($cachedContext)) {
            $cache[$cacheKey] = $cachedContext;
            return $cachedContext;
        }
    }

    $context = plan_features_get_context_from_database($tenantId, $config, $diagnosticLogger);

    if (is_array($context)) {
        if (plan_features_temporary_error_flag()) {
            $context['temporary_error'] = true;
        }

        $cache[$cacheKey] = $context;

        if ($persistentCacheKey !== '' && empty($context['temporary_error'])) {
            $requestCountAfter = function_exists('booking_supabase_request_count_for_stage')
                ? booking_supabase_request_count_for_stage('plan_context')
                : $requestCountBefore + 1;
            booking_context_cache_write(
                'plan_context',
                $persistentCacheKey,
                $context,
                booking_context_cache_default_ttl(),
                max(1, $requestCountAfter - $requestCountBefore)
            );
        }

        return $context;
    }

    plan_features_log('using_php_fallback', [
        'tenant_id_set' => trim($tenantId) !== '',
        'schema' => $config['schema'] ?? null,
    ]);

    $context = plan_features_get_context_fallback($tenantId, null, $diagnosticLogger);

    if (plan_features_temporary_error_flag()) {
        $context['temporary_error'] = true;
    }

    $cache[$cacheKey] = $context;

    if ($persistentCacheKey !== '' && empty($context['temporary_error'])) {
        $requestCountAfter = function_exists('booking_supabase_request_count_for_stage')
            ? booking_supabase_request_count_for_stage('plan_context')
            : $requestCountBefore + 1;
        booking_context_cache_write(
            'plan_context',
            $persistentCacheKey,
            $context,
            booking_context_cache_default_ttl(),
            max(1, $requestCountAfter - $requestCountBefore)
        );
    }

    return $context;
}

function tenant_has_feature(
    string $tenantId,
    string $featureKey,
    ?callable $diagnosticLogger = null
): bool
{
    $featureKey = trim($featureKey);

    if ($featureKey === '') {
        return false;
    }

    $context = plan_features_get_context($tenantId, $diagnosticLogger);
    $features = is_array($context['features'] ?? null) ? $context['features'] : [];

    return !empty($features[$featureKey]);
}

function require_tenant_feature(string $tenantId, string $featureKey, string $message = ''): void
{
    if (tenant_has_feature($tenantId, $featureKey)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'error' => $message !== '' ? $message : 'Ta funkcja jest dostępna w wersji Pro.',
        'feature' => $featureKey,
        'upgrade_required' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}
