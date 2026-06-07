<?php
declare(strict_types=1);

function booking_availability_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hours * 60) + $minutes;
}

function booking_availability_ranges_overlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

function booking_availability_nullable_int(array $row, string $key): ?int
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return is_numeric($row[$key]) ? (int) $row[$key] : null;
}

function booking_availability_interval_end(int $start, array $settings): int
{
    return $start
        + max(1, (int) ($settings['consultation_duration'] ?? 60))
        + max(0, (int) ($settings['consultation_break'] ?? 0));
}

function booking_availability_effective_min_notice_minutes(array $service, array $calendar): int
{
    $serviceBuffer = booking_availability_nullable_int($service, 'booking_buffer_minutes');
    $globalBuffer = booking_availability_nullable_int($calendar, 'booking_buffer');

    if ($serviceBuffer !== null && $serviceBuffer > 0) {
        return $serviceBuffer;
    }

    return max(0, (int) ($globalBuffer ?? 0));
}

function booking_availability_effective_settings(array $service, array $staff, array $calendar): array
{
    $serviceDuration = booking_availability_nullable_int($service, 'duration_minutes');
    $serviceBreak = booking_availability_nullable_int($service, 'break_minutes');
    $staffDuration = booking_availability_nullable_int($staff, 'service_duration_minutes');
    $staffBreak = booking_availability_nullable_int($staff, 'service_break_minutes');

    return [
        'consultation_duration' => max(1, (int) ($serviceDuration ?? $staffDuration ?? (int) ($calendar['consultation_duration'] ?? 60))),
        'consultation_break' => max(0, (int) ($serviceBreak ?? $staffBreak ?? (int) ($calendar['consultation_break'] ?? 0))),
        'booking_buffer' => booking_availability_effective_min_notice_minutes($service, $calendar),
    ];
}

function booking_availability_slots_from_ranges(array $ranges, array $settings, string $date): array
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

        $current = booking_availability_time_to_minutes($start);
        $endMinutes = booking_availability_time_to_minutes($end);

        while ($current + $duration <= $endMinutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
            $current += $duration + $break;
        }
    }

    $slots = array_values(array_unique($slots));
    sort($slots);
    return $slots;
}

function booking_availability_polish_holidays(): array
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

function booking_availability_global_rule_blocked(string $date, array $blockSettings): bool
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

    return !empty($blockSettings['block_holidays']) && in_array($monthDay, booking_availability_polish_holidays(), true);
}

function booking_availability_slot_respects_buffer(string $date, string $time, int $bufferMinutes): bool
{
    if ($bufferMinutes <= 0) {
        return true;
    }

    $timezone = new DateTimeZone('Europe/Warsaw');
    $slotDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($time, 0, 5), $timezone);

    if (!$slotDateTime instanceof DateTimeImmutable || $slotDateTime->format('Y-m-d H:i') !== $date . ' ' . substr($time, 0, 5)) {
        return false;
    }

    $minAllowedDateTime = (new DateTimeImmutable('now', $timezone))->modify('+' . $bufferMinutes . ' minutes');

    return $slotDateTime >= $minAllowedDateTime;
}

function booking_availability_blocked_time_overlaps(string $time, array $settings, array $blockedTimes): bool
{
    $start = booking_availability_time_to_minutes($time);
    $end = booking_availability_interval_end($start, $settings);

    foreach ($blockedTimes as $blockedTime) {
        $blockTime = substr((string) $blockedTime, 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $blockTime)) {
            continue;
        }

        $blockStart = booking_availability_time_to_minutes($blockTime);
        $blockEnd = $blockStart + 60;

        if (booking_availability_ranges_overlap($start, $end, $blockStart, $blockEnd)) {
            return true;
        }
    }

    return false;
}

function booking_availability_slot_is_free(string $time, array $settings, array $occupiedIntervals): bool
{
    $start = booking_availability_time_to_minutes($time);
    $end = booking_availability_interval_end($start, $settings);

    foreach ($occupiedIntervals as $interval) {
        if (!is_array($interval)) {
            continue;
        }

        if (booking_availability_ranges_overlap($start, $end, (int) ($interval['start'] ?? 0), (int) ($interval['end'] ?? 0))) {
            return false;
        }
    }

    return true;
}

function booking_availability_occupied_intervals(array $bookings, array $settingsByService, array $fallbackSettings, string $excludeBookingId = ''): array
{
    $intervals = [];

    foreach ($bookings as $booking) {
        if (!is_array($booking) || ($excludeBookingId !== '' && (string) ($booking['id'] ?? '') === $excludeBookingId)) {
            continue;
        }

        $time = substr((string) ($booking['booking_time'] ?? ''), 0, 5);

        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            continue;
        }

        $serviceId = trim((string) ($booking['service_id'] ?? ''));
        $settings = isset($settingsByService[$serviceId]) && is_array($settingsByService[$serviceId])
            ? $settingsByService[$serviceId]
            : $fallbackSettings;
        $start = booking_availability_time_to_minutes($time);

        $intervals[] = [
            'start' => $start,
            'end' => booking_availability_interval_end($start, $settings),
        ];
    }

    return $intervals;
}

function booking_availability_times_for_day(
    string $date,
    array $ranges,
    array $settings,
    array $blockSettings,
    array $blockedDates,
    array $blockedTimes,
    array $availabilityExceptions,
    array $occupiedIntervals,
    array $ignoredBlockedTimes = []
): array {
    $hasException = in_array($date, $availabilityExceptions, true);

    if (!$hasException && (in_array($date, $blockedDates, true) || booking_availability_global_rule_blocked($date, $blockSettings))) {
        return [];
    }

    $slots = booking_availability_slots_from_ranges($ranges, $settings, $date);
    $globalBlockedTimes = [];
    $staffBlockedTimes = [];

    foreach ($blockedTimes[$date] ?? [] as $row) {
        if (!is_array($row) || empty($row['time'])) {
            continue;
        }

        $blockedTime = substr((string) $row['time'], 0, 5);

        if ($blockedTime === 'all') {
            return [];
        }

        if (in_array($blockedTime, $ignoredBlockedTimes[$date] ?? [], true)) {
            continue;
        }

        if (trim((string) ($row['staff_id'] ?? '')) === '') {
            $globalBlockedTimes[] = $blockedTime;
        } else {
            $staffBlockedTimes[] = $blockedTime;
        }
    }

    if (!empty($globalBlockedTimes) || !empty($staffBlockedTimes)) {
        $slots = array_values(array_filter($slots, static function (string $time) use ($settings, $globalBlockedTimes, $staffBlockedTimes): bool {
            if (in_array($time, $staffBlockedTimes, true)) {
                return false;
            }

            return !booking_availability_blocked_time_overlaps($time, $settings, $globalBlockedTimes);
        }));
    }

    if ((int) ($settings['booking_buffer'] ?? 0) > 0) {
        $bufferMinutes = (int) $settings['booking_buffer'];
        $slots = array_values(array_filter($slots, static function (string $time) use ($date, $bufferMinutes): bool {
            return booking_availability_slot_respects_buffer($date, $time, $bufferMinutes);
        }));
    }

    if (!empty($occupiedIntervals)) {
        $slots = array_values(array_filter($slots, static function (string $time) use ($settings, $occupiedIntervals): bool {
            return booking_availability_slot_is_free($time, $settings, $occupiedIntervals);
        }));
    }

    sort($slots);
    return array_values($slots);
}
