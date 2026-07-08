<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/aiiq_payu.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/system_subscription_mail.php';
require_once __DIR__ . '/../helpers/activation_link.php';


function subscription_payu_notify_security_event(
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

    security_log_event($eventKey, [
        'action_key' => 'subscription_payu_notify',
        'endpoint' => '/api/subscriptions/payu-notify.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'payu_webhook',
        'tenant_id' => $tenantId,
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

function subscription_payu_notify_request(string $method, string $url, array $headers, ?array $payload = null): array
{
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'ok' => $response !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $curlError ?: null,
        'data' => json_decode((string) $response, true),
        'raw' => $response,
    ];
}

function subscription_payu_notify_hash(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    return substr(hash('sha256', $value), 0, 16);
}

function subscription_payu_notify_safe_payload(array $payload, bool $signatureVerified = false): array
{
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    $orderId = trim((string) ($order['orderId'] ?? ''));
    $extOrderId = trim((string) ($order['extOrderId'] ?? ''));
    $status = trim((string) ($order['status'] ?? ''));

    return [
        'order_id_set' => $orderId !== '',
        'order_hash' => subscription_payu_notify_hash($orderId),
        'ext_order_id_set' => $extOrderId !== '',
        'ext_order_hash' => subscription_payu_notify_hash($extOrderId),
        'payu_status' => $status !== '' ? strtoupper($status) : 'UNKNOWN',
        'notify_received_at' => gmdate('c'),
        'signature_verified' => $signatureVerified,
    ];
}

function subscription_payu_notify_find_payment(
    string $supabaseUrl,
    array $headers,
    string $orderId,
    string $extOrderId
): ?array {
    $filters = [];

    if ($orderId !== '') {
        $filters[] = 'payu_order_id.eq.' . rawurlencode($orderId);
        $filters[] = 'payu_ext_order_id.eq.' . rawurlencode($orderId);
    }

    if ($extOrderId !== '') {
        $filters[] = 'payu_ext_order_id.eq.' . rawurlencode($extOrderId);
    }

    $filters = array_values(array_unique($filters));

    if (!$filters) {
        return null;
    }

    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscription_payments'
        . '?select=id,tenant_id,payment_type,plan_code,billing_period,amount,currency,status,payu_order_id,payu_ext_order_id,paid_at,processed_at,subscription_period_start,subscription_period_end,activation_email_sent_at'
        . '&or=(' . implode(',', $filters) . ')'
        . '&limit=1';

    $result = subscription_payu_notify_request('GET', $url, $headers);

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PAYMENT_FETCH_ERROR', [
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null,
            'order_id_set' => $orderId !== '',
            'order_hash' => subscription_payu_notify_hash($orderId),
            'ext_order_id_set' => $extOrderId !== '',
            'ext_order_hash' => subscription_payu_notify_hash($extOrderId),
        ]);

        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_notify_fetch_subscription(string $supabaseUrl, array $headers, string $tenantId): ?array
{
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscriptions'
        . '?select=tenant_id,plan_code,plan_name,billing_period,status,amount,currency,current_period_start,current_period_end,next_payment_due_at,grace_period_days,last_payment_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $result = subscription_payu_notify_request('GET', $url, $headers);

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SUBSCRIPTION_FETCH_ERROR', [
            'tenant_hash' => subscription_payu_notify_hash($tenantId),
            'http_code' => $result['http_code'],
            'has_error' => $result['error'] !== null,
        ]);

        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_notify_fetch_last_paid_pro_period(string $supabaseUrl, array $headers, string $tenantId): ?array
{
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscription_payments'
        . '?select=paid_at,subscription_period_start,subscription_period_end'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&plan_code=eq.pro'
        . '&status=eq.paid'
        . '&order=subscription_period_end.desc.nullslast'
        . '&limit=1';

    $result = subscription_payu_notify_request('GET', $url, $headers);

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_notify_fetch_email_context(string $supabaseUrl, array $headers, string $tenantId): array
{
    $context = [
        'recipient_email' => '',
        'company_name' => '',
        'panel_domain' => '',
    ];

    $settingsUrl = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_service_settings'
        . '?select=company_full_name,company_email'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';
    $settingsResult = subscription_payu_notify_request('GET', $settingsUrl, $headers);
    $settings = $settingsResult['ok'] && is_array($settingsResult['data'][0] ?? null)
        ? $settingsResult['data'][0]
        : [];

    $context['company_name'] = trim((string) ($settings['company_full_name'] ?? ''));
    $companyEmail = trim((string) ($settings['company_email'] ?? ''));

    $domainUrl = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_domains'
        . '?select=domain'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&order=is_primary.desc'
        . '&limit=1';
    $domainResult = subscription_payu_notify_request('GET', $domainUrl, $headers);

    if ($domainResult['ok'] && is_array($domainResult['data'][0] ?? null)) {
        $context['panel_domain'] = trim((string) ($domainResult['data'][0]['domain'] ?? ''));
    }

    $userUrl = rtrim($supabaseUrl, '/')
        . '/rest/v1/users'
        . '?select=email'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&role=in.(administrator,admin)'
        . '&order=created_at.asc'
        . '&limit=1';
    $userResult = subscription_payu_notify_request('GET', $userUrl, $headers);

    if ($userResult['ok'] && is_array($userResult['data'][0] ?? null)) {
        $context['recipient_email'] = trim((string) ($userResult['data'][0]['email'] ?? ''));
    }

    if (!filter_var($context['recipient_email'], FILTER_VALIDATE_EMAIL) && filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
        $context['recipient_email'] = $companyEmail;
    }

    return $context;
}

function subscription_payu_notify_fetch_email_log(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $paymentId,
    string $emailType
): ?array {
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/subscription_email_logs'
        . '?select=id,status'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&payment_id=eq.' . rawurlencode($paymentId)
        . '&email_type=eq.' . rawurlencode($emailType)
        . '&limit=1';
    $result = subscription_payu_notify_request('GET', $url, $headers);

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : [];
}

function subscription_payu_notify_create_email_log(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $paymentId,
    string $emailType,
    string $recipientEmail
): ?array {
    $now = gmdate('c');
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/subscription_email_logs';
    $result = subscription_payu_notify_request('POST', $url, $headers, [
        'tenant_id' => $tenantId,
        'payment_id' => $paymentId,
        'email_type' => $emailType,
        'recipient_email' => $recipientEmail,
        'status' => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_payu_notify_update_email_log(
    string $supabaseUrl,
    array $headers,
    string $logId,
    array $payload
): bool {
    $payload['updated_at'] = gmdate('c');
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/subscription_email_logs'
        . '?id=eq.' . rawurlencode($logId);

    return subscription_payu_notify_request('PATCH', $url, $headers, $payload)['ok'];
}

function subscription_payu_notify_prepare_pro_email_log(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $paymentId,
    string $recipientEmail
): array {
    $emailType = 'subscription_pro_activated';
    $existing = subscription_payu_notify_fetch_email_log($supabaseUrl, $headers, $tenantId, $paymentId, $emailType);

    if ($existing === null) {
        return [
            'ok' => false,
            'send_allowed' => false,
            'reason' => 'log_fetch_failed',
        ];
    }

    if (is_array($existing) && !empty($existing)) {
        $status = strtolower(trim((string) ($existing['status'] ?? '')));

        if ($status === 'sent') {
            return [
                'ok' => true,
                'send_allowed' => false,
                'idempotent' => true,
                'reason' => 'sent',
                'log_id' => trim((string) ($existing['id'] ?? '')),
            ];
        }

        if ($status === 'pending') {
            return [
                'ok' => true,
                'send_allowed' => false,
                'idempotent' => true,
                'reason' => 'pending',
                'log_id' => trim((string) ($existing['id'] ?? '')),
            ];
        }

        if ($status === 'failed') {
            return [
                'ok' => false,
                'send_allowed' => false,
                'reason' => 'failed',
                'log_id' => trim((string) ($existing['id'] ?? '')),
            ];
        }
    }

    $created = subscription_payu_notify_create_email_log(
        $supabaseUrl,
        $headers,
        $tenantId,
        $paymentId,
        $emailType,
        $recipientEmail
    );

    if (is_array($created) && trim((string) ($created['id'] ?? '')) !== '') {
        return [
            'ok' => true,
            'send_allowed' => true,
            'idempotent' => false,
            'reason' => 'created',
            'log_id' => trim((string) $created['id']),
        ];
    }

    $afterCreate = subscription_payu_notify_fetch_email_log($supabaseUrl, $headers, $tenantId, $paymentId, $emailType);

    if (is_array($afterCreate) && !empty($afterCreate)) {
        return [
            'ok' => true,
            'send_allowed' => false,
            'idempotent' => true,
            'reason' => strtolower(trim((string) ($afterCreate['status'] ?? 'existing'))),
            'log_id' => trim((string) ($afterCreate['id'] ?? '')),
        ];
    }

    return [
        'ok' => false,
        'send_allowed' => false,
        'reason' => 'log_unavailable',
    ];
}


function subscription_payu_notify_fetch_admin_user(string $supabaseUrl, array $headers, string $tenantId): ?array
{
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/users'
        . '?select=id,email,is_active'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&role=in.(administrator,admin)'
        . '&order=created_at.asc'
        . '&limit=1';

    $result = subscription_payu_notify_request('GET', $url, $headers);

    if (!$result['ok'] || !is_array($result['data'][0] ?? null)) {
        return null;
    }

    return $result['data'][0];
}

function subscription_payu_notify_create_activation_url(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $userId,
    string $email
): string {
    if ($tenantId === '' || $userId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    $now = gmdate('c');

    subscription_payu_notify_request(
        'PATCH',
        rtrim($supabaseUrl, '/')
            . '/rest/v1/user_activation_tokens'
            . '?tenant_id=eq.' . rawurlencode($tenantId)
            . '&user_id=eq.' . rawurlencode($userId)
            . '&used_at=is.null'
            . '&revoked_at=is.null',
        $headers,
        [
            'revoked_at' => $now,
        ]
    );

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return '';
    }

    $insertHeaders = $headers;
    $insertHeaders[] = 'Prefer: return=representation';
    $insert = subscription_payu_notify_request(
        'POST',
        rtrim($supabaseUrl, '/') . '/rest/v1/user_activation_tokens',
        $insertHeaders,
        [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('c', time() + (48 * 60 * 60)),
            'used_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'ip_address' => null,
            'user_agent' => 'PayU notify - direct Pro registration',
        ]
    );

    if (!$insert['ok']) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_INITIAL_PRO_ACTIVATION_TOKEN_ERROR', [
            'tenant_hash' => subscription_payu_notify_hash($tenantId),
            'user_hash' => subscription_payu_notify_hash($userId),
            'http_code' => $insert['http_code'],
        ]);

        return '';
    }

    $activationRef = activation_link_build_ref($token, $tenantId, $userId);

    if ($activationRef === '') {
        return '';
    }

    return 'https://rezerwacja-ai-iq.pl/api/auth/activate.php?token=' . rawurlencode($token)
        . '&ref=' . rawurlencode($activationRef);
}

function subscription_payu_notify_build_initial_pro_registration_mail(
    string $supabaseUrl,
    array $headers,
    array $payment,
    array $subscription,
    array $context
): array {
    $tenantId = trim((string) ($payment['tenant_id'] ?? ''));
    $adminUser = $tenantId !== '' ? subscription_payu_notify_fetch_admin_user($supabaseUrl, $headers, $tenantId) : null;

    if (!is_array($adminUser)) {
        return [
            'ok' => false,
            'error' => 'admin_user_missing',
        ];
    }

    $userId = trim((string) ($adminUser['id'] ?? ''));
    $email = trim((string) ($adminUser['email'] ?? ''));
    $activationUrl = subscription_payu_notify_create_activation_url($supabaseUrl, $headers, $tenantId, $userId, $email);

    if ($activationUrl === '') {
        return [
            'ok' => false,
            'error' => 'activation_url_missing',
        ];
    }

    $html = buildRegistrationConfirmationMailHtml([
        'company_name' => trim((string) ($context['company_name'] ?? '')),
        'plan' => 'Pro',
        'panel_domain' => trim((string) ($context['panel_domain'] ?? '')),
        'activation_url' => $activationUrl,
        'activation_expires_label' => 'przez 48 godzin',
    ]);

    return [
        'ok' => true,
        'subject' => 'Potwierdzenie rejestracji Pro w AI-IQ Rezerwacja Pro',
        'html' => $html,
    ];
}

function subscription_payu_notify_send_activation_email_if_needed(
    string $supabaseUrl,
    array $headers,
    array $payment,
    array $subscription
): array {
    $paymentId = trim((string) ($payment['id'] ?? ''));
    $tenantId = trim((string) ($payment['tenant_id'] ?? ''));

    if (trim((string) ($payment['activation_email_sent_at'] ?? '')) !== '') {
        return [
            'ok' => true,
            'sent' => false,
            'idempotent' => true,
        ];
    }

    if ($paymentId === '' || $tenantId === '') {
        return [
            'ok' => false,
            'error' => 'invalid_payment',
        ];
    }

    $context = subscription_payu_notify_fetch_email_context($supabaseUrl, $headers, $tenantId);
    $recipientEmail = trim((string) ($context['recipient_email'] ?? ''));

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_ACTIVATION_EMAIL_RECIPIENT_MISSING', [
            'payment_hash' => subscription_payu_notify_hash($paymentId),
            'tenant_hash' => subscription_payu_notify_hash($tenantId),
        ]);

        return [
            'ok' => false,
            'error' => 'recipient_missing',
        ];
    }

    $logState = subscription_payu_notify_prepare_pro_email_log(
        $supabaseUrl,
        $headers,
        $tenantId,
        $paymentId,
        $recipientEmail
    );

    if (empty($logState['ok'])) {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_ACTIVATION_EMAIL_LOG_BLOCKED', [
            'payment_hash' => subscription_payu_notify_hash($paymentId),
            'tenant_hash' => subscription_payu_notify_hash($tenantId),
            'reason' => (string) ($logState['reason'] ?? 'unknown'),
        ]);

        return [
            'ok' => false,
            'error' => 'log_blocked',
            'reason' => (string) ($logState['reason'] ?? 'unknown'),
        ];
    }

    if (empty($logState['send_allowed'])) {
        if (($logState['reason'] ?? '') === 'sent') {
            $now = gmdate('c');
            $marked = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
                'activation_email_sent_at' => $now,
                'updated_at' => $now,
            ]);

            if (!$marked) {
                return [
                    'ok' => false,
                    'sent' => false,
                    'idempotent' => true,
                    'reason' => 'sent_log_marker_backfill_failed',
                    'marker_backfilled' => false,
                ];
            }

            return [
                'ok' => true,
                'sent' => false,
                'idempotent' => true,
                'reason' => 'sent',
                'log_already_sent' => true,
                'marker_backfilled' => true,
            ];
        }

        return [
            'ok' => true,
            'sent' => false,
            'idempotent' => true,
            'reason' => (string) ($logState['reason'] ?? 'blocked'),
        ];
    }

    $logId = trim((string) ($logState['log_id'] ?? ''));

    if ($logId === '') {
        return [
            'ok' => false,
            'error' => 'log_missing',
        ];
    }

    $paymentType = strtolower(trim((string) ($payment['payment_type'] ?? '')));

    if ($paymentType === 'subscription_initial') {
        $mail = subscription_payu_notify_build_initial_pro_registration_mail(
            $supabaseUrl,
            $headers,
            $payment,
            $subscription,
            $context
        );
    } else {
        $mail = [
            'ok' => true,
            'subject' => 'Plan Pro aktywny w AI-IQ Rezerwacja Pro',
            'html' => buildSubscriptionProActivatedMailHtml($payment, $subscription, $context),
        ];
    }

    if (empty($mail['ok']) || trim((string) ($mail['html'] ?? '')) === '') {
        subscription_payu_notify_update_email_log($supabaseUrl, $headers, $logId, [
            'status' => 'failed',
            'failed_at' => gmdate('c'),
        ]);

        return [
            'ok' => false,
            'error' => (string) ($mail['error'] ?? 'mail_build_failed'),
        ];
    }

    if (!sendSystemMail($recipientEmail, (string) ($mail['subject'] ?? 'AI-IQ Rezerwacja Pro'), (string) $mail['html'])) {
        subscription_payu_notify_update_email_log($supabaseUrl, $headers, $logId, [
            'status' => 'failed',
            'failed_at' => gmdate('c'),
        ]);

        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_ACTIVATION_EMAIL_SEND_FAILED', [
            'payment_hash' => subscription_payu_notify_hash($paymentId),
            'tenant_hash' => subscription_payu_notify_hash($tenantId),
        ]);

        return [
            'ok' => false,
            'error' => 'send_failed',
        ];
    }

    $now = gmdate('c');
    $logMarkedSent = subscription_payu_notify_update_email_log($supabaseUrl, $headers, $logId, [
        'status' => 'sent',
        'sent_at' => $now,
    ]);

    if (!$logMarkedSent) {
        return [
            'ok' => false,
            'sent' => false,
            'error' => 'log_mark_sent_failed',
        ];
    }

    $marked = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
        'activation_email_sent_at' => $now,
        'updated_at' => $now,
    ]);

    if (!$marked) {
        return [
            'ok' => false,
            'error' => 'mark_failed',
        ];
    }

    return [
        'ok' => true,
        'sent' => true,
        'idempotent' => false,
    ];
}

