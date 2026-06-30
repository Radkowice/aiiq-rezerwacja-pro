<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/booking_mail.php';
require_once __DIR__ . '/../helpers/google_calendar.php';
require_once __DIR__ . '/../system/tenant.php';

date_default_timezone_set('Europe/Warsaw');

function reschedule_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reschedule_error(string $message, string $code = 'reschedule_error', int $statusCode = 400): void
{
    reschedule_json([
        'success' => false,
        'can_reschedule' => false,
        'message' => $message,
        'code' => $code,
    ], $statusCode);
}

function reschedule_trace_hash(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    return '[hash:' . substr(hash('sha256', $value), 0, 12) . ']';
}

function reschedule_headers(string $key, string $schema, bool $minimal = false): array
{
    $headers = supabaseHeaders($key, $schema);
    $headers[] = $minimal ? 'Prefer: return=minimal' : 'Prefer: return=representation';
    return $headers;
}

function reschedule_request(string $method, string $url, string $key, string $schema, ?array $payload = null, bool $minimal = false): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => reschedule_headers($key, $schema, $minimal),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'response' => $response,
        'error' => $error,
        'httpCode' => $httpCode,
        'data' => json_decode((string) $response, true),
    ];
}

function reschedule_fetch_rows(string $supabaseUrl, string $key, string $schema, string $table, array $query): ?array
{
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . $table . '?' . implode('&', $query);
    $result = reschedule_request('GET', $url, $key, $schema);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        return null;
    }

    return is_array($result['data'] ?? null) ? $result['data'] : [];
}

function reschedule_fetch_single(string $supabaseUrl, string $key, string $schema, string $table, array $query): ?array
{
    $rows = reschedule_fetch_rows($supabaseUrl, $key, $schema, $table, array_merge($query, ['limit=1']));
    return is_array($rows[0] ?? null) ? $rows[0] : null;
}

function reschedule_read_json_input(): array
{
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($input) ? $input : [];
}

function reschedule_validate_token(string $token): void
{
    if ($token === '') {
        reschedule_error('Brak linku do przełożenia rezerwacji.', 'missing_token', 400);
    }

    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        reschedule_error('Link do przełożenia rezerwacji jest nieprawidłowy.', 'invalid_token', 400);
    }
}

function reschedule_validate_date(string $date): void
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Warsaw'));

    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
        reschedule_error('Wybierz poprawną datę.', 'invalid_date', 400);
    }
}

function reschedule_validate_time(string $time): void
{
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        reschedule_error('Wybierz poprawną godzinę.', 'invalid_time', 400);
    }
}

function reschedule_max_changes(): int
{
    return 3;
}

function reschedule_limit_message(): string
{
    return 'Nie możesz już samodzielnie zmienić terminu tej rezerwacji. Skontaktuj się z obsługą.';
}

function reschedule_count_value(array $booking): int
{
    return max(0, (int) ($booking['reschedule_count'] ?? 0));
}

function reschedule_limit_reached(array $booking): bool
{
    return reschedule_count_value($booking) >= reschedule_max_changes();
}

function reschedule_payment_status_label($value): string
{
    $status = strtolower(trim((string) ($value ?? '')));

    return match ($status) {
        'paid', 'completed', 'success' => 'Opłacona',
        'pending', 'waiting' => 'Oczekuje na płatność',
        'unpaid', 'new' => 'Nieopłacona',
        'cancelled', 'canceled' => 'Anulowana',
        'failed', 'error' => 'Nieudana',
        'expired' => 'Wygasła',
        'refunded' => 'Zwrócona',
        '', 'not_required', 'no_payment' => 'Nie dotyczy',
        default => 'Nieznany',
    };
}

function reschedule_format_date_label(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Warsaw'));
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date ? $parsed->format('d.m.Y') : '';
}

function reschedule_normalize_time(string $time): string
{
    $time = substr(trim($time), 0, 5);
    return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '';
}

function reschedule_is_same_booking_slot(array $booking, string $date, string $time): bool
{
    $oldDate = trim((string) ($booking['booking_date'] ?? ''));
    $oldTime = reschedule_normalize_time((string) ($booking['booking_time'] ?? ''));
    $newDate = trim($date);
    $newTime = reschedule_normalize_time($time);

    return $oldDate !== ''
        && $oldTime !== ''
        && $newDate !== ''
        && $newTime !== ''
        && $oldDate === $newDate
        && $oldTime === $newTime;
}

function reschedule_booking_start(string $date, string $time): ?DateTimeImmutable
{
    $time = reschedule_normalize_time($time);

    if ($date === '' || $time === '') {
        return null;
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone('Europe/Warsaw'));
    return $start instanceof DateTimeImmutable ? $start : null;
}

function reschedule_start_atom(string $date, string $time): string
{
    $start = reschedule_booking_start($date, $time);
    return $start instanceof DateTimeImmutable ? $start->format(DATE_ATOM) : '';
}

function reschedule_timestamp_is_future(string $value): bool
{
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    try {
        return new DateTimeImmutable($value) > new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
    } catch (Throwable $e) {
        return false;
    }
}

function reschedule_float_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float) $value : null;
}

