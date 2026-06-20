<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/branding-assets.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function subscription_return_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_return_request(string $url, array $headers): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode((string) $raw, true);

    return [
        'ok' => $raw !== false && $error === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $error ?: null,
        'data' => is_array($data) ? $data : null,
    ];
}

function subscription_return_period_label(string $period): string
{
    return match ($period) {
        'monthly' => 'miesiąc',
        'yearly' => 'rok',
        default => '—',
    };
}

function subscription_return_validity_fallback_label(string $period): string
{
    return match ($period) {
        'monthly' => '1 miesiąc od potwierdzenia płatności',
        'yearly' => '12 miesięcy od potwierdzenia płatności',
        default => 'po potwierdzeniu płatności',
    };
}

function subscription_return_payment_type_label(string $paymentType): string
{
    return match ($paymentType) {
        'subscription_renewal' => 'Przedłużenie Pro',
        'subscription_upgrade', 'subscription_initial' => 'Przejście na Pro',
        default => 'Płatność Pro',
    };
}

function subscription_return_format_date(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format('d.m.Y');
    } catch (Throwable $e) {
        return '';
    }
}

function subscription_return_normalize_domain(?string $rawDomain): string
{
    $domain = strtolower(trim((string) $rawDomain));

    if ($domain === '' || preg_match('/[\x00-\x1F\x7F]/', $domain)) {
        return '';
    }

    $domain = preg_replace('#^https?://#i', '', $domain);

    if (!is_string($domain) || $domain === '') {
        return '';
    }

    $domain = preg_replace('/:\d+$/', '', $domain);

    if (!is_string($domain)) {
        return '';
    }

    $domain = rtrim($domain, '.');

    if (
        $domain === ''
        || strlen($domain) > 253
        || preg_match('/[\/\\:?#\s]/', $domain)
        || !preg_match('/^[a-z0-9.-]+$/', $domain)
    ) {
        return '';
    }

    foreach (explode('.', $domain) as $label) {
        if (
            $label === ''
            || strlen($label) > 63
            || str_starts_with($label, '-')
            || str_ends_with($label, '-')
        ) {
            return '';
        }
    }

    return $domain;
}

