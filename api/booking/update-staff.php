<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();
date_default_timezone_set('Europe/Warsaw');

function booking_staff_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function booking_staff_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $staffId = null,
    ?string $stage = null
): void {
    if (!function_exists('security_log_event')) {
        return;
    }

    $details = [
        'reason' => $reason,
    ];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    $context = [
        'action_key' => 'booking_staff_update',
        'endpoint' => '/api/booking/update-staff.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    if ($tenantId !== null && $tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    if ($staffId !== null && $staffId !== '') {
        $context['staff_id'] = $staffId;
    }

    try {
        security_log_event($eventKey, $context);
    } catch (Throwable $exception) {
        // Audyt bezpieczeństwa nie może blokować operacji biznesowej.
    }
}

function booking_staff_require_admin_session(): array
{
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user) || empty($user['id']) || empty($user['tenant_id'])) {
        booking_staff_security_event(
            'booking_staff_update_unauthorized',
            'unauthorized',
            401
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], 401);
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));

    if (!in_array($role, ['admin', 'administrator'], true)) {
        booking_staff_security_event(
            'booking_staff_update_forbidden',
            'forbidden',
            403,
            'denied',
            'medium',
            (string) ($user['tenant_id'] ?? '')
        );

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
            'error' => 'Nie udało się pobrać danych'
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
        booking_staff_security_event(
            'booking_staff_update_failed',
            'booking_update_failed',
            $result['httpCode'] > 0 ? $result['httpCode'] : 500,
            'error',
            'high',
            null,
            null,
            'booking_patch'
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nie udało się zapisać zmiany'
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
        booking_staff_security_event(
            'booking_staff_update_history_failed',
            'history_insert_failed',
            $result['httpCode'] > 0 ? $result['httpCode'] : 500,
            'warning',
            'medium',
            null,
            null,
            'history_insert'
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nie udało się zapisać historii zmiany'
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


function booking_staff_public_ref_secret(string $supabaseKey): string
{
    return (string) (getenv('BOOKING_PUBLIC_REF_SECRET') ?: getenv('APP_SECRET') ?: $supabaseKey);
}

function booking_staff_build_public_ref(string $prefix, string $kind, string $tenantId, string $recordId, string $secret): string
{
    return $prefix . '_' . substr(hash_hmac('sha256', $kind . '|' . $tenantId . '|' . $recordId, $secret), 0, 48);
}

function booking_staff_build_booking_ref(string $tenantId, string $bookingId, string $secret): string
{
    return booking_staff_build_public_ref('bk', 'booking', $tenantId, $bookingId, $secret);
}

function booking_staff_build_staff_ref(string $tenantId, string $staffId, string $secret): string
{
    return booking_staff_build_public_ref('st', 'staff', $tenantId, $staffId, $secret);
}

function booking_staff_is_valid_booking_ref(string $ref): bool
{
    return preg_match('/^bk_[a-f0-9]{32,64}$/', $ref) === 1;
}

function booking_staff_is_valid_staff_ref(string $ref): bool
{
    return preg_match('/^st_[a-f0-9]{32,64}$/', $ref) === 1;
}

function booking_staff_is_valid_date_hint(string $date): bool
{
    return $date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
}

function booking_staff_is_valid_time_hint(string $time): bool
{
    return $time === '' || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) === 1;
}

function booking_staff_resolve_booking_by_ref(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    string $bookingRef,
    string $dateHint,
    string $timeHint
): ?array {
    if (!booking_staff_is_valid_booking_ref($bookingRef)) {
        booking_staff_security_event(
            'booking_staff_update_booking_ref_invalid',
            'booking_ref_invalid',
            400,
            'failed',
            'medium',
            $tenantId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator rezerwacji'
        ], 400);
    }

    if (!booking_staff_is_valid_date_hint($dateHint) || !booking_staff_is_valid_time_hint($timeHint)) {
        booking_staff_security_event(
            'booking_staff_update_hint_invalid',
            'hint_invalid',
            400,
            'failed',
            'medium',
            $tenantId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nieprawidłowe dane rezerwacji'
        ], 400);
    }

    $query = [
        'select=*',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'limit=200',
    ];

    if ($dateHint !== '') {
        $query[] = 'booking_date=eq.' . rawurlencode($dateHint);
    }

    if ($timeHint !== '') {
        $query[] = 'booking_time=eq.' . rawurlencode($timeHint);
    }

    $rows = booking_staff_fetch_rows($supabaseUrl, $key, $schema, 'bookings', $query);
    $secret = booking_staff_public_ref_secret($key);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $candidateId = trim((string) ($row['id'] ?? ''));

        if ($candidateId === '') {
            continue;
        }

        $candidateRef = booking_staff_build_booking_ref($tenantId, $candidateId, $secret);

        if (hash_equals($candidateRef, $bookingRef)) {
            return $row;
        }
    }

    return null;
}

