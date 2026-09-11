<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/google_calendar.php';

const BOOKING_CALENDAR_WORKER_LIMIT = 5;
const BOOKING_CALENDAR_WORKER_LEASE_SECONDS = 300;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function booking_calendar_worker_log(string $event): void
{
    $allowed = [
        'claim_failed',
        'claim_blocked',
        'malformed_claim',
        'context_read_failed',
        'context_invalid',
        'adapter_result_invalid',
        'record_result_failed',
        'worker_run_success',
        'worker_run_failed',
    ];

    if (!in_array($event, $allowed, true)) {
        $event = 'worker_run_failed';
    }

    error_log('BOOKING_CALENDAR_WORKER ' . $event);
}

function booking_calendar_worker_id(): string
{
    $host = function_exists('gethostname') ? trim((string) gethostname()) : '';
    $hostHash = substr(hash('sha256', $host !== '' ? $host : 'unknown-host'), 0, 16);
    $pid = function_exists('getmypid') ? max(0, (int) getmypid()) : 0;

    return 'booking-calendar-v1:h-' . $hostHash . ':p-' . $pid;
}

function booking_calendar_worker_uuid(string $value): bool
{
    return preg_match(
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
        $value
    ) === 1;
}

function booking_calendar_worker_safe_text($value, int $maxBytes = 128): bool
{
    return is_string($value)
        && $value !== ''
        && $value === trim($value)
        && strlen($value) <= $maxBytes
        && preg_match('/[\p{C}]/u', $value) === 0;
}

function booking_calendar_worker_supabase_rows(
    array $config,
    string $table,
    string $query
): array {
    $allowedTables = ['tenant_services', 'staff_profiles', 'calendar_settings'];

    if (!in_array($table, $allowedTables, true) || !function_exists('curl_init')) {
        return [
            'ok' => false,
            'retryable' => false,
            'rows' => [],
            'error_code' => 'calendar_context_configuration_invalid',
        ];
    }

    $url = $config['url'] . '/rest/v1/' . rawurlencode($table) . '?' . $query . '&limit=2';
    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'retryable' => true,
            'rows' => [],
            'error_code' => 'calendar_context_read_failed',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $config['service_role_key'],
            'Authorization: Bearer ' . $config['service_role_key'],
            'Accept: application/json',
            'Accept-Profile: ' . $config['schema'],
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return [
            'ok' => false,
            'retryable' => true,
            'rows' => [],
            'error_code' => 'calendar_context_read_failed',
        ];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return [
            'ok' => false,
            'retryable' => $httpStatus === 0 || $httpStatus === 408 || $httpStatus === 429 || $httpStatus >= 500,
            'rows' => [],
            'error_code' => 'calendar_context_read_failed',
        ];
    }

    $decoded = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [
            'ok' => false,
            'retryable' => false,
            'rows' => [],
            'error_code' => 'calendar_context_response_invalid',
        ];
    }

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            return [
                'ok' => false,
                'retryable' => false,
                'rows' => [],
                'error_code' => 'calendar_context_response_invalid',
            ];
        }
    }

    if (count($decoded) > 1) {
        return [
            'ok' => false,
            'retryable' => false,
            'rows' => [],
            'error_code' => 'calendar_context_collision',
        ];
    }

    return [
        'ok' => true,
        'retryable' => false,
        'rows' => $decoded,
        'error_code' => '',
    ];
}

function booking_calendar_worker_duration_value($value, bool &$valid): ?int
{
    $valid = true;

    if ($value === null) {
        return null;
    }

    if (is_int($value)) {
        if ($value > 2147483647) {
            $valid = false;
            return null;
        }

        return max(1, $value);
    }

    if (is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1) {
        $normalized = ltrim($value, '0');

        if ($normalized === '') {
            return 1;
        }

        if (strlen($normalized) > 10 || (strlen($normalized) === 10 && strcmp($normalized, '2147483647') > 0)) {
            $valid = false;
            return null;
        }

        return max(1, (int) $normalized);
    }

    $valid = false;
    return null;
}

