<?php

declare(strict_types=1);

/**
 * AI-IQ Rezerwacja Pro — starter testu masowych rezerwacji.
 *
 * Tryb domyœlny: DRY RUN, bez tworzenia rezerwacji.
 *
 * Przyk³ad dry-run:
 * php load-booking-starter.php --host=twoja-subdomena.rezerwacja-ai-iq.pl --email-base=test@example.com
 *
 * Przyk³ad realnego testu x5:
 * php load-booking-starter.php --host=twoja-subdomena.rezerwacja-ai-iq.pl --email-base=test@example.com --count=5 --run=1
 *
 * Uwaga:
 * - przy count > 3/min endpoint mo¿e celowo zadzia³aæ rate limitem,
 * - dla testów x10/x20 IP testuj¹ce musi byæ œwiadomie dodane do BOOKING_LOAD_TEST_ALLOW_IPS,
 * - payload publiczny u¿ywa service_ref/staff_ref, nie technicznych ID.
 */

date_default_timezone_set('Europe/Warsaw');

$projectRoot = __DIR__;

$publicResponseHelper = $projectRoot . '/api/helpers/public_response.php';
if (!is_file($publicResponseHelper)) {
    fwrite(STDERR, "B£¥D: brak helpera public_response.php\n");
    exit(1);
}

require_once $publicResponseHelper;

if (
    !function_exists('public_response_ref_secret')
    || !function_exists('public_response_service_ref')
    || !function_exists('public_response_staff_ref')
) {
    fwrite(STDERR, "B£¥D: brak wymaganych funkcji publicznych refów\n");
    exit(1);
}

function cli_arg(string $name, ?string $default = null): ?string
{
    global $argv;

    $prefix = '--' . $name . '=';

    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }

    return $default;
}

function cli_bool(string $name, bool $default = false): bool
{
    $value = cli_arg($name);

    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function load_dotenv_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return trim((string) $value);
}

function normalize_test_host(string $host): string
{
    $host = trim($host);
    $host = preg_replace('#^https?://#i', '', $host) ?? $host;
    $host = preg_replace('#/.*$#', '', $host) ?? $host;
    $host = strtolower($host);

    if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
        throw new RuntimeException('Niepoprawny host testowy.');
    }

    return $host;
}

function mask_id(string $value, string $prefix): string
{
    $value = trim($value);

    if ($value === '') {
        return $prefix . ':empty';
    }

    return $prefix . ':' . substr(hash('sha256', $value), 0, 12);
}

function supabase_headers(string $key, string $schema): array
{
    return [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];
}

function http_json_get(string $url, array $headers): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $decoded = is_string($body) && $body !== ''
        ? json_decode($body, true)
        : null;

    return [
        'http_code' => $httpCode,
        'error' => $error,
        'body' => is_string($body) ? $body : '',
        'data' => is_array($decoded) ? $decoded : null,
    ];
}

function rest_select(string $baseUrl, string $table, string $query, array $headers): array
{
    $url = rtrim($baseUrl, '/') . '/rest/v1/' . $table . '?' . $query;
    $result = http_json_get($url, $headers);

    if ($result['http_code'] !== 200 || !is_array($result['data'])) {
        throw new RuntimeException(
            'B³¹d pobierania z tabeli ' . $table . ', HTTP=' . $result['http_code']
        );
    }

    return $result['data'];
}

function bool_value($value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
}

function time_to_minutes(string $time): int
{
    $time = substr($time, 0, 5);

    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        throw new RuntimeException('Niepoprawna godzina: ' . $time);
    }

    [$h, $m] = array_map('intval', explode(':', $time));

    return $h * 60 + $m;
}

function next_date_for_weekday(int $weekday, int $minDaysAhead = 1): string
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw'));

    for ($i = $minDaysAhead; $i <= 28; $i++) {
        $candidate = $today->modify('+' . $i . ' days');

        if ((int) $candidate->format('N') === $weekday) {
            return $candidate->format('Y-m-d');
        }
    }

    throw new RuntimeException('Nie znaleziono daty dla grafiku w kolejnych 28 dniach.');
}

