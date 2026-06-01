<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
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
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$TENANT_ID = (string) $_SESSION['user']['tenant_id'];

if ($TENANT_ID === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawid owa sesja'
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
        // tylko dzisiejsze rezerwacje od aktualnej godziny wzwy .
        $query[] = 'booking_date=eq.' . rawurlencode($today);
        $query[] = 'booking_time=gte.' . rawurlencode($currentTime);
        $query[] = 'order=booking_time.asc';
        break;

    case 'past':
        // Historia:
        // - wszystkie dni wcze niejsze ni  dzisiaj,
        // - oraz dzisiejsze rezerwacje, kt rych godzina ju  min a.
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
        // ca a lista, sortowana po terminie.
        $query[] = 'order=booking_date.asc';
        $query[] = 'order=booking_time.asc';
        break;

    case 'upcoming':
    default:
        // Nadchodz ce:
        // tylko rezerwacje od jutra wzwy .
        // Dzisiejsze s  obs ugiwane osobnym filtrem "Dzisiejsze".
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
        'error' => 'CURL error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string)$response, true);

if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => 'Supabase error'
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

foreach ($data as &$booking) {
    if (!is_array($booking)) {
        continue;
    }

    $staffId = trim((string)($booking['staff_id'] ?? ''));
    $booking['staff_display_name'] = $staffId !== '' && isset($staffDisplayNames[$staffId])
        ? $staffDisplayNames[$staffId]
        : null;
}

unset($booking);

echo json_encode($data, JSON_UNESCAPED_UNICODE);
