<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/payu.php';

const BOOKING_RECONCILIATION_WORKER_LIMIT = 1;
const BOOKING_RECONCILIATION_WORKER_LEASE_SECONDS = 300;
const BOOKING_RECONCILIATION_MIN_LEASE_BEFORE_CONFIG_SECONDS = 120;
const BOOKING_RECONCILIATION_MIN_LEASE_BEFORE_PROVIDER_SECONDS = 90;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function booking_reconciliation_worker_log(string $event): void
{
    $allowed = [
        'claim_failed',
        'malformed_claim',
        'integration_unavailable',
        'provider_order_missing',
        'provider_result_invalid',
        'provider_binding_mismatch',
        'record_result_failed',
        'lease_budget_insufficient',
        'worker_run_success',
        'worker_run_failed',
    ];

    if (!in_array($event, $allowed, true)) {
        $event = 'worker_run_failed';
    }

    error_log('BOOKING_RECONCILIATION_WORKER ' . $event);
}

function booking_reconciliation_worker_id(): string
{
    $host = function_exists('gethostname') ? trim((string) gethostname()) : '';
    $hostHash = substr(hash('sha256', $host !== '' ? $host : 'unknown-host'), 0, 16);
    $pid = function_exists('getmypid') ? max(0, (int) getmypid()) : 0;

    return 'booking-reconciliation-v1:h-' . $hostHash . ':p-' . $pid;
}

function booking_reconciliation_worker_uuid(string $value): bool
{
    return preg_match(
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
        $value
    ) === 1;
}

function booking_reconciliation_worker_safe_text($value, int $maxBytes = 160): bool
{
    return is_string($value)
        && $value !== ''
        && $value === trim($value)
        && strlen($value) <= $maxBytes
        && preg_match('/[\p{C}]/u', $value) === 0;
}

function booking_reconciliation_worker_order_id(string $value): bool
{
    return preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) === 1;
}

function booking_reconciliation_worker_ext_order_id(string $value): bool
{
    return preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) === 1;
}

function booking_reconciliation_worker_currency(string $value): bool
{
    return preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
}

function booking_reconciliation_worker_sha256(string $value): bool
{
    return preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
}

function booking_reconciliation_worker_error_code(string $value): bool
{
    return preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $value) === 1;
}

function booking_reconciliation_worker_http_status($value): ?int
{
    if (!is_int($value)) {
        return null;
    }

    return ($value >= 100 && $value <= 599) ? $value : null;
}

function booking_reconciliation_worker_lease_seconds_remaining($value): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $leaseExpiresAt = new DateTimeImmutable(trim($value));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }

    return $leaseExpiresAt->getTimestamp() - $now->getTimestamp();
}

function booking_reconciliation_worker_reserve_provider_attempt(
    string $paymentId,
    string $claimToken,
    int $attemptCount,
    string $leaseExpiresAt
): ?array {
    // Never retry: a lost response may hide a committed reservation/token rotation.
    $rpc = payment_lifecycle_v3_rpc('booking_payment_reconciliation_reserve_provider_attempt', [
        'p_payment_id' => $paymentId,
        'p_claim_token' => $claimToken,
    ]);

    if (($rpc['ok'] ?? null) !== true || !is_array($rpc['data'] ?? null)) {
        return null;
    }

    $data = $rpc['data'];
    $reserved = $data['reserved'] ?? null;
    $mayGet = $data['may_get'] ?? null;
    $token = $data['claim_token'] ?? null;
    $count = $data['attempt_count'] ?? null;
    $lease = $data['lease_expires_at'] ?? null;
    $keys = array_keys($data);
    sort($keys);
    $expectedKeys = ['attempt_count', 'claim_token', 'lease_expires_at', 'may_get', 'reserved'];
    if ($reserved === false) {
        $expectedKeys[] = 'reason';
        sort($expectedKeys);
    }

    if ($keys !== $expectedKeys
        || !is_string($token)
        || !booking_reconciliation_worker_uuid($token)
        || !is_int($count)
        || !is_string($lease)
        || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $lease) !== 1
    ) {
        return null;
    }

    try {
        $gateLease = new DateTimeImmutable($lease);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($dateErrors !== false
            && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)
        ) {
            return null;
        }
        // The deployed gate does not extend or shorten the claimed lease.
        if ($gateLease != new DateTimeImmutable($leaseExpiresAt)) {
            return null;
        }
    } catch (Throwable $e) {
        return null;
    }

    $sameToken = hash_equals(strtolower($claimToken), strtolower($token));
    if ($reserved === true && $mayGet === true
        && !$sameToken && $count === $attemptCount + 1
        && $count >= 1 && $count <= 12
    ) {
        return $data;
    }

    if ($reserved === false && $mayGet === false
        && ($data['reason'] ?? null) === 'lease_too_short'
        && $sameToken && $count === $attemptCount
    ) {
        return $data;
    }

    return null;
}

