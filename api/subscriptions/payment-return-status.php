<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/aiiq_payu.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');


function subscription_return_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && $stage !== '') {
        $details['stage'] = $stage;
    }

    $httpMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $actionKey = $httpMethod === 'POST'
        ? 'payment_return_reconciliation'
        : 'subscription_payment_return_status';

    security_log_event($eventKey, [
        'action_key' => $actionKey,
        'endpoint' => '/api/subscriptions/payment-return-status.php',
        'http_method' => $httpMethod,
        'actor_type' => 'tenant_user',
        'tenant_id' => $tenantId,
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

function subscription_return_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_return_request(string $url, array $headers): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode((string) $raw, true);

    return [
        'ok' => $raw !== false && $error === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $error ?: null,
        'data' => is_array($data) ? $data : null,
    ];
}

function subscription_return_period_label(string $period): string
{
    return match ($period) {
        'monthly' => 'miesiąc',
        'yearly' => 'rok',
        default => '—',
    };
}

function subscription_return_validity_fallback_label(string $period): string
{
    return match ($period) {
        'monthly' => '1 miesiąc od potwierdzenia płatności',
        'yearly' => '12 miesięcy od potwierdzenia płatności',
        default => 'po potwierdzeniu płatności',
    };
}

function subscription_return_plan_name(string $planCode): string
{
    return match (strtolower(trim($planCode))) {
        'pro' => 'Pro',
        'vip' => 'VIP',
        default => '',
    };
}

function subscription_return_payment_type_label(string $paymentType, string $planCode): string
{
    $planName = subscription_return_plan_name($planCode);

    return match ($paymentType) {
        'subscription_renewal' => 'Przedłużenie ' . $planName,
        'subscription_upgrade', 'subscription_initial' => 'Przejście na ' . $planName,
        default => 'Płatność ' . $planName,
    };
}

function subscription_return_format_date(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format('d.m.Y');
    } catch (Throwable $e) {
        return '';
    }
}

function subscription_return_normalize_domain(?string $rawDomain): string
{
    $domain = strtolower(trim((string) $rawDomain));

    if ($domain === '' || preg_match('/[\x00-\x1F\x7F]/', $domain)) {
        return '';
    }

    $domain = preg_replace('#^https?://#i', '', $domain);

    if (!is_string($domain) || $domain === '') {
        return '';
    }

    $domain = preg_replace('/:\d+$/', '', $domain);

    if (!is_string($domain)) {
        return '';
    }

    $domain = rtrim($domain, '.');

    if (
        $domain === ''
        || strlen($domain) > 253
        || preg_match('/[\/\\:?#\s]/', $domain)
        || !preg_match('/^[a-z0-9.-]+$/', $domain)
    ) {
        return '';
    }

    foreach (explode('.', $domain) as $label) {
        if (
            $label === ''
            || strlen($label) > 63
            || str_starts_with($label, '-')
            || str_ends_with($label, '-')
        ) {
            return '';
        }
    }

    return $domain;
}

function subscription_return_build_url(string $domain, string $path): string
{
    $domain = subscription_return_normalize_domain($domain);

    if ($domain === '') {
        return '';
    }

    $path = '/' . ltrim($path, '/');
    return 'https://' . $domain . $path;
}



function subscription_return_post_request_allowed(): bool
{
    $contentTypeRaw = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    $contentType = trim(explode(';', $contentTypeRaw, 2)[0] ?? '');

    if ($contentType !== 'application/json') {
        return false;
    }

    $rawBody = file_get_contents('php://input');

    if (!is_string($rawBody) || strlen($rawBody) > 32) {
        return false;
    }

    $decodedBody = json_decode($rawBody);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_object($decodedBody)
        || get_object_vars($decodedBody) !== []
    ) {
        return false;
    }

    $secFetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));

    if ($secFetchSite !== '' && $secFetchSite !== 'same-origin') {
        return false;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origin === '') {
        return true;
    }

    $originParts = parse_url($origin);

    if (
        !is_array($originParts)
        || strtolower((string) ($originParts['scheme'] ?? '')) !== 'https'
    ) {
        return false;
    }

    $originHost = subscription_return_normalize_domain(
        (string) ($originParts['host'] ?? '')
    );

    $requestHostRaw = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $requestHostRaw = trim($requestHostRaw);
    $requestHost = subscription_return_normalize_domain($requestHostRaw);

    return $originHost !== ''
        && $requestHost !== ''
        && hash_equals($requestHost, $originHost);
}

