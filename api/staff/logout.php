<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';

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

unset($_SESSION['staff_user']);

echo json_encode([
    'success' => true,
    'message' => 'Wylogowano pracownika.'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);