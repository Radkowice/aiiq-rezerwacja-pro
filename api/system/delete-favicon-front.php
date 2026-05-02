<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function delete_favicon_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    delete_favicon_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ]);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    delete_favicon_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$safeTenantId = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId);

$baseDir = realpath(__DIR__ . '/../../html');

if ($baseDir !== false && $safeTenantId !== '') {
    $targetDir = $baseDir . '/data/favicon/' . $safeTenantId;

    foreach (glob($targetDir . '/favicon-front.*') ?: [] as $oldFavicon) {
        if (is_file($oldFavicon)) {
            @unlink($oldFavicon);
        }
    }
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    delete_favicon_json(500, [
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ]);
}

$url = $supabaseUrl . '/rest/v1/tenant_branding'
    . '?tenant_id=eq.' . rawurlencode($tenantId);

$payload = json_encode([
    'favicon_url_front' => null
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$headers = supabaseHeaders($supabaseKey, $schema);
$headers[] = 'Content-Type: application/json';
$headers[] = 'Prefer: return=representation';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $payload,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError) {
    delete_favicon_json(500, [
        'success' => false,
        'error' => 'Nie udało się połączyć z bazą.',
        'debug' => $curlError
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    delete_favicon_json(500, [
        'success' => false,
        'error' => 'Nie udało się usunąć favicony z bazy.',
        'debug' => $response
    ]);
}

delete_favicon_json(200, [
    'success' => true,
    'message' => 'Favicon usunięta.',
    'favicon_url_front' => null
]);