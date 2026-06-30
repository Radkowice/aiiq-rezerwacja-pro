<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

$SUPABASE_URL = rtrim((string) getenv('SUPABASE_URL'), '/');
$SUPABASE_KEY = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$SCHEMA       = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Brak konfiguracji Supabase'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

// 🔑 TENANT Z DOMENY (kluczowe!)
$tenantId = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA);

if (!$tenantId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak tenant']);
    exit;
}

// 📥 dane z formularza
$input = json_decode(file_get_contents('php://input'), true);

$email    = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Podaj email i hasło']);
    exit;
}

// 🔎 zapytanie do users z tenant_id
$url = $SUPABASE_URL
    . "/rest/v1/users?select=id,email,password_hash,tenant_id,role,is_active"
    . "&email=eq." . rawurlencode($email)
    . "&tenant_id=eq." . rawurlencode($tenantId)
    . "&limit=1";

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => supabaseHeaders($SUPABASE_KEY, $SCHEMA)
]);

$response = curl_exec($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd połączenia']);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => 'Błąd zapytania']);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Błędny login lub hasło']);
    exit;
}

$user = $data[0];

// 🔐 weryfikacja hasła
if (!password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Błędny login lub hasło']);
    exit;
}

// ✅ zapis do sesji
if (!filter_var($user['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Konto nie zostało jeszcze aktywowane. Sprawdź e-mail i kliknij link aktywacyjny.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION['user'] = [
    'id'        => $user['id'],
    'email'     => $user['email'],
    'tenant_id' => $user['tenant_id'],
    'role'      => $user['role'] ?? 'admin'
];

echo json_encode([
    'success' => true,
    'user'    => [
        'email' => (string) ($user['email'] ?? ''),
        'role'  => (string) ($user['role'] ?? 'admin'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