function reschedule_int_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (int) $value : null;
}


function reschedule_service_public_ref(string $tenantId, string $serviceId, string $refSecret): string
{
    $serviceId = trim($serviceId);

    if ($serviceId === '') {
        return '';
    }

    return public_response_service_ref($tenantId, $serviceId, $refSecret);
}

function reschedule_staff_public_ref(string $tenantId, string $staffId, string $refSecret): string
{
    $staffId = trim($staffId);

    if ($staffId === '') {
        return '';
    }

    return public_response_staff_ref($tenantId, $staffId, $refSecret);
}

function reschedule_public_service_payload(array $service, array $booking, string $tenantId, string $refSecret): array
{
    $serviceId = trim((string) ($service['id'] ?? $booking['service_id'] ?? ''));
    $serviceRef = reschedule_service_public_ref($tenantId, $serviceId, $refSecret);

    return [
        'service_ref' => $serviceRef,
        'name' => (string) ($service['name'] ?? ''),
        'description' => (string) ($service['description'] ?? ''),
        'duration_minutes' => reschedule_int_or_null($service['duration_minutes'] ?? null),
        'break_minutes' => reschedule_int_or_null($service['break_minutes'] ?? null),
        'booking_buffer_minutes' => reschedule_int_or_null($service['booking_buffer_minutes'] ?? null),
        'price_amount' => reschedule_float_or_null($service['price_amount'] ?? null),
        'price_currency' => (string) ($service['price_currency'] ?? 'PLN'),
        'payments_enabled' => array_key_exists('payments_enabled', $service) ? $service['payments_enabled'] : null,
    ];
}

function reschedule_public_staff_payload(?array $staff, array $booking, string $tenantId, string $refSecret): ?array
{
    if (!is_array($staff)) {
        return null;
    }

    $staffId = trim((string) ($staff['id'] ?? $booking['staff_id'] ?? ''));
    $staffRef = reschedule_staff_public_ref($tenantId, $staffId, $refSecret);

    return [
        'staff_ref' => $staffRef,
        'display_name' => (string) ($staff['display_name'] ?? ''),
        'description' => (string) ($staff['description'] ?? ''),
    ];
}


function reschedule_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));
    return ($hours * 60) + $minutes;
}

function reschedule_ranges_overlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

function reschedule_interval_end(int $start, array $settings): int
{
    return $start
        + max(1, (int) ($settings['consultation_duration'] ?? 60))
        + max(0, (int) ($settings['consultation_break'] ?? 0));
}

function reschedule_slots_from_ranges(array $ranges, array $settings, string $date): array
{
    $duration = max(1, (int) ($settings['consultation_duration'] ?? 60));
    $break = max(0, (int) ($settings['consultation_break'] ?? 0));
    $weekday = (int) (new DateTimeImmutable($date, new DateTimeZone('Europe/Warsaw')))->format('N');
    $slots = [];

    foreach ($ranges as $range) {
        if (!is_array($range) || (isset($range['is_active']) && empty($range['is_active']))) {
            continue;
        }

        if (isset($range['weekday']) && (int) $range['weekday'] !== $weekday) {
            continue;
        }

        $start = substr((string) ($range['start_time'] ?? ''), 0, 5);
        $end = substr((string) ($range['end_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            continue;
        }

        $current = reschedule_time_to_minutes($start);
        $endMinutes = reschedule_time_to_minutes($end);

        while ($current + $duration <= $endMinutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
            $current += $duration + $break;
        }
    }

    $slots = array_values(array_unique($slots));
    sort($slots);
    return $slots;
}

function reschedule_effective_min_notice_minutes(array $service, array $calendar): int
{
    $serviceBuffer = reschedule_int_or_null($service['booking_buffer_minutes'] ?? null);
    $globalBuffer = reschedule_int_or_null($calendar['booking_buffer'] ?? null);

    if ($serviceBuffer !== null && $serviceBuffer > 0) {
        return max(0, $serviceBuffer);
    }

    return max(0, (int) ($globalBuffer ?? 0));
}

function reschedule_effective_settings(array $service, array $staff, array $calendar): array
{
    return [
        'consultation_duration' => max(1, (int) (reschedule_int_or_null($service['duration_minutes'] ?? null) ?? reschedule_int_or_null($staff['service_duration_minutes'] ?? null) ?? (int) ($calendar['consultation_duration'] ?? 60))),
        'consultation_break' => max(0, (int) (reschedule_int_or_null($service['break_minutes'] ?? null) ?? reschedule_int_or_null($staff['service_break_minutes'] ?? null) ?? (int) ($calendar['consultation_break'] ?? 0))),
        'booking_buffer' => reschedule_effective_min_notice_minutes($service, $calendar),
    ];
}

function reschedule_slot_respects_buffer(string $date, string $time, int $bufferMinutes): bool
{
    $bufferMinutes = max(0, $bufferMinutes);

    if ($bufferMinutes <= 0) {
        return true;
    }

    $time = reschedule_normalize_time($time);

    if ($date === '' || $time === '') {
        return false;
    }

    $timezone = new DateTimeZone('Europe/Warsaw');
    $slotDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);

    if (!$slotDateTime instanceof DateTimeImmutable || $slotDateTime->format('Y-m-d H:i') !== $date . ' ' . $time) {
        return false;
    }

    $now = new DateTimeImmutable('now', $timezone);
    $minAllowedDateTime = $now->modify('+' . $bufferMinutes . ' minutes');

    return $slotDateTime >= $minAllowedDateTime;
}

function reschedule_slot_is_free(string $time, array $settings, array $occupiedIntervals): bool
{
    $start = reschedule_time_to_minutes($time);
    $end = reschedule_interval_end($start, $settings);

    foreach ($occupiedIntervals as $interval) {
        if (reschedule_ranges_overlap($start, $end, (int) $interval['start'], (int) $interval['end'])) {
            return false;
        }
    }

    return true;
}

function reschedule_blocked_time_overlaps(string $time, array $settings, array $blockedTimes): bool
{
    $start = reschedule_time_to_minutes($time);
    $end = reschedule_interval_end($start, $settings);

    foreach ($blockedTimes as $blockedTime) {
        $blockTime = substr((string) $blockedTime, 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $blockTime)) {
            continue;
        }

        $blockStart = reschedule_time_to_minutes($blockTime);
        $blockEnd = $blockStart + 60;

        if (reschedule_ranges_overlap($start, $end, $blockStart, $blockEnd)) {
            return true;
        }
    }

    return false;
}

