<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function account_info_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function account_info_request(string $method, string $url, array $headers, ?array $payload = null): array
{
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $curlError) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error' => $curlError ?: 'Błąd połączenia',
            'data' => null,
            'raw' => null,
        ];
    }

    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => null,
        'data' => is_array($decoded) ? $decoded : null,
        'raw' => $response,
    ];
}

function account_info_format_date_label(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function account_info_date_start(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }
}

function account_info_days_until(?DateTimeImmutable $date): ?int
{
    if (!$date) {
        return null;
    }

    $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw')))->setTime(0, 0, 0);
    $seconds = $date->getTimestamp() - $today->getTimestamp();

    return (int) floor($seconds / 86400);
}

function account_info_days_label(int $days): string
{
    $days = max(0, $days);

    if ($days === 1) {
        return '1 dzień';
    }

    return $days . ' dni';
}

function account_info_billing_period_label(?string $value): string
{
    return match (strtolower(trim((string) $value))) {
        'monthly' => 'Miesięczny',
        'yearly', 'annual' => 'Roczny',
        'manual' => 'Ustalany indywidualnie',
        default => '—',
    };
}

function account_info_status_label(?string $value): string
{
    return match (strtolower(trim((string) $value))) {
        'trial' => 'Okres próbny',
        'active' => 'Aktywny',
        'payment_due' => 'Do zapłaty',
        'overdue' => 'Po terminie',
        'suspended' => 'Zawieszony',
        'cancelled' => 'Anulowany',
        default => trim((string) $value) !== '' ? (string) $value : '—',
    };
}

function account_info_money_label($amount, ?string $currency): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }

    if (!is_numeric($amount)) {
        return trim((string) $amount . ' ' . (string) $currency);
    }

    $displayCurrency = strtoupper(trim((string) $currency)) === 'PLN' ? 'zł' : trim((string) $currency);

    return trim(number_format((float) $amount, 2, ',', ' ') . ' ' . $displayCurrency);
}

