<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/system_subscription_mail.php';
require_once __DIR__ . '/../helpers/security.php';

const SUBSCRIPTION_EMAIL_WORKER_LIMIT = 5;
const SUBSCRIPTION_EMAIL_WORKER_LEASE_SECONDS = 300;

function subscription_email_worker_response(array $payload, int $statusCode = 200): void
{
    $isCli = PHP_SAPI === 'cli';
    $isIdleCliSuccess = $isCli
        && $statusCode < 400
        && $payload === [
            'success' => true,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

    if (!$isCli) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    if (!$isIdleCliSuccess) {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    exit($isCli && $statusCode >= 400 ? 1 : 0);
}

function subscription_email_worker_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function subscription_email_worker_authorized(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $expected = trim((string) getenv('SUBSCRIPTION_EMAIL_WORKER_CRON_SECRET'));
    $provided = subscription_email_worker_header('X-Cron-Secret');

    if ($provided === '') {
        $authorization = subscription_email_worker_header('Authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $provided = trim((string) $matches[1]);
        }
    }

    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function subscription_email_worker_log(string $event): void
{
    $allowedEvents = [
        'worker_unauthorized',
        'claim_failed',
        'malformed_claim',
        'payment_context_missing',
        'unsupported_email_type',
        'activation_flow_not_supported',
        'recipient_invalid',
        'smtp_send_failed',
        'record_result_failed',
        'worker_run_success',
        'worker_run_failed',
    ];

    if (!in_array($event, $allowedEvents, true)) {
        $event = 'worker_run_failed';
    }

    error_log('SUBSCRIPTION_EMAIL_WORKER ' . $event);
}

function subscription_email_worker_id(): string
{
    $host = function_exists('gethostname') ? trim((string) gethostname()) : '';
    $hostHash = substr(hash('sha256', $host !== '' ? $host : 'unknown-host'), 0, 16);
    $processId = function_exists('getmypid') ? max(0, (int) getmypid()) : 0;

    return 'subscription-email-v1:h-' . $hostHash . ':p-' . $processId;
}

function subscription_email_worker_fetch_payment(
    array $config,
    string $paymentId,
    string $tenantId
): array {
    if (security_uuid_or_null($paymentId) === null || $tenantId === '') {
        return ['ok' => false, 'payment' => null];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'payment' => null];
    }

    $url = $config['url']
        . '/rest/v1/tenant_subscription_payments'
        . '?select=id,tenant_id,payment_type,plan_code,billing_period,amount,currency,status,subscription_period_start,subscription_period_end,lifecycle_version'
        . '&id=eq.' . rawurlencode($paymentId)
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&lifecycle_version=eq.1'
        . '&limit=2';
    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'payment' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
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

    if (
        $response === false
        || $curlError !== ''
        || $httpStatus < 200
        || $httpStatus >= 300
    ) {
        return ['ok' => false, 'payment' => null];
    }

    $decoded = json_decode((string) $response, true);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_array($decoded)
        || count($decoded) !== 1
        || !is_array($decoded[0] ?? null)
    ) {
        return ['ok' => false, 'payment' => null];
    }

    $payment = $decoded[0];
    $returnedId = trim((string) ($payment['id'] ?? ''));
    $returnedTenantId = trim((string) ($payment['tenant_id'] ?? ''));
    $lifecycleVersion = $payment['lifecycle_version'] ?? null;
    $status = strtolower(trim((string) ($payment['status'] ?? '')));
    $paymentType = trim((string) ($payment['payment_type'] ?? ''));
    $planCode = strtolower(trim((string) ($payment['plan_code'] ?? '')));
    $billingPeriod = trim((string) ($payment['billing_period'] ?? ''));
    $currency = strtoupper(trim((string) ($payment['currency'] ?? '')));

    if (
        $returnedId !== $paymentId
        || $returnedTenantId !== $tenantId
        || !is_numeric($lifecycleVersion)
        || (int) $lifecycleVersion !== 1
        || $status !== 'paid'
        || $paymentType === ''
        || !in_array($planCode, ['pro', 'vip'], true)
        || $billingPeriod === ''
        || !is_numeric($payment['amount'] ?? null)
        || $currency === ''
    ) {
        return ['ok' => false, 'payment' => null];
    }

    $payment['payment_type'] = $paymentType;
    $payment['plan_code'] = $planCode;
    $payment['billing_period'] = $billingPeriod;
    $payment['currency'] = $currency;
    $payment['status'] = $status;
    $payment['lifecycle_version'] = 1;

    return ['ok' => true, 'payment' => $payment];
}

function subscription_email_worker_record_result(
    string $emailLogId,
    string $claimToken,
    string $result,
    string $errorCode
): bool {
    $rpcResult = payment_lifecycle_v3_rpc('subscription_email_record_result', [
        'p_email_log_id' => $emailLogId,
        'p_claim_token' => $claimToken,
        'p_result' => $result,
        'p_error_code' => $errorCode,
    ]);

    if (empty($rpcResult['ok']) || !is_array($rpcResult['data'] ?? null)) {
        return false;
    }

    $data = $rpcResult['data'];
    $status = strtolower(trim((string) ($data['status'] ?? '')));

    if ($result === 'sent') {
        return $status === 'sent'
            && (($data['recorded'] ?? false) === true || ($data['idempotent'] ?? false) === true);
    }

    return in_array($status, ['failed', 'blocked'], true)
        && ($data['recorded'] ?? false) === true;
}

function subscription_email_worker_record_sent(
    string $emailLogId,
    string $claimToken
): bool {
    for ($attempt = 0; $attempt < 2; $attempt++) {
        if (subscription_email_worker_record_result($emailLogId, $claimToken, 'sent', '')) {
            return true;
        }

        if ($attempt === 0) {
            usleep(250000);
        }
    }

    return false;
}

function subscription_email_worker_fail_claim(
    string $emailLogId,
    string $claimToken,
    string $errorCode
): bool {
    return subscription_email_worker_record_result(
        $emailLogId,
        $claimToken,
        'failed',
        $errorCode
    );
}

if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    subscription_email_worker_response(['success' => false], 405);
}

