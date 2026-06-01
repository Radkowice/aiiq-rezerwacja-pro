<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';

function export_history_json(array $payload, int $statusCode): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function export_history_supabase_rows(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $table,
    array $query,
    int $timeout = 30
): array {
    $url = $supabaseUrl . '/rest/v1/' . $table . '?' . implode('&', $query);
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $supabaseKey,
            'Authorization: Bearer ' . $supabaseKey,
            'Accept: application/json',
            'Accept-Profile: ' . $schema,
        ],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($error !== '') {
        return [
            'success' => false,
            'status' => 500,
            'error' => 'CURL error',
            'details' => $error,
            'rows' => [],
            'response' => null,
        ];
    }

    $rows = json_decode((string) $response, true);

    if ($httpCode >= 400 || !is_array($rows)) {
        return [
            'success' => false,
            'status' => $httpCode >= 400 ? $httpCode : 500,
            'error' => 'Nie udało się pobrać danych',
            'details' => null,
            'rows' => [],
            'response' => $rows ?: $response,
        ];
    }

    return [
        'success' => true,
        'status' => 200,
        'error' => null,
        'details' => null,
        'rows' => $rows,
        'response' => $rows,
    ];
}

function formatCsvDate($value): string
{
    $raw = trim((string) ($value ?? ''));

    if ($raw === '') {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $matches) === 1) {
        return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
    }

    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y');
    } catch (Throwable $error) {
        return '';
    }
}

function formatCsvTime($value): string
{
    $raw = trim((string) ($value ?? ''));

    if ($raw === '') {
        return '';
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $matches) === 1) {
        return str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2];
    }

    try {
        return (new DateTimeImmutable($raw))->format('H:i');
    } catch (Throwable $error) {
        return '';
    }
}

function formatCsvDateTime($value): string
{
    $raw = trim((string) ($value ?? ''));

    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y H:i');
    } catch (Throwable $error) {
        return '';
    }
}

function csvText($value): string
{
    return trim((string) ($value ?? ''));
}

function csvPaymentRequired($value): string
{
    return $value === true || $value === 'true' || $value === 1 || $value === '1' ? 'tak' : 'nie';
}


function csvRescheduleCount($value): int
{
    return max(0, (int) ($value ?? 0));
}

function csvRescheduledLabel(array $item): string
{
    return csvRescheduleCount($item['reschedule_count'] ?? 0) > 0 || csvText($item['rescheduled_at'] ?? '') !== '' ? 'tak' : 'nie';
}

function csvCurrentTerm(array $item): string
{
    $date = formatCsvDate($item['booking_date'] ?? $item['date'] ?? '');
    $time = formatCsvTime($item['booking_time'] ?? $item['time'] ?? '');

    return trim($date . ($date !== '' && $time !== '' ? ' ' : '') . $time);
}

function formatBookingStatusForCsv($value): string
{
    $status = strtolower(trim((string) ($value ?? '')));

    if ($status === '') {
        return 'Brak statusu';
    }

    $labels = [
        'confirmed' => 'Potwierdzona',
        'pending_payment' => 'Oczekuje na płatność',
        'payment_pending' => 'Oczekuje na płatność',
        'pending' => 'Oczekuje na potwierdzenie',
        'new' => 'Nowa',
        'cancelled' => 'Anulowana',
        'canceled' => 'Anulowana',
        'completed' => 'Zakończona',
        'done' => 'Zakończona',
        'no_show' => 'Klient nie przyszedł',
        'payment_overdue' => 'Płatność po terminie',
        'expired' => 'Wygasła',
        'rejected' => 'Odrzucona',
    ];

    return $labels[$status] ?? 'Nieznany';
}

function formatPaymentStatusForCsv($value): string
{
    $status = strtolower(trim((string) ($value ?? '')));

    if ($status === '') {
        return 'Nie dotyczy';
    }

    $labels = [
        'paid' => 'Opłacona',
        'completed' => 'Opłacona',
        'success' => 'Opłacona',
        'pending' => 'Oczekuje na płatność',
        'waiting' => 'Oczekuje na płatność',
        'unpaid' => 'Nieopłacona',
        'new' => 'Nieopłacona',
        'cancelled' => 'Anulowana',
        'canceled' => 'Anulowana',
        'failed' => 'Nieudana',
        'error' => 'Nieudana',
        'expired' => 'Wygasła',
        'refunded' => 'Zwrócona',
        'not_required' => 'Nie dotyczy',
        'no_payment' => 'Nie dotyczy',
    ];

    return $labels[$status] ?? 'Nieznany';
}

function csvWriteRow($output, array $row): void
{
    $buffer = fopen('php://temp', 'r+');

    if ($buffer === false) {
        return;
    }

    fputcsv($buffer, $row, ';');
    rewind($buffer);

    $line = stream_get_contents($buffer);
    fclose($buffer);

    if ($line === false) {
        return;
    }

    fwrite($output, rtrim($line, "\r\n") . "\r\n");
}

function csvServiceName(array $item): string
{
    $serviceName = csvText($item['service_name_snapshot'] ?? $item['service_name'] ?? '');

    return $serviceName !== '' ? $serviceName : 'Usługa domyślna';
}

