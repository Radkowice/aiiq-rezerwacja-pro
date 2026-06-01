<?php

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/crypto.php';

/**
 * Google Calendar helper.
 *
 * Zasada:
 * - aplikacja jest źródłem prawdy,
 * - Google Calendar jest tylko dodatkowym miejscem zapisu wydarzenia,
 * - błąd Google nie może przerwać rezerwacji.
 */
 
 if (!function_exists('google_calendar_debug')) {
    function google_calendar_debug(string $tag, $data = null): void
    {
        $logFile = __DIR__ . '/../data/google-calendar.log';

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $line = date('Y-m-d H:i:s') . ' [' . $tag . ']';

        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $line .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $line .= ' ' . (string) $data;
            }
        }

        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    }
}
  
function google_calendar_supabase_request(
    string $url,
    string $method,
    string $key,
    string $schema,
    ?array $body = null,
    array $extraHeaders = []
): array {
    $headers = array_merge(
        supabaseHeaders($key, $schema),
        [
            'Accept: application/json',
        ],
        $extraHeaders
    );

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $curlError,
        'data' => json_decode((string) $response, true),
    ];
}

function google_calendar_http_request(
    string $url,
    string $method,
    array $headers = [],
    ?array $body = null
): array {
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $curlError,
        'data' => json_decode((string) $response, true),
    ];
}

function google_calendar_get_integration(string $tenantId): ?array
{
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/tenant_integrations'
        . '?select=tenant_id,provider,enabled,mode,settings,secrets,connected_at,disconnected_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&provider=eq.google_calendar'
        . '&limit=1';

    $result = google_calendar_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        return null;
    }

    $row = $result['data'][0] ?? null;

    if (!$row || empty($row['enabled'])) {
        return null;
    }

    $settings = is_array($row['settings'] ?? null) ? $row['settings'] : [];
    $storedSecrets = is_array($row['secrets'] ?? null) ? $row['secrets'] : [];

    try {
        $secrets = decrypt_json_secret($storedSecrets);
    } catch (Throwable $e) {
        return null;
    }

    if (empty($secrets['access_token']) && empty($secrets['refresh_token'])) {
        return null;
    }

    return [
        'settings' => $settings,
        'secrets' => $secrets,
    ];
}

function google_calendar_save_secrets(string $tenantId, array $settings, array $secrets): bool
{
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        return false;
    }

    try {
        $encryptedSecrets = encrypt_json_secret($secrets);
    } catch (Throwable $e) {
        return false;
    }

    $payload = [
        'tenant_id' => $tenantId,
        'provider' => 'google_calendar',
        'enabled' => true,
        'mode' => 'production',
        'settings' => $settings,
        'secrets' => $encryptedSecrets,
        'connected_at' => date('c'),
        'disconnected_at' => null,
    ];

    $url = $supabaseUrl
        . '/rest/v1/tenant_integrations'
        . '?on_conflict=tenant_id,provider';

    $result = google_calendar_supabase_request(
        $url,
        'POST',
        $supabaseKey,
        $schema,
        $payload,
        [
            'Prefer: resolution=merge-duplicates,return=minimal',
        ]
    );

    return !$result['error'] && $result['http_code'] >= 200 && $result['http_code'] < 300;
}