function booking_calendar_worker_context(
    array $config,
    string $tenantId,
    array $booking
): array {
    $serviceId = $booking['service_id'] ?? null;
    $staffId = $booking['staff_id'] ?? null;

    if ($serviceId !== null && !booking_calendar_worker_safe_text($serviceId, 128)) {
        return ['ok' => false, 'retryable' => false, 'error_code' => 'calendar_context_invalid'];
    }

    if ($staffId !== null && !booking_calendar_worker_safe_text($staffId, 128)) {
        return ['ok' => false, 'retryable' => false, 'error_code' => 'calendar_context_invalid'];
    }

    $service = null;
    $staff = null;
    $calendar = null;

    if (is_string($serviceId) && $serviceId !== '') {
        $serviceResult = booking_calendar_worker_supabase_rows(
            $config,
            'tenant_services',
            'select=id,tenant_id,duration_minutes'
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&id=eq.' . rawurlencode($serviceId)
        );

        if (!$serviceResult['ok']) {
            return $serviceResult;
        }

        $service = $serviceResult['rows'][0] ?? null;

        if (is_array($service)) {
            if (
                (string) ($service['tenant_id'] ?? '') !== $tenantId
                || (string) ($service['id'] ?? '') !== $serviceId
            ) {
                return ['ok' => false, 'retryable' => false, 'error_code' => 'calendar_context_binding_invalid'];
            }
        }
    }

    if (is_string($staffId) && $staffId !== '') {
        $staffResult = booking_calendar_worker_supabase_rows(
            $config,
            'staff_profiles',
            'select=id,tenant_id,display_name,service_duration_minutes'
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&id=eq.' . rawurlencode($staffId)
        );

        if (!$staffResult['ok']) {
            return $staffResult;
        }

        $staff = $staffResult['rows'][0] ?? null;

        if (is_array($staff)) {
            if (
                (string) ($staff['tenant_id'] ?? '') !== $tenantId
                || (string) ($staff['id'] ?? '') !== $staffId
            ) {
                return ['ok' => false, 'retryable' => false, 'error_code' => 'calendar_context_binding_invalid'];
            }
        }
    }

    $calendarResult = booking_calendar_worker_supabase_rows(
        $config,
        'calendar_settings',
        'select=tenant_id,consultation_duration'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
    );

    if (!$calendarResult['ok']) {
        return $calendarResult;
    }

    $calendar = $calendarResult['rows'][0] ?? null;

    if (is_array($calendar) && (string) ($calendar['tenant_id'] ?? '') !== $tenantId) {
        return ['ok' => false, 'retryable' => false, 'error_code' => 'calendar_context_binding_invalid'];
    }

    $candidates = [
        is_array($service) ? ($service['duration_minutes'] ?? null) : null,
        is_array($staff) ? ($staff['service_duration_minutes'] ?? null) : null,
        is_array($calendar) ? ($calendar['consultation_duration'] ?? null) : null,
        60,
    ];

    $durationMinutes = null;

    foreach ($candidates as $candidate) {
        $valid = true;
        $normalized = booking_calendar_worker_duration_value($candidate, $valid);

        if (!$valid) {
            return ['ok' => false, 'retryable' => false, 'error_code' => 'calendar_context_invalid'];
        }

        if ($normalized !== null) {
            $durationMinutes = $normalized;
            break;
        }
    }

    if (!is_int($durationMinutes) || $durationMinutes < 1) {
        return ['ok' => false, 'retryable' => false, 'error_code' => 'calendar_context_invalid'];
    }

    $staffDisplayName = '';

    if (is_array($staff) && is_string($staff['display_name'] ?? null)) {
        $candidateName = trim((string) $staff['display_name']);

        if ($candidateName !== '' && strlen($candidateName) <= 512 && preg_match('/[\p{C}]/u', $candidateName) === 0) {
            $staffDisplayName = $candidateName;
        }
    }

    return [
        'ok' => true,
        'retryable' => false,
        'error_code' => '',
        'duration_minutes' => $durationMinutes,
        'staff_display_name' => $staffDisplayName,
    ];
}

