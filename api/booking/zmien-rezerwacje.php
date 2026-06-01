<?php
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["error" => "Brak dostêpu"]);
    exit;
}
header('Content-Type: application/json');

$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY');

// dane z frontu
$input = json_decode(file_get_contents('php://input'), true);

$id   = $input['id'] ?? null;
$date = $input['date'] ?? null;
$time = $input['time'] ?? null;

if (!$id || !$date || !$time) {
    echo json_encode([
        "success" => false,
        "error" => "Brak danych"
    ]);
    exit;
}

// payload update
$data = [
    "date" => $date,
    "time" => $time
];

// endpoint update
$url = $SUPABASE_URL . "/rest/v1/rezerwacje?id=eq.$id";

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY",
    "Authorization: Bearer $SUPABASE_KEY",
    "Content-Type: application/json"
]);

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error || $httpCode >= 400) {
    echo json_encode([
        "success" => false,
        "error" => "B³¹d aktualizacji",
        "debug" => $response
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Zmieniono rezerwacjê"
]);