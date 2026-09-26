<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';

// Application limits; verify against legitimate PayU notifications before deployment.
const BOOKING_PAYU_NOTIFY_MAX_BODY_BYTES = 1048576;
const BOOKING_PAYU_NOTIFY_MAX_SIGNATURE_BYTES = 1024;

function booking_payu_notify_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function booking_payu_notify_debug(string $tag, array $context = []): void
{
    $safe = [];

    foreach ($context as $key => $value) {
        if (in_array($key, [
            'tenant_id',
            'booking_id',
            'payment_id',
            'order_id',
            'ext_order_id',
            'raw_body',
            'payload',
            'signature',
            'second_key',
        ], true)) {
            $safe[$key . '_set'] = is_scalar($value) ? trim((string) $value) !== '' : !empty($value);
            continue;
        }

        if (is_string($value)) {
            $safe[$key] = mb_substr($value, 0, 160);
        } elseif (is_scalar($value) || $value === null) {
            $safe[$key] = $value;
        } else {
            $safe[$key] = '[complex]';
        }
    }

    payu_debug($tag, $safe);
}

function booking_payu_notify_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        $value = $_SERVER[$serverKey];
        return is_string($value) && strlen($value) <= BOOKING_PAYU_NOTIFY_MAX_SIGNATURE_BYTES
            ? trim($value) : '';
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return is_string($value) && strlen($value) <= BOOKING_PAYU_NOTIFY_MAX_SIGNATURE_BYTES
                    ? trim($value) : '';
            }
        }
    }

    return '';
}

function booking_payu_notify_parse_signature(string $header): array
{
    if ($header === '' || strlen($header) > BOOKING_PAYU_NOTIFY_MAX_SIGNATURE_BYTES) {
        return [];
    }

    $result = [];

    foreach (explode(';', $header) as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        if (strpos($part, '=') === false) {
            return [];
        }

        [$key, $value] = explode('=', $part, 2);
        $key = strtolower(trim($key));
        $value = trim($value);

        if ($key === '' || array_key_exists($key, $result)) {
            return [];
        }

        $result[$key] = $value;
    }

    if (
        preg_match('/^[0-9a-f]{32}$/iD', $result['signature'] ?? '') !== 1
        || strtolower($result['algorithm'] ?? 'md5') !== 'md5'
    ) {
        return [];
    }

    return $result;
}

function booking_payu_notify_verify_signature(
    string $rawBody,
    string $secondKey,
    string $signatureHeader
): bool {
    if ($rawBody === '' || $secondKey === '' || $signatureHeader === '') {
        return false;
    }

    $signature = booking_payu_notify_parse_signature($signatureHeader);
    $incomingSignature = strtolower((string) ($signature['signature'] ?? ''));
    $algorithm = strtolower((string) ($signature['algorithm'] ?? 'md5'));

    if ($incomingSignature === '' || $algorithm !== 'md5') {
        booking_payu_notify_debug('PAYU_NOTIFY_SIGNATURE_UNSUPPORTED', [
            'algorithm_supported' => $algorithm === 'md5',
            'signature_present' => $incomingSignature !== '',
        ]);
        return false;
    }

    $expectedSignature = md5($rawBody . $secondKey);

    return hash_equals($expectedSignature, $incomingSignature);
}

function booking_payu_notify_valid_id($value): bool
{
    return is_string($value)
        && $value !== ''
        && strlen($value) <= 128
        && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
}

function booking_payu_notify_amount_minor($value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
        return null;
    }

    $canonical = ltrim($value, '0');

    if ($canonical === '') {
        return null;
    }

    $maximum = (string) PHP_INT_MAX;

    if (
        strlen($canonical) > strlen($maximum)
        || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)
    ) {
        return null;
    }

    $amount = (int) $canonical;

    return $amount > 0 ? $amount : null;
}

function booking_payu_notify_context_tenant($data): ?string
{
    if (!is_array($data)) {
        return null;
    }

    $tenantId = $data['tenant_id'] ?? null;

    if (!is_string($tenantId)) {
        return null;
    }

    $tenantId = trim($tenantId);

    return $tenantId !== '' ? $tenantId : null;
}

