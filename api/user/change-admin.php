<?php

require_once __DIR__ . '/../helpers/session.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
    json_response([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$currentUser = $_SESSION['user'];

$tenantId = (string)($currentUser['tenant_id'] ?? '');
$currentUserId = (string)($currentUser['id'] ?? '');
$currentRole = (string)($currentUser['role'] ?? '');

if ($tenantId === '' || $currentUserId === '') {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

if ($currentRole !== '' && $currentRole !== 'admin') {
    json_response([
        'success' => false,
        'error' => 'Brak uprawnień'
    ], 403);
}

$supabaseUrl = rtrim((string)getenv('SUPABASE_URL'), '/');
$supabaseKey = (string)getenv('SUPABASE_SERVICE_ROLE_KEY');
$supabaseSchema = (string)(getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    json_response([
        'success' => false,
        'error' => 'Brak konfiguracji serwera'
    ], 500);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '{}', true);

if (!is_array($data)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy format danych'
    ], 400);
}

$newEmail = trim((string)($data['email'] ?? ''));

if ($newEmail === '') {
    json_response([
        'success' => false,
        'error' => 'Podaj email nowego administratora'
    ], 400);
}

if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy adres email'
    ], 400);
}

function supabase_request(
    string $method,
    string $url,
    string $key,
    string $schema,
    ?array $payload = null
): array {
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'status' => $status,
        'body' => $body,
        'error' => $error,
        'json' => json_decode((string)$body, true),
    ];
}

$findUrl = $supabaseUrl
    . '/rest/v1/users?select=id,email,tenant_id,role,is_active'
    . '&email=eq.' . rawurlencode($newEmail)
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';

$findResponse = supabase_request('GET', $findUrl, $supabaseKey, $supabaseSchema);

if ($findResponse['error'] !== '' || $findResponse['status'] < 200 || $findResponse['status'] >= 300) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się sprawdzić użytkownika'
    ], 500);
}

$users = is_array($findResponse['json']) ? $findResponse['json'] : [];

if (count($users) === 0) {
    json_response([
        'success' => false,
        'error' => 'Użytkownik nie istnieje'
    ], 404);
}

$newUser = $users[0];
$newUserId = (string)($newUser['id'] ?? '');

if ($newUserId === '') {
    json_response([
        'success' => false,
        'error' => 'Nieprawidłowy użytkownik'
    ], 500);
}

if ($newUserId === $currentUserId) {
    json_response([
        'success' => false,
        'error' => 'Ten użytkownik jest już administratorem'
    ], 400);
}

$promoteUrl = $supabaseUrl . '/rest/v1/users?id=eq.' . rawurlencode($newUserId);

$promoteResponse = supabase_request('PATCH', $promoteUrl, $supabaseKey, $supabaseSchema, [
    'role' => 'admin',
    'is_active' => true,
]);

if ($promoteResponse['error'] !== '' || $promoteResponse['status'] < 200 || $promoteResponse['status'] >= 300) {
    json_response([
        'success' => false,
        'error' => 'Nie udało się ustawić nowego administratora'
    ], 500);
}

$demoteUrl = $supabaseUrl . '/rest/v1/users?id=eq.' . rawurlencode($currentUserId);

$demoteResponse = supabase_request('PATCH', $demoteUrl, $supabaseKey, $supabaseSchema, [
    'role' => 'user',
    'is_active' => false,
]);

if ($demoteResponse['error'] !== '' || $demoteResponse['status'] < 200 || $demoteResponse['status'] >= 300) {
    json_response([
        'success' => false,
        'error' => 'Nowy administrator został ustawiony, ale nie udało się dezaktywować starego administratora'
    ], 500);
}

session_destroy();

json_response([
    'success' => true,
    'message' => 'Administrator zmieniony'
]);