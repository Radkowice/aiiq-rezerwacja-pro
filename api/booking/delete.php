<?php
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/security.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function booking_delete_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result,
    string $severity = 'medium',
    ?string $stage = null,
    ?string $tenantId = null
): void {
    $details = [
        'reason' => $reason,
    ];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    $context = [
        'action_key' => 'booking_delete',
        'endpoint' => '/api/booking/delete.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    if ($tenantId !== null && trim($tenantId) !== '') {
        $context['tenant_id'] = $tenantId;
    }

    security_log_event($eventKey, $context);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    booking_delete_security_event('booking_delete_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    header('Allow: POST');
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    booking_delete_security_event('booking_delete_unauthorized', 'unauthorized', 401, 'denied', 'medium');
    json_response([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$TENANT_ID = $_SESSION['user']['tenant_id'] ?? null;

if (!$TENANT_ID) {
    booking_delete_security_event('booking_delete_session_missing', 'session_missing', 400, 'failed', 'medium');
    json_response([
        'success' => false,
        'error' => 'Brak wymaganych danych sesji'
    ], 400);
}

$SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    booking_delete_security_event('booking_delete_env_missing', 'env_missing', 500, 'error', 'high', 'config', (string)$TENANT_ID);
    json_response([
        'success' => false,
        'error' => 'Błąd konfiguracji systemu'
    ], 500);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA)) {
    booking_delete_security_event('booking_delete_tenant_denied', 'tenant_mismatch', 401, 'denied', 'high', 'tenant_check', (string)$TENANT_ID);
    json_response([
        'success' => false,
        'error' => 'Brak autoryzacji dla tej domeny'
    ], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    booking_delete_security_event('booking_delete_invalid_json', 'invalid_json', 400, 'failed', 'medium', 'input', (string)$TENANT_ID);
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], 400);
}

$legacyId = trim((string)($input['id'] ?? ''));
$bookingRef = trim((string)($input['booking_ref'] ?? ''));
$bookingDateHint = trim((string)($input['booking_date'] ?? ''));
$bookingTimeHint = trim((string)($input['booking_time'] ?? ''));

if ($legacyId !== '') {
    booking_delete_security_event('booking_delete_legacy_id_rejected', 'legacy_id_rejected', 400, 'failed', 'high', 'input', (string)$TENANT_ID);
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy identyfikator rezerwacji'
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

function booking_public_ref_secret(string $supabaseKey): string {
    $secret = getenv('BOOKING_PUBLIC_REF_SECRET') ?: getenv('APP_SECRET') ?: $supabaseKey;
    return (string)$secret;
}

function build_booking_public_ref(string $tenantId, string $bookingId, string $secret): string {
    return 'bk_' . substr(hash_hmac('sha256', 'booking|' . $tenantId . '|' . $bookingId, $secret), 0, 48);
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
        booking_delete_security_event('booking_delete_ref_invalid', 'invalid_booking_ref', 400, 'failed', 'medium', 'resolve', $tenantId);
        json_response([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator rezerwacji'
        ], 400);
    }

    if (!is_valid_booking_date_hint($dateHint) || !is_valid_booking_time_hint($timeHint)) {
        booking_delete_security_event('booking_delete_hint_invalid', 'invalid_booking_hint', 400, 'failed', 'medium', 'resolve', $tenantId);
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
        booking_delete_security_event('booking_delete_lookup_failed', 'booking_lookup_failed', 500, 'error', 'high', 'resolve', $tenantId);
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

if ($bookingRef === '') {
    booking_delete_security_event('booking_delete_ref_missing', 'booking_ref_missing', 400, 'failed', 'medium', 'input', (string)$TENANT_ID);
    json_response([
        'success' => false,
        'error' => 'Brak identyfikatora rezerwacji'
    ], 400);
}

$booking = resolve_booking_by_public_ref(
    $SUPABASE_URL,
    $SUPABASE_KEY,
    $SCHEMA,
    (string)$TENANT_ID,
    $bookingRef,
    $bookingDateHint,
    $bookingTimeHint
);

if (!$booking) {
    booking_delete_security_event('booking_delete_not_found', 'booking_not_found', 404, 'failed', 'medium', 'resolve', (string)$TENANT_ID);
    json_response([
        'success' => false,
        'error' => 'Nie znaleziono rezerwacji'
    ], 404);
}

$bookingId = trim((string)($booking['id'] ?? ''));
$bookingDate = $booking['booking_date'] ?? null;
$bookingTime = $booking['booking_time'] ?? null;

if ($bookingId === '') {
    booking_delete_security_event('booking_delete_invalid_booking', 'invalid_booking', 500, 'error', 'high', 'resolve', (string)$TENANT_ID);
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
    booking_delete_security_event('booking_delete_failed', 'booking_delete_failed', 500, 'error', 'high', 'delete_booking', (string)$TENANT_ID);
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
        booking_delete_security_event('booking_delete_unblock_failed', 'unblock_failed', 500, 'warning', 'medium', 'delete_block', (string)$TENANT_ID);
        json_response([
            'success' => false,
            'error' => 'Rezerwacja usunięta, ale nie udało się odblokować godziny'
        ], 500);
    }
}

booking_delete_security_event('booking_delete_success', 'booking_delete_success', 200, 'success', 'medium', 'delete_booking', (string)$TENANT_ID);

json_response([
    'success' => true,
    'message' => 'Rezerwacja usunięta i godzina odblokowana'
]);
