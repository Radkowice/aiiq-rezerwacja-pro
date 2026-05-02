<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/php_mail.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id']) || empty($_SESSION['user']['email'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$password = trim((string)($input['password'] ?? ''));

if ($password === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Podaj hasło, aby usunąć konto'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string) $_SESSION['user']['id'];
$tenantId = (string) $_SESSION['user']['tenant_id'];
$userEmail = (string) $_SESSION['user']['email'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$supabaseSchema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

function deleteDirectoryRecursive(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }

    $items = scandir($dir);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path) && !is_link($path)) {
            deleteDirectoryRecursive($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function deleteTenantFiles(string $tenantId): void
{
    $safeTenantId = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId);

    if ($safeTenantId === '') {
        return;
    }

    $baseDir = realpath(__DIR__ . '/../../html/data');

    if ($baseDir === false) {
        return;
    }

    $tenantDirs = [
        $baseDir . '/logo/' . $safeTenantId,
        $baseDir . '/favicon/' . $safeTenantId,
    ];

    foreach ($tenantDirs as $dir) {
        deleteDirectoryRecursive($dir);
    }
}

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userUrl = $supabaseUrl
    . '/rest/v1/users?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($userId)
    . '&select=id,email,password_hash,tenant_id'
    . '&limit=1';

$userCh = curl_init($userUrl);

curl_setopt_array($userCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept: application/json',
        'Accept-Profile: ' . $supabaseSchema,
        'Content-Profile: ' . $supabaseSchema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$userResponse = curl_exec($userCh);
$userHttpCode = (int) curl_getinfo($userCh, CURLINFO_HTTP_CODE);
$userCurlError = curl_error($userCh);

curl_close($userCh);

if ($userCurlError || $userHttpCode >= 400) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać danych użytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userData = json_decode((string) $userResponse, true);

if (!is_array($userData) || empty($userData[0]['password_hash'])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Nie znaleziono użytkownika'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRow = $userData[0];
$passwordHash = (string) ($userRow['password_hash'] ?? '');

if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowe hasło'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$countUsersUrl = $supabaseUrl
    . '/rest/v1/users?tenant_id=eq.' . rawurlencode($tenantId)
    . '&select=id';

$countUsersCh = curl_init($countUsersUrl);

curl_setopt_array($countUsersCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Accept: application/json',
        'Accept-Profile: ' . $supabaseSchema,
        'Content-Profile: ' . $supabaseSchema,
    ],
    CURLOPT_TIMEOUT        => 20,
]);

$countUsersResponse = curl_exec($countUsersCh);
$countUsersHttpCode = (int) curl_getinfo($countUsersCh, CURLINFO_HTTP_CODE);
$countUsersCurlError = curl_error($countUsersCh);

curl_close($countUsersCh);

if ($countUsersCurlError || $countUsersHttpCode >= 400) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się sprawdzić liczby użytkowników'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$countUsersData = json_decode((string) $countUsersResponse, true);
$userCount = is_array($countUsersData) ? count($countUsersData) : 0;
$isLastUser = $userCount <= 1;

if ($isLastUser) {
    $deleteTenantUrl = $supabaseUrl
        . '/rest/v1/tenant_branding?tenant_id=eq.' . rawurlencode($tenantId);

    $deleteTenantCh = curl_init($deleteTenantUrl);

    curl_setopt_array($deleteTenantCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept: application/json',
            'Prefer: return=minimal',
            'Accept-Profile: ' . $supabaseSchema,
            'Content-Profile: ' . $supabaseSchema,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $deleteTenantResponse = curl_exec($deleteTenantCh);
    $deleteTenantHttpCode = (int) curl_getinfo($deleteTenantCh, CURLINFO_HTTP_CODE);
    $deleteTenantCurlError = curl_error($deleteTenantCh);

    curl_close($deleteTenantCh);

      if ($deleteTenantCurlError || $deleteTenantHttpCode >= 400) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się usunąć wszystkich danych konta'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    deleteTenantFiles($tenantId);
} else {
    $deleteUserUrl = $supabaseUrl
        . '/rest/v1/users?tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($userId);

    $deleteUserCh = curl_init($deleteUserUrl);

    curl_setopt_array($deleteUserCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $serviceRoleKey,
            'Authorization: Bearer ' . $serviceRoleKey,
            'Accept: application/json',
            'Prefer: return=minimal',
            'Accept-Profile: ' . $supabaseSchema,
            'Content-Profile: ' . $supabaseSchema,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $deleteUserResponse = curl_exec($deleteUserCh);
    $deleteUserHttpCode = (int) curl_getinfo($deleteUserCh, CURLINFO_HTTP_CODE);
    $deleteUserCurlError = curl_error($deleteUserCh);

    curl_close($deleteUserCh);

    if ($deleteUserCurlError || $deleteUserHttpCode >= 400) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się usunąć konta'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function buildAccountDeletedHtml(string $email, bool $isLastUser): string
{
  $message = $isLastUser
    ? ''
        . '<p style="margin:0 0 14px;"><strong>Twoje konto oraz wszystkie dane zostały usunięte.</strong></p>'
        . '<p style="margin:0 0 10px;">Potwierdzamy usunięcie konta <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong> oraz wszystkich danych powiązanych z Twoją przestrzenią w systemie.</p>'
        . '<p style="margin:0 0 10px;">Szkoda, że odchodzisz. Jeśli czegoś zabrakło, coś nie działało tak jak trzeba albo możemy pomóc wrócić — napisz do nas na biuro@ai-iq.pl</p>'
        . '<p style="margin:0 0 10px;">Będzie nam też bardzo miło, jeśli zostawisz krótką opinię: co było okej, czego zabrakło i co warto poprawić.</p>'
        . '<p style="margin:14px 0 0;">Dziękujemy za korzystanie z naszej aplikacji.</p>'
    : ''
        . '<p style="margin:0 0 14px;"><strong>Twoje konto użytkownika zostało usunięte.</strong></p>'
        . '<p style="margin:0 0 10px;">Potwierdzamy usunięcie konta <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<p style="margin:0 0 10px;">Pozostałe dane organizacji zostały zachowane, ponieważ w tej przestrzeni istnieją jeszcze inni użytkownicy.</p>'
        . '<p style="margin:0 0 10px;">Jeśli czegoś zabrakło albo możemy pomóc wrócić — napisz do nas na biuro@ai-iq.pl</p>'
        . '<p style="margin:14px 0 0;">Dziękujemy za korzystanie z naszej aplikacji.</p>';

    $html = buildSystemMailLayout(
        'Potwierdzenie usunięcia konta',
        'To wiadomość systemowa potwierdzająca usunięcie konta.',
        $message,
        'Jeśli chcesz wrócić lub przekazać opinię, napisz na biuro@ai-iq.pl'
    );

    $footer = '<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">'
        . '© ' . date('Y') . ' '
        . '<a href="https://www.ai-iq.pl" style="color:#28406b;text-decoration:none;font-weight:700;">AI-IQ</a>'
        . ' | Inteligentne systemy · Powiadomienie systemowe'
        . '</div>';

    return preg_replace(
        '/<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">.*?<\/div>\s*<\/div>\s*$/s',
        $footer . '</div>',
        $html,
        1
    ) ?: $html;
}

sendSystemMail(
    $userEmail,
    'Potwierdzenie usunięcia konta',
    buildAccountDeletedHtml($userEmail, $isLastUser)
);

sendSystemMail(
    $userEmail,
    $isLastUser ? 'Potwierdzenie usunięcia konta i danych' : 'Potwierdzenie usunięcia konta',
    buildAccountDeletedHtml($userEmail, $isLastUser)
);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();

echo json_encode([
    'success' => true,
    'message' => $isLastUser
        ? 'Konto oraz wszystkie dane zostały usunięte'
        : 'Konto użytkownika zostało usunięte'
], JSON_UNESCAPED_UNICODE);