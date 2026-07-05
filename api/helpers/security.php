<?php
declare(strict_types=1);

function security_allowed_schema(): string
{
    $schema = trim((string) (getenv('SUPABASE_DB_SCHEMA') ?: ''));

    if ($schema === '') {
        return 'rezerwacja_pro';
    }

    if ($schema !== 'rezerwacja_pro') {
        throw new RuntimeException('Invalid security schema configuration.');
    }

    return $schema;
}

function security_supabase_config(): array
{
    $url = rtrim(trim((string) getenv('SUPABASE_URL')), '/');
    $serviceRoleKey = trim((string) getenv('SUPABASE_SERVICE_ROLE_KEY'));

    if ($url === '' || $serviceRoleKey === '') {
        throw new RuntimeException('Missing security Supabase configuration.');
    }

    return [
        'url' => $url,
        'service_role_key' => $serviceRoleKey,
        'schema' => security_allowed_schema(),
    ];
}

function security_supabase_rpc(string $functionName, array $payload): array
{
    $allowedFunctions = [
        'security_rate_limit_check',
        'security_log_event',
    ];

    if (!in_array($functionName, $allowedFunctions, true)) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'rpc_not_allowed',
            'data' => null,
        ];
    }

    try {
        $config = security_supabase_config();
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'configuration_error',
            'data' => null,
        ];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'payload_encode_error',
            'data' => null,
        ];
    }

    $url = $config['url'] . '/rest/v1/rpc/' . rawurlencode($functionName);

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'curl_missing',
            'data' => null,
        ];
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'curl_init_error',
            'data' => null,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 5,
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
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = null;

    if (is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
    }

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'status' => $httpCode,
            'error' => $curlError !== '' ? 'request_failed' : 'http_error',
            'data' => is_array($decoded) ? $decoded : null,
        ];
    }

    return [
        'ok' => true,
        'status' => $httpCode,
        'error' => '',
        'data' => $decoded,
    ];
}

