<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';

$SUPABASE_URL = rtrim((string) getenv('SUPABASE_URL'), '/');
$SUPABASE_KEY = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$SCHEMA = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');
$CENTRAL_LOGIN_URL = 'https://rezerwacja-ai-iq.pl/logowanie.html';

function activation_redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

function activation_redirect_error(string $reason = 'invalid'): void
{
    global $CENTRAL_LOGIN_URL;
    activation_redirect($CENTRAL_LOGIN_URL . '?activation=' . rawurlencode($reason));
}

function activation_request(string $method, string $path, ?array $payload = null): array
{
    global $SUPABASE_URL, $SUPABASE_KEY, $SCHEMA;

    $ch = curl_init($SUPABASE_URL . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => supabaseHeaders($SUPABASE_KEY, $SCHEMA),
        CURLOPT_TIMEOUT => 20,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function activation_is_valid_domain(string $domain): bool
{
    $domain = strtolower(trim($domain));

    if ($domain === '' || strlen($domain) > 253 || preg_match('/[\x00-\x20\x7f\/\\\\:?#]/', $domain)) {
        return false;
    }

    return preg_match(
        '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
        $domain
    ) === 1;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || $SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    activation_redirect_error();
}

$token = trim((string) ($_GET['token'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/i', $token) !== 1) {
    activation_redirect_error();
}

$tokenHash = hash('sha256', $token);
$now = gmdate('c');
$tokenResult = activation_request(
    'GET',
    '/rest/v1/user_activation_tokens?select=id,tenant_id,user_id'
    . '&token_hash=eq.' . rawurlencode($tokenHash)
    . '&used_at=is.null&revoked_at=is.null'
    . '&expires_at=gt.' . rawurlencode($now)
    . '&limit=1'
);
unset($token, $tokenHash);

if (!$tokenResult['ok'] || empty($tokenResult['data'][0])) {
    activation_redirect_error();
}

$activationState = $tokenResult['data'][0];
$stateId = trim((string) ($activationState['id'] ?? ''));
$tenantId = trim((string) ($activationState['tenant_id'] ?? ''));
$userId = trim((string) ($activationState['user_id'] ?? ''));

if (
    $stateId === ''
    || $tenantId === ''
    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId) !== 1
) {
    activation_redirect_error();
}

$userResult = activation_request(
    'GET',
    '/rest/v1/users?select=id,tenant_id&id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId) . '&limit=1'
);
if (!$userResult['ok'] || empty($userResult['data'][0])) {
    activation_redirect_error();
}

$activateUserResult = activation_request(
    'PATCH',
    '/rest/v1/users?id=eq.' . rawurlencode($userId) . '&tenant_id=eq.' . rawurlencode($tenantId),
    ['is_active' => true]
);
if (!$activateUserResult['ok']) {
    activation_redirect_error();
}

$markUsedResult = activation_request(
    'PATCH',
    '/rest/v1/user_activation_tokens?id=eq.' . rawurlencode($stateId)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId)
    . '&used_at=is.null&revoked_at=is.null',
    ['used_at' => $now]
);
if (!$markUsedResult['ok'] || empty($markUsedResult['data'])) {
    activation_redirect_error();
}

$revokeOtherTokensResult = activation_request(
    'PATCH',
    '/rest/v1/user_activation_tokens?tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId)
    . '&used_at=is.null&revoked_at=is.null&id=neq.' . rawurlencode($stateId),
    ['revoked_at' => $now]
);
if (!$revokeOtherTokensResult['ok']) {
    activation_redirect_error();
}

$domainResult = activation_request(
    'GET',
    '/rest/v1/tenant_domains?select=domain&tenant_id=eq.' . rawurlencode($tenantId)
    . '&is_active=eq.true&order=is_primary.desc&limit=1'
);
$domain = strtolower(trim((string) ($domainResult['data'][0]['domain'] ?? '')));

if (!$domainResult['ok'] || !activation_is_valid_domain($domain)) {
    activation_redirect_error('domain_unavailable');
}

activation_redirect('https://' . $domain . '/logowanie.html?activated=1');
