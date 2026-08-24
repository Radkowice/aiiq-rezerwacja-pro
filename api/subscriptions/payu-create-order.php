<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/aiiq_payu.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();
require_csrf_token();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const SUBSCRIPTION_PAYU_TERMS_VERSION = 'platform_terms_v2';
const SUBSCRIPTION_PAYU_PRIVACY_VERSION = 'platform_privacy_v2';


function subscription_payu_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $userId = null,
    ?string $email = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'subscription_payu_create_order',
        'endpoint' => '/api/subscriptions/payu-create-order.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'email' => $email,
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

function subscription_payu_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_payu_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function subscription_payu_scalar_string($value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function subscription_payu_valid_uuid(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $value
    ) === 1;
}

function subscription_payu_valid_idempotency_key(string $value): bool
{
    return preg_match(
        '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{64})$/i',
        $value
    ) === 1;
}

function subscription_payu_rpc(string $functionName, array $payload): ?array
{
    $result = payment_lifecycle_v3_rpc($functionName, $payload);

    if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_LIFECYCLE_RPC_ERROR', [
            'rpc' => $functionName,
            'error_kind' => subscription_payu_scalar_string($result['error_kind'] ?? ''),
            'error_code' => subscription_payu_scalar_string($result['error'] ?? ''),
            'http_status' => (int) ($result['status'] ?? 0),
        ]);

        return null;
    }

    return $result['data'];
}

function subscription_payu_valid_email(?string $email): string
{
    $email = trim((string) $email);

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function subscription_payu_public_base_url(): string
{
    $scheme = 'https';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0] ?? ''));

        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
        }
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? '';

    $host = trim(explode(',', (string) $host)[0] ?? '');

    if ($host === '' || !preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
        return '';
    }

    return $scheme . '://' . $host;
}

function subscription_payu_rpc_ip_address(): ?string
{
    $ipAddress = trim((string) security_client_ip());

    return filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
}

function subscription_payu_rpc_user_agent(): ?string
{
    $userAgent = trim((string) security_user_agent());

    if ($userAgent === '' || strlen($userAgent) > 1024 || preg_match('/[[:cntrl:]]/', $userAgent) === 1) {
        return null;
    }

    return $userAgent;
}

function subscription_payu_provider_http_status($value): ?int
{
    $status = is_numeric($value) ? (int) $value : 0;

    return $status >= 100 && $status <= 599 ? $status : null;
}

function subscription_payu_provider_status($value): ?string
{
    $status = subscription_payu_scalar_string($value);

    if ($status === '' || strlen($status) > 64 || preg_match('/^[A-Za-z0-9_.:-]+$/', $status) !== 1) {
        return null;
    }

    return $status;
}

function subscription_payu_provider_error_code($value): ?string
{
    $errorCode = strtolower(subscription_payu_scalar_string($value));

    if ($errorCode === '' || strlen($errorCode) > 80 || preg_match('/^[a-z0-9_.:-]+$/', $errorCode) !== 1) {
        return null;
    }

    return $errorCode;
}

function subscription_payu_provider_payload_hash($value): ?string
{
    $hash = strtolower(subscription_payu_scalar_string($value));

    return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
}

function subscription_payu_store_return_handoff(string $tenantId, string $paymentId): void
{
    $_SESSION['subscription_payment_return_handoff'] = [
        'tenant_id' => $tenantId,
        'payment_id' => $paymentId,
        'created_at' => time(),
    ];
}

function subscription_payu_unresolved_response(
    string $tenantId,
    string $userId,
    ?string $email,
    string $stage
): void {
    subscription_payu_security_event(
        'subscription_payu_create_order_unresolved',
        'provider_result_unresolved',
        202,
        'pending',
        'high',
        $tenantId,
        $userId,
        $email,
        $stage
    );
    subscription_payu_json(202, [
        'success' => false,
        'unresolved' => true,
        'retry_allowed' => false,
        'error' => 'Wynik przygotowania płatności jest nierozstrzygnięty. Nie ponawiaj płatności. Sprawdź jej status później albo skontaktuj się z obsługą.',
    ]);
}

