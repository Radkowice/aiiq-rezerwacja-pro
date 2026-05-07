<?php

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function favicon_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    favicon_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ]);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    favicon_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$SUPABASE_URL = getenv('SUPABASE_URL') ?: '';
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    favicon_json(500, [
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ]);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA)) {
    favicon_json(401, [
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.'
    ]);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];

if (empty($_FILES['favicon']) || !is_array($_FILES['favicon'])) {
    favicon_json(400, [
        'success' => false,
        'error' => 'Nie przesłano pliku favicony.'
    ]);
}

$file = $_FILES['favicon'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    favicon_json(400, [
        'success' => false,
        'error' => 'Błąd przesyłania favicony.'
    ]);
}

$maxSize = 512 * 1024;

if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSize) {
    favicon_json(400, [
        'success' => false,
        'error' => 'Favicon może mieć maksymalnie 512 KB.'
    ]);
}

$tmpPath = $file['tmp_name'] ?? '';

if (!is_uploaded_file($tmpPath)) {
    favicon_json(400, [
        'success' => false,
        'error' => 'Nieprawidłowy plik uploadu.'
    ]);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmpPath);

$allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/x-icon' => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
];

if (!isset($allowed[$mime])) {
    favicon_json(400, [
        'success' => false,
        'error' => 'Dozwolone formaty favicony: PNG, JPG, WEBP, ICO.'
    ]);
}

$extension = $allowed[$mime];

$baseDir = realpath(__DIR__ . '/../../html');

if ($baseDir === false) {
    favicon_json(500, [
        'success' => false,
        'error' => 'Nie znaleziono katalogu aplikacji.'
    ]);
}

$safeTenantId = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId);

$relativeDir = '/data/favicon/' . $safeTenantId;
$targetDir = $baseDir . $relativeDir;

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
    favicon_json(500, [
        'success' => false,
        'error' => 'Nie udało się utworzyć katalogu favicony.'
    ]);
}

$targetFileName = 'favicon-front.' . $extension;
$targetPath = $targetDir . '/' . $targetFileName;

foreach (glob($targetDir . '/favicon-front.*') ?: [] as $oldFavicon) {
    if (is_file($oldFavicon)) {
        @unlink($oldFavicon);
    }
}

if (!move_uploaded_file($tmpPath, $targetPath)) {
    favicon_json(500, [
        'success' => false,
        'error' => 'Nie udało się zapisać favicony.'
    ]);
}

@chmod($targetPath, 0644);

$faviconUrl = $relativeDir . '/' . $targetFileName;

$url = rtrim($SUPABASE_URL, '/') . '/rest/v1/tenant_branding'
    . '?tenant_id=eq.' . rawurlencode($tenantId);

$payload = json_encode([
    'favicon_url_front' => $faviconUrl
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$headers = supabaseHeaders($SUPABASE_KEY, $SCHEMA);
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
    favicon_json(500, [
        'success' => false,
        'error' => 'Favicon zapisany, ale nie udało się połączyć z bazą.'
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    favicon_json(500, [
        'success' => false,
        'error' => 'Favicon zapisany, ale nie udało się zaktualizować bazy.'
    ]);
}

favicon_json(200, [
    'success' => true,
    'message' => 'Favicon zapisany.',
    'favicon_url_front' => $faviconUrl
]);
