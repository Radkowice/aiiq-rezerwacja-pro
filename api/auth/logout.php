<?php
require_once __DIR__ . '/../helpers/session.php';

start_secure_session();

header('Content-Type: application/json');

clear_secure_session();

echo json_encode([
    'success' => true
]);