function booking_calendar_worker_record_result(
    string $outboxId,
    string $claimToken,
    string $result,
    ?string $providerEventId,
    string $errorCode
): bool {
    $payload = [
        'p_calendar_outbox_id' => $outboxId,
        'p_claim_token' => $claimToken,
        'p_result' => $result,
        'p_provider_event_id' => $providerEventId,
        'p_error_code' => $errorCode,
    ];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $rpc = payment_lifecycle_v3_rpc('booking_calendar_record_result', $payload);

        if (!empty($rpc['ok']) && is_array($rpc['data'] ?? null)) {
            $data = $rpc['data'];
            $recorded = ($data['recorded'] ?? false) === true;
            $idempotent = ($data['idempotent'] ?? false) === true;
            $status = strtolower(trim((string) ($data['status'] ?? '')));

            if ($recorded || $idempotent) {
                if ($result === 'done' && in_array($status, ['done', 'blocked'], true)) {
                    return true;
                }

                if (in_array($result, ['blocked', 'result_unknown'], true) && $status === 'blocked') {
                    return true;
                }

                if ($result === 'not_attempted_retryable' && in_array($status, ['pending', 'failed'], true)) {
                    return true;
                }
            }
        }

        if ($attempt === 0) {
            usleep(250000);
        }
    }

    return false;
}

function booking_calendar_worker_record_pre_call_failure(
    string $outboxId,
    string $claimToken,
    bool $retryable,
    string $errorCode
): bool {
    return booking_calendar_worker_record_result(
        $outboxId,
        $claimToken,
        $retryable ? 'not_attempted_retryable' : 'blocked',
        null,
        $errorCode
    );
}

$processed = 0;
$done = 0;
$requeued = 0;
$blocked = 0;
$runFailed = false;