function google_calendar_refresh_access_token_if_needed(
    string $tenantId,
    array $settings,
    array $secrets
): ?array {
    $accessToken = (string) ($secrets['access_token'] ?? '');
    $refreshToken = (string) ($secrets['refresh_token'] ?? '');
    $tokenExpiresAt = strtotime((string) ($secrets['token_expires_at'] ?? ''));

    if ($accessToken !== '' && $tokenExpiresAt && $tokenExpiresAt > (time() + 120)) {
        return $secrets;
    }

    if ($refreshToken === '') {
        return null;
    }

    $googleClientId = trim((string) getenv('GOOGLE_CLIENT_ID'));
    $googleClientSecret = trim((string) getenv('GOOGLE_CLIENT_SECRET'));

    if ($googleClientId === '' || $googleClientSecret === '') {
        return null;
    }

    $payload = http_build_query([
        'client_id' => $googleClientId,
        'client_secret' => $googleClientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $data = json_decode((string) $response, true);

    if (!is_array($data) || empty($data['access_token'])) {
        return null;
    }

    $expiresIn = (int) ($data['expires_in'] ?? 3600);
    $newExpiresAt = (new DateTimeImmutable('+' . max(60, $expiresIn - 60) . ' seconds'))
        ->format(DateTimeInterface::ATOM);

    $updatedSecrets = array_merge($secrets, [
        'access_token' => (string) $data['access_token'],
        'token_expires_at' => $newExpiresAt,
    ]);

    google_calendar_save_secrets($tenantId, $settings, $updatedSecrets);

    return $updatedSecrets;
}

function google_calendar_update_booking_event_id(string $bookingId, string $googleEventId, string $tenantId = ''): bool
{
    if ($bookingId === '' || $googleEventId === '') {
        return false;
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        return false;
    }

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?id=eq.' . rawurlencode($bookingId);

    if ($tenantId !== '') {
        $url .= '&tenant_id=eq.' . rawurlencode($tenantId);
    }

    $result = google_calendar_supabase_request(
        $url,
        'PATCH',
        $supabaseKey,
        $schema,
        [
            'google_event_id' => $googleEventId,
        ],
        [
            'Prefer: return=minimal',
        ]
    );

    return !$result['error'] && $result['http_code'] >= 200 && $result['http_code'] < 300;
}

function google_calendar_string_value(array $data, string $key): string
{
    $value = $data[$key] ?? '';

    if (is_array($value) || is_object($value)) {
        return '';
    }

    return trim((string) $value);
}

function google_calendar_payment_status_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'paid', 'completed', 'success' => 'Opłacona',
        'pending', 'waiting' => 'Oczekuje na płatność',
        'unpaid', 'new' => 'Nieopłacona',
        'cancelled', 'canceled' => 'Anulowana',
        'failed', 'error' => 'Nieudana',
        'expired' => 'Wygasła',
        'refunded' => 'Zwrócona',
        '', 'not_required', 'no_payment' => 'Nie dotyczy',
        default => $status,
    };
}

function google_calendar_format_amount($amount, string $currency): string
{
    if ($amount === null || $amount === '' || !is_numeric($amount)) {
        return '';
    }

    $currency = trim($currency) !== '' ? trim($currency) : 'PLN';

    return number_format((float) $amount, 2, ',', ' ') . ' ' . $currency;
}

function google_calendar_build_event(array $booking, array $settings): array
{
    $bookingId = google_calendar_string_value($booking, 'id');
    $name = google_calendar_string_value($booking, 'name');
    $email = google_calendar_string_value($booking, 'email');
    $phone = google_calendar_string_value($booking, 'phone');
    $notes = google_calendar_string_value($booking, 'notes');
    $serviceName = google_calendar_string_value($booking, 'service_name_snapshot');
    $staffDisplayName = google_calendar_string_value($booking, 'staff_display_name');
    $paymentStatus = google_calendar_string_value($booking, 'payment_status');
    $paymentCurrency = google_calendar_string_value($booking, 'payment_currency');
    $paymentRequired = filter_var($booking['payment_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $paymentAmount = $paymentRequired
        ? google_calendar_format_amount($booking['payment_amount'] ?? null, $paymentCurrency)
        : '';

    $date = google_calendar_string_value($booking, 'booking_date');
    $time = google_calendar_string_value($booking, 'booking_time');

    $timezone = trim((string) ($settings['timezone'] ?? 'Europe/Warsaw'));
    $bookingDuration = $booking['duration_minutes'] ?? null;
    $durationMinutes = is_numeric($bookingDuration) && (int) $bookingDuration > 0
        ? (int) $bookingDuration
        : (int) ($settings['duration_minutes'] ?? 60);

    if ($durationMinutes < 1) {
        $durationMinutes = 60;
    }

    $start = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone($timezone));
    $end = $start->modify('+' . $durationMinutes . ' minutes');
    $termin = trim($date . ' ' . $time);
    $summaryName = $name !== '' ? $name : 'Klient';
    $summary = $serviceName !== ''
        ? 'Rezerwacja: ' . $serviceName . ' - ' . $summaryName
        : 'Rezerwacja: ' . $summaryName;

    $descriptionLines = [
        'Rezerwacja z AI-IQ Rezerwacja Pro',
        '',
        'Rezerwacja:',
    ];

    if ($serviceName !== '') {
        $descriptionLines[] = 'Usługa: ' . $serviceName;
    }

    if ($staffDisplayName !== '') {
        $descriptionLines[] = 'Osoba obsługująca: ' . $staffDisplayName;
    }

    if ($termin !== '') {
        $descriptionLines[] = 'Termin: ' . $termin;
    }

    if ($paymentStatus !== '') {
        $descriptionLines[] = 'Status płatności: ' . google_calendar_payment_status_label($paymentStatus);
    }

    if ($paymentAmount !== '') {
        $descriptionLines[] = 'Kwota: ' . $paymentAmount;
    }

    $previousDateLabel = google_calendar_string_value($booking, 'previous_date_label');
    $newDateLabel = google_calendar_string_value($booking, 'new_date_label');
    $rescheduleCount = google_calendar_string_value($booking, 'reschedule_count');
    $rescheduleLimit = google_calendar_string_value($booking, 'reschedule_limit');

    if ($previousDateLabel !== '' || $newDateLabel !== '' || $rescheduleCount !== '') {
        $descriptionLines[] = '';
        $descriptionLines[] = 'Zmiana terminu:';

        if ($previousDateLabel !== '') {
            $descriptionLines[] = 'Poprzedni termin: ' . $previousDateLabel;
        }

        if ($newDateLabel !== '') {
            $descriptionLines[] = 'Nowy termin: ' . $newDateLabel;
        }

        if ($rescheduleCount !== '') {
            $countText = $rescheduleCount;

            if ($rescheduleLimit !== '') {
                $countText .= '/' . $rescheduleLimit;
            }

            $descriptionLines[] = 'Liczba zmian: ' . $countText;
        }
    }

    $descriptionLines[] = '';
    $descriptionLines[] = 'Klient:';
    $descriptionLines = array_merge($descriptionLines, [
        'Imię i nazwisko: ' . ($name !== '' ? $name : '-'),
        'E-mail: ' . ($email !== '' ? $email : '-'),
        'Telefon: ' . ($phone !== '' ? $phone : '-'),
    ]);

    if ($bookingId !== '') {
        $descriptionLines[] = '';
        $descriptionLines[] = 'Dane techniczne:';
        $descriptionLines[] = 'ID rezerwacji: ' . $bookingId;
    }

    if ($notes !== '') {
        $descriptionLines[] = '';
        $descriptionLines[] = 'Notatki:';
        $descriptionLines[] = $notes;
    }

    return [
        'summary' => $summary,
        'description' => implode("\n", $descriptionLines),
        'start' => [
            'dateTime' => $start->format(DateTimeInterface::ATOM),
            'timeZone' => $timezone,
        ],
        'end' => [
            'dateTime' => $end->format(DateTimeInterface::ATOM),
            'timeZone' => $timezone,
        ],
    ];
}

function createGoogleCalendarEventForBooking(string $tenantId, array $booking): ?string
{
   
    $integration = google_calendar_get_integration($tenantId);

    if (!$integration) {
        google_calendar_debug('NO_INTEGRATION_OR_DISABLED', $tenantId);
        return null;
    }

    google_calendar_debug('INTEGRATION_FOUND', [
        'settings' => $integration['settings'] ?? [],
        'has_access_token' => !empty($integration['secrets']['access_token'] ?? ''),
        'has_refresh_token' => !empty($integration['secrets']['refresh_token'] ?? ''),
        'token_expires_at' => $integration['secrets']['token_expires_at'] ?? null,
    ]);

    $settings = $integration['settings'];
    $secrets = $integration['secrets'];

    $secrets = google_calendar_refresh_access_token_if_needed($tenantId, $settings, $secrets);

    if (!$secrets || empty($secrets['access_token'])) {
        google_calendar_debug('NO_ACCESS_TOKEN_AFTER_REFRESH');
        return null;
    }

    $calendarId = trim((string) ($settings['calendar_id'] ?? 'primary'));

    if ($calendarId === '') {
        $calendarId = 'primary';
    }

    google_calendar_debug('CALENDAR_ID', $calendarId);

    try {
        $event = google_calendar_build_event($booking, $settings);
        google_calendar_debug('EVENT_PAYLOAD', $event);
    } catch (Throwable $e) {
        google_calendar_debug('BUILD_EVENT_ERROR', $e->getMessage());
        return null;
    }

    $url = 'https://www.googleapis.com/calendar/v3/calendars/'
        . rawurlencode($calendarId)
        . '/events';

    $result = google_calendar_http_request(
        $url,
        'POST',
        [
            'Authorization: Bearer ' . $secrets['access_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        $event
    );

    google_calendar_debug('GOOGLE_RESPONSE', [
        'http_code' => $result['http_code'] ?? null,
        'error' => $result['error'] ?? null,
        'response' => $result['response'] ?? null,
    ]);

    if ($result['error'] || $result['http_code'] < 200 || $result['http_code'] >= 300) {
        return null;
    }

    $googleEventId = (string) ($result['data']['id'] ?? '');

    google_calendar_debug('GOOGLE_EVENT_ID', $googleEventId);

    return $googleEventId !== '' ? $googleEventId : null;
}

function updateGoogleCalendarEventForBooking(string $tenantId, string $googleEventId, array $booking): array
{
    $googleEventId = trim($googleEventId);

    if ($tenantId === '' || $googleEventId === '') {
        return [
            'success' => false,
            'not_found' => false,
            'status_code' => 0,
        ];
    }

    $integration = google_calendar_get_integration($tenantId);

    if (!$integration) {
        google_calendar_debug('UPDATE_NO_INTEGRATION_OR_DISABLED', $tenantId);
        return [
            'success' => false,
            'not_found' => false,
            'status_code' => 0,
        ];
    }

    $settings = $integration['settings'];
    $secrets = google_calendar_refresh_access_token_if_needed($tenantId, $settings, $integration['secrets']);

    if (!$secrets || empty($secrets['access_token'])) {
        google_calendar_debug('UPDATE_NO_ACCESS_TOKEN_AFTER_REFRESH');
        return [
            'success' => false,
            'not_found' => false,
            'status_code' => 0,
        ];
    }

    $calendarId = trim((string) ($settings['calendar_id'] ?? 'primary'));

    if ($calendarId === '') {
        $calendarId = 'primary';
    }

    try {
        $event = google_calendar_build_event($booking, $settings);
        google_calendar_debug('UPDATE_EVENT_PAYLOAD', [
            'event_id' => $googleEventId,
            'event' => $event,
        ]);
    } catch (Throwable $e) {
        google_calendar_debug('UPDATE_BUILD_EVENT_ERROR', $e->getMessage());
        return [
            'success' => false,
            'not_found' => false,
            'status_code' => 0,
        ];
    }

    $url = 'https://www.googleapis.com/calendar/v3/calendars/'
        . rawurlencode($calendarId)
        . '/events/'
        . rawurlencode($googleEventId);

    $result = google_calendar_http_request(
        $url,
        'PATCH',
        [
            'Authorization: Bearer ' . $secrets['access_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        $event
    );

    google_calendar_debug('UPDATE_GOOGLE_RESPONSE', [
        'event_id' => $googleEventId,
        'http_code' => $result['http_code'] ?? null,
        'error' => $result['error'] ?? null,
        'response' => $result['response'] ?? null,
    ]);

    $statusCode = (int) ($result['http_code'] ?? 0);

    return [
        'success' => !$result['error'] && $statusCode >= 200 && $statusCode < 300,
        'not_found' => $statusCode === 404,
        'status_code' => $statusCode,
    ];
}