function subscription_return_reconciliation_rpc_data_valid($data): bool
{
    if (!is_array($data)) {
        return false;
    }

    if (
        !array_key_exists('recorded', $data)
        || !is_bool($data['recorded'])
        || !array_key_exists('idempotent', $data)
        || !is_bool($data['idempotent'])
        || !array_key_exists('review_required', $data)
        || !is_bool($data['review_required'])
    ) {
        return false;
    }

    $status = (string) ($data['status'] ?? '');
    $providerState = (string) ($data['provider_state'] ?? '');
    $processingResult = (string) ($data['processing_result'] ?? '');

    return in_array(
        $status,
        ['pending', 'paid', 'failed', 'canceled', 'expired'],
        true
    )
        && in_array(
            $providerState,
            [
                'not_started',
                'request_in_flight',
                'order_created',
                'result_unknown',
                'terminal',
            ],
            true
        )
        && in_array(
            $processingResult,
            [
                'paid',
                'pending',
                'canceled',
                'ignored_terminal_regression',
                'replay',
                'review_required',
                'result_unknown',
                'transport_failure',
            ],
            true
        );
}

function subscription_return_provider_http_status($value): ?int
{
    $status = is_numeric($value) ? (int) $value : 0;

    return $status >= 100 && $status <= 599 ? $status : null;
}

function subscription_return_provider_error_code($value): string
{
    $errorCode = strtolower(trim((string) $value));

    if (
        $errorCode === ''
        || strlen($errorCode) > 80
        || preg_match('/^[a-z0-9_.:-]+$/', $errorCode) !== 1
    ) {
        return 'provider_result_unknown';
    }

    return $errorCode;
}