function booking_reconciliation_worker_release_claim(
    string $paymentId,
    string $claimToken,
    string $errorCode
): bool {
    if (!booking_reconciliation_worker_error_code($errorCode)) {
        return false;
    }

    $rpc = payment_lifecycle_v3_rpc('booking_payment_reconciliation_release_claim', [
        'p_payment_id' => $paymentId,
        'p_claim_token' => $claimToken,
        'p_error_code' => $errorCode,
    ]);

    return !empty($rpc['ok'])
        && is_array($rpc['data'] ?? null)
        && (($rpc['data']['released'] ?? false) === true);
}

function booking_reconciliation_worker_escalate_unqueryable(
    string $paymentId,
    string $claimToken,
    string $errorCode
): bool {
    if (!booking_reconciliation_worker_error_code($errorCode)) {
        return false;
    }

    $rpc = payment_lifecycle_v3_rpc('booking_payment_reconciliation_escalate_unqueryable', [
        'p_payment_id' => $paymentId,
        'p_claim_token' => $claimToken,
        'p_error_code' => $errorCode,
    ]);

    if (empty($rpc['ok']) || !is_array($rpc['data'] ?? null)) {
        return false;
    }

    $data = $rpc['data'];

    return ($data['escalated'] ?? false) === true
        || ($data['order_id_available'] ?? false) === true
        || ($data['terminal_or_ineligible'] ?? false) === true;
}

function booking_reconciliation_worker_record_result(
    string $paymentId,
    string $claimToken,
    string $resultKind,
    ?string $payuStatus,
    ?string $orderId,
    ?string $extOrderId,
    ?int $amountMinor,
    ?string $currency,
    bool $bodyAvailable,
    ?string $payloadSha256,
    ?string $operationFingerprint,
    string $transportStage,
    ?int $httpStatus,
    string $errorCode
): bool {
    $payload = [
        'p_payment_id' => $paymentId,
        'p_claim_token' => $claimToken,
        'p_result_kind' => $resultKind,
        'p_payu_status' => $payuStatus,
        'p_order_id' => $orderId,
        'p_ext_order_id' => $extOrderId,
        'p_total_amount_minor' => $amountMinor,
        'p_currency' => $currency,
        'p_body_available' => $bodyAvailable,
        'p_payload_sha256_hex' => $payloadSha256,
        'p_operation_fingerprint_hex' => $operationFingerprint,
        'p_transport_stage' => $transportStage,
        'p_http_status' => $httpStatus,
        'p_error_code' => $errorCode !== '' ? $errorCode : null,
    ];

    // Retry only the local idempotent record-result RPC. The provider attempt
    // was already reserved; neither receipt replay nor recording increments it.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $rpc = payment_lifecycle_v3_rpc(
            'booking_payment_reconciliation_record_result',
            $payload
        );

        if (!empty($rpc['ok']) && is_array($rpc['data'] ?? null)) {
            $data = $rpc['data'];
            $recorded = ($data['recorded'] ?? false) === true;
            $idempotent = ($data['idempotent'] ?? false) === true;

            if ($recorded || $idempotent) {
                return true;
            }
        }

        if ($attempt === 0) {
            usleep(250000);
        }
    }

    return false;
}

$claimedCount = 0;
$processed = 0;
$completed = 0;
$canceled = 0;
$pending = 0;
$deferred = 0;
$runFailed = false;

