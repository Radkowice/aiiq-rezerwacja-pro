<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

function export_history_json(array $payload, int $statusCode): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function export_history_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $stage = null
): void {
    $details = [
        'reason' => $reason,
    ];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    $context = [
        'action_key' => 'booking_export_history',
        'endpoint' => '/api/booking/export-history.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    if ($tenantId !== null && $tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    security_log_event($eventKey, $context);
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

function csvYesNo(bool $value): string
{
    return $value ? 'Tak' : 'Nie';
}

function export_history_is_uuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

function csvRescheduleCount($value): int
{
    return max(0, (int) ($value ?? 0));
}

function csvRescheduledLabel(array $item): string
{
    return csvYesNo(csvRescheduleCount($item['reschedule_count'] ?? 0) > 0 || csvText($item['rescheduled_at'] ?? '') !== '');
}

function csvStaffChangedLabel(array $item, array $staffChangesByBooking): string
{
    $bookingId = csvText($item['id'] ?? '');

    return csvYesNo($bookingId !== '' && !empty($staffChangesByBooking[$bookingId]));
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

    return $staffId !== '' ? 'Przypisany pracownik bez nazwy' : 'Bez przypisanego pracownika';
}

start_secure_session();

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    export_history_security_event(
        'booking_export_history_unauthorized',
        'unauthorized',
        401,
        'denied',
        'medium'
    );

    export_history_json([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    export_history_security_event(
        'booking_export_history_env_missing',
        'env_missing',
        500,
        'error',
        'high'
    );

    export_history_json([
        'success' => false,
        'error' => 'Nie udało się wczytać konfiguracji systemu.'
    ], 500);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA)) {
    export_history_security_event(
        'booking_export_history_tenant_denied',
        'tenant_mismatch',
        401,
        'denied',
        'medium',
        (string) ($_SESSION['user']['tenant_id'] ?? '')
    );

    export_history_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 401);
}

$TENANT_ID = (string) $_SESSION['user']['tenant_id'];

if ($TENANT_ID === '') {
    export_history_security_event(
        'booking_export_history_session_invalid',
        'invalid_session',
        401,
        'denied',
        'medium'
    );

    export_history_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

$timezone = new DateTimeZone('Europe/Warsaw');
$now = new DateTimeImmutable('now', $timezone);
$requestedStaffRef = csvText($_GET['staff_ref'] ?? '');
$legacyStaffId = csvText($_GET['staff_id'] ?? '');
$selectedStaffId = '';

if ($legacyStaffId !== '') {
    export_history_security_event(
        'booking_export_history_legacy_id_rejected',
        'legacy_staff_id_rejected',
        400,
        'failed',
        'medium',
        $TENANT_ID,
        'staff_filter'
    );

    export_history_json([
        'success' => false,
        'error' => 'Filtr pracownika wymaga aktualnej referencji.'
    ], 400);
}

if ($requestedStaffRef !== '') {
    if (preg_match('/^st_[a-f0-9]{32,64}$/', $requestedStaffRef) !== 1) {
        export_history_security_event(
            'booking_export_history_staff_ref_invalid',
            'staff_ref_invalid',
            400,
            'failed',
            'medium',
            $TENANT_ID,
            'staff_filter'
        );

        export_history_json([
            'success' => false,
            'error' => 'Nieprawidłowa referencja pracownika'
        ], 400);
    }

    $refSecret = public_response_ref_secret($SUPABASE_KEY);
    $staffCheckResult = export_history_supabase_rows(
        $SUPABASE_URL,
        $SUPABASE_KEY,
        $SUPABASE_DB_SCHEMA,
        'staff_profiles',
        [
            'select=id,display_name',
            'tenant_id=eq.' . rawurlencode($TENANT_ID),
            'is_active=eq.true',
            'limit=1000',
        ],
        20
    );

    if (!$staffCheckResult['success']) {
        export_history_security_event(
            'booking_export_history_staff_lookup_failed',
            'staff_lookup_failed',
            (int) $staffCheckResult['status'],
            'error',
            'medium',
            $TENANT_ID,
            'staff_filter'
        );

        export_history_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić pracownika',
        ], (int) $staffCheckResult['status']);
    }

    foreach ($staffCheckResult['rows'] as $staffRow) {
        if (!is_array($staffRow)) {
            continue;
        }

        $candidateId = csvText($staffRow['id'] ?? '');

        if ($candidateId === '' || !export_history_is_uuid($candidateId)) {
            continue;
        }

        $generatedStaffRef = public_response_staff_ref($TENANT_ID, $candidateId, $refSecret);

        if (hash_equals($generatedStaffRef, $requestedStaffRef)) {
            $selectedStaffId = $candidateId;
            break;
        }
    }

    if ($selectedStaffId === '') {
        export_history_security_event(
            'booking_export_history_staff_not_found',
            'staff_not_found',
            404,
            'failed',
            'medium',
            $TENANT_ID,
            'staff_filter'
        );

        export_history_json([
            'success' => false,
            'error' => 'Nie znaleziono pracownika w aktualnym koncie'
        ], 404);
    }
}

