<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../system/tenant.php';

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$TENANT_ID = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA);

if (!$TENANT_ID) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'BOOKINGS.PHP BRAK TENANT'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedViews = ['upcoming', 'today', 'past', 'all'];
$view = strtolower(trim((string)($_GET['view'] ?? 'upcoming')));

if (!in_array($view, $allowedViews, true)) {
    $view = 'upcoming';
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$query = [
    'select=*',
    'tenant_id=eq.' . rawurlencode($TENANT_ID),
];

$timezone = new DateTimeZone('Europe/Warsaw');
$now = new DateTimeImmutable('now', $timezone);
$currentTime = $now->modify('-15 minutes')->format('H:i');

switch ($view) {
    case 'today':
        // Dzisiejsze:
        // tylko dzisiejsze rezerwacje od aktualnej godziny wzwy¿.
        $query[] = 'booking_date=eq.' . rawurlencode($today);
        $query[] = 'booking_time=gte.' . rawurlencode($currentTime);
        $query[] = 'order=booking_time.asc';
        break;

    case 'past':
        // Historia:
        // - wszystkie dni wczeœniejsze ni¿ dzisiaj,
        // - oraz dzisiejsze rezerwacje, których godzina ju¿ minê³a.
        $query[] = 'or=('
            . 'booking_date.lt.' . rawurlencode($today)
            . ',and(booking_date.eq.' . rawurlencode($today)
            . ',booking_time.lt.' . rawurlencode($currentTime) . ')'
            . ')';
        $query[] = 'order=booking_date.desc';
        $query[] = 'order=booking_time.desc';
        break;

    case 'all':
        // Wszystkie:
        // ca³a lista, sortowana po terminie.
        $query[] = 'order=booking_date.asc';
        $query[] = 'order=booking_time.asc';
        break;

    case 'upcoming':
    default:
        // Nadchodz¹ce:
        // tylko rezerwacje od jutra wzwy¿.
        // Dzisiejsze s¹ obs³ugiwane osobnym filtrem "Dzisiejsze".
        $query[] = 'booking_date=gt.' . rawurlencode($today);
        $query[] = 'order=booking_date.asc';
        $query[] = 'order=booking_time.asc';
        break;
}

$url = $SUPABASE_URL . '/rest/v1/bookings?' . implode('&', $query);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $SUPABASE_KEY,
        'Authorization: Bearer ' . $SUPABASE_KEY,
        'Accept: application/json',
        'Accept-Profile: ' . $SUPABASE_DB_SCHEMA,
    ],
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'CURL error',
        'details' => $error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string)$response, true);

if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => 'Supabase error',
        'httpCode' => $httpCode,
        'schema' => $SUPABASE_DB_SCHEMA,
        'view' => $view,
        'response' => $data ?: $response
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($data)) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);