function account_info_subscription_notice(?array $subscription): array
{
    if (!is_array($subscription)) {
        return [
            'variant' => 'neutral',
            'title' => 'Brak danych abonamentu',
            'text' => 'Nie udało się odczytać szczegółów abonamentu.',
            'days_left' => null,
            'grace_days_left' => null,
            'current_period_start_label' => '—',
            'current_period_end_label' => '—',
            'next_payment_due_at_label' => '—',
            'amount_label' => '—',
            'billing_period_label' => '—',
            'status_label' => '—',
            'days_left_label' => '—',
            'grace_period_label' => '—',
            'days_left_row_label' => 'Pozostało do końca abonamentu',
            'grace_period_row_label' => 'Okres ochronny danych',
            'display_plan_name' => '—',
            'effective_plan_code' => 'free',
        ];
    }

    $planCode = strtolower(trim((string) ($subscription['plan_code'] ?? 'free')));
    $status = strtolower(trim((string) ($subscription['status'] ?? 'active')));
    $periodStart = account_info_date_start($subscription['current_period_start'] ?? null);
    $periodEnd = account_info_date_start($subscription['current_period_end'] ?? null);
    $nextPaymentDue = account_info_date_start($subscription['next_payment_due_at'] ?? null);
    $daysLeft = account_info_days_until($periodEnd);
    $configuredGraceDays = is_numeric($subscription['grace_period_days'] ?? null)
        ? (int) $subscription['grace_period_days']
        : 0;
    $graceDays = $configuredGraceDays > 0 ? $configuredGraceDays : 30;
    $graceBase = $periodEnd ?: $nextPaymentDue;
    $graceDaysLeft = null;

    if ($graceBase && $graceDays !== null) {
        $graceEnd = $graceBase->modify('+' . $graceDays . ' days');
        $graceDaysLeft = account_info_days_until($graceEnd);
    }

    $periodEndLabel = account_info_format_date_label($subscription['current_period_end'] ?? null);
    $graceDaysLabel = account_info_days_label($graceDays);
    $activeProText = 'Twój plan Pro jest aktywny do ' . $periodEndLabel . '. Po tym terminie, jeśli abonament nie zostanie opłacony, konto zostanie przełączone na plan Free, a funkcje Pro zostaną zablokowane. Dane i konfiguracje Pro będą przechowywane jeszcze przez ' . $graceDaysLabel . ' w okresie ochronnym. Po tym czasie mogą zostać usunięte.';
    $notice = [
        'variant' => 'neutral',
        'title' => 'Plan Free jest aktywny',
        'text' => 'Korzystasz z podstawowej wersji systemu.',
        'days_left' => $daysLeft,
        'grace_days_left' => $graceDaysLeft,
        'current_period_start_label' => account_info_format_date_label($subscription['current_period_start'] ?? null),
        'current_period_end_label' => $periodEndLabel,
        'next_payment_due_at_label' => account_info_format_date_label($subscription['next_payment_due_at'] ?? null),
        'amount_label' => account_info_money_label($subscription['amount'] ?? null, $subscription['currency'] ?? null),
        'billing_period_label' => $planCode === 'free' ? 'Nie dotyczy' : account_info_billing_period_label($subscription['billing_period'] ?? null),
        'status_label' => account_info_status_label($status),
        'days_left_label' => $daysLeft !== null ? account_info_days_label($daysLeft) : '—',
        'grace_period_label' => $graceDaysLabel,
        'days_left_row_label' => 'Pozostało do końca abonamentu',
        'grace_period_row_label' => 'Okres ochronny danych po terminie',
        'display_plan_name' => trim((string) ($subscription['plan_name'] ?? '')) !== '' ? (string) $subscription['plan_name'] : strtoupper($planCode),
        'effective_plan_code' => $planCode,
    ];

    if ($planCode === 'free') {
        return array_merge($notice, [
            'grace_period_label' => 'Nie dotyczy',
            'grace_period_row_label' => 'Okres ochronny danych',
            'display_plan_name' => 'Free',
            'effective_plan_code' => 'free',
        ]);
    }

    if ($status === 'cancelled') {
        return array_merge($notice, [
            'variant' => 'neutral',
            'title' => 'Abonament zakończony',
            'text' => 'Abonament Pro został zakończony.',
        ]);
    }

    if ($status === 'suspended') {
        return array_merge($notice, [
            'variant' => 'danger',
            'title' => 'Plan Pro jest zablokowany',
            'text' => 'Funkcje Pro są obecnie niedostępne, a konto działa w planie Free. Opłać abonament albo skontaktuj się z supportem.',
            'days_left_label' => '—',
        ]);
    }

    if ($periodEnd && $daysLeft !== null && $daysLeft < 0) {
        if ($graceDaysLeft !== null && $graceDaysLeft >= 0) {
            $safeGraceDaysLeft = max(0, $graceDaysLeft);

            return array_merge($notice, [
                'variant' => 'danger',
                'title' => 'Plan Pro wygasł — konto działa w planie Free',
                'text' => 'Twój abonament Pro zakończył się ' . $periodEndLabel . '. Funkcje Pro zostały zablokowane, a konto działa teraz w planie Free. Masz jeszcze ' . account_info_days_label($safeGraceDaysLeft) . ' okresu ochronnego na opłacenie abonamentu i odzyskanie konfiguracji Pro. Po zakończeniu okresu ochronnego dane Pro mogą zostać usunięte.',
                'status_label' => 'Free po wygaśnięciu Pro',
                'days_left_label' => '0 dni',
                'grace_period_label' => account_info_days_label($safeGraceDaysLeft),
                'grace_period_row_label' => 'Pozostało okresu ochronnego danych',
                'display_plan_name' => 'Free',
                'effective_plan_code' => 'free',
            ]);
        }

        return array_merge($notice, [
            'variant' => 'danger',
            'title' => 'Okres ochronny zakończony',
            'text' => 'Abonament Pro nie został opłacony w okresie ochronnym. Dane i konfiguracje Pro mogą zostać usunięte, a konto pozostaje w planie Free.',
            'status_label' => 'Free po zakończeniu ochrony danych',
            'days_left_label' => '0 dni',
            'grace_period_label' => 'zakończony',
            'grace_period_row_label' => 'Okres ochronny danych',
            'display_plan_name' => 'Free',
            'effective_plan_code' => 'free',
        ]);
    }

    if ($status === 'payment_due' || $status === 'overdue') {
        return array_merge($notice, [
            'variant' => 'warning',
            'title' => 'Płatność abonamentu wymaga uwagi',
            'text' => 'Plan Pro działa tylko do ' . $periodEndLabel . '. Po tym terminie, jeśli abonament nie zostanie opłacony, konto zostanie przełączone na plan Free, a funkcje Pro zostaną zablokowane. Dane i konfiguracje Pro będą przechowywane jeszcze przez ' . $graceDaysLabel . ' w okresie ochronnym.',
            'grace_period_label' => $graceDaysLabel,
            'grace_period_row_label' => 'Okres ochronny danych po terminie',
        ]);
    }

    if ($status === 'active' || $status === 'trial') {
        if ($periodEnd) {
            if ($daysLeft !== null && $daysLeft >= 0) {
                return array_merge($notice, [
                    'variant' => 'success',
                    'title' => 'Brak zaległości w płatnościach',
                    'text' => $activeProText,
                    'grace_period_label' => $graceDaysLabel,
                    'grace_period_row_label' => 'Okres ochronny danych po terminie',
                ]);
            }
        }

        return array_merge($notice, [
            'variant' => 'warning',
            'title' => 'Brak daty końca abonamentu',
            'text' => 'Nie udało się ustalić daty końca planu Pro. Sprawdź dane abonamentu albo skontaktuj się z supportem.',
        ]);
    }

    return $notice;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    account_info_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ]);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    account_info_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$userId = (string) $_SESSION['user']['id'];
