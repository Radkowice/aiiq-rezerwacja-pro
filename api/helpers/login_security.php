<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/security.php';

function login_security_actor(string $actorType): string
{
    $actorType = strtolower(trim($actorType));

    if (!in_array($actorType, ['admin', 'staff'], true)) {
        throw new InvalidArgumentException('Invalid login actor type.');
    }

    return $actorType;
}

function login_security_normalize_email(string $email): string
{
    $email = trim($email);

    return function_exists('mb_strtolower')
        ? mb_strtolower($email, 'UTF-8')
        : strtolower($email);
}

function login_security_code_ttl_seconds(): int
{
    $configured = (int) (getenv('LOGIN_CODE_TTL_SECONDS') ?: 600);

    return max(180, min(900, $configured));
}

function login_security_max_attempts(): int
{
    $configured = (int) (getenv('LOGIN_CODE_MAX_ATTEMPTS') ?: 5);

    return max(3, min(10, $configured));
}

function login_security_code_request_window_seconds(): int
{
    $configured = (int) (getenv('LOGIN_CODE_REQUEST_WINDOW_SECONDS') ?: 600);

    return max(60, min(3600, $configured));
}

function login_security_code_request_limit(): int
{
    $configured = (int) (getenv('LOGIN_CODE_REQUEST_LIMIT') ?: 3);

    return max(1, min(10, $configured));
}

function login_security_trusted_ttl_seconds(): int
{
    $configured = (int) (getenv('TRUSTED_DEVICE_TTL_SECONDS') ?: 2592000);

    return max(86400, min(7776000, $configured));
}

function login_security_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    string $prefer = 'return=representation'
): array {
    $headers = array_values(array_filter(
        supabaseHeaders($supabaseKey, $schema),
        static fn (string $header): bool => stripos($header, 'Prefer:') !== 0
    ));
    $headers[] = 'Prefer: ' . $prefer;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => 'curl_init_failed',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            curl_close($ch);

            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'error' => 'json_encode_failed',
            ];
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = null;

    if (is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
    }

    return [
        'ok' => $response !== false && $curlError === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $decoded,
        'error' => $curlError !== '' ? 'request_failed' : '',
    ];
}

function login_security_first_row(array $result): ?array
{
    $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
    $row = $rows[0] ?? null;

    return is_array($row) ? $row : null;
}

function login_security_storage_available(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema
): bool {
    $requiredSelects = [
        'auth_login_codes' => 'id,tenant_id,actor_type,subject_id,email_hash,code_hash,attempts,expires_at,created_at,request_ip_hash,user_agent_hash,used_at',
        'auth_trusted_devices' => 'id,tenant_id,actor_type,subject_id,selector_hash,token_hash,credential_hash,created_at,expires_at,last_used_at,revoked_at,created_ip_hash,user_agent_hash',
    ];

    foreach ($requiredSelects as $table => $select) {
        $result = login_security_request(
            'GET',
            $supabaseUrl . '/rest/v1/' . $table . '?select=' . rawurlencode($select) . '&limit=1',
            $supabaseKey,
            $schema,
            null,
            'return=minimal'
        );

        if (!$result['ok']) {
            return false;
        }
    }

    return true;
}

function login_security_rate_limit(
    string $actionKey,
    string $actorType,
    string $tenantId,
    string $email,
    ?string $subjectId = null
): array {
    $actorType = login_security_actor($actorType);
    $identity = [
        'tenant_id' => $tenantId,
        'email' => login_security_normalize_email($email),
        'ip' => security_client_ip(),
    ];

    if ($actorType === 'admin' && $subjectId !== null && $subjectId !== '') {
        $identity['user_id'] = $subjectId;
    }

    if ($actorType === 'staff' && $subjectId !== null && $subjectId !== '') {
        $identity['staff_account_id'] = $subjectId;
    }

    $context = [
        'endpoint' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        'http_method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')),
        'actor_type' => $actorType === 'admin' ? 'tenant_user' : 'staff_user',
        'tenant_id' => $tenantId,
        'email' => $email,
        'ip_address' => security_client_ip(),
        'metadata' => [
            'reason' => $actionKey,
        ],
    ];
    $primary = security_rate_limit_check($actionKey, $identity, $context);

    if (strpos($actionKey, 'auth_login_code_request_') !== 0) {
        return $primary;
    }

    $ipResult = security_rate_limit_check(
        $actionKey . '_ip',
        [
            'tenant_id' => $tenantId,
            'ip' => security_client_ip(),
        ],
        $context
    );

    if (empty($primary['ok']) || empty($ipResult['ok'])) {
        return [
            'ok' => false,
            'allowed' => false,
            'retry_after_seconds' => null,
            'remaining' => null,
            'raw' => null,
            'error' => 'login_code_rate_limit_unavailable',
        ];
    }

    $retryAfter = max(
        (int) ($primary['retry_after_seconds'] ?? 0),
        (int) ($ipResult['retry_after_seconds'] ?? 0)
    );

    return [
        'ok' => true,
        'allowed' => ($primary['allowed'] ?? true) === true && ($ipResult['allowed'] ?? true) === true,
        'retry_after_seconds' => $retryAfter > 0 ? $retryAfter : null,
        'remaining' => null,
        'raw' => null,
    ];
}

