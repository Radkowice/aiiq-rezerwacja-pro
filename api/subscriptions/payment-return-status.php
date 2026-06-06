<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

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

function subscription_return_payment_type_label(string $paymentType): string
{
    return match ($paymentType) {
        'subscription_renewal' => 'Przedłużenie Pro',
        'subscription_upgrade' => 'Przejście na Pro',
        default => 'Płatność Pro',
    };
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        subscription_return_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
        subscription_return_json(401, [
            'success' => false,
            'error' => 'Brak autoryzacji.',
        ]);
    }

    $paymentId = trim((string) ($_GET['payment_id'] ?? ''));

    if ($paymentId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $paymentId)) {
        subscription_return_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator płatności.',
        ]);
    }

    $tenantId = (string) $_SESSION['user']['tenant_id'];
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności abonamentu.',
        ]);
    }

    if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
        subscription_return_json(401, [
            'success' => false,
            'error' => 'Sesja nie pasuje do domeny.',
        ]);
    }

    $headers = supabaseHeaders($supabaseKey, $schema);

    $paymentUrl = $supabaseUrl
        . '/rest/v1/tenant_subscription_payments'
        . '?select=id,tenant_id,status,plan_code,billing_period,payment_type'
        . '&id=eq.' . rawurlencode($paymentId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
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

    $billingPeriod = (string) ($payment['billing_period'] ?? '');
    $status = (string) ($payment['status'] ?? '');
    $paymentType = (string) ($payment['payment_type'] ?? '');
    $clientName = is_array($branding) ? trim((string) ($branding['client_name'] ?? '')) : '';
    $companyFullName = is_array($company) ? trim((string) ($company['company_full_name'] ?? '')) : '';
    $companyOwnerName = is_array($company) ? trim((string) ($company['company_owner_name'] ?? '')) : '';
    $displayCompanyName = $companyFullName !== '' ? $companyFullName : $clientName;

    subscription_return_json(200, [
        'success' => true,
        'payment' => [
            'status' => $status,
            'plan_code' => (string) ($payment['plan_code'] ?? ''),
            'billing_period' => $billingPeriod,
            'billing_period_label' => subscription_return_period_label($billingPeriod),
            'payment_type' => $paymentType,
            'payment_type_label' => subscription_return_payment_type_label($paymentType),
            'awaiting_payu_confirmation' => in_array($status, ['', 'pending'], true),
        ],
        'company' => [
            'client_name' => $clientName,
            'company_name' => $displayCompanyName,
            'company_full_name' => $companyFullName,
            'company_owner_name' => $companyOwnerName,
            'logo_url_front' => is_array($branding) ? (string) ($branding['logo_url_front'] ?? '') : '',
            'favicon_url_front' => is_array($branding) ? (string) ($branding['favicon_url_front'] ?? '') : '',
        ],
    ]);
} catch (Throwable $e) {
    subscription_return_json(500, [
        'success' => false,
        'error' => 'Błąd pobierania statusu płatności abonamentu.',
    ]);
}
