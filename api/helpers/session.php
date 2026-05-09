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
        $cookieName = session_name();
        $baseCookie = [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'secure' => (bool) ($params['secure'] ?? true),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ];

        // Aktualne host-only cookie sesji.
        setcookie($cookieName, '', $baseCookie);

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $host = strtolower(trim((string) $host));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = rtrim($host, '.');

        if ($host !== '') {
            // Stary wariant cookie ustawiony jawnie dla bieżącego hosta.
            setcookie($cookieName, '', $baseCookie + ['domain' => $host]);

            $parts = explode('.', $host);

            if (count($parts) >= 3) {
                $rootDomain = '.' . $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];

                // Stary wariant współdzielony na domenie nadrzędnej.
                setcookie($cookieName, '', $baseCookie + ['domain' => $rootDomain]);
            }
        }
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
