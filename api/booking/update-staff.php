<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();
date_default_timezone_set('Europe/Warsaw');

function booking_staff_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function booking_staff_require_admin_session(): array
{
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user) || empty($user['id']) || empty($user['tenant_id'])) {
        booking_staff_json([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], 401);
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));

    if (!in_array($role, ['admin', 'administrator'], true)) {
        booking_staff_json([
            'success' => false,
            'error' => 'Brak uprawnień administratora'
        ], 403);
    }

    return $user;
}

function booking_staff_request(
    string $method,
    string $url,
    string $key,
    string $schema,
    ?array $payload = null,
    bool $minimal = false
): array {
    $headers = supabaseHeaders($key, $schema);

    if ($minimal) {
        $headers = array_values(array_filter($headers, static function (string $header): bool {
            return stripos($header, 'Prefer:') !== 0;
        }));

        $headers[] = 'Prefer: return=minimal';
    }

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $decoded = null;

    if (is_string($response) && trim($response) !== '') {
        $decoded = json_decode($response, true);
    }

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'error' => $curlError,
        'raw' => is_string($response) ? $response : '',
        'data' => $decoded,
    ];
}

function booking_staff_fetch_rows(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $table,
    array $query
): array {
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . $table . '?' . implode('&', $query);
    $result = booking_staff_request('GET', $url, $key, $schema);

    if (!$result['ok']) {
        booking_staff_json([
            'success' => false,
            'error' => 'Nie udało się pobrać danych',
            'table' => $table
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    return is_array($result['data']) ? $result['data'] : [];
}

function booking_staff_fetch_single(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $table,
    array $query
): ?array {
    $rows = booking_staff_fetch_rows($supabaseUrl, $key, $schema, $table, array_merge($query, ['limit=1']));

    if (!isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    return $rows[0];
}

function booking_staff_patch(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $table,
    array $query,
    array $payload
): ?array {
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . $table . '?' . implode('&', $query);

    $result = booking_staff_request('PATCH', $url, $key, $schema, $payload);

    if (!$result['ok']) {
        booking_staff_json([
            'success' => false,
            'error' => 'Nie udało się zapisać zmiany',
            'table' => $table,
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    if (is_array($result['data']) && isset($result['data'][0]) && is_array($result['data'][0])) {
        return $result['data'][0];
    }

    return null;
}

function booking_staff_insert(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $table,
    array $payload
): ?array {
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . $table;

    $result = booking_staff_request('POST', $url, $key, $schema, $payload);

    if (!$result['ok']) {
        booking_staff_json([
            'success' => false,
            'error' => 'Nie udało się zapisać historii zmiany',
            'table' => $table,
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    if (is_array($result['data']) && isset($result['data'][0]) && is_array($result['data'][0])) {
        return $result['data'][0];
    }

    return null;
}

function booking_staff_is_uuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

function booking_staff_text(?string $value, string $fallback = ''): string
{
    $text = trim((string) $value);

    return $text !== '' ? $text : $fallback;
}

function booking_staff_format_term(array $booking): string
{
    $date = booking_staff_text((string) ($booking['booking_date'] ?? ''));
    $time = booking_staff_text((string) ($booking['booking_time'] ?? ''));

    return trim($date . ' ' . substr($time, 0, 5));
}

function booking_staff_build_client_mail_html(array $booking, string $action, string $oldStaffName, string $newStaffName): string
{
    $clientName = booking_staff_text((string) ($booking['name'] ?? ''), 'Kliencie');
    $serviceName = booking_staff_text((string) ($booking['service_name_snapshot'] ?? ''), 'Usługa');
    $term = booking_staff_format_term($booking);

    $clientNameEsc = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
    $serviceNameEsc = htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8');
    $termEsc = htmlspecialchars($term, ENT_QUOTES, 'UTF-8');
    $oldStaffEsc = htmlspecialchars($oldStaffName !== '' ? $oldStaffName : 'Dotychczasowy specjalista', ENT_QUOTES, 'UTF-8');
    $newStaffEsc = htmlspecialchars($newStaffName, ENT_QUOTES, 'UTF-8');

    if ($action === 'change_staff') {
        $message = ''
            . '<p style="margin:0 0 14px;">Dzień dobry, <strong>' . $clientNameEsc . '</strong>.</p>'
            . '<p style="margin:0 0 14px;">Informujemy, że przy Twojej rezerwacji nastąpiła zmiana specjalisty.</p>'
            . '<div style="margin:18px 0;padding:16px;border:1px solid #e5e7eb;border-radius:14px;background:#f9fafb;">'
            . '  <p style="margin:0 0 10px;"><strong>👤 Nowy specjalista:</strong> ' . $newStaffEsc . '</p>'
            . '  <p style="margin:0 0 10px;"><strong>🔁 Poprzedni specjalista:</strong> ' . $oldStaffEsc . '</p>'
            . '  <p style="margin:0 0 10px;"><strong>🧾 Usługa:</strong> ' . $serviceNameEsc . '</p>'
            . '  <p style="margin:0;"><strong>🕒 Termin:</strong> ' . $termEsc . '</p>'
            . '</div>'
            . '<p style="margin:0 0 12px;">Termin rezerwacji oraz pozostałe dane nie zostały zmienione.</p>'
            . '<p style="margin:0;">Nie musisz nic robić — ta wiadomość ma charakter informacyjny.</p>';

        return buildSystemMailLayout(
            '🔁 Zmiana specjalisty przy rezerwacji',
            'Do Twojej rezerwacji został przypisany nowy specjalista.',
            $message,
            'Wiadomość została wysłana automatycznie przez system AI-IQ Rezerwacja Pro.'
        );
    }

    $message = ''
        . '<p style="margin:0 0 14px;">Dzień dobry, <strong>' . $clientNameEsc . '</strong>.</p>'
        . '<p style="margin:0 0 14px;">Informujemy, że specjalista przypisany do Twojej rezerwacji nie zajmie się Twoją prośbą .</p>'
        . '<div style="margin:18px 0;padding:16px;border:1px solid #e5e7eb;border-radius:14px;background:#f9fafb;">'
        . '  <p style="margin:0 0 10px;"><strong>👤 Poprzedni specjalista:</strong> ' . $oldStaffEsc . '</p>'
        . '  <p style="margin:0 0 10px;"><strong>🧾 Usługa:</strong> ' . $serviceNameEsc . '</p>'
        . '  <p style="margin:0;"><strong>🕒 Termin:</strong> ' . $termEsc . '</p>'
        . '</div>'
        . '<p style="margin:0 0 12px;">Nowy specjalista nie został jeszcze przypisany.</p>'
        . '<p style="margin:0;">Jeśli będzie to konieczne, usługodawca poinformuje Cię o dalszych szczegółach.</p>';

    return buildSystemMailLayout(
        '👤 Aktualizacja specjalisty przy rezerwacji',
        'Twój Specjalista nie zajmie się Twoją rezerwacją.',
        $message,
        'Wiadomość została wysłana automatycznie przez system AI-IQ Rezerwacja Pro.'
    );
}

function booking_staff_send_client_mail(array $booking, string $action, string $oldStaffName, string $newStaffName): bool
{
    $email = trim((string) ($booking['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = $action === 'change_staff'
        ? 'Zmiana specjalisty przy Twojej rezerwacji'
        : 'Aktualizacja specjalisty przy Twojej rezerwacji';

    $html = booking_staff_build_client_mail_html($booking, $action, $oldStaffName, $newStaffName);

    return sendSystemMail($email, $subject, $html);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    booking_staff_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

$adminUser = booking_staff_require_admin_session();

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    booking_staff_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    booking_staff_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($adminUser['tenant_id'] ?? '');
$adminUserId = (string) ($adminUser['id'] ?? '');

if ($tenantId === '') {
    booking_staff_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

$rawInput = file_get_contents('php://input');
$input = json_decode((string) $rawInput, true);

if (!is_array($input)) {
    booking_staff_json([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], 400);
}

$action = trim((string) ($input['action'] ?? ''));
$bookingId = trim((string) ($input['booking_id'] ?? ''));
$newStaffId = trim((string) ($input['staff_id'] ?? ''));

if (!in_array($action, ['change_staff', 'detach_staff'], true)) {
    booking_staff_json([
        'success' => false,
        'error' => 'Nieprawidłowa akcja'
    ], 400);
}

if ($bookingId === '' || !booking_staff_is_uuid($bookingId)) {
    booking_staff_json([
        'success' => false,
        'error' => 'Brak poprawnego ID rezerwacji'
    ], 400);
}

if ($action === 'change_staff' && ($newStaffId === '' || !booking_staff_is_uuid($newStaffId))) {
    booking_staff_json([
        'success' => false,
        'error' => 'Wybierz poprawnego pracownika'
    ], 400);
}

$booking = booking_staff_fetch_single($supabaseUrl, $supabaseKey, $schema, 'bookings', [
    'select=*',
    'tenant_id=eq.' . rawurlencode($tenantId),
    'id=eq.' . rawurlencode($bookingId),
]);

if (!$booking) {
    booking_staff_json([
        'success' => false,
        'error' => 'Nie znaleziono rezerwacji'
    ], 404);
}

$oldStaffId = trim((string) ($booking['staff_id'] ?? ''));
$oldStaff = null;
$oldStaffName = '';

if ($oldStaffId !== '' && booking_staff_is_uuid($oldStaffId)) {
    $oldStaff = booking_staff_fetch_single($supabaseUrl, $supabaseKey, $schema, 'staff_profiles', [
        'select=id,display_name,email,is_active',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=eq.' . rawurlencode($oldStaffId),
    ]);

    if (is_array($oldStaff)) {
        $oldStaffName = booking_staff_text((string) ($oldStaff['display_name'] ?? ''));
    }
}

$newStaff = null;
$newStaffName = '';

if ($action === 'change_staff') {
    if ($oldStaffId !== '' && strtolower($oldStaffId) === strtolower($newStaffId)) {
        booking_staff_json([
            'success' => false,
            'error' => 'Ten pracownik jest już przypisany do tej rezerwacji'
        ], 409);
    }

    $newStaff = booking_staff_fetch_single($supabaseUrl, $supabaseKey, $schema, 'staff_profiles', [
        'select=id,display_name,email,is_active',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=eq.' . rawurlencode($newStaffId),
    ]);

    if (!$newStaff) {
        booking_staff_json([
            'success' => false,
            'error' => 'Nie znaleziono wybranego pracownika'
        ], 404);
    }

    if (($newStaff['is_active'] ?? false) !== true) {
        booking_staff_json([
            'success' => false,
            'error' => 'Nie można przypisać nieaktywnego pracownika'
        ], 409);
    }

    $newStaffName = booking_staff_text((string) ($newStaff['display_name'] ?? ''), 'Nowy specjalista');
}

if ($action === 'detach_staff' && $oldStaffId === '') {
    booking_staff_json([
        'success' => false,
        'error' => 'Ta rezerwacja nie ma przypisanego pracownika'
    ], 409);
}

$updatePayload = [
    'staff_id' => $action === 'change_staff' ? $newStaffId : null,
    'updated_at' => gmdate('c'),
];

$updatedBooking = booking_staff_patch($supabaseUrl, $supabaseKey, $schema, 'bookings', [
    'tenant_id=eq.' . rawurlencode($tenantId),
    'id=eq.' . rawurlencode($bookingId),
], $updatePayload);

if (!$updatedBooking) {
    $updatedBooking = array_merge($booking, $updatePayload);
}

$mailSent = booking_staff_send_client_mail(
    $updatedBooking,
    $action,
    $oldStaffName,
    $newStaffName
);

$changeRow = booking_staff_insert($supabaseUrl, $supabaseKey, $schema, 'booking_staff_changes', [
    'tenant_id' => $tenantId,
    'booking_id' => $bookingId,
    'old_staff_id' => $oldStaffId !== '' ? $oldStaffId : null,
    'new_staff_id' => $action === 'change_staff' ? $newStaffId : null,
    'old_staff_name' => $oldStaffName !== '' ? $oldStaffName : null,
    'new_staff_name' => $action === 'change_staff' ? $newStaffName : null,
    'action' => $action,
    'changed_by' => 'admin',
    'changed_by_user_id' => booking_staff_is_uuid($adminUserId) ? $adminUserId : null,
    'client_email_sent_at' => $mailSent ? gmdate('c') : null,
    'staff_notified_at' => null,
    'note' => $action === 'change_staff'
        ? 'Administrator zmienił personel przypisany do rezerwacji.'
        : 'Administrator odłączył personel od rezerwacji.',
]);

booking_staff_json([
    'success' => true,
    'booking' => $updatedBooking,
    'change' => $changeRow,
    'mail_sent' => $mailSent,
    'message' => $action === 'change_staff'
        ? 'Zmieniono pracownika przypisanego do rezerwacji.'
        : 'Odłączono pracownika od rezerwacji.'
]);