function csvStaffName(array $item, array $staffDisplayNames): string
{
    $staffId = csvText($item['staff_id'] ?? '');
    $staffName = csvText($item['staff_display_name'] ?? '');

    if ($staffName !== '') {
        return $staffName;
    }

    if ($staffId !== '' && isset($staffDisplayNames[$staffId])) {
        return $staffDisplayNames[$staffId];
    }

    return $staffId !== '' ? 'przypisany, brak nazwy' : 'Bez przypisanego pracownika';
}

start_secure_session();

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    export_history_json([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    export_history_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA)) {
    export_history_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 401);
}

$TENANT_ID = (string) $_SESSION['user']['tenant_id'];

if ($TENANT_ID === '') {
    export_history_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

$timezone = new DateTimeZone('Europe/Warsaw');
$now = new DateTimeImmutable('now', $timezone);
$historyFrom = $now->modify('-3 months')->format('Y-m-d');

$today = $now->format('Y-m-d');
$currentTime = $now->format('H:i');

$bookingQuery = [
    'select=*',
    'tenant_id=eq.' . rawurlencode($TENANT_ID),
    'booking_date=gte.' . rawurlencode($historyFrom),
    'or=('
        . 'booking_date.lt.' . rawurlencode($today)
        . ',and(booking_date.eq.' . rawurlencode($today)
        . ',booking_time.lt.' . rawurlencode($currentTime) . ')'
        . ')',
    'order=booking_date.desc',
    'order=booking_time.desc',
];

$bookingResult = export_history_supabase_rows(
    $SUPABASE_URL,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA,
    'bookings',
    $bookingQuery
);

if (!$bookingResult['success']) {
    export_history_json([
        'success' => false,
        'error' => 'Nie udało się pobrać historii rezerwacji',
        'response' => $bookingResult['response'],
    ], (int) $bookingResult['status']);
}

$data = $bookingResult['rows'];
$staffIds = [];

foreach ($data as $booking) {
    if (!is_array($booking)) {
        continue;
    }

    $staffId = csvText($booking['staff_id'] ?? '');

    if ($staffId !== '') {
        $staffIds[$staffId] = true;
    }
}

$staffDisplayNames = [];

if (!empty($staffIds)) {
    $staffQuery = [
        'select=id,display_name',
        'tenant_id=eq.' . rawurlencode($TENANT_ID),
        'id=in.(' . implode(',', array_map('rawurlencode', array_keys($staffIds))) . ')',
    ];

    $staffResult = export_history_supabase_rows(
        $SUPABASE_URL,
        $SUPABASE_KEY,
        $SUPABASE_DB_SCHEMA,
        'staff_profiles',
        $staffQuery,
        20
    );

    if ($staffResult['success']) {
        foreach ($staffResult['rows'] as $staffRow) {
            if (!is_array($staffRow)) {
                continue;
            }

            $staffId = csvText($staffRow['id'] ?? '');
            $displayName = csvText($staffRow['display_name'] ?? '');

            if ($staffId !== '' && $displayName !== '') {
                $staffDisplayNames[$staffId] = $displayName;
            }
        }
    }
}

$fileDate = $now->format('Y-m-d_H-i');

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="historia-rezerwacji-' . $fileDate . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

if ($output === false) {
    exit;
}

echo "\xEF\xBB\xBF";

csvWriteRow($output, [
    'ID',
    'Data rezerwacji',
    'Godzina',
    'Klient',
    'E-mail',
    'Telefon',
    'Usługa',
    'Pracownik',
    'Opis',
    'Status rezerwacji',
    'Płatność wymagana',
    'Status płatności',
    'Kwota płatności',
    'Waluta',
    'Rezerwacja przeniesiona',
    'Liczba zmian terminu',
    'Ostatnia zmiana terminu',
    'Aktualny termin po zmianie',
    'Data utworzenia',
]);

foreach ($data as $item) {
    if (!is_array($item)) {
        continue;
    }

    csvWriteRow($output, [
        csvText($item['id'] ?? ''),
        formatCsvDate($item['booking_date'] ?? $item['date'] ?? ''),
        formatCsvTime($item['booking_time'] ?? $item['time'] ?? ''),
        csvText($item['name'] ?? ''),
        csvText($item['email'] ?? ''),
        csvText($item['phone'] ?? ''),
        csvServiceName($item),
        csvStaffName($item, $staffDisplayNames),
        csvText($item['notes'] ?? $item['message'] ?? $item['description'] ?? ''),
        formatBookingStatusForCsv($item['status'] ?? null),
        csvPaymentRequired($item['payment_required'] ?? null),
        formatPaymentStatusForCsv($item['payment_status'] ?? null),
        csvText($item['payment_amount'] ?? ''),
        csvText($item['payment_currency'] ?? ''),
        csvRescheduledLabel($item),
        (string) csvRescheduleCount($item['reschedule_count'] ?? 0),
        formatCsvDateTime($item['rescheduled_at'] ?? ''),
        csvCurrentTerm($item),
        formatCsvDateTime($item['created_at'] ?? ''),
    ]);
}

fclose($output);
exit;