function subscription_payu_notify_update_payment(
    string $supabaseUrl,
    array $headers,
    string $paymentId,
    string $tenantId,
    array $payload
): bool {
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscription_payments'
        . '?id=eq.' . rawurlencode($paymentId)
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = subscription_payu_notify_request('PATCH', $url, $headers, $payload);

    if ($result['ok']) {
        return true;
    }

    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PAYMENT_UPDATE_ERROR', [
        'payment_hash' => subscription_payu_notify_hash($paymentId),
        'tenant_hash' => subscription_payu_notify_hash($tenantId),
        'http_code' => $result['http_code'],
        'has_error' => $result['error'] !== null,
    ]);

    return false;
}

function subscription_payu_notify_save_subscription(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    array $payload,
    bool $exists
): bool {
    if ($exists) {
        $url = rtrim($supabaseUrl, '/')
            . '/rest/v1/tenant_subscriptions'
            . '?tenant_id=eq.' . rawurlencode($tenantId);

        $result = subscription_payu_notify_request('PATCH', $url, $headers, $payload);
    } else {
        $url = rtrim($supabaseUrl, '/') . '/rest/v1/tenant_subscriptions';
        $result = subscription_payu_notify_request('POST', $url, $headers, array_merge(['tenant_id' => $tenantId], $payload));
    }

    if ($result['ok']) {
        return true;
    }

    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SUBSCRIPTION_SAVE_ERROR', [
        'tenant_hash' => subscription_payu_notify_hash($tenantId),
        'exists' => $exists,
        'http_code' => $result['http_code'],
        'has_error' => $result['error'] !== null,
    ]);

    return false;
}

