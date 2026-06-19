<?php
declare(strict_types=1);

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

function upload_logo_valid_tenant_id(string $tenantId): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId) === 1;
}

function upload_logo_log(string $code): void
{
    error_log('[branding_upload_logo] ' . $code);
}

function upload_logo_decode_image(string $path, string $mime)
{
    $decoder = match ($mime) {
        'image/png' => 'imagecreatefrompng',
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };

    if ($decoder === '' || !function_exists($decoder)) {
        upload_logo_log('missing_gd_function:' . ($decoder !== '' ? $decoder : 'unknown'));
        return false;
    }

    $image = @$decoder($path);
    if ($image === false) {
        upload_logo_log('decode_failed:' . $mime);
    }

    return $image;
}

function upload_logo_prepare_image($image)
{
    if (function_exists('imageistruecolor')
        && function_exists('imagepalettetotruecolor')
        && !imageistruecolor($image)
        && !@imagepalettetotruecolor($image)) {
        upload_logo_log('palette_conversion_failed');
    }

    if (function_exists('imagealphablending')) {
        @imagealphablending($image, false);
    }
    if (function_exists('imagesavealpha')) {
        @imagesavealpha($image, true);
    }

    return $image;
}

function upload_logo_output_is_valid(string $path, string $expectedMime, int $expectedType, finfo $finfo): bool
{
    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size <= 0) {
        upload_logo_log('output_empty');
        return false;
    }

    $mime = (string) $finfo->file($path);
    if ($mime !== $expectedMime) {
        upload_logo_log('output_mime_invalid');
        return false;
    }

    $imageInfo = @getimagesize($path);
    if (!is_array($imageInfo)
        || (int) ($imageInfo[0] ?? 0) < 1
        || (int) ($imageInfo[1] ?? 0) < 1
        || (int) ($imageInfo[2] ?? 0) !== $expectedType
        || strtolower((string) ($imageInfo['mime'] ?? '')) !== $expectedMime) {
        upload_logo_log('output_image_invalid');
        return false;
    }

    return true;
}

function upload_logo_encode($image, string $targetDir, string $format, finfo $finfo): ?string
{
    $temporaryPath = @tempnam($targetDir, '.logo-upload-');
    if ($temporaryPath === false) {
        upload_logo_log('temp_file_failed');
        return null;
    }

    $temporaryDirReal = realpath(dirname($temporaryPath));
    if ($temporaryDirReal === false || $temporaryDirReal !== $targetDir) {
        upload_logo_log('temp_file_failed');
        @unlink($temporaryPath);
        return null;
    }

    if (function_exists('chmod')) {
        @chmod($temporaryPath, 0600);
    }

    if ($format === 'webp') {
        $encoded = @imagewebp($image, $temporaryPath, 88);
        $expectedMime = 'image/webp';
        $expectedType = IMAGETYPE_WEBP;
    } else {
        $encoded = @imagepng($image, $temporaryPath, 6);
        $expectedMime = 'image/png';
        $expectedType = IMAGETYPE_PNG;
    }

    if (!$encoded) {
        upload_logo_log('encode_failed:' . $format);
        @unlink($temporaryPath);
        return null;
    }

    if (!upload_logo_output_is_valid($temporaryPath, $expectedMime, $expectedType, $finfo)) {
        @unlink($temporaryPath);
        return null;
    }

    return $temporaryPath;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    upload_logo_json(405, ['success' => false, 'error' => 'Metoda niedozwolona.']);
}

$sessionUser = $_SESSION['user'] ?? null;

if (!is_array($sessionUser) || empty($sessionUser['id']) || empty($sessionUser['tenant_id'])) {
    upload_logo_json(401, ['success' => false, 'error' => 'Brak autoryzacji.']);
}

$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if (!in_array($role, ['admin', 'administrator'], true)) {
    upload_logo_json(403, ['success' => false, 'error' => 'Brak uprawnień administratora.']);
}