function login_security_code_hash(
    string $actorType,
    string $tenantId,
    string $subjectId,
    string $code
): string {
    $actorType = login_security_actor($actorType);
    $hash = security_hash_value(
        $actorType . '|' . $tenantId . '|' . $subjectId . '|' . $code,
        'login_code'
    );

    if ($hash === null) {
        throw new RuntimeException('Unable to hash login code.');
    }

    return $hash;
}

function login_security_invalidate_codes(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $actorType,
    string $tenantId,
    string $subjectId
): bool {
    $actorType = login_security_actor($actorType);
    $url = $supabaseUrl
        . '/rest/v1/auth_login_codes'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&actor_type=eq.' . rawurlencode($actorType)
        . '&subject_id=eq.' . rawurlencode($subjectId)
        . '&used_at=is.null';

    $result = login_security_request(
        'PATCH',
        $url,
        $supabaseKey,
        $schema,
        ['used_at' => gmdate('c')],
        'return=minimal'
    );

    return $result['ok'];
}

function login_security_code_generation_allowed(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $actorType,
    string $tenantId,
    string $subjectId
): bool {
    $actorType = login_security_actor($actorType);
    $limit = login_security_code_request_limit();
    $createdAfter = gmdate('c', time() - login_security_code_request_window_seconds());
    $result = login_security_request(
        'GET',
        $supabaseUrl
            . '/rest/v1/auth_login_codes'
            . '?select=id'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&actor_type=eq.' . rawurlencode($actorType)
            . '&subject_id=eq.' . rawurlencode($subjectId)
            . '&created_at=gte.' . rawurlencode($createdAfter)
            . '&limit=' . $limit,
        $supabaseKey,
        $schema
    );

    if (!$result['ok'] || !is_array($result['data'] ?? null)) {
        return false;
    }

    return count($result['data']) < $limit;
}

function login_security_issue_code(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $actorType,
    string $tenantId,
    string $subjectId,
    string $email
): array {
    $actorType = login_security_actor($actorType);

    if (!login_security_code_generation_allowed(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $actorType,
        $tenantId,
        $subjectId
    )) {
        return ['ok' => false, 'challenge_id' => '', 'code' => ''];
    }

    if (!login_security_invalidate_codes(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $actorType,
        $tenantId,
        $subjectId
    )) {
        return ['ok' => false, 'challenge_id' => '', 'code' => ''];
    }

    $code = (string) random_int(100000, 999999);
    $now = time();
    $payload = [
        'tenant_id' => $tenantId,
        'actor_type' => $actorType,
        'subject_id' => $subjectId,
        'email_hash' => security_email_hash($email),
        'code_hash' => login_security_code_hash($actorType, $tenantId, $subjectId, $code),
        'attempts' => 0,
        'expires_at' => gmdate('c', $now + login_security_code_ttl_seconds()),
        'created_at' => gmdate('c', $now),
        'request_ip_hash' => security_ip_hash(security_client_ip()),
        'user_agent_hash' => security_user_agent_hash(security_user_agent()),
    ];

    $result = login_security_request(
        'POST',
        $supabaseUrl . '/rest/v1/auth_login_codes',
        $supabaseKey,
        $schema,
        $payload
    );
    $row = login_security_first_row($result);
    $challengeId = trim((string) ($row['id'] ?? ''));

    if (!$result['ok'] || $challengeId === '') {
        return ['ok' => false, 'challenge_id' => '', 'code' => ''];
    }

    return [
        'ok' => true,
        'challenge_id' => $challengeId,
        'code' => $code,
    ];
}

function login_security_cancel_code(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $challengeId
): void {
    if ($challengeId === '') {
        return;
    }

    login_security_request(
        'PATCH',
        $supabaseUrl
            . '/rest/v1/auth_login_codes'
            . '?id=eq.' . rawurlencode($challengeId)
            . '&used_at=is.null',
        $supabaseKey,
        $schema,
        ['used_at' => gmdate('c')],
        'return=minimal'
    );
}

