<?php
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';

start_secure_session();

header('Content-Type: application/json');

$logoutUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];

security_log_event('user_logout_success', [
    'action_key' => 'user_logout',
    'endpoint' => '/api/auth/logout.php',
    'actor_type' => 'tenant_user',
    'tenant_id' => (string) ($logoutUser['tenant_id'] ?? ''),
    'user_id' => (string) ($logoutUser['id'] ?? ''),
    'severity' => 'low',
    'response_status' => 200,
    'result' => 'success',
    'details' => [
        'reason' => 'user_logout_success',
    ],
]);

clear_secure_session();

echo json_encode([
    'success' => true,
], JSON_UNESCAPED_UNICODE);
