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

function clear_secure_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? true),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function session_tenant_matches_current_host(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema
): bool {
    $sessionTenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

    if ($sessionTenantId === '') {
        return false;
    }

    if (!function_exists('getTenantIdFromHost')) {
        return false;
    }

    $hostTenantId = getTenantIdFromHost($supabaseUrl, $supabaseKey, $schema);

    if (!$hostTenantId || !hash_equals($sessionTenantId, (string) $hostTenantId)) {
        clear_secure_session();
        return false;
    }

    return true;
}
