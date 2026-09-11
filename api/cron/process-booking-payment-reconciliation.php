<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/payu.php';

const BOOKING_RECONCILIATION_WORKER_LIMIT = 5;
const BOOKING_RECONCILIATION_WORKER_LEASE_SECONDS = 300;

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
    return preg_match('/\A[A-Za-z0-9_-]{1,160}\z/D', $value) === 1;
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

function booking_reconciliation_worker_record_result(
    string $paymentId,
    string $claimToken,
    string $resultKind,
    ?string $payuStatus,
    ?string $orderId,
    ?int $amountMinor,
    ?string $currency,
    string $payloadSha256,
    ?int $httpStatus,
    string $errorCode
): bool {
    $payload = [
        'p_payment_id' => $paymentId,
        'p_claim_token' => $claimToken,
        'p_result_kind' => $resultKind,
        'p_payu_status' => $payuStatus,
        'p_order_id' => $orderId,
        'p_total_amount_minor' => $amountMinor,
        'p_currency' => $currency,
        'p_payload_sha256_hex' => $payloadSha256,
        'p_http_status' => $httpStatus,
        'p_error_code' => $errorCode !== '' ? $errorCode : null,
    ];

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

function booking_reconciliation_worker_record_failure(
    string $paymentId,
    string $claimToken,
    string $resultKind,
    string $errorCode,
    string $payloadSha256 = '',
    ?int $httpStatus = null
): bool {
    if (!booking_reconciliation_worker_sha256($payloadSha256)) {
        $payloadSha256 = hash('sha256', '');
    }

    if (!booking_reconciliation_worker_error_code($errorCode)) {
        $errorCode = 'reconciliation_worker_failure';
    }

    return booking_reconciliation_worker_record_result(
        $paymentId,
        $claimToken,
        $resultKind,
        null,
        null,
        null,
        null,
        $payloadSha256,
        $httpStatus,
        $errorCode
    );
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

        if (!is_int($claimed) || $claimed < 0 || !is_array($items) || count($items) !== $claimed) {
            booking_reconciliation_worker_log('malformed_claim');
            $runFailed = true;
        } else {
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

                if (!booking_reconciliation_worker_uuid($paymentId)
                    || !booking_reconciliation_worker_uuid($claimToken)
                ) {
                    booking_reconciliation_worker_log('malformed_claim');
                    $runFailed = true;
                    break;
                }

                $claimContextValid = booking_reconciliation_worker_safe_text($tenantId, 128)
                    && booking_reconciliation_worker_uuid($bookingId)
                    && booking_reconciliation_worker_ext_order_id($extOrderId)
                    && is_int($amountMinor)
                    && $amountMinor > 0
                    && booking_reconciliation_worker_currency($currency)
                    && in_array($providerState, ['request_in_flight', 'order_created', 'result_unknown'], true)
                    && is_int($attemptCount)
                    && $attemptCount >= 1
                    && $attemptCount <= 12;

                if (!$claimContextValid) {
                    booking_reconciliation_worker_log('malformed_claim');

                    if (!booking_reconciliation_worker_record_failure(
                        $paymentId,
                        $claimToken,
                        'result_unknown',
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

                    if (!booking_reconciliation_worker_record_failure(
                        $paymentId,
                        $claimToken,
                        'result_unknown',
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

                    if (!booking_reconciliation_worker_record_failure(
                        $paymentId,
                        $claimToken,
                        'result_unknown',
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

                $payu = payu_get_integration($tenantId);

                if (!is_array($payu)) {
                    booking_reconciliation_worker_log('integration_unavailable');

                    if (!booking_reconciliation_worker_record_failure(
                        $paymentId,
                        $claimToken,
                        'transport_failure',
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

                $provider = payu_retrieve_order($payu, $orderId);
                $responseSha256 = trim((string) ($provider['response_sha256'] ?? ''));
                $providerHttpStatus = booking_reconciliation_worker_http_status(
                    $provider['http_code'] ?? null
                );

                if (empty($provider['success'])) {
                    $resultKind = strtolower(trim((string) ($provider['result_kind'] ?? '')));
                    $errorCode = strtolower(trim((string) ($provider['error_code'] ?? '')));

                    if (!in_array($resultKind, ['result_unknown', 'transport_failure'], true)
                        || !booking_reconciliation_worker_error_code($errorCode)
                        || !booking_reconciliation_worker_sha256($responseSha256)
                    ) {
                        booking_reconciliation_worker_log('provider_result_invalid');
                        $resultKind = 'result_unknown';
                        $errorCode = 'provider_result_contract_invalid';
                        $responseSha256 = booking_reconciliation_worker_sha256($responseSha256)
                            ? $responseSha256
                            : hash('sha256', '');
                    }

                    if (!booking_reconciliation_worker_record_failure(
                        $paymentId,
                        $claimToken,
                        $resultKind,
                        $errorCode,
                        $responseSha256,
                        $providerHttpStatus
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
                    && booking_reconciliation_worker_sha256($responseSha256)
                    && $providerHttpStatus === 200;

                if (!$providerContractValid) {
                    booking_reconciliation_worker_log('provider_result_invalid');

                    if (!booking_reconciliation_worker_record_failure(
                        $paymentId,
                        $claimToken,
                        'result_unknown',
                        'provider_result_contract_invalid',
                        booking_reconciliation_worker_sha256($responseSha256)
                            ? $responseSha256
                            : hash('sha256', ''),
                        $providerHttpStatus
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

                    if (!booking_reconciliation_worker_record_result(
                        $paymentId,
                        $claimToken,
                        'result_unknown',
                        $providerStatus,
                        $providerOrderId,
                        $providerAmountMinor,
                        $providerCurrency,
                        $responseSha256,
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
                    $providerAmountMinor,
                    $providerCurrency,
                    $responseSha256,
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
