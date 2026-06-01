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
            'staff_count' => 1,
        ],
        'pro' => [
            'services_count' => 30,
            'staff_count' => 20,
        ],
        'vip' => [
            'services_count' => 50,
            'staff_count' => 50,
        ],
        'business' => [
            'services_count' => 50,
            'staff_count' => 50,
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

function plan_features_normalize_plan_code(?string $planCode): string
{
    $planCode = strtolower(trim((string) $planCode));

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

function plan_features_get_subscription(string $tenantId): array
{
    $tenantId = trim($tenantId);

    if ($tenantId === '') {
        return plan_features_default_subscription();
    }

    $config = plan_features_config();

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

function plan_features_get_context(string $tenantId): array
{
    $subscription = plan_features_get_subscription($tenantId);
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
    ];
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