function post_booking_parallel(string $endpoint, array $payloads): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($payloads as $index => $payload) {
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        curl_multi_add_handle($multi, $ch);
        $handles[$index] = $ch;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];

    foreach ($handles as $index => $ch) {
        $body = curl_multi_getcontent($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $json = is_string($body) && $body !== '' ? json_decode($body, true) : null;

        $results[] = [
            'index' => $index + 1,
            'http_code' => $httpCode,
            'curl_error' => $error,
            'success' => is_array($json) ? ($json['success'] ?? null) : null,
            'error' => is_array($json) ? ($json['error'] ?? null) : null,
            'message' => is_array($json) ? ($json['message'] ?? null) : null,
            'payment_required' => is_array($json) ? ($json['payment_required'] ?? null) : null,
            'postprocess_queued' => is_array($json) ? ($json['postprocess_queued'] ?? null) : null,
            'raw_short' => is_string($body) ? mb_substr($body, 0, 500) : '',
        ];

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }

    curl_multi_close($multi);

    return $results;
}

load_dotenv_file($projectRoot . '/.env');

$host = normalize_test_host((string) cli_arg('host', ''));
$emailBase = trim((string) cli_arg('email-base', ''));
$count = max(1, min(50, (int) cli_arg('count', '5')));
$run = cli_bool('run', false);
$force = cli_bool('force', false);
$preferNoPayment = cli_bool('prefer-no-payment', true);
$manualDate = trim((string) cli_arg('date', ''));
$manualTime = trim((string) cli_arg('time', ''));
$manualServiceId = trim((string) cli_arg('service-id', ''));
$manualStaffId = trim((string) cli_arg('staff-id', ''));

if ($host === '') {
    fwrite(STDERR, "B£¥D: podaj --host=subdomena\n");
    exit(1);
}

if ($emailBase === '') {
    fwrite(STDERR, "B£¥D: podaj kontrolowany adres przez --email-base=test@example.com\n");
    exit(1);
}

if (!filter_var($emailBase, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "B£¥D: --email-base nie jest poprawnym adresem e-mail\n");
    exit(1);
}

$supabaseUrl = rtrim(env_value('SUPABASE_URL'), '/');
$supabaseKey = env_value('SUPABASE_SERVICE_ROLE_KEY');
$schema = env_value('SUPABASE_DB_SCHEMA', 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    fwrite(STDERR, "B£¥D: brak SUPABASE_URL albo SUPABASE_SERVICE_ROLE_KEY\n");
    exit(1);
}

$headers = supabase_headers($supabaseKey, $schema);
$refSecret = public_response_ref_secret($supabaseKey);

$tenantRows = rest_select(
    $supabaseUrl,
    'tenant_domains',
    'select=tenant_id,domain,is_active'
        . '&domain=eq.' . rawurlencode($host)
        . '&is_active=eq.true'
        . '&limit=1',
    $headers
);

if (empty($tenantRows[0]['tenant_id'])) {
    fwrite(STDERR, "B£¥D: nie znaleziono aktywnego tenanta dla hosta\n");
    exit(1);
}

$tenantId = (string) $tenantRows[0]['tenant_id'];

$calendarRows = rest_select(
    $supabaseUrl,
    'calendar_settings',
    'select=calendar_enabled,consultation_duration,consultation_break,booking_buffer'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1',
    $headers
);

if (empty($calendarRows[0]) || !bool_value($calendarRows[0]['calendar_enabled'] ?? false)) {
    fwrite(STDERR, "B£¥D: kalendarz rezerwacji jest wy³¹czony dla tego tenanta\n");
    exit(1);
}