function booking_staff_resolve_staff_by_ref(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    string $staffRef
): ?array {
    if (!booking_staff_is_valid_staff_ref($staffRef)) {
        booking_staff_security_event(
            'booking_staff_update_staff_ref_invalid',
            'staff_ref_invalid',
            400,
            'failed',
            'medium',
            $tenantId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator pracownika'
        ], 400);
    }

    $rows = booking_staff_fetch_rows($supabaseUrl, $key, $schema, 'staff_profiles', [
        'select=id,display_name,email,is_active,service_duration_minutes,service_break_minutes',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'limit=500',
    ]);

    $secret = booking_staff_public_ref_secret($key);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $candidateId = trim((string) ($row['id'] ?? ''));

        if ($candidateId === '') {
            continue;
        }

        $candidateRef = booking_staff_build_staff_ref($tenantId, $candidateId, $secret);

        if (hash_equals($candidateRef, $staffRef)) {
            return $row;
        }
    }

    return null;
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


function booking_staff_nullable_int(array $row, string $key): ?int
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return (int) $row[$key];
}

function booking_staff_normalize_time(string $time): string
{
    $value = substr(trim($time), 0, 5);

    return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '';
}

function booking_staff_time_to_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
}

function booking_staff_ranges_overlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

function booking_staff_interval_end(int $start, array $settings): int
{
    return $start
        + max(1, (int) ($settings['consultation_duration'] ?? 60))
        + max(0, (int) ($settings['consultation_break'] ?? 0));
}

function booking_staff_effective_settings(array $service, array $staff, array $calendar): array
{
    $serviceDuration = booking_staff_nullable_int($service, 'duration_minutes');
    $serviceBreak = booking_staff_nullable_int($service, 'break_minutes');

    $staffDuration = booking_staff_nullable_int($staff, 'service_duration_minutes');
    $staffBreak = booking_staff_nullable_int($staff, 'service_break_minutes');

    return [
        'consultation_duration' => max(1, (int) ($serviceDuration ?? $staffDuration ?? (int) ($calendar['consultation_duration'] ?? 60))),
        'consultation_break' => max(0, (int) ($serviceBreak ?? $staffBreak ?? (int) ($calendar['consultation_break'] ?? 0))),
    ];
}

function booking_staff_slots_from_availability(array $availability, array $settings, string $date): array
{
    $duration = max(1, (int) ($settings['consultation_duration'] ?? 60));
    $break = max(0, (int) ($settings['consultation_break'] ?? 0));
    $timestamp = strtotime($date . ' 00:00:00');
    $weekday = $timestamp !== false ? (int) date('N', $timestamp) : null;
    $slots = [];

    foreach ($availability as $entry) {
        if (!is_array($entry) || empty($entry['is_active'])) {
            continue;
        }

        if ($weekday !== null && (int) ($entry['weekday'] ?? 0) !== $weekday) {
            continue;
        }

        $start = booking_staff_normalize_time((string) ($entry['start_time'] ?? ''));
        $end = booking_staff_normalize_time((string) ($entry['end_time'] ?? ''));

        if ($start === '' || $end === '') {
            continue;
        }

        $current = booking_staff_time_to_minutes($start);
        $endMinutes = booking_staff_time_to_minutes($end);

        while ($current + $duration <= $endMinutes) {
            $slots[] = sprintf('%02d:%02d', intdiv($current, 60), $current % 60);
            $current += $duration + $break;
        }
    }

    $slots = array_values(array_unique($slots));
    sort($slots);

    return $slots;
}

