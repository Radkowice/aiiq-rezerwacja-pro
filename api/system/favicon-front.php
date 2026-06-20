<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/tenant.php';

header('X-Content-Type-Options: nosniff');

function favicon_front_not_found(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(404);
    exit;
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(405);
    exit;
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    favicon_front_not_found();
}

$tenantId = (string) (getTenantIdFromHost($supabaseUrl, $serviceRoleKey, $schema) ?: '');
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId) !== 1) {
    favicon_front_not_found();
}

$url = $supabaseUrl
    . '/rest/v1/tenant_branding?select=favicon_url_front'
    . '&tenant_id=eq.' . rawurlencode($tenantId)
    . '&limit=1';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => supabaseHeaders($serviceRoleKey, $schema),
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError || $httpCode < 200 || $httpCode >= 300) {
    favicon_front_not_found();
}

$data = json_decode((string) $response, true);
$faviconUrl = trim((string) ($data[0]['favicon_url_front'] ?? ''));
$path = $faviconUrl !== '' ? parse_url($faviconUrl, PHP_URL_PATH) : null;
$expectedPrefix = '/data/favicon/' . $tenantId . '/';

if (!is_string($path) || !str_starts_with($path, $expectedPrefix)) {
    favicon_front_not_found();
}

$fileName = basename($path);
$allowedFiles = [
    'favicon-front.png' => ['image/png'],
];

if (!isset($allowedFiles[$fileName])) {
    favicon_front_not_found();
}

$baseDir = realpath(__DIR__ . '/../../html');
if ($baseDir === false) {
    favicon_front_not_found();
}

$storageRootPath = realpath($baseDir . '/data/favicon');
$tenantBasePath = realpath($baseDir . '/data/favicon/' . $tenantId);
$filePath = realpath($baseDir . '/data/favicon/' . $tenantId . '/' . $fileName);

if ($storageRootPath === false
    || $tenantBasePath === false
    || $tenantBasePath !== $storageRootPath . DIRECTORY_SEPARATOR . $tenantId
    || $filePath === false
    || !str_starts_with($filePath, $tenantBasePath . DIRECTORY_SEPARATOR)
    || !is_file($filePath)
    || !is_readable($filePath)) {
    favicon_front_not_found();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($filePath);
if (!in_array($mime, $allowedFiles[$fileName], true)) {
    favicon_front_not_found();
}

$imageInfo = @getimagesize($filePath);
if (!is_array($imageInfo) || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime) {
    favicon_front_not_found();
}

$fileSize = filesize($filePath);
if ($fileSize === false) {
    favicon_front_not_found();
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . $fileSize);
header('Cache-Control: public, max-age=3600');
if ($requestMethod === 'GET') {
    readfile($filePath);
}
exit;
