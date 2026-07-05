<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();

function payu_test_connection_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result,
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $userId = null,
    ?string $stage = null
): void {
    $details = [
        'reason' => $reason,
    ];

    if ($stage !== null && trim($stage) !== '') {
        $details['stage'] = trim($stage);
    }

    $context = [
        'action_key' => 'payu_test_connection',
        'endpoint' => '/api/payments/payu-test-connection.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    $tenantId = trim((string) $tenantId);
    if ($tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    $userId = trim((string) $userId);
    if ($userId !== '') {
        $context['user_id'] = $userId;
    }

    security_log_event($eventKey, $context);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    payu_test_connection_security_event(
        'payu_test_connection_method_not_allowed',
        'method_not_allowed',
        405,
        'failed',
        'low'
    );

    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');
$userId = (string) ($_SESSION['user']['id'] ?? '');

if ($tenantId === '' || $userId === '') {
    payu_test_connection_security_event(
        'payu_test_connection_unauthorized',
        'unauthorized',
        401,
        'denied',
        'medium'
    );

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak aktywnej sesji administratora.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    payu_test_connection_security_event(
        'payu_test_connection_env_missing',
        'env_missing',
        500,
        'error',
        'high',
        $tenantId,
        $userId,
        'supabase_config'
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    payu_test_connection_security_event(
        'payu_test_connection_tenant_denied',
        'tenant_denied',
        401,
        'denied',
        'high',
        $tenantId,
        $userId
    );

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payu = payu_get_integration($tenantId);

if (!$payu) {
    payu_test_connection_security_event(
        'payu_test_connection_integration_missing',
        'integration_missing',
        400,
        'failed',
        'medium',
        $tenantId,
        $userId
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Brak kompletnej lub włączonej konfiguracji PayU. Zapisz ustawienia PayU i upewnij się, że integracja jest włączona.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenResult = payu_get_access_token($payu);

if (empty($tokenResult['success'])) {
    payu_test_connection_security_event(
        'payu_test_connection_provider_failed',
        'provider_failed',
        400,
        'failed',
        'medium',
        $tenantId,
        $userId,
        'access_token'
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się połączyć z PayU.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

payu_test_connection_security_event(
    'payu_test_connection_success',
    'payu_test_connection_success',
    200,
    'success',
    'low',
    $tenantId,
    $userId
);

echo json_encode([
    'success' => true,
    'message' => 'Połączenie z PayU poprawne.',
    'mode' => $payu['mode'] ?? 'sandbox',
], JSON_UNESCAPED_UNICODE);
