<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const RETENTION_BOOKINGS_MONTHS = 3;
const RETENTION_SUBSCRIPTION_EMAIL_LOGS_MONTHS = 6;
const RETENTION_SUBSCRIPTION_FAILED_PAYMENTS_MONTHS = 6;
const RETENTION_SUBSCRIPTION_PAID_PAYMENTS_MONTHS = 24;
const RETENTION_TOKENS_DAYS = 2;
const RETENTION_EMAIL_CHANGE_CODES_DAYS = 2;
const RETENTION_ACCOUNT_DELETION_CODES_DAYS = 2;
const RETENTION_RUNTIME_DIR = '/var/www/data';

function retention_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function retention_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    $value = trim((string) $value);

    return $value !== '' ? $value : $default;
}

function retention_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function retention_request_token(): string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if ($authorization === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authorization = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    $headerSecret = trim((string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? ''));

    if ($headerSecret !== '') {
        return $headerSecret;
    }

    return '';
}

function retention_expected_token(): string
{
    $specific = retention_env('CLEANUP_SUPABASE_RETENTION_CRON_SECRET');

    if ($specific !== '') {
        return $specific;
    }

    $legacy = retention_env('CLEANUP_CRON_TOKEN');

    if ($legacy !== '') {
        return $legacy;
    }

    return retention_env('CRON_SECRET');
}

function retention_authorize(): void
{
    if (retention_is_cli()) {
        return;
    }

    $expectedToken = retention_expected_token();

    if ($expectedToken === '') {
        retention_json([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }

    $providedToken = retention_request_token();

    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        retention_json([
            'success' => false,
            'error' => 'unauthorized',
        ], 401);
    }
}

function retention_is_dry_run(): bool
{
    $value = strtolower(trim((string) ($_GET['dry_run'] ?? '')));

    return !in_array($value, ['0', 'false', 'no'], true);
}

function retention_headers(string $key, string $schema, bool $count = false, bool $minimal = false): array
{
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    if ($count) {
        $headers[] = 'Prefer: count=exact';
    } elseif ($minimal) {
        $headers[] = 'Prefer: return=minimal';
    } else {
        $headers[] = 'Prefer: return=representation';
    }

    return $headers;
}

function retention_schema_is_allowed(string $schema): bool
{
    return trim($schema) === 'rezerwacja_pro';
}

function retention_filter_segments(string $filters): ?array
{
    $filters = trim($filters);

    if ($filters === '') {
        return null;
    }

    $segments = explode('&', $filters);
    $reservedFields = ['select', 'order', 'limit', 'offset', 'columns', 'on_conflict', 'or', 'and'];

    foreach ($segments as $segment) {
        if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)=([a-zA-Z][a-zA-Z0-9_]*)\.([^#?&=\s]+)$/', $segment, $matches)) {
            return null;
        }

        if (in_array(strtolower($matches[1]), $reservedFields, true)) {
            return null;
        }
    }

    return $segments;
}

function retention_registration_consents_cutoff_is_safe(string $encodedCutoff): bool
{
    if ($encodedCutoff === '' || preg_match('/[#?&=\s]/', $encodedCutoff) === 1) {
        return false;
    }

    $cutoffIso = rawurldecode($encodedCutoff);

    if (
        $cutoffIso === ''
        || preg_match('/[#?&=\s]/', $cutoffIso) === 1
        || rawurlencode($cutoffIso) !== $encodedCutoff
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $cutoffIso) !== 1
    ) {
        return false;
    }

    $cutoff = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $cutoffIso);
    $dateErrors = DateTimeImmutable::getLastErrors();

    if (
        $cutoff === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $cutoff->format(DateTimeInterface::ATOM) !== $cutoffIso
    ) {
        return false;
    }

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return $cutoff <= $nowUtc;
}

