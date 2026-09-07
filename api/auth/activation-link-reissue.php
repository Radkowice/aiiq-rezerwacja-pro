<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/security.php';

start_secure_session();

function activation_reissue_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function activation_reissue_neutral_success(): void
{
    activation_reissue_json([
        'success' => true,
        'message' => 'Jeśli konto wymaga aktywacji, wyślemy nowy link aktywacyjny.',
    ]);
}

function activation_reissue_security_event(
    string $eventKey,
    string $reason,
    int $statusCode,
    string $result = 'failed',
    string $severity = 'medium',
    array $context = []
): void {
    $details = ['reason' => $reason];

    if (isset($context['stage']) && is_scalar($context['stage'])) {
        $details['stage'] = (string) $context['stage'];
    }

    security_log_event($eventKey, [
        'action_key' => 'activation_reissue',
        'severity' => $severity,
        'actor_type' => 'tenant_user',
        'tenant_id' => '',
        'user_id' => '',
        'email' => (string) ($context['email'] ?? ''),
        'ip_address' => security_client_ip(),
        'endpoint' => '/api/auth/activation-link-reissue.php',
        'http_method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')),
        'response_status' => $statusCode,
        'result' => $result,
        'details' => $details,
    ]);
}

function activation_reissue_normalize_email(string $email): string
{
    $email = trim($email);
    $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL);

    return is_string($validEmail) ? $validEmail : '';
}

function activation_reissue_user_agent(): ?string
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if (
        $userAgent === ''
        || strlen($userAgent) > 1024
        || preg_match('/[\x00-\x1F\x7F]/', $userAgent)
    ) {
        return null;
    }

    return $userAgent;
}

function activation_reissue_rate_limit(string $email): void
{
    $rateFile = __DIR__ . '/../data/rate_limit_activation_link_reissue.json';
    $rateDir = dirname($rateFile);

    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0775, true);
    }

    $rateData = file_exists($rateFile)
        ? json_decode((string) file_get_contents($rateFile), true)
        : [];

    if (!is_array($rateData)) {
        $rateData = [];
    }

    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $emailKey = function_exists('mb_strtolower')
        ? mb_strtolower($email, 'UTF-8')
        : strtolower($email);
    $rateKey = hash('sha256', $ip . '|' . $emailKey);
    $now = time();
    $windowSeconds = 600;
    $maxAttempts = 3;

    $rateData[$rateKey] = array_values(array_filter(
        $rateData[$rateKey] ?? [],
        static function ($timestamp) use ($now, $windowSeconds): bool {
            return is_numeric($timestamp) && ($now - (int) $timestamp) < $windowSeconds;
        }
    ));

    if (count($rateData[$rateKey]) >= $maxAttempts) {
        activation_reissue_security_event(
            'activation_reissue_rate_limited',
            'activation_reissue',
            429,
            'blocked',
            'high',
            [
                'email' => $email,
                'stage' => 'legacy_json_rate_limit',
            ]
        );

        activation_reissue_json([
            'success' => false,
            'error' => 'Zbyt wiele prób. Spróbuj ponownie za 10 minut.',
        ], 429);
    }

    $rateData[$rateKey][] = $now;

    @file_put_contents(
        $rateFile,
        json_encode($rateData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function activation_reissue_request_key(string $email): string
{
    $bucket = intdiv(time(), 600);

    return 'activation-reissue-v1:'
        . hash('sha256', strtolower(trim($email)) . "\n" . (string) $bucket);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        activation_reissue_security_event(
            'activation_reissue_method_not_allowed',
            'method_not_allowed',
            405,
            'failed',
            'low'
        );
        header('Allow: POST');
        activation_reissue_json([
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ], 405);
    }

    $input = json_decode((string) file_get_contents('php://input'), true);

    if (!is_array($input)) {
        activation_reissue_security_event(
            'activation_reissue_invalid_json',
            'invalid_json',
            400,
            'failed',
            'low'
        );
        activation_reissue_json([
            'success' => false,
            'error' => 'Podaj poprawny adres e-mail.',
        ], 400);
    }

    $email = activation_reissue_normalize_email((string) ($input['email'] ?? ''));

    if ($email === '') {
        activation_reissue_security_event(
            'activation_reissue_invalid_email',
            'invalid_email',
            400,
            'failed',
            'low'
        );
        activation_reissue_json([
            'success' => false,
            'error' => 'Podaj poprawny adres e-mail.',
        ], 400);
    }

    $securityEmail = $email;
    $securityIp = security_client_ip();
    $securityEndpoint = '/api/auth/activation-link-reissue.php';
    $securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';

    $rateLimitResult = security_rate_limit_check(
        'activation_reissue',
        [
            'email' => $securityEmail,
            'ip' => $securityIp,
        ],
        [
            'endpoint' => $securityEndpoint,
            'http_method' => $securityMethod,
            'actor_type' => 'tenant_user',
            'email' => $securityEmail,
            'ip_address' => $securityIp,
            'metadata' => [
                'reason' => 'activation_reissue',
            ],
        ]
    );

    if (isset($rateLimitResult['allowed']) && $rateLimitResult['allowed'] === false) {
        activation_reissue_security_event(
            'activation_reissue_rate_limited',
            'activation_reissue',
            429,
            'blocked',
            'high',
            [
                'email' => $securityEmail,
                'stage' => 'security_rate_limit_check',
            ]
        );

        http_response_code(429);

        $rateLimitPayload = security_neutral_rate_limit_response($rateLimitResult);
        if (!isset($rateLimitPayload['error'])) {
            $rateLimitPayload['error'] = (string) (
                $rateLimitPayload['message']
                ?? 'Zbyt wiele prób. Spróbuj ponownie za chwilę.'
            );
        }

        echo json_encode(
            $rateLimitPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    activation_reissue_rate_limit($email);

    $requestKey = activation_reissue_request_key($email);
    $ipAddress = filter_var($securityIp, FILTER_VALIDATE_IP)
        ? $securityIp
        : null;

    $rpcResult = payment_lifecycle_v3_rpc(
        'subscription_activation_reissue_enqueue',
        [
            'p_email' => $email,
            'p_request_key' => $requestKey,
            'p_ip_address' => $ipAddress,
            'p_user_agent' => activation_reissue_user_agent(),
        ]
    );

    if (
        empty($rpcResult['ok'])
        || !is_array($rpcResult['data'] ?? null)
        || ($rpcResult['data']['accepted'] ?? null) !== true
    ) {
        activation_reissue_security_event(
            'activation_reissue_enqueue_failed',
            'enqueue_failed',
            500,
            'failed',
            'high',
            [
                'email' => $securityEmail,
                'stage' => 'subscription_activation_reissue_enqueue',
            ]
        );
        activation_reissue_json([
            'success' => false,
            'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.',
        ], 500);
    }

    activation_reissue_security_event(
        'activation_reissue_request_accepted',
        'activation_reissue',
        200,
        'accepted',
        'low',
        [
            'email' => $securityEmail,
            'stage' => 'queued',
        ]
    );

    activation_reissue_neutral_success();
} catch (Throwable $e) {
    activation_reissue_security_event(
        'activation_reissue_fatal',
        'fatal',
        500,
        'failed',
        'critical'
    );
    activation_reissue_json([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.',
    ], 500);
}