if (!subscription_email_worker_authorized()) {
    subscription_email_worker_log('worker_unauthorized');
    subscription_email_worker_response(['success' => false], 401);
}

$processed = 0;
$sent = 0;
$failed = 0;
$skipped = 0;
$runFailed = false;

try {
    $config = payment_lifecycle_v3_config();
    $workerId = subscription_email_worker_id();

    for ($index = 0; $index < SUBSCRIPTION_EMAIL_WORKER_LIMIT; $index++) {
        $claimResult = payment_lifecycle_v3_rpc('subscription_email_claim', [
            'p_worker_id' => $workerId,
            'p_lease_seconds' => SUBSCRIPTION_EMAIL_WORKER_LEASE_SECONDS,
        ]);

        if (empty($claimResult['ok']) || !is_array($claimResult['data'] ?? null)) {
            subscription_email_worker_log('claim_failed');
            $runFailed = true;
            break;
        }

        $claim = $claimResult['data'];

        if (!array_key_exists('claimed', $claim) || !is_bool($claim['claimed'])) {
            subscription_email_worker_log('malformed_claim');
            $runFailed = true;
            break;
        }

        if ($claim['claimed'] === false) {
            if (($claim['blocked'] ?? false) === true) {
                $failed++;
                continue;
            }

            $reason = trim((string) ($claim['reason'] ?? ''));

            if (!in_array($reason, ['not_due', 'contended_or_not_due'], true)) {
                subscription_email_worker_log('malformed_claim');
                $runFailed = true;
            }

            break;
        }

        $processed++;
        $emailLogId = trim((string) ($claim['email_log_id'] ?? ''));
        $claimToken = trim((string) ($claim['claim_token'] ?? ''));
        $tenantId = trim((string) ($claim['tenant_id'] ?? ''));
        $paymentId = trim((string) ($claim['payment_id'] ?? ''));
        $emailType = strtolower(trim((string) ($claim['email_type'] ?? '')));
        $recipientEmail = trim((string) ($claim['recipient_email'] ?? ''));
        $activationRequired = $claim['activation_required'] ?? null;
        unset($claim['activation_token'], $claim['activation_expires_at']);

        if (
            security_uuid_or_null($emailLogId) === null
            || security_uuid_or_null($claimToken) === null
        ) {
            subscription_email_worker_log('malformed_claim');
            $failed++;
            $runFailed = true;
            break;
        }

        if (
            $tenantId === ''
            || security_uuid_or_null($paymentId) === null
            || !is_bool($activationRequired)
        ) {
            subscription_email_worker_log('malformed_claim');
            $failed++;

            if (!subscription_email_worker_fail_claim(
                $emailLogId,
                $claimToken,
                'claim_context_invalid'
            )) {
                subscription_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            continue;
        }

        if (!in_array($emailType, [
            'subscription_pro_activated',
            'subscription_vip_activated',
            'subscription_vip_custom_domain_requested',
        ], true)) {
            subscription_email_worker_log('unsupported_email_type');
            $failed++;

            if (!subscription_email_worker_fail_claim(
                $emailLogId,
                $claimToken,
                'unsupported_email_type'
            )) {
                subscription_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            continue;
        }

        if ($activationRequired) {
            subscription_email_worker_log('activation_flow_not_supported');
            $failed++;

            if (!subscription_email_worker_fail_claim(
                $emailLogId,
                $claimToken,
                'activation_flow_not_supported'
            )) {
                subscription_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            continue;
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            subscription_email_worker_log('recipient_invalid');
            $failed++;

            if (!subscription_email_worker_fail_claim(
                $emailLogId,
                $claimToken,
                'recipient_invalid'
            )) {
                subscription_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            continue;
        }

        $paymentResult = subscription_email_worker_fetch_payment($config, $paymentId, $tenantId);

        if (empty($paymentResult['ok']) || !is_array($paymentResult['payment'] ?? null)) {
            subscription_email_worker_log('payment_context_missing');
            $failed++;

            if (!subscription_email_worker_fail_claim(
                $emailLogId,
                $claimToken,
                'payment_context_missing'
            )) {
                subscription_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            continue;
        }

        $payment = $paymentResult['payment'];
        $isVipCustomDomainRequest = $emailType === 'subscription_vip_custom_domain_requested';
        $expectedPlanCode = in_array($emailType, [
            'subscription_vip_activated',
            'subscription_vip_custom_domain_requested',
        ], true) ? 'vip' : 'pro';

        if (($payment['plan_code'] ?? '') !== $expectedPlanCode) {
            subscription_email_worker_log('payment_context_missing');
            $failed++;

            if (!subscription_email_worker_fail_claim(
                $emailLogId,
                $claimToken,
                'payment_context_invalid'
            )) {
                subscription_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            continue;
        }

        $subscription = [
            'plan_code' => $payment['plan_code'],
            'billing_period' => $payment['billing_period'],
            'current_period_start' => trim((string) ($payment['subscription_period_start'] ?? '')),
            'current_period_end' => trim((string) ($payment['subscription_period_end'] ?? '')),
        ];
        $context = [
            'company_name' => is_scalar($claim['company_name'] ?? null)
                ? trim((string) $claim['company_name'])
                : '',
            'panel_domain' => is_scalar($claim['panel_domain'] ?? null)
                ? trim((string) $claim['panel_domain'])
                : '',
        ];
        if ($isVipCustomDomainRequest) {
            $subject = 'Prośba o podłączenie własnej domeny została przyjęta';
            $html = buildSubscriptionVipCustomDomainRequestedCustomerMailHtml($context);
        } else {
            $planLabel = $expectedPlanCode === 'vip' ? 'VIP' : 'Pro';
            $subject = 'Plan ' . $planLabel . ' aktywny';
            $html = buildSubscriptionProActivatedMailHtml($payment, $subscription, $context);
        }

        if (!sendSystemMail($recipientEmail, $subject, $html)) {
            subscription_email_worker_log('smtp_send_failed');
            $failed++;

            if (!subscription_email_worker_fail_claim(
                $emailLogId,
                $claimToken,
                'smtp_send_failed'
            )) {
                subscription_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            continue;
        }

        $sent++;

        if (!subscription_email_worker_record_sent($emailLogId, $claimToken)) {
            subscription_email_worker_log('record_result_failed');
            $failed++;
            $runFailed = true;
            break;
        }
    }
} catch (Throwable $e) {
    subscription_email_worker_log('worker_run_failed');
    $runFailed = true;
}

if ($runFailed) {
    subscription_email_worker_response([
        'success' => false,
        'processed' => $processed,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
    ], 500);
}

$successPayload = [
    'success' => true,
    'processed' => $processed,
    'sent' => $sent,
    'failed' => $failed,
    'skipped' => $skipped,
];

if ($processed > 0) {
    subscription_email_worker_log('worker_run_success');
}

subscription_email_worker_response($successPayload);