function security_normalize_ip(?string $ip): ?string
{
    $ip = trim((string) $ip);

    if ($ip === '') {
        return null;
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

function security_public_ip_or_null(?string $ip): ?string
{
    $ip = security_normalize_ip($ip);

    if ($ip === null) {
        return null;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ? $ip
        : null;
}

function security_uuid_or_null(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1
        ? $value
        : null;
}

function security_trusted_proxy_cidrs(): array
{
    $rawCidrs = trim((string) (getenv('TRUSTED_PROXY_CIDRS') ?: ''));

    if ($rawCidrs === '') {
        return [];
    }

    $cidrs = [];
    foreach (explode(',', $rawCidrs) as $cidr) {
        $cidr = trim($cidr);
        if ($cidr !== '') {
            $cidrs[] = $cidr;
        }
    }

    return $cidrs;
}

function security_ip_in_cidr(string $ip, string $cidr): bool
{
    $ip = security_normalize_ip($ip);
    $cidr = trim($cidr);

    if ($ip === '' || $ip === null || $cidr === '') {
        return false;
    }

    if (strpos($cidr, '/') === false) {
        $trustedIp = security_normalize_ip($cidr);
        return $trustedIp !== null && inet_pton($ip) === inet_pton($trustedIp);
    }

    [$rangeIp, $prefixLength] = array_pad(explode('/', $cidr, 2), 2, '');
    $rangeIp = security_normalize_ip($rangeIp);

    if ($rangeIp === null || !ctype_digit($prefixLength)) {
        return false;
    }

    $ipBinary = inet_pton($ip);
    $rangeBinary = inet_pton($rangeIp);

    if ($ipBinary === false || $rangeBinary === false || strlen($ipBinary) !== strlen($rangeBinary)) {
        return false;
    }

    $prefixBits = (int) $prefixLength;
    $maxBits = strlen($ipBinary) * 8;

    if ($prefixBits < 0 || $prefixBits > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($prefixBits, 8);
    $remainingBits = $prefixBits % 8;

    if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($rangeBinary, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;

    return (ord($ipBinary[$fullBytes]) & $mask) === (ord($rangeBinary[$fullBytes]) & $mask);
}

function security_is_trusted_proxy(?string $remoteAddr): bool
{
    $remoteAddr = security_normalize_ip($remoteAddr);

    if ($remoteAddr === null) {
        return false;
    }

    foreach (security_trusted_proxy_cidrs() as $cidr) {
        if (security_ip_in_cidr($remoteAddr, $cidr)) {
            return true;
        }
    }

    return false;
}

function security_first_forwarded_ip(?string $xff): ?string
{
    if ($xff === null) {
        return null;
    }

    foreach (explode(',', $xff) as $candidate) {
        $candidate = security_public_ip_or_null($candidate);

        if ($candidate !== null) {
            return $candidate;
        }
    }

    return null;
}

function security_client_ip(): ?string
{
    $remoteAddr = security_normalize_ip($_SERVER['REMOTE_ADDR'] ?? null);

    if ($remoteAddr === null) {
        return null;
    }

    if (!security_is_trusted_proxy($remoteAddr)) {
        return $remoteAddr;
    }

    $cloudflareIp = security_public_ip_or_null($_SERVER['HTTP_CF_CONNECTING_IP'] ?? null);
    if ($cloudflareIp !== null) {
        return $cloudflareIp;
    }

    $realIp = security_public_ip_or_null($_SERVER['HTTP_X_REAL_IP'] ?? null);
    if ($realIp !== null) {
        return $realIp;
    }

    $forwardedIp = security_first_forwarded_ip($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null);
    if ($forwardedIp !== null) {
        return $forwardedIp;
    }

    return $remoteAddr;
}

function security_user_agent(): string
{
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $userAgent = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $userAgent) ?? '';
    $userAgent = trim($userAgent);

    if ($userAgent === '') {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($userAgent, 0, 300, 'UTF-8')
        : substr($userAgent, 0, 300);
}

function security_hash_value(?string $value, string $purpose): ?string
{
    $normalizedValue = trim((string) $value);
    $purpose = trim($purpose);

    if ($normalizedValue === '') {
        return null;
    }

    if ($purpose === '') {
        throw new RuntimeException('Missing security hash purpose.');
    }

    $secret = trim((string) getenv('SECURITY_HASH_SECRET'));

    if ($secret === '') {
        $secret = trim((string) getenv('APP_SECRET'));
    }

    if ($secret === '') {
        throw new RuntimeException('Missing security hash secret.');
    }

    return hash_hmac('sha256', $purpose . '|' . $normalizedValue, $secret);
}

function security_email_hash(?string $email): ?string
{
    $email = trim((string) $email);

    if ($email === '') {
        return null;
    }

    $email = function_exists('mb_strtolower')
        ? mb_strtolower($email, 'UTF-8')
        : strtolower($email);

    return security_hash_value($email, 'email');
}

function security_ip_hash(?string $ip): ?string
{
    return security_hash_value($ip, 'ip');
}

function security_tenant_hash(?string $tenantId): ?string
{
    return security_hash_value($tenantId, 'tenant');
}

function security_user_hash(?string $userId): ?string
{
    return security_hash_value($userId, 'user');
}

function security_staff_account_hash(?string $staffAccountId): ?string
{
    return security_hash_value($staffAccountId, 'staff_account');
}

function security_staff_hash(?string $staffId): ?string
{
    return security_hash_value($staffId, 'staff');
}

function security_user_agent_hash(?string $ua): ?string
{
    return security_hash_value($ua, 'user_agent');
}

function security_session_hash(?string $sessionId = null): ?string
{
    $sessionId = $sessionId !== null ? $sessionId : '';

    if ($sessionId === '' && session_status() === PHP_SESSION_ACTIVE) {
        $sessionId = session_id();
    }

    return security_hash_value($sessionId, 'session');
}

function security_sanitize_event_details(array $details): array
{
    $sanitized = [];
    $blockedKeyPattern = '/(^|_)(password|passwd|pass|token|code|secret|authorization|auth|cookie|api_key|apikey|service_role|payment_url|raw|payload|body|request|response|headers?)(_|$)/i';
    $technicalIdKeyPattern = '/(^id$|_id$|^uuid$|^booking_id$|^tenant_id$|^user_id$|^staff_id$|^subscription_id$|^payment_id$|^payment_order_id$|^ext_order_id$|^order_id$)/i';
    $uuidPattern = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i';

    foreach ($details as $key => $value) {
        $keyString = is_string($key) ? $key : (string) $key;

        if (preg_match($blockedKeyPattern, $keyString) === 1 || preg_match($technicalIdKeyPattern, $keyString) === 1) {
            continue;
        }

        if (is_array($value)) {
            $sanitized[$key] = security_sanitize_event_details($value);
            continue;
        }

        if (is_object($value)) {
            $sanitized[$key] = '[object]';
            continue;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            $sanitized[$key] = $value;
            continue;
        }

        $text = trim((string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
        $text = preg_replace('#https?://\S+#i', '[url]', $text) ?? $text;
        $text = preg_replace($uuidPattern, '[uuid]', $text) ?? $text;

        $sanitized[$key] = function_exists('mb_substr')
            ? mb_substr($text, 0, 500, 'UTF-8')
            : substr($text, 0, 500);
    }

    return $sanitized;
}

function security_context_value(array $context, string $key): ?string
{
    if (!array_key_exists($key, $context)) {
        return null;
    }

    $value = $context[$key];

    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string) $value);

    return $value !== '' ? $value : null;
}

function security_session_context_record(string $sessionKey): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }

    $record = $_SESSION[$sessionKey] ?? null;

    return is_array($record) ? $record : [];
}

function security_session_context_value(array $record, string $key): ?string
{
    if (!array_key_exists($key, $record) || !is_scalar($record[$key])) {
        return null;
    }

    $value = trim((string) $record[$key]);

    return $value !== '' ? $value : null;
}

function security_log_event_session_context(?string $actorType): array
{
    $actorType = strtolower(trim((string) $actorType));
    $userSession = security_session_context_record('user');
    $staffSession = security_session_context_record('staff_user');
    $useStaffSession = $actorType !== ''
        && (strpos($actorType, 'staff') !== false || strpos($actorType, 'personel') !== false || strpos($actorType, 'personnel') !== false);
    $useUserSession = !$useStaffSession
        && $actorType !== ''
        && (strpos($actorType, 'tenant') !== false
            || strpos($actorType, 'admin') !== false
            || $actorType === 'user'
            || strpos($actorType, 'user') !== false);

    if ($actorType === '') {
        $useStaffSession = !empty($staffSession) && empty($userSession);
        $useUserSession = !empty($userSession) && empty($staffSession);
    }

    $context = [];

    if ($useStaffSession && !empty($staffSession)) {
        $context['tenant_id'] = security_session_context_value($staffSession, 'tenant_id');
        $context['staff_account_id'] = security_session_context_value($staffSession, 'account_id');
        $context['staff_id'] = security_session_context_value($staffSession, 'staff_id');
    }

    if ($useUserSession && !empty($userSession)) {
        $context['tenant_id'] = $context['tenant_id'] ?? security_session_context_value($userSession, 'tenant_id');
        $context['user_id'] = security_session_context_value($userSession, 'id');
    }

    return $context;
}

function security_default_endpoint(): string
{
    $script = trim((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($script !== '') {
        return $script;
    }

    return trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
}

function security_log_event(string $eventKey, array $context = []): array
{
    try {
        $eventKey = trim($eventKey);

        if ($eventKey === '') {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'missing_event_key',
            ];
        }

        $ipAddress = security_normalize_ip(security_context_value($context, 'ip_address')) ?? security_client_ip();
        $userAgent = security_context_value($context, 'user_agent') ?? security_user_agent();
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $actorType = security_context_value($context, 'actor_type');
        $sessionContext = security_log_event_session_context($actorType);

        $tenantId = security_context_value($context, 'tenant_id') ?? security_context_value($sessionContext, 'tenant_id');
        $userId = security_uuid_or_null(security_context_value($context, 'user_id') ?? security_context_value($sessionContext, 'user_id'));
        $staffAccountId = security_uuid_or_null(security_context_value($context, 'staff_account_id') ?? security_context_value($sessionContext, 'staff_account_id'));
        $staffId = security_uuid_or_null(security_context_value($context, 'staff_id') ?? security_context_value($sessionContext, 'staff_id'));

        $payload = [
            'p_event_key' => $eventKey,
            'p_action_key' => security_context_value($context, 'action_key') ?? $eventKey,
            'p_severity' => security_context_value($context, 'severity') ?? 'medium',
            'p_actor_type' => $actorType ?? 'unknown',
            'p_tenant_id' => null,
            'p_user_id' => null,
            'p_staff_account_id' => null,
            'p_staff_id' => null,
            'p_tenant_hash' => security_context_value($context, 'tenant_hash') ?? security_tenant_hash($tenantId),
            'p_user_hash' => security_context_value($context, 'user_hash') ?? security_user_hash($userId),
            'p_staff_account_hash' => security_context_value($context, 'staff_account_hash') ?? security_staff_account_hash($staffAccountId),
            'p_staff_hash' => security_context_value($context, 'staff_hash') ?? security_staff_hash($staffId),
            'p_ip_address' => null,
            'p_ip_hash' => security_context_value($context, 'ip_hash') ?? security_ip_hash($ipAddress),
            'p_email_hash' => security_context_value($context, 'email_hash') ?? security_email_hash(security_context_value($context, 'email')),
            'p_phone_hash' => security_context_value($context, 'phone_hash') ?? security_hash_value(security_context_value($context, 'phone'), 'phone'),
            'p_user_agent_hash' => security_context_value($context, 'user_agent_hash') ?? security_user_agent_hash($userAgent),
            'p_user_agent_preview' => $userAgent !== '' ? $userAgent : null,
            'p_endpoint' => security_context_value($context, 'endpoint') ?? security_default_endpoint(),
            'p_http_method' => security_context_value($context, 'http_method') ?? strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            'p_response_status' => isset($context['response_status']) && is_numeric($context['response_status']) ? (int) $context['response_status'] : null,
            'p_result' => security_context_value($context, 'result'),
            'p_request_id' => security_context_value($context, 'request_id') ?? security_context_value($context, 'request_ref'),
            'p_session_hash' => security_context_value($context, 'session_hash') ?? security_session_hash(security_context_value($context, 'session_id')),
            'p_details' => security_sanitize_event_details($details),
        ];

        return security_supabase_rpc('security_log_event', $payload);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'security_event_error',
        ];
    }
}

function security_rate_limit_scope_type(array $identity): string
{
    $parts = [];

    foreach (['tenant_id', 'user_id', 'staff_account_id', 'staff_id', 'email', 'phone', 'ip', 'session_id'] as $key) {
        if (security_context_value($identity, $key) !== null) {
            $parts[] = $key;
        }
    }

    return $parts ? implode('+', $parts) : 'anonymous';
}

function security_rate_limit_scope_hash(string $actionKey, array $identity): string
{
    $scopeParts = ['action=' . $actionKey];

    foreach (['tenant_id', 'user_id', 'staff_account_id', 'staff_id', 'email', 'phone', 'ip', 'session_id'] as $key) {
        $value = security_context_value($identity, $key);

        if ($value === null) {
            continue;
        }

        if ($key === 'email') {
            $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        }

        $scopeParts[] = $key . '=' . $value;
    }

    return (string) security_hash_value(implode('|', $scopeParts), 'rate_limit_scope');
}

function security_rate_limit_has_scope_identity(array $identity): bool
{
    foreach (['tenant_id', 'user_id', 'staff_account_id', 'staff_id', 'email', 'phone', 'ip', 'session_id'] as $key) {
        if (security_context_value($identity, $key) !== null) {
            return true;
        }
    }

    return false;
}

function security_rate_limit_check(string $actionKey, array $identity = [], array $context = []): array
{
    try {
        $actionKey = trim($actionKey);

        if ($actionKey === '') {
            return [
                'ok' => false,
                'allowed' => true,
                'retry_after_seconds' => null,
                'remaining' => null,
                'raw' => null,
                'error' => 'missing_action_key',
            ];
        }

        $ipAddress = security_normalize_ip(security_context_value($context, 'ip_address'))
            ?? security_normalize_ip(security_context_value($identity, 'ip'))
            ?? security_client_ip();
        $userAgent = security_context_value($context, 'user_agent') ?? security_user_agent();
        $sessionId = security_context_value($identity, 'session_id') ?? security_context_value($context, 'session_id');
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $scopeIdentity = $identity;
        $scopeIdentityIp = security_normalize_ip(security_context_value($scopeIdentity, 'ip'));

        if ($scopeIdentityIp !== null) {
            $scopeIdentity['ip'] = $scopeIdentityIp;
        } else {
            unset($scopeIdentity['ip']);
        }

        if (security_context_value($scopeIdentity, 'ip') === null && $ipAddress !== null) {
            $scopeIdentity['ip'] = $ipAddress;
        }

        if (security_context_value($scopeIdentity, 'session_id') === null && $sessionId !== null) {
            $scopeIdentity['session_id'] = $sessionId;
        }

        foreach (['tenant_id', 'user_id', 'staff_account_id', 'staff_id', 'email', 'phone'] as $key) {
            if (security_context_value($scopeIdentity, $key) !== null) {
                continue;
            }

            $contextValue = security_context_value($context, $key);
            if ($contextValue !== null) {
                $scopeIdentity[$key] = $contextValue;
            }
        }

        if (!security_rate_limit_has_scope_identity($scopeIdentity)) {
            return [
                'ok' => false,
                'allowed' => true,
                'retry_after_seconds' => null,
                'remaining' => null,
                'raw' => null,
                'error' => 'missing_scope_identity',
            ];
        }

        $payload = [
            'p_action_key' => $actionKey,
            'p_scope_hash' => security_rate_limit_scope_hash($actionKey, $scopeIdentity),
            'p_scope_type' => security_context_value($context, 'scope_type') ?? security_rate_limit_scope_type($scopeIdentity),
            'p_ip_address' => $ipAddress,
            'p_ip_hash' => security_ip_hash($ipAddress),
            'p_email_hash' => security_email_hash(security_context_value($scopeIdentity, 'email')),
            'p_tenant_hash' => security_tenant_hash(security_context_value($scopeIdentity, 'tenant_id')),
            'p_user_hash' => security_user_hash(security_context_value($scopeIdentity, 'user_id')),
            'p_user_agent_hash' => security_user_agent_hash($userAgent),
            'p_endpoint' => security_context_value($context, 'endpoint') ?? security_default_endpoint(),
            'p_http_method' => security_context_value($context, 'http_method') ?? strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            'p_request_id' => security_context_value($context, 'request_id') ?? security_context_value($context, 'request_ref'),
            'p_session_hash' => security_session_hash($sessionId),
            'p_metadata' => security_sanitize_event_details($metadata),
        ];

        $rpcResult = security_supabase_rpc('security_rate_limit_check', $payload);

        if (empty($rpcResult['ok'])) {
            // Public endpoints currently fail open if the security RPC is unavailable.
            // Critical actions can be moved to fail-closed later per action key.
            return [
                'ok' => false,
                'allowed' => true,
                'retry_after_seconds' => null,
                'remaining' => null,
                'raw' => null,
                'error' => $rpcResult['error'] ?? 'rpc_error',
            ];
        }

        $raw = $rpcResult['data'];
        $data = is_array($raw) && isset($raw[0]) && is_array($raw[0])
            ? $raw[0]
            : (is_array($raw) ? $raw : []);

        return [
            'ok' => true,
            'allowed' => (bool) ($data['allowed'] ?? $data['is_allowed'] ?? true),
            'retry_after_seconds' => isset($data['retry_after_seconds']) && is_numeric($data['retry_after_seconds'])
                ? (int) $data['retry_after_seconds']
                : null,
            'remaining' => isset($data['remaining']) && is_numeric($data['remaining'])
                ? (int) $data['remaining']
                : null,
            'raw' => $data,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'allowed' => true,
            'retry_after_seconds' => null,
            'remaining' => null,
            'raw' => null,
            'error' => 'security_rate_limit_error',
        ];
    }
}

function security_neutral_rate_limit_response(array $result): array
{
    $response = [
        'success' => false,
        'message' => 'Zbyt wiele prób. Spróbuj ponownie za chwilę.',
    ];

    if (isset($result['retry_after_seconds']) && is_numeric($result['retry_after_seconds'])) {
        $response['retry_after_seconds'] = max(0, (int) $result['retry_after_seconds']);
    }

    return $response;
}
