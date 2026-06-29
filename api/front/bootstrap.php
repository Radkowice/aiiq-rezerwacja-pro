<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

function front_bootstrap_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function front_bootstrap_cache_dir(): string
{
    return __DIR__ . '/../../data/cache/front-bootstrap';
}

function front_bootstrap_cache_key(): string
{
    $hosts = host_candidates();
    $hostKey = !empty($hosts) ? implode('|', $hosts) : (string)($_SERVER['HTTP_HOST'] ?? '');

    return hash('sha256', strtolower($hostKey));
}

function front_bootstrap_cache_file(string $cacheKey): string
{
    return front_bootstrap_cache_dir() . '/' . $cacheKey . '.json';
}

function front_bootstrap_blocked_cache_dir(): string
{
    return __DIR__ . '/../../data/cache/front-bootstrap-blocked';
}

function front_bootstrap_cache_file_in_dir(string $cacheDir, string $cacheKey): string
{
    return $cacheDir . '/' . $cacheKey . '.json';
}

function front_bootstrap_ensure_cache_dir_path(string $cacheDir): bool
{
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    return is_dir($cacheDir);
}

function front_bootstrap_ensure_cache_dir(): bool
{
    return front_bootstrap_ensure_cache_dir_path(front_bootstrap_cache_dir());
}

function front_bootstrap_lock_cache_in_dir(string $cacheDir, string $cacheKey)
{
    if (!front_bootstrap_ensure_cache_dir_path($cacheDir)) {
        return null;
    }

    $lockHandle = @fopen($cacheDir . '/' . $cacheKey . '.lock', 'c');

    if (!$lockHandle) {
        return null;
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        return null;
    }

    return $lockHandle;
}

function front_bootstrap_lock_cache(string $cacheKey)
{
    return front_bootstrap_lock_cache_in_dir(front_bootstrap_cache_dir(), $cacheKey);
}

function front_bootstrap_read_cache_from_dir(string $cacheDir, string $cacheKey): ?array
{
    $file = front_bootstrap_cache_file_in_dir($cacheDir, $cacheKey);

    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    $cache = json_decode((string) $raw, true);

    if (
        !is_array($cache)
        || !isset($cache['expires_at'], $cache['payload'])
        || (int) $cache['expires_at'] < time()
        || !is_array($cache['payload'])
    ) {
        return null;
    }

    return $cache['payload'];
}

function front_bootstrap_read_cache(string $cacheKey): ?array
{
    return front_bootstrap_read_cache_from_dir(front_bootstrap_cache_dir(), $cacheKey);
}