function retention_registration_consents_filters_are_safe(string $filters): bool
{
    $segments = retention_filter_segments($filters);

    if ($segments === null || count($segments) !== 3 || count(array_unique($segments)) !== 3) {
        return false;
    }

    $cutoffPrefix = 'retention_until=lt.';
    $cutoffSegments = array_values(array_filter(
        $segments,
        static fn(string $segment): bool => strncmp($segment, $cutoffPrefix, strlen($cutoffPrefix)) === 0
    ));

    if (
        !in_array('retention_until=not.is.null', $segments, true)
        || !in_array('legal_hold=eq.false', $segments, true)
        || count($cutoffSegments) !== 1
    ) {
        return false;
    }

    $encodedCutoff = substr($cutoffSegments[0], strlen($cutoffPrefix));

    return retention_registration_consents_cutoff_is_safe($encodedCutoff);
}

function retention_registration_consents_build_filters(DateTimeImmutable $cutoff): string
{
    $cutoffIso = retention_iso($cutoff);

    return 'retention_until=not.is.null'
        . '&retention_until=lt.' . rawurlencode($cutoffIso)
        . '&legal_hold=eq.false';
}

function retention_request_guard_error(
    string $supabaseUrl,
    string $key,
    string $schema,
    string $table,
    string $filters
): ?string {
    if (
        trim($supabaseUrl) === ''
        || trim($key) === ''
        || trim($schema) === ''
        || trim($table) === ''
        || trim($filters) === ''
    ) {
        return 'invalid_request';
    }

    if (!retention_schema_is_allowed($schema)) {
        return 'invalid_schema';
    }

    if (retention_filter_segments($filters) === null) {
        return 'unsafe_filters';
    }

    if (
        trim($table) === 'registration_consents'
        && !retention_registration_consents_filters_are_safe($filters)
    ) {
        return 'registration_consents_guard_failed';
    }

    return null;
}

function retention_count(string $supabaseUrl, string $key, string $schema, string $table, string $filters): array
{
    $guardError = retention_request_guard_error($supabaseUrl, $key, $schema, $table, $filters);

    if ($guardError !== null) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error_code' => $guardError,
            'count' => null,
        ];
    }

    $table = trim($table);
    $filters = trim($filters);
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/' . rawurlencode($table)
        . '?select=id'
        . '&' . $filters;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error_code' => 'curl_init_failed',
            'count' => null,
        ];
    }

    $configured = curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => retention_headers($key, $schema, true),
    ]);

    if (!$configured) {
        curl_close($ch);

        return [
            'ok' => false,
            'http_code' => 0,
            'error_code' => 'curl_config_failed',
            'count' => null,
        ];
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error_code' => $error !== '' ? 'request_failed' : 'http_failed',
            'count' => null,
        ];
    }

    if (!preg_match('/^content-range:\s*(?:\*|\d+-\d+)\/(\d+)\s*$/mi', $raw, $matches)) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error_code' => 'invalid_count_response',
            'count' => null,
        ];
    }

    $count = (int) $matches[1];

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'error_code' => null,
        'count' => $count,
    ];
}

function retention_delete(string $supabaseUrl, string $key, string $schema, string $table, string $filters): array
{
    $guardError = retention_request_guard_error($supabaseUrl, $key, $schema, $table, $filters);

    if ($guardError !== null) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error_code' => $guardError,
            'deleted' => null,
        ];
    }

    $table = trim($table);
    $filters = trim($filters);
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/' . rawurlencode($table)
        . '?select=id'
        . '&' . $filters;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error_code' => 'curl_init_failed',
            'deleted' => null,
        ];
    }

    $configured = curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => retention_headers($key, $schema, false, false),
    ]);

    if (!$configured) {
        curl_close($ch);

        return [
            'ok' => false,
            'http_code' => 0,
            'error_code' => 'curl_config_failed',
            'deleted' => null,
        ];
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error_code' => $error !== '' ? 'request_failed' : 'http_failed',
            'deleted' => null,
        ];
    }

    $decoded = json_decode($raw);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error_code' => 'invalid_delete_response',
            'deleted' => null,
        ];
    }

    foreach ($decoded as $row) {
        if (
            !is_object($row)
            || !property_exists($row, 'id')
            || (!is_string($row->id) && !is_int($row->id))
            || trim((string) $row->id) === ''
        ) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error_code' => 'invalid_delete_response',
                'deleted' => null,
            ];
        }
    }

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'error_code' => null,
        'deleted' => count($decoded),
    ];
}

