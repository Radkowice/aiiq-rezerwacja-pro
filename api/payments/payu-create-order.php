<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function payu_create_order_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function payu_create_order_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result,
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $email = null,
    ?string $stage = null
): void {
    $details = [
        'reason' => $reason,
    ];

    if ($stage !== null && trim($stage) !== '') {
        $details['stage'] = trim($stage);
    }

    $context = [
        'action_key' => 'payu_create_order',
        'endpoint' => '/api/payments/payu-create-order.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'public',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    $tenantId = trim((string) $tenantId);
    if ($tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    // Security logs intentionally omit customer e-mail/PII.

    security_log_event($eventKey, $context);
}

function payu_get_session_booking_handoff(string $tenantId): string
{
    $handoff = $_SESSION['booking_payment_handoff'] ?? null;

    if (!is_array($handoff)) {
        return '';
    }

    $bookingId = trim((string)($handoff['booking_id'] ?? ''));
    $handoffTenantId = trim((string)($handoff['tenant_id'] ?? ''));
    $createdAt = (int)($handoff['created_at'] ?? 0);

    if ($bookingId === '' || $handoffTenantId === '' || $createdAt <= 0) {
        unset($_SESSION['booking_payment_handoff']);
        return '';
    }

    if (time() - $createdAt > 1200) {
        unset($_SESSION['booking_payment_handoff']);
        return '';
    }

    if (!hash_equals($tenantId, $handoffTenantId)) {
        return '';
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $bookingId)) {
        unset($_SESSION['booking_payment_handoff']);
        return '';
    }

    return $bookingId;
}

function payu_clear_session_booking_handoff(string $tenantId, string $bookingId): void
{
    $handoff = $_SESSION['booking_payment_handoff'] ?? null;

    if (!is_array($handoff)) {
        return;
    }

    $handoffBookingId = trim((string)($handoff['booking_id'] ?? ''));
    $handoffTenantId = trim((string)($handoff['tenant_id'] ?? ''));

    if (hash_equals($tenantId, $handoffTenantId) && hash_equals($bookingId, $handoffBookingId)) {
        unset($_SESSION['booking_payment_handoff']);
    }
}

function payu_store_session_payment_return_handoff(string $tenantId, string $bookingId): void
{
    $tenantId = trim($tenantId);
    $bookingId = trim($bookingId);

    if ($tenantId === '' || $bookingId === '') {
        return;
    }

    $_SESSION['booking_payment_return_handoff'] = [
        'booking_id' => $bookingId,
        'tenant_id' => $tenantId,
        'created_at' => time(),
    ];
}

function payu_get_customer_ip(): string
{
    $clientIp = security_client_ip();

    if (is_string($clientIp) && filter_var($clientIp, FILTER_VALIDATE_IP)) {
        return $clientIp;
    }

    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }

    return '127.0.0.1';
}

function payu_fetch_booking(string $bookingId, string $tenantId): ?array
{
    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_BOOKING_ENV_MISSING');
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/bookings'
        . '?select=id,tenant_id,email,name,booking_date,booking_time,status,payment_required,payment_status,payment_provider,payment_amount,payment_currency,payment_expires_at,payment_order_id,payment_lifecycle_version,payment_lifecycle_payment_id,booking_create_request_key,staff_id,service_name_snapshot'
        . '&id=eq.' . rawurlencode($bookingId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('PAYU_BOOKING_FETCH_ERROR', [
            'booking_id_set' => $bookingId !== '',
            'http_code' => $result['http_code'],
            'error' => $result['error'],
        ]);
        return null;
    }

    return $result['data'][0] ?? null;
}