function login_security_verify_code(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $actorType,
    string $tenantId,
    string $subjectId,
    string $code
): bool {
    $actorType = login_security_actor($actorType);

    if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
        return false;
    }

    $now = gmdate('c');
    $url = $supabaseUrl
        . '/rest/v1/auth_login_codes'
        . '?select=id,code_hash,attempts,expires_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&actor_type=eq.' . rawurlencode($actorType)
        . '&subject_id=eq.' . rawurlencode($subjectId)
        . '&used_at=is.null'
        . '&expires_at=gt.' . rawurlencode($now)
        . '&order=created_at.desc'
        . '&limit=1';
    $result = login_security_request('GET', $url, $supabaseKey, $schema);
    $row = login_security_first_row($result);

    if (!$result['ok'] || !is_array($row)) {
        return false;
    }

    $challengeId = trim((string) ($row['id'] ?? ''));
    $storedHash = trim((string) ($row['code_hash'] ?? ''));
    $attempts = max(0, (int) ($row['attempts'] ?? 0));
    $maxAttempts = login_security_max_attempts();

    if ($challengeId === '' || $storedHash === '' || $attempts >= $maxAttempts) {
        login_security_cancel_code($supabaseUrl, $supabaseKey, $schema, $challengeId);
        return false;
    }

    $reservedAttempts = $attempts + 1;
    $reserveResult = login_security_request(
        'PATCH',
        $supabaseUrl
            . '/rest/v1/auth_login_codes'
            . '?id=eq.' . rawurlencode($challengeId)
            . '&attempts=eq.' . $attempts
            . '&used_at=is.null'
            . '&expires_at=gt.' . rawurlencode($now),
        $supabaseKey,
        $schema,
        ['attempts' => $reservedAttempts]
    );

    if (!$reserveResult['ok'] || login_security_first_row($reserveResult) === null) {
        return false;
    }

    $candidateHash = login_security_code_hash($actorType, $tenantId, $subjectId, $code);

    if (!hash_equals($storedHash, $candidateHash)) {
        if ($reservedAttempts >= $maxAttempts) {
            login_security_cancel_code($supabaseUrl, $supabaseKey, $schema, $challengeId);
        }

        return false;
    }

    $consumeResult = login_security_request(
        'PATCH',
        $supabaseUrl
            . '/rest/v1/auth_login_codes'
            . '?id=eq.' . rawurlencode($challengeId)
            . '&attempts=eq.' . $reservedAttempts
            . '&used_at=is.null'
            . '&expires_at=gt.' . rawurlencode($now),
        $supabaseKey,
        $schema,
        ['used_at' => $now]
    );

    return $consumeResult['ok'] && login_security_first_row($consumeResult) !== null;
}

function login_security_cookie_name(string $actorType): string
{
    return login_security_actor($actorType) === 'admin'
        ? 'aiiq_admin_trusted_device'
        : 'aiiq_staff_trusted_device';
}