function booking_staff_service_relation_exists(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    string $serviceId,
    string $staffId
): bool {
    $row = booking_staff_fetch_single($supabaseUrl, $key, $schema, 'tenant_service_staff', [
        'select=staff_id',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'service_id=eq.' . rawurlencode($serviceId),
        'staff_id=eq.' . rawurlencode($staffId),
    ]);

    return is_array($row);
}

function booking_staff_service_settings_map(
    array $bookings,
    string $tenantId,
    string $supabaseUrl,
    string $key,
    string $schema,
    array $staff,
    array $calendar
): array {
    $serviceIds = [];

    foreach ($bookings as $booking) {
        if (!is_array($booking) || empty($booking['service_id'])) {
            continue;
        }

        $serviceIds[(string) $booking['service_id']] = true;
    }

    if (empty($serviceIds)) {
        return [];
    }

    $rows = booking_staff_fetch_rows($supabaseUrl, $key, $schema, 'tenant_services', [
        'select=id,duration_minutes,break_minutes',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'id=in.(' . implode(',', array_map('rawurlencode', array_keys($serviceIds))) . ')',
    ]);

    $settingsByService = [];

    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }

        $settingsByService[(string) $row['id']] = booking_staff_effective_settings($row, $staff, $calendar);
    }

    return $settingsByService;
}

function booking_staff_occupied_intervals(array $bookings, array $settingsByService, array $fallbackSettings): array
{
    $intervals = [];

    foreach ($bookings as $booking) {
        if (!is_array($booking)) {
            continue;
        }

        $time = booking_staff_normalize_time((string) ($booking['booking_time'] ?? ''));

        if ($time === '') {
            continue;
        }

        $serviceId = trim((string) ($booking['service_id'] ?? ''));
        $settings = isset($settingsByService[$serviceId]) && is_array($settingsByService[$serviceId])
            ? $settingsByService[$serviceId]
            : $fallbackSettings;
        $start = booking_staff_time_to_minutes($time);

        $intervals[] = [
            'start' => $start,
            'end' => booking_staff_interval_end($start, $settings),
        ];
    }

    return $intervals;
}

function booking_staff_slot_is_free(string $time, array $candidateSettings, array $occupiedIntervals): bool
{
    $candidateStart = booking_staff_time_to_minutes($time);
    $candidateEnd = booking_staff_interval_end($candidateStart, $candidateSettings);

    foreach ($occupiedIntervals as $interval) {
        if (!is_array($interval)) {
            continue;
        }

        if (booking_staff_ranges_overlap(
            $candidateStart,
            $candidateEnd,
            (int) ($interval['start'] ?? 0),
            (int) ($interval['end'] ?? 0)
        )) {
            return false;
        }
    }

    return true;
}

function booking_staff_blocked_time_overlaps(string $time, array $candidateSettings, array $blockedTimes): bool
{
    $candidateStart = booking_staff_time_to_minutes($time);
    $candidateEnd = booking_staff_interval_end($candidateStart, $candidateSettings);

    foreach ($blockedTimes as $blockedTime) {
        $blockTime = booking_staff_normalize_time((string) $blockedTime);

        if ($blockTime === '') {
            continue;
        }

        $blockStart = booking_staff_time_to_minutes($blockTime);
        $blockEnd = $blockStart + 60;

        if (booking_staff_ranges_overlap($candidateStart, $candidateEnd, $blockStart, $blockEnd)) {
            return true;
        }
    }

    return false;
}