function reschedule_load_booking(string $supabaseUrl, string $key, string $schema, string $tenantId, string $token): ?array
{
    $bookingSelect = implode(',', [
        'id',
        'tenant_id',
        'booking_date',
        'booking_time',
        'name',
        'email',
        'phone',
        'notes',
        'service_id',
        'staff_id',
        'service_name_snapshot',
        'payment_required',
        'payment_status',
        'payment_amount',
        'payment_currency',
        'google_event_id',
        'reschedule_count',
        'manage_token_expires_at',
    ]);

    return reschedule_fetch_single($supabaseUrl, $key, $schema, 'bookings', [
        'select=' . rawurlencode($bookingSelect),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'manage_token=eq.' . rawurlencode($token),
    ]);
}

function reschedule_assert_booking_can_change(array $booking): void
{
    $tokenExpiresAt = trim((string) ($booking['manage_token_expires_at'] ?? ''));

    if (!reschedule_timestamp_is_future($tokenExpiresAt)) {
        reschedule_error('Nie można już przełożyć tej rezerwacji, ponieważ termin już się rozpoczął lub minął.', 'token_expired', 410);
    }

    $bookingStart = reschedule_booking_start(
        trim((string) ($booking['booking_date'] ?? '')),
        reschedule_normalize_time((string) ($booking['booking_time'] ?? ''))
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

    if (!$bookingStart || $bookingStart <= $now) {
        reschedule_error('Nie można już przełożyć tej rezerwacji, ponieważ termin już się rozpoczął lub minął.', 'booking_already_started', 410);
    }
}

function reschedule_load_service(string $supabaseUrl, string $key, string $schema, string $tenantId, array $booking): array
{
    $serviceId = trim((string) ($booking['service_id'] ?? ''));
    $service = [
        'id' => null,
        'name' => trim((string) ($booking['service_name_snapshot'] ?? '')),
        'description' => '',
        'duration_minutes' => null,
        'break_minutes' => null,
        'booking_buffer_minutes' => null,
        'price_amount' => null,
        'price_currency' => null,
        'payments_enabled' => null,
    ];

    if ($serviceId === '') {
        return $service;
    }

    $serviceRow = reschedule_fetch_single($supabaseUrl, $key, $schema, 'tenant_services', [
        'select=' . rawurlencode('id,name,description,duration_minutes,break_minutes,booking_buffer_minutes,price_amount,price_currency,payments_enabled,is_active,visible_on_front'),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=eq.' . rawurlencode($serviceId),
    ]);

    if (!is_array($serviceRow)) {
        $service['id'] = $serviceId;
        return $service;
    }

    return [
        'id' => (string) ($serviceRow['id'] ?? ''),
        'name' => (string) ($serviceRow['name'] ?? ''),
        'description' => (string) ($serviceRow['description'] ?? ''),
        'duration_minutes' => reschedule_int_or_null($serviceRow['duration_minutes'] ?? null),
        'break_minutes' => reschedule_int_or_null($serviceRow['break_minutes'] ?? null),
        'booking_buffer_minutes' => reschedule_int_or_null($serviceRow['booking_buffer_minutes'] ?? null),
        'price_amount' => reschedule_float_or_null($serviceRow['price_amount'] ?? null),
        'price_currency' => (string) ($serviceRow['price_currency'] ?? 'PLN'),
        'payments_enabled' => array_key_exists('payments_enabled', $serviceRow) ? (bool) $serviceRow['payments_enabled'] : null,
    ];
}

function reschedule_load_staff(string $supabaseUrl, string $key, string $schema, string $tenantId, array $booking, bool $forAvailability = false): ?array
{
    $staffId = trim((string) ($booking['staff_id'] ?? ''));

    if ($staffId === '') {
        return null;
    }

    $select = $forAvailability
        ? 'id,display_name,description,service_duration_minutes,service_break_minutes,is_active'
        : 'id,display_name,description';

    $staffRow = reschedule_fetch_single($supabaseUrl, $key, $schema, 'staff_profiles', [
        'select=' . rawurlencode($select),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=eq.' . rawurlencode($staffId),
        'is_active=eq.true',
    ]);

    if (!is_array($staffRow)) {
        return $forAvailability ? null : [
            'id' => $staffId,
            'display_name' => '',
            'description' => '',
        ];
    }

    return $staffRow;
}

function reschedule_load_calendar(string $supabaseUrl, string $key, string $schema, string $tenantId): array
{
    $calendar = reschedule_fetch_single($supabaseUrl, $key, $schema, 'calendar_settings', [
        'select=' . rawurlencode('work_start,work_end,consultation_duration,consultation_break,booking_buffer,booking_start_month_offset,booking_month_range'),
        'tenant_id=eq.' . rawurlencode($tenantId),
    ]);

    return is_array($calendar) ? $calendar : [];
}

function reschedule_booking_response(array $booking, array $service, ?array $staff, string $tenantId, string $refSecret): array
{
    $currentDate = trim((string) ($booking['booking_date'] ?? ''));
    $currentTime = reschedule_normalize_time((string) ($booking['booking_time'] ?? ''));
    $paymentRequired = filter_var($booking['payment_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $paymentStatus = trim((string) ($booking['payment_status'] ?? ''));

    return [
        'customer_name' => (string) ($booking['name'] ?? ''),
        'customer_email' => (string) ($booking['email'] ?? ''),
        'customer_phone' => (string) ($booking['phone'] ?? ''),
        'service' => reschedule_public_service_payload($service, $booking, $tenantId, $refSecret),
        'staff' => reschedule_public_staff_payload($staff, $booking, $tenantId, $refSecret),
        'current_date' => $currentDate,
        'current_date_label' => reschedule_format_date_label($currentDate),
        'current_time' => $currentTime,
        'current_time_label' => $currentTime,
        'payment_required' => $paymentRequired,
        'payment_status' => $paymentStatus,
        'payment_status_label' => reschedule_payment_status_label($paymentStatus),
        'payment_amount' => reschedule_float_or_null($booking['payment_amount'] ?? null),
        'payment_currency' => (string) ($booking['payment_currency'] ?? 'PLN'),
        'reschedule_count' => reschedule_count_value($booking),
        'reschedule_limit' => reschedule_max_changes(),
        'manage_token_expires_at' => trim((string) ($booking['manage_token_expires_at'] ?? '')),
    ];
}


function reschedule_load_block_settings(string $supabaseUrl, string $key, string $schema, string $tenantId): array
{
    $row = reschedule_fetch_single($supabaseUrl, $key, $schema, 'block_settings', [
        'select=' . rawurlencode('block_saturdays,block_sundays,block_holidays'),
        'tenant_id=eq.' . rawurlencode($tenantId),
    ]);

    return [
        'block_saturdays' => !empty($row['block_saturdays'] ?? false),
        'block_sundays' => !empty($row['block_sundays'] ?? false),
        'block_holidays' => !empty($row['block_holidays'] ?? false),
    ];
}

function reschedule_polish_holidays(): array
{
    return [
        '01-01',
        '01-06',
        '05-01',
        '05-02',
        '05-03',
        '08-15',
        '11-01',
        '11-11',
        '12-24',
        '12-25',
        '12-26',
    ];
}

function reschedule_date_is_global_rule_blocked(string $date, array $blockSettings): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Warsaw'));

    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
        return true;
    }

    $weekday = (int) $parsed->format('N');
    $monthDay = $parsed->format('m-d');

    if (!empty($blockSettings['block_saturdays']) && $weekday === 6) {
        return true;
    }

    if (!empty($blockSettings['block_sundays']) && $weekday === 7) {
        return true;
    }

    return !empty($blockSettings['block_holidays']) && in_array($monthDay, reschedule_polish_holidays(), true);
}

function reschedule_load_availability_exception_dates(string $supabaseUrl, string $key, string $schema, string $tenantId, string $date, string $staffId): array
{
    $staffFilter = $staffId !== ''
        ? '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')'
        : '&staff_id=is.null';

    $rows = reschedule_fetch_rows($supabaseUrl, $key, $schema, 'availability_exceptions', [
        'select=' . rawurlencode('date,allow_booking,staff_id'),
        'tenant_id=eq.' . rawurlencode($tenantId),
        'date=eq.' . rawurlencode($date) . $staffFilter,
        'allow_booking=eq.true',
    ]);

    return is_array($rows) ? $rows : [];
}

