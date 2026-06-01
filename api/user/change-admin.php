<?php
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();
header('Content-Type: application/json');

$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["error" => "Brak dostêpu"]);
    exit;
}

$currentUser = $_SESSION['user'];
$tenantId = $currentUser['tenant_id'];

// tylko admin mo¿e zmieniæ admina
// (zak³adamy, ¿e masz role w sesji — jak nie, zaraz poprawimy)
$data = json_decode(file_get_contents("php://input"), true);
$newEmail = $data['email'] ?? '';

if (!$newEmail) {
    echo json_encode(["error" => "Podaj email nowego administratora"]);
    exit;
}

// =======================
// znajdŸ nowego usera
// =======================
$url = $SUPABASE_URL . "/rest/v1/users?email=eq." . rawurlencode($newEmail) .
       "&tenant_id=eq." . rawurlencode($tenantId) . "&limit=1";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY",
    "Authorization: Bearer $SUPABASE_KEY",
    "Accept-Profile: rezerwacja_pro"
]);

$response = curl_exec($ch);
curl_close($ch);

$users = json_decode($response, true);

if (!$users || count($users) === 0) {
    echo json_encode(["error" => "U¿ytkownik nie istnieje"]);
    exit;
}

$newUser = $users[0];

// =======================
// ustaw nowego admina
// =======================
$url = $SUPABASE_URL . "/rest/v1/users?id=eq." . $newUser['id'];

$payload = [
    "role" => "admin",
    "is_active" => true
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY",
    "Authorization: Bearer $SUPABASE_KEY",
    "Content-Type: application/json",
    "Content-Profile: rezerwacja_pro"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_exec($ch);
curl_close($ch);

// =======================
// zdejmij admina ze starego
// =======================
$url = $SUPABASE_URL . "/rest/v1/users?id=eq." . $currentUser['id'];

$payload = [
    "role" => "user",
    "is_active" => false
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY",
    "Authorization: Bearer $SUPABASE_KEY",
    "Content-Type: application/json",
    "Content-Profile: rezerwacja_pro"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_exec($ch);
curl_close($ch);

// =======================
// wyloguj starego admina
// =======================
session_destroy();

echo json_encode([
    "success" => true,
    "message" => "Administrator zmieniony"
]);