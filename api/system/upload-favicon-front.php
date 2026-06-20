<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/branding-assets.php';
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

function favicon_valid_tenant_id(string $tenantId): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId) === 1;
}

function favicon_log(string $code): void
{
    error_log('[branding_upload_favicon] ' . $code);
}

function favicon_decode_image(string $path, string $mime)
{
    $decoder = match ($mime) {
        'image/png' => 'imagecreatefrompng',
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };

    if ($decoder === '' || !function_exists($decoder)) {
        favicon_log('missing_gd_function:' . ($decoder !== '' ? $decoder : 'unknown'));
        return false;
    }

    $image = @$decoder($path);
    if ($image === false) {
        favicon_log('decode_failed:' . $mime);
    }

    return $image;
}

function favicon_prepare_image($image)
{
    if (function_exists('imageistruecolor')
        && function_exists('imagepalettetotruecolor')
        && !imageistruecolor($image)
        && !@imagepalettetotruecolor($image)) {
        favicon_log('palette_conversion_failed');
    }

    if (function_exists('imagealphablending')) {
        @imagealphablending($image, false);
    }
    if (function_exists('imagesavealpha')) {
        @imagesavealpha($image, true);
    }

    return $image;
}

function favicon_output_is_valid(string $path, finfo $finfo): bool
{
    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size <= 0) {
        favicon_log('output_empty');
        return false;
    }

    $mime = (string) $finfo->file($path);
    if ($mime !== 'image/png') {
        favicon_log('output_mime_invalid');
        return false;
    }

    $imageInfo = @getimagesize($path);
    if (!is_array($imageInfo)
        || (int) ($imageInfo[0] ?? 0) < 1
        || (int) ($imageInfo[1] ?? 0) < 1
        || (int) ($imageInfo[2] ?? 0) !== IMAGETYPE_PNG
        || strtolower((string) ($imageInfo['mime'] ?? '')) !== 'image/png') {
        favicon_log('output_image_invalid');
        return false;
    }

    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    favicon_json(405, ['success' => false, 'error' => 'Metoda niedozwolona.']);
}

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || empty($sessionUser['id']) || empty($sessionUser['tenant_id'])) {
    favicon_json(401, ['success' => false, 'error' => 'Brak autoryzacji.']);
}

$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if (!in_array($role, ['admin', 'administrator'], true)) {
    favicon_json(403, ['success' => false, 'error' => 'Brak uprawnień administratora.']);
}

$SUPABASE_URL = getenv('SUPABASE_URL') ?: '';
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    favicon_json(500, ['success' => false, 'error' => 'Brak konfiguracji usługi.']);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA)) {
    favicon_json(401, ['success' => false, 'error' => 'Sesja nie pasuje do domeny.']);
}

$tenantId = (string) $sessionUser['tenant_id'];
if (!favicon_valid_tenant_id($tenantId)) {
    favicon_json(400, ['success' => false, 'error' => 'Nieprawidłowe dane konta.']);
}

if (empty($_FILES['favicon']) || !is_array($_FILES['favicon'])) {
    favicon_json(400, ['success' => false, 'error' => 'Nie przesłano pliku favicony.']);
}

$file = $_FILES['favicon'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    favicon_json(400, ['success' => false, 'error' => 'Błąd przesyłania favicony.']);
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    favicon_json(400, ['success' => false, 'error' => 'Nieprawidłowy plik uploadu.']);
}

$maxSize = 512 * 1024;
$actualSize = filesize($tmpPath);
if ($actualSize === false || $actualSize <= 0 || $actualSize > $maxSize) {
    favicon_json(400, ['success' => false, 'error' => 'Plik jest za duży. Maksymalny rozmiar favicony to 512 KB.']);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmpPath);
$allowedTypes = [
    'image/png' => IMAGETYPE_PNG,
    'image/jpeg' => IMAGETYPE_JPEG,
    'image/webp' => IMAGETYPE_WEBP,
];

if (!isset($allowedTypes[$mime])) {
    favicon_json(400, ['success' => false, 'error' => 'Plik musi być obrazem PNG, JPG lub WebP.']);
}

$imageInfo = @getimagesize($tmpPath);
if (!is_array($imageInfo)
    || (int) ($imageInfo[0] ?? 0) < 16
    || (int) ($imageInfo[1] ?? 0) < 16
    || (int) ($imageInfo[2] ?? 0) !== $allowedTypes[$mime]
    || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime) {
    favicon_json(400, ['success' => false, 'error' => 'Favicon musi być prawidłowym obrazem PNG, JPG lub WebP o wymiarach co najmniej 16 × 16 px.']);
}

$width = (int) $imageInfo[0];
$height = (int) $imageInfo[1];
if ($width > 1024 || $height > 1024 || ($width * $height) > 1048576) {
    favicon_json(400, ['success' => false, 'error' => 'Obraz ma zbyt duże wymiary. Maksymalnie 1024 × 1024 px.']);
}

if (!function_exists('imagepng')) {
    favicon_log('missing_gd_function:imagepng');
    favicon_json(500, ['success' => false, 'error' => 'Serwer nie obsługuje bezpiecznego zapisu favicony.']);
}