function reschedule_has_availability_exception(string $supabaseUrl, string $key, string $schema, string $tenantId, string $date, string $staffId): bool
{
    return !empty(reschedule_load_availability_exception_dates($supabaseUrl, $key, $schema, $tenantId, $date, $staffId));
}

function reschedule_month_range_limits(array $calendar): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw'));
    $startOffset = max(0, (int) ($calendar['booking_start_month_offset'] ?? 0));
    $monthRange = max(1, (int) ($calendar['booking_month_range'] ?? 1));

    $minMonth = $today->modify('first day of this month')->modify('+' . $startOffset . ' months');
    $maxMonth = $minMonth->modify('+' . ($monthRange - 1) . ' months')->modify('last day of this month');
    $minDate = $minMonth > $today ? $minMonth : $today;

    return [$minDate, $maxMonth];
}

function reschedule_assert_date_in_calendar_range(string $date, array $calendar): void
{
    $selected = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Warsaw'));

    if (!$selected instanceof DateTimeImmutable || $selected->format('Y-m-d') !== $date) {
        reschedule_error('Wybierz poprawną datę.', 'invalid_date', 400);
    }

    [$minDate, $maxDate] = reschedule_month_range_limits($calendar);

    if ($selected < $minDate || $selected > $maxDate) {
        reschedule_error('Wybrany termin jest poza zakresem dostępnych rezerwacji.', 'date_out_of_range', 409);
    }
}

