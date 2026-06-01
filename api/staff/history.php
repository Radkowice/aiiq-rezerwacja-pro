<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_history_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function staff_history_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema
): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'response' => $response,
        'error' => $curlError,
        'httpCode' => $httpCode,
    ];
}

function staff_history_fail(string $message = 'Nie udało się pobrać historii pracownika.', int $statusCode = 500): void
{
    staff_history_json([
        'success' => false,
        'error' => $message,
    ], $statusCode);
}

function staff_history_fetch_rows(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $table,
    string $query
): array {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . $query;
    $result = staff_history_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '') {
        staff_history_fail();
    }

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        staff_history_fail();
    }

    $rows = json_decode((string) $result['response'], true);

    if (!is_array($rows)) {
        staff_history_fail();
    }

    return $rows;
}

function staff_history_count(array $rows): int
{
    return count(array_filter($rows, static fn($row) => is_array($row)));
}

function staff_history_is_future_booking(array $booking, string $today, string $currentTime): bool
{
    $date = trim((string) ($booking['booking_date'] ?? ''));
    $time = substr(trim((string) ($booking['booking_time'] ?? '')), 0, 5);

    if ($date === '') {
        return false;
    }

    if ($date > $today) {
        return true;
    }

    return $date === $today && $time !== '' && $time >= $currentTime;
}

function staff_history_boolean_count(array $rows, string $field): int
{
    $count = 0;

    foreach ($rows as $row) {
        if (is_array($row) && !empty($row[$field])) {
            $count++;
        }
    }

    return $count;
}

function staff_history_null_count(array $rows, string $field): int
{
    $count = 0;

    foreach ($rows as $row) {
        if (is_array($row) && empty($row[$field])) {
            $count++;
        }
    }

    return $count;
}

function staff_history_yes_no(bool $value): string
{
    return $value ? 'tak' : 'nie';
}

function staff_history_active_label(bool $value): string
{
    return $value ? 'aktywny' : 'nieaktywny';
}

function staff_history_text_value($value): string
{
    $text = trim((string) ($value ?? ''));

    return $text !== '' ? $text : '-';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    staff_history_fail('Metoda niedozwolona', 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    staff_history_fail('Brak autoryzacji', 401);
}

$role = (string) ($_SESSION['user']['role'] ?? '');

if ($role !== 'administrator') {
    staff_history_fail('Brak uprawnień', 403);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_history_fail('Brak konfiguracji Supabase', 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_history_fail('Sesja nie pasuje do domeny', 403);
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

if ($tenantId === '') {
    staff_history_fail('Nieprawidłowa sesja', 401);
}

require_tenant_feature($tenantId, 'staff_module');

$staffId = trim((string) ($_GET['id'] ?? ''));

if ($staffId === '') {
    staff_history_fail('Brak identyfikatora pracownika.', 400);
}

$staffSelect = implode(',', [
    'display_name',
    'email',
    'phone',
    'description',
    'color',
    'sort_order',
    'is_active',
    'visible_on_front',
    'created_at',
    'updated_at',
]);

$staffRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'staff_profiles',
    'select=' . rawurlencode($staffSelect)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&limit=1'
);

if (empty($staffRows[0]) || !is_array($staffRows[0])) {
    staff_history_fail('Nie znaleziono pracownika.', 404);
}

date_default_timezone_set('Europe/Warsaw');
$today = date('Y-m-d');
$currentTime = date('H:i');

$serviceRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'tenant_service_staff',
    'select=service_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$bookingRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'bookings',
    'select=booking_date,booking_time'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$futureBookingsCount = 0;
$historicalBookingsCount = 0;

foreach ($bookingRows as $booking) {
    if (!is_array($booking)) {
        continue;
    }

    if (staff_history_is_future_booking($booking, $today, $currentTime)) {
        $futureBookingsCount++;
    } else {
        $historicalBookingsCount++;
    }
}

$accountRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'staff_accounts',
    'select=is_active'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$inviteRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'staff_invites',
    'select=accepted_at,revoked_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$resetRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'staff_password_reset_tokens',
    'select=used_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$availabilityRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'staff_availability',
    'select=weekday'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$blockedDateRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'blocked_dates',
    'select=date'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$blockedTimeRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'blocked_times',
    'select=date,time'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$exceptionRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'availability_exceptions',
    'select=date,allow_booking'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$notificationRows = staff_history_fetch_rows(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    'tenant_admin_notifications',
    'select=is_read'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId)
);

$staff = $staffRows[0];
$hasStaffAccount = staff_history_count($accountRows) > 0;
$hasActiveStaffAccount = staff_history_boolean_count($accountRows, 'is_active') > 0;
$blocksCount = staff_history_count($blockedDateRows) + staff_history_count($blockedTimeRows) + staff_history_count($exceptionRows);
$openInvitesCount = max(0, staff_history_null_count($inviteRows, 'accepted_at') - staff_history_boolean_count($inviteRows, 'revoked_at'));
$unreadNotificationsCount = staff_history_null_count($notificationRows, 'is_read');

$reportLines = [
    'Historia pracownika',
    '===================',
    '',
    'Imię i nazwisko: ' . staff_history_text_value($staff['display_name'] ?? ''),
    'E-mail: ' . staff_history_text_value($staff['email'] ?? ''),
    'Telefon: ' . staff_history_text_value($staff['phone'] ?? ''),
    'Opis: ' . staff_history_text_value($staff['description'] ?? ''),
    'Status: ' . staff_history_active_label(!empty($staff['is_active'])),
    'Widoczny na froncie: ' . staff_history_yes_no(!empty($staff['visible_on_front'])),
    'Data utworzenia: ' . staff_history_text_value($staff['created_at'] ?? ''),
    'Ostatnia aktualizacja: ' . staff_history_text_value($staff['updated_at'] ?? ''),
    '',
    'Podsumowanie',
    '------------',
    'Przypisane usługi: ' . staff_history_count($serviceRows),
    'Zaplanowane rezerwacje: ' . $futureBookingsCount,
    'Historyczne rezerwacje: ' . $historicalBookingsCount,
    'Konto pracownika: ' . staff_history_yes_no($hasStaffAccount),
    'Aktywne konto pracownika: ' . staff_history_yes_no($hasActiveStaffAccount),
    'Konta pracownika: ' . staff_history_count($accountRows),
    'Zaproszenia: ' . staff_history_count($inviteRows),
    'Otwarte zaproszenia: ' . $openInvitesCount,
    'Prośby o reset hasła: ' . staff_history_count($resetRows),
    'Reguły grafiku: ' . staff_history_count($availabilityRows),
    'Blokady: ' . $blocksCount,
    'Blokady całodniowe: ' . staff_history_count($blockedDateRows),
    'Blokady godzinowe: ' . staff_history_count($blockedTimeRows),
    'Wyjątki dostępności: ' . staff_history_count($exceptionRows),
    'Powiadomienia admina: ' . staff_history_count($notificationRows),
    'Nieprzeczytane powiadomienia admina: ' . $unreadNotificationsCount,
    '',
    'Informacja',
    '----------',
    'Ten raport nie zawiera danych klientów, haseł, tokenów ani technicznych identyfikatorów.',
    '',
    'Wygenerowano: ' . date('c'),
];

$fileNameBase = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($staffRows[0]['display_name'] ?? 'pracownik'));
$fileNameBase = trim((string) $fileNameBase, '-');

if ($fileNameBase === '') {
    $fileNameBase = 'pracownik';
}

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="historia-pracownika-' . $fileNameBase . '.txt"');
echo implode("\n", $reportLines);
exit;