$serviceRows = rest_select(
    $supabaseUrl,
    'tenant_services',
    'select=id,name,duration_minutes,break_minutes,booking_buffer_minutes,price_amount,price_currency,payments_enabled,is_active,visible_on_front'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&visible_on_front=eq.true'
        . '&limit=100',
    $headers
);

if (empty($serviceRows)) {
    fwrite(STDERR, "B£¥D: brak aktywnej us³ugi widocznej na froncie\n");
    exit(1);
}

$service = null;

if ($manualServiceId !== '') {
    foreach ($serviceRows as $row) {
        if ((string) ($row['id'] ?? '') === $manualServiceId) {
            $service = $row;
            break;
        }
    }

    if (!is_array($service)) {
        fwrite(STDERR, "B£¥D: podany service-id nie jest aktywn¹ us³ug¹ frontow¹\n");
        exit(1);
    }
}

if (!is_array($service)) {
    foreach ($serviceRows as $row) {
        if ($preferNoPayment && !bool_value($row['payments_enabled'] ?? false)) {
            $service = $row;
            break;
        }
    }
}

if (!is_array($service)) {
    $service = $serviceRows[0];
}

$serviceId = (string) ($service['id'] ?? '');
if ($serviceId === '') {
    fwrite(STDERR, "B£¥D: wybrana us³uga nie ma ID\n");
    exit(1);
}

$serviceStaffRows = rest_select(
    $supabaseUrl,
    'tenant_service_staff',
    'select=staff_id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&service_id=eq.' . rawurlencode($serviceId),
    $headers
);

$staffId = '';
$staff = null;

if ($manualStaffId !== '') {
    $staffId = $manualStaffId;
} elseif (!empty($serviceStaffRows)) {
    $staffId = (string) ($serviceStaffRows[0]['staff_id'] ?? '');
}

if ($staffId !== '') {
    $staffRows = rest_select(
        $supabaseUrl,
        'staff_profiles',
        'select=id,display_name,is_active,service_duration_minutes,service_break_minutes'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&id=eq.' . rawurlencode($staffId)
            . '&is_active=eq.true'
            . '&limit=1',
        $headers
    );

    if (empty($staffRows[0]['id'])) {
        fwrite(STDERR, "B£¥D: wybrany staff_id nie jest aktywny\n");
        exit(1);
    }

    $staff = $staffRows[0];
}

$date = $manualDate;
$time = $manualTime;

if ($date === '' || $time === '') {
    if ($staffId !== '') {
        $availabilityRows = rest_select(
            $supabaseUrl,
            'staff_availability',
            'select=weekday,start_time,end_time,is_active'
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&staff_id=eq.' . rawurlencode($staffId)
                . '&is_active=eq.true',
            $headers
        );

        if (empty($availabilityRows)) {
            fwrite(STDERR, "B£¥D: wybrany pracownik nie ma aktywnego grafiku\n");
            exit(1);
        }

        $availability = $availabilityRows[0];
        $date = $date !== '' ? $date : next_date_for_weekday((int) $availability['weekday'], 1);
        $time = $time !== '' ? $time : substr((string) $availability['start_time'], 0, 5);
    } else {
        $date = $date !== '' ? $date : (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $time = $time !== '' ? $time : '10:00';
    }
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "B£¥D: data musi mieæ format YYYY-MM-DD\n");
    exit(1);
}

if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    fwrite(STDERR, "B£¥D: godzina musi mieæ format HH:MM\n");
    exit(1);
}

$existingBookings = rest_select(
    $supabaseUrl,
    'bookings',
    'select=id,staff_id,service_id,booking_date,booking_time'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&booking_date=eq.' . rawurlencode($date)
        . '&booking_time=eq.' . rawurlencode($time)
        . '&limit=20',
    $headers
);

if (!$force && !empty($existingBookings)) {
    fwrite(STDERR, "B£¥D: slot ma ju¿ rezerwacjê. Zmieñ date/time albo u¿yj --force=1 œwiadomie.\n");
    exit(1);
}