function reschedule_service_relation_exists(string $supabaseUrl, string $key, string $schema, string $tenantId, string $serviceId, string $staffId): bool
{
    if ($serviceId === '' || $staffId === '') {
        return true;
    }

    $relation = reschedule_fetch_single($supabaseUrl, $key, $schema, 'tenant_service_staff', [
        'select=staff_id',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'service_id=eq.' . rawurlencode($serviceId),
        'staff_id=eq.' . rawurlencode($staffId),
    ]);

    return is_array($relation);
}

function reschedule_availability(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    array $booking,
    array $service,
    string $date
): array {
    reschedule_validate_date($date);

    $bookingId = (string) ($booking['id'] ?? '');
    $staffId = trim((string) ($booking['staff_id'] ?? ''));
    $serviceId = trim((string) ($booking['service_id'] ?? ''));
    $calendar = reschedule_load_calendar($supabaseUrl, $key, $schema, $tenantId);
    reschedule_assert_date_in_calendar_range($date, $calendar);

    $blockSettings = reschedule_load_block_settings($supabaseUrl, $key, $schema, $tenantId);
    $hasAvailabilityException = reschedule_has_availability_exception($supabaseUrl, $key, $schema, $tenantId, $date, $staffId);

    if (!$hasAvailabilityException && reschedule_date_is_global_rule_blocked($date, $blockSettings)) {
        return [];
    }

    $staffForSettings = [];

    if ($staffId !== '') {
        $staff = reschedule_load_staff($supabaseUrl, $key, $schema, $tenantId, $booking, true);

        if (!is_array($staff) || empty($staff['id'])) {
            reschedule_error('Osoba obsługująca tę rezerwację jest niedostępna.', 'staff_not_found', 404);
        }

        $staffForSettings = $staff;

        if (!reschedule_service_relation_exists($supabaseUrl, $key, $schema, $tenantId, $serviceId, $staffId)) {
            reschedule_error('Osoba obsługująca nie obsługuje tej usługi.', 'staff_service_mismatch', 409);
        }

        $settings = reschedule_effective_settings($service, $staff, $calendar);
        $availability = reschedule_fetch_rows($supabaseUrl, $key, $schema, 'staff_availability', [
            'select=weekday,start_time,end_time,is_active',
            'tenant_id=eq.' . rawurlencode($tenantId),
            'staff_id=eq.' . rawurlencode($staffId),
            'is_active=eq.true',
            'order=weekday.asc',
            'order=start_time.asc',
        ]);

        if (!is_array($availability)) {
            reschedule_error('Nie udało się pobrać dostępności osoby obsługującej.', 'availability_error', 500);
        }

        $slots = reschedule_slots_from_ranges($availability, $settings, $date);
        $staffFilter = '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';
    } else {
        $settings = reschedule_effective_settings($service, [], $calendar);
        $ranges = [[
            'start_time' => substr((string) ($calendar['work_start'] ?? '09:00'), 0, 5),
            'end_time' => substr((string) ($calendar['work_end'] ?? '17:00'), 0, 5),
            'is_active' => true,
        ]];
        $slots = reschedule_slots_from_ranges($ranges, $settings, $date);
        $staffFilter = '&staff_id=is.null';
    }

    $blockedDate = reschedule_fetch_single($supabaseUrl, $key, $schema, 'blocked_dates', [
        'select=date',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'date=eq.' . rawurlencode($date) . $staffFilter,
    ]);

    if (!$hasAvailabilityException && is_array($blockedDate)) {
        return [];
    }

    $blockedTimes = reschedule_fetch_rows($supabaseUrl, $key, $schema, 'blocked_times', [
        'select=time,staff_id',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'date=eq.' . rawurlencode($date) . $staffFilter,
    ]);

    if (is_array($blockedTimes) && !empty($blockedTimes)) {
        $globalBlockedTimes = [];
        $staffBlockedTimes = [];
        $oldDate = trim((string) ($booking['booking_date'] ?? ''));
        $oldTime = reschedule_normalize_time((string) ($booking['booking_time'] ?? ''));

        foreach ($blockedTimes as $row) {
            if (!is_array($row) || empty($row['time'])) {
                continue;
            }

            $blockedTime = substr((string) $row['time'], 0, 5);

            if ($blockedTime === 'all') {
                return [];
            }

            if ($staffId === '' && $date === $oldDate && $blockedTime === $oldTime) {
                continue;
            }

            if (trim((string) ($row['staff_id'] ?? '')) === '') {
                $globalBlockedTimes[] = $blockedTime;
            } else {
                $staffBlockedTimes[] = $blockedTime;
            }
        }

        $slots = array_values(array_filter($slots, static function (string $time) use ($settings, $globalBlockedTimes, $staffBlockedTimes): bool {
            return !reschedule_blocked_time_overlaps($time, $settings, $globalBlockedTimes)
                && !reschedule_blocked_time_overlaps($time, $settings, $staffBlockedTimes);
        }));
    }

    if ((int) ($settings['booking_buffer'] ?? 0) > 0) {
        $bufferMinutes = (int) $settings['booking_buffer'];
        $slots = array_values(array_filter($slots, static function (string $time) use ($date, $bufferMinutes): bool {
            return reschedule_slot_respects_buffer($date, $time, $bufferMinutes);
        }));
    }

    $bookingQuery = [
        'select=id,booking_time,service_id',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'booking_date=eq.' . rawurlencode($date),
    ];

    if ($staffId !== '') {
        $bookingQuery[] = 'staff_id=eq.' . rawurlencode($staffId);
    } else {
        $bookingQuery[] = 'staff_id=is.null';
    }

    $bookings = reschedule_fetch_rows($supabaseUrl, $key, $schema, 'bookings', $bookingQuery);

    if (is_array($bookings)) {
        $occupied = [];
        $serviceSettingsById = [];
        $serviceIds = [];

        foreach ($bookings as $row) {
            if (!is_array($row) || empty($row['service_id'])) {
                continue;
            }

            $serviceIds[(string) $row['service_id']] = true;
        }

        if (!empty($serviceIds)) {
            $serviceRows = reschedule_fetch_rows($supabaseUrl, $key, $schema, 'tenant_services', [
                'select=' . rawurlencode('id,duration_minutes,break_minutes,booking_buffer_minutes'),
                'tenant_id=eq.' . rawurlencode($tenantId),
                'id=in.(' . implode(',', array_map('rawurlencode', array_keys($serviceIds))) . ')',
            ]);

            if (is_array($serviceRows)) {
                foreach ($serviceRows as $serviceRow) {
                    if (!is_array($serviceRow) || empty($serviceRow['id'])) {
                        continue;
                    }

                    $serviceSettingsById[(string) $serviceRow['id']] = reschedule_effective_settings($serviceRow, $staffForSettings, $calendar);
                }
            }
        }

        foreach ($bookings as $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') === $bookingId) {
                continue;
            }

            $time = reschedule_normalize_time((string) ($row['booking_time'] ?? ''));

            if ($time === '') {
                continue;
            }

            $start = reschedule_time_to_minutes($time);
            $rowServiceId = trim((string) ($row['service_id'] ?? ''));
            $rowSettings = isset($serviceSettingsById[$rowServiceId]) ? $serviceSettingsById[$rowServiceId] : $settings;
            $occupied[] = [
                'start' => $start,
                'end' => reschedule_interval_end($start, $rowSettings),
            ];
        }

        $slots = array_values(array_filter($slots, static function (string $time) use ($settings, $occupied): bool {
            return reschedule_slot_is_free($time, $settings, $occupied);
        }));
    }

    $oldDate = trim((string) ($booking['booking_date'] ?? ''));
    $oldTime = reschedule_normalize_time((string) ($booking['booking_time'] ?? ''));

    if ($date === $oldDate && $oldTime !== '') {
        $slots = array_values(array_filter($slots, static function (string $time) use ($oldTime): bool {
            return reschedule_normalize_time($time) !== $oldTime;
        }));
    }

    sort($slots);
    return array_values($slots);
}

