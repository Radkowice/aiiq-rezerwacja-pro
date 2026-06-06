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

function plan_features_request(string $url, string $supabaseKey, string $schema): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'ok' => $response !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $curlError,
        'data' => json_decode((string) $response, true),
        'raw' => $response,
    ];
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

function plan_features_get_subscription_from_config(string $tenantId, array $config): array
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
        . '?select=tenant_id,plan_code,plan_name,status'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $result = plan_features_request($url, $config['supabase_key'], $config['schema']);

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
        'source' => 'tenant_subscriptions',
    ];
}

function plan_features_get_subscription(string $tenantId): array
{
    return plan_features_get_subscription_from_config($tenantId, plan_features_config());
}

function plan_features_fetch_single(string $table, string $query, array $config): ?array
{
    if ($config['supabase_url'] === '' || $config['supabase_key'] === '') {
        return null;
    }

    $url = $config['supabase_url'] . '/rest/v1/' . $table . '?' . $query . '&limit=1';
    $result = plan_features_request($url, $config['supabase_key'], $config['schema']);

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
        'legal_documents' => $fallbackFeatures['legal_documents'] ?? $effectivePlanCode !== 'free',
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

function plan_features_get_context_fallback(string $tenantId, ?array $subscription = null): array
{
    $subscription = is_array($subscription) ? $subscription : plan_features_get_subscription($tenantId);
    $subscriptionPlanCode = plan_features_normalize_plan_code((string) ($subscription['plan_code'] ?? 'free'));
    $status = strtolower(trim((string) ($subscription['status'] ?? 'active')));
    $isPaidPlanActive = plan_features_is_paid_plan_active($subscriptionPlanCode, $status);
    $effectivePlanCode = $isPaidPlanActive ? $subscriptionPlanCode : 'free';
    $featuresMap = plan_features_map();
    $limitsMap = plan_features_limits_map();

    return [
        'plan_code' => $effectivePlanCode,
        'plan_name' => $effectivePlanCode === 'free'
            ? 'Free'
            : (trim((string) ($subscription['plan_name'] ?? '')) ?: ucfirst($effectivePlanCode)),
        'subscription_plan_code' => $subscriptionPlanCode,
        'status' => $status,
        'is_paid_plan_active' => $isPaidPlanActive,
        'features' => $featuresMap[$effectivePlanCode] ?? $featuresMap['free'],
        'limits' => $limitsMap[$effectivePlanCode] ?? $limitsMap['free'],
        'source' => 'php_fallback',
    ];
}

function plan_features_get_context_from_database(string $tenantId, array $config): ?array
{
    $tenantId = trim($tenantId);

    if ($tenantId === '' || $config['supabase_url'] === '' || $config['supabase_key'] === '') {
        return null;
    }

    $subscription = plan_features_get_subscription_from_config($tenantId, $config);

    if (($subscription['source'] ?? '') !== 'tenant_subscriptions') {
        return null;
    }

    $subscriptionPlanCode = plan_features_normalize_plan_code((string) ($subscription['plan_code'] ?? 'free'));
    $status = strtolower(trim((string) ($subscription['status'] ?? 'active')));
    $isPaidPlanActive = plan_features_is_paid_plan_active($subscriptionPlanCode, $status);
    $effectivePlanCode = $isPaidPlanActive ? $subscriptionPlanCode : 'free';
    $storagePlanCode = plan_features_storage_plan_code($effectivePlanCode);
    $featuresMap = plan_features_map();
    $limitsMap = plan_features_limits_map();
    $fallbackFeatures = $featuresMap[$effectivePlanCode] ?? $featuresMap['free'];
    $fallbackLimits = $limitsMap[$effectivePlanCode] ?? $limitsMap['free'];

    $globalSelect = 'select=' . rawurlencode('plan_code,plan_name,max_services,max_staff,staff_enabled,payments_enabled,google_calendar_enabled,branding_enabled,custom_domain_enabled,sms_enabled,service_payments_enabled,reminders_enabled,reschedule_enabled,is_active');
    $global = plan_features_fetch_single(
        'subscription_plan_limits',
        $globalSelect
            . '&plan_code=eq.' . rawurlencode($storagePlanCode)
            . '&is_active=eq.true',
        $config
    );

    if (!is_array($global)) {
        return null;
    }

    $override = plan_features_fetch_single(
        'tenant_plan_overrides',
        'select=' . rawurlencode('plan_code,max_services,max_staff,staff_enabled,payments_enabled,google_calendar_enabled,branding_enabled,custom_domain_enabled,sms_enabled,service_payments_enabled,reminders_enabled,reschedule_enabled,is_active')
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&is_active=eq.true',
        $config
    );

    if (is_array($override) && $override['plan_code'] !== null && trim((string) $override['plan_code']) !== '') {
        $overridePlanCode = plan_features_normalize_plan_code((string) $override['plan_code']);
        $overrideStoragePlanCode = plan_features_storage_plan_code($overridePlanCode);

        if ($overrideStoragePlanCode !== $storagePlanCode) {
            $overrideGlobal = plan_features_fetch_single(
                'subscription_plan_limits',
                $globalSelect
                    . '&plan_code=eq.' . rawurlencode($overrideStoragePlanCode)
                    . '&is_active=eq.true',
                $config
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

    return [
        'plan_code' => plan_features_public_plan_code($effectivePlanCode),
        'plan_name' => trim((string) ($effective['plan_name'] ?? '')) ?: ($effectivePlanCode === 'free' ? 'Free' : ucfirst($effectivePlanCode)),
        'subscription_plan_code' => plan_features_public_plan_code($subscriptionPlanCode),
        'effective_storage_plan_code' => $effectiveStoragePlanCode,
        'status' => $status,
        'is_paid_plan_active' => $isPaidPlanActive,
        'features' => $features,
        'limits' => $limits,
        'source' => 'database',
        'override_active' => is_array($override),
    ];
}

function plan_features_get_context(string $tenantId): array
{
    $config = plan_features_config();
    $context = plan_features_get_context_from_database($tenantId, $config);

    if (is_array($context)) {
        return $context;
    }

    plan_features_log('using_php_fallback', [
        'tenant_id_set' => trim($tenantId) !== '',
        'schema' => $config['schema'] ?? null,
    ]);

    return plan_features_get_context_fallback($tenantId);
}

function tenant_has_feature(string $tenantId, string $featureKey): bool
{
    $featureKey = trim($featureKey);

    if ($featureKey === '') {
        return false;
    }

    $context = plan_features_get_context($tenantId);
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