function subscription_return_handle_reconciliation(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $paymentId
): void {
    $paymentUrl = $supabaseUrl
        . '/rest/v1/tenant_subscription_payments'
        . '?select=id,lifecycle_version,status,provider_state,payu_order_id'
        . '&id=eq.' . rawurlencode($paymentId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $paymentResult = subscription_return_request($paymentUrl, $headers);

    if (!$paymentResult['ok']) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_fetch_failed',
            'payment_fetch_failed',
            500,
            'error',
            'high',
            $tenantId,
            'payment_fetch'
        );
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się sprawdzić płatności abonamentu.',
        ]);
    }

    $payment = $paymentResult['data'][0] ?? null;

    if (!is_array($payment)) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_not_found',
            'payment_not_found',
            404,
            'failed',
            'high',
            $tenantId,
            'payment_fetch'
        );
        subscription_return_json(404, [
            'success' => false,
            'error' => 'Nie znaleziono płatności abonamentu.',
        ]);
    }

    if ((int) ($payment['lifecycle_version'] ?? 0) !== 1) {
        subscription_return_json(200, [
            'success' => true,
        ]);
    }

    $localStatus = trim((string) ($payment['status'] ?? ''));
    $providerState = trim((string) ($payment['provider_state'] ?? ''));

    if ($localStatus === 'paid' && $providerState === 'terminal') {
        subscription_return_json(200, [
            'success' => true,
        ]);
    }

    $orderId = trim((string) ($payment['payu_order_id'] ?? ''));

    if (
        $orderId === ''
        || strlen($orderId) > 160
        || preg_match('/^[A-Za-z0-9_-]+$/', $orderId) !== 1
    ) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_order_unavailable',
            'order_unavailable',
            200,
            'skipped',
            'medium',
            $tenantId,
            'local_context'
        );
        subscription_return_json(200, [
            'success' => true,
        ]);
    }

    $sessionId = session_id();

    if (!is_string($sessionId) || trim($sessionId) === '') {
        subscription_return_security_event(
            'subscription_payment_reconciliation_session_missing',
            'session_missing',
            503,
            'error',
            'high',
            $tenantId,
            'rate_limit'
        );
        subscription_return_json(503, [
            'success' => false,
            'error' => 'Nie udało się teraz sprawdzić płatności abonamentu.',
        ]);
    }

    $rateLimit = security_rate_limit_check(
        'payment_return_reconciliation',
        [
            'session_id' => $sessionId,
        ],
        [
            'endpoint' => '/api/subscriptions/payment-return-status.php',
            'http_method' => 'POST',
            'metadata' => [
                'stage' => 'payu_retrieve',
            ],
        ]
    );

    $rateRaw = is_array($rateLimit['raw'] ?? null)
        ? $rateLimit['raw']
        : [];
    $rateRuleFound = ($rateRaw['rule_found'] ?? false) === true;

    if (empty($rateLimit['ok']) || !$rateRuleFound) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_rate_limit_unavailable',
            'rate_limit_unavailable',
            503,
            'error',
            'critical',
            $tenantId,
            'rate_limit'
        );
        subscription_return_json(503, [
            'success' => false,
            'error' => 'Nie udało się teraz sprawdzić płatności abonamentu.',
        ]);
    }

    if (empty($rateLimit['allowed'])) {
        $retryAfter = isset($rateLimit['retry_after_seconds'])
            && is_numeric($rateLimit['retry_after_seconds'])
            ? max(0, (int) $rateLimit['retry_after_seconds'])
            : 0;

        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
        }

        subscription_return_security_event(
            'subscription_payment_reconciliation_rate_limited',
            'rate_limited',
            429,
            'blocked',
            'high',
            $tenantId,
            'rate_limit'
        );
        subscription_return_json(
            429,
            security_neutral_rate_limit_response($rateLimit)
        );
    }

    $payuConfigResult = aiiq_payu_config();

    if (
        empty($payuConfigResult['success'])
        || !is_array($payuConfigResult['config'] ?? null)
    ) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_payu_config_failed',
            'payu_config_failed',
            503,
            'error',
            'critical',
            $tenantId,
            'payu_config'
        );
        subscription_return_json(503, [
            'success' => false,
            'error' => 'Nie udało się teraz sprawdzić płatności abonamentu.',
        ]);
    }

    $retrieveResult = aiiq_payu_retrieve_order(
        $payuConfigResult['config'],
        $orderId
    );

    $resultKind = (string) ($retrieveResult['result_kind'] ?? '');

    if (
        !in_array(
            $resultKind,
            [
                AI_IQ_PAYU_RECONCILIATION_RESULT_PROVIDER,
                AI_IQ_PAYU_RECONCILIATION_RESULT_UNKNOWN,
                AI_IQ_PAYU_RECONCILIATION_RESULT_TRANSPORT,
            ],
            true
        )
    ) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_provider_result_invalid',
            'provider_result_invalid',
            502,
            'error',
            'critical',
            $tenantId,
            'payu_retrieve'
        );
        subscription_return_json(502, [
            'success' => false,
            'error' => 'Nie udało się teraz potwierdzić płatności abonamentu.',
        ]);
    }

    $responseHash = strtolower(trim(
        (string) ($retrieveResult['response_sha256'] ?? '')
    ));

    if (preg_match('/^[a-f0-9]{64}$/', $responseHash) !== 1) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_hash_invalid',
            'provider_hash_invalid',
            502,
            'error',
            'critical',
            $tenantId,
            'payu_retrieve'
        );
        subscription_return_json(502, [
            'success' => false,
            'error' => 'Nie udało się teraz potwierdzić płatności abonamentu.',
        ]);
    }

    $providerResult = $resultKind === AI_IQ_PAYU_RECONCILIATION_RESULT_PROVIDER;

    $rpcResult = payment_lifecycle_v3_rpc(
        'subscription_payment_apply_payu_reconciliation',
        [
            'p_tenant_id' => $tenantId,
            'p_payment_id' => $paymentId,
            'p_result_kind' => $resultKind,
            'p_payu_status' => $providerResult
                ? (string) ($retrieveResult['payu_status'] ?? '')
                : null,
            'p_ext_order_id' => $providerResult
                ? (string) ($retrieveResult['ext_order_id'] ?? '')
                : null,
            'p_order_id' => $providerResult
                ? (string) ($retrieveResult['order_id'] ?? '')
                : null,
            'p_total_amount_minor' => $providerResult
                ? (int) ($retrieveResult['amount_minor'] ?? -1)
                : null,
            'p_currency' => $providerResult
                ? (string) ($retrieveResult['currency'] ?? '')
                : null,
            'p_payload_sha256_hex' => $responseHash,
            'p_http_status' => subscription_return_provider_http_status(
                $retrieveResult['http_code'] ?? null
            ),
            'p_error_code' => $providerResult
                ? null
                : subscription_return_provider_error_code(
                    $retrieveResult['error_code'] ?? ''
                ),
        ]
    );

    if (empty($rpcResult['ok'])) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_rpc_failed',
            'rpc_failed',
            500,
            'error',
            'critical',
            $tenantId,
            'rpc'
        );
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_RECONCILIATION_RPC_FAILED', [
            'error_kind' => (string) ($rpcResult['error_kind'] ?? 'unknown'),
            'http_status' => (int) ($rpcResult['status'] ?? 0),
        ]);
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się teraz potwierdzić płatności abonamentu.',
        ]);
    }

    $rpcData = $rpcResult['data'] ?? null;

    if (!subscription_return_reconciliation_rpc_data_valid($rpcData)) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_rpc_result_invalid',
            'rpc_result_invalid',
            500,
            'error',
            'critical',
            $tenantId,
            'rpc_response'
        );
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się teraz potwierdzić płatności abonamentu.',
        ]);
    }

    subscription_return_security_event(
        'subscription_payment_reconciliation_processed',
        'reconciliation_processed',
        200,
        'success',
        !empty($rpcData['review_required']) ? 'high' : 'medium',
        $tenantId,
        'complete'
    );

    subscription_return_json(200, [
        'success' => true,
    ]);
}