try {
    $config = payment_lifecycle_v3_config();
    $workerId = booking_calendar_worker_id();

    for ($index = 0; $index < BOOKING_CALENDAR_WORKER_LIMIT; $index++) {
        $claimRpc = payment_lifecycle_v3_rpc('booking_calendar_claim', [
            'p_worker_id' => $workerId,
            'p_lease_seconds' => BOOKING_CALENDAR_WORKER_LEASE_SECONDS,
        ]);

        if (empty($claimRpc['ok']) || !is_array($claimRpc['data'] ?? null)) {
            booking_calendar_worker_log('claim_failed');
            $runFailed = true;
            break;
        }

        $claim = $claimRpc['data'];

        if (!array_key_exists('claimed', $claim) || !is_bool($claim['claimed'])) {
            booking_calendar_worker_log('malformed_claim');
            $runFailed = true;
            break;
        }

        if ($claim['claimed'] === false) {
            if (($claim['blocked'] ?? false) === true) {
                booking_calendar_worker_log('claim_blocked');
                $blocked++;
                continue;
            }

            break;
        }

        $processed++;

        $outboxId = trim((string) ($claim['calendar_outbox_id'] ?? ''));
        $claimToken = trim((string) ($claim['claim_token'] ?? ''));
        $tenantId = trim((string) ($claim['tenant_id'] ?? ''));
        $bookingId = trim((string) ($claim['booking_id'] ?? ''));
        $paymentId = trim((string) ($claim['payment_id'] ?? ''));
        $action = strtolower(trim((string) ($claim['action'] ?? '')));
        $providerEventId = trim((string) ($claim['provider_event_id'] ?? ''));
        $booking = $claim['booking'] ?? null;

        if (
            !booking_calendar_worker_uuid($outboxId)
            || !booking_calendar_worker_uuid($claimToken)
            || !booking_calendar_worker_uuid($bookingId)
            || !booking_calendar_worker_uuid($paymentId)
            || !booking_calendar_worker_safe_text($tenantId, 128)
            || !in_array($action, ['create', 'update', 'delete'], true)
            || ($action === 'create' && $providerEventId !== '')
            || ($action !== 'create' && !booking_calendar_worker_safe_text($providerEventId, 512))
            || ($action !== 'delete' && !is_array($booking))
        ) {
            booking_calendar_worker_log('malformed_claim');

            if (
                booking_calendar_worker_uuid($outboxId)
                && booking_calendar_worker_uuid($claimToken)
                && !booking_calendar_worker_record_pre_call_failure(
                    $outboxId,
                    $claimToken,
                    false,
                    'calendar_claim_context_invalid'
                )
            ) {
                booking_calendar_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            $blocked++;
            continue;
        }

        $providerBooking = [];

        if ($action !== 'delete') {
            $providerBooking = $booking;
            $providerBooking['tenant_id'] = $tenantId;
            $providerBooking['payment_required'] = true;

            $context = booking_calendar_worker_context($config, $tenantId, $providerBooking);

            if (empty($context['ok'])) {
                $retryable = ($context['retryable'] ?? false) === true;
                $errorCode = trim((string) ($context['error_code'] ?? 'calendar_context_invalid'));

                booking_calendar_worker_log($retryable ? 'context_read_failed' : 'context_invalid');

                if (!booking_calendar_worker_record_pre_call_failure(
                    $outboxId,
                    $claimToken,
                    $retryable,
                    $errorCode
                )) {
                    booking_calendar_worker_log('record_result_failed');
                    $runFailed = true;
                    break;
                }

                if ($retryable) {
                    $requeued++;
                } else {
                    $blocked++;
                }

                continue;
            }

            $providerBooking['duration_minutes'] = $context['duration_minutes'];
            $providerBooking['staff_display_name'] = $context['staff_display_name'];
        }

        $adapter = google_calendar_v7_execute(
            $action,
            $tenantId,
            $providerBooking,
            $providerEventId
        );

        $result = is_string($adapter['result'] ?? null)
            ? strtolower(trim((string) $adapter['result']))
            : '';
        $errorCode = is_string($adapter['error_code'] ?? null)
            ? strtolower(trim((string) $adapter['error_code']))
            : '';
        $adapterEventId = is_string($adapter['provider_event_id'] ?? null)
            ? trim((string) $adapter['provider_event_id'])
            : '';
        $requestAttempted = ($adapter['request_attempted'] ?? false) === true;

        $adapterValid = in_array(
            $result,
            ['done', 'not_attempted_retryable', 'result_unknown', 'blocked'],
            true
        )
            && ($result === 'done' || preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $errorCode) === 1)
            && !($requestAttempted && $result === 'not_attempted_retryable')
            && !(!$requestAttempted && in_array($result, ['done', 'result_unknown'], true))
            && ($adapterEventId === '' || booking_calendar_worker_safe_text($adapterEventId, 512));

        if (!$adapterValid) {
            booking_calendar_worker_log('adapter_result_invalid');
            $result = $requestAttempted ? 'result_unknown' : 'blocked';
            $errorCode = 'calendar_adapter_contract_invalid';
            $adapterEventId = '';
        }

        if (!booking_calendar_worker_record_result(
            $outboxId,
            $claimToken,
            $result,
            $adapterEventId !== '' ? $adapterEventId : null,
            $errorCode
        )) {
            booking_calendar_worker_log('record_result_failed');
            $runFailed = true;
            break;
        }

        if ($result === 'done') {
            $done++;
        } elseif ($result === 'not_attempted_retryable') {
            $requeued++;
        } else {
            $blocked++;
        }
    }
} catch (Throwable $e) {
    booking_calendar_worker_log('worker_run_failed');
    $runFailed = true;
}

if ($runFailed) {
    booking_calendar_worker_log('worker_run_failed');
    echo json_encode([
        'success' => false,
        'processed' => $processed,
        'done' => $done,
        'requeued' => $requeued,
        'blocked' => $blocked,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

booking_calendar_worker_log('worker_run_success');

if ($processed > 0 || $blocked > 0 || $requeued > 0) {
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'done' => $done,
        'requeued' => $requeued,
        'blocked' => $blocked,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit(0);
