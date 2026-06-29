<?php

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/public_response.php';

/**
 * Google Calendar helper.
 *
 * Zasada:
 * - aplikacja jest źródłem prawdy,
 * - Google Calendar jest tylko dodatkowym miejscem zapisu wydarzenia,
 * - błąd Google nie może przerwać rezerwacji.
 */
 

if (!function_exists('google_calendar_trace_value')) {
    function google_calendar_trace_value($value): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return '';
        }

        return substr(hash('sha256', $text), 0, 16);
    }
}

if (!function_exists('google_calendar_is_sensitive_debug_key')) {
    function google_calendar_is_sensitive_debug_key(string $key): bool
    {
        $key = strtolower(trim($key));

        if ($key === '') {
            return false;
        }

        if (str_starts_with($key, 'has_') || str_ends_with($key, '_ref') || str_ends_with($key, '_trace')) {
            return false;
        }

        return preg_match('/(^|_)(id|tenant_id|booking_id|service_id|staff_id|user_id|company_id|payment_order_id|google_event_id|calendar_id|token|secret|key|authorization|bearer)(_|$)/', $key) === 1
            || in_array($key, [
                'id',
                'tenant_id',
                'booking_id',
                'service_id',
                'staff_id',
                'user_id',
                'company_id',
                'payment_order_id',
                'google_event_id',
                'calendar_id',
                'access_token',
                'refresh_token',
                'client_secret',
                'apikey',
                'authorization',
            ], true);
    }
}

if (!function_exists('google_calendar_sanitize_debug_data')) {
    function google_calendar_sanitize_debug_data($data, string $parentKey = '')
    {
        if (is_array($data)) {
            $sanitized = [];

            foreach ($data as $key => $value) {
                $keyString = is_string($key) ? $key : (string) $key;

                if (google_calendar_is_sensitive_debug_key($keyString)) {
                    $sanitized[$keyString . '_set'] = !empty($value);

                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $sanitized[$keyString . '_trace'] = google_calendar_trace_value($value);
                    }

                    continue;
                }

                $sanitized[$key] = google_calendar_sanitize_debug_data($value, $keyString);
            }

            return $sanitized;
        }

        if (is_object($data)) {
            return google_calendar_sanitize_debug_data(get_object_vars($data), $parentKey);
        }

        if (google_calendar_is_sensitive_debug_key($parentKey)) {
            if (!is_scalar($data) || trim((string) $data) === '') {
                return null;
            }

            return [
                'set' => true,
                'trace' => google_calendar_trace_value($data),
            ];
        }

        return $data;
    }
}

if (!function_exists('google_calendar_sanitize_url_for_record')) {
    function google_calendar_sanitize_url_for_record(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '[invalid-url]';
        }

        $safeUrl = '';

        if (!empty($parts['scheme'])) {
            $safeUrl .= $parts['scheme'] . '://';
        }

        if (!empty($parts['host'])) {
            $safeUrl .= $parts['host'];
        }

        if (!empty($parts['port'])) {
            $safeUrl .= ':' . $parts['port'];
        }

        $safeUrl .= $parts['path'] ?? '';

        $query = $parts['query'] ?? '';

        if ($query !== '') {
            $queryParts = [];
            parse_str($query, $queryParts);

            foreach ($queryParts as $key => $value) {
                $keyString = (string) $key;

                if (google_calendar_is_sensitive_debug_key($keyString)) {
                    $queryParts[$key] = '[redacted]';
                }
            }

            $safeQuery = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);

            if ($safeQuery !== '') {
                $safeUrl .= '?' . $safeQuery;
            }
        }

        return $safeUrl;
    }
}

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
            $safeData = google_calendar_sanitize_debug_data($data);

            if (is_array($safeData) || is_object($safeData)) {
                $line .= ' ' . json_encode($safeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $line .= ' ' . (string) $safeData;
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

    if (function_exists('booking_supabase_request_record')) {
        booking_supabase_request_record($method, google_calendar_sanitize_url_for_record($url), 'google_calendar', $httpCode);
    }

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

function google_calendar_execution_result(string $status, string $reason = '', array $context = []): array
{
    $result = ['status' => $status];

    if ($reason !== '') {
        $result['reason'] = $reason;
    }

    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $result[$key] = $value;
        }
    }

    return $result;
}

