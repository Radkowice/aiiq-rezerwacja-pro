<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/system_subscription_mail.php';
require_once __DIR__ . '/../helpers/activation_link.php';

start_secure_session();

$SUPABASE_URL = rtrim((string) getenv('SUPABASE_URL'), '/');
$SUPABASE_KEY = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$SUPABASE_DB_SCHEMA = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

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

function activation_reissue_request(string $method, string $path, ?array $payload = null): array
{
    global $SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA;

    $ch = curl_init($SUPABASE_URL . $path);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => supabaseHeaders($SUPABASE_KEY, $SUPABASE_DB_SCHEMA),
        CURLOPT_TIMEOUT => 20,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'data' => is_array($decoded) ? $decoded : [],
    ];
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

    if ($userAgent === '' || preg_match('/[\x00-\x1F\x7F]/', $userAgent)) {
        return null;
    }

    return $userAgent;
}

function activation_reissue_ip_address(): ?string
{
    $ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
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

function activation_reissue_is_uuid(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        trim($value)
    ) === 1;
}

function activation_reissue_active_domain(string $tenantId): string
{
    $result = activation_reissue_request(
        'GET',
        '/rest/v1/tenant_domains?select=domain'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&order=is_primary.desc'
        . '&limit=1'
    );

    if (!$result['ok']) {
        return '';
    }

    $domain = strtolower(trim((string) ($result['data'][0]['domain'] ?? '')));

    if (
        $domain === ''
        || strlen($domain) > 253
        || preg_match('/[\x00-\x20\x7f\/\\\\:?#]/', $domain)
        || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) !== 1
    ) {
        return '';
    }

    return $domain;
}

function activation_reissue_company_name(string $tenantId): string
{
    $settingsResult = activation_reissue_request(
        'GET',
        '/rest/v1/tenant_service_settings?select=company_full_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1'
    );

    $companyName = $settingsResult['ok']
        ? trim((string) ($settingsResult['data'][0]['company_full_name'] ?? ''))
        : '';

    if ($companyName !== '') {
        return $companyName;
    }

    $brandingResult = activation_reissue_request(
        'GET',
        '/rest/v1/tenant_branding?select=client_name'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1'
    );

    return $brandingResult['ok']
        ? trim((string) ($brandingResult['data'][0]['client_name'] ?? ''))
        : '';
}

function activation_reissue_plan_label(string $tenantId): string
{
    $result = activation_reissue_request(
        'GET',
        '/rest/v1/tenant_subscriptions?select=plan_code'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1'
    );

    $planCode = strtolower(trim((string) ($result['data'][0]['plan_code'] ?? 'free')));

    return $result['ok'] && $planCode === 'pro' ? 'Pro' : 'Free';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        activation_reissue_json([
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ], 405);
    }

    if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
        activation_reissue_json([
            'success' => false,
            'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.',
        ], 500);
    }

    $input = json_decode((string) file_get_contents('php://input'), true);

    if (!is_array($input)) {
        activation_reissue_json([
            'success' => false,
            'error' => 'Podaj poprawny adres e-mail.',
        ], 400);
    }

    $email = activation_reissue_normalize_email((string) ($input['email'] ?? ''));

    if ($email === '') {
        activation_reissue_json([
            'success' => false,
            'error' => 'Podaj poprawny adres e-mail.',
        ], 400);
    }

    activation_reissue_rate_limit($email);

    $userResult = activation_reissue_request(
        'GET',
        '/rest/v1/users?select=id,email,tenant_id,is_active'
        . '&email=eq.' . rawurlencode($email)
        . '&limit=2'
    );

    if (!$userResult['ok'] || count($userResult['data']) !== 1 || !is_array($userResult['data'][0] ?? null)) {
        activation_reissue_neutral_success();
    }

    $user = $userResult['data'][0];
    $userId = trim((string) ($user['id'] ?? ''));
    $tenantId = trim((string) ($user['tenant_id'] ?? ''));
    $isActive = filter_var($user['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($isActive || !activation_reissue_is_uuid($userId) || !activation_reissue_is_uuid($tenantId)) {
        activation_reissue_neutral_success();
    }

    $activationToken = bin2hex(random_bytes(32));
    $activationTokenHash = hash('sha256', $activationToken);
    $now = gmdate('c');
    $activationExpiresAt = gmdate('c', time() + (48 * 60 * 60));
    $activationRef = activation_link_build_ref($activationToken, $tenantId, $userId);

    if ($activationRef === '') {
        activation_reissue_json([
            'success' => false,
            'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.',
        ], 500);
    }

    $insertResult = activation_reissue_request(
        'POST',
        '/rest/v1/user_activation_tokens',
        [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => $email,
            'token_hash' => $activationTokenHash,
            'expires_at' => $activationExpiresAt,
            'used_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'ip_address' => activation_reissue_ip_address(),
            'user_agent' => activation_reissue_user_agent(),
        ]
    );

    if (!$insertResult['ok']) {
        activation_reissue_json([
            'success' => false,
            'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.',
        ], 500);
    }

    $domain = activation_reissue_active_domain($tenantId);
    $activationUrl = 'https://rezerwacja-ai-iq.pl/api/auth/activate.php?token=' . rawurlencode($activationToken)
        . '&ref=' . rawurlencode($activationRef);
    $mailHtml = buildRegistrationConfirmationMailHtml([
        'company_name' => activation_reissue_company_name($tenantId),
        'plan' => activation_reissue_plan_label($tenantId),
        'panel_domain' => $domain,
        'activation_url' => $activationUrl,
        'activation_expires_label' => 'przez 48 godzin',
    ]);

    unset($activationToken, $activationRef, $activationUrl);

    if (!sendSystemMail($email, 'Nowy link aktywacyjny w AI-IQ Rezerwacja Pro', $mailHtml)) {
        activation_reissue_request(
            'PATCH',
            '/rest/v1/user_activation_tokens'
            . '?tenant_id=eq.' . rawurlencode($tenantId)
            . '&user_id=eq.' . rawurlencode($userId)
            . '&token_hash=eq.' . rawurlencode($activationTokenHash)
            . '&used_at=is.null'
            . '&revoked_at=is.null',
            ['revoked_at' => gmdate('c')]
        );

        activation_reissue_json([
            'success' => false,
            'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.',
        ], 500);
    }

    activation_reissue_request(
        'PATCH',
        '/rest/v1/user_activation_tokens'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&user_id=eq.' . rawurlencode($userId)
        . '&token_hash=neq.' . rawurlencode($activationTokenHash)
        . '&used_at=is.null'
        . '&revoked_at=is.null',
        ['revoked_at' => gmdate('c')]
    );

    activation_reissue_neutral_success();
} catch (Throwable $e) {
    activation_reissue_json([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.',
    ], 500);
}