function payu_fetch_staff_display_name(string $tenantId, string $staffId): string
{
    if ($tenantId === '' || $staffId === '') {
        return '';
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_debug('PAYU_STAFF_ENV_MISSING');
        return '';
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id,display_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&limit=1';

    $result = payu_supabase_request($url, 'GET', $supabaseKey, $schema);

    if ($result['error'] || $result['http_code'] !== 200) {
        payu_debug('PAYU_STAFF_FETCH_ERROR', [
            'tenant_id_set' => $tenantId !== '',
            'staff_id_set' => $staffId !== '',
            'http_code' => $result['http_code'],
            'error' => $result['error'],
        ]);
        return '';
    }

    return trim((string)($result['data'][0]['display_name'] ?? ''));
}

function payu_get_public_base_url(string $tenantHost): string
{
    $tenantHost = normalize_host($tenantHost);

    if (!tenant_host_is_valid($tenantHost)) {
        return '';
    }

    return 'https://' . $tenantHost;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        payu_create_order_security_event(
            'payu_create_order_method_not_allowed',
            'method_not_allowed',
            405,
            'failed',
            'low'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Metoda niedozwolona.'
        ], 405);
    }

    /*
     * Utworzenie płatności PayU nie przyjmuje już publicznego booking_id z JSON-a.
     * Techniczny identyfikator rezerwacji może pochodzić wyłącznie z backendowego handoffu sesyjnego.
     */
    $bookingId = '';

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
    $schema = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

    if ($supabaseUrl === '' || $supabaseKey === '') {
        payu_create_order_security_event(
            'payu_create_order_env_missing',
            'supabase_env_missing',
            500,
            'error',
            'high',
            null,
            null,
            'configuration'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Brak konfiguracji Supabase.'
        ], 500);
    }

    $tenantLookup = getTenantLookupFromHost($supabaseUrl, $supabaseKey, $schema);
    $hostTenantId = (($tenantLookup['status'] ?? '') === 'found')
        ? trim((string)($tenantLookup['tenant_id'] ?? ''))
        : '';
    $hostTenantDomain = (($tenantLookup['status'] ?? '') === 'found')
        ? normalize_host((string)($tenantLookup['host'] ?? ''))
        : '';

    if ($hostTenantId === '' || !tenant_host_is_valid($hostTenantDomain)) {
        payu_create_order_security_event(
            'payu_create_order_tenant_denied',
            'tenant_not_found',
            404,
            'failed',
            'medium',
            null,
            null,
            'tenant_lookup'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie rozpoznano klienta.'
        ], 404);
    }

    if ($bookingId === '') {
        $bookingId = payu_get_session_booking_handoff((string) $hostTenantId);
    }

    if ($bookingId === '') {
        payu_create_order_security_event(
            'payu_create_order_handoff_missing',
            'booking_payment_handoff_missing',
            400,
            'failed',
            'medium',
            (string) $hostTenantId,
            null,
            'handoff'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Brak aktywnej rezerwacji do płatności.'
        ], 400);
    }

    $booking = payu_fetch_booking($bookingId, (string) $hostTenantId);

    if (!$booking) {
        payu_create_order_security_event(
            'payu_create_order_booking_not_found',
            'booking_not_found',
            404,
            'failed',
            'medium',
            (string) $hostTenantId,
            null,
            'booking_lookup'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie znaleziono rezerwacji.'
        ], 404);
    }

    $tenantId = (string) ($booking['tenant_id'] ?? '');

    if ($tenantId === '') {
        payu_create_order_security_event(
            'payu_create_order_booking_invalid',
            'booking_tenant_missing',
            422,
            'failed',
            'medium',
            (string) $hostTenantId,
            null,
            'booking_validation'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie udało się przygotować płatności dla rezerwacji.'
        ], 422);
    }

    if (!hash_equals((string) $hostTenantId, $tenantId)) {
        payu_create_order_security_event(
            'payu_create_order_tenant_mismatch',
            'tenant_mismatch',
            404,
            'failed',
            'high',
            (string) $hostTenantId,
            null,
            'booking_validation'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie znaleziono rezerwacji.'
        ], 404);
    }

    $staffId = trim((string)($booking['staff_id'] ?? ''));

    if ($staffId !== '') {
        $staffDisplayName = payu_fetch_staff_display_name($tenantId, $staffId);

        if ($staffDisplayName !== '') {
            $booking['staff_display_name'] = $staffDisplayName;
        }
    }

    $paymentRequired = $booking['payment_required'] === true || $booking['payment_required'] === 'true';

    if (!$paymentRequired) {
        payu_create_order_security_event(
            'payu_create_order_payment_not_required',
            'payment_not_required',
            422,
            'failed',
            'low',
            $tenantId,
            null,
            'booking_validation'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Ta rezerwacja nie wymaga płatności.'
        ], 422);
    }

    $paymentStatus = strtolower(trim((string)($booking['payment_status'] ?? '')));

    if ($paymentStatus === 'paid') {
        payu_create_order_security_event(
            'payu_create_order_already_paid',
            'booking_already_paid',
            422,
            'failed',
            'low',
            $tenantId,
            null,
            'booking_validation'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Ta rezerwacja jest już opłacona.'
        ], 422);
    }

    // A7 only: this endpoint must never mutate a legacy booking/payment directly.
    $lifecycleVersion = (int)($booking['payment_lifecycle_version'] ?? 0);
    $paymentId = trim((string)($booking['payment_lifecycle_payment_id'] ?? ''));
    $requestKey = trim((string)($booking['booking_create_request_key'] ?? ''));
    $paymentProvider = strtolower(trim((string)($booking['payment_provider'] ?? '')));

    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    $requestKeyValid = $requestKey !== ''
        && strlen($requestKey) <= 160
        && preg_match('/[[:cntrl:]]/', $requestKey) !== 1;

    if ($lifecycleVersion !== 1
        || preg_match($uuidPattern, $paymentId) !== 1
        || !$requestKeyValid
        || $paymentProvider !== 'payu') {
        payu_create_order_security_event(
            'payu_create_order_lifecycle_invalid',
            'a7_lifecycle_context_invalid',
            409,
            'denied',
            'high',
            $tenantId,
            null,
            'lifecycle_validation'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie można bezpiecznie rozpocząć płatności dla tej rezerwacji.'
        ], 409);
    }

    // booking_payment_atomic_create() derives this exact payment key in DB.
    // Reconstruct it only from the DB-owned booking_create_request_key, never from frontend input.
    $paymentIdempotencyKey = 'booking-create-payment-v1:' . $requestKey;

    if (!tenant_has_feature($tenantId, 'online_payments') || !tenant_has_feature($tenantId, 'payu')) {
        payu_create_order_security_event(
            'payu_create_order_feature_denied',
            'payu_feature_denied',
            403,
            'denied',
            'medium',
            $tenantId,
            null,
            'feature_check'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Płatności online PayU są niedostępne w aktualnym planie.',
            'upgrade_required' => true,
        ], 403);
    }

    $payu = payu_get_integration($tenantId);

    if (!$payu) {
        payu_create_order_security_event(
            'payu_create_order_integration_missing',
            'payu_integration_missing',
            422,
            'failed',
            'medium',
            $tenantId,
            null,
            'integration_lookup'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Integracja PayU nie jest skonfigurowana albo jest wyłączona.'
        ], 422);
    }

    $publicBaseUrl = payu_get_public_base_url($hostTenantDomain);

    if ($publicBaseUrl === '') {
        payu_create_order_security_event(
            'payu_create_order_base_url_missing',
            'public_base_url_missing',
            500,
            'error',
            'medium',
            $tenantId,
            null,
            'configuration'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie udało się ustalić publicznego adresu aplikacji.'
        ], 500);
    }

    // Durable one-POST gate. If the RPC response is ambiguous, retrying this endpoint
    // must not assume that a second provider POST is safe.
    $markResult = payment_lifecycle_v3_rpc('booking_payment_mark_provider_started', [
        'p_tenant_id' => $tenantId,
        'p_booking_id' => $bookingId,
        'p_payment_id' => $paymentId,
        'p_idempotency_key' => $paymentIdempotencyKey,
    ]);

    if (empty($markResult['ok']) || !is_array($markResult['data'] ?? null)) {
        payu_create_order_security_event(
            'payu_create_order_mark_started_unknown',
            'mark_provider_started_result_unknown',
            503,
            'error',
            'high',
            $tenantId,
            null,
            'provider_gate'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie udało się bezpiecznie potwierdzić stanu płatności.',
        ], 503);
    }

    $markData = $markResult['data'];
    $markedPaymentId = trim((string)($markData['payment_id'] ?? ''));
    $extOrderId = trim((string)($markData['ext_order_id'] ?? ''));
    $amountMinor = $markData['amount_minor'] ?? null;
    $currency = strtoupper(trim((string)($markData['currency'] ?? '')));
    $mayPost = ($markData['may_post'] ?? null) === true;

    $extOrderValid = $extOrderId !== ''
        && strlen($extOrderId) <= 128
        && preg_match('/^[A-Za-z0-9_-]+$/', $extOrderId) === 1;

    $bookingAmountMinor = isset($booking['payment_amount']) && is_numeric($booking['payment_amount'])
        ? (int)round(((float)$booking['payment_amount']) * 100)
        : 0;
    $bookingCurrency = strtoupper(trim((string)($booking['payment_currency'] ?? '')));

    if ($markedPaymentId === ''
        || !hash_equals($paymentId, $markedPaymentId)
        || !$extOrderValid
        || !is_numeric($amountMinor)
        || (int)$amountMinor <= 0
        || $currency === ''
        || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        || $bookingAmountMinor <= 0
        || $bookingAmountMinor !== (int)$amountMinor
        || $bookingCurrency === ''
        || !hash_equals($bookingCurrency, $currency)) {
        payu_create_order_security_event(
            'payu_create_order_mark_started_mismatch',
            'provider_gate_binding_mismatch',
            503,
            'denied',
            'high',
            $tenantId,
            null,
            'provider_gate'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie udało się bezpiecznie potwierdzić parametrów płatności.',
        ], 503);
    }

    if (!$mayPost) {
        // Fail closed on every replay/denied gate. The authoritative RPC evaluates
        // payment state, booking state, deadline and open-resolution conditions under
        // lock, but it does not return enough reason data to prove that a persisted
        // redirect is still safe to reuse. Never return an old payment_url here and
        // never issue another external create-order POST.
        payu_create_order_security_event(
            'payu_create_order_post_not_authorized',
            'provider_post_not_authorized',
            202,
            'pending',
            'medium',
            $tenantId,
            null,
            'provider_gate'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Stan płatności wymaga potwierdzenia. Nie ponawiamy automatycznie utworzenia zamówienia PayU.',
        ], 202);
    }

    $bookingDate = (string)($booking['booking_date'] ?? '');
    $bookingTime = (string)($booking['booking_time'] ?? '');
    $customerName = trim((string)($booking['name'] ?? 'Klient'));
    $customerEmail = trim((string)($booking['email'] ?? ''));
    $serviceName = trim((string)($booking['service_name_snapshot'] ?? ''));
    $description = 'Rezerwacja: ' . ($serviceName !== '' ? $serviceName : 'termin');

    if ($bookingDate !== '' || $bookingTime !== '') {
        $description .= ' - ' . trim($bookingDate . ' ' . $bookingTime);
    }

    $orderPayload = [
        'notifyUrl' => $publicBaseUrl . '/api/payments/payu-notify.php',
        'continueUrl' => $publicBaseUrl . '/platnosc-powrot.html',
        'customerIp' => payu_get_customer_ip(),
        'merchantPosId' => $payu['pos_id'],
        'description' => $description,
        'currencyCode' => $currency,
        'totalAmount' => (string)(int)$amountMinor,
        'extOrderId' => $extOrderId,
        'products' => [
            [
                'name' => $description,
                'unitPrice' => (string)(int)$amountMinor,
                'quantity' => '1',
            ],
        ],
    ];

    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $orderPayload['buyer'] = [
            'email' => $customerEmail,
            'firstName' => $customerName,
        ];
    }

    payu_debug('PAYU_CREATE_ORDER_A7_REQUEST', [
        'booking_id_set' => $bookingId !== '',
        'tenant_id_set' => $tenantId !== '',
        'payment_id_set' => $paymentId !== '',
        'amount_minor' => (int)$amountMinor,
        'currency' => $currency,
        'mode' => (string)($payu['mode'] ?? ''),
    ]);

    $created = payu_create_order_lifecycle_v7($payu, $orderPayload);

    $resultKind = strtolower(trim((string)($created['result_kind'] ?? '')));
    $bodyAvailable = ($created['body_available'] ?? null) === true;
    $responseSha = $created['response_sha256'] ?? null;
    $operationFingerprint = $created['operation_fingerprint'] ?? null;
    $transportStage = strtolower(trim((string)($created['transport_stage'] ?? '')));
    $providerDefinitive = ($created['provider_contract_definitive'] ?? null) === true;
    $providerOrderId = trim((string)($created['order_id'] ?? ''));
    $redirectUri = trim((string)($created['redirect_uri'] ?? ''));
    $httpCodeRaw = (int)($created['http_code'] ?? 0);
    $httpCode = ($httpCodeRaw >= 100 && $httpCodeRaw <= 599) ? $httpCodeRaw : null;
    $errorCode = strtolower(trim((string)($created['error_code'] ?? '')));
    $payuStatus = strtoupper(trim((string)($created['payu_status'] ?? '')));

    $validKinds = ['order_created', 'result_unknown', 'definitive_failure', 'local_failure'];
    $shaValid = is_string($responseSha) && preg_match('/^[0-9a-f]{64}$/', $responseSha) === 1;
    $operationValid = is_string($operationFingerprint) && preg_match('/^[0-9a-f]{64}$/', $operationFingerprint) === 1;
    $evidenceShapeValid = in_array($resultKind, $validKinds, true)
        && in_array($transportStage, ['pre_post', 'request_started', 'response_received'], true)
        && (($bodyAvailable && $shaValid && $operationFingerprint === null)
            || (!$bodyAvailable && $responseSha === null && $operationValid));

    $providerOrderValid = $providerOrderId === ''
        || (strlen($providerOrderId) <= 128 && preg_match('/^[A-Za-z0-9_-]+$/', $providerOrderId) === 1);
    $redirectParts = $redirectUri !== '' ? parse_url($redirectUri) : false;
    $redirectEvidenceValid = $redirectUri === '' || (
        is_array($redirectParts)
        && strtolower((string)($redirectParts['scheme'] ?? '')) === 'https'
        && trim((string)($redirectParts['host'] ?? '')) !== ''
        && strlen($redirectUri) <= 2048
    );

    $matrixValid = false;
    if ($resultKind === 'local_failure') {
        $matrixValid = !$bodyAvailable
            && $transportStage === 'pre_post'
            && !$providerDefinitive
            && $providerOrderId === ''
            && $redirectUri === '';
    } elseif ($resultKind === 'result_unknown') {
        $matrixValid = !$providerDefinitive
            && (($bodyAvailable && $transportStage === 'response_received')
                || (!$bodyAvailable && in_array($transportStage, ['request_started', 'response_received'], true)))
            && $providerOrderId === ''
            && $redirectUri === '';
    } elseif ($resultKind === 'order_created') {
        $matrixValid = $bodyAvailable
            && $transportStage === 'response_received'
            && $providerDefinitive
            && $payuStatus === 'SUCCESS'
            && $providerOrderId !== ''
            && $providerOrderValid
            && $redirectUri !== ''
            && $redirectEvidenceValid;
    } elseif ($resultKind === 'definitive_failure') {
        $matrixValid = $bodyAvailable
            && $transportStage === 'response_received'
            && $providerDefinitive
            && $providerOrderId === ''
            && $redirectUri === '';
    }

    if (!$evidenceShapeValid || !$providerOrderValid || !$redirectEvidenceValid || !$matrixValid) {
        // The POST may already have started. Do not invent provider evidence and do not retry.
        payu_create_order_security_event(
            'payu_create_order_evidence_invalid',
            'provider_evidence_invalid',
            503,
            'error',
            'high',
            $tenantId,
            null,
            'provider_result'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Nie udało się bezpiecznie potwierdzić wyniku PayU.',
        ], 503);
    }

    $recordResult = payment_lifecycle_v3_rpc('booking_payment_record_provider_result', [
        'p_tenant_id' => $tenantId,
        'p_booking_id' => $bookingId,
        'p_payment_id' => $paymentId,
        'p_idempotency_key' => $paymentIdempotencyKey,
        'p_result_kind' => $resultKind,
        'p_payu_order_id' => $providerOrderId !== '' ? $providerOrderId : null,
        'p_redirect_uri' => $redirectUri !== '' ? $redirectUri : null,
        'p_http_status' => $httpCode,
        'p_error_code' => $errorCode !== '' ? $errorCode : null,
        'p_body_available' => $bodyAvailable,
        'p_payload_sha256_hex' => $bodyAvailable ? $responseSha : null,
        'p_operation_fingerprint_hex' => $bodyAvailable ? null : $operationFingerprint,
        'p_transport_stage' => $transportStage,
        'p_provider_contract_definitive' => $providerDefinitive,
    ]);

    if (empty($recordResult['ok']) || !is_array($recordResult['data'] ?? null)) {
        payu_create_order_security_event(
            'payu_create_order_result_record_unknown',
            'provider_result_record_unknown',
            503,
            'error',
            'high',
            $tenantId,
            null,
            'provider_result'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Wynik PayU wymaga bezpiecznej weryfikacji. Nie ponawiamy automatycznie płatności.',
        ], 503);
    }

    $recordData = $recordResult['data'];
    $recordedState = strtolower(trim((string)($recordData['provider_state'] ?? '')));
    $recordedStatus = strtolower(trim((string)($recordData['status'] ?? '')));
    $recordedRedirect = trim((string)($recordData['redirect_uri'] ?? ''));

    if ($resultKind === 'order_created') {
        $redirectParts = $recordedRedirect !== '' ? parse_url($recordedRedirect) : false;
        $redirectValid = is_array($redirectParts)
            && strtolower((string)($redirectParts['scheme'] ?? '')) === 'https'
            && trim((string)($redirectParts['host'] ?? '')) !== ''
            && strlen($recordedRedirect) <= 2048;

        if ($recordedState === 'order_created' && $recordedStatus === 'pending' && $redirectValid) {
            payu_store_session_payment_return_handoff((string)$hostTenantId, $bookingId);
            payu_clear_session_booking_handoff((string)$hostTenantId, $bookingId);

            payu_create_order_security_event(
                'payu_create_order_success',
                'payu_create_order_success',
                200,
                'success',
                'medium',
                $tenantId,
                null,
                'success'
            );
            payu_create_order_response([
                'success' => true,
                'payment_url' => $recordedRedirect,
            ], 200);
        }

        // A verified webhook/reconciliation may have advanced the state before this
        // late create response was persisted. Do not expose an unconfirmed redirect.
        payu_create_order_security_event(
            'payu_create_order_late_result',
            'provider_state_advanced_before_redirect',
            202,
            'pending',
            'medium',
            $tenantId,
            null,
            'provider_result'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Stan płatności został już dalej przetworzony i wymaga potwierdzenia.',
        ], 202);
    }

    if ($resultKind === 'result_unknown') {
        payu_create_order_security_event(
            'payu_create_order_result_unknown',
            'provider_result_unknown',
            202,
            'pending',
            'medium',
            $tenantId,
            null,
            'provider_result'
        );
        payu_create_order_response([
            'success' => false,
            'error' => 'Wynik płatności wymaga potwierdzenia. Nie ponawiamy automatycznie zamówienia PayU.',
        ], 202);
    }

    payu_create_order_security_event(
        'payu_create_order_failed',
        $resultKind === 'local_failure' ? 'provider_local_failure' : 'provider_definitive_failure',
        502,
        'failed',
        'high',
        $tenantId,
        null,
        'provider_result'
    );
    payu_create_order_response([
        'success' => false,
        'error' => 'Nie udało się rozpocząć płatności PayU dla zapisanej rezerwacji.',
    ], 502);

} catch (Throwable $e) {
    payu_debug('PAYU_CREATE_ORDER_FATAL', [
        'exception_type' => get_class($e),
    ]);

    payu_create_order_security_event(
        'payu_create_order_fatal',
        'payu_create_order_fatal',
        500,
        'error',
        'high',
        isset($tenantId) ? (string) $tenantId : (isset($hostTenantId) ? (string) $hostTenantId : null),
        isset($customerEmail) ? (string) $customerEmail : null,
        'fatal'
    );

    payu_create_order_response([
        'success' => false,
        'error' => 'Błąd tworzenia płatności PayU.',
    ], 500);
}
