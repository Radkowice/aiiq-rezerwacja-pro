<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function plan_prices_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function plan_prices_request(string $url, array $headers): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return [
            'ok' => false,
            'http_code' => 0,
            'error' => $curlError ?: 'Błąd połączenia',
            'data' => null,
        ];
    }

    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => null,
        'data' => is_array($decoded) ? $decoded : null,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    plan_prices_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ]);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    plan_prices_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.',
    ]);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    plan_prices_json(500, [
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ]);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    plan_prices_json(401, [
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.',
    ]);
}

$headers = supabaseHeaders($supabaseKey, $schema);

$pricesUrl = $supabaseUrl
    . '/rest/v1/subscription_plan_prices'
    . '?select=plan_code,plan_name,billing_period,amount,currency,is_active'
    . '&plan_code=eq.pro'
    . '&is_active=eq.true'
    . '&billing_period=in.(monthly,yearly)'
    . '&order=sort_order.asc,billing_period.asc';

$pricesResult = plan_prices_request($pricesUrl, $headers);

if (!$pricesResult['ok']) {
    $requiresMigration = in_array((int) $pricesResult['http_code'], [400, 404], true);

    plan_prices_json($requiresMigration ? 503 : 500, [
        'success' => false,
        'error' => $requiresMigration
            ? 'Cennik planów wymaga konfiguracji w bazie danych.'
            : 'Nie udało się pobrać cennika planu Pro.',
        'requires_migration' => $requiresMigration,
        'prices' => [],
    ]);
}

$prices = [];

foreach (($pricesResult['data'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }

    $period = (string) ($row['billing_period'] ?? '');

    if (!in_array($period, ['monthly', 'yearly'], true)) {
        continue;
    }

    $prices[] = [
        'plan_code' => (string) ($row['plan_code'] ?? 'pro'),
        'plan_name' => (string) ($row['plan_name'] ?? 'Pro'),
        'billing_period' => $period,
        'amount' => $row['amount'] ?? null,
        'currency' => (string) ($row['currency'] ?? 'PLN'),
        'is_active' => ($row['is_active'] ?? false) === true,
    ];
}

plan_prices_json(200, [
    'success' => true,
    'prices' => $prices,
]);
