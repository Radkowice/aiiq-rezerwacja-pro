<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Niedozwolona metoda'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    http_response_code(401);
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
    echo json_encode([
        'success' => false,
        'error' => 'Nie uda³o siê wczytaæ konfiguracji systemu.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$TENANT_ID = (string) $_SESSION['user']['tenant_id'];

if ($TENANT_ID === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawid³owa sesja'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function booking_admin_select_fields(): string
{
    return implode(',', [
        'id',
        'booking_date',
        'booking_time',
        'name',
        'email',
        'phone',
        'notes',
        'status',
        'service_id',
        'staff_id',
        'service_name_snapshot',
        'payment_required',
        'payment_status',
        'payment_amount',
        'payment_currency',
        'payment_provider',
        'payment_expires_at',
        'source',
        'created_at',
        'updated_at',
        'reschedule_count',
        'rescheduled_at',
        'google_event_id',
    ]);
}

function booking_admin_string_value(array $row, string $key): string
{
    $value = $row[$key] ?? '';

    if (is_string($value) || is_numeric($value)) {
        return trim((string) $value);
    }

    return '';
}

function booking_admin_nullable_string(array $row, string $key): ?string
{
    $value = booking_admin_string_value($row, $key);

    return $value !== '' ? $value : null;
}

function booking_admin_nullable_scalar(array $row, string $key)
{
    $value = $row[$key] ?? null;

    if (is_string($value)) {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    if (is_int($value) || is_float($value) || is_bool($value)) {
        return $value;
    }

    return null;
}

function booking_admin_bool_value(array $row, string $key): bool
{
    $value = $row[$key] ?? false;

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

function booking_admin_int_value(array $row, string $key): int
{
    $value = $row[$key] ?? 0;

    if (is_int($value)) {
        return max(0, $value);
    }

    if (is_numeric($value)) {
        return max(0, (int) $value);
    }

    return 0;
}

function booking_admin_map_booking(array $booking, array $staffDisplayNames, string $tenantId, string $refSecret): array
{
    $bookingId = booking_admin_string_value($booking, 'id');
    $serviceId = booking_admin_string_value($booking, 'service_id');
    $staffId = booking_admin_string_value($booking, 'staff_id');
    $googleEventId = booking_admin_string_value($booking, 'google_event_id');
    $staffDisplayName = $staffId !== '' && isset($staffDisplayNames[$staffId])
        ? $staffDisplayNames[$staffId]
        : null;

    return public_response_sanitize([
        'booking_ref' => $bookingId !== ''
            ? public_response_booking_ref($tenantId, $bookingId, $refSecret)
            : '',
        'service_ref' => $serviceId !== ''
            ? public_response_service_ref($tenantId, $serviceId, $refSecret)
            : null,
        'staff_ref' => $staffId !== ''
            ? public_response_staff_ref($tenantId, $staffId, $refSecret)
            : null,
        'has_staff' => $staffId !== '' || ($staffDisplayName !== null && trim((string) $staffDisplayName) !== ''),
        'booking_date' => booking_admin_string_value($booking, 'booking_date'),
        'booking_time' => booking_admin_string_value($booking, 'booking_time'),
        'name' => booking_admin_string_value($booking, 'name'),
        'email' => booking_admin_string_value($booking, 'email'),
        'phone' => booking_admin_string_value($booking, 'phone'),
        'notes' => booking_admin_nullable_string($booking, 'notes'),
        'status' => booking_admin_string_value($booking, 'status'),
        'staff_display_name' => $staffDisplayName,
        'service_name_snapshot' => booking_admin_nullable_string($booking, 'service_name_snapshot'),
        'payment_required' => booking_admin_bool_value($booking, 'payment_required'),
        'payment_status' => booking_admin_nullable_string($booking, 'payment_status'),
        'payment_amount' => booking_admin_nullable_scalar($booking, 'payment_amount'),
        'payment_currency' => booking_admin_nullable_string($booking, 'payment_currency'),
        'payment_provider' => booking_admin_nullable_string($booking, 'payment_provider'),
        'payment_expires_at' => booking_admin_nullable_string($booking, 'payment_expires_at'),
        'source' => booking_admin_nullable_string($booking, 'source'),
        'created_at' => booking_admin_nullable_string($booking, 'created_at'),
        'updated_at' => booking_admin_nullable_string($booking, 'updated_at'),
        'reschedule_count' => booking_admin_int_value($booking, 'reschedule_count'),
        'rescheduled_at' => booking_admin_nullable_string($booking, 'rescheduled_at'),
        'google_calendar_synced' => $googleEventId !== '',
    ]);
}

function booking_admin_map_calendar_booking(array $booking, array $staffDisplayNames, string $tenantId, string $refSecret): array
{
    $bookingId = booking_admin_string_value($booking, 'id');
    $serviceId = booking_admin_string_value($booking, 'service_id');
    $staffId = booking_admin_string_value($booking, 'staff_id');
    $staffDisplayName = $staffId !== '' && isset($staffDisplayNames[$staffId])
        ? $staffDisplayNames[$staffId]
        : null;

    return public_response_sanitize([
        'booking_ref' => $bookingId !== ''
            ? public_response_booking_ref($tenantId, $bookingId, $refSecret)
            : '',
        'service_ref' => $serviceId !== ''
            ? public_response_service_ref($tenantId, $serviceId, $refSecret)
            : null,
        'staff_ref' => $staffId !== ''
            ? public_response_staff_ref($tenantId, $staffId, $refSecret)
            : null,
        'has_staff' => $staffId !== '' || ($staffDisplayName !== null && trim((string) $staffDisplayName) !== ''),
        'booking_date' => booking_admin_string_value($booking, 'booking_date'),
        'booking_time' => booking_admin_string_value($booking, 'booking_time'),
        'status' => booking_admin_string_value($booking, 'status'),
        'name' => booking_admin_string_value($booking, 'name'),
        'staff_display_name' => $staffDisplayName,
        'service_name_snapshot' => booking_admin_nullable_string($booking, 'service_name_snapshot'),
        'reschedule_count' => booking_admin_int_value($booking, 'reschedule_count'),
        'rescheduled_at' => booking_admin_nullable_string($booking, 'rescheduled_at'),
    ]);
}

$allowedViews = ['upcoming', 'today', 'past', 'all'];
$view = strtolower(trim((string)($_GET['view'] ?? 'upcoming')));

if (!in_array($view, $allowedViews, true)) {
    $view = 'upcoming';
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$query = [
    'select=' . rawurlencode(booking_admin_select_fields()),
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
        'error' => 'Nie uda³o siê pobraæ rezerwacji.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string)$response, true);

if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => 'Nie uda³o siê pobraæ rezerwacji.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($data)) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

$staffIds = [];

foreach ($data as $booking) {
    if (!is_array($booking)) {
        continue;
    }

    $staffId = trim((string)($booking['staff_id'] ?? ''));

    if ($staffId !== '') {
        $staffIds[$staffId] = true;
    }
}

$staffDisplayNames = [];

if (!empty($staffIds)) {
    $encodedStaffIds = array_map('rawurlencode', array_keys($staffIds));
    $staffQuery = [
        'select=id,display_name',
        'tenant_id=eq.' . rawurlencode($TENANT_ID),
        'id=in.(' . implode(',', $encodedStaffIds) . ')',
    ];

    $staffUrl = $SUPABASE_URL . '/rest/v1/staff_profiles?' . implode('&', $staffQuery);
    $staffCh = curl_init($staffUrl);

    curl_setopt_array($staffCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $SUPABASE_KEY,
            'Authorization: Bearer ' . $SUPABASE_KEY,
            'Accept: application/json',
            'Accept-Profile: ' . $SUPABASE_DB_SCHEMA,
        ],
    ]);

    $staffResponse = curl_exec($staffCh);
    $staffError = curl_error($staffCh);
    $staffHttpCode = (int) curl_getinfo($staffCh, CURLINFO_HTTP_CODE);

    curl_close($staffCh);

    if (!$staffError && $staffHttpCode < 400) {
        $staffRows = json_decode((string)$staffResponse, true);

        if (is_array($staffRows)) {
            foreach ($staffRows as $staffRow) {
                if (!is_array($staffRow)) {
                    continue;
                }

                $staffId = trim((string)($staffRow['id'] ?? ''));
                $displayName = trim((string)($staffRow['display_name'] ?? ''));

                if ($staffId !== '' && $displayName !== '') {
                    $staffDisplayNames[$staffId] = $displayName;
                }
            }
        }
    }
}

$bookings = [];
$refSecret = public_response_ref_secret($SUPABASE_KEY);

foreach ($data as $booking) {
    if (!is_array($booking)) {
        continue;
    }

    $bookings[] = $view === 'all'
        ? booking_admin_map_calendar_booking($booking, $staffDisplayNames, $TENANT_ID, $refSecret)
        : booking_admin_map_booking($booking, $staffDisplayNames, $TENANT_ID, $refSecret);
}

echo json_encode(public_response_sanitize($bookings), JSON_UNESCAPED_UNICODE);
