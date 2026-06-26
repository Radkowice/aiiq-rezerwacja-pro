<?php
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
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

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA)) {
    json_response([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], 400);
}

$id = trim((string)($input['id'] ?? ''));
$bookingRef = trim((string)($input['booking_ref'] ?? ''));
$bookingDateHint = trim((string)($input['booking_date'] ?? ''));
$bookingTimeHint = trim((string)($input['booking_time'] ?? ''));

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

function booking_public_ref_secret(string $supabaseKey): string {
    $secret = getenv('BOOKING_PUBLIC_REF_SECRET') ?: getenv('APP_SECRET') ?: $supabaseKey;
    return (string)$secret;
}

function build_booking_public_ref(string $tenantId, string $bookingId, string $secret): string {
    return 'bk_' . substr(hash_hmac('sha256', 'booking|' . $tenantId . '|' . $bookingId, $secret), 0, 48);
}

function is_valid_internal_booking_id(string $id): bool {
    return $id !== '' && preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $id) === 1;
}

function is_valid_booking_public_ref(string $ref): bool {
    return preg_match('/^bk_[a-f0-9]{32,64}$/', $ref) === 1;
}

function is_valid_booking_date_hint(string $date): bool {
    return $date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
}

function is_valid_booking_time_hint(string $time): bool {
    return $time === '' || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) === 1;
}

function fetch_booking_by_id(string $supabaseUrl, string $key, string $schema, string $tenantId, string $id): ?array {
    $getUrl = $supabaseUrl
        . '/rest/v1/bookings?select=id,booking_date,booking_time'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($id)
        . '&limit=1';

    [$getRes, $getErr, $getCode] = supabase_request('GET', $getUrl, $key, $schema);

    if ($getErr || $getCode >= 400) {
        json_response([
            'success' => false,
            'error' => 'Błąd pobierania rezerwacji'
        ], 500);
    }

    $data = json_decode((string)$getRes, true);

    if (!is_array($data) || empty($data[0]) || !is_array($data[0])) {
        return null;
    }

    return $data[0];
}

function resolve_booking_by_public_ref(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    string $bookingRef,
    string $dateHint,
    string $timeHint
): ?array {
    if (!is_valid_booking_public_ref($bookingRef)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator rezerwacji'
        ], 400);
    }

    if (!is_valid_booking_date_hint($dateHint) || !is_valid_booking_time_hint($timeHint)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowe dane rezerwacji'
        ], 400);
    }

    $query = [
        'select=id,booking_date,booking_time',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'limit=200'
    ];

    if ($dateHint !== '') {
        $query[] = 'booking_date=eq.' . rawurlencode($dateHint);
    }

    if ($timeHint !== '') {
        $query[] = 'booking_time=eq.' . rawurlencode($timeHint);
    }

    $url = $supabaseUrl . '/rest/v1/bookings?' . implode('&', $query);

    [$response, $error, $code] = supabase_request('GET', $url, $key, $schema);

    if ($error || $code >= 400) {
        json_response([
            'success' => false,
            'error' => 'Błąd pobierania rezerwacji'
        ], 500);
    }

    $rows = json_decode((string)$response, true);

    if (!is_array($rows)) {
        return null;
    }

    $secret = booking_public_ref_secret($key);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $candidateId = trim((string)($row['id'] ?? ''));

        if ($candidateId === '') {
            continue;
        }

        $candidateRef = build_booking_public_ref($tenantId, $candidateId, $secret);

        if (hash_equals($candidateRef, $bookingRef)) {
            return $row;
        }
    }

    return null;
}

$booking = null;

if ($bookingRef !== '') {
    $booking = resolve_booking_by_public_ref(
        $SUPABASE_URL,
        $SUPABASE_KEY,
        $SCHEMA,
        (string)$TENANT_ID,
        $bookingRef,
        $bookingDateHint,
        $bookingTimeHint
    );
} elseif ($id !== '') {
    if (!is_valid_internal_booking_id($id)) {
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowe ID rezerwacji'
        ], 400);
    }

    $booking = fetch_booking_by_id($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA, (string)$TENANT_ID, $id);
} else {
    json_response([
        'success' => false,
        'error' => 'Brak identyfikatora rezerwacji'
    ], 400);
}

if (!$booking) {
    json_response([
        'success' => false,
        'error' => 'Nie znaleziono rezerwacji'
    ], 404);
}

$bookingId = trim((string)($booking['id'] ?? ''));
$bookingDate = $booking['booking_date'] ?? null;
$bookingTime = $booking['booking_time'] ?? null;

if ($bookingId === '') {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowa rezerwacja'
    ], 500);
}

$deleteBookingUrl = $SUPABASE_URL
    . '/rest/v1/bookings?tenant_id=eq.' . rawurlencode((string)$TENANT_ID)
    . '&id=eq.' . rawurlencode($bookingId);

[$delRes, $delErr, $delCode] = supabase_request('DELETE', $deleteBookingUrl, $SUPABASE_KEY, $SCHEMA);

if ($delErr || $delCode >= 400) {
    json_response([
        'success' => false,
        'error' => 'Błąd usuwania rezerwacji'
    ], 500);
}

if ($bookingDate && $bookingTime) {
    $deleteBlockUrl = $SUPABASE_URL
        . '/rest/v1/blocked_times?tenant_id=eq.' . rawurlencode((string)$TENANT_ID)
        . '&date=eq.' . rawurlencode((string)$bookingDate)
        . '&time=eq.' . rawurlencode((string)$bookingTime);

    [$blockRes, $blockErr, $blockCode] = supabase_request('DELETE', $deleteBlockUrl, $SUPABASE_KEY, $SCHEMA);

    if ($blockErr || $blockCode >= 400) {
        json_response([
            'success' => false,
            'error' => 'Rezerwacja usunięta, ale nie udało się odblokować godziny'
        ], 500);
    }
}

json_response([
    'success' => true,
    'message' => 'Rezerwacja usunięta i godzina odblokowana'
]);