$SUPABASE_URL = getenv('SUPABASE_URL') ?: '';
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    upload_logo_json(500, ['success' => false, 'error' => 'Brak konfiguracji usługi.']);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SCHEMA)) {
    upload_logo_json(401, ['success' => false, 'error' => 'Sesja nie pasuje do domeny.']);
}

$tenantId = (string) $sessionUser['tenant_id'];
if (!upload_logo_valid_tenant_id($tenantId)) {
    upload_logo_json(400, ['success' => false, 'error' => 'Nieprawidłowe dane konta.']);
}

if (empty($_FILES['logo']) || !is_array($_FILES['logo'])) {
    upload_logo_json(400, ['success' => false, 'error' => 'Nie przesłano pliku logo.']);
}

$file = $_FILES['logo'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    upload_logo_json(400, ['success' => false, 'error' => 'Błąd przesyłania pliku.']);
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    upload_logo_json(400, ['success' => false, 'error' => 'Nieprawidłowy plik uploadu.']);
}

$maxSize = 2 * 1024 * 1024;
$actualSize = filesize($tmpPath);
if ($actualSize === false || $actualSize <= 0 || $actualSize > $maxSize) {
    upload_logo_json(400, ['success' => false, 'error' => 'Plik jest za duży. Maksymalny rozmiar to 2 MB.']);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmpPath);
$allowedTypes = [
    'image/png' => IMAGETYPE_PNG,
    'image/jpeg' => IMAGETYPE_JPEG,
    'image/webp' => IMAGETYPE_WEBP,
];

if (!isset($allowedTypes[$mime])) {
    upload_logo_json(400, ['success' => false, 'error' => 'Plik musi być obrazem PNG, JPG lub WebP.']);
}

$imageInfo = @getimagesize($tmpPath);
if (!is_array($imageInfo)
    || (int) ($imageInfo[0] ?? 0) < 1
    || (int) ($imageInfo[1] ?? 0) < 1
    || (int) ($imageInfo[2] ?? 0) !== $allowedTypes[$mime]
    || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime) {
    upload_logo_json(400, ['success' => false, 'error' => 'Plik musi być prawidłowym obrazem PNG, JPG lub WebP.']);
}

$width = (int) $imageInfo[0];
$height = (int) $imageInfo[1];
if ($width > 3000 || $height > 3000 || ($width * $height) > 9000000) {
    upload_logo_json(400, ['success' => false, 'error' => 'Obraz ma zbyt duże wymiary. Maksymalnie 3000 × 3000 px.']);
}

$image = upload_logo_decode_image($tmpPath, $mime);
if ($image === false) {
    upload_logo_json(400, ['success' => false, 'error' => 'Nie udało się bezpiecznie przetworzyć obrazu.']);
}

$image = upload_logo_prepare_image($image);

$baseDir = realpath(__DIR__ . '/../../html');
if ($baseDir === false) {
    upload_logo_log('directory_create_failed');
    imagedestroy($image);
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na logo.']);
}

$storageRoot = $baseDir . '/data/logo';
$storageRootCreated = false;
if (!is_dir($storageRoot)) {
    $storageRootCreated = @mkdir($storageRoot, 0775, true);
}
if (!is_dir($storageRoot)) {
    upload_logo_log('directory_create_failed');
    imagedestroy($image);
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na logo.']);
}
if ($storageRootCreated && function_exists('chmod')) {
    @chmod($storageRoot, 0775);
}
if (!is_writable($storageRoot)) {
    upload_logo_log('directory_not_writable');
    imagedestroy($image);
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na logo.']);
}

