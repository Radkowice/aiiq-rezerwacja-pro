<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/tenant.php';

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$serviceRoleKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $serviceRoleKey === '') {
    http_response_code(500);
    exit;
}

$tenantId = getTenantIdFromHost($supabaseUrl, $serviceRoleKey, $schema);

if (!$tenantId) {
    http_response_code(404);
    exit;
}

$url = $supabaseUrl
    . '/rest/v1/tenant_branding'
    . '?select=logo_url_front'
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

if ($response === false || $curlError || $httpCode >= 400) {
    http_response_code(404);
    exit;
}

$data = json_decode($response, true);
$logoUrl = trim((string)($data[0]['logo_url_front'] ?? ''));

if ($logoUrl === '') {
    http_response_code(404);
    exit;
}

$path = parse_url($logoUrl, PHP_URL_PATH);

if (!is_string($path) || $path === '') {
    http_response_code(404);
    exit;
}

$allowedPrefix = '/data/logo/';
if (!str_starts_with($path, $allowedPrefix)) {
    http_response_code(403);
    exit;
}

$relativePath = ltrim($path, '/');
$filePath = realpath('/var/www/html/' . $relativePath);
$basePath = realpath('/var/www/html/data/logo');

if ($filePath === false || $basePath === false || !str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeMap = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];

if (!isset($mimeMap[$extension])) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mimeMap[$extension]);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=3600');

readfile($filePath);
exit;