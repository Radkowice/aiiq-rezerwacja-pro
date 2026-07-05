<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function delete_logo_security_event(string $eventKey, string $reason, int $responseStatus = 200, string $result = 'success', string $severity = 'medium', string $stage = ''): void
{
    $details = ['reason' => $reason];
    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'system_logo_delete',
        'endpoint' => '/api/system/delete-logo-front.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'actor_type' => 'tenant_user',
        'tenant_id' => (string) ($_SESSION['user']['tenant_id'] ?? ''),
        'user_id' => (string) ($_SESSION['user']['id'] ?? ''),
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ]);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function delete_logo_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    delete_logo_json(405, ['success' => false, 'error' => 'Metoda niedozwolona.']);
}

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || empty($sessionUser['id']) || empty($sessionUser['tenant_id'])) {
    delete_logo_json(401, ['success' => false, 'error' => 'Brak autoryzacji.']);
}

$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if (!in_array($role, ['admin', 'administrator'], true)) {
    delete_logo_json(403, ['success' => false, 'error' => 'Brak uprawnień administratora.']);
}

$tenantId = (string) $sessionUser['tenant_id'];
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId) !== 1) {
    delete_logo_json(400, ['success' => false, 'error' => 'Nieprawidłowe dane konta.']);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    delete_logo_json(500, ['success' => false, 'error' => 'Brak konfiguracji usługi.']);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    delete_logo_json(401, ['success' => false, 'error' => 'Sesja nie pasuje do domeny.']);
}

$url = $supabaseUrl . '/rest/v1/tenant_branding?tenant_id=eq.' . rawurlencode($tenantId);
$payload = json_encode(['logo_url_front' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

if ($response === false || $curlError || $httpCode < 200 || $httpCode >= 300) {
    delete_logo_security_event('system_logo_delete_failed', 'supabase_patch_failed', 500, 'failed', 'medium', 'supabase_patch');
    delete_logo_json(500, ['success' => false, 'error' => 'Nie udało się usunąć logo z brandingu.']);
}

$baseDir = realpath(__DIR__ . '/../../html');
if ($baseDir !== false) {
    $storageRootReal = realpath($baseDir . '/data/logo');
    $targetDirReal = realpath($baseDir . '/data/logo/' . $tenantId);
    if ($storageRootReal !== false
        && $targetDirReal !== false
        && $targetDirReal === $storageRootReal . DIRECTORY_SEPARATOR . $tenantId) {
        foreach (['logo-front.png', 'logo-front.jpg', 'logo-front.jpeg', 'logo-front.webp'] as $fileName) {
            $filePath = $targetDirReal . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }
}

delete_logo_security_event('system_logo_delete_success', 'system_logo_delete_success', 200, 'success', 'medium');

delete_logo_json(200, [
    'success' => true,
    'message' => 'Logo usunięte.',
    'logo_url_front' => null,
]);