function subscription_payu_terminal_response(
    string $status,
    string $tenantId,
    string $userId,
    ?string $email
): void {
    $retryAllowed = in_array($status, ['failed', 'canceled', 'expired'], true);
    subscription_payu_security_event(
        'subscription_payu_create_order_terminal_replay',
        'terminal_replay',
        409,
        'denied',
        'medium',
        $tenantId,
        $userId,
        $email,
        'lifecycle_replay'
    );
    subscription_payu_json(409, [
        'success' => false,
        'retry_allowed' => $retryAllowed,
        'new_intent_required' => $retryAllowed,
        'error' => $retryAllowed
            ? 'Poprzednia próba płatności została zakończona. Możesz rozpocząć nową płatność.'
            : 'Ta płatność została już zakończona.',
    ]);
}

function subscription_payu_created_response(
    array $recorded,
    string $tenantId,
    string $userId,
    string $paymentId,
    ?string $email
): void {
    $providerState = subscription_payu_scalar_string($recorded['provider_state'] ?? '');
    $paymentUrl = subscription_payu_scalar_string($recorded['payment_url'] ?? '');
    $orderId = subscription_payu_scalar_string($recorded['order_id'] ?? '');

    if ($providerState !== 'order_created' || $paymentUrl === '' || $orderId === '') {
        subscription_payu_unresolved_response($tenantId, $userId, $email, 'recorded_result_invalid');
    }

    subscription_payu_store_return_handoff($tenantId, $paymentId);
    subscription_payu_security_event(
        'subscription_payu_create_order_success',
        'subscription_payu_create_order_success',
        200,
        'success',
        'medium',
        $tenantId,
        $userId,
        $email
    );
    subscription_payu_json(200, [
        'success' => true,
        'payment_url' => $paymentUrl,
    ]);
}

function subscription_payu_existing_state_response(
    string $providerState,
    string $status,
    string $tenantId,
    string $userId,
    string $paymentId,
    string $idempotencyKey,
    ?string $email
): void {
    if (in_array($providerState, ['request_in_flight', 'result_unknown'], true)) {
        subscription_payu_unresolved_response($tenantId, $userId, $email, 'lifecycle_replay');
    }

    if ($providerState === 'terminal') {
        subscription_payu_terminal_response($status, $tenantId, $userId, $email);
    }

    if ($providerState === 'order_created') {
        $recorded = subscription_payu_rpc('subscription_payment_record_provider_result', [
            'p_tenant_id' => $tenantId,
            'p_user_id' => $userId,
            'p_payment_id' => $paymentId,
            'p_idempotency_key' => $idempotencyKey,
            'p_result_kind' => AI_IQ_PAYU_ORDER_RESULT_CREATED,
            'p_payu_order_id' => null,
            'p_payment_url' => null,
            'p_payu_status' => null,
            'p_http_status' => null,
            'p_error_code' => null,
            'p_payload_sha256_hex' => null,
        ]);

        if ($recorded === null) {
            subscription_payu_unresolved_response($tenantId, $userId, $email, 'order_created_replay_read');
        }

        subscription_payu_created_response($recorded, $tenantId, $userId, $paymentId, $email);
    }

    subscription_payu_security_event(
        'subscription_payu_create_order_state_invalid',
        'provider_state_invalid',
        500,
        'error',
        'high',
        $tenantId,
        $userId,
        $email,
        'lifecycle_replay'
    );
    subscription_payu_json(500, [
        'success' => false,
        'retry_allowed' => false,
        'error' => 'Nie udało się bezpiecznie ustalić stanu płatności.',
    ]);
}