try {
    payment_lifecycle_v3_config();
    $workerId = booking_reconciliation_worker_id();

    $claimRpc = payment_lifecycle_v3_rpc('booking_payment_reconciliation_claim', [
        'p_worker_id' => $workerId,
        'p_limit' => BOOKING_RECONCILIATION_WORKER_LIMIT,
        'p_lease_seconds' => BOOKING_RECONCILIATION_WORKER_LEASE_SECONDS,
    ]);

    if (empty($claimRpc['ok']) || !is_array($claimRpc['data'] ?? null)) {
        booking_reconciliation_worker_log('claim_failed');
        $runFailed = true;
    } else {
        $claim = $claimRpc['data'];
        $claimed = $claim['claimed'] ?? null;
        $items = $claim['items'] ?? null;

        if (!is_int($claimed)
            || $claimed < 0
            || $claimed > BOOKING_RECONCILIATION_WORKER_LIMIT
            || !is_array($items)
            || count($items) !== $claimed
        ) {
            booking_reconciliation_worker_log('malformed_claim');
            $runFailed = true;
        } else {
            $seenPaymentIds = [];
            $seenClaimTokens = [];
            $claimedCount = $claimed;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    booking_reconciliation_worker_log('malformed_claim');
                    $runFailed = true;
                    break;
                }

                $paymentId = trim((string) ($item['payment_id'] ?? ''));
                $claimToken = trim((string) ($item['claim_token'] ?? ''));
                $tenantId = trim((string) ($item['tenant_id'] ?? ''));
                $bookingId = trim((string) ($item['booking_id'] ?? ''));
                $extOrderId = trim((string) ($item['ext_order_id'] ?? ''));
                $orderId = trim((string) ($item['order_id'] ?? ''));
                $currency = strtoupper(trim((string) ($item['currency'] ?? '')));
                $providerState = strtolower(trim((string) ($item['provider_state'] ?? '')));
                $amountMinor = $item['amount_minor'] ?? null;
                $attemptCount = $item['attempt_count'] ?? null;
                $leaseExpiresAt = $item['lease_expires_at'] ?? null;
                $leaseSecondsRemaining = booking_reconciliation_worker_lease_seconds_remaining($leaseExpiresAt);

                if (!booking_reconciliation_worker_uuid($paymentId)
                    || !booking_reconciliation_worker_uuid($claimToken)
                    || isset($seenPaymentIds[$paymentId])
                    || isset($seenClaimTokens[$claimToken])
                ) {
                    booking_reconciliation_worker_log('malformed_claim');
                    $runFailed = true;
                    break;
                }

                $seenPaymentIds[$paymentId] = true;
                $seenClaimTokens[$claimToken] = true;

                if ($leaseSecondsRemaining === null || $leaseSecondsRemaining <= 0) {
                    booking_reconciliation_worker_log('malformed_claim');

                    if (!booking_reconciliation_worker_release_claim(
                        $paymentId,
                        $claimToken,
                        'reconciliation_lease_invalid'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $claimContextValid = booking_reconciliation_worker_safe_text($tenantId, 128)
                    && booking_reconciliation_worker_uuid($bookingId)
                    && booking_reconciliation_worker_ext_order_id($extOrderId)
                    && is_int($amountMinor)
                    && $amountMinor > 0
                    && booking_reconciliation_worker_currency($currency)
                    && in_array($providerState, ['request_in_flight', 'order_created', 'result_unknown'], true)
                    && is_int($attemptCount)
                    && $attemptCount >= 0
                    && $attemptCount <= 11;

                if (!$claimContextValid) {
                    booking_reconciliation_worker_log('malformed_claim');

                    if (!booking_reconciliation_worker_escalate_unqueryable(
                        $paymentId,
                        $claimToken,
                        'reconciliation_claim_context_invalid'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                if ($orderId === '') {
                    booking_reconciliation_worker_log('provider_order_missing');

                    if (!booking_reconciliation_worker_escalate_unqueryable(
                        $paymentId,
                        $claimToken,
                        'provider_order_id_missing'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                if (!booking_reconciliation_worker_order_id($orderId)) {
                    booking_reconciliation_worker_log('malformed_claim');

                    if (!booking_reconciliation_worker_escalate_unqueryable(
                        $paymentId,
                        $claimToken,
                        'provider_order_id_invalid'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $leaseSecondsRemaining = booking_reconciliation_worker_lease_seconds_remaining($leaseExpiresAt);

                if ($leaseSecondsRemaining === null
                    || $leaseSecondsRemaining < BOOKING_RECONCILIATION_MIN_LEASE_BEFORE_CONFIG_SECONDS
                ) {
                    booking_reconciliation_worker_log('lease_budget_insufficient');

                    if (!booking_reconciliation_worker_release_claim(
                        $paymentId,
                        $claimToken,
                        'reconciliation_lease_too_short_pre_config'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $payu = payu_get_integration($tenantId);

                if (!is_array($payu)) {
                    booking_reconciliation_worker_log('integration_unavailable');

                    if (!booking_reconciliation_worker_release_claim(
                        $paymentId,
                        $claimToken,
                        'payu_integration_unavailable'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $leaseSecondsRemaining = booking_reconciliation_worker_lease_seconds_remaining($leaseExpiresAt);

                if ($leaseSecondsRemaining === null
                    || $leaseSecondsRemaining < BOOKING_RECONCILIATION_MIN_LEASE_BEFORE_PROVIDER_SECONDS
                ) {
                    booking_reconciliation_worker_log('lease_budget_insufficient');

                    if (!booking_reconciliation_worker_release_claim(
                        $paymentId,
                        $claimToken,
                        'reconciliation_lease_too_short_pre_provider'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $gate = booking_reconciliation_worker_reserve_provider_attempt(
                    $paymentId,
                    $claimToken,
                    $attemptCount,
                    $leaseExpiresAt
                );

                if ($gate === null) {
                    // Ambiguous reservation: no GET, retry, release or record.
                    booking_reconciliation_worker_log('provider_result_invalid');
                    $runFailed = true;
                    break;
                }

                if ($gate['reserved'] === false) {
                    if (!booking_reconciliation_worker_release_claim(
                        $paymentId,
                        $claimToken,
                        'reconciliation_lease_too_short_pre_provider'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $claimToken = $gate['claim_token'];
                $attemptCount = $gate['attempt_count'];
                $leaseExpiresAt = $gate['lease_expires_at'];
                $leaseSecondsRemaining = booking_reconciliation_worker_lease_seconds_remaining($leaseExpiresAt);
                if ($leaseSecondsRemaining === null
                    || $leaseSecondsRemaining < BOOKING_RECONCILIATION_MIN_LEASE_BEFORE_PROVIDER_SECONDS
                ) {
                    // A successful reservation remains spent; lease recovery owns it.
                    booking_reconciliation_worker_log('lease_budget_insufficient');
                    $runFailed = true;
                    break;
                }

                // At most one GET per successful reservation, with no provider retry.
                // Subsequent record/release must use only the rotated claim token.
                $provider = payu_retrieve_order($payu, $orderId);
                $requestAttempted = $provider['request_attempted'] ?? null;

                if ($requestAttempted === false) {
                    if (!booking_reconciliation_worker_release_claim(
                        $paymentId,
                        $claimToken,
                        'payu_integration_unavailable'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                if ($requestAttempted !== true) {
                    booking_reconciliation_worker_log('provider_result_invalid');
                    $runFailed = true;
                    break;
                }

                $bodyAvailableRaw = $provider['body_available'] ?? null;
                $bodyAvailable = is_bool($bodyAvailableRaw) ? $bodyAvailableRaw : null;
                $responseSha256 = is_string($provider['response_sha256'] ?? null)
                    ? trim((string) $provider['response_sha256'])
                    : null;
                $operationFingerprint = is_string($provider['operation_fingerprint'] ?? null)
                    ? trim((string) $provider['operation_fingerprint'])
                    : null;
                $transportStage = strtolower(trim((string) ($provider['transport_stage'] ?? '')));
                $providerHttpStatus = booking_reconciliation_worker_http_status(
                    $provider['http_code'] ?? null
                );

                $evidenceValid = $bodyAvailable !== null
                    && in_array($transportStage, ['request_started', 'response_received', 'reconciliation'], true)
                    && (
                        ($bodyAvailable === true
                            && $transportStage === 'response_received'
                            && is_string($responseSha256)
                            && booking_reconciliation_worker_sha256($responseSha256)
                            && $operationFingerprint === null)
                        ||
                        ($bodyAvailable === false
                            && $responseSha256 === null
                            && is_string($operationFingerprint)
                            && booking_reconciliation_worker_sha256($operationFingerprint))
                    );

                if (empty($provider['success'])) {
                    $resultKind = strtolower(trim((string) ($provider['result_kind'] ?? '')));
                    $errorCode = strtolower(trim((string) ($provider['error_code'] ?? '')));

                    if (!$evidenceValid) {
                        // Never fabricate SHA/operation evidence. The claim remains
                        // leased and will expire according to the DB contract.
                        booking_reconciliation_worker_log('provider_result_invalid');
                        $runFailed = true;
                        break;
                    }

                    $failureKindValid = $resultKind === 'result_unknown'
                        || ($resultKind === 'transport_failure' && $bodyAvailable === false);

                    if (!$failureKindValid
                        || !booking_reconciliation_worker_error_code($errorCode)
                    ) {
                        booking_reconciliation_worker_log('provider_result_invalid');
                        $resultKind = 'result_unknown';
                        $errorCode = 'provider_result_contract_invalid';
                    }

                    if (!booking_reconciliation_worker_record_result(
                        $paymentId,
                        $claimToken,
                        $resultKind,
                        null,
                        null,
                        null,
                        null,
                        null,
                        $bodyAvailable,
                        $bodyAvailable ? $responseSha256 : null,
                        $bodyAvailable ? null : $operationFingerprint,
                        $transportStage,
                        $providerHttpStatus,
                        $errorCode
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $providerResultKind = strtolower(trim((string) ($provider['result_kind'] ?? '')));
                $providerOrderId = trim((string) ($provider['order_id'] ?? ''));
                $providerExtOrderId = trim((string) ($provider['ext_order_id'] ?? ''));
                $providerStatus = strtoupper(trim((string) ($provider['payu_status'] ?? '')));
                $providerCurrency = strtoupper(trim((string) ($provider['currency'] ?? '')));
                $providerAmountMinor = $provider['amount_minor'] ?? null;

                $providerContractValid = $providerResultKind === 'provider_result'
                    && $evidenceValid
                    && $bodyAvailable === true
                    && booking_reconciliation_worker_order_id($providerOrderId)
                    && booking_reconciliation_worker_ext_order_id($providerExtOrderId)
                    && in_array(
                        $providerStatus,
                        ['NEW', 'PENDING', 'WAITING_FOR_CONFIRMATION', 'COMPLETED', 'CANCELED'],
                        true
                    )
                    && booking_reconciliation_worker_currency($providerCurrency)
                    && is_int($providerAmountMinor)
                    && $providerAmountMinor > 0
                    && $providerHttpStatus === 200;

                if (!$providerContractValid) {
                    booking_reconciliation_worker_log('provider_result_invalid');

                    if (!$evidenceValid) {
                        // Real provider evidence is unavailable or malformed. Do not
                        // replace it with SHA256("") or synthetic provider fields.
                        $runFailed = true;
                        break;
                    }

                    if (!booking_reconciliation_worker_record_result(
                        $paymentId,
                        $claimToken,
                        'result_unknown',
                        null,
                        null,
                        null,
                        null,
                        null,
                        $bodyAvailable,
                        $responseSha256,
                        null,
                        $transportStage,
                        $providerHttpStatus,
                        'provider_result_contract_invalid'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                $bindingValid = hash_equals($orderId, $providerOrderId)
                    && hash_equals($extOrderId, $providerExtOrderId)
                    && $amountMinor === $providerAmountMinor
                    && hash_equals($currency, $providerCurrency);

                if (!$bindingValid) {
                    booking_reconciliation_worker_log('provider_binding_mismatch');

                    // Persist only the real body evidence and mismatch classification.
                    // Do not pass a known PayU status as result_unknown and do not let
                    // mismatched provider tuple mutate the authoritative payment.
                    if (!booking_reconciliation_worker_record_result(
                        $paymentId,
                        $claimToken,
                        'result_unknown',
                        null,
                        null,
                        null,
                        null,
                        null,
                        true,
                        $responseSha256,
                        null,
                        'response_received',
                        $providerHttpStatus,
                        'provider_binding_mismatch'
                    )) {
                        booking_reconciliation_worker_log('record_result_failed');
                        $runFailed = true;
                        break;
                    }

                    $processed++;
                    $deferred++;
                    continue;
                }

                if ($providerStatus === 'COMPLETED') {
                    $resultKind = 'completed';
                } elseif ($providerStatus === 'CANCELED') {
                    $resultKind = 'canceled';
                } else {
                    $resultKind = 'pending';
                }

                if (!booking_reconciliation_worker_record_result(
                    $paymentId,
                    $claimToken,
                    $resultKind,
                    $providerStatus,
                    $providerOrderId,
                    $providerExtOrderId,
                    $providerAmountMinor,
                    $providerCurrency,
                    true,
                    $responseSha256,
                    null,
                    'response_received',
                    $providerHttpStatus,
                    ''
                )) {
                    booking_reconciliation_worker_log('record_result_failed');
                    $runFailed = true;
                    break;
                }

                $processed++;

                if ($resultKind === 'completed') {
                    $completed++;
                } elseif ($resultKind === 'canceled') {
                    $canceled++;
                } else {
                    $pending++;
                }
            }
        }
    }
} catch (Throwable $e) {
    booking_reconciliation_worker_log('worker_run_failed');
    $runFailed = true;
}

if ($runFailed) {
    booking_reconciliation_worker_log('worker_run_failed');
    echo json_encode([
        'success' => false,
        'claimed' => $claimedCount,
        'processed' => $processed,
        'completed' => $completed,
        'canceled' => $canceled,
        'pending' => $pending,
        'deferred' => $deferred,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

booking_reconciliation_worker_log('worker_run_success');

if ($claimedCount > 0) {
    echo json_encode([
        'success' => true,
        'claimed' => $claimedCount,
        'processed' => $processed,
        'completed' => $completed,
        'canceled' => $canceled,
        'pending' => $pending,
        'deferred' => $deferred,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit(0);