$tenantId = (string) $_SESSION['user']['tenant_id'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    account_info_json(500, [
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ]);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    account_info_json(401, [
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.'
    ]);
}

$headers = supabaseHeaders($supabaseKey, $schema);
$headers[] = 'Content-Type: application/json';

$userUrl = $supabaseUrl
    . '/rest/v1/users?select=id,email,tenant_id,role,is_active'
    . '&id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$userResult = account_info_request('GET', $userUrl, $headers);

if (!$userResult['ok']) {
    account_info_json(500, [
        'success' => false,
        'error' => 'Nie udało się pobrać danych użytkownika.',
    ]);
}

$user = $userResult['data'][0] ?? null;

if (!is_array($user)) {
    account_info_json(404, [
        'success' => false,
        'error' => 'Nie znaleziono użytkownika.'
    ]);
}

$brandingUrl = $supabaseUrl
    . '/rest/v1/tenant_branding?select=tenant_id,client_name,client_number,company_id,service_title_front,logo_url_front,favicon_url_front'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$brandingResult = account_info_request('GET', $brandingUrl, $headers);

if (!$brandingResult['ok']) {
    account_info_json(500, [
        'success' => false,
        'error' => 'Nie udało się pobrać danych firmy.',
    ]);
}

$branding = $brandingResult['data'][0] ?? null;

$companyUrl = $supabaseUrl
    . '/rest/v1/tenant_service_settings?select=company_full_name,company_owner_name,company_tax_id,company_address,company_email,company_phone'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$companyResult = account_info_request('GET', $companyUrl, $headers);

if (!$companyResult['ok']) {
    account_info_json(500, [
        'success' => false,
        'error' => 'Nie udało się pobrać danych firmy z ustawień usługi.',
    ]);
}

$company = $companyResult['data'][0] ?? null;

$subscriptionUrl = $supabaseUrl
    . '/rest/v1/tenant_subscriptions?select=tenant_id,plan_code,plan_name,billing_period,status,amount,currency,current_period_start,current_period_end,next_payment_due_at,grace_period_days,suspended_at,cancelled_at,last_payment_at,last_reminder_at,reminder_count,notes'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$subscriptionResult = account_info_request('GET', $subscriptionUrl, $headers);

if (!$subscriptionResult['ok']) {
    account_info_json(500, [
        'success' => false,
        'error' => 'Nie udało się pobrać danych abonamentu.',
    ]);
}

$subscription = $subscriptionResult['data'][0] ?? null;
$planContext = plan_features_get_context($tenantId);
$subscriptionNotice = account_info_subscription_notice(is_array($subscription) ? $subscription : null);

account_info_json(200, [
    'success' => true,
    'user' => $user,
    'branding' => is_array($branding) ? $branding : null,
    'company' => is_array($company) ? $company : null,
    'subscription' => is_array($subscription) ? $subscription : null,
    'subscription_notice' => $subscriptionNotice,
    'plan_context' => $planContext,
]);
