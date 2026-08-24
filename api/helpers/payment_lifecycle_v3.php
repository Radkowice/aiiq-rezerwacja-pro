<?php
declare(strict_types=1);

function payment_lifecycle_v3_allowed_rpc_names(): array
{
    return [
        'subscription_registration_attempt_begin',
        'subscription_registration_commit_initial',
        'subscription_payment_begin',
        'subscription_payment_mark_provider_started',
        'subscription_payment_record_provider_result',
        'subscription_payment_apply_payu_notification',
        'subscription_activation_reissue_enqueue',
        'subscription_email_claim',
        'subscription_email_record_result',
        'subscription_activation_consume',
    ];
}

function payment_lifecycle_v3_schema(): string
{
    $schema = trim((string) (getenv('SUPABASE_DB_SCHEMA') ?: ''));

    if ($schema === '') {
        return 'rezerwacja_pro';
    }

    if ($schema !== 'rezerwacja_pro') {
        throw new RuntimeException('Invalid Payment Lifecycle schema configuration.');
    }

    return $schema;
}

function payment_lifecycle_v3_config(): array
{
    $url = rtrim(trim((string) getenv('SUPABASE_URL')), '/');
    $serviceRoleKey = trim((string) getenv('SUPABASE_SERVICE_ROLE_KEY'));

    if ($url === '' || $serviceRoleKey === '') {
        throw new RuntimeException('Missing Payment Lifecycle Supabase configuration.');
    }

    return [
        'url' => $url,
        'service_role_key' => $serviceRoleKey,
        'schema' => payment_lifecycle_v3_schema(),
    ];
}

function payment_lifecycle_v3_failure(
    string $errorKind,
    string $errorCode,
    int $httpStatus = 0
): array {
    return [
        'ok' => false,
        'status' => $httpStatus,
        'error_kind' => $errorKind,
        'error' => $errorCode,
        'data' => null,
    ];
}

function payment_lifecycle_v3_is_json_object($value): bool
{
    if (!is_array($value) || $value === []) {
        return false;
    }

    foreach (array_keys($value) as $key) {
        if (!is_string($key)) {
            return false;
        }
    }

    return true;
}

function payment_lifecycle_v3_rpc(string $functionName, array $payload): array
{
    if (!in_array($functionName, payment_lifecycle_v3_allowed_rpc_names(), true)) {
        return payment_lifecycle_v3_failure('policy_failure', 'rpc_not_allowed');
    }

    try {
        $config = payment_lifecycle_v3_config();
    } catch (Throwable $e) {
        return payment_lifecycle_v3_failure('configuration_failure', 'configuration_error');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return payment_lifecycle_v3_failure('local_failure', 'payload_encode_error');
    }

    if (!function_exists('curl_init')) {
        return payment_lifecycle_v3_failure('transport_failure', 'curl_missing');
    }

    $url = $config['url'] . '/rest/v1/rpc/' . rawurlencode($functionName);
    $ch = curl_init($url);

    if ($ch === false) {
        return payment_lifecycle_v3_failure('transport_failure', 'curl_init_error');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $config['service_role_key'],
            'Authorization: Bearer ' . $config['service_role_key'],
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Profile: ' . $config['schema'],
            'Content-Profile: ' . $config['schema'],
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return payment_lifecycle_v3_failure('transport_failure', 'request_failed', $httpStatus);
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return payment_lifecycle_v3_failure('rpc_error', 'rpc_http_error', $httpStatus);
    }

    if (!is_string($response) || trim($response) === '') {
        return payment_lifecycle_v3_failure('malformed_response', 'empty_rpc_response', $httpStatus);
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !payment_lifecycle_v3_is_json_object($decoded)) {
        return payment_lifecycle_v3_failure('malformed_response', 'invalid_rpc_json', $httpStatus);
    }

    return [
        'ok' => true,
        'status' => $httpStatus,
        'error_kind' => '',
        'error' => '',
        'data' => $decoded,
    ];
}

function payment_lifecycle_v3_credential_fingerprint(string $plaintextPassword): string
{
    $secret = getenv('AI_IQ_PAYMENT_CREDENTIAL_FINGERPRINT_SECRET');

    if (!is_string($secret) || $secret === '') {
        throw new RuntimeException('Missing credential fingerprint configuration.');
    }

    $message = "subscription-registration-credential-v1\n" . $plaintextPassword;
    $fingerprint = hash_hmac('sha256', $message, $secret, false);

    if (strlen($fingerprint) !== 64 || preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
        throw new RuntimeException('Credential fingerprint calculation failed.');
    }

    return $fingerprint;
}