function subscription_payu_notify_update_branding_plan(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $planCode
): bool {
    $planCode = strtolower(trim($planCode));

    if ($tenantId === '' || !in_array($planCode, ['free', 'pro'], true)) {
        return false;
    }

    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_branding'
        . '?tenant_id=eq.' . rawurlencode($tenantId);

    $result = subscription_payu_notify_request('PATCH', $url, $headers, [
        'plan' => $planCode,
    ]);

    if ($result['ok']) {
        return true;
    }

    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_BRANDING_PLAN_UPDATE_ERROR', [
        'tenant_hash' => subscription_payu_notify_hash($tenantId),
        'plan_code' => $planCode,
        'http_code' => $result['http_code'],
        'has_error' => $result['error'] !== null,
    ]);

    return false;
}

function subscription_payu_notify_map_status(string $payuStatus): string
{
    return match (strtoupper(trim($payuStatus))) {
        'COMPLETED' => 'paid',
        'CANCELED' => 'canceled',
        'REJECTED' => 'failed',
        'EXPIRED' => 'expired',
        default => 'pending',
    };
}

function subscription_payu_notify_date_start(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }
}

function subscription_payu_notify_period(array $payment, ?array $subscription, ?string $fallbackPaidAt = null): array
{
    $billingPeriod = strtolower(trim((string) ($payment['billing_period'] ?? '')));
    $storedPaidAt = trim((string) ($payment['paid_at'] ?? ''));
    $paidAtSource = $storedPaidAt !== '' ? $storedPaidAt : trim((string) $fallbackPaidAt);
    $paidDate = subscription_payu_notify_date_start($paidAtSource);

    if (!$paidDate) {
        $paidDate = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    }

    $start = $paidDate;

    if (is_array($subscription)) {
        $currentEnd = subscription_payu_notify_date_start($subscription['current_period_end'] ?? null);

        if ($currentEnd && $paidDate <= $currentEnd) {
            $start = $currentEnd;
        }
    }

    $end = match ($billingPeriod) {
        'monthly' => $start->modify('+1 month'),
        'yearly' => $start->modify('+1 year'),
        default => $start,
    };

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

function subscription_payu_notify_valid_id(string $value): bool
{
    return $value === '' || preg_match('/^[a-zA-Z0-9_-]{1,160}$/', $value) === 1;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        subscription_payu_notify_security_event('subscription_payu_notify_method_not_allowed', 'method_not_allowed', 405);
        subscription_payu_notify_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        subscription_payu_notify_security_event('subscription_payu_notify_invalid_json', 'invalid_json', 400, 'failed', 'medium', null, 'parse');
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_INVALID_JSON', [
            'body_length' => strlen($rawBody),
            'json_error' => json_last_error_msg(),
        ]);

        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy JSON.',
        ]);
    }

    $order = is_array($data['order'] ?? null) ? $data['order'] : [];
    $orderId = trim((string) ($order['orderId'] ?? ''));
    $extOrderId = trim((string) ($order['extOrderId'] ?? ''));
    $payuStatus = trim((string) ($order['status'] ?? ''));

    if (!subscription_payu_notify_valid_id($orderId) || !subscription_payu_notify_valid_id($extOrderId)) {
        subscription_payu_notify_security_event('subscription_payu_notify_invalid_order_identifier', 'invalid_order_identifier', 400);
        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Nieprawidłowy identyfikator płatności.',
        ]);
    }

    if ($orderId === '' && $extOrderId === '') {
        subscription_payu_notify_security_event('subscription_payu_notify_order_id_missing', 'order_id_missing', 400);
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_ORDER_ID_MISSING', [
            'body_length' => strlen($rawBody),
            'has_order' => is_array($data['order'] ?? null),
        ]);

        subscription_payu_notify_json(400, [
            'success' => false,
            'error' => 'Brak orderId/extOrderId.',
        ]);
    }

    $secondKey = aiiq_payu_env('AI_IQ_PAYU_SECOND_KEY');
    $signatureHeader = subscription_payu_notify_header('OpenPayu-Signature');

    if (!subscription_payu_notify_verify_signature($rawBody, $secondKey, $signatureHeader)) {
        subscription_payu_notify_security_event('subscription_payu_notify_signature_invalid', 'signature_invalid', 401, 'denied', 'high');
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_SIGNATURE_INVALID', [
            'order_id_set' => $orderId !== '',
            'order_hash' => subscription_payu_notify_hash($orderId),
            'ext_order_id_set' => $extOrderId !== '',
            'ext_order_hash' => subscription_payu_notify_hash($extOrderId),
            'signature_header_set' => $signatureHeader !== '',
            'second_key_set' => $secondKey !== '',
        ]);

        subscription_payu_notify_json(401, [
            'success' => false,
            'error' => 'Nieprawidłowy podpis PayU.',
        ]);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_payu_notify_security_event('subscription_payu_notify_env_missing', 'env_missing', 500, 'error', 'critical');
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji bazy danych.',
        ]);
    }

    $headers = supabaseHeaders($supabaseKey, $schema);
    $headers[] = 'Content-Type: application/json';

    $payment = subscription_payu_notify_find_payment($supabaseUrl, $headers, $orderId, $extOrderId);

    if (!is_array($payment)) {
        subscription_payu_notify_security_event('subscription_payu_notify_payment_not_found', 'payment_not_found', 404);
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PAYMENT_NOT_FOUND', [
            'order_id_set' => $orderId !== '',
            'order_hash' => subscription_payu_notify_hash($orderId),
            'ext_order_id_set' => $extOrderId !== '',
            'ext_order_hash' => subscription_payu_notify_hash($extOrderId),
            'payu_status' => $payuStatus,
        ]);

        subscription_payu_notify_json(404, [
            'success' => false,
            'error' => 'Nie znaleziono płatności abonamentu.',
        ]);
    }

    $paymentId = trim((string) ($payment['id'] ?? ''));
    $tenantId = trim((string) ($payment['tenant_id'] ?? ''));

    if ($paymentId === '' || $tenantId === '') {
        subscription_payu_notify_security_event('subscription_payu_notify_payment_invalid', 'payment_invalid', 422, 'failed', 'high', $tenantId ?: null);
        subscription_payu_notify_json(422, [
            'success' => false,
            'error' => 'Nieprawidłowy rekord płatności abonamentu.',
        ]);
    }

    $mappedStatus = subscription_payu_notify_map_status($payuStatus);
    $now = gmdate('c');
    $safeNotify = subscription_payu_notify_safe_payload($data, true);
    $alreadyProcessed = strtolower(trim((string) ($payment['status'] ?? ''))) === 'paid'
        && $mappedStatus === 'paid'
        && trim((string) ($payment['processed_at'] ?? '')) !== '';

    if ($alreadyProcessed) {
        $activationEmailResult = ['ok' => true, 'sent' => false, 'idempotent' => true];

        if ($mappedStatus === 'paid') {
            $subscription = subscription_payu_notify_fetch_subscription($supabaseUrl, $headers, $tenantId);

            if (is_array($subscription)) {
                $activationEmailResult = subscription_payu_notify_send_activation_email_if_needed(
                    $supabaseUrl,
                    $headers,
                    $payment,
                    $subscription
                );

                if (empty($activationEmailResult['ok'])) {
                    subscription_payu_notify_security_event('subscription_payu_notify_activation_email_failed', 'activation_email_failed', 500, 'error', 'medium', $tenantId, 'idempotent_email');
                    subscription_payu_notify_json(500, [
                        'success' => false,
                        'error' => 'Nie udało się wysłać potwierdzenia Pro dla przetworzonej płatności.',
                    ]);
                }
            }
        }

        subscription_payu_notify_security_event('subscription_payu_notify_already_processed', 'already_processed', 200, 'success', 'low', $tenantId);
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_ALREADY_PROCESSED', [
            'payment_hash' => subscription_payu_notify_hash($paymentId),
            'tenant_hash' => subscription_payu_notify_hash($tenantId),
            'payu_status' => $payuStatus,
            'activation_email_sent' => !empty($activationEmailResult['sent']),
        ]);

        subscription_payu_notify_json(200, [
            'success' => true,
            'status' => 'paid',
            'processed' => false,
            'idempotent' => true,
            'activation_email_sent' => !empty($activationEmailResult['sent']),
        ]);
    }

    if ($mappedStatus !== 'paid') {
        $updated = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
            'status' => $mappedStatus,
            'payu_status' => $payuStatus !== '' ? strtoupper($payuStatus) : 'UNKNOWN',
            'raw_notify' => $safeNotify,
            'updated_at' => $now,
        ]);

        if (!$updated) {
            subscription_payu_notify_security_event('subscription_payu_notify_status_update_failed', 'status_update_failed', 500, 'error', 'high', $tenantId, 'non_paid_status');
            subscription_payu_notify_json(500, [
                'success' => false,
                'error' => 'Nie udało się zaktualizować płatności abonamentu.',
            ]);
        }

        subscription_payu_notify_security_event('subscription_payu_notify_status_update_success', 'status_update_success', 200, 'success', 'low', $tenantId, 'non_paid_status');
        subscription_payu_notify_json(200, [
            'success' => true,
            'status' => $mappedStatus,
            'processed' => false,
        ]);
    }

    $billingPeriod = strtolower(trim((string) ($payment['billing_period'] ?? '')));

    if (!in_array($billingPeriod, ['monthly', 'yearly'], true)) {
        subscription_payu_notify_security_event('subscription_payu_notify_billing_period_invalid', 'billing_period_invalid', 422, 'failed', 'high', $tenantId);
        subscription_payu_notify_json(422, [
            'success' => false,
            'error' => 'Nieprawidłowy okres rozliczeniowy płatności.',
        ]);
    }

    $subscription = subscription_payu_notify_fetch_subscription($supabaseUrl, $headers, $tenantId);

    if (is_array($subscription) && trim((string) ($subscription['current_period_end'] ?? '')) === '') {
        $lastPaidProPeriod = subscription_payu_notify_fetch_last_paid_pro_period($supabaseUrl, $headers, $tenantId);

        if (is_array($lastPaidProPeriod) && trim((string) ($lastPaidProPeriod['subscription_period_end'] ?? '')) !== '') {
            $subscription['current_period_end'] = $lastPaidProPeriod['subscription_period_end'];
        }
    }

    $storedPeriodStart = trim((string) ($payment['subscription_period_start'] ?? ''));
    $storedPeriodEnd = trim((string) ($payment['subscription_period_end'] ?? ''));
    $period = ($storedPeriodStart !== '' && $storedPeriodEnd !== '')
        ? ['start' => $storedPeriodStart, 'end' => $storedPeriodEnd]
        : subscription_payu_notify_period($payment, $subscription, $now);
    $subscriptionExists = is_array($subscription);

    if ($storedPeriodStart !== '' && $storedPeriodEnd !== '') {
        aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_RETRY_WITH_STORED_PERIOD', [
            'payment_hash' => subscription_payu_notify_hash($paymentId),
            'tenant_hash' => subscription_payu_notify_hash($tenantId),
            'billing_period' => $billingPeriod,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
        ]);
    }

    if ($storedPeriodStart === '' || $storedPeriodEnd === '') {
        $paymentPrepared = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
            'status' => 'paid',
            'payu_status' => 'COMPLETED',
            'paid_at' => $now,
            'raw_notify' => $safeNotify,
            'subscription_period_start' => $period['start'],
            'subscription_period_end' => $period['end'],
            'updated_at' => $now,
        ]);

        if (!$paymentPrepared) {
            subscription_payu_notify_security_event('subscription_payu_notify_payment_prepare_failed', 'payment_prepare_failed', 500, 'error', 'high', $tenantId);
            subscription_payu_notify_json(500, [
                'success' => false,
                'error' => 'Nie udało się przygotować płatności abonamentu do przetworzenia.',
            ]);
        }
    }

    $subscriptionPayload = [
        'plan_code' => 'pro',
        'plan_name' => 'Pro',
        'billing_period' => $billingPeriod,
        'status' => 'active',
        'amount' => $payment['amount'] ?? null,
        'currency' => (string) ($payment['currency'] ?? 'PLN'),
        'current_period_start' => $period['start'],
        'current_period_end' => $period['end'],
        'next_payment_due_at' => $period['end'],
        'last_payment_at' => $now,
        'updated_at' => $now,
    ];

    if (!$subscriptionExists) {
        $subscriptionPayload['grace_period_days'] = 0;
    }

    $subscriptionSaved = subscription_payu_notify_save_subscription(
        $supabaseUrl,
        $headers,
        $tenantId,
        $subscriptionPayload,
        $subscriptionExists
    );

    if (!$subscriptionSaved) {
        subscription_payu_notify_security_event('subscription_payu_notify_subscription_save_failed', 'subscription_save_failed', 500, 'error', 'high', $tenantId);
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Nie udało się zaktualizować abonamentu.',
        ]);
    }

    $brandingUpdated = subscription_payu_notify_update_branding_plan($supabaseUrl, $headers, $tenantId, 'pro');

    if (!$brandingUpdated) {
        subscription_payu_notify_security_event('subscription_payu_notify_branding_plan_update_failed', 'branding_plan_update_failed', 500, 'error', 'high', $tenantId);
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Nie udało się zaktualizować planu firmy.',
        ]);
    }

    $paymentUpdated = subscription_payu_notify_update_payment($supabaseUrl, $headers, $paymentId, $tenantId, [
        'status' => 'paid',
        'payu_status' => 'COMPLETED',
        'paid_at' => $payment['paid_at'] ?? $now,
        'processed_at' => $now,
        'raw_notify' => $safeNotify,
        'subscription_period_start' => $period['start'],
        'subscription_period_end' => $period['end'],
        'updated_at' => $now,
    ]);

    if (!$paymentUpdated) {
        subscription_payu_notify_security_event('subscription_payu_notify_payment_update_failed', 'payment_update_failed', 500, 'error', 'high', $tenantId);
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Abonament został zaktualizowany, ale nie udało się oznaczyć płatności jako przetworzonej.',
        ]);
    }

    $paymentForEmail = array_merge($payment, [
        'status' => 'paid',
        'payu_status' => 'COMPLETED',
        'paid_at' => $payment['paid_at'] ?? $now,
        'processed_at' => $now,
        'subscription_period_start' => $period['start'],
        'subscription_period_end' => $period['end'],
    ]);
    $subscriptionForEmail = array_merge($subscriptionPayload, [
        'tenant_id' => $tenantId,
    ]);
    $activationEmailResult = subscription_payu_notify_send_activation_email_if_needed(
        $supabaseUrl,
        $headers,
        $paymentForEmail,
        $subscriptionForEmail
    );

    if (empty($activationEmailResult['ok'])) {
        subscription_payu_notify_security_event('subscription_payu_notify_activation_email_failed', 'activation_email_failed', 500, 'error', 'medium', $tenantId, 'final_email');
        subscription_payu_notify_json(500, [
            'success' => false,
            'error' => 'Abonament został aktywowany, ale nie udało się wysłać potwierdzenia Pro.',
        ]);
    }

    subscription_payu_notify_security_event('subscription_payu_notify_processed', 'subscription_payu_notify_processed', 200, 'success', 'medium', $tenantId);
    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_PROCESSED', [
        'payment_hash' => subscription_payu_notify_hash($paymentId),
        'tenant_hash' => subscription_payu_notify_hash($tenantId),
        'billing_period' => $billingPeriod,
        'period_start' => $period['start'],
        'period_end' => $period['end'],
        'activation_email_sent' => !empty($activationEmailResult['sent']),
    ]);

    subscription_payu_notify_json(200, [
        'success' => true,
        'status' => 'paid',
        'processed' => true,
        'period_start' => $period['start'],
        'period_end' => $period['end'],
        'activation_email_sent' => !empty($activationEmailResult['sent']),
    ]);
} catch (Throwable $e) {
    subscription_payu_notify_security_event('subscription_payu_notify_fatal', 'fatal', 500, 'error', 'critical', $tenantId ?? null);
    aiiq_payu_debug('AI_IQ_SUBSCRIPTION_PAYU_NOTIFY_FATAL', [
        'exception_type' => get_class($e),
    ]);

    subscription_payu_notify_json(500, [
        'success' => false,
        'error' => 'Błąd obsługi powiadomienia PayU.',
    ]);
}
