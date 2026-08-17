<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/php_mail.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id']) || empty($_SESSION['user']['email'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function userConfirmEmailChangeCsrfToken(): string
{
    $token = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($token === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $token = trim((string) ($headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ''));
    }

    return $token;
}

function requireUserConfirmEmailChangeCsrf(): void
{
    $sessionToken = (string) ($_SESSION['csrf'] ?? '');
    $requestToken = userConfirmEmailChangeCsrfToken();

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'csrf_invalid'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function patchConfirmEmailChangeCode(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $codeHash,
    array $changes,
    array $additionalFilters = []
): array {
    if ($codeHash === '') {
        return ['ok' => false, 'affected' => null];
    }

    $url = $supabaseUrl
        . '/rest/v1/email_change_codes'
        . '?select=code_hash'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&user_id=eq.' . rawurlencode($userId)
        . '&code_hash=eq.' . rawurlencode($codeHash)
        . '&used_at=is.null';

    foreach ($additionalFilters as $filter) {
        $filter = trim((string) $filter);

        if ($filter !== '') {
            $url .= '&' . $filter;
        }
    }

    $payload = json_encode($changes, JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return ['ok' => false, 'affected' => null];
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return ['ok' => false, 'affected' => null];
    }

    $configured = curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
            'Prefer: return=representation',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    if (!$configured) {
        curl_close($ch);

        return ['ok' => false, 'affected' => null];
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'affected' => null];
    }

    $rows = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($rows)) {
        return ['ok' => false, 'affected' => null];
    }

    foreach ($rows as $row) {
        if (
            !is_array($row)
            || !isset($row['code_hash'])
            || !is_string($row['code_hash'])
            || !hash_equals($codeHash, $row['code_hash'])
        ) {
            return ['ok' => false, 'affected' => null];
        }
    }

    return ['ok' => true, 'affected' => count($rows)];
}

function markConfirmEmailChangeCodesUsed(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $codeHash
): array {
    return patchConfirmEmailChangeCode(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        $codeHash,
        ['used_at' => gmdate('Y-m-d\TH:i:s\Z')]
    );
}

function incrementConfirmEmailChangeAttempts(
    string $supabaseUrl,
    string $serviceRoleKey,
    string $schema,
    string $tenantId,
    string $userId,
    string $codeHash,
    int $attempts
): array {
    return patchConfirmEmailChangeCode(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        $codeHash,
        ['attempts' => $attempts + 1],
        ['attempts=eq.' . rawurlencode((string) $attempts)]
    );
}

function confirmEmailChangeLogSecurityEvent(
    string $eventKey,
    ?string $securityIp,
    string $securityEndpoint,
    string $securityMethod,
    int $responseStatus,
    string $result,
    string $reason
): void {
    security_log_event($eventKey, [
        'action_key' => 'email_change_confirm',
        'ip_address' => $securityIp,
        'endpoint' => $securityEndpoint,
        'http_method' => $securityMethod,
        'actor_type' => 'tenant_user',
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => [
            'reason' => $reason,
        ],
    ]);
}

requireUserConfirmEmailChangeCsrf();

$userId = (string) $_SESSION['user']['id'];
$tenantId = (string) $_SESSION['user']['tenant_id'];
$sessionEmail = (string) $_SESSION['user']['email'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$securityIp = security_client_ip();
$securityEndpoint = '/api/user/confirm-email-change.php';
$securityMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$clientIp = $securityIp ?: 'unknown';

if (!session_tenant_matches_current_host($supabaseUrl, $serviceRoleKey, $schema)) {
    confirmEmailChangeLogSecurityEvent(
        'email_change_confirm_session_denied',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        401,
        'denied',
        'tenant_mismatch'
    );

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = trim((string) ($input['code'] ?? ''));

if (!preg_match('/^\d{6}$/', $code)) {
    confirmEmailChangeLogSecurityEvent(
        'email_change_confirm_invalid_code_format',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        400,
        'failed',
        'invalid_code_format'
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowy kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$codeUrl = $supabaseUrl
    . '/rest/v1/email_change_codes'
    . '?select=tenant_id,user_id,current_email,new_email,code_hash,expires_at,used_at,attempts,created_at'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&user_id=eq.' . rawurlencode($userId)
    . '&used_at=is.null'
    . '&order=created_at.desc'
    . '&limit=1';

$codeCh = curl_init($codeUrl);
curl_setopt_array($codeCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$codeResponse = curl_exec($codeCh);

if ($codeResponse === false) {
    curl_close($codeCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się odczytać kodu potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$codeHttpCode = (int) curl_getinfo($codeCh, CURLINFO_HTTP_CODE);
curl_close($codeCh);

if ($codeHttpCode < 200 || $codeHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać kodu potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = json_decode($codeResponse, true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

if (!$row) {
    confirmEmailChangeLogSecurityEvent(
        'email_change_confirm_no_active_code',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        404,
        'failed',
        'no_active_code'
    );

    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Brak aktywnego kodu potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$codeHash = (string) ($row['code_hash'] ?? '');
$oldEmail = (string) ($row['current_email'] ?? $sessionEmail);
$newEmail = trim((string) ($row['new_email'] ?? ''));
$attempts = (int) ($row['attempts'] ?? 0);
$expiresAt = (string) ($row['expires_at'] ?? '');

if ($oldEmail !== '' && mb_strtolower($oldEmail, 'UTF-8') !== mb_strtolower($sessionEmail, 'UTF-8')) {
    if ($codeHash !== '') {
        $markStaleResult = markConfirmEmailChangeCodesUsed(
            $supabaseUrl,
            $serviceRoleKey,
            $schema,
            $tenantId,
            $userId,
            $codeHash
        );

        if (($markStaleResult['ok'] ?? false) !== true || ($markStaleResult['affected'] ?? null) !== 1) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Nie udało się potwierdzić zmiany e-maila. Spróbuj ponownie.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    confirmEmailChangeLogSecurityEvent(
        'email_change_confirm_stale_code',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        409,
        'failed',
        'stale_code'
    );

    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'Ten kod nie jest już aktualny. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    if ($codeHash !== '') {
        $markInvalidResult = markConfirmEmailChangeCodesUsed(
            $supabaseUrl,
            $serviceRoleKey,
            $schema,
            $tenantId,
            $userId,
            $codeHash
        );

        if (($markInvalidResult['ok'] ?? false) !== true || ($markInvalidResult['affected'] ?? null) !== 1) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Nie udało się potwierdzić zmiany e-maila. Spróbuj ponownie.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowy kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($expiresAt === '' || strtotime($expiresAt) < time()) {
    if ($codeHash !== '') {
        $markExpiredResult = markConfirmEmailChangeCodesUsed(
            $supabaseUrl,
            $serviceRoleKey,
            $schema,
            $tenantId,
            $userId,
            $codeHash
        );

        if (($markExpiredResult['ok'] ?? false) !== true || ($markExpiredResult['affected'] ?? null) !== 1) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Nie udało się potwierdzić zmiany e-maila. Spróbuj ponownie.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    http_response_code(410);
    echo json_encode([
        'success' => false,
        'error' => 'Kod wygasł. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($attempts >= 5) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Przekroczono limit prób wpisania kodu. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($codeHash === '' || $newEmail === '' || !password_verify($code, $codeHash)) {
    $attemptUpdateResult = incrementConfirmEmailChangeAttempts(
        $supabaseUrl,
        $serviceRoleKey,
        $schema,
        $tenantId,
        $userId,
        $codeHash,
        $attempts
    );

    if (
        ($attemptUpdateResult['ok'] ?? false) !== true
        || ($attemptUpdateResult['affected'] ?? null) !== 1
    ) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się potwierdzić zmiany e-maila. Spróbuj ponownie.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    confirmEmailChangeLogSecurityEvent(
        'email_change_confirm_invalid_code',
        $securityIp,
        $securityEndpoint,
        $securityMethod,
        422,
        'failed',
        'invalid_code'
    );

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowy kod potwierdzenia'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conflictUrl = $supabaseUrl
    . '/rest/v1/users'
    . '?select=id'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&email=ilike.' . rawurlencode($newEmail)
    . '&limit=1';

$conflictCh = curl_init($conflictUrl);
curl_setopt_array($conflictCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$conflictResponse = curl_exec($conflictCh);
$conflictHttpCode = (int) curl_getinfo($conflictCh, CURLINFO_HTTP_CODE);
curl_close($conflictCh);

if ($conflictResponse === false || $conflictHttpCode < 200 || $conflictHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się potwierdzić zmiany e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conflictRows = json_decode($conflictResponse, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($conflictRows)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się potwierdzić zmiany e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($conflictRows)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'Ten adres e-mail jest niedostępny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$consumeCodeResult = markConfirmEmailChangeCodesUsed(
    $supabaseUrl,
    $serviceRoleKey,
    $schema,
    $tenantId,
    $userId,
    $codeHash
);

if (($consumeCodeResult['ok'] ?? false) !== true) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się potwierdzić zmiany e-maila. Spróbuj ponownie.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($consumeCodeResult['affected'] ?? null) !== 1) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się potwierdzić zmiany e-maila. Wygeneruj nowy kod.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userPatchUrl = $supabaseUrl
    . '/rest/v1/users'
    . '?select=id'
    . '&id=eq.' . rawurlencode($userId)
    . '&tenant_id=eq.' . rawurlencode($tenantId);

$userPatchPayload = json_encode([
    'email' => $newEmail
], JSON_UNESCAPED_UNICODE);

if ($userPatchPayload === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się przygotować zmiany e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userPatchCh = curl_init($userPatchUrl);
curl_setopt_array($userPatchCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => $userPatchPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
        'Prefer: return=representation',
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$userPatchResponse = curl_exec($userPatchCh);
$userPatchCurlError = curl_error($userPatchCh);

if ($userPatchResponse === false || $userPatchCurlError !== '') {
    curl_close($userPatchCh);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zmienić e-maila. Spróbuj ponownie.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userPatchHttpCode = (int) curl_getinfo($userPatchCh, CURLINFO_HTTP_CODE);
curl_close($userPatchCh);

if ($userPatchHttpCode < 200 || $userPatchHttpCode >= 300) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać nowego e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userPatchRows = json_decode((string) $userPatchResponse, true);
$userPatchRow = is_array($userPatchRows) && count($userPatchRows) === 1 && is_array($userPatchRows[0])
    ? $userPatchRows[0]
    : null;

if (
    json_last_error() !== JSON_ERROR_NONE
    || $userPatchRow === null
    || !isset($userPatchRow['id'])
    || !is_string($userPatchRow['id'])
    || !hash_equals($userId, $userPatchRow['id'])
) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać nowego e-maila'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION['user']['email'] = $newEmail;

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$logPayload = json_encode([
    'tenant_id'  => $tenantId,
    'user_id'    => $userId,
    'old_email'  => $oldEmail,
    'new_email'  => $newEmail,
    'ip_address' => $clientIp,
    'user_agent' => $userAgent,
], JSON_UNESCAPED_UNICODE);

if ($logPayload !== false) {
    $logUrl = $supabaseUrl . '/rest/v1/email_change_logs';

    $logCh = curl_init($logUrl);
    curl_setopt_array($logCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $logPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept-Profile: ' . $schema,
            'Content-Profile: ' . $schema,
            'Prefer: return=minimal',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    curl_exec($logCh);
    curl_close($logCh);
}

confirmEmailChangeLogSecurityEvent(
    'email_change_confirm_success',
    $securityIp,
    $securityEndpoint,
    $securityMethod,
    200,
    'success',
    'email_change_confirm_success'
);

try {
    sendSystemMail($oldEmail, 'Zmiana adresu e-mail', buildEmailChangeOldAddressHtml($oldEmail, $newEmail));
    sendSystemMail($newEmail, 'Potwierdzenie zmiany e-maila', buildEmailChangeNewAddressHtml($oldEmail, $newEmail));
} catch (Throwable $e) {
}

echo json_encode([
    'success' => true,
    'message' => 'E-mail został zmieniony',
    'email' => $newEmail
], JSON_UNESCAPED_UNICODE);
exit;
