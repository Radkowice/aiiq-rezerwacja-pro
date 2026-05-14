<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function delete_logo_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    delete_logo_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ]);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    delete_logo_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    delete_logo_json(500, [
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ]);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    delete_logo_json(401, [
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.'
    ]);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$safeTenantId = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId);

$baseDir = realpath(__DIR__ . '/../../html');

if ($baseDir !== false && $safeTenantId !== '') {
    $targetDir = $baseDir . '/data/logo/' . $safeTenantId;

    foreach (array_merge(
        glob($targetDir . '/logo-front-*') ?: [],
        glob($targetDir . '/logo-front.*') ?: []
    ) as $oldLogo) {
        if (is_file($oldLogo)) {
            @unlink($oldLogo);
        }
    }
}

$url = $supabaseUrl . '/rest/v1/tenant_branding'
    . '?tenant_id=eq.' . rawurlencode($tenantId);

$payload = json_encode([
    'logo_url_front' => null
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
    delete_logo_json(500, [
        'success' => false,
        'error' => 'Nie udało się połączyć z bazą.'
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    delete_logo_json(500, [
        'success' => false,
        'error' => 'Nie udało się usunąć logo z bazy.'
    ]);
}

delete_logo_json(200, [
    'success' => true,
    'message' => 'Logo usunięte.',
    'logo_url_front' => null
]);
