<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payu = payu_get_integration($tenantId);

if (!$payu) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Brak kompletnej lub włączonej konfiguracji PayU. Zapisz ustawienia PayU i upewnij się, że integracja jest włączona.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenResult = payu_get_access_token($payu);

if (empty($tokenResult['success'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się połączyć z PayU.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Połączenie z PayU poprawne.',
    'mode' => $payu['mode'] ?? 'sandbox',
], JSON_UNESCAPED_UNICODE);
