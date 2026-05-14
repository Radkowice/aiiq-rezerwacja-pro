<?php

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function upload_logo_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    upload_logo_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ]);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    upload_logo_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$SUPABASE_URL = getenv('SUPABASE_URL') ?: '';
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    upload_logo_json(500, [
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ]);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA)) {
    upload_logo_json(401, [
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.'
    ]);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];

if (empty($_FILES['logo']) || !is_array($_FILES['logo'])) {
    upload_logo_json(400, [
        'success' => false,
        'error' => 'Nie przesłano pliku logo.'
    ]);
}

$file = $_FILES['logo'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    upload_logo_json(400, [
        'success' => false,
        'error' => 'Błąd przesyłania pliku.'
    ]);
}

$maxSize = 2 * 1024 * 1024;

if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSize) {
    upload_logo_json(400, [
        'success' => false,
        'error' => 'Logo może mieć maksymalnie 2 MB.'
    ]);
}

$tmpPath = $file['tmp_name'] ?? '';

if (!is_uploaded_file($tmpPath)) {
    upload_logo_json(400, [
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
];

if (!isset($allowed[$mime])) {
    upload_logo_json(400, [
        'success' => false,
        'error' => 'Dozwolone formaty logo: PNG, JPG, WEBP.'
    ]);
}

$extension = $allowed[$mime];

$baseDir = realpath(__DIR__ . '/../../html');

if ($baseDir === false) {
    upload_logo_json(500, [
        'success' => false,
        'error' => 'Nie znaleziono katalogu aplikacji.'
    ]);
}

$safeTenantId = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId);
$relativeDir = '/data/logo/' . $safeTenantId;
$targetDir = $baseDir . $relativeDir;

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
    upload_logo_json(500, [
        'success' => false,
        'error' => 'Nie udało się utworzyć katalogu logo.'
    ]);
}

$targetFileName = 'logo-front-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
$targetPath = $targetDir . '/' . $targetFileName;

if (!move_uploaded_file($tmpPath, $targetPath)) {
    upload_logo_json(500, [
        'success' => false,
        'error' => 'Nie udało się zapisać logo.'
    ]);
}

@chmod($targetPath, 0644);

$logoUrl = $relativeDir . '/' . $targetFileName;

$url = rtrim($SUPABASE_URL, '/') . '/rest/v1/tenant_branding'
    . '?tenant_id=eq.' . rawurlencode($tenantId);

$payload = json_encode([
    'logo_url_front' => $logoUrl
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
    upload_logo_json(500, [
        'success' => false,
        'error' => 'Logo zapisane, ale nie udało się połączyć z bazą.'
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    upload_logo_json(500, [
        'success' => false,
        'error' => 'Logo zapisane, ale nie udało się zaktualizować bazy.'
    ]);
}

foreach (array_merge(
    glob($targetDir . '/logo-front-*') ?: [],
    glob($targetDir . '/logo-front.*') ?: []
) as $oldLogo) {
    if (is_file($oldLogo) && realpath($oldLogo) !== realpath($targetPath)) {
        @unlink($oldLogo);
    }
}

upload_logo_json(200, [
    'success' => true,
    'message' => 'Logo zapisane.',
    'logo_url_front' => $logoUrl
]);