function subscription_return_build_url(string $domain, string $path): string
{
    $domain = subscription_return_normalize_domain($domain);

    if ($domain === '') {
        return '';
    }

    $path = '/' . ltrim($path, '/');
    return 'https://' . $domain . $path;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        subscription_return_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    $paymentId = trim((string) ($_GET['payment_id'] ?? ''));

    if ($paymentId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $paymentId)) {
        subscription_return_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator płatności.',
        ]);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności abonamentu.',
        ]);
    }

    $headers = supabaseHeaders($supabaseKey, $schema);

    $paymentUrl = $supabaseUrl
        . '/rest/v1/tenant_subscription_payments'
        . '?select=id,tenant_id,status,plan_code,billing_period,payment_type,subscription_period_start,subscription_period_end,paid_at'
        . '&id=eq.' . rawurlencode($paymentId)
        . '&limit=1';

    $paymentResult = subscription_return_request($paymentUrl, $headers);

    if (!$paymentResult['ok']) {
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności abonamentu.',
        ]);
    }

    $payment = $paymentResult['data'][0] ?? null;

    if (!is_array($payment)) {
        subscription_return_json(404, [
            'success' => false,
            'error' => 'Nie znaleziono płatności abonamentu.',
        ]);
    }

    $tenantId = trim((string) ($payment['tenant_id'] ?? ''));

    if ($tenantId === '' || strlen($tenantId) > 128) {
        subscription_return_json(404, [
            'success' => false,
            'error' => 'Nie znaleziono danych klienta dla tej płatności.',
        ]);
    }

    $brandingUrl = $supabaseUrl
        . '/rest/v1/tenant_branding'
        . '?select=client_name,logo_url_front,favicon_url_front'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $brandingResult = subscription_return_request($brandingUrl, $headers);
    $branding = $brandingResult['ok'] ? ($brandingResult['data'][0] ?? []) : [];

    $companyUrl = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?select=company_full_name,company_owner_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $companyResult = subscription_return_request($companyUrl, $headers);
    $company = $companyResult['ok'] ? ($companyResult['data'][0] ?? []) : [];

    $domainUrl = $supabaseUrl
        . '/rest/v1/tenant_domains'
        . '?select=domain,is_active,is_primary'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&order=is_primary.desc'
        . '&limit=1';

    $domainResult = subscription_return_request($domainUrl, $headers);
    $domainRow = $domainResult['ok'] ? ($domainResult['data'][0] ?? []) : [];
    $tenantDomain = is_array($domainRow)
        ? subscription_return_normalize_domain((string) ($domainRow['domain'] ?? ''))
        : '';

    $subscriptionUrl = $supabaseUrl
        . '/rest/v1/tenant_subscriptions'
        . '?select=status,plan_code,billing_period,current_period_start,current_period_end'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $subscriptionResult = subscription_return_request($subscriptionUrl, $headers);
    $subscription = $subscriptionResult['ok'] ? ($subscriptionResult['data'][0] ?? []) : [];

    $billingPeriod = (string) ($payment['billing_period'] ?? ($subscription['billing_period'] ?? ''));
    $status = (string) ($payment['status'] ?? '');
    $paymentType = (string) ($payment['payment_type'] ?? '');
    $clientName = is_array($branding) ? trim((string) ($branding['client_name'] ?? '')) : '';
    $companyFullName = is_array($company) ? trim((string) ($company['company_full_name'] ?? '')) : '';
    $companyOwnerName = is_array($company) ? trim((string) ($company['company_owner_name'] ?? '')) : '';
    $displayCompanyName = $companyFullName !== '' ? $companyFullName : $clientName;

    $paymentPeriodEnd = subscription_return_format_date((string) ($payment['subscription_period_end'] ?? ''));
    $subscriptionPeriodEnd = is_array($subscription)
        ? subscription_return_format_date((string) ($subscription['current_period_end'] ?? ''))
        : '';
    $validUntilLabel = $paymentPeriodEnd !== ''
        ? $paymentPeriodEnd
        : ($subscriptionPeriodEnd !== '' ? $subscriptionPeriodEnd : subscription_return_validity_fallback_label($billingPeriod));

    $loginUrl = subscription_return_build_url($tenantDomain, '/logowanie.html');
    $panelUrl = subscription_return_build_url($tenantDomain, '/panel-admina.php');
    $publicLogoPath = branding_asset_public_url(
        is_array($branding) ? (string)($branding['logo_url_front'] ?? '') : '',
        $tenantId,
        'logo'
    );
    $publicFaviconPath = branding_asset_public_url(
        is_array($branding) ? (string)($branding['favicon_url_front'] ?? '') : '',
        $tenantId,
        'favicon'
    );
    $publicLogoUrl = $publicLogoPath !== ''
        ? subscription_return_build_url($tenantDomain, $publicLogoPath)
        : '';
    $publicFaviconUrl = $publicFaviconPath !== ''
        ? subscription_return_build_url($tenantDomain, $publicFaviconPath)
        : '';

    subscription_return_json(200, [
        'success' => true,
        'payment' => [
            'status' => $status,
            'plan_code' => (string) ($payment['plan_code'] ?? ''),
            'billing_period' => $billingPeriod,
            'billing_period_label' => subscription_return_period_label($billingPeriod),
            'subscription_valid_until' => (string) ($payment['subscription_period_end'] ?? ($subscription['current_period_end'] ?? '')),
            'subscription_valid_until_label' => $validUntilLabel,
            'payment_type' => $paymentType,
            'payment_type_label' => subscription_return_payment_type_label($paymentType),
            'awaiting_payu_confirmation' => in_array($status, ['', 'pending'], true),
        ],
        'company' => [
            'client_name' => $clientName,
            'company_name' => $displayCompanyName,
            'company_full_name' => $companyFullName,
            'company_owner_name' => $companyOwnerName,
            'logo_url_front' => $publicLogoUrl,
            'favicon_url_front' => $publicFaviconUrl,
        ],
        'urls' => [
            'tenant_domain' => $tenantDomain,
            'login_url' => $loginUrl,
            'panel_url' => $panelUrl,
            'primary_url' => $loginUrl !== '' ? $loginUrl : $panelUrl,
        ],
    ]);
} catch (Throwable $e) {
    subscription_return_json(500, [
        'success' => false,
        'error' => 'Błąd pobierania statusu płatności abonamentu.',
    ]);
}