function login_security_clear_trusted_cookie(string $actorType): void
{
    setcookie(login_security_cookie_name($actorType), '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function login_security_parse_trusted_cookie(string $actorType): ?array
{
    $value = trim((string) ($_COOKIE[login_security_cookie_name($actorType)] ?? ''));

    if (preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $value, $matches) !== 1) {
        if ($value !== '') {
            login_security_clear_trusted_cookie($actorType);
        }

        return null;
    }

    return [
        'selector' => $matches[1],
        'validator' => $matches[2],
    ];
}

function login_security_trusted_selector_hash(string $selector): string
{
    $hash = security_hash_value($selector, 'trusted_device_selector');

    if ($hash === null) {
        throw new RuntimeException('Unable to hash trusted device selector.');
    }

    return $hash;
}

function login_security_trusted_token_hash(
    string $actorType,
    string $tenantId,
    string $subjectId,
    string $validator
): string {
    $hash = security_hash_value(
        login_security_actor($actorType) . '|' . $tenantId . '|' . $subjectId . '|' . $validator,
        'trusted_device_token'
    );

    if ($hash === null) {
        throw new RuntimeException('Unable to hash trusted device token.');
    }

    return $hash;
}

function login_security_trusted_credential_hash(string $credentialBinding): string
{
    $hash = security_hash_value($credentialBinding, 'trusted_device_credential');

    if ($hash === null) {
        throw new RuntimeException('Unable to hash trusted device credential binding.');
    }

    return $hash;
}

function login_security_issue_trusted_device(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $actorType,
    string $tenantId,
    string $subjectId,
    string $credentialBinding
): bool {
    $actorType = login_security_actor($actorType);
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $now = time();
    $ttl = login_security_trusted_ttl_seconds();
    $payload = [
        'tenant_id' => $tenantId,
        'actor_type' => $actorType,
        'subject_id' => $subjectId,
        'selector_hash' => login_security_trusted_selector_hash($selector),
        'token_hash' => login_security_trusted_token_hash(
            $actorType,
            $tenantId,
            $subjectId,
            $validator
        ),
        'credential_hash' => login_security_trusted_credential_hash($credentialBinding),
        'created_at' => gmdate('c', $now),
        'expires_at' => gmdate('c', $now + $ttl),
        'last_used_at' => gmdate('c', $now),
        'revoked_at' => null,
        'created_ip_hash' => security_ip_hash(security_client_ip()),
        'user_agent_hash' => security_user_agent_hash(security_user_agent()),
    ];

    $result = login_security_request(
        'POST',
        $supabaseUrl . '/rest/v1/auth_trusted_devices',
        $supabaseKey,
        $schema,
        $payload
    );

    if (!$result['ok'] || login_security_first_row($result) === null) {
        return false;
    }

    setcookie(login_security_cookie_name($actorType), $selector . '.' . $validator, [
        'expires' => $now + $ttl,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return true;
}

function login_security_revoke_subject_devices(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $actorType,
    string $tenantId,
    string $subjectId
): bool {
    $actorType = login_security_actor($actorType);
    $result = login_security_request(
        'PATCH',
        $supabaseUrl
            . '/rest/v1/auth_trusted_devices'
            . '?tenant_id=eq.' . rawurlencode($tenantId)
            . '&actor_type=eq.' . rawurlencode($actorType)
            . '&subject_id=eq.' . rawurlencode($subjectId)
            . '&revoked_at=is.null',
        $supabaseKey,
        $schema,
        ['revoked_at' => gmdate('c')],
        'return=minimal'
    );

    login_security_clear_trusted_cookie($actorType);

    return $result['ok'];
}

function login_security_is_trusted_device(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $actorType,
    string $tenantId,
    string $subjectId,
    string $credentialBinding
): bool {
    $actorType = login_security_actor($actorType);
    $cookie = login_security_parse_trusted_cookie($actorType);

    if ($cookie === null) {
        return false;
    }

    $selectorHash = login_security_trusted_selector_hash($cookie['selector']);
    $now = gmdate('c');
    $url = $supabaseUrl
        . '/rest/v1/auth_trusted_devices'
        . '?select=id,token_hash,credential_hash'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&actor_type=eq.' . rawurlencode($actorType)
        . '&subject_id=eq.' . rawurlencode($subjectId)
        . '&selector_hash=eq.' . rawurlencode($selectorHash)
        . '&revoked_at=is.null'
        . '&expires_at=gt.' . rawurlencode($now)
        . '&limit=1';
    $result = login_security_request('GET', $url, $supabaseKey, $schema);
    $row = login_security_first_row($result);
    $storedHash = trim((string) ($row['token_hash'] ?? ''));
    $storedCredentialHash = trim((string) ($row['credential_hash'] ?? ''));
    $candidateHash = login_security_trusted_token_hash(
        $actorType,
        $tenantId,
        $subjectId,
        $cookie['validator']
    );
    $candidateCredentialHash = login_security_trusted_credential_hash($credentialBinding);

    if (
        !$result['ok']
        || $storedHash === ''
        || $storedCredentialHash === ''
        || !hash_equals($storedHash, $candidateHash)
        || !hash_equals($storedCredentialHash, $candidateCredentialHash)
    ) {
        login_security_clear_trusted_cookie($actorType);
        return false;
    }

    login_security_request(
        'PATCH',
        $supabaseUrl
            . '/rest/v1/auth_trusted_devices'
            . '?id=eq.' . rawurlencode((string) ($row['id'] ?? ''))
            . '&revoked_at=is.null',
        $supabaseKey,
        $schema,
        ['last_used_at' => $now],
        'return=minimal'
    );

    return true;
}

function login_security_send_code(string $email, string $actorType, string $code): bool
{
    if (!function_exists('sendSystemMail') || !function_exists('buildSystemMailLayout')) {
        return false;
    }

    $actorType = login_security_actor($actorType);
    $label = $actorType === 'admin' ? 'administratora' : 'personelu';
    $minutes = (int) ceil(login_security_code_ttl_seconds() / 60);
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $message = ''
        . '<p style="margin:0 0 14px;">Kod logowania do panelu ' . $label . ':</p>'
        . '<p style="margin:18px 0;text-align:center;font-size:30px;font-weight:700;letter-spacing:8px;">'
        . $safeCode
        . '</p>'
        . '<p style="margin:0 0 10px;">Kod jest jednorazowy i ważny przez <strong>'
        . $minutes
        . ' minut</strong>.</p>'
        . '<p style="margin:0;">Jeśli to nie Ty próbujesz się zalogować, zignoruj tę wiadomość.</p>';
    $html = buildSystemMailLayout(
        'Kod logowania',
        'Bezpieczne logowanie do RezerwIQ.',
        $message,
        'Nie przekazuj kodu innej osobie.'
    );

    return sendSystemMail($email, 'Kod logowania do RezerwIQ', $html);
}