function booking_staff_assert_staff_available_for_booking(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $tenantId,
    array $booking,
    array $staff,
    string $staffId
): void {
    $date = trim((string) ($booking['booking_date'] ?? ''));
    $time = booking_staff_normalize_time((string) ($booking['booking_time'] ?? ''));
    $bookingId = trim((string) ($booking['id'] ?? ''));
    $serviceId = trim((string) ($booking['service_id'] ?? ''));

    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $time === '') {
        booking_staff_json([
            'success' => false,
            'error' => 'Rezerwacja ma nieprawidłowy termin. Nie można zweryfikować dostępności pracownika.'
        ], 409);
    }

    $service = [];

    if ($serviceId !== '' && booking_staff_is_uuid($serviceId)) {
        $service = booking_staff_fetch_single($supabaseUrl, $key, $schema, 'tenant_services', [
            'select=id,duration_minutes,break_minutes',
            'tenant_id=eq.' . rawurlencode($tenantId),
            'id=eq.' . rawurlencode($serviceId),
        ]) ?? [];

        if (!booking_staff_service_relation_exists($supabaseUrl, $key, $schema, $tenantId, $serviceId, $staffId)) {
            booking_staff_security_event(
                'booking_staff_update_staff_service_denied',
                'staff_not_assigned_to_service',
                409,
                'failed',
                'medium',
                $tenantId,
                $staffId
            );

            booking_staff_json([
                'success' => false,
                'code' => 'staff_not_assigned_to_service',
                'error' => 'Wybrany pracownik nie obsługuje tej usługi.'
            ], 409);
        }
    }

    $calendar = booking_staff_fetch_single($supabaseUrl, $key, $schema, 'calendar_settings', [
        'select=consultation_duration,consultation_break',
        'tenant_id=eq.' . rawurlencode($tenantId),
    ]) ?? [];

    $settings = booking_staff_effective_settings(
        is_array($service) ? $service : [],
        $staff,
        is_array($calendar) ? $calendar : []
    );

    $availability = booking_staff_fetch_rows($supabaseUrl, $key, $schema, 'staff_availability', [
        'select=weekday,start_time,end_time,is_active',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'staff_id=eq.' . rawurlencode($staffId),
        'is_active=eq.true',
        'order=weekday.asc',
        'order=start_time.asc',
    ]);

    $slots = booking_staff_slots_from_availability($availability, $settings, $date);

    if (!in_array($time, $slots, true)) {
        booking_staff_security_event(
            'booking_staff_update_staff_unavailable',
            'staff_outside_schedule',
            409,
            'failed',
            'medium',
            $tenantId,
            $staffId
        );

        booking_staff_json([
            'success' => false,
            'code' => 'staff_outside_schedule',
            'error' => 'Wybrany pracownik nie jest dostępny w tym dniu i o tej godzinie według swojego grafiku.'
        ], 409);
    }

    $staffBlockFilter = '&or=(staff_id.is.null,staff_id.eq.' . rawurlencode($staffId) . ')';

    $blockedDate = booking_staff_fetch_single($supabaseUrl, $key, $schema, 'blocked_dates', [
        'select=date',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'date=eq.' . rawurlencode($date) . $staffBlockFilter,
    ]);

    if (is_array($blockedDate)) {
        booking_staff_security_event(
            'booking_staff_update_staff_unavailable',
            'staff_date_blocked',
            409,
            'failed',
            'medium',
            $tenantId,
            $staffId
        );

        booking_staff_json([
            'success' => false,
            'code' => 'staff_date_blocked',
            'error' => 'Wybrany pracownik ma zablokowany ten dzień albo dzień jest zablokowany globalnie.'
        ], 409);
    }

    $blockedTimes = booking_staff_fetch_rows($supabaseUrl, $key, $schema, 'blocked_times', [
        'select=time,staff_id',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'date=eq.' . rawurlencode($date) . $staffBlockFilter,
    ]);

    $globalBlockedTimes = [];
    $staffBlockedTimes = [];

    foreach ($blockedTimes as $row) {
        if (!is_array($row) || empty($row['time'])) {
            continue;
        }

        $blockedTime = booking_staff_normalize_time((string) $row['time']);

        if (trim((string) $row['time']) === 'all') {
            booking_staff_security_event(
                'booking_staff_update_staff_unavailable',
                'staff_time_blocked',
                409,
                'failed',
                'medium',
                $tenantId,
                $staffId
            );

            booking_staff_json([
                'success' => false,
                'code' => 'staff_time_blocked',
                'error' => 'Wybrany pracownik ma zablokowany cały dzień albo dzień jest zablokowany globalnie.'
            ], 409);
        }

        if ($blockedTime === '') {
            continue;
        }

        if (trim((string) ($row['staff_id'] ?? '')) === '') {
            $globalBlockedTimes[] = $blockedTime;
        } else {
            $staffBlockedTimes[] = $blockedTime;
        }
    }

    if (booking_staff_blocked_time_overlaps($time, $settings, $globalBlockedTimes)
        || booking_staff_blocked_time_overlaps($time, $settings, $staffBlockedTimes)
    ) {
        booking_staff_security_event(
            'booking_staff_update_staff_unavailable',
            'staff_time_blocked',
            409,
            'failed',
            'medium',
            $tenantId,
            $staffId
        );

        booking_staff_json([
            'success' => false,
            'code' => 'staff_time_blocked',
            'error' => 'Wybrany pracownik ma blokadę w tym terminie. Wybierz innego pracownika albo zmień termin rezerwacji.'
        ], 409);
    }

    $bookingQuery = [
        'select=id,booking_time,service_id',
        'tenant_id=eq.' . rawurlencode($tenantId),
        'staff_id=eq.' . rawurlencode($staffId),
        'booking_date=eq.' . rawurlencode($date),
    ];

    if ($bookingId !== '' && booking_staff_is_uuid($bookingId)) {
        $bookingQuery[] = 'id=neq.' . rawurlencode($bookingId);
    }

    $bookings = booking_staff_fetch_rows($supabaseUrl, $key, $schema, 'bookings', $bookingQuery);
    $settingsByService = booking_staff_service_settings_map(
        $bookings,
        $tenantId,
        $supabaseUrl,
        $key,
        $schema,
        $staff,
        is_array($calendar) ? $calendar : []
    );
    $occupiedIntervals = booking_staff_occupied_intervals($bookings, $settingsByService, $settings);

    if (!booking_staff_slot_is_free($time, $settings, $occupiedIntervals)) {
        booking_staff_security_event(
            'booking_staff_update_staff_conflict',
            'staff_booking_conflict',
            409,
            'failed',
            'medium',
            $tenantId,
            $staffId
        );

        booking_staff_json([
            'success' => false,
            'code' => 'staff_booking_conflict',
            'error' => 'Wybrany pracownik ma już rezerwację kolidującą z tym terminem.'
        ], 409);
    }
}


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    booking_staff_security_event(
        'booking_staff_update_method_not_allowed',
        'method_not_allowed',
        405
    );

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
    booking_staff_security_event(
        'booking_staff_update_env_missing',
        'env_missing',
        500,
        'error',
        'high'
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Nie udało się wczytać konfiguracji systemu.'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    booking_staff_security_event(
        'booking_staff_update_tenant_denied',
        'tenant_mismatch',
        403,
        'denied',
        'high'
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($adminUser['tenant_id'] ?? '');
$adminUserId = (string) ($adminUser['id'] ?? '');

if ($tenantId === '') {
    booking_staff_security_event(
        'booking_staff_update_session_invalid',
        'invalid_session',
        401
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

$rawInput = file_get_contents('php://input');
$input = json_decode((string) $rawInput, true);

if (!is_array($input)) {
    booking_staff_security_event(
        'booking_staff_update_invalid_json',
        'invalid_json',
        400,
        'failed',
        'medium',
        $tenantId
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], 400);
}

$action = trim((string) ($input['action'] ?? ''));
$bookingRef = trim((string) ($input['booking_ref'] ?? ''));
$bookingDateHint = trim((string) ($input['booking_date'] ?? ''));
$bookingTimeHint = trim((string) ($input['booking_time'] ?? ''));
$newStaffRef = trim((string) ($input['staff_ref'] ?? ''));
$newStaffId = '';

if (!in_array($action, ['change_staff', 'detach_staff'], true)) {
    booking_staff_security_event(
        'booking_staff_update_action_invalid',
        'action_invalid',
        400,
        'failed',
        'medium',
        $tenantId
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Nieprawidłowa akcja'
    ], 400);
}

if ($bookingRef === '') {
    booking_staff_security_event(
        'booking_staff_update_booking_ref_missing',
        'booking_ref_missing',
        400,
        'failed',
        'medium',
        $tenantId
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Brak identyfikatora rezerwacji'
    ], 400);
}

$booking = booking_staff_resolve_booking_by_ref(
    $supabaseUrl,
    $supabaseKey,
    $schema,
    $tenantId,
    $bookingRef,
    $bookingDateHint,
    $bookingTimeHint
);

if (!$booking) {
    booking_staff_security_event(
        'booking_staff_update_booking_not_found',
        'booking_not_found',
        404,
        'failed',
        'medium',
        $tenantId
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Nie znaleziono rezerwacji'
    ], 404);
}

$bookingId = trim((string) ($booking['id'] ?? ''));

if ($bookingId === '' || !booking_staff_is_uuid($bookingId)) {
    booking_staff_security_event(
        'booking_staff_update_booking_invalid',
        'booking_invalid',
        500,
        'error',
        'high',
        $tenantId
    );

    booking_staff_json([
        'success' => false,
        'error' => 'Nieprawidłowa rezerwacja'
    ], 500);
}

$newStaff = null;

if ($action === 'change_staff') {
    if ($newStaffRef === '') {
        booking_staff_security_event(
            'booking_staff_update_staff_ref_missing',
            'staff_ref_missing',
            400,
            'failed',
            'medium',
            $tenantId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Wybierz poprawnego pracownika'
        ], 400);
    }

    $newStaff = booking_staff_resolve_staff_by_ref(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $tenantId,
        $newStaffRef
    );

    if (!$newStaff) {
        booking_staff_security_event(
            'booking_staff_update_staff_not_found',
            'staff_not_found',
            404,
            'failed',
            'medium',
            $tenantId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nie znaleziono wybranego pracownika'
        ], 404);
    }

    $newStaffId = trim((string) ($newStaff['id'] ?? ''));

    if ($newStaffId === '' || !booking_staff_is_uuid($newStaffId)) {
        booking_staff_security_event(
            'booking_staff_update_staff_invalid',
            'staff_invalid',
            500,
            'error',
            'high',
            $tenantId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nieprawidłowy pracownik'
        ], 500);
    }
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

