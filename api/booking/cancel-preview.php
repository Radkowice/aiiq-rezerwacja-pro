<?php
declare(strict_types=1);
/** Minimal, public management-token preview — no booking mutation. */
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
function cancel_preview_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cancel_preview_reply(405, ['success' => false]);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024) {
    cancel_preview_reply(413, ['success' => false]);
}
$raw = file_get_contents('php://input', false, null, 0, 1025);
$input = is_string($raw) && strlen($raw) <= 1024 ? json_decode($raw, true) : null;
if (!is_array($input) || array_is_list($input) || count($input) !== 1
    || !is_string($input['token'] ?? null)
    || preg_match('/\A[a-f0-9]{64}\z/iD', $input['token']) !== 1) {
    cancel_preview_reply(400, ['success' => false]);
}
$token = $input['token'];
try {
    $cfg = payment_lifecycle_v3_config();
    $tenant = getTenantIdFromHost($cfg['url'], $cfg['service_role_key'], $cfg['schema']);
    if (!is_string($tenant) || $tenant === '') cancel_preview_reply(404, ['success' => false]);
    $rate = security_rate_limit_check('booking_cancel_preview', [
        'tenant_id' => $tenant,
        'token_fingerprint' => hash_hmac('sha256', $token, $cfg['service_role_key']),
    ], ['endpoint' => '/api/booking/cancel-preview.php', 'http_method' => 'POST']);
    if (empty($rate['ok'])) cancel_preview_reply(503, ['success' => false]);
    if (empty($rate['allowed'])) cancel_preview_reply(429, ['success' => false]);
    $params = http_build_query([
       'select' => 'booking_date,booking_time,status,payment_required,manage_token_expires_at',
       'tenant_id' => 'eq.' . $tenant,
       'manage_token' => 'eq.' . $token,
       'limit' => '2',
    ], '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($cfg['url'] . '/rest/v1/bookings?' . $params);
    if ($ch === false) cancel_preview_reply(503, ['success' => false]);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
           'apikey: ' . $cfg['service_role_key'],
           'Authorization: Bearer ' . $cfg['service_role_key'],
           'Accept: application/json',
           'Accept-Profile: ' . $cfg['schema'],
        ],
    ]);
    $response = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $rows = is_string($response) && $http === 200 ? json_decode($response, true) : null;
    if (!is_array($rows) || !array_is_list($rows)) cancel_preview_reply(503, ['success' => false]);
    if (count($rows) !== 1) cancel_preview_reply(404, ['success' => false]);
    $r = $rows[0];
    $expiry = strtotime((string)($r['manage_token_expires_at'] ?? ''));
    if ($expiry === false || $expiry <= time()
        || !in_array($r['status'] ?? '', ['new','confirmed','pending_payment'], true)) {
        cancel_preview_reply(404, ['success' => false]);
    }
    $date = $r['booking_date'] ?? null;
    $time = is_string($r['booking_time'] ?? null) ? substr($r['booking_time'], 0, 5) : null;
    if (!is_string($date) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) !== 1
        || !is_string($time) || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $time) !== 1) {
        cancel_preview_reply(503, ['success' => false]);
    }
    cancel_preview_reply(200, [
        'success' => true,
        'booking_date' => $date,
        'booking_time' => $time,
        'payment_required' => $r['payment_required'] === true,
        'booking_cancelled' => false,
    ]);
} catch (Throwable $e) {
    cancel_preview_reply(503, ['success' => false]);
}
