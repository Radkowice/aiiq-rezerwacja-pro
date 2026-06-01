<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/booking_mail.php';

function cron_booking_reminders_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cron_booking_reminders_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string)$value);

    return $value !== '' ? $value : $default;
}

function cron_booking_reminders_config(): array
{
    $supabaseUrl = rtrim(cron_booking_reminders_env('SUPABASE_URL'), '/');
    $supabaseKey = cron_booking_reminders_env('SUPABASE_SERVICE_ROLE_KEY');
    $schema = cron_booking_reminders_env('SUPABASE_DB_SCHEMA', 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        throw new RuntimeException('Brak konfiguracji Supabase.');
    }

    return [$supabaseUrl, $supabaseKey, $schema];
}

function cron_booking_reminders_headers(string $key, string $schema, bool $minimal = false): array
{
    return [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
        'Prefer: ' . ($minimal ? 'return=minimal' : 'return=representation'),
    ];
}

function cron_booking_reminders_request(string $method, string $url, ?array $payload = null, bool $minimal = false): array
{
    [, $supabaseKey, $schema] = cron_booking_reminders_config();

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => cron_booking_reminders_headers($supabaseKey, $schema, $minimal),
        CURLOPT_TIMEOUT => 25,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = null;

    if (is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
        $data = is_array($decoded) ? $decoded : null;
    }

    return [
        'response' => $response,
        'data' => $data,
        'error' => $error,
        'http_code' => $httpCode,
    ];
}

function cron_booking_reminders_fetch_records(string $type, DateTimeImmutable $now): array
{
    [$supabaseUrl] = cron_booking_reminders_config();

    $isDayBefore = $type === 'day_before';
    $targetDate = $isDayBefore
        ? $now->modify('+1 day')->format('Y-m-d')
        : $now->format('Y-m-d');
    $sentColumn = $isDayBefore
        ? 'reminder_day_before_sent_at'
        : 'reminder_same_day_sent_at';

    $query = [
        'select=id,tenant_id,booking_date,booking_time,name,email,service_name_snapshot,staff_id,status,payment_required,payment_status,' . $sentColumn,
        'booking_date=eq.' . rawurlencode($targetDate),
        $sentColumn . '=is.null',
        'status=not.in.(cancelled,canceled,deleted,payment_overdue)',
        'or=(payment_required.eq.false,payment_status.eq.not_required,payment_status.eq.paid,status.eq.confirmed)',
        'order=booking_time.asc',
        'limit=50',
    ];

    if (!$isDayBefore) {
        $query[] = 'booking_time=gt.' . rawurlencode($now->format('H:i'));
    }

    $url = $supabaseUrl . '/rest/v1/bookings?' . implode('&', $query);
    $result = cron_booking_reminders_request('GET', $url);

    if ($result['error'] || $result['http_code'] !== 200 || !is_array($result['data'])) {
        throw new RuntimeException('Nie udało się pobrać rezerwacji do przypomnień.');
    }

    return $result['data'];
}