$blockedRows = rest_select(
    $supabaseUrl,
    'blocked_times',
    'select=date,time'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&date=eq.' . rawurlencode($date)
        . '&time=eq.' . rawurlencode($time)
        . '&limit=20',
    $headers
);

if (!$force && !empty($blockedRows)) {
    fwrite(STDERR, "B£¥D: slot jest w blocked_times. Zmieñ date/time albo u¿yj --force=1 œwiadomie.\n");
    exit(1);
}

$serviceRef = public_response_service_ref($tenantId, $serviceId, $refSecret);
$staffRef = $staffId !== ''
    ? public_response_staff_ref($tenantId, $staffId, $refSecret)
    : '';

$endpoint = 'https://' . $host . '/api/booking/book.php';

$payloads = [];
$startedAt = (int) round(microtime(true) * 1000) - 5000;

[$emailLocal, $emailDomain] = explode('@', $emailBase, 2);

for ($i = 1; $i <= $count; $i++) {
    $payload = [
        'date' => $date,
        'time' => $time,
        'name' => 'AI-IQ Load Test ' . date('Ymd-His') . ' #' . $i,
        'email' => $emailLocal . '+load' . date('YmdHis') . '-' . $i . '@' . $emailDomain,
        'phone' => '+48123123123',
        'note' => 'Test masowych rezerwacji AI-IQ #' . $i,
        'service_ref' => $serviceRef,
        'terms_accepted' => true,
        'website' => '',
        'form_started_at' => $startedAt,
        'form_fill_time_ms' => 5000,
    ];

    if ($staffRef !== '') {
        $payload['staff_ref'] = $staffRef;
    }

    $payloads[] = $payload;
}

echo json_encode([
    'mode' => $run ? 'RUN' : 'DRY_RUN',
    'endpoint' => $endpoint,
    'tenant' => [
        'tenant_hash' => mask_id($tenantId, 'tenant'),
        'host' => $host,
    ],
    'service' => [
        'service_hash' => mask_id($serviceId, 'service'),
        'name' => $service['name'] ?? '',
        'payments_enabled' => bool_value($service['payments_enabled'] ?? false),
        'service_ref' => $serviceRef,
    ],
    'staff' => [
        'staff_hash' => $staffId !== '' ? mask_id($staffId, 'staff') : '',
        'display_name' => is_array($staff) ? ($staff['display_name'] ?? '') : '',
        'staff_ref' => $staffRef,
    ],
    'slot' => [
        'date' => $date,
        'time' => $time,
        'existing_bookings_found' => count($existingBookings),
        'blocked_times_found' => count($blockedRows),
    ],
    'count' => $count,
    'rate_limit_note' => $count > 3
        ? 'Dla count > 3/min potrzebny jest œwiadomy allowlist IP w BOOKING_LOAD_TEST_ALLOW_IPS albo endpoint zwróci kontrolowane 429.'
        : 'Count mieœci siê w podstawowym limicie 3/min.',
    'sample_payload' => $payloads[0],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (!$run) {
    echo "\nDRY RUN: nie wys³ano rezerwacji. Dodaj --run=1, gdy dane s¹ poprawne.\n";
    exit(0);
}

$results = post_booking_parallel($endpoint, $payloads);

$summary = [
    'total' => count($results),
    'success_true' => 0,
    'http' => [],
    'errors' => [],
];

foreach ($results as $result) {
    $code = (string) $result['http_code'];
    $summary['http'][$code] = ($summary['http'][$code] ?? 0) + 1;

    if ($result['success'] === true) {
        $summary['success_true']++;
    }

    $errorKey = trim((string) ($result['error'] ?? $result['message'] ?? ''));
    if ($errorKey !== '') {
        $summary['errors'][$errorKey] = ($summary['errors'][$errorKey] ?? 0) + 1;
    }
}

echo "\nWYNIK TESTU:\n";
echo json_encode([
    'summary' => $summary,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;