$image = favicon_decode_image($tmpPath, $mime);
if ($image === false) {
    favicon_json(400, ['success' => false, 'error' => 'Nie udało się bezpiecznie przetworzyć obrazu.']);
}

$image = favicon_prepare_image($image);

$baseDir = realpath(__DIR__ . '/../../html');
if ($baseDir === false) {
    favicon_log('directory_create_failed');
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na faviconę.']);
}

$storageRoot = $baseDir . '/data/favicon';
$storageRootCreated = false;
if (!is_dir($storageRoot)) {
    $storageRootCreated = @mkdir($storageRoot, 0775, true);
}
if (!is_dir($storageRoot)) {
    favicon_log('directory_create_failed');
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na faviconę.']);
}
if ($storageRootCreated && function_exists('chmod')) {
    @chmod($storageRoot, 0775);
}
if (!is_writable($storageRoot)) {
    favicon_log('directory_not_writable');
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na faviconę.']);
}

$storageRootReal = realpath($storageRoot);
$targetDir = $storageRoot . '/' . $tenantId;
$targetDirCreated = false;
if ($storageRootReal !== false && !is_dir($targetDir)) {
    $targetDirCreated = @mkdir($targetDir, 0775, true);
}
if ($storageRootReal === false || !is_dir($targetDir)) {
    favicon_log('directory_create_failed');
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na faviconę.']);
}
if ($targetDirCreated && function_exists('chmod')) {
    @chmod($targetDir, 0775);
}
if (!is_writable($targetDir)) {
    favicon_log('directory_not_writable');
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na faviconę.']);
}

$targetDirReal = realpath($targetDir);
$expectedTenantDir = $storageRootReal . DIRECTORY_SEPARATOR . $tenantId;
if ($targetDirReal === false || $targetDirReal !== $expectedTenantDir) {
    favicon_log('directory_containment_failed');
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie zapisać favicony.']);
}

$targetFileName = 'favicon-front.png';
$targetPath = $targetDirReal . DIRECTORY_SEPARATOR . $targetFileName;
$temporaryPath = @tempnam($targetDirReal, '.favicon-upload-');
if ($temporaryPath === false) {
    favicon_log('temp_file_failed');
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie zapisać favicony.']);
}

$temporaryDirReal = realpath(dirname($temporaryPath));
if ($temporaryDirReal === false || $temporaryDirReal !== $targetDirReal) {
    favicon_log('temp_file_failed');
    @unlink($temporaryPath);
    imagedestroy($image);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie zapisać favicony.']);
}

if (function_exists('chmod')) {
    @chmod($temporaryPath, 0600);
}

$encoded = @imagepng($image, $temporaryPath, 6);
imagedestroy($image);
if (!$encoded) {
    favicon_log('encode_failed:png');
    @unlink($temporaryPath);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie przetworzyć obrazu.']);
}

if (!favicon_output_is_valid($temporaryPath, $finfo)) {
    @unlink($temporaryPath);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie przetworzyć obrazu.']);
}

if (!@rename($temporaryPath, $targetPath)) {
    favicon_log('rename_failed');
    @unlink($temporaryPath);
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie zapisać favicony.']);
}

if (function_exists('chmod')) {
    @chmod($targetPath, 0644);
}
$relativeDir = '/data/favicon/' . $tenantId;
$faviconUrl = $relativeDir . '/' . $targetFileName;
$url = rtrim($SUPABASE_URL, '/') . '/rest/v1/tenant_branding?tenant_id=eq.' . rawurlencode($tenantId);
$payload = json_encode(['favicon_url_front' => $faviconUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

if ($response === false || $curlError || $httpCode < 200 || $httpCode >= 300) {
    favicon_log('branding_patch_failed');
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się zapisać ścieżki pliku w ustawieniach brandingu.']);
}

$updatedRows = json_decode((string) $response, true);
$brandingPatchEmpty = !is_array($updatedRows) || !isset($updatedRows[0]) || !is_array($updatedRows[0]);
if ($brandingPatchEmpty) {
    favicon_log('branding_patch_empty');
}
$updatedFaviconUrl = is_array($updatedRows) && isset($updatedRows[0]) && is_array($updatedRows[0])
    ? ($updatedRows[0]['favicon_url_front'] ?? null)
    : null;

if (!is_string($updatedFaviconUrl) || !hash_equals($faviconUrl, $updatedFaviconUrl)) {
    if (!$brandingPatchEmpty) {
        favicon_log('branding_patch_invalid');
    }
    favicon_json(500, ['success' => false, 'error' => 'Nie udało się zapisać ścieżki pliku w ustawieniach brandingu.']);
}

$allowedOldNames = [
    'favicon-front.png',
    'favicon-front.jpg',
    'favicon-front.jpeg',
    'favicon-front.webp',
    'favicon-front.ico',
];
foreach ($allowedOldNames as $oldName) {
    if ($oldName === $targetFileName) {
        continue;
    }
    $oldPath = $targetDirReal . DIRECTORY_SEPARATOR . $oldName;
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

favicon_json(200, [
    'success' => true,
    'message' => 'Favicon zapisana.',
    'favicon_url_front' => branding_asset_public_url($faviconUrl, $tenantId, 'favicon'),
    'has_favicon' => true,
]);
