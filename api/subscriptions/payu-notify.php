<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/aiiq_payu.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/security.php';

function subscription_payu_notify_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'subscription_payu_notify',
        'endpoint' => '/api/subscriptions/payu-notify.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'payu_webhook',
        'tenant_id' => null,
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

function subscription_payu_notify_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_payu_notify_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function subscription_payu_notify_parse_signature(string $header): array
{
    $result = [];

    foreach (explode(';', $header) as $part) {
        $part = trim($part);

        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $part, 2);
        $key = strtolower(trim($key));
        $value = trim($value);

        if ($key !== '') {
            $result[$key] = $value;
        }
    }

    return $result;
}

function subscription_payu_notify_verify_signature(string $rawBody, string $secondKey, string $signatureHeader): bool
{
    if ($rawBody === '' || $secondKey === '' || $signatureHeader === '') {
        return false;
    }

    $signature = subscription_payu_notify_parse_signature($signatureHeader);
    $incomingSignature = strtolower((string) ($signature['signature'] ?? ''));
    $algorithm = strtolower((string) ($signature['algorithm'] ?? 'md5'));

    if ($incomingSignature === '' || $algorithm !== 'md5') {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SIGNATURE_UNSUPPORTED', [
            'algorithm' => $algorithm,
            'signature_set' => $incomingSignature !== '',
        ]);

        return false;
    }

    $expectedSignature = md5($rawBody . $secondKey);

    return hash_equals($expectedSignature, $incomingSignature);
}

function subscription_payu_notify_valid_id($value): bool
{
    return is_string($value)
        && $value !== ''
        && strlen($value) <= 160
        && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
}

function subscription_payu_notify_amount_minor($value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }

    if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
        return null;
    }

    $canonical = ltrim($value, '0');

    if ($canonical === '') {
        return 0;
    }

    $maximum = (string) PHP_INT_MAX;

    if (
        strlen($canonical) > strlen($maximum)
        || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)
    ) {
        return null;
    }

    return (int) $canonical;
}

function subscription_payu_notify_valid_rpc_data($data): bool
{
    if (!is_array($data)) {
        return false;
    }

    $processed = $data['processed'] ?? null;
    $idempotent = $data['idempotent'] ?? null;
    $status = $data['status'] ?? null;

    if (!is_bool($processed) || !is_bool($idempotent) || $processed === $idempotent) {
        return false;
    }

    return is_string($status)
        && in_array($status, ['pending', 'paid', 'canceled', 'failed', 'expired'], true);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        subscription_payu_notify_json(405, [
            'success' => false,
            'error' => 'Method not allowed.',
        ]);
    }

    $rawBody = file_get_contents('php://input');

    if (!is_string($rawBody) || $rawBody === '') {
        subscription_payu_notify_security_event(
            'subscription_payu_notify_body_missing',
            'body_missing',
            400
        );
        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        subscription_payu_notify_security_event(
            'subscription_payu_notify_json_invalid',
            'json_invalid',
            400
        );
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_JSON_INVALID', [
            'body_length' => strlen($rawBody),
        ]);
        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $secondKey = aiiq_payu_env('AI_IQ_PAYU_SECOND_KEY');
    $signatureHeader = subscription_payu_notify_header('OpenPayu-Signature');

    if (!subscription_payu_notify_verify_signature($rawBody, $secondKey, $signatureHeader)) {
        subscription_payu_notify_security_event(
            'subscription_payu_notify_signature_invalid',
            'signature_invalid',
            401,
            'denied',
            'high'
        );
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SIGNATURE_INVALID', [
            'body_length' => strlen($rawBody),
            'signature_header_set' => $signatureHeader !== '',
            'second_key_set' => $secondKey !== '',
        ]);
        subscription_payu_notify_json(401, [
            'success' => false,
            'error' => 'Nieprawidłowy podpis PayU.',
        ]);
    }

    $order = $data['order'] ?? null;

    if (!is_array($order)) {
        subscription_payu_notify_security_event(
            'subscription_payu_notify_order_invalid',
            'order_invalid',
            400
        );
        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $orderId = $order['orderId'] ?? null;
    $extOrderId = $order['extOrderId'] ?? null;
    $payuStatus = $order['status'] ?? null;
    $totalAmountMinor = subscription_payu_notify_amount_minor($order['totalAmount'] ?? null);
    $currency = $order['currencyCode'] ?? null;

    if (
        !subscription_payu_notify_valid_id($orderId)
        || !subscription_payu_notify_valid_id($extOrderId)
        || !is_string($payuStatus)
        || !in_array(
            $payuStatus,
            ['PENDING', 'WAITING_FOR_CONFIRMATION', 'COMPLETED', 'CANCELED', 'REJECTED', 'EXPIRED'],
            true
        )
        || $totalAmountMinor === null
        || !is_string($currency)
        || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
    ) {
        subscription_payu_notify_security_event(
            'subscription_payu_notify_fields_invalid',
            'fields_invalid',
            400
        );
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_FIELDS_INVALID', [
            'order_id_valid' => subscription_payu_notify_valid_id($orderId),
            'ext_order_id_valid' => subscription_payu_notify_valid_id($extOrderId),
            'status_valid' => is_string($payuStatus),
            'amount_valid' => $totalAmountMinor !== null,
            'currency_valid' => is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency) === 1,
        ]);
        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $rpcResult = payment_lifecycle_v3_rpc(
        'subscription_payment_apply_payu_notification',
        [
            'p_ext_order_id' => $extOrderId,
            'p_order_id' => $orderId,
            'p_payu_status' => $payuStatus,
            'p_total_amount_minor' => $totalAmountMinor,
            'p_currency' => $currency,
            'p_payload_sha256_hex' => hash('sha256', $rawBody),
        ]
    );

    if (empty($rpcResult['ok'])) {
        subscription_payu_notify_security_event(
            'subscription_payu_notify_rpc_failed',
            'rpc_failed',
            500,
            'error',
            'high',
            'rpc'
        );
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_RPC_FAILED', [
            'error_kind' => (string) ($rpcResult['error_kind'] ?? 'unknown'),
            'http_status' => (int) ($rpcResult['status'] ?? 0),
        ]);
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Nie udało się obsłużyć powiadomienia PayU.',
        ]);
    }

    $rpcData = $rpcResult['data'] ?? null;

    if (!subscription_payu_notify_valid_rpc_data($rpcData)) {
        subscription_payu_notify_security_event(
            'subscription_payu_notify_rpc_result_invalid',
            'rpc_result_invalid',
            500,
            'error',
            'critical',
            'rpc_response'
        );
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_RPC_RESULT_INVALID');
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Nie udało się obsłużyć powiadomienia PayU.',
        ]);
    }

    subscription_payu_notify_security_event(
        'subscription_payu_notify_processed',
        'subscription_payu_notify_processed',
        200,
        'success'
    );
    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PROCESSED', [
        'processed' => $rpcData['processed'],
        'idempotent' => $rpcData['idempotent'],
        'status' => $rpcData['status'],
    ]);

    subscription_payu_notify_json(200, [
        'success' => true,
    ]);
} catch (Throwable $e) {
    subscription_payu_notify_security_event(
        'subscription_payu_notify_fatal',
        'fatal',
        500,
        'error',
        'critical'
    );
    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_FATAL');

    subscription_payu_notify_json(500, [
        'success' => false,
        'error' => 'Błąd obsługi powiadomienia PayU.',
    ]);
}
