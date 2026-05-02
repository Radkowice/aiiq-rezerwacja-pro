<?php
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user'])) {
    json_response([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$TENANT_ID = $_SESSION['user']['tenant_id'] ?? null;

if (!$TENANT_ID) {
    json_response([
        'success' => false,
        'error' => 'Brak tenant_id w sesji'
    ], 400);
}

$SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    json_response([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? null;

if (!$id) {
    json_response([
        'success' => false,
        'error' => 'Brak ID rezerwacji'
    ], 400);
}

function supabase_request(string $method, string $url, string $key, string $schema, ?array $payload = null): array {
    $ch = curl_init($url);

    $headers = [
        "apikey: $key",
        "Authorization: Bearer $key",
        "Accept: application/json",
        "Accept-Profile: $schema",
        "Content-Profile: $schema",
        "Content-Type: application/json"
    ];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [$response, $error, $code];
}

// 1. Pobranie rezerwacji
$getUrl = $SUPABASE_URL
    . "/rest/v1/bookings?select=id,booking_date,booking_time"
    . "&tenant_id=eq." . rawurlencode($TENANT_ID)
    . "&id=eq." . rawurlencode((string)$id)
    . "&limit=1";

[$getRes, $getErr, $getCode] = supabase_request('GET', $getUrl, $SUPABASE_KEY, $SCHEMA);

if ($getErr || $getCode >= 400) {
    json_response([
        'success' => false,
        'error' => 'Błąd pobierania rezerwacji',
        'debug' => $getErr ?: $getRes
    ], 500);
}

$data = json_decode($getRes, true);

if (!is_array($data) || empty($data[0])) {
    json_response([
        'success' => false,
        'error' => 'Nie znaleziono rezerwacji'
    ], 404);
}

$bookingDate = $data[0]['booking_date'] ?? null;
$bookingTime = $data[0]['booking_time'] ?? null;

// 2. Usunięcie rezerwacji
$deleteBookingUrl = $SUPABASE_URL
    . "/rest/v1/bookings?tenant_id=eq." . rawurlencode($TENANT_ID)
    . "&id=eq." . rawurlencode((string)$id);

[$delRes, $delErr, $delCode] = supabase_request('DELETE', $deleteBookingUrl, $SUPABASE_KEY, $SCHEMA);

if ($delErr || $delCode >= 400) {
    json_response([
        'success' => false,
        'error' => 'Błąd usuwania rezerwacji',
        'debug' => $delErr ?: $delRes
    ], 500);
}

// 3. Usunięcie odpowiadającej blokady godziny
if ($bookingDate && $bookingTime) {
    $deleteBlockUrl = $SUPABASE_URL
        . "/rest/v1/blocked_times?tenant_id=eq." . rawurlencode($TENANT_ID)
        . "&date=eq." . rawurlencode($bookingDate)
        . "&time=eq." . rawurlencode($bookingTime);

    [$blockRes, $blockErr, $blockCode] = supabase_request('DELETE', $deleteBlockUrl, $SUPABASE_KEY, $SCHEMA);

    if ($blockErr || $blockCode >= 400) {
        json_response([
            'success' => false,
            'error' => 'Rezerwacja usunięta, ale nie udało się odblokować godziny',
            'debug' => $blockErr ?: $blockRes
        ], 500);
    }
}

json_response([
    'success' => true,
    'message' => 'Rezerwacja usunięta i godzina odblokowana'
]);