function reschedule_update_booking(string $supabaseUrl, string $key, string $schema, string $tenantId, array $booking, string $date, string $time): ?array
{
    $bookingId = (string) ($booking['id'] ?? '');
    $rescheduleCount = (int) ($booking['reschedule_count'] ?? 0);
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format(DATE_ATOM);

    $payload = [
        'booking_date' => $date,
        'booking_time' => $time,
        'updated_at' => $now,
        'rescheduled_at' => $now,
        'reschedule_count' => $rescheduleCount + 1,
        'manage_token_expires_at' => reschedule_start_atom($date, $time),
    ];

    $url = rtrim($supabaseUrl, '/') . '/rest/v1/bookings'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($bookingId);

    $result = reschedule_request('PATCH', $url, $key, $schema, $payload);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        return null;
    }

    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
    return is_array($rows[0] ?? null) ? $rows[0] : array_merge($booking, $payload);
}

function reschedule_insert_global_block(string $supabaseUrl, string $key, string $schema, string $tenantId, string $date, string $time): bool
{
    $payload = [
        'tenant_id' => $tenantId,
        'date' => $date,
        'time' => $time,
    ];

    $result = reschedule_request('POST', rtrim($supabaseUrl, '/') . '/rest/v1/blocked_times', $key, $schema, $payload, true);
    return $result['error'] === '' && $result['httpCode'] >= 200 && $result['httpCode'] < 300;
}

