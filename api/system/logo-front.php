<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/tenant.php';

function logo_front_not_found(): void
{
    http_response_code(404);
    exit;
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    logo_front_not_found();
}

$tenantId = (string) (getTenantIdFromHost($supabaseUrl, $serviceRoleKey, $schema) ?: '');
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId) !== 1) {
    logo_front_not_found();
}

$url = $supabaseUrl
    . '/rest/v1/tenant_branding?select=logo_url_front'
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
    logo_front_not_found();
}

$data = json_decode((string) $response, true);
$logoUrl = trim((string) ($data[0]['logo_url_front'] ?? ''));
$path = $logoUrl !== '' ? parse_url($logoUrl, PHP_URL_PATH) : null;
$expectedPrefix = '/data/logo/' . $tenantId . '/';

if (!is_string($path) || !str_starts_with($path, $expectedPrefix)) {
    logo_front_not_found();
}

$fileName = basename($path);
$allowedFiles = [
    'logo-front.png' => ['image/png'],
    'logo-front.jpg' => ['image/jpeg'],
    'logo-front.jpeg' => ['image/jpeg'],
    'logo-front.webp' => ['image/webp'],
];

if (!isset($allowedFiles[$fileName])) {
    logo_front_not_found();
}

$storageRootPath = realpath('/var/www/html/data/logo');
$tenantBasePath = realpath('/var/www/html/data/logo/' . $tenantId);
$filePath = realpath('/var/www/html/data/logo/' . $tenantId . '/' . $fileName);

if ($storageRootPath === false
    || $tenantBasePath === false
    || $tenantBasePath !== $storageRootPath . DIRECTORY_SEPARATOR . $tenantId
    || $filePath === false
    || !str_starts_with($filePath, $tenantBasePath . DIRECTORY_SEPARATOR)
    || !is_file($filePath)
    || !is_readable($filePath)) {
    logo_front_not_found();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($filePath);
if (!in_array($mime, $allowedFiles[$fileName], true)) {
    logo_front_not_found();
}

$imageInfo = @getimagesize($filePath);
if (!is_array($imageInfo) || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime) {
    logo_front_not_found();
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=3600');
readfile($filePath);
exit;