function cron_booking_reminders_fetch_single(string $table, string $query): ?array
{
    [$supabaseUrl] = cron_booking_reminders_config();

    $url = $supabaseUrl
        . '/rest/v1/' . rawurlencode($table)
        . '?' . $query
        . '&limit=1';

    $result = cron_booking_reminders_request('GET', $url);

    if ($result['error'] || $result['http_code'] !== 200 || !is_array($result['data'])) {
        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function cron_booking_reminders_fetch_staff_display_name(string $tenantId, string $staffId): string
{
    if ($tenantId === '' || $staffId === '') {
        return '';
    }

    $staff = cron_booking_reminders_fetch_single(
        'staff_profiles',
        'select=id,display_name'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&id=eq.' . rawurlencode($staffId)
    );

    return trim((string)($staff['display_name'] ?? ''));
}

function cron_booking_reminders_tenant_mail_config(string $tenantId, array &$cache): ?array
{
    if ($tenantId === '') {
        return null;
    }

    if (array_key_exists($tenantId, $cache)) {
        return $cache[$tenantId];
    }

    $tenantQuery = 'tenant_id=eq.' . rawurlencode($tenantId);
    $emailSettings = cron_booking_reminders_fetch_single(
        'email_settings',
        $tenantQuery . '&is_active=eq.true'
    );
    $tenantData = cron_booking_reminders_fetch_single(
        'tenant_branding',
        $tenantQuery
    );

    $cache[$tenantId] = ($emailSettings && $tenantData)
        ? [
            'email_settings' => $emailSettings,
            'tenant_data' => $tenantData,
        ]
        : null;

    return $cache[$tenantId];
}

function cron_booking_reminders_update_sent_at(string $bookingId, string $tenantId, string $column, string $sentAt): bool
{
    [$supabaseUrl] = cron_booking_reminders_config();

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?id=eq.' . rawurlencode($bookingId)
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = cron_booking_reminders_request(
        'PATCH',
        $url,
        [
            $column => $sentAt,
            'updated_at' => $sentAt,
        ],
        true
    );

    return !$result['error'] && $result['http_code'] >= 200 && $result['http_code'] < 300;
}

function cron_booking_reminders_valid_email(array $booking): bool
{
    $email = trim((string)($booking['email'] ?? ''));

    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function cron_booking_reminders_process(string $type, DateTimeImmutable $now): array
{
    $isDayBefore = $type === 'day_before';
    $threshold = $isDayBefore ? '12:00' : '07:00';
    $sentColumn = $isDayBefore
        ? 'reminder_day_before_sent_at'
        : 'reminder_same_day_sent_at';

    $result = [
        'type' => $type,
        'due' => $now->format('H:i') >= $threshold,
        'found' => 0,
        'sent' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    if (!$result['due']) {
        return $result;
    }

    $records = cron_booking_reminders_fetch_records($type, $now);
    $result['found'] = count($records);
    $tenantConfigCache = [];
    $staffNameCache = [];
    $sentAt = $now->format(DateTimeInterface::ATOM);

    foreach ($records as $booking) {
        if (!is_array($booking)) {
            $result['skipped']++;
            continue;
        }

        $bookingId = trim((string)($booking['id'] ?? ''));
        $tenantId = trim((string)($booking['tenant_id'] ?? ''));

        if ($bookingId === '' || $tenantId === '' || !cron_booking_reminders_valid_email($booking)) {
            $result['skipped']++;
            continue;
        }

        $mailConfig = cron_booking_reminders_tenant_mail_config($tenantId, $tenantConfigCache);

        if (!$mailConfig) {
            $result['skipped']++;
            continue;
        }

        $staffId = trim((string)($booking['staff_id'] ?? ''));

        if ($staffId !== '') {
            $staffCacheKey = $tenantId . ':' . $staffId;

            if (!array_key_exists($staffCacheKey, $staffNameCache)) {
                $staffNameCache[$staffCacheKey] = cron_booking_reminders_fetch_staff_display_name($tenantId, $staffId);
            }

            if ($staffNameCache[$staffCacheKey] !== '') {
                $booking['staff_display_name'] = $staffNameCache[$staffCacheKey];
            }
        }

        try {
            $mailSent = booking_mail_send_booking_reminder(
                $mailConfig['email_settings'],
                $mailConfig['tenant_data'],
                $booking,
                $type
            );
        } catch (Throwable $e) {
            $mailSent = false;
        }

        if (!$mailSent) {
            $result['failed']++;
            continue;
        }

        if (cron_booking_reminders_update_sent_at($bookingId, $tenantId, $sentColumn, $sentAt)) {
            $result['sent']++;
        } else {
            $result['failed']++;
        }
    }

    return $result;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        cron_booking_reminders_response([
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ], 405);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
    $dayBefore = cron_booking_reminders_process('day_before', $now);
    $sameDay = cron_booking_reminders_process('same_day', $now);

    cron_booking_reminders_response([
        'success' => true,
        'timezone' => 'Europe/Warsaw',
        'now' => $now->format(DateTimeInterface::ATOM),
        'day_before' => $dayBefore,
        'same_day' => $sameDay,
    ]);
} catch (Throwable $e) {
    cron_booking_reminders_response([
        'success' => false,
        'error' => 'Błąd obsługi przypomnień o rezerwacjach.',
    ], 500);
}
