<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';

start_secure_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$staffSession = is_array($_SESSION['staff_user'] ?? null) ? $_SESSION['staff_user'] : [];

security_log_event('staff_logout_success', [
    'action_key' => 'staff_logout',
    'endpoint' => '/api/staff/logout.php',
    'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
    'actor_type' => 'staff',
    'tenant_id' => (string) ($staffSession['tenant_id'] ?? ''),
    'staff_account_id' => (string) ($staffSession['account_id'] ?? ''),
    'staff_id' => (string) ($staffSession['staff_id'] ?? ''),
    'response_status' => 200,
    'result' => 'success',
    'severity' => 'low',
    'details' => [
        'reason' => 'staff_logout_success',
    ],
]);

unset($_SESSION['staff_user']);

echo json_encode([
    'success' => true,
    'message' => 'Wylogowano pracownika.'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