function subscription_return_session_handoff(): array
{
    $handoff = $_SESSION['subscription_payment_return_handoff'] ?? null;

    if (!is_array($handoff)) {
        return [];
    }

    $paymentId = trim((string) ($handoff['payment_id'] ?? ''));
    $handoffTenantId = trim((string) ($handoff['tenant_id'] ?? ''));
    $createdAt = (int) ($handoff['created_at'] ?? 0);

    if ($paymentId === '' || $handoffTenantId === '' || $createdAt <= 0) {
        unset($_SESSION['subscription_payment_return_handoff']);
        return [];
    }

    if (time() - $createdAt > 7200) {
        unset($_SESSION['subscription_payment_return_handoff']);
        return [];
    }

    if (
        !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $handoffTenantId)
        || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $paymentId)
    ) {
        unset($_SESSION['subscription_payment_return_handoff']);
        return [];
    }

    return [
        'tenant_id' => $handoffTenantId,
        'payment_id' => $paymentId,
    ];
}

function subscription_return_session_payment_id(string $tenantId): string
{
    $handoff = subscription_return_session_handoff();

    if ($handoff === []) {
        return '';
    }

    if (!hash_equals($tenantId, $handoff['tenant_id'])) {
        return '';
    }

    return $handoff['payment_id'];
}

