<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$TENANT_ID = (string) $_SESSION['user']['tenant_id'];

if (!$TENANT_ID) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$timezone = new DateTimeZone('Europe/Warsaw');
$now = new DateTimeImmutable('now', $timezone);

$today = $now->format('Y-m-d');
$currentTime = $now->format('H:i');

$query = [
    'select=*',
    'tenant_id=eq.' . rawurlencode($TENANT_ID),
    'or=('
        . 'booking_date.lt.' . rawurlencode($today)
        . ',and(booking_date.eq.' . rawurlencode($today)
        . ',booking_time.lt.' . rawurlencode($currentTime) . ')'
        . ')',
    'order=booking_date.desc',
    'order=booking_time.desc',
];

$url = $SUPABASE_URL . '/rest/v1/bookings?' . implode('&', $query);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'CURL error',
        'details' => $error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string) $response, true);

if ($httpCode >= 400 || !is_array($data)) {
    http_response_code($httpCode >= 400 ? $httpCode : 500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać historii rezerwacji',
        'response' => $data ?: $response
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileDate = $now->format('Y-m-d_H-i');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="historia-rezerwacji-' . $fileDate . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

if ($output === false) {
    exit;
}

/*
 * BOM dla Excela, żeby polskie znaki działały poprawnie.
 */
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [
    'ID',
    'Data rezerwacji',
    'Godzina',
    'Klient',
    'E-mail',
    'Telefon',
    'Opis',
    'Status rezerwacji',
    'Płatność wymagana',
    'Status płatności',
    'Kwota płatności',
    'Waluta',
    'Data utworzenia',
], ';');

foreach ($data as $item) {
    fputcsv($output, [
        (string)($item['id'] ?? ''),
        (string)($item['booking_date'] ?? $item['date'] ?? ''),
        (string)($item['booking_time'] ?? $item['time'] ?? ''),
        (string)($item['name'] ?? ''),
        (string)($item['email'] ?? ''),
        (string)($item['phone'] ?? ''),
        (string)($item['notes'] ?? $item['message'] ?? $item['description'] ?? ''),
        (string)($item['status'] ?? ''),
        !empty($item['payment_required']) ? 'tak' : 'nie',
        (string)($item['payment_status'] ?? ''),
        (string)($item['payment_amount'] ?? ''),
        (string)($item['payment_currency'] ?? ''),
        (string)($item['created_at'] ?? ''),
    ], ';');
}

fclose($output);
exit;
