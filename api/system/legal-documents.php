<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$userId = (string) $_SESSION['user']['id'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tenantId === '' || $userId === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak danych użytkownika w sesji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function legal_clean_text(mixed $value, int $maxLength = 0): string
{
    $text = trim((string) $value);

    $text = str_replace(["\r\n", "\r"], "\n", $text);

    if ($maxLength > 0 && mb_strlen($text, 'UTF-8') > $maxLength) {
        $text = mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return $text;
}

function legal_default_payload(string $tenantId): array
{
    return [
        'tenant_id' => $tenantId,
        'terms_title' => 'Regulamin rezerwacji',
        'terms_content' => '',
        'privacy_title' => 'Polityka prywatności',
        'privacy_content' => '',
        'is_enabled' => false,
        'updated_at' => null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $url = $supabaseUrl
        . '/rest/v1/tenant_legal_documents'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&select=tenant_id,terms_title,terms_content,privacy_title,privacy_content,is_enabled,updated_at'
        . '&limit=1';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $curlError) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Błąd połączenia z Supabase',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        http_response_code($httpCode > 0 ? $httpCode : 500);
        echo json_encode([
            'success' => false,
            'error' => 'Nie udało się pobrać dokumentów prawnych',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = json_decode($response, true);

    if (!is_array($rows) || empty($rows[0]) || !is_array($rows[0])) {
        echo json_encode(public_response_sanitize([
            'success' => true,
            'documents' => legal_default_payload($tenantId)
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = $rows[0];

    echo json_encode(public_response_sanitize([
        'success' => true,
        'documents' => [
            'tenant_id' => (string) ($row['tenant_id'] ?? $tenantId),
            'terms_title' => (string) ($row['terms_title'] ?? 'Regulamin rezerwacji'),
            'terms_content' => (string) ($row['terms_content'] ?? ''),
            'privacy_title' => (string) ($row['privacy_title'] ?? 'Polityka prywatności'),
            'privacy_content' => (string) ($row['privacy_content'] ?? ''),
            'is_enabled' => !empty($row['is_enabled']),
            'updated_at' => $row['updated_at'] ?? null,
        ]
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$termsTitle = legal_clean_text($input['terms_title'] ?? 'Regulamin rezerwacji', 150);
$privacyTitle = legal_clean_text($input['privacy_title'] ?? 'Polityka prywatności', 150);

if ($termsTitle === '') {
    $termsTitle = 'Regulamin rezerwacji';
}

if ($privacyTitle === '') {
    $privacyTitle = 'Polityka prywatności';
}

$data = [
    'tenant_id' => $tenantId,
    'terms_title' => $termsTitle,
    'terms_content' => legal_clean_text($input['terms_content'] ?? ''),
    'privacy_title' => $privacyTitle,
    'privacy_content' => legal_clean_text($input['privacy_content'] ?? ''),
    'is_enabled' => !empty($input['is_enabled']),
];

$url = $supabaseUrl . '/rest/v1/tenant_legal_documents?on_conflict=tenant_id';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([$data], JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => array_merge(
        supabaseHeaders($supabaseKey, $schema),
        ['Prefer: resolution=merge-duplicates']
    ),
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false || $curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd połączenia z Supabase',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode > 0 ? $httpCode : 500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać dokumentów prawnych',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(public_response_sanitize([
    'success' => true,
    'message' => 'Dokumenty prawne zapisane',
    'saved_fields' => array_keys($data)
]), JSON_UNESCAPED_UNICODE);
