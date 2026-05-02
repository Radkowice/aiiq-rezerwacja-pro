<?php
session_start();

header('Content-Type: application/json');

// Jeœli nie ma sesji — te¿ traktujemy jako sukces
if (session_status() !== PHP_SESSION_ACTIVE) {
    echo json_encode(["success" => true]);
    exit;
}

// wyczyszczenie danych sesji
$_SESSION = [];

// usuniêcie ciasteczka sesji (jeœli istnieje)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// zniszczenie sesji
session_destroy();

echo json_encode([
    "success" => true
]);