function reschedule_send_mail(string $supabaseUrl, string $key, string $schema, string $tenantId, array $booking): void
{
    try {
        $tenantQuery = 'tenant_id=eq.' . rawurlencode($tenantId);
        $emailSettings = reschedule_fetch_single($supabaseUrl, $key, $schema, 'email_settings', [
            'select=*',
            $tenantQuery,
            'is_active=eq.true',
        ]);
        $tenantData = reschedule_fetch_single($supabaseUrl, $key, $schema, 'tenant_branding', [
            'select=*',
            $tenantQuery,
        ]);

        if (!$emailSettings || !$tenantData || !function_exists('booking_mail_send_reschedule_confirmation')) {
            return;
        }

        booking_mail_send_reschedule_confirmation($emailSettings, $tenantData, $booking);
    } catch (Throwable $e) {
        return;
    }
}

function reschedule_google_duration_minutes(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    array $booking,
    array $service
): int {
    $calendar = reschedule_load_calendar($supabaseUrl, $key, $schema, $tenantId);
    $staff = reschedule_load_staff($supabaseUrl, $key, $schema, $tenantId, $booking, true);
    $settings = reschedule_effective_settings($service, is_array($staff) ? $staff : [], $calendar);

    return max(1, (int) ($settings['consultation_duration'] ?? 60));
}

function reschedule_sync_google_calendar(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    array $booking
): void {
    try {
        $bookingId = (string) ($booking['id'] ?? '');

        if ($bookingId === '') {
            google_calendar_debug('RESCHEDULE_GOOGLE_SKIPPED', 'Brak wewnętrznego identyfikatora rezerwacji.');
            return;
        }

        $googleEventId = trim((string) ($booking['google_event_id'] ?? ''));
        $updated = false;

        if ($googleEventId !== '') {
            $updateResult = updateGoogleCalendarEventForBooking($tenantId, $googleEventId, $booking);

            if (!empty($updateResult['success'])) {
                google_calendar_debug('RESCHEDULE_GOOGLE_EVENT_UPDATED', [
                    'booking_hash' => reschedule_trace_hash($bookingId),
                    'google_event_hash' => reschedule_trace_hash($googleEventId),
                ]);
                $updated = true;
            } elseif (empty($updateResult['not_found'])) {
                // Miękka synchronizacja: błąd Google nie może cofnąć przełożenia w bazie.
                google_calendar_debug('RESCHEDULE_GOOGLE_EVENT_UPDATE_FAILED', [
                    'booking_hash' => reschedule_trace_hash($bookingId),
                    'google_event_hash' => reschedule_trace_hash($googleEventId),
                    'status_code' => $updateResult['status_code'] ?? null,
                ]);
                return;
            }
        }

        if ($updated) {
            return;
        }

        $newGoogleEventId = createGoogleCalendarEventForBooking($tenantId, $booking);

        if ($newGoogleEventId) {
            google_calendar_update_booking_event_id($bookingId, $newGoogleEventId, $tenantId);
            google_calendar_debug('RESCHEDULE_GOOGLE_EVENT_CREATED', [
                'booking_hash' => reschedule_trace_hash($bookingId),
                'google_event_hash' => reschedule_trace_hash($newGoogleEventId),
                'previous_google_event_hash' => $googleEventId !== '' ? reschedule_trace_hash($googleEventId) : null,
            ]);
        } else {
            google_calendar_debug('RESCHEDULE_GOOGLE_EVENT_CREATE_FAILED', [
                'booking_hash' => reschedule_trace_hash($bookingId),
                'previous_google_event_hash' => $googleEventId !== '' ? reschedule_trace_hash($googleEventId) : null,
            ]);
        }
    } catch (Throwable $e) {
        // Miękka synchronizacja: błąd Google nie może cofnąć przełożenia w bazie.
        google_calendar_debug('RESCHEDULE_GOOGLE_SYNC_ERROR', [
            'error_type' => 'google_sync_error',
            'booking_hash' => reschedule_trace_hash((string) ($booking['id'] ?? '')),
        ]);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    reschedule_error('Metoda niedozwolona.', 'method_not_allowed', 405);
}

$input = $method === 'POST' ? reschedule_read_json_input() : [];
$token = trim((string) ($method === 'POST' ? ($input['token'] ?? '') : ($_GET['token'] ?? '')));
reschedule_validate_token($token);

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    reschedule_error('Nie udało się wczytać konfiguracji systemu.', 'configuration_error', 500);
}

$tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

if (!$tenantId) {
    reschedule_error('Nie znaleziono klienta dla tej domeny.', 'tenant_not_found', 404);
}

$tenantId = (string) $tenantId;
$refSecret = public_response_ref_secret($supabaseKey);

if (!tenant_has_feature($tenantId, 'reschedule_booking')) {
    reschedule_error('Funkcja przełożenia rezerwacji jest dostępna w wyższych planach.', 'feature_unavailable', 403);
}