function retention_log_run(array $payload): void
{
    $logFile = RETENTION_RUNTIME_DIR . '/cleanup-supabase-retention.log';
    $line = date('Y-m-d H:i:s')
        . ' dry_run=' . (!empty($payload['dry_run']) ? 'true' : 'false')
        . ' success=' . (!empty($payload['success']) ? 'true' : 'false')
        . ' candidates=' . (int) ($payload['total_candidates'] ?? 0)
        . ' deleted=' . (int) ($payload['total_deleted'] ?? 0)
        . ' errors=' . count($payload['errors'] ?? [])
        . PHP_EOL;

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function retention_iso(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
}

$retentionLockHandle = null;
$responsePayload = [
    'success' => false,
    'error' => 'Błąd retencji Supabase.',
];
$responseStatus = 500;

try {
    $requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

    if (!in_array($requestMethod, ['GET', 'POST'], true)) {
        retention_json([
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ], 405);
    }

    retention_authorize();

    $supabaseUrl = rtrim(retention_env('SUPABASE_URL'), '/');
    $supabaseKey = retention_env('SUPABASE_SERVICE_ROLE_KEY');
    $schema = retention_env('SUPABASE_DB_SCHEMA', 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '' || !retention_schema_is_allowed($schema)) {
        retention_json([
            'success' => false,
            'error' => 'Błąd konfiguracji retencji.',
        ], 500);
    }

    $dryRun = retention_is_dry_run();

    if (!retention_is_cli() && $requestMethod === 'GET' && !$dryRun) {
        retention_json([
            'success' => false,
            'error' => 'Metoda niedozwolona dla wykonania retencji.',
        ], 405);
    }

    $lockFile = RETENTION_RUNTIME_DIR . '/cleanup-supabase-retention.lock';
    $retentionLockHandle = @fopen($lockFile, 'c');

    if ($retentionLockHandle === false) {
        $retentionLockHandle = null;
        retention_json([
            'success' => false,
            'error' => 'Nie udało się uruchomić blokady retencji.',
        ], 500);
    }

    if (!@flock($retentionLockHandle, LOCK_EX | LOCK_NB)) {
        fclose($retentionLockHandle);
        $retentionLockHandle = null;
        retention_json([
            'success' => false,
            'error' => 'Cleanup retencji jest już uruchomiony.',
        ], 409);
    }

    $tz = new DateTimeZone('Europe/Warsaw');
    $today = new DateTimeImmutable('today', $tz);
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $bookingsCutoff = $today->modify('-' . RETENTION_BOOKINGS_MONTHS . ' months')->format('Y-m-d');
    $subscriptionLogsCutoff = retention_iso($nowUtc->modify('-' . RETENTION_SUBSCRIPTION_EMAIL_LOGS_MONTHS . ' months'));
    $failedPaymentsCutoff = retention_iso($nowUtc->modify('-' . RETENTION_SUBSCRIPTION_FAILED_PAYMENTS_MONTHS . ' months'));
    $paidPaymentsCutoff = retention_iso($nowUtc->modify('-' . RETENTION_SUBSCRIPTION_PAID_PAYMENTS_MONTHS . ' months'));
    $tokensCutoff = retention_iso($nowUtc->modify('-' . RETENTION_TOKENS_DAYS . ' days'));
    $emailChangeCodesCutoff = retention_iso($nowUtc->modify('-' . RETENTION_EMAIL_CHANGE_CODES_DAYS . ' days'));
    $accountDeletionCodesCutoff = retention_iso($nowUtc->modify('-' . RETENTION_ACCOUNT_DELETION_CODES_DAYS . ' days'));
    $nowIso = retention_iso($nowUtc);

    $rules = [
        [
            'key' => 'bookings_old',
            'table' => 'bookings',
            'description' => 'Rezerwacje starsze niż ustalona retencja.',
            'filters' => 'booking_date=lt.' . rawurlencode($bookingsCutoff),
            'retention' => '3 miesiące',
        ],
        [
            'key' => 'subscription_email_logs_old',
            'table' => 'subscription_email_logs',
            'description' => 'Logi maili abonamentowych starsze niż ustalona retencja.',
            'filters' => 'created_at=lt.' . rawurlencode($subscriptionLogsCutoff),
            'retention' => '6 miesięcy',
        ],
        [
            'key' => 'subscription_failed_payments_old',
            'table' => 'tenant_subscription_payments',
            'description' => 'Nieudane/anulowane/wygasłe płatności abonamentowe starsze niż ustalona retencja.',
            'filters' => 'created_at=lt.' . rawurlencode($failedPaymentsCutoff)
                . '&status=in.(pending,failed,canceled,cancelled,expired)',
            'retention' => '6 miesięcy',
        ],
        [
            'key' => 'subscription_paid_payments_old',
            'table' => 'tenant_subscription_payments',
            'description' => 'Opłacone płatności abonamentowe starsze niż ustalona retencja.',
            'filters' => 'created_at=lt.' . rawurlencode($paidPaymentsCutoff)
                . '&status=eq.paid',
            'retention' => '24 miesiące',
        ],
        [
            'key' => 'activation_tokens_used_old',
            'table' => 'user_activation_tokens',
            'description' => 'Zużyte tokeny aktywacyjne starsze niż ustalona retencja.',
            'filters' => 'used_at=not.is.null'
                . '&used_at=lt.' . rawurlencode($tokensCutoff),
            'retention' => '2 dni od zużycia',
        ],
        [
            'key' => 'activation_tokens_revoked_old',
            'table' => 'user_activation_tokens',
            'description' => 'Unieważnione tokeny aktywacyjne starsze niż ustalona retencja.',
            'filters' => 'revoked_at=not.is.null'
                . '&revoked_at=lt.' . rawurlencode($tokensCutoff),
            'retention' => '2 dni od unieważnienia',
        ],
        [
            'key' => 'activation_tokens_expired_old',
            'table' => 'user_activation_tokens',
            'description' => 'Wygasłe, nieużyte i nieunieważnione tokeny aktywacyjne starsze niż ustalona retencja.',
            'filters' => 'used_at=is.null'
                . '&revoked_at=is.null'
                . '&expires_at=lt.' . rawurlencode($tokensCutoff),
            'retention' => '2 dni od wygaśnięcia',
        ],
        [
            'key' => 'email_change_codes_used_old',
            'table' => 'email_change_codes',
            'description' => 'Zużyte kody zmiany e-maila starsze niż ustalona retencja.',
            'filters' => 'used_at=not.is.null'
                . '&used_at=lt.' . rawurlencode($emailChangeCodesCutoff),
            'retention' => '2 dni od zużycia',
        ],
        [
            'key' => 'email_change_codes_expired_unused_old',
            'table' => 'email_change_codes',
            'description' => 'Wygasłe, nieużyte kody zmiany e-maila starsze niż ustalona retencja.',
            'filters' => 'used_at=is.null'
                . '&expires_at=lt.' . rawurlencode($emailChangeCodesCutoff),
            'retention' => '2 dni od wygaśnięcia',
        ],
        [
            'key' => 'account_deletion_codes_used_old',
            'table' => 'account_deletion_codes',
            'description' => 'Zamknięte kody usunięcia konta starsze niż ustalona retencja.',
            'filters' => 'used_at=not.is.null'
                . '&used_at=lt.' . rawurlencode($accountDeletionCodesCutoff),
            'retention' => '2 dni od zamknięcia',
        ],
        [
            'key' => 'account_deletion_codes_expired_unused_old',
            'table' => 'account_deletion_codes',
            'description' => 'Wygasłe, niezamknięte kody usunięcia konta starsze niż ustalona retencja.',
            'filters' => 'used_at=is.null'
                . '&expires_at=lt.' . rawurlencode($accountDeletionCodesCutoff),
            'retention' => '2 dni od wygaśnięcia',
        ],
        [
            'key' => 'registration_consents_retention_expired',
            'table' => 'registration_consents',
            'description' => 'Zgody dowodowe po upływie 6-letniego okresu retencji.',
            'filters' => retention_registration_consents_build_filters($nowUtc),
            'retention' => '6 lat zgodnie z retention_until',
        ],
        [
            'key' => 'password_change_codes_used_old',
            'table' => 'password_change_codes',
            'description' => 'Zużyte kody zmiany hasła starsze niż ustalona retencja.',
            'filters' => 'used_at=not.is.null'
                . '&used_at=lt.' . rawurlencode($tokensCutoff),
            'retention' => '2 dni od zużycia',
        ],
        [
            'key' => 'password_change_codes_expired_old',
            'table' => 'password_change_codes',
            'description' => 'Wygasłe, nieużyte kody zmiany hasła starsze niż ustalona retencja.',
            'filters' => 'used_at=is.null'
                . '&expires_at=lt.' . rawurlencode($tokensCutoff),
            'retention' => '2 dni od wygaśnięcia',
        ],
    ];

    $result = [
        'success' => true,
        'dry_run' => $dryRun,
        'now' => $nowIso,
        'cutoffs' => [
            'bookings_before' => $bookingsCutoff,
            'subscription_email_logs_before' => $subscriptionLogsCutoff,
            'failed_subscription_payments_before' => $failedPaymentsCutoff,
            'paid_subscription_payments_before' => $paidPaymentsCutoff,
            'tokens_before' => $tokensCutoff,
            'email_change_codes_before' => $emailChangeCodesCutoff,
            'account_deletion_codes_before' => $accountDeletionCodesCutoff,
        ],
        'total_candidates' => 0,
        'total_deleted' => 0,
        'rules' => [],
        'errors' => [],
    ];

    foreach ($rules as $rule) {
        $count = retention_count(
            $supabaseUrl,
            $supabaseKey,
            $schema,
            $rule['table'],
            $rule['filters']
        );

        $ruleResult = [
            'key' => $rule['key'],
            'description' => $rule['description'],
            'retention' => $rule['retention'],
            'candidates' => $count['count'],
            'deleted' => 0,
            'success' => $count['ok'],
            'http_code' => $count['http_code'],
        ];

        if (!$count['ok']) {
            $ruleResult['error'] = $count['error_code'] ?: 'count_failed';
            $result['errors'][] = [
                'rule' => $rule['key'],
                'error' => $ruleResult['error'],
                'http_code' => $count['http_code'],
            ];
            $result['success'] = false;
            $result['rules'][] = $ruleResult;
            continue;
        }

        $candidates = is_int($count['count']) ? $count['count'] : 0;
        $result['total_candidates'] += $candidates;

        if (!$dryRun && $candidates > 0) {
            $delete = retention_delete(
                $supabaseUrl,
                $supabaseKey,
                $schema,
                $rule['table'],
                $rule['filters']
            );

            $ruleResult['success'] = $delete['ok'];
            $ruleResult['http_code'] = $delete['http_code'];
            $ruleResult['deleted'] = is_int($delete['deleted']) ? $delete['deleted'] : 0;

            if (!$delete['ok']) {
                $ruleResult['error'] = $delete['error_code'] ?: 'delete_failed';
                $result['errors'][] = [
                    'rule' => $rule['key'],
                    'error' => $ruleResult['error'],
                    'http_code' => $delete['http_code'],
                ];
                $result['success'] = false;
            } else {
                $result['total_deleted'] += $ruleResult['deleted'];
            }
        }

        $result['rules'][] = $ruleResult;
    }

    retention_log_run($result);
    $responsePayload = $result;
    $responseStatus = $result['success'] ? 200 : 500;
} catch (Throwable $e) {
    $responsePayload = [
        'success' => false,
        'error' => 'Błąd retencji Supabase.',
    ];

    retention_log_run($responsePayload);
    $responseStatus = 500;
} finally {
    if (is_resource($retentionLockHandle)) {
        @flock($retentionLockHandle, LOCK_UN);
        fclose($retentionLockHandle);
    }
}

retention_json($responsePayload, $responseStatus);