function google_calendar_is_temporary_http_failure(array $result): bool
{
    $httpCode = (int) ($result['http_code'] ?? 0);

    return !empty($result['error'])
        || $httpCode === 0
        || $httpCode === 408
        || $httpCode === 429
        || $httpCode >= 500;
}

function google_calendar_api_error_code(array $data): string
{
    $error = $data['error'] ?? '';

    if (is_string($error)) {
        return strtolower(trim($error));
    }

    if (!is_array($error)) {
        return '';
    }

    foreach (['status', 'reason', 'code'] as $key) {
        $value = $error[$key] ?? '';

        if (is_scalar($value) && trim((string) $value) !== '') {
            return strtolower(trim((string) $value));
        }
    }

    $errors = $error['errors'] ?? [];

    if (is_array($errors)) {
        foreach ($errors as $item) {
            if (!is_array($item)) {
                continue;
            }

            $reason = $item['reason'] ?? '';

            if (is_scalar($reason) && trim((string) $reason) !== '') {
                return strtolower(trim((string) $reason));
            }
        }
    }

    return '';
}

function google_calendar_is_permanent_oauth_error(array $data): bool
{
    return in_array(google_calendar_api_error_code($data), [
        'access_denied',
        'deleted_client',
        'disabled_client',
        'invalid_client',
        'invalid_grant',
        'invalid_request',
        'unauthorized_client',
    ], true);
}

function google_calendar_is_temporary_google_api_failure(array $result): bool
{
    if (google_calendar_is_temporary_http_failure($result)) {
        return true;
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];

    return in_array(google_calendar_api_error_code($data), [
        'backenderror',
        'internalerror',
        'ratelimitexceeded',
        'userratelimitexceeded',
    ], true);
}