function front_bootstrap_write_cache_to_dir(string $cacheDir, string $cacheKey, array $payload, int $ttlSeconds): void
{
    if (!front_bootstrap_ensure_cache_dir_path($cacheDir)) {
        return;
    }

    $cache = [
        'expires_at' => time() + $ttlSeconds,
        'payload' => $payload,
    ];

    @file_put_contents(
        front_bootstrap_cache_file_in_dir($cacheDir, $cacheKey),
        json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function front_bootstrap_write_cache(string $cacheKey, array $payload, int $ttlSeconds = 40): void
{
    front_bootstrap_write_cache_to_dir(front_bootstrap_cache_dir(), $cacheKey, $payload, $ttlSeconds);
}

function front_bootstrap_blocked_cache_key(string $tenantId): string
{
    $hosts = host_candidates();
    $hostKey = !empty($hosts) ? implode('|', $hosts) : (string)($_SERVER['HTTP_HOST'] ?? '');
    $scope = strtolower($hostKey) . '|' . $tenantId . '|' . date('Y-m');

    return hash('sha256', $scope);
}

function front_bootstrap_read_blocked_cache(string $cacheKey): ?array
{
    return front_bootstrap_read_cache_from_dir(front_bootstrap_blocked_cache_dir(), $cacheKey);
}

function front_bootstrap_write_blocked_cache(string $cacheKey, array $payload, int $ttlSeconds = 25): void
{
    front_bootstrap_write_cache_to_dir(front_bootstrap_blocked_cache_dir(), $cacheKey, $payload, $ttlSeconds);
}

function front_bootstrap_lock_blocked_cache(string $cacheKey)
{
    return front_bootstrap_lock_cache_in_dir(front_bootstrap_blocked_cache_dir(), $cacheKey);
}

function front_bootstrap_technical_status(array $result): int
{
    return (int)($result['httpCode'] ?? 0) === 429 ? 429 : 503;
}

function front_bootstrap_request(string $url, string $key, string $schema): array
{
    $attempts = 2;
    $lastResult = [
        'ok' => false,
        'response' => false,
        'error' => '',
        'httpCode' => 0,
        'data' => null,
        'jsonValid' => false,
        'retryable' => true,
    ];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => supabaseHeaders($key, $schema),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $data = json_decode((string) $response, true);
        $jsonValid = json_last_error() === JSON_ERROR_NONE;
        $retryable = $response === false
            || $error !== ''
            || $httpCode === 429
            || $httpCode >= 500
            || $httpCode === 0;

        $lastResult = [
            'ok' => $response !== false
                && $error === ''
                && $httpCode >= 200
                && $httpCode < 300
                && $jsonValid
                && is_array($data),
            'response' => $response,
            'error' => $error,
            'httpCode' => $httpCode,
            'data' => $data,
            'jsonValid' => $jsonValid,
            'retryable' => $retryable,
        ];

        if ($lastResult['ok'] || !$retryable || $attempt === $attempts) {
            break;
        }

        usleep(150000);
    }

    return $lastResult;
}


function front_bootstrap_multi_request(array $requests, string $key, string $schema): array
{
    $multi = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($requests as $name => $url) {
        if (!is_string($name) || !is_string($url) || trim($url) === '') {
            continue;
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => supabaseHeaders($key, $schema),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);

        $handles[$name] = $ch;
        curl_multi_add_handle($multi, $ch);
    }

    $running = null;

    do {
        $status = curl_multi_exec($multi, $running);

        if ($running) {
            $selectResult = curl_multi_select($multi, 0.4);

            if ($selectResult === -1) {
                usleep(10000);
            }
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $name => $ch) {
        $response = curl_multi_getcontent($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $results[$name] = [
            'ok' => $response !== false && $error === '' && $httpCode >= 200 && $httpCode < 300,
            'response' => $response,
            'error' => $error,
            'httpCode' => $httpCode,
            'data' => json_decode((string) $response, true),
        ];

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }

    curl_multi_close($multi);

    foreach ($requests as $name => $url) {
        $result = $results[$name] ?? null;
        $retryable = !is_array($result)
            || ($result['response'] ?? false) === false
            || ($result['error'] ?? '') !== ''
            || (int)($result['httpCode'] ?? 0) === 429
            || (int)($result['httpCode'] ?? 0) >= 500
            || (int)($result['httpCode'] ?? 0) === 0;

        if (is_array($result) && !empty($result['ok'])) {
            continue;
        }

        if ($retryable && is_string($url) && trim($url) !== '') {
            $results[$name] = front_bootstrap_request($url, $key, $schema);
        }
    }

    return $results;
}

function front_bootstrap_feature(array $planContext, string $featureKey): bool
{
    $features = is_array($planContext['features'] ?? null) ? $planContext['features'] : [];

    return !empty($features[$featureKey]);
}

function front_bootstrap_public_plan_context(array $planContext): array
{
    $features = is_array($planContext['features'] ?? null) ? $planContext['features'] : [];
    $publicPlanCode = (string) ($planContext['plan_code'] ?? 'free');
    $featureKeys = [
        'staff_module',
        'multiple_services',
        'online_payments',
        'payu',
        'legal_documents',
        'branding_logo',
        'branding_favicon',
        'branding_colors',
        'calendar_appearance',
        'reschedule_booking',
    ];
    $publicFeatures = [];

    foreach ($featureKeys as $featureKey) {
        $publicFeatures[$featureKey] = !empty($features[$featureKey]);
    }

    return [
        'plan_code' => $publicPlanCode,
        'is_free' => $publicPlanCode === 'free',
        'features' => $publicFeatures,
    ];
}

function front_bootstrap_filter_branding_for_plan(array $branding, array $planContext): array
{
    $features = is_array($planContext['features'] ?? null) ? $planContext['features'] : [];
    $hasCalendarAppearance = !empty($features['calendar_appearance']);

    return [
        'client_name' => (string)($branding['client_name'] ?? ''),
        'service_title_front' => $hasCalendarAppearance ? (string)($branding['service_title_front'] ?? '') : '',
        'logo_url_front' => !empty($features['branding_logo']) ? (string)($branding['logo_url_front'] ?? '') : '',
        'favicon_url_front' => !empty($features['branding_favicon']) ? (string)($branding['favicon_url_front'] ?? '') : '',
        'calendar_front_style' => $hasCalendarAppearance && is_array($branding['calendar_front_style'] ?? null)
            ? $branding['calendar_front_style']
            : [],
        'calendar_form_fields' => $hasCalendarAppearance && is_array($branding['calendar_form_fields'] ?? null)
            ? $branding['calendar_form_fields']
            : [],
    ];
}

function front_bootstrap_fail(string $message, int $statusCode = 500): void
{
    front_bootstrap_json([
        'success' => false,
        'error' => $message,
    ], $statusCode);
}

function front_bootstrap_rows(array $result): array
{
    return is_array($result['data'] ?? null) ? $result['data'] : [];
}

function front_bootstrap_first_row(array $result): array
{
    $rows = front_bootstrap_rows($result);
    $row = $rows[0] ?? [];

    return is_array($row) ? $row : [];
}

function front_bootstrap_nullable_int($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (int) $value : null;
}

function front_bootstrap_price($value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function front_bootstrap_push_time(array &$map, string $date, string $time): void
{
    if (!isset($map[$date])) {
        $map[$date] = [];
    }

    $map[$date][] = $time;
}

function front_bootstrap_unique_time_map(array $map): array
{
    foreach ($map as $date => $times) {
        $map[$date] = array_values(array_unique(is_array($times) ? $times : []));
    }

    return $map;
}

function front_bootstrap_generate_working_hours(array $calendarSettings): array
{
    $start = substr((string)($calendarSettings['work_start'] ?? '09:00'), 0, 5);
    $end = substr((string)($calendarSettings['work_end'] ?? '17:00'), 0, 5);
    $duration = max(1, (int)($calendarSettings['consultation_duration'] ?? 60));
    $break = max(0, (int)($calendarSettings['consultation_break'] ?? 0));
    $workingHours = [];

    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        return $workingHours;
    }

    [$startHour, $startMinute] = array_map('intval', explode(':', $start));
    [$endHour, $endMinute] = array_map('intval', explode(':', $end));

    $current = ($startHour * 60) + $startMinute;
    $endMinutes = ($endHour * 60) + $endMinute;

    while ($current + $duration <= $endMinutes) {
        $workingHours[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
        $current += $duration + $break;
    }

    return $workingHours;
}

function front_bootstrap_build_branding(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    array $planContext
): array {
    $url = $supabaseUrl
        . '/rest/v1/tenant_branding'
        . '?select=tenant_id,client_name,service_title_front,logo_url_front,favicon_url_front,calendar_front_style,calendar_form_fields,updated_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $result = front_bootstrap_request($url, $serviceRoleKey, $schema);

    if (!$result['ok']) {
        front_bootstrap_json([
            'success' => false,
            'error' => 'temporary_unavailable',
            'message' => 'Nie udało się chwilowo pobrać brandingu. Spróbuj ponownie za moment.',
        ], front_bootstrap_technical_status($result));
    }

    $row = front_bootstrap_first_row($result);

    if (empty($row)) {
        front_bootstrap_fail('Nie znaleziono brandingu klienta', 404);
    }

    $features = is_array($planContext['features'] ?? null) ? $planContext['features'] : [];
    $hasBrandingLogo = !empty($features['branding_logo']);
    $hasBrandingFavicon = !empty($features['branding_favicon']);
    $publicLogoUrl = $hasBrandingLogo
        ? branding_asset_public_url((string)($row['logo_url_front'] ?? ''), $tenantId, 'logo')
        : '';
    $publicFaviconUrl = $hasBrandingFavicon
        ? branding_asset_public_url((string)($row['favicon_url_front'] ?? ''), $tenantId, 'favicon')
        : '';

    return [
        'plan_context' => front_bootstrap_public_plan_context($planContext),
        'branding' => front_bootstrap_filter_branding_for_plan([
            'client_name' => (string)($row['client_name'] ?? ''),
            'service_title_front' => (string)($row['service_title_front'] ?? ''),
            'logo_url_front' => $publicLogoUrl,
            'favicon_url_front' => $publicFaviconUrl,
            'calendar_front_style' => is_array($row['calendar_front_style'] ?? null) ? $row['calendar_front_style'] : [],
            'calendar_form_fields' => is_array($row['calendar_form_fields'] ?? null) ? $row['calendar_form_fields'] : [],
        ], $planContext),
    ];
}

function front_bootstrap_build_service(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    array $planContext
): array {
    $settingsUrl = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&select=service_name,service_description,price_amount,price_currency,payment_required,payment_message,company_full_name'
        . '&limit=1';

    $payuUrl = $supabaseUrl
        . '/rest/v1/tenant_integrations'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&provider=eq.payu'
        . '&select=enabled'
        . '&limit=1';

    $calendarUrl = $supabaseUrl
        . '/rest/v1/calendar_settings'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&select=calendar_enabled,work_start,work_end,consultation_duration,consultation_break,booking_buffer,booking_start_month_offset,booking_month_range'
        . '&limit=1';

    $results = front_bootstrap_multi_request([
        'settings' => $settingsUrl,
        'payu' => $payuUrl,
        'calendar' => $calendarUrl,
    ], $serviceRoleKey, $schema);

    $settingsResult = $results['settings'] ?? ['ok' => false];

    if (!$settingsResult['ok']) {
        front_bootstrap_json([
            'success' => false,
            'error' => 'temporary_unavailable',
            'message' => 'Nie udało się chwilowo pobrać danych usługi. Spróbuj ponownie za moment.',
        ], front_bootstrap_technical_status($settingsResult));
    }

    $settings = front_bootstrap_first_row($settingsResult);

    if (empty($settings)) {
        return [
            'service' => null,
            'company_full_name' => '',
            'payu_enabled' => false,
            'global_payments_enabled' => false,
            'calendar_settings' => [],
        ];
    }

    $payuResult = $results['payu'] ?? ['ok' => false];
    $payuIntegration = $payuResult['ok'] ? front_bootstrap_first_row($payuResult) : [];
    $payuEnabled = !empty($payuIntegration['enabled']);

    $calendarResult = $results['calendar'] ?? ['ok' => false];
    $calendarSettings = $calendarResult['ok'] ? front_bootstrap_first_row($calendarResult) : [];

    $settings['calendar_enabled'] = (bool)($calendarSettings['calendar_enabled'] ?? false);
    $settings['work_start'] = (string)($calendarSettings['work_start'] ?? '09:00');
    $settings['work_end'] = (string)($calendarSettings['work_end'] ?? '17:00');
    $settings['consultation_duration'] = (int)($calendarSettings['consultation_duration'] ?? 60);
    $settings['consultation_break'] = (int)($calendarSettings['consultation_break'] ?? 0);
    $settings['booking_buffer'] = (int)($calendarSettings['booking_buffer'] ?? 0);
    $settings['booking_start_month_offset'] = (int)($calendarSettings['booking_start_month_offset'] ?? 0);
    $settings['booking_month_range'] = (int)($calendarSettings['booking_month_range'] ?? 1);

    $settings['payment_required_configured'] = !empty($settings['payment_required']);
    $settings['payment_provider_enabled'] = $payuEnabled;
    $globalPriceAmount = front_bootstrap_price($settings['price_amount'] ?? null);
    $globalPaymentFeatureEnabled = front_bootstrap_feature($planContext, 'online_payments')
        && front_bootstrap_feature($planContext, 'payu');
    $settings['payment_required'] = !empty($settings['payment_required'])
        && $globalPaymentFeatureEnabled
        && $payuEnabled
        && $globalPriceAmount !== null
        && $globalPriceAmount > 0;

    return [
        'service' => $settings,
        'company_full_name' => (string)($settings['company_full_name'] ?? ''),
        'payu_enabled' => $payuEnabled,
        'global_payments_enabled' => !empty($settings['payment_required_configured']),
        'calendar_settings' => $settings,
    ];
}

function front_bootstrap_build_services(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    array $publicPlanContext,
    bool $payuEnabled
): array {
    if (!front_bootstrap_feature($publicPlanContext, 'multiple_services')) {
        return [
            'success' => true,
            'services' => [],
        ];
    }

    $onlinePaymentsEnabled = front_bootstrap_feature($publicPlanContext, 'online_payments') && front_bootstrap_feature($publicPlanContext, 'payu');
    $refSecret = public_response_ref_secret($serviceRoleKey);
    $serviceSelect = implode(',', [
        'id',
        'name',
        'description',
        'duration_minutes',
        'break_minutes',
        'booking_buffer_minutes',
        'price_amount',
        'price_currency',
        'payments_enabled',
        'payment_message',
        'sort_order',
    ]);

    $servicesUrl = $supabaseUrl
        . '/rest/v1/tenant_services'
        . '?select=' . rawurlencode($serviceSelect)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&order=sort_order.asc'
        . '&order=name.asc';

    $servicesResult = front_bootstrap_request($servicesUrl, $serviceRoleKey, $schema);

    if (!$servicesResult['ok']) {
        return [
            'success' => false,
            'services' => [],
            'error' => 'Nie udało się pobrać usług',
        ];
    }

    $serviceRows = front_bootstrap_rows($servicesResult);

    if (empty($serviceRows)) {
        return [
            'success' => true,
            'services' => [],
        ];
    }

    $serviceIds = [];

    foreach ($serviceRows as $serviceRow) {
        if (is_array($serviceRow) && !empty($serviceRow['id'])) {
            $serviceIds[(string) $serviceRow['id']] = true;
        }
    }

    $staffIdsByService = [];
    $allStaffIds = [];

    if (!empty($serviceIds)) {
        $serviceIdList = implode(',', array_map('rawurlencode', array_keys($serviceIds)));
        $relationsUrl = $supabaseUrl
            . '/rest/v1/tenant_service_staff'
            . '?select=service_id,staff_id'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&service_id=in.(' . $serviceIdList . ')';

        $relationsResult = front_bootstrap_request($relationsUrl, $serviceRoleKey, $schema);
        $relationRows = $relationsResult['ok'] ? front_bootstrap_rows($relationsResult) : [];

        foreach ($relationRows as $relationRow) {
            if (!is_array($relationRow)) {
                continue;
            }

            $serviceId = (string)($relationRow['service_id'] ?? '');
            $staffId = (string)($relationRow['staff_id'] ?? '');

            if ($serviceId === '' || $staffId === '' || !isset($serviceIds[$serviceId])) {
                continue;
            }

            $staffIdsByService[$serviceId] ??= [];
            $staffIdsByService[$serviceId][$staffId] = true;
            $allStaffIds[$staffId] = true;
        }
    }

    $staffById = [];

    if (!empty($allStaffIds)) {
        $staffIdList = implode(',', array_map('rawurlencode', array_keys($allStaffIds)));
        $staffSelect = implode(',', ['id', 'display_name', 'description', 'color', 'sort_order']);
        $staffUrl = $supabaseUrl
            . '/rest/v1/staff_profiles'
            . '?select=' . rawurlencode($staffSelect)
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&id=in.(' . $staffIdList . ')'
            . '&is_active=eq.true'
            . '&order=sort_order.asc'
            . '&order=display_name.asc';

        $staffResult = front_bootstrap_request($staffUrl, $serviceRoleKey, $schema);
        $staffRows = $staffResult['ok'] ? front_bootstrap_rows($staffResult) : [];

        foreach ($staffRows as $staffRow) {
            if (!is_array($staffRow) || empty($staffRow['id'])) {
                continue;
            }

            $staffId = (string) $staffRow['id'];
            $staffRef = public_response_staff_ref($tenantId, $staffId, $refSecret);

            $staffById[(string)$staffRow['id']] = [
                'id' => $staffRef,
                'staff_ref' => $staffRef,
                'display_name' => (string)($staffRow['display_name'] ?? ''),
                'description' => (string)($staffRow['description'] ?? ''),
                'color' => (string)($staffRow['color'] ?? ''),
                'sort_order' => front_bootstrap_nullable_int($staffRow['sort_order'] ?? null) ?? 0,
            ];
        }
    }

    $services = [];

    foreach ($serviceRows as $serviceRow) {
        if (!is_array($serviceRow) || empty($serviceRow['id'])) {
            continue;
        }

        $serviceId = (string)$serviceRow['id'];
        $assignedStaff = [];

        foreach (array_keys($staffIdsByService[$serviceId] ?? []) as $staffId) {
            if (isset($staffById[$staffId])) {
                $assignedStaff[] = $staffById[$staffId];
            }
        }

        usort($assignedStaff, static function (array $a, array $b): int {
            $sortCompare = ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));

            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return strcmp((string)($a['display_name'] ?? ''), (string)($b['display_name'] ?? ''));
        });

        $priceAmount = front_bootstrap_price($serviceRow['price_amount'] ?? null);
        $servicePaymentsEnabled = !empty($serviceRow['payments_enabled']);
        $paymentRequired = $onlinePaymentsEnabled
            && $payuEnabled
            && $servicePaymentsEnabled
            && $priceAmount !== null
            && $priceAmount > 0;
        $serviceRef = public_response_service_ref($tenantId, $serviceId, $refSecret);

        $services[] = [
            'id' => $serviceRef,
            'service_ref' => $serviceRef,
            'name' => (string)($serviceRow['name'] ?? ''),
            'description' => (string)($serviceRow['description'] ?? ''),
            'price_amount' => $priceAmount,
            'price_currency' => (string)($serviceRow['price_currency'] ?? 'PLN'),
            'payments_enabled' => $servicePaymentsEnabled,
            'payment_required' => $paymentRequired,
            'payment_message' => (string)($serviceRow['payment_message'] ?? ''),
            'duration' => front_bootstrap_nullable_int($serviceRow['duration_minutes'] ?? null),
            'break_minutes' => front_bootstrap_nullable_int($serviceRow['break_minutes'] ?? null),
            'booking_buffer_minutes' => front_bootstrap_nullable_int($serviceRow['booking_buffer_minutes'] ?? null),
            'sort_order' => front_bootstrap_nullable_int($serviceRow['sort_order'] ?? null) ?? 0,
            'assigned_staff' => $assignedStaff,
        ];
    }

    return [
        'success' => true,
        'services' => $services,
    ];
}

function front_bootstrap_build_staff(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    array $publicPlanContext
): array {
    if (!front_bootstrap_feature($publicPlanContext, 'staff_module')) {
        return [
            'success' => false,
            'code' => 'staff_panel_requires_pro',
            'feature' => 'staff_module',
            'upgrade_required' => true,
            'staff_enabled' => false,
            'staff' => [],
        ];
    }

    $select = implode(',', [
        'id',
        'display_name',
        'description',
        'color',
        'sort_order',
        'service_name',
        'service_description',
        'service_duration_minutes',
        'service_break_minutes',
        'booking_buffer_minutes',
        'service_price',
        'payments_enabled',
    ]);

    $staffUrl = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=' . rawurlencode($select)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&order=sort_order.asc'
        . '&order=display_name.asc';

    $staffResult = front_bootstrap_request($staffUrl, $serviceRoleKey, $schema);

    if (!$staffResult['ok']) {
        return [
            'success' => false,
            'staff_enabled' => false,
            'staff' => [],
            'error' => 'Nie udało się pobrać personelu',
        ];
    }

    $rows = front_bootstrap_rows($staffResult);
    $staff = [];
    $refSecret = public_response_ref_secret($serviceRoleKey);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = (string)($row['id'] ?? '');
        $staffRef = $staffId !== ''
            ? public_response_staff_ref($tenantId, $staffId, $refSecret)
            : '';

        $staff[] = [
            'id' => $staffRef,
            'staff_ref' => $staffRef,
            'display_name' => (string)($row['display_name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'color' => (string)($row['color'] ?? ''),
            'service_name' => array_key_exists('service_name', $row) && $row['service_name'] !== null ? (string)$row['service_name'] : null,
            'service_description' => array_key_exists('service_description', $row) && $row['service_description'] !== null ? (string)$row['service_description'] : null,
            'service_duration_minutes' => array_key_exists('service_duration_minutes', $row) && $row['service_duration_minutes'] !== null ? (int)$row['service_duration_minutes'] : null,
            'service_break_minutes' => array_key_exists('service_break_minutes', $row) && $row['service_break_minutes'] !== null ? (int)$row['service_break_minutes'] : null,
            'booking_buffer_minutes' => array_key_exists('booking_buffer_minutes', $row) && $row['booking_buffer_minutes'] !== null ? (int)$row['booking_buffer_minutes'] : null,
            'service_price' => array_key_exists('service_price', $row) && $row['service_price'] !== null ? (string)$row['service_price'] : null,
            'payments_enabled' => array_key_exists('payments_enabled', $row) && $row['payments_enabled'] !== null ? (bool)$row['payments_enabled'] : null,
        ];
    }

    return [
        'success' => true,
        'staff_enabled' => count($staff) > 0,
        'staff' => $staff,
    ];
}

function front_bootstrap_build_legal(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    array $publicPlanContext,
    string $companyFullName
): array {
    $features = is_array($publicPlanContext['features'] ?? null) ? $publicPlanContext['features'] : [];
    $isFree = !empty($publicPlanContext['is_free']) || (string)($publicPlanContext['plan_code'] ?? 'free') === 'free';
    $provider = [
        'company_full_name' => $companyFullName,
    ];

    if ($isFree || empty($features['legal_documents'])) {
        return [
            'success' => true,
            'enabled' => false,
            'provider' => $provider,
            'documents' => null,
        ];
    }

    $url = $supabaseUrl
        . '/rest/v1/tenant_legal_documents'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_enabled=eq.true'
        . '&select=tenant_id,terms_title,privacy_title,is_enabled,updated_at'
        . '&limit=1';

    $result = front_bootstrap_request($url, $serviceRoleKey, $schema);

    if (!$result['ok']) {
        return [
            'success' => false,
            'enabled' => false,
            'provider' => $provider,
            'documents' => null,
            'error' => 'Nie udało się pobrać dokumentów prawnych',
        ];
    }

    $row = front_bootstrap_first_row($result);

    if (empty($row)) {
        return [
            'success' => true,
            'enabled' => false,
            'provider' => $provider,
            'documents' => null,
        ];
    }

    return [
        'success' => true,
        'enabled' => !empty($row['is_enabled']),
        'provider' => $provider,
        'documents' => [
            'terms_title' => (string)($row['terms_title'] ?? 'Regulamin rezerwacji'),
            'privacy_title' => (string)($row['privacy_title'] ?? 'Polityka prywatności'),
            'updated_at' => $row['updated_at'] ?? null,
            'links' => [
                'terms' => '/dokumenty/regulamin.html',
                'privacy' => '/dokumenty/polityka-prywatnosci.html',
            ],
        ],
    ];
}

function front_bootstrap_build_blocked(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    array $calendarSettings
): array {
    $datesUrl = $supabaseUrl
        . '/rest/v1/blocked_dates?select=date,staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=is.null';

    $timesUrl = $supabaseUrl
        . '/rest/v1/blocked_times?select=date,time,staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=is.null';

    $settingsUrl = $supabaseUrl
        . '/rest/v1/block_settings?select=block_saturdays,block_sundays,block_holidays'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $exceptionsUrl = $supabaseUrl
        . '/rest/v1/availability_exceptions?select=date,allow_booking,staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&allow_booking=eq.true'
        . '&staff_id=is.null';

    $results = front_bootstrap_multi_request([
        'dates' => $datesUrl,
        'times' => $timesUrl,
        'settings' => $settingsUrl,
        'exceptions' => $exceptionsUrl,
    ], $serviceRoleKey, $schema);

    $blockedCacheable = true;

    foreach (['dates', 'times', 'settings', 'exceptions'] as $resultKey) {
        if (empty($results[$resultKey]['ok'])) {
            $blockedCacheable = false;
            break;
        }
    }

    $blockedDates = [];
    $globalBlockedDates = [];
    $staffBlockedDates = [];
    $blockedDateScopes = [];

    $datesResult = $results['dates'] ?? ['ok' => false];

    if ($datesResult['ok']) {
        foreach (front_bootstrap_rows($datesResult) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = (string)($row['date'] ?? '');

            if ($date === '') {
                continue;
            }

            $blockedDates[] = $date;
            $globalBlockedDates[] = $date;
            $blockedDateScopes[$date] = 'global';
        }
    }

    $blockedTimes = [];
    $globalBlockedTimes = [];
    $staffBlockedTimes = [];
    $blockedTimeScopes = [];

    $timesResult = $results['times'] ?? ['ok' => false];

    if ($timesResult['ok']) {
        foreach (front_bootstrap_rows($timesResult) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = (string)($row['date'] ?? '');
            $time = substr((string)($row['time'] ?? ''), 0, 5);

            if ($date === '' || $time === '') {
                continue;
            }

            front_bootstrap_push_time($blockedTimes, $date, $time);
            front_bootstrap_push_time($globalBlockedTimes, $date, $time);

            if (!isset($blockedTimeScopes[$date])) {
                $blockedTimeScopes[$date] = [];
            }

            $blockedTimeScopes[$date][$time] = 'global';
        }
    }

    $blockedTimes = front_bootstrap_unique_time_map($blockedTimes);
    $globalBlockedTimes = front_bootstrap_unique_time_map($globalBlockedTimes);
    $staffBlockedTimes = front_bootstrap_unique_time_map($staffBlockedTimes);

    $blockSettings = [
        'block_saturdays' => false,
        'block_sundays' => false,
        'block_holidays' => false,
    ];

    $settingsResult = $results['settings'] ?? ['ok' => false];

    if ($settingsResult['ok']) {
        $row = front_bootstrap_first_row($settingsResult);

        if (!empty($row)) {
            $blockSettings = [
                'block_saturdays' => !empty($row['block_saturdays']),
                'block_sundays' => !empty($row['block_sundays']),
                'block_holidays' => !empty($row['block_holidays']),
            ];
        }
    }

    $globalAvailabilityExceptions = [];
    $staffAvailabilityExceptions = [];
    $exceptionsResult = $results['exceptions'] ?? ['ok' => false];

    if ($exceptionsResult['ok']) {
        foreach (front_bootstrap_rows($exceptionsResult) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = (string)($row['date'] ?? '');

            if ($date === '') {
                continue;
            }

            $globalAvailabilityExceptions[] = $date;
        }
    }

    return [
        '_cacheable' => $blockedCacheable,
        'success' => true,
        'blockedDates' => array_values(array_unique($blockedDates)),
        'blockedTimes' => $blockedTimes,
        'globalBlockedDates' => array_values(array_unique($globalBlockedDates)),
        'staffBlockedDates' => array_values(array_unique($staffBlockedDates)),
        'globalBlockedTimes' => $globalBlockedTimes,
        'staffBlockedTimes' => $staffBlockedTimes,
        'blockedDateScopes' => $blockedDateScopes,
        'blockedTimeScopes' => $blockedTimeScopes,
        'availabilityExceptions' => array_values(array_unique($globalAvailabilityExceptions)),
        'globalAvailabilityExceptions' => array_values(array_unique($globalAvailabilityExceptions)),
        'staffAvailabilityExceptions' => array_values(array_unique($staffAvailabilityExceptions)),
        'blockSettings' => $blockSettings,
        'minDate' => date('Y-m-d'),
        'maxDate' => date('Y-m-t'),
        'workingHours' => front_bootstrap_generate_working_hours($calendarSettings),
    ];
}

function front_bootstrap_get_blocked(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    array $calendarSettings
): array {
    $cacheKey = front_bootstrap_blocked_cache_key($tenantId);
    $blocked = front_bootstrap_read_blocked_cache($cacheKey);

    if (is_array($blocked)) {
        return $blocked;
    }

    $lockHandle = front_bootstrap_lock_blocked_cache($cacheKey);

    if ($lockHandle) {
        $blocked = front_bootstrap_read_blocked_cache($cacheKey);

        if (is_array($blocked)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return $blocked;
        }
    }

    $blocked = front_bootstrap_build_blocked($supabaseUrl, $serviceRoleKey, $schema, $tenantId, $calendarSettings);
    $blockedCacheable = ($blocked['_cacheable'] ?? true) === true;
    unset($blocked['_cacheable']);

    if (is_array($blocked) && ($blocked['success'] ?? false) === true && $blockedCacheable) {
        front_bootstrap_write_blocked_cache($cacheKey, $blocked);
    }

    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    return $blocked;
}

function front_bootstrap_build_static_bundle(string $supabaseUrl, string $serviceRoleKey, string $schema): array
{
    $tenantLookup = getTenantLookupFromHost($supabaseUrl, $serviceRoleKey, $schema);

    if (($tenantLookup['status'] ?? '') === 'not_found') {
        front_bootstrap_json([
            'success' => false,
            'error' => 'tenant_not_found',
            'message' => 'Ten adres nie jest zarejestrowany w AI-IQ Rezerwacja Pro.',
        ], 404);
    }

    if (($tenantLookup['status'] ?? '') !== 'found' || empty($tenantLookup['tenant_id'])) {
        $statusCode = (int)($tenantLookup['http_code'] ?? 503);
        $statusCode = $statusCode === 429 ? 429 : 503;

        front_bootstrap_json([
            'success' => false,
            'error' => 'temporary_unavailable',
            'message' => 'Nie udało się chwilowo potwierdzić domeny kalendarza. Spróbuj ponownie za moment.',
        ], $statusCode);
    }

    $tenantId = (string) $tenantLookup['tenant_id'];
    $planContext = plan_features_get_context($tenantId);
    $brandingBundle = front_bootstrap_build_branding($supabaseUrl, $serviceRoleKey, $schema, $tenantId, $planContext);
    $serviceBundle = front_bootstrap_build_service($supabaseUrl, $serviceRoleKey, $schema, $tenantId, $planContext);
    $publicPlanContext = $brandingBundle['plan_context'];
    $service = is_array($serviceBundle['service'] ?? null) ? $serviceBundle['service'] : [];

    $services = front_bootstrap_build_services(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $publicPlanContext,
        !empty($serviceBundle['payu_enabled'])
    );

    $hasAssignedStaffInServices = false;

    foreach (($services['services'] ?? []) as $serviceRow) {
        if (is_array($serviceRow) && !empty($serviceRow['assigned_staff']) && is_array($serviceRow['assigned_staff'])) {
            $hasAssignedStaffInServices = true;
            break;
        }
    }

    if (!empty($services['services']) && front_bootstrap_feature($publicPlanContext, 'staff_module')) {
        $staff = [
            'success' => true,
            'staff_enabled' => $hasAssignedStaffInServices,
            'staff' => [],
        ];
    } else {
        $staff = front_bootstrap_build_staff($supabaseUrl, $serviceRoleKey, $schema, $tenantId, $publicPlanContext);
    }

    $legal = front_bootstrap_build_legal(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $publicPlanContext,
        (string)($serviceBundle['company_full_name'] ?? '')
    );

    return [
        'tenant_id' => $tenantId,
        'plan_context' => $publicPlanContext,
        'branding' => $brandingBundle['branding'],
        'service' => $serviceBundle['service'],
        'services' => $services,
        'staff' => $staff,
        'legal' => $legal,
        'calendar_service' => $service,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        front_bootstrap_json([
            'success' => false,
            'error' => 'Metoda niedozwolona',
        ], 405);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $serviceRoleKey === '') {
        front_bootstrap_fail('Brak konfiguracji Supabase', 500);
    }

    $cacheKey = front_bootstrap_cache_key();
    $staticBundle = front_bootstrap_read_cache($cacheKey);

    if (!is_array($staticBundle)) {
        $cacheLockHandle = front_bootstrap_lock_cache($cacheKey);

        if ($cacheLockHandle) {
            $staticBundle = front_bootstrap_read_cache($cacheKey);
        }

        if (!is_array($staticBundle)) {
            $staticBundle = front_bootstrap_build_static_bundle($supabaseUrl, $serviceRoleKey, $schema);
            front_bootstrap_write_cache($cacheKey, $staticBundle);
        }

        if ($cacheLockHandle) {
            flock($cacheLockHandle, LOCK_UN);
            fclose($cacheLockHandle);
        }
    }

    $tenantId = (string)($staticBundle['tenant_id'] ?? '');
    $publicPlanContext = is_array($staticBundle['plan_context'] ?? null) ? $staticBundle['plan_context'] : [];
    $branding = is_array($staticBundle['branding'] ?? null) ? $staticBundle['branding'] : [];
    $servicePayload = $staticBundle['service'] ?? null;
    $services = is_array($staticBundle['services'] ?? null) ? $staticBundle['services'] : ['success' => true, 'services' => []];
    $staff = is_array($staticBundle['staff'] ?? null) ? $staticBundle['staff'] : ['success' => false, 'staff_enabled' => false, 'staff' => []];
    $legal = is_array($staticBundle['legal'] ?? null) ? $staticBundle['legal'] : ['success' => true, 'enabled' => false, 'documents' => null];
    $service = is_array($staticBundle['calendar_service'] ?? null) ? $staticBundle['calendar_service'] : [];

    if ($tenantId === '') {
        front_bootstrap_json([
            'success' => false,
            'error' => 'temporary_unavailable',
            'message' => 'Nie udało się chwilowo potwierdzić domeny kalendarza. Spróbuj ponownie za moment.',
        ], 503);
    }

    $currentPlanContext = plan_features_get_context($tenantId);
    $publicPlanContext = front_bootstrap_public_plan_context($currentPlanContext);
    $branding = front_bootstrap_filter_branding_for_plan($branding, $publicPlanContext);
    $blocked = front_bootstrap_get_blocked($supabaseUrl, $serviceRoleKey, $schema, $tenantId, $service);

    front_bootstrap_json([
        'success' => true,
        'plan_context' => $publicPlanContext,
        'branding' => $branding,
        'service' => $servicePayload,
        'services' => $services,
        'staff' => $staff,
        'legal' => $legal,
        'blocked' => $blocked,
    ]);
} catch (Throwable $e) {
    error_log('front bootstrap error: ' . $e->getMessage());

    front_bootstrap_json([
        'success' => false,
        'error' => 'Błąd ładowania danych kalendarza.',
    ], 500);
}