$storageRootReal = realpath($storageRoot);
$targetDir = $storageRoot . '/' . $tenantId;
$targetDirCreated = false;
if ($storageRootReal !== false && !is_dir($targetDir)) {
    $targetDirCreated = @mkdir($targetDir, 0775, true);
}
if ($storageRootReal === false || !is_dir($targetDir)) {
    upload_logo_log('directory_create_failed');
    imagedestroy($image);
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na logo.']);
}
if ($targetDirCreated && function_exists('chmod')) {
    @chmod($targetDir, 0775);
}
if (!is_writable($targetDir)) {
    upload_logo_log('directory_not_writable');
    imagedestroy($image);
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się przygotować miejsca na logo.']);
}

$targetDirReal = realpath($targetDir);
$expectedTenantDir = $storageRootReal . DIRECTORY_SEPARATOR . $tenantId;
if ($targetDirReal === false || $targetDirReal !== $expectedTenantDir) {
    upload_logo_log('directory_containment_failed');
    imagedestroy($image);
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie zapisać logo.']);
}

$temporaryPath = null;
$extension = '';

if (function_exists('imagewebp')) {
    $temporaryPath = upload_logo_encode($image, $targetDirReal, 'webp', $finfo);
    if ($temporaryPath !== null) {
        $extension = 'webp';
    }
}

if ($temporaryPath === null) {
    if (!function_exists('imagepng')) {
        upload_logo_log('missing_gd_function:imagepng');
        imagedestroy($image);
        upload_logo_json(500, ['success' => false, 'error' => 'Serwer nie obsługuje bezpiecznego zapisu obrazu.']);
    }

    $temporaryPath = upload_logo_encode($image, $targetDirReal, 'png', $finfo);
    if ($temporaryPath !== null) {
        $extension = 'png';
    }
}

imagedestroy($image);

if ($temporaryPath === null || $extension === '') {
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie przetworzyć obrazu.']);
}

$targetFileName = 'logo-front.' . $extension;
$targetPath = $targetDirReal . DIRECTORY_SEPARATOR . $targetFileName;

if (!@rename($temporaryPath, $targetPath)) {
    upload_logo_log('rename_failed');
    @unlink($temporaryPath);
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się bezpiecznie zapisać logo.']);
}

if (function_exists('chmod')) {
    @chmod($targetPath, 0644);
}
$relativeDir = '/data/logo/' . $tenantId;
$logoUrl = $relativeDir . '/' . $targetFileName;
$url = rtrim($SUPABASE_URL, '/') . '/rest/v1/tenant_branding?tenant_id=eq.' . rawurlencode($tenantId);
$payload = json_encode(['logo_url_front' => $logoUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    upload_logo_log('branding_patch_failed');
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się zapisać ścieżki pliku w ustawieniach brandingu.']);
}

$updatedRows = json_decode((string) $response, true);
$brandingPatchEmpty = !is_array($updatedRows) || !isset($updatedRows[0]) || !is_array($updatedRows[0]);
if ($brandingPatchEmpty) {
    upload_logo_log('branding_patch_empty');
}
$updatedLogoUrl = is_array($updatedRows) && isset($updatedRows[0]) && is_array($updatedRows[0])
    ? ($updatedRows[0]['logo_url_front'] ?? null)
    : null;

if (!is_string($updatedLogoUrl) || !hash_equals($logoUrl, $updatedLogoUrl)) {
    if (!$brandingPatchEmpty) {
        upload_logo_log('branding_patch_invalid');
    }
    upload_logo_json(500, ['success' => false, 'error' => 'Nie udało się zapisać ścieżki pliku w ustawieniach brandingu.']);
}

$allowedOldNames = ['logo-front.png', 'logo-front.jpg', 'logo-front.jpeg', 'logo-front.webp'];
foreach ($allowedOldNames as $oldName) {
    if ($oldName === $targetFileName) {
        continue;
    }
    $oldPath = $targetDirReal . DIRECTORY_SEPARATOR . $oldName;
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

upload_logo_json(200, [
    'success' => true,
    'message' => 'Logo zapisane.',
    'logo_url_front' => $logoUrl,
]);