$booking = reschedule_load_booking($supabaseUrl, $supabaseKey, $schema, $tenantId, $token);

if (!$booking) {
    reschedule_error('Nie znaleziono rezerwacji albo link jest nieprawidłowy.', 'booking_not_found', 404);
}

reschedule_assert_booking_can_change($booking);

$service = reschedule_load_service($supabaseUrl, $supabaseKey, $schema, $tenantId, $booking);
$staff = reschedule_load_staff($supabaseUrl, $supabaseKey, $schema, $tenantId, $booking);

$rescheduleLimitReached = reschedule_limit_reached($booking);

if ($method === 'GET') {
    $date = trim((string) ($_GET['date'] ?? ''));

    if ($date !== '') {
        if ($rescheduleLimitReached) {
            reschedule_error(reschedule_limit_message(), 'reschedule_limit_reached', 409);
        }

        reschedule_validate_date($date);

        reschedule_json([
            'success' => true,
            'can_reschedule' => true,
            'date' => $date,
            'date_label' => reschedule_format_date_label($date),
            'availableTimes' => reschedule_availability($supabaseUrl, $supabaseKey, $schema, $tenantId, $booking, $service, $date),
        ]);
    }

    reschedule_json([
        'success' => true,
        'can_reschedule' => !$rescheduleLimitReached,
        'message' => $rescheduleLimitReached ? reschedule_limit_message() : '',
        'booking' => reschedule_booking_response($booking, $service, $staff, $tenantId, $refSecret),
    ]);
}

if ($rescheduleLimitReached) {
    reschedule_error(reschedule_limit_message(), 'reschedule_limit_reached', 409);
}

$newDate = trim((string) ($input['date'] ?? ''));
$newTime = reschedule_normalize_time((string) ($input['time'] ?? ''));

reschedule_validate_date($newDate);
reschedule_validate_time($newTime);

if (reschedule_is_same_booking_slot($booking, $newDate, $newTime)) {
    reschedule_error('Wybierz inny termin niż obecny. Nie można przełożyć rezerwacji na tę samą datę i godzinę.', 'same_booking_slot', 409);
}

$newStart = reschedule_booking_start($newDate, $newTime);
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));

if (!$newStart || $newStart <= $now) {
    reschedule_error('Wybierz przyszły termin rezerwacji.', 'new_slot_in_past', 409);
}

$availableTimes = reschedule_availability($supabaseUrl, $supabaseKey, $schema, $tenantId, $booking, $service, $newDate);

if (!in_array($newTime, $availableTimes, true)) {
    reschedule_error('Wybrany termin jest już niedostępny. Wybierz inną godzinę.', 'slot_unavailable', 409);
}

$oldDate = trim((string) ($booking['booking_date'] ?? ''));
$oldTime = reschedule_normalize_time((string) ($booking['booking_time'] ?? ''));
$previousLabel = trim(reschedule_format_date_label($oldDate) . ' ' . $oldTime);
$newLabel = trim(reschedule_format_date_label($newDate) . ' ' . $newTime);

$updatedBooking = reschedule_update_booking($supabaseUrl, $supabaseKey, $schema, $tenantId, $booking, $newDate, $newTime);

if (!$updatedBooking) {
    reschedule_error('Nie udało się zmienić terminu rezerwacji. Spróbuj ponownie.', 'update_failed', 500);
}

if (trim((string) ($booking['staff_id'] ?? '')) === '') {
    reschedule_insert_global_block($supabaseUrl, $supabaseKey, $schema, $tenantId, $newDate, $newTime);
}

$mailBooking = array_merge($booking, $updatedBooking, [
    'previous_date_label' => $previousLabel,
    'new_date_label' => $newLabel,
    'service_name_snapshot' => (string) ($service['name'] ?? $booking['service_name_snapshot'] ?? ''),
    'staff_display_name' => is_array($staff) ? (string) ($staff['display_name'] ?? '') : '',
    'payment_status_label' => reschedule_payment_status_label($updatedBooking['payment_status'] ?? $booking['payment_status'] ?? ''),
]);

$googleBooking = array_merge($mailBooking, [
    'duration_minutes' => reschedule_google_duration_minutes($supabaseUrl, $supabaseKey, $schema, $tenantId, $mailBooking, $service),
    'reschedule_limit' => reschedule_max_changes(),
]);

reschedule_sync_google_calendar($supabaseUrl, $supabaseKey, $schema, $tenantId, $googleBooking);

reschedule_send_mail($supabaseUrl, $supabaseKey, $schema, $tenantId, $mailBooking);

$updatedService = reschedule_load_service($supabaseUrl, $supabaseKey, $schema, $tenantId, $updatedBooking);
$updatedStaff = reschedule_load_staff($supabaseUrl, $supabaseKey, $schema, $tenantId, $updatedBooking);

reschedule_json([
    'success' => true,
    'can_reschedule' => true,
    'message' => 'Termin rezerwacji został zmieniony.',
    'booking' => reschedule_booking_response($updatedBooking, $updatedService, $updatedStaff, $tenantId, $refSecret),
]);
