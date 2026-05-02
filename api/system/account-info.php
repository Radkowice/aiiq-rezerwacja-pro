<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';

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
        'debug' => $userResult['raw'] ?? $userResult['error']
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
        'debug' => $brandingResult['raw'] ?? $brandingResult['error']
    ]);
}

$branding = $brandingResult['data'][0] ?? null;

$subscriptionUrl = $supabaseUrl
    . '/rest/v1/tenant_subscriptions?select=tenant_id,plan_code,plan_name,billing_period,status,amount,currency,current_period_start,current_period_end,next_payment_due_at,grace_period_days,suspended_at,cancelled_at,last_payment_at,last_reminder_at,reminder_count,notes'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$subscriptionResult = account_info_request('GET', $subscriptionUrl, $headers);

if (!$subscriptionResult['ok']) {
    account_info_json(500, [
        'success' => false,
        'error' => 'Nie udało się pobrać danych abonamentu.',
        'debug' => $subscriptionResult['raw'] ?? $subscriptionResult['error']
    ]);
}

$subscription = $subscriptionResult['data'][0] ?? null;

account_info_json(200, [
    'success' => true,
    'user' => $user,
    'branding' => is_array($branding) ? $branding : null,
    'subscription' => is_array($subscription) ? $subscription : null,
]);