$providerPostGranted = false;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        subscription_payu_security_event('subscription_payu_create_order_method_not_allowed', 'method_not_allowed', 405);
        subscription_payu_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
        subscription_payu_security_event('subscription_payu_create_order_unauthorized', 'unauthorized', 401);
        subscription_payu_json(401, [
            'success' => false,
            'error' => 'Brak autoryzacji.',
        ]);
    }

    $userId = trim((string) $_SESSION['user']['id']);
    $tenantId = trim((string) $_SESSION['user']['tenant_id']);
    $sessionRole = strtolower(trim((string) ($_SESSION['user']['role'] ?? '')));

    if (!subscription_payu_valid_uuid($userId) || $tenantId === '') {
        subscription_payu_security_event('subscription_payu_create_order_unauthorized', 'invalid_session_context', 401);
        subscription_payu_json(401, [
            'success' => false,
            'error' => 'Brak autoryzacji.',
        ]);
    }

    if (!in_array($sessionRole, ['admin', 'administrator'], true)) {
        subscription_payu_security_event('subscription_payu_create_order_forbidden', 'forbidden', 403, 'denied', 'medium', $tenantId, $userId);
        subscription_payu_json(403, [
            'success' => false,
            'error' => 'Brak uprawnień administratora.',
        ]);
    }

    $input = subscription_payu_input();
    $billingPeriod = strtolower(trim((string) ($input['billing_period'] ?? '')));
    $paymentType = strtolower(trim((string) ($input['payment_type'] ?? '')));
    $idempotencyKey = strtolower(trim((string) ($input['idempotency_key'] ?? '')));
    $termsAccepted = ($input['terms_accepted'] ?? null) === true;
    $privacyAccepted = ($input['privacy_accepted'] ?? null) === true;

    if (!in_array($billingPeriod, ['monthly', 'yearly'], true)) {
        subscription_payu_security_event('subscription_payu_create_order_validation_failed', 'invalid_billing_period', 400, 'failed', 'medium', $tenantId, $userId);
        subscription_payu_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy okres rozliczeniowy.',
        ]);
    }

    if (!in_array($paymentType, ['subscription_upgrade', 'subscription_renewal'], true)) {
        subscription_payu_security_event('subscription_payu_create_order_validation_failed', 'invalid_payment_type', 400, 'failed', 'medium', $tenantId, $userId);
        subscription_payu_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy typ płatności abonamentu.',
        ]);
    }

    if (!subscription_payu_valid_idempotency_key($idempotencyKey)) {
        subscription_payu_security_event('subscription_payu_create_order_validation_failed', 'invalid_idempotency_key', 400, 'failed', 'medium', $tenantId, $userId);
        subscription_payu_json(400, [
            'success' => false,
            'error' => 'Nie udało się bezpiecznie zidentyfikować intencji płatności.',
        ]);
    }

    if (!$termsAccepted || !$privacyAccepted) {
        subscription_payu_security_event('subscription_payu_create_order_validation_failed', 'document_consent_required', 400, 'failed', 'medium', $tenantId, $userId);
        subscription_payu_json(400, [
            'success' => false,
            'error' => 'Zaakceptuj Regulamin oraz Politykę prywatności przed przejściem do płatności.',
        ]);
    }

    $supabaseUrl = rtrim(trim((string) getenv('SUPABASE_URL')), '/');
    $supabaseKey = trim((string) getenv('SUPABASE_SERVICE_ROLE_KEY'));
    $schema = payment_lifecycle_v3_schema();

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_payu_security_event('subscription_payu_create_order_env_missing', 'env_missing', 500, 'error', 'high', $tenantId ?? null, $userId ?? null);
        subscription_payu_json(500, [
            'success' => false,
            'error' => 'Płatność za plan Pro jest chwilowo niedostępna.',
        ]);
    }

    if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
        subscription_payu_security_event('subscription_payu_create_order_tenant_denied', 'tenant_denied', 401, 'denied', 'high', $tenantId, $userId);
        subscription_payu_json(401, [
            'success' => false,
            'error' => 'Sesja nie pasuje do domeny.',
        ]);
    }

    $payuConfigResult = aiiq_payu_config();

    if (empty($payuConfigResult['success']) || !is_array($payuConfigResult['config'] ?? null)) {
        subscription_payu_security_event('subscription_payu_create_order_integration_missing', 'integration_missing', 503, 'error', 'medium', $tenantId, $userId);
        subscription_payu_json(503, [
            'success' => false,
            'retry_allowed' => true,
            'error' => 'Płatność za plan Pro jest chwilowo niedostępna. Spróbuj ponownie później.',
        ]);
    }

    $payu = $payuConfigResult['config'];
    $publicBaseUrl = subscription_payu_public_base_url();

    if ($publicBaseUrl === '') {
        subscription_payu_security_event('subscription_payu_create_order_base_url_missing', 'base_url_missing', 500, 'error', 'medium', $tenantId, $userId);
        subscription_payu_json(500, [
            'success' => false,
            'retry_allowed' => true,
            'error' => 'Nie udało się ustalić publicznego adresu aplikacji.',
        ]);
    }

    $begin = subscription_payu_rpc('subscription_payment_begin', [
        'p_tenant_id' => $tenantId,
        'p_user_id' => $userId,
        'p_payment_type' => $paymentType,
        'p_billing_period' => $billingPeriod,
        'p_idempotency_key' => $idempotencyKey,
        'p_terms_version' => SUBSCRIPTION_PAYU_TERMS_VERSION,
        'p_privacy_version' => SUBSCRIPTION_PAYU_PRIVACY_VERSION,
        'p_terms_accepted' => true,
        'p_privacy_accepted' => true,
        'p_ip_address' => subscription_payu_rpc_ip_address(),
        'p_user_agent' => subscription_payu_rpc_user_agent(),
    ]);

    if ($begin === null) {
        subscription_payu_security_event('subscription_payu_create_order_begin_failed', 'lifecycle_begin_failed', 503, 'error', 'high', $tenantId, $userId, null, 'lifecycle_begin');
        subscription_payu_json(503, [
            'success' => false,
            'retry_allowed' => true,
            'error' => 'Nie udało się bezpiecznie przygotować płatności. Spróbuj ponownie z tą samą intencją.',
        ]);
    }

    $paymentId = subscription_payu_scalar_string($begin['payment_id'] ?? '');
    $providerState = subscription_payu_scalar_string($begin['provider_state'] ?? '');
    $paymentStatus = strtolower(subscription_payu_scalar_string($begin['status'] ?? ''));
    $buyerEmail = subscription_payu_valid_email(subscription_payu_scalar_string($begin['buyer_email'] ?? ''));

    if ($buyerEmail === '') {
        $buyerEmail = subscription_payu_valid_email((string) ($_SESSION['user']['email'] ?? ''));
    }

    if (
        !subscription_payu_valid_uuid($paymentId)
        || !in_array($providerState, ['not_started', 'request_in_flight', 'order_created', 'result_unknown', 'terminal'], true)
    ) {
        subscription_payu_security_event('subscription_payu_create_order_begin_invalid', 'lifecycle_begin_invalid', 500, 'error', 'high', $tenantId, $userId, $buyerEmail ?: null, 'lifecycle_begin');
        subscription_payu_json(500, [
            'success' => false,
            'retry_allowed' => false,
            'error' => 'Nie udało się bezpiecznie ustalić stanu płatności.',
        ]);
    }

    if ($providerState !== 'not_started') {
        subscription_payu_existing_state_response(
            $providerState,
            $paymentStatus,
            $tenantId,
            $userId,
            $paymentId,
            $idempotencyKey,
            $buyerEmail ?: null
        );
    }

    $extOrderId = subscription_payu_scalar_string($begin['ext_order_id'] ?? '');
    $planCode = strtolower(subscription_payu_scalar_string($begin['plan_code'] ?? ''));
    $rpcBillingPeriod = strtolower(subscription_payu_scalar_string($begin['billing_period'] ?? ''));
    $amountMinor = subscription_payu_scalar_string($begin['amount_minor'] ?? '');
    $currency = strtoupper(subscription_payu_scalar_string($begin['currency'] ?? ''));
    $payuCurrency = strtoupper(subscription_payu_scalar_string($payu['currency'] ?? ''));

    if (
        $extOrderId === ''
        || strlen($extOrderId) > 160
        || preg_match('/^[A-Za-z0-9_-]+$/', $extOrderId) !== 1
        || $planCode !== 'pro'
        || $rpcBillingPeriod !== $billingPeriod
        || preg_match('/^[1-9][0-9]*$/', $amountMinor) !== 1
        || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        || $currency !== $payuCurrency
        || $buyerEmail === ''
    ) {
        subscription_payu_security_event('subscription_payu_create_order_begin_payload_invalid', 'lifecycle_payload_invalid', 500, 'error', 'high', $tenantId, $userId, $buyerEmail ?: null, 'lifecycle_begin');
        subscription_payu_json(500, [
            'success' => false,
            'retry_allowed' => false,
            'error' => 'Nie udało się bezpiecznie przygotować danych płatności.',
        ]);
    }

    $marked = subscription_payu_rpc('subscription_payment_mark_provider_started', [
        'p_tenant_id' => $tenantId,
        'p_user_id' => $userId,
        'p_payment_id' => $paymentId,
        'p_idempotency_key' => $idempotencyKey,
    ]);

    if ($marked === null) {
        subscription_payu_unresolved_response($tenantId, $userId, $buyerEmail, 'provider_start_rpc');
    }

    if (($marked['may_post'] ?? null) !== true) {
        subscription_payu_existing_state_response(
            subscription_payu_scalar_string($marked['provider_state'] ?? ''),
            strtolower(subscription_payu_scalar_string($marked['status'] ?? '')),
            $tenantId,
            $userId,
            $paymentId,
            $idempotencyKey,
            $buyerEmail
        );
    }

    $markedPaymentId = subscription_payu_scalar_string($marked['payment_id'] ?? '');
    $markedExtOrderId = subscription_payu_scalar_string($marked['ext_order_id'] ?? '');
    $markedPlanCode = strtolower(subscription_payu_scalar_string($marked['plan_code'] ?? ''));
    $markedBillingPeriod = strtolower(subscription_payu_scalar_string($marked['billing_period'] ?? ''));
    $markedAmountMinor = subscription_payu_scalar_string($marked['amount_minor'] ?? '');
    $markedCurrency = strtoupper(subscription_payu_scalar_string($marked['currency'] ?? ''));

    if (
        $markedPaymentId !== $paymentId
        || $markedExtOrderId !== $extOrderId
        || $markedPlanCode !== 'pro'
        || $markedBillingPeriod !== $billingPeriod
        || $markedAmountMinor !== $amountMinor
        || $markedCurrency !== $currency
    ) {
        subscription_payu_unresolved_response($tenantId, $userId, $buyerEmail, 'provider_start_payload_invalid');
    }

    $providerPostGranted = true;
    $periodLabel = $billingPeriod === 'yearly' ? 'roczny' : 'miesieczny';
    $description = 'AI-IQ Rezerwacja Pro - plan Pro ' . $periodLabel;
    $orderPayload = [
        'notifyUrl' => $publicBaseUrl . '/api/subscriptions/payu-notify.php',
        'continueUrl' => $publicBaseUrl . '/platnosc-abonament-powrot.html',
        'customerIp' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'merchantPosId' => $payu['pos_id'],
        'description' => $description,
        'currencyCode' => $currency,
        'totalAmount' => $amountMinor,
        'extOrderId' => $extOrderId,
        'buyer' => [
            'email' => $buyerEmail,
            'language' => 'pl',
        ],
        'products' => [
            [
                'name' => $description,
                'unitPrice' => $amountMinor,
                'quantity' => '1',
            ],
        ],
    ];

    $created = aiiq_payu_create_order($payu, $orderPayload);
    $resultKind = subscription_payu_scalar_string($created['result_kind'] ?? '');
    $orderRequestSent = ($created['order_request_sent'] ?? null) === true;

    if ($resultKind === AI_IQ_PAYU_ORDER_RESULT_LOCAL_FAILURE && !$orderRequestSent) {
        subscription_payu_security_event('subscription_payu_create_order_local_failure', 'provider_local_failure', 503, 'error', 'high', $tenantId, $userId, $buyerEmail, 'payu_create');
        subscription_payu_json(503, [
            'success' => false,
            'retry_allowed' => false,
            'error' => 'Nie udało się wysłać zamówienia do PayU. Ze względów bezpieczeństwa nie ponawiaj tej płatności automatycznie.',
        ]);
    }

    $payuOrderId = subscription_payu_scalar_string($created['order_id'] ?? '');
    $paymentUrl = subscription_payu_scalar_string($created['redirect_uri'] ?? '');
    $recordKind = $resultKind;
    $recordErrorCode = subscription_payu_provider_error_code($created['error_code'] ?? null);

    if (
        !$orderRequestSent
        || !in_array($recordKind, [
            AI_IQ_PAYU_ORDER_RESULT_DEFINITIVE_FAILURE,
            AI_IQ_PAYU_ORDER_RESULT_CREATED,
            AI_IQ_PAYU_ORDER_RESULT_UNKNOWN,
        ], true)
    ) {
        subscription_payu_unresolved_response($tenantId, $userId, $buyerEmail, 'provider_contract_invalid');
    }

    if (
        $recordKind === AI_IQ_PAYU_ORDER_RESULT_CREATED
        && (
            ($created['success'] ?? null) !== true
            || $payuOrderId === ''
            || $paymentUrl === ''
        )
    ) {
        $recordKind = AI_IQ_PAYU_ORDER_RESULT_UNKNOWN;
        $recordErrorCode = 'invalid_order_created_contract';
    }

    if ($recordKind !== AI_IQ_PAYU_ORDER_RESULT_CREATED) {
        $payuOrderId = '';
        $paymentUrl = '';
    }

    $recorded = subscription_payu_rpc('subscription_payment_record_provider_result', [
        'p_tenant_id' => $tenantId,
        'p_user_id' => $userId,
        'p_payment_id' => $paymentId,
        'p_idempotency_key' => $idempotencyKey,
        'p_result_kind' => $recordKind,
        'p_payu_order_id' => $payuOrderId !== '' ? $payuOrderId : null,
        'p_payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
        'p_payu_status' => subscription_payu_provider_status($created['payu_status'] ?? null),
        'p_http_status' => subscription_payu_provider_http_status($created['http_code'] ?? null),
        'p_error_code' => $recordErrorCode,
        'p_payload_sha256_hex' => subscription_payu_provider_payload_hash($created['response_sha256'] ?? null),
    ]);

    if ($recorded === null) {
        subscription_payu_unresolved_response($tenantId, $userId, $buyerEmail, 'provider_result_rpc');
    }

    $recordedState = subscription_payu_scalar_string($recorded['provider_state'] ?? '');
    $recordedStatus = strtolower(subscription_payu_scalar_string($recorded['status'] ?? ''));

    if ($recordedState === 'order_created') {
        subscription_payu_created_response($recorded, $tenantId, $userId, $paymentId, $buyerEmail);
    }

    if ($recordedState === 'terminal') {
        subscription_payu_terminal_response($recordedStatus, $tenantId, $userId, $buyerEmail);
    }

    subscription_payu_unresolved_response($tenantId, $userId, $buyerEmail, 'provider_result_recorded');
} catch (Throwable $e) {
    subscription_payu_security_event('subscription_payu_create_order_fatal', 'fatal', 500, 'error', 'critical', $tenantId ?? null, $userId ?? null, $buyerEmail ?? null);
    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_CREATE_ORDER_FATAL', [
        'exception_type' => get_class($e),
    ]);

    if ($providerPostGranted) {
        subscription_payu_unresolved_response(
            (string) ($tenantId ?? ''),
            (string) ($userId ?? ''),
            isset($buyerEmail) && $buyerEmail !== '' ? $buyerEmail : null,
            'fatal_after_provider_start'
        );
    }

    subscription_payu_json(500, [
        'success' => false,
        'retry_allowed' => true,
        'error' => 'Błąd tworzenia płatności abonamentu.',
    ]);
}
