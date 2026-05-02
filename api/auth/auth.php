<?php
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["error" => "Brak autoryzacji"]);
    exit;
}

// CSRF (opcjonalnie)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

echo json_encode([
    "success" => true,
    "user" => $_SESSION['user']
]);

