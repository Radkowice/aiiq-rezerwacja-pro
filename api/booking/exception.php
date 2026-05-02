<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
start_secure_session();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$TENANT_ID = $_SESSION['user']['tenant_id'] ?? null;

if (!$TENANT_ID) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Brak tenant_id w sesji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$SUPABASE_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$date = $input['date'] ?? null;   

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';  

if ($method === 'DELETE') {
    $url = $SUPABASE_URL
        . '/rest/v1/availability_exceptions?tenant_id=eq.'
        . rawurlencode($TENANT_ID)
        . '&date=eq.'
        . rawurlencode($date);

    $result = supabase_request(
        'DELETE',
        $url,
        $SUPABASE_KEY,
        $SUPABASE_SCHEMA
    );

    if ($result['error']) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'CURL exception delete error',
            'debug' => $result['error']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($result['httpCode'] >= 400) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Supabase exception delete error',
            'httpCode' => $result['httpCode'],
            'debug' => $result['response']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'date' => $date
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


if (!$date) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Brak daty'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function supabase_request(string $method, string $url, string $apiKey, string $schema, array $headers = [], ?array $payload = null): array
{
    $ch = curl_init($url);

    $baseHeaders = [
        "apikey: {$apiKey}",
        "Authorization: Bearer {$apiKey}",
        "Accept-Profile: {$schema}",
        "Content-Profile: {$schema}"
    ];

    $allHeaders = array_merge($baseHeaders, $headers);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'response' => $response,
        'error' => $error,
        'httpCode' => $httpCode
    ];
}

$url = $SUPABASE_URL
    . '/rest/v1/availability_exceptions?on_conflict=tenant_id,date';

$payload = [
    'tenant_id' => $TENANT_ID,
    'date' => $date,
    'allow_booking' => true
];

$result = supabase_request(
    'POST',
    $url,
    $SUPABASE_KEY,
    $SUPABASE_SCHEMA,
    [
        'Content-Type: application/json',
        'Prefer: return=representation,resolution=merge-duplicates'
    ],
    $payload
);

if ($result['error']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'CURL exception save error',
        'debug' => $result['error']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($result['httpCode'] >= 400) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Supabase exception save error',
        'httpCode' => $result['httpCode'],
        'debug' => $result['response']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cleanupUrl = $SUPABASE_URL
    . '/rest/v1/blocked_dates?tenant_id=eq.'
    . rawurlencode($TENANT_ID)
    . '&date=eq.'
    . rawurlencode($date);

$cleanup = supabase_request(
    'DELETE',
    $cleanupUrl,
    $SUPABASE_KEY,
    $SUPABASE_SCHEMA
);

echo json_encode([
    'success' => true,
    'date' => $date
], JSON_UNESCAPED_UNICODE);