$newStaffName = '';

if ($action === 'change_staff') {
    if ($oldStaffId !== '' && strtolower($oldStaffId) === strtolower($newStaffId)) {
        booking_staff_security_event(
            'booking_staff_update_noop_conflict',
            'same_staff',
            409,
            'failed',
            'medium',
            $tenantId,
            $newStaffId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Ten pracownik jest już przypisany do tej rezerwacji'
        ], 409);
    }

    if (!$newStaff) {
        $newStaff = booking_staff_fetch_single($supabaseUrl, $supabaseKey, $schema, 'staff_profiles', [
            'select=id,display_name,email,is_active,service_duration_minutes,service_break_minutes',
            'tenant_id=eq.' . rawurlencode($tenantId),
            'id=eq.' . rawurlencode($newStaffId),
        ]);
    }

    if (!$newStaff) {
        booking_staff_security_event(
            'booking_staff_update_staff_not_found',
            'staff_not_found',
            404,
            'failed',
            'medium',
            $tenantId,
            $newStaffId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nie znaleziono wybranego pracownika'
        ], 404);
    }

    if (($newStaff['is_active'] ?? false) !== true) {
        booking_staff_security_event(
            'booking_staff_update_staff_inactive',
            'staff_inactive',
            409,
            'failed',
            'medium',
            $tenantId,
            $newStaffId
        );

        booking_staff_json([
            'success' => false,
            'error' => 'Nie można przypisać nieaktywnego pracownika'
        ], 409);
    }

    $newStaffName = booking_staff_text((string) ($newStaff['display_name'] ?? ''), 'Nowy specjalista');

    booking_staff_assert_staff_available_for_booking(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $tenantId,
        $booking,
        $newStaff,
        $newStaffId
    );
}

if ($action === 'detach_staff' && $oldStaffId === '') {
    booking_staff_security_event(
        'booking_staff_update_noop_conflict',
        'no_staff_to_detach',
        409,
        'failed',
        'medium',
        $tenantId
    );

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

booking_staff_security_event(
    $action === 'change_staff' ? 'booking_staff_update_success' : 'booking_staff_detach_success',
    $action === 'change_staff' ? 'booking_staff_update_success' : 'booking_staff_detach_success',
    200,
    'success',
    'medium',
    $tenantId,
    $action === 'change_staff' ? $newStaffId : $oldStaffId
);

booking_staff_json([
    'success' => true,
    'mail_sent' => $mailSent,
    'message' => $action === 'change_staff'
        ? 'Zmieniono pracownika przypisanego do rezerwacji.'
        : 'Odłączono pracownika od rezerwacji.'
]);