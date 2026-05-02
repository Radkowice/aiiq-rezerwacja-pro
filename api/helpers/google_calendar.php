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

function google_calendar_update_booking_event_id(string $bookingId, string $googleEventId): bool
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

function google_calendar_build_event(array $booking, array $settings): array
{
    $name = trim((string) ($booking['name'] ?? ''));
    $email = trim((string) ($booking['email'] ?? ''));
    $phone = trim((string) ($booking['phone'] ?? ''));
    $notes = trim((string) ($booking['notes'] ?? ''));

    $date = trim((string) ($booking['booking_date'] ?? ''));
    $time = trim((string) ($booking['booking_time'] ?? ''));

    $timezone = trim((string) ($settings['timezone'] ?? 'Europe/Warsaw'));
    $durationMinutes = (int) ($settings['duration_minutes'] ?? 60);

    if ($durationMinutes < 5) {
        $durationMinutes = 60;
    }

    $start = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone($timezone));
    $end = $start->modify('+' . $durationMinutes . ' minutes');

    $descriptionLines = [
        'Rezerwacja z AI-IQ Rezerwacja Pro',
        '',
        'Imię i nazwisko: ' . ($name !== '' ? $name : '-'),
        'E-mail: ' . ($email !== '' ? $email : '-'),
        'Telefon: ' . ($phone !== '' ? $phone : '-'),
    ];

    if ($notes !== '') {
        $descriptionLines[] = '';
        $descriptionLines[] = 'Notatki:';
        $descriptionLines[] = $notes;
    }

    return [
        'summary' => 'Rezerwacja: ' . ($name !== '' ? $name : 'Klient'),
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