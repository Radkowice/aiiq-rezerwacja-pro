<?php
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["error" => "Brak dostępu"]);
    exit;
}
header('Content-Type: application/json');

$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY');
$TENANT_ID = $_SESSION['user']['tenant_id'] ?? null;

if (!$TENANT_ID) {
    http_response_code(400);
    echo json_encode(["error" => "Nieprawidłowa sesja."], JSON_UNESCAPED_UNICODE);
    exit;
}

// sprawdzamy czy istnieje user
$url = $SUPABASE_URL . "/rest/v1/users?select=id&tenant_id=eq." . rawurlencode($TENANT_ID) . "&limit=1";

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY",
    "Authorization: Bearer $SUPABASE_KEY",
    "Accept-Profile: rezerwacja_pro"
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error || $httpCode >= 400) {
    echo json_encode([
        "installed" => false,
        "error" => "Supabase error"
    ]);
    exit;
}

$users = json_decode($response, true);

echo json_encode([
    "installed" => !empty($users)
]);