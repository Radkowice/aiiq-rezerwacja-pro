<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/payu.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

if ($tenantId === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak aktywnej sesji administratora.',
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
        'error' => $tokenResult['error'] ?? 'Nie udało się połączyć z PayU.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Połączenie z PayU poprawne.',
    'mode' => $payu['mode'] ?? 'sandbox',
], JSON_UNESCAPED_UNICODE);