$bookingQuery = [
    'select=*',
    'tenant_id=eq.' . rawurlencode($TENANT_ID),
    'order=booking_date.desc',
    'order=booking_time.desc',
];

if ($selectedStaffId !== '') {
    $bookingQuery[] = 'staff_id=eq.' . rawurlencode($selectedStaffId);
}

$bookingResult = export_history_supabase_rows(
    $SUPABASE_URL,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA,
    'bookings',
    $bookingQuery
);

if (!$bookingResult['success']) {
    export_history_security_event(
        'booking_export_history_fetch_failed',
        'booking_fetch_failed',
        (int) $bookingResult['status'],
        'error',
        'medium',
        $TENANT_ID
    );

    export_history_json([
        'success' => false,
        'error' => 'Nie udało się pobrać rezerwacji',
    ], (int) $bookingResult['status']);
}

$data = $bookingResult['rows'];
$staffIds = [];
$bookingIds = [];

foreach ($data as $booking) {
    if (!is_array($booking)) {
        continue;
    }

    $bookingId = csvText($booking['id'] ?? '');
    $staffId = csvText($booking['staff_id'] ?? '');

    if ($bookingId !== '') {
        $bookingIds[$bookingId] = true;
    }

    if ($staffId !== '') {
        $staffIds[$staffId] = true;
    }
}

$staffDisplayNames = [];
$staffChangesByBooking = [];

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

if (!empty($bookingIds)) {
    $staffChangesQuery = [
        'select=booking_id',
        'tenant_id=eq.' . rawurlencode($TENANT_ID),
        'order=created_at.desc',
        'limit=10000',
    ];

    $staffChangesResult = export_history_supabase_rows(
        $SUPABASE_URL,
        $SUPABASE_KEY,
        $SUPABASE_DB_SCHEMA,
        'booking_staff_changes',
        $staffChangesQuery,
        20
    );

    if ($staffChangesResult['success']) {
        foreach ($staffChangesResult['rows'] as $changeRow) {
            if (!is_array($changeRow)) {
                continue;
            }

            $bookingId = csvText($changeRow['booking_id'] ?? '');

            if ($bookingId !== '' && isset($bookingIds[$bookingId])) {
                $staffChangesByBooking[$bookingId] = true;
            }
        }
    }
}

$fileDate = $now->format('Y-m-d_H-i');
$filePrefix = $requestedStaffRef !== '' ? 'rezerwacje-pracownika' : 'rezerwacje-i-historia';

export_history_security_event(
    'booking_export_history_success',
    'booking_export_history_success',
    200,
    'success',
    'high',
    $TENANT_ID,
    $requestedStaffRef !== '' ? 'staff_filter' : 'full_export'
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filePrefix . '-' . $fileDate . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

if ($output === false) {
    export_history_security_event(
        'booking_export_history_output_failed',
        'output_failed',
        500,
        'error',
        'medium',
        $TENANT_ID
    );

    exit;
}

echo "\xEF\xBB\xBF";

csvWriteRow($output, [
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
    'zmiana personelu',
    'zmiana rezerwacji',
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
        csvStaffChangedLabel($item, $staffChangesByBooking),
        csvRescheduledLabel($item),
        csvRescheduledLabel($item),
        (string) csvRescheduleCount($item['reschedule_count'] ?? 0),
        formatCsvDateTime($item['rescheduled_at'] ?? ''),
        csvCurrentTerm($item),
        formatCsvDateTime($item['created_at'] ?? ''),
    ]);
}

fclose($output);
exit;