function booking_payu_notify_valid_apply_result($data): bool
{
    if (!is_array($data)) {
        return false;
    }

    $accepted = $data['accepted'] ?? null;
    $idempotent = $data['idempotent'] ?? null;
    $status = $data['status'] ?? null;
    $resolutionRequired = $data['resolution_required'] ?? null;

    return $accepted === true
        && is_bool($idempotent)
        && is_string($status)
        && in_array($status, ['pending', 'paid', 'failed', 'canceled', 'expired'], true)
        && is_bool($resolutionRequired);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        booking_payu_notify_response(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    // Cheap syntax gate only: this does NOT authenticate the request.
    // Do not perform context/config/decrypt work for absent or malformed signatures.
    $signatureHeader = booking_payu_notify_header('OpenPayu-Signature');
    if (booking_payu_notify_parse_signature($signatureHeader) === []) {
        booking_payu_notify_response(401, [
            'success' => false,
            'error' => 'Nieprawidłowy podpis PayU.',
        ]);
    }

    $declaredLength = null;
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $contentLength = $_SERVER['CONTENT_LENGTH'];
        if (!is_string($contentLength)
            || strlen($contentLength) > 20
            || preg_match('/^[0-9]+$/D', $contentLength) !== 1) {
            booking_payu_notify_response(400, [
                'success' => false,
                'error' => 'Nieprawidłowe powiadomienie PayU.',
            ]);
        }

        // Compare decimal strings before casting, including on 32-bit PHP.
        $canonicalLength = ltrim($contentLength, '0');
        $maximumLength = (string) BOOKING_PAYU_NOTIFY_MAX_BODY_BYTES;
        if (strlen($canonicalLength) > strlen($maximumLength)
            || (strlen($canonicalLength) === strlen($maximumLength)
                && strcmp($canonicalLength, $maximumLength) > 0)) {
            booking_payu_notify_response(413, [
                'success' => false,
                'error' => 'Powiadomienie PayU jest zbyt duże.',
            ]);
        }
        $declaredLength = (int) $canonicalLength;
    }

    // Missing Content-Length is allowed (e.g. chunked transport). Never rely on
    // its value to bound the read; the extra byte detects actual oversize bodies.
    $rawBody = file_get_contents('php://input', false, null, 0, BOOKING_PAYU_NOTIFY_MAX_BODY_BYTES + 1);

    if (is_string($rawBody) && strlen($rawBody) > BOOKING_PAYU_NOTIFY_MAX_BODY_BYTES) {
        booking_payu_notify_response(413, [
            'success' => false,
            'error' => 'Powiadomienie PayU jest zbyt duże.',
        ]);
    }

    if (!is_string($rawBody) || $rawBody === '') {
        booking_payu_notify_debug('PAYU_NOTIFY_BODY_MISSING');
        booking_payu_notify_response(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    if ($declaredLength !== null && strlen($rawBody) !== $declaredLength) {
        booking_payu_notify_response(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    // Pre-signature routing is deliberately limited to extOrderId. No status,
    // amount, currency or orderId is trusted before the tenant-specific second
    // key has been resolved and the exact raw body signature verified.
    $routingData = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($routingData)) {
        booking_payu_notify_debug('PAYU_NOTIFY_ROUTING_JSON_INVALID', [
            'body_length' => strlen($rawBody),
        ]);
        booking_payu_notify_response(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $routingOrder = is_array($routingData['order'] ?? null) ? $routingData['order'] : [];
    $routingExtOrderId = $routingOrder['extOrderId'] ?? null;

    if (!booking_payu_notify_valid_id($routingExtOrderId)) {
        booking_payu_notify_debug('PAYU_NOTIFY_ROUTING_EXT_ORDER_INVALID');
        booking_payu_notify_response(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $contextResult = payment_lifecycle_v3_rpc(
        'booking_payment_webhook_context',
        ['p_ext_order_id' => $routingExtOrderId]
    );

    if (empty($contextResult['ok'])) {
        $errorKind = (string) ($contextResult['error_kind'] ?? 'unknown');
        booking_payu_notify_debug('PAYU_NOTIFY_CONTEXT_FAILED', [
            'error_kind' => $errorKind,
            'rpc_status' => (int) ($contextResult['status'] ?? 0),
        ]);

        $rpcStatus = (int) ($contextResult['status'] ?? 0);
        $clientStatus = $errorKind === 'rpc_error' && $rpcStatus >= 400 && $rpcStatus < 500
            ? 400
            : 500;
        booking_payu_notify_response($clientStatus, [
            'success' => false,
            'error' => 'Nie udało się obsłużyć powiadomienia PayU.',
        ]);
    }

    $tenantId = booking_payu_notify_context_tenant($contextResult['data'] ?? null);

    if ($tenantId === null) {
        booking_payu_notify_debug('PAYU_NOTIFY_CONTEXT_INVALID');
        booking_payu_notify_response(500, [
            'success' => false,
            'error' => 'Nie udało się obsłużyć powiadomienia PayU.',
        ]);
    }

    $payu = payu_get_integration($tenantId);
    $secondKey = is_array($payu) ? trim((string) ($payu['second_key'] ?? '')) : '';

    if (!is_array($payu) || $secondKey === '') {
        booking_payu_notify_debug('PAYU_NOTIFY_INTEGRATION_MISSING', [
            'tenant_id' => $tenantId,
            'second_key_present' => $secondKey !== '',
        ]);
        booking_payu_notify_response(500, [
            'success' => false,
            'error' => 'Nie udało się obsłużyć powiadomienia PayU.',
        ]);
    }

    if (!booking_payu_notify_verify_signature($rawBody, $secondKey, $signatureHeader)) {
        booking_payu_notify_debug('PAYU_NOTIFY_SIGNATURE_INVALID', [
            'tenant_id' => $tenantId,
            'signature_header_present' => $signatureHeader !== '',
        ]);
        booking_payu_notify_response(401, [
            'success' => false,
            'error' => 'Nieprawidłowy podpis PayU.',
        ]);
    }

    // Re-parse only after successful signature verification. From this point the
    // provider fields can be validated and passed to the DB binding RPC.
    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        booking_payu_notify_debug('PAYU_NOTIFY_SIGNED_JSON_INVALID');
        booking_payu_notify_response(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $order = is_array($data['order'] ?? null) ? $data['order'] : [];
    $orderId = $order['orderId'] ?? null;
    $extOrderId = $order['extOrderId'] ?? null;
    $payuStatus = $order['status'] ?? null;
    $totalAmountMinor = booking_payu_notify_amount_minor($order['totalAmount'] ?? null);
    $currency = $order['currencyCode'] ?? null;

    $normalizedStatus = is_string($payuStatus) ? strtoupper(trim($payuStatus)) : '';
    $normalizedCurrency = is_string($currency) ? strtoupper(trim($currency)) : '';

    if (
        !booking_payu_notify_valid_id($orderId)
        || !booking_payu_notify_valid_id($extOrderId)
        || !hash_equals((string) $routingExtOrderId, (string) $extOrderId)
        || $normalizedStatus === ''
        || strlen($normalizedStatus) > 80
        || $totalAmountMinor === null
        || preg_match('/^[A-Z]{3}$/D', $normalizedCurrency) !== 1
    ) {
        booking_payu_notify_debug('PAYU_NOTIFY_FIELDS_INVALID', [
            'order_id_valid' => booking_payu_notify_valid_id($orderId),
            'ext_order_id_valid' => booking_payu_notify_valid_id($extOrderId),
            'routing_binding_valid' => is_string($extOrderId)
                && hash_equals((string) $routingExtOrderId, $extOrderId),
            'status_valid' => $normalizedStatus !== '' && strlen($normalizedStatus) <= 80,
            'amount_valid' => $totalAmountMinor !== null,
            'currency_valid' => preg_match('/^[A-Z]{3}$/D', $normalizedCurrency) === 1,
        ]);
        booking_payu_notify_response(400, [
            'success' => false,
            'error' => 'Nieprawidłowe powiadomienie PayU.',
        ]);
    }

    $applyResult = payment_lifecycle_v3_rpc(
        'booking_payment_apply_payu_notification',
        [
            'p_tenant_id' => $tenantId,
            'p_ext_order_id' => $extOrderId,
            'p_order_id' => $orderId,
            'p_payu_status' => $normalizedStatus,
            'p_total_amount_minor' => $totalAmountMinor,
            'p_currency' => $normalizedCurrency,
            'p_payload_sha256_hex' => hash('sha256', $rawBody),
        ]
    );

    if (empty($applyResult['ok'])) {
        booking_payu_notify_debug('PAYU_NOTIFY_APPLY_FAILED', [
            'error_kind' => (string) ($applyResult['error_kind'] ?? 'unknown'),
            'rpc_status' => (int) ($applyResult['status'] ?? 0),
            'provider_status_set' => $normalizedStatus !== '',
        ]);
        booking_payu_notify_response(500, [
            'success' => false,
            'error' => 'Nie udało się obsłużyć powiadomienia PayU.',
        ]);
    }

    $applyData = $applyResult['data'] ?? null;

    if (!booking_payu_notify_valid_apply_result($applyData)) {
        booking_payu_notify_debug('PAYU_NOTIFY_APPLY_RESULT_INVALID');
        booking_payu_notify_response(500, [
            'success' => false,
            'error' => 'Nie udało się obsłużyć powiadomienia PayU.',
        ]);
    }

    booking_payu_notify_debug('PAYU_NOTIFY_PROCESSED', [
        'provider_status_set' => $normalizedStatus !== '',
        'payment_status' => (string) $applyData['status'],
        'idempotent' => (bool) $applyData['idempotent'],
        'resolution_required' => (bool) $applyData['resolution_required'],
    ]);

    // PayU only needs acknowledgement. Do not expose internal identifiers or
    // lifecycle state in the public webhook response.
    booking_payu_notify_response(200, [
        'success' => true,
    ]);
} catch (Throwable $e) {
    booking_payu_notify_debug('PAYU_NOTIFY_FATAL', [
        'exception_type' => get_class($e),
    ]);
    booking_payu_notify_response(500, [
        'success' => false,
        'error' => 'Błąd obsługi powiadomienia PayU.',
    ]);
}