try {
    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));

    if (!in_array($requestMethod, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        subscription_return_security_event(
            'subscription_payment_return_method_not_allowed',
            'method_not_allowed',
            405
        );
        subscription_return_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    if (
        $requestMethod === 'POST'
        && !subscription_return_post_request_allowed()
    ) {
        subscription_return_security_event(
            'subscription_payment_reconciliation_request_denied',
            'request_denied',
            403,
            'blocked',
            'high',
            null,
            'request_validation'
        );
        subscription_return_json(403, [
            'success' => false,
            'error' => 'Żądanie zostało odrzucone.',
        ]);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_return_security_event('subscription_payment_return_env_missing', 'env_missing', 500, 'error', 'high');
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności abonamentu.',
        ]);
    }

    $headers = supabaseHeaders($supabaseKey, $schema);
    $sessionHandoff = subscription_return_session_handoff();

    if ($requestMethod === 'POST') {
        if ($sessionHandoff === []) {
            subscription_return_security_event(
                'subscription_payment_reconciliation_handoff_missing',
                'handoff_missing',
                400,
                'failed',
                'high',
                null,
                'handoff'
            );
            subscription_return_json(400, [
                'success' => false,
                'error' => 'Brak aktywnej płatności abonamentu do sprawdzenia.',
            ]);
        }

        $tenantId = $sessionHandoff['tenant_id'];
        $paymentId = $sessionHandoff['payment_id'];

        subscription_return_handle_reconciliation(
            $supabaseUrl,
            $headers,
            $tenantId,
            $paymentId
        );
    }

    if ($sessionHandoff !== []) {
        $tenantId = $sessionHandoff['tenant_id'];
        $paymentId = $sessionHandoff['payment_id'];
    } else {
        $tenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

        if (!$tenantId) {
            subscription_return_security_event('subscription_payment_return_tenant_denied', 'tenant_denied', 404);
            subscription_return_json(404, [
                'success' => false,
                'error' => 'Nie rozpoznano klienta.',
            ]);
        }

        $tenantId = (string) $tenantId;
        $paymentId = subscription_return_session_payment_id($tenantId);
    }

    if ($paymentId === '') {
        subscription_return_security_event('subscription_payment_return_handoff_missing', 'handoff_missing', 400, 'failed', 'medium', $tenantId ?? null);
        subscription_return_json(400, [
            'success' => false,
            'error' => 'Brak aktywnej płatności abonamentu do sprawdzenia.',
        ]);
    }

    $paymentUrl = $supabaseUrl
        . '/rest/v1/tenant_subscription_payments'
        . '?select=status,plan_code,billing_period,payment_type,subscription_period_start,subscription_period_end,paid_at'
        . '&id=eq.' . rawurlencode($paymentId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $paymentResult = subscription_return_request($paymentUrl, $headers);

    if (!$paymentResult['ok']) {
        subscription_return_security_event('subscription_payment_return_fetch_failed', 'payment_fetch_failed', 500, 'error', 'medium', $tenantId ?? null, 'payment_fetch');
        subscription_return_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności abonamentu.',
        ]);
    }

    $payment = $paymentResult['data'][0] ?? null;

    if (!is_array($payment)) {
        subscription_return_security_event('subscription_payment_return_not_found', 'payment_not_found', 404, 'failed', 'medium', $tenantId ?? null);
        subscription_return_json(404, [
            'success' => false,
            'error' => 'Nie znaleziono płatności abonamentu.',
        ]);
    }

    $paymentPlanCode = strtolower(trim((string) ($payment['plan_code'] ?? '')));

    if (subscription_return_plan_name($paymentPlanCode) === '') {
        subscription_return_security_event('subscription_payment_return_plan_invalid', 'plan_invalid', 422, 'failed', 'high', $tenantId ?? null);
        subscription_return_json(422, [
            'success' => false,
            'error' => 'Nie udało się pobrać danych płatności abonamentu.',
        ]);
    }

    $brandingUrl = $supabaseUrl
        . '/rest/v1/tenant_branding'
        . '?select=client_name,logo_url_front,favicon_url_front'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $brandingResult = subscription_return_request($brandingUrl, $headers);
    $branding = $brandingResult['ok'] ? ($brandingResult['data'][0] ?? []) : [];

    $companyUrl = $supabaseUrl
        . '/rest/v1/tenant_service_settings'
        . '?select=company_full_name,company_owner_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $companyResult = subscription_return_request($companyUrl, $headers);
    $company = $companyResult['ok'] ? ($companyResult['data'][0] ?? []) : [];

    $domainUrl = $supabaseUrl
        . '/rest/v1/tenant_domains'
        . '?select=domain,is_active,is_primary'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&order=is_primary.desc'
        . '&limit=1';

    $domainResult = subscription_return_request($domainUrl, $headers);
    $domainRow = $domainResult['ok'] ? ($domainResult['data'][0] ?? []) : [];
    $tenantDomain = is_array($domainRow)
        ? subscription_return_normalize_domain((string) ($domainRow['domain'] ?? ''))
        : '';

    $subscriptionUrl = $supabaseUrl
        . '/rest/v1/tenant_subscriptions'
        . '?select=status,plan_code,billing_period,current_period_start,current_period_end'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $subscriptionResult = subscription_return_request($subscriptionUrl, $headers);
    $subscription = $subscriptionResult['ok'] ? ($subscriptionResult['data'][0] ?? []) : [];

    $billingPeriod = (string) ($payment['billing_period'] ?? ($subscription['billing_period'] ?? ''));
    $status = (string) ($payment['status'] ?? '');
    $paymentType = (string) ($payment['payment_type'] ?? '');
    $clientName = is_array($branding) ? trim((string) ($branding['client_name'] ?? '')) : '';
    $companyFullName = is_array($company) ? trim((string) ($company['company_full_name'] ?? '')) : '';
    $companyOwnerName = is_array($company) ? trim((string) ($company['company_owner_name'] ?? '')) : '';
    $displayCompanyName = $companyFullName !== '' ? $companyFullName : $clientName;

    $paymentPeriodEnd = subscription_return_format_date((string) ($payment['subscription_period_end'] ?? ''));
    $subscriptionPeriodEnd = is_array($subscription)
        ? subscription_return_format_date((string) ($subscription['current_period_end'] ?? ''))
        : '';
    $validUntilLabel = $paymentPeriodEnd !== ''
        ? $paymentPeriodEnd
        : ($subscriptionPeriodEnd !== '' ? $subscriptionPeriodEnd : subscription_return_validity_fallback_label($billingPeriod));

    $loginUrl = subscription_return_build_url($tenantDomain, '/logowanie.html');
    $panelUrl = subscription_return_build_url($tenantDomain, '/panel-admina.php');
    $publicLogoPath = branding_asset_public_url(
        is_array($branding) ? (string)($branding['logo_url_front'] ?? '') : '',
        $tenantId,
        'logo'
    );
    $publicFaviconPath = branding_asset_public_url(
        is_array($branding) ? (string)($branding['favicon_url_front'] ?? '') : '',
        $tenantId,
        'favicon'
    );
    $publicLogoUrl = $publicLogoPath !== ''
        ? subscription_return_build_url($tenantDomain, $publicLogoPath)
        : '';
    $publicFaviconUrl = $publicFaviconPath !== ''
        ? subscription_return_build_url($tenantDomain, $publicFaviconPath)
        : '';

    subscription_return_json(200, [
        'success' => true,
        'payment' => [
            'status' => $status,
            'plan_code' => $paymentPlanCode,
            'billing_period' => $billingPeriod,
            'billing_period_label' => subscription_return_period_label($billingPeriod),
            'subscription_valid_until' => (string) ($payment['subscription_period_end'] ?? ($subscription['current_period_end'] ?? '')),
            'subscription_valid_until_label' => $validUntilLabel,
            'payment_type' => $paymentType,
            'payment_type_label' => subscription_return_payment_type_label($paymentType, $paymentPlanCode),
            'awaiting_payu_confirmation' => in_array($status, ['', 'pending'], true),
        ],
        'company' => [
            'client_name' => $clientName,
            'company_name' => $displayCompanyName,
            'company_full_name' => $companyFullName,
            'company_owner_name' => $companyOwnerName,
            'logo_url_front' => $publicLogoUrl,
            'favicon_url_front' => $publicFaviconUrl,
        ],
        'urls' => [
            'tenant_domain' => $tenantDomain,
            'login_url' => $loginUrl,
            'panel_url' => $panelUrl,
            'primary_url' => $loginUrl !== '' ? $loginUrl : $panelUrl,
        ],
    ]);
} catch (Throwable $e) {
    subscription_return_security_event('subscription_payment_return_fatal', 'fatal', 500, 'error', 'high', $tenantId ?? null);
    subscription_return_json(500, [
        'success' => false,
        'error' => 'Błąd pobierania statusu płatności abonamentu.',
    ]);
}
