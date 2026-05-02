<?php
function start_secure_session() {
    if (session_status() === PHP_SESSION_NONE) {

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();
    }
}