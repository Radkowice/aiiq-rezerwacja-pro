<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/tenant.php';

start_secure_session();

function seo_google_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(
        public_response_sanitize($payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function seo_google_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result,
    string $severity,
    string $tenantId = '',
    string $userId = '',
    string $stage = ''
): void {
    $details = ['reason' => $reason];

    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'system_seo_google',
        'endpoint' => '/api/system/seo-google.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'actor_type' => 'tenant_user',
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

function seo_google_supabase_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null
): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_CONNECTTIMEOUT => 5,
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
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode((string) $response, true);
    $jsonValid = json_last_error() === JSON_ERROR_NONE;

    return [
        'ok' => $response !== false
            && $error === ''
            && $httpCode >= 200
            && $httpCode < 300
            && $jsonValid
            && is_array($data),
        'httpCode' => $httpCode,
        'error' => $error,
        'data' => $data,
    ];
}

function seo_google_decode_rpc_result(mixed $data): ?array
{
    if (!is_array($data)) {
        return null;
    }

    if (array_key_exists('success', $data)) {
        return $data;
    }

    if (isset($data[0]) && is_array($data[0])) {
        $decoded = seo_google_decode_rpc_result($data[0]);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    foreach ($data as $value) {
        if (!is_array($value)) {
            continue;
        }

        $decoded = seo_google_decode_rpc_result($value);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function seo_google_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === 1 || $value === '1' || $value === 'true') {
        return true;
    }

    if ($value === 0 || $value === '0' || $value === 'false') {
        return false;
    }

    return $default;
}

function seo_google_text(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function seo_google_response_payload(array $record): array
{
    $indexingEnabled = seo_google_bool($record['indexing_enabled'] ?? true, true);
    $seoTitle = seo_google_text($record['seo_title'] ?? '');
    $seoDescription = seo_google_text($record['seo_description'] ?? '');
    $effectiveTitle = seo_google_text($record['effective_title'] ?? '');
    $effectiveDescription = seo_google_text($record['effective_description'] ?? '');
    $domain = seo_google_text($record['domain'] ?? '');

    return [
        'settings' => [
            'configured' => seo_google_bool($record['configured'] ?? false),
            'indexing_enabled' => $indexingEnabled,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'effective_title' => $effectiveTitle,
            'effective_description' => $effectiveDescription,
            'domain' => $domain,
        ],
    ];
}

function seo_google_call_rpc(
    string $functionName,
    array $payload,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema
): array {
    return seo_google_supabase_request(
        'POST',
        $supabaseUrl . '/rest/v1/rpc/' . rawurlencode($functionName),
        $supabaseKey,
        $schema,
        $payload
    );
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
$sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
$tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
$userId = trim((string) ($sessionUser['id'] ?? ''));

try {
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        seo_google_security_event(
            'seo_google_method_not_allowed',
            'method_not_allowed',
            405,
            'denied',
            'low',
            $tenantId,
            $userId
        );
        seo_google_json([
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ], 405);
    }

    if ($tenantId === '' || $userId === '') {
        seo_google_security_event(
            'seo_google_unauthorized',
            'unauthorized',
            401,
            'denied',
            'medium'
        );
        seo_google_json([
            'success' => false,
            'error' => 'Brak autoryzacji.',
        ], 401);
    }

    $sessionRole = strtolower(trim((string) ($sessionUser['role'] ?? '')));

    if (!in_array($sessionRole, ['admin', 'administrator'], true)) {
        seo_google_security_event(
            'seo_google_forbidden',
            'forbidden',
            403,
            'denied',
            'medium',
            $tenantId,
            $userId
        );
        seo_google_json([
            'success' => false,
            'error' => 'Brak uprawnień administratora.',
        ], 403);
    }

    if ($method === 'POST') {
        require_csrf_token();
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = trim((string) getenv('SUPABASE_SERVICE_ROLE_KEY'));
    $schema = security_allowed_schema();

    if ($supabaseUrl === '' || $supabaseKey === '') {
        seo_google_security_event(
            'seo_google_configuration_error',
            'configuration_error',
            500,
            'error',
            'high',
            $tenantId,
            $userId
        );
        seo_google_json([
            'success' => false,
            'error' => 'Nie udało się wczytać konfiguracji systemu.',
        ], 500);
    }

    if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
        seo_google_security_event(
            'seo_google_tenant_denied',
            'tenant_mismatch',
            401,
            'denied',
            'high',
            $tenantId,
            $userId
        );
        seo_google_json([
            'success' => false,
            'error' => 'Sesja nie pasuje do domeny.',
        ], 401);
    }

    $userResult = seo_google_supabase_request(
        'GET',
        $supabaseUrl
            . '/rest/v1/users?select=role,is_active'
            . '&id=eq.' . rawurlencode($userId)
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&limit=1',
        $supabaseKey,
        $schema
    );

    if (!$userResult['ok']) {
        seo_google_security_event(
            'seo_google_admin_verification_failed',
            'admin_verification_failed',
            503,
            'error',
            'high',
            $tenantId,
            $userId,
            'admin_verification'
        );
        seo_google_json([
            'success' => false,
            'error' => 'Nie udało się potwierdzić uprawnień administratora.',
        ], 503);
    }

    $databaseUser = is_array($userResult['data'][0] ?? null)
        ? $userResult['data'][0]
        : null;
    $databaseRole = is_array($databaseUser)
        ? strtolower(trim((string) ($databaseUser['role'] ?? '')))
        : '';
    $databaseUserActive = is_array($databaseUser)
        && seo_google_bool($databaseUser['is_active'] ?? false);

    if (!$databaseUserActive) {
        seo_google_security_event(
            'seo_google_admin_inactive',
            'admin_inactive',
            401,
            'denied',
            'medium',
            $tenantId,
            $userId,
            'admin_verification'
        );
        seo_google_json([
            'success' => false,
            'error' => 'Konto administratora jest nieaktywne albo nie istnieje.',
        ], 401);
    }

    if (!in_array($databaseRole, ['admin', 'administrator'], true)) {
        seo_google_security_event(
            'seo_google_admin_forbidden',
            'admin_forbidden',
            403,
            'denied',
            'medium',
            $tenantId,
            $userId,
            'admin_verification'
        );
        seo_google_json([
            'success' => false,
            'error' => 'Brak uprawnień administratora.',
        ], 403);
    }

    $rpcFunction = 'get_tenant_seo_settings';
    $rpcPayload = [
        'p_tenant_id' => $tenantId,
    ];

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
            seo_google_json([
                'success' => false,
                'error' => 'Nieprawidłowe dane wejściowe.',
            ], 400);
        }

        $expectedFields = ['indexing_enabled', 'seo_description', 'seo_title'];
        $inputFields = array_keys($input);
        sort($expectedFields);
        sort($inputFields);

        if ($inputFields !== $expectedFields) {
            seo_google_json([
                'success' => false,
                'error' => 'Dozwolone są wyłącznie ustawienia widoczności, tytułu i opisu.',
            ], 400);
        }

        if (!is_bool($input['indexing_enabled'])) {
            seo_google_json([
                'success' => false,
                'error' => 'Nieprawidłowa wartość ustawienia widoczności.',
            ], 400);
        }

        if (!is_string($input['seo_title']) || !is_string($input['seo_description'])) {
            seo_google_json([
                'success' => false,
                'error' => 'Tytuł i opis muszą być tekstem.',
            ], 400);
        }

        $seoTitle = trim($input['seo_title']);
        $seoDescription = trim(str_replace(["\r\n", "\r"], "\n", $input['seo_description']));

        if (mb_strlen($seoTitle, 'UTF-8') > 60) {
            seo_google_json([
                'success' => false,
                'error' => 'Tytuł nie może przekraczać 60 znaków.',
            ], 400);
        }

        if (mb_strlen($seoDescription, 'UTF-8') > 160) {
            seo_google_json([
                'success' => false,
                'error' => 'Opis nie może przekraczać 160 znaków.',
            ], 400);
        }

        $rpcFunction = 'save_tenant_seo_settings';
        $rpcPayload = [
            'p_tenant_id' => $tenantId,
            'p_indexing_enabled' => $input['indexing_enabled'],
            'p_seo_title' => $seoTitle,
            'p_seo_description' => $seoDescription,
        ];
    }

    $rpcResult = seo_google_call_rpc(
        $rpcFunction,
        $rpcPayload,
        $supabaseUrl,
        $supabaseKey,
        $schema
    );

    if (!$rpcResult['ok']) {
        seo_google_security_event(
            'seo_google_rpc_failed',
            'rpc_failed',
            502,
            'error',
            'high',
            $tenantId,
            $userId,
            $rpcFunction
        );
        seo_google_json([
            'success' => false,
            'error' => $method === 'POST'
                ? 'Nie udało się zapisać ustawień widoczności.'
                : 'Nie udało się pobrać ustawień widoczności.',
        ], 502);
    }

    $record = seo_google_decode_rpc_result($rpcResult['data']);

    if (!is_array($record) || seo_google_bool($record['success'] ?? false) !== true) {
        seo_google_security_event(
            'seo_google_rpc_response_invalid',
            'invalid_rpc_response',
            502,
            'error',
            'high',
            $tenantId,
            $userId,
            $rpcFunction
        );
        seo_google_json([
            'success' => false,
            'error' => $method === 'POST'
                ? 'Nie udało się zapisać ustawień widoczności.'
                : 'Nie udało się pobrać ustawień widoczności.',
        ], 502);
    }

    $responsePayload = seo_google_response_payload($record);
    $response = ['success' => true];

    if ($method === 'POST') {
        $response['message'] = 'Ustawienia widoczności zostały zapisane.';
    }

    seo_google_json(array_merge($response, $responsePayload));
} catch (Throwable $error) {
    seo_google_security_event(
        'seo_google_unexpected_error',
        'unexpected_error',
        500,
        'error',
        'high',
        $tenantId,
        $userId
    );
    seo_google_json([
        'success' => false,
        'error' => 'Nie udało się obsłużyć żądania.',
    ], 500);
}