function google_calendar_get_integration(string $tenantId, ?array &$lookupResult = null): ?array
{
    $lookupResult = google_calendar_execution_result('failed', 'unknown');
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        $lookupResult = google_calendar_execution_result('skipped', 'missing_supabase_config');
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
        $lookupResult = google_calendar_execution_result(
            google_calendar_is_temporary_http_failure($result) ? 'failed' : 'skipped',
            google_calendar_is_temporary_http_failure($result)
                ? 'integration_lookup_temporary'
                : 'integration_lookup_rejected',
            ['http_code' => (int) ($result['http_code'] ?? 0)]
        );
        return null;
    }

    $row = $result['data'][0] ?? null;

    if (!$row) {
        $lookupResult = google_calendar_execution_result('skipped', 'integration_missing');
        return null;
    }

    if (!filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $lookupResult = google_calendar_execution_result('skipped', 'integration_disabled');
        return null;
    }

    if (trim((string) ($row['disconnected_at'] ?? '')) !== '') {
        $lookupResult = google_calendar_execution_result('skipped', 'integration_disconnected');
        return null;
    }

    $settings = is_array($row['settings'] ?? null) ? $row['settings'] : [];

    if (trim((string) ($settings['calendar_id'] ?? '')) === '') {
        $lookupResult = google_calendar_execution_result('skipped', 'missing_calendar_id');
        return null;
    }

    if (!is_array($row['secrets'] ?? null) || empty($row['secrets'])) {
        $lookupResult = google_calendar_execution_result('skipped', 'missing_secrets');
        return null;
    }

    $storedSecrets = $row['secrets'];

    try {
        $secrets = decrypt_json_secret($storedSecrets);
    } catch (Throwable $e) {
        $lookupResult = google_calendar_execution_result('skipped', 'secrets_decrypt_failed');
        return null;
    }

    if (!is_array($secrets)) {
        $lookupResult = google_calendar_execution_result('skipped', 'secrets_invalid');
        return null;
    }

    if (empty($secrets['access_token']) && empty($secrets['refresh_token'])) {
        $lookupResult = google_calendar_execution_result('skipped', 'missing_tokens');
        return null;
    }

    $lookupResult = google_calendar_execution_result('done');

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
    array $secrets,
    ?array &$refreshResult = null
): ?array {
    $refreshResult = google_calendar_execution_result('failed', 'unknown');
    $accessToken = (string) ($secrets['access_token'] ?? '');
    $refreshToken = (string) ($secrets['refresh_token'] ?? '');
    $tokenExpiresAt = strtotime((string) ($secrets['token_expires_at'] ?? ''));

    if ($accessToken !== '' && $tokenExpiresAt && $tokenExpiresAt > (time() + 120)) {
        $refreshResult = google_calendar_execution_result('done');
        return $secrets;
    }

    if ($refreshToken === '') {
        $refreshResult = google_calendar_execution_result('skipped', 'missing_refresh_token');
        return null;
    }

    $googleClientId = trim((string) getenv('GOOGLE_CLIENT_ID'));
    $googleClientSecret = trim((string) getenv('GOOGLE_CLIENT_SECRET'));

    if ($googleClientId === '' || $googleClientSecret === '') {
        $refreshResult = google_calendar_execution_result('skipped', 'missing_google_oauth_client_config');
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

    $data = json_decode((string) $response, true);
    $data = is_array($data) ? $data : [];
    $tokenResult = [
        'http_code' => $httpCode,
        'error' => $curlError,
        'data' => $data,
    ];

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        if (google_calendar_is_temporary_http_failure($tokenResult)) {
            $refreshResult = google_calendar_execution_result(
                'failed',
                'token_refresh_temporary',
                ['http_code' => $httpCode]
            );
        } elseif (google_calendar_is_permanent_oauth_error($data)) {
            $refreshResult = google_calendar_execution_result(
                'skipped',
                'token_refresh_permanent',
                ['http_code' => $httpCode]
            );
        } else {
            $refreshResult = google_calendar_execution_result(
                'skipped',
                'token_refresh_rejected',
                ['http_code' => $httpCode]
            );
        }

        return null;
    }

    if (!is_array($data) || empty($data['access_token'])) {
        $refreshResult = google_calendar_execution_result('skipped', 'token_refresh_missing_access_token');
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

    $refreshResult = google_calendar_execution_result('done');

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

function google_calendar_booking_ref(array $booking, string $tenantId): string
{
    $existingRef = google_calendar_string_value($booking, 'booking_ref');

    if ($existingRef !== '') {
        return $existingRef;
    }

    $bookingId = google_calendar_string_value($booking, 'id');

    if ($tenantId === '' || $bookingId === '') {
        return '';
    }

    return public_response_booking_ref(
        $tenantId,
        $bookingId,
        public_response_ref_secret((string) getenv('SUPABASE_SERVICE_ROLE_KEY'))
    );
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

function google_calendar_build_event(array $booking, array $settings, string $tenantId = ''): array
{
    $bookingRef = google_calendar_booking_ref($booking, $tenantId);
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

    if ($bookingRef !== '') {
        $descriptionLines[] = '';
        $descriptionLines[] = 'Nr rezerwacji: ' . $bookingRef;
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

function createGoogleCalendarEventForBooking(
    string $tenantId,
    array $booking,
    ?array &$executionResult = null
): ?string
{
    $executionResult = google_calendar_execution_result('failed', 'unknown');
    $integrationLookup = null;
    $integration = google_calendar_get_integration($tenantId, $integrationLookup);

    if (!$integration) {
        $executionResult = is_array($integrationLookup)
            ? $integrationLookup
            : google_calendar_execution_result('failed', 'integration_lookup_unknown');
        google_calendar_debug('GOOGLE_CALENDAR_INTEGRATION_UNAVAILABLE', [
            'tenant_ref' => substr(hash('sha256', $tenantId), 0, 16),
            'status' => $executionResult['status'] ?? 'failed',
            'reason' => $executionResult['reason'] ?? 'unknown',
            'http_code' => $executionResult['http_code'] ?? null,
        ]);
        return null;
    }

    google_calendar_debug('INTEGRATION_FOUND', [
        'has_access_token' => !empty($integration['secrets']['access_token'] ?? ''),
        'has_refresh_token' => !empty($integration['secrets']['refresh_token'] ?? ''),
        'has_calendar_id' => !empty($integration['settings']['calendar_id'] ?? ''),
    ]);

    $settings = $integration['settings'];
    $secrets = $integration['secrets'];

    $refreshResult = null;
    $secrets = google_calendar_refresh_access_token_if_needed($tenantId, $settings, $secrets, $refreshResult);

    if (!$secrets || empty($secrets['access_token'])) {
        $executionResult = is_array($refreshResult)
            ? $refreshResult
            : google_calendar_execution_result('failed', 'token_refresh_unknown');
        google_calendar_debug('GOOGLE_CALENDAR_TOKEN_UNAVAILABLE', [
            'status' => $executionResult['status'] ?? 'failed',
            'reason' => $executionResult['reason'] ?? 'unknown',
            'http_code' => $executionResult['http_code'] ?? null,
        ]);
        return null;
    }

    $calendarId = trim((string) ($settings['calendar_id'] ?? ''));

    google_calendar_debug('CALENDAR_SELECTED', [
        'has_calendar_id' => $calendarId !== '',
    ]);

    try {
        $event = google_calendar_build_event($booking, $settings, $tenantId);
        google_calendar_debug('EVENT_READY', [
            'has_start' => !empty($event['start']['dateTime'] ?? ''),
            'has_end' => !empty($event['end']['dateTime'] ?? ''),
        ]);
    } catch (Throwable $e) {
        $executionResult = google_calendar_execution_result('skipped', 'build_event_failed');
        google_calendar_debug('BUILD_EVENT_ERROR', [
            'exception' => get_class($e),
        ]);
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
        'has_transport_error' => !empty($result['error']),
        'has_event_id' => !empty($result['data']['id'] ?? ''),
    ]);

    if ($result['error'] || $result['http_code'] < 200 || $result['http_code'] >= 300) {
        $isTemporary = google_calendar_is_temporary_google_api_failure($result);
        $executionResult = google_calendar_execution_result(
            $isTemporary ? 'failed' : 'skipped',
            $isTemporary
                ? 'google_api_temporary'
                : 'google_api_permanent',
            ['http_code' => (int) ($result['http_code'] ?? 0)]
        );
        google_calendar_debug('GOOGLE_CALENDAR_CREATE_FAILED', [
            'status' => $executionResult['status'] ?? 'failed',
            'reason' => $executionResult['reason'] ?? 'unknown',
            'http_code' => $executionResult['http_code'] ?? null,
        ]);
        return null;
    }

    $googleEventId = (string) ($result['data']['id'] ?? '');

    if ($googleEventId !== '') {
        $executionResult = google_calendar_execution_result('done');
    } else {
        $executionResult = google_calendar_execution_result('skipped', 'missing_event_id');
    }

    google_calendar_debug('GOOGLE_EVENT_CREATED', [
        'has_event_id' => $googleEventId !== '',
    ]);

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
        google_calendar_debug('UPDATE_NO_INTEGRATION_OR_DISABLED', [
            'tenant_ref' => substr(hash('sha256', $tenantId), 0, 16),
        ]);
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
        $event = google_calendar_build_event($booking, $settings, $tenantId);
        google_calendar_debug('UPDATE_EVENT_PAYLOAD', [
            'event_ref' => substr(hash('sha256', $googleEventId), 0, 16),
            'has_start' => !empty($event['start']['dateTime'] ?? ''),
            'has_end' => !empty($event['end']['dateTime'] ?? ''),
        ]);
    } catch (Throwable $e) {
        google_calendar_debug('UPDATE_BUILD_EVENT_ERROR', [
            'exception' => get_class($e),
        ]);
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
        'event_ref' => substr(hash('sha256', $googleEventId), 0, 16),
        'http_code' => $result['http_code'] ?? null,
        'has_transport_error' => !empty($result['error']),
    ]);

    $statusCode = (int) ($result['http_code'] ?? 0);

    return [
        'success' => !$result['error'] && $statusCode >= 200 && $statusCode < 300,
        'not_found' => $statusCode === 404,
        'status_code' => $statusCode,
    ];
}
