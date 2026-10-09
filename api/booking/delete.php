<?php
declare(strict_types=1);

/** P0/01 - REVIEW ONLY. Safe replacement for legacy physical DELETE.
 * Requires frontend CAS fields, DB migration, verified workers and active DB gate.
 * Disabled by default. Never falls back to DELETE.
 */
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function p001_response(int $status, string $error, ?string $message = null): void
{
    http_response_code($status);
    $body = ['success' => $error === ''];
    if ($error !== '') { $body['error'] = $error; }
    if ($message !== null) { $body['message'] = $message; }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function p001_log(string $event, int $status, string $tenantId): void
{
    security_log_event($event, [
        'action_key' => 'booking_delete',
        'endpoint' => '/api/booking/delete.php',
        'http_method' => 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $status >= 500 ? 'high' : 'medium',
        'response_status' => $status,
        'result' => $status === 200 ? 'success' : 'failed',
        'tenant_id' => $tenantId,
        'details' => ['stage' => 'cancel_rpc'],
    ]);
}

/** Internal, tenant-scoped, bounded REST read; never returns data to client. */
function p001_get_rows(string $base, string $key, string $schema, string $table, array $params): ?array
{
    if (!in_array($table, ['users', 'bookings'], true)) { return null; }
    if (!function_exists('curl_init')) { return null; }
    $ch = curl_init($base . '/rest/v1/' . $table . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    if ($ch === false) { return null; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
            'Accept-Profile: ' . $schema,
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $err !== '' || $http < 200 || $http >= 300) { return null; }
    $data = json_decode($body, true);
    return is_array($data) && array_is_list($data) ? $data : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    p001_response(405, 'method_not_allowed');
}

$sessionUser = $_SESSION['user'] ?? null;
$tenantId = is_array($sessionUser) ? (string)($sessionUser['tenant_id'] ?? '') : '';
$actorId = is_array($sessionUser) ? (string)($sessionUser['id'] ?? '') : '';
if ($tenantId === '' || $actorId === ''
    || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $actorId) !== 1) {
    p001_response(401, 'unauthorized');
}

require_csrf_token();

// A second, independent cutover switch; SQL gate defaults to disabled too.
if (getenv('REZERWIA_P001_CANCEL_ENDPOINT_ENABLED') !== '1') {
    p001_response(503, 'cancellation_unavailable');
}

$base = rtrim(trim((string)getenv('SUPABASE_URL')), '/');
$key = trim((string)getenv('SUPABASE_SERVICE_ROLE_KEY'));
$schema = trim((string)(getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro'));
if ($base === '' || $key === '' || $schema !== 'rezerwacja_pro') {
    p001_response(503, 'cancellation_unavailable');
}
if (!session_tenant_matches_current_host($base, $key, $schema)) {
    p001_response(401, 'unauthorized');
}

$limit = security_rate_limit_check('booking_delete', [
    'tenant_id' => $tenantId, 'user_id' => $actorId,
    'session_id' => session_id(),
], ['endpoint' => '/api/booking/delete.php', 'http_method' => 'POST']);
// Critical destructive-equivalent action: fail closed if rate limiter fails.
if (empty($limit['ok'])) { p001_response(503, 'cancellation_unavailable'); }
if (empty($limit['allowed'])) { p001_response(429, 'rate_limited'); }

// Recheck role & activity NOW. SQL RPC independently rechecks it inside TX.
$users = p001_get_rows($base, $key, $schema, 'users', [
    'select' => 'id,tenant_id,role,is_active',
    'tenant_id' => 'eq.' . $tenantId,
    'id' => 'eq.' . $actorId,
    'limit' => '2',
]);
if ($users === null) { p001_response(503, 'cancellation_unavailable'); }
if (count($users) !== 1 || ($users[0]['is_active'] ?? null) !== true
    || ($users[0]['role'] ?? '') !== 'administrator'
    || ($users[0]['tenant_id'] ?? '') !== $tenantId) {
    p001_log('booking_delete_role_denied', 403, $tenantId);
    p001_response(403, 'forbidden');
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4096) { p001_response(413, 'invalid_request'); }
$raw = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($raw) || strlen($raw) > 4096) { p001_response(400, 'invalid_request'); }
$input = json_decode($raw, true);
if (!is_array($input) || array_is_list($input)) { p001_response(400, 'invalid_request'); }
// Client does not control actor/tenant/booking UUID. Reject unknown identity fields.
foreach (['tenant_id', 'actor_user_id', 'id', 'booking_id'] as $forbidden) {
    if (array_key_exists($forbidden, $input)) { p001_response(400, 'invalid_request'); }
}
$ref = $input['booking_ref'] ?? null;
$date = $input['expected_booking_date'] ?? null;
$time = $input['expected_booking_time'] ?? null;
$revision = $input['expected_revision'] ?? null;
$staffRef = $input['expected_staff_ref'] ?? null;
$keyId = $input['idempotency_key'] ?? null;
if (!is_string($ref) || preg_match('/\Abk_[0-9a-f]{48}\z/D', $ref) !== 1
    || !is_string($date) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) !== 1
    || !is_string($time) || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $time) !== 1
    || !is_int($revision) || $revision < 0 || $revision > 3
    || !is_string($staffRef) || ($staffRef !== '' && preg_match('/\Ast_[0-9a-f]{48}\z/D', $staffRef) !== 1)
    || !is_string($keyId)
    || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $keyId) !== 1
    || DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
    p001_response(400, 'invalid_request');
}

try { $secret = public_response_ref_secret($key); }
catch (Throwable $e) { p001_response(503, 'cancellation_unavailable'); }

// Scope by tenant AND confirmed date, paginate; no silent first-200 truncation.
// Very large days are intentionally rejected rather than matched ambiguously.
$matched = null;
$maxPages = 100;
$perPage = 200;
for ($page = 0; $page < $maxPages; $page++) {
    $rows = p001_get_rows($base, $key, $schema, 'bookings', [
        'select' => 'id,tenant_id,booking_date,booking_time,reschedule_count,staff_id,email',
        'tenant_id' => 'eq.' . $tenantId,
        'booking_date' => 'eq.' . $date,
        'order' => 'id.asc',
        'limit' => (string)$perPage,
        'offset' => (string)($page * $perPage),
    ]);
    if ($rows === null) { p001_response(503, 'cancellation_unavailable'); }
    foreach ($rows as $row) {
        $candidateId = (string)($row['id'] ?? '');
        if ($candidateId === '' || ($row['tenant_id'] ?? '') !== $tenantId) {
            p001_response(503, 'cancellation_unavailable');
        }
        $candidateRef = public_response_booking_ref($tenantId, $candidateId, $secret);
        if (hash_equals($candidateRef, $ref)) {
            $matched = $row;
            break 2;
        }
    }
    if (count($rows) < $perPage) { break; }
}
if ($matched === null) {
    if ($page === $maxPages) { p001_response(503, 'cancellation_unavailable'); }
    p001_response(404, 'booking_not_found');
}
$email = strtolower(trim((string)($matched['email'] ?? '')));
if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || preg_match('/[\r\n\x00]/', $email) === 1) {
    p001_response(409, 'notification_recipient_invalid');
}
$bookingId = (string)($matched['id'] ?? '');
$bookingTime = substr((string)($matched['booking_time'] ?? ''), 0, 5);
$currentStaffId = $matched['staff_id'] ?? null;
$currentStaffRef = is_string($currentStaffId) && $currentStaffId !== ''
    ? public_response_staff_ref($tenantId, $currentStaffId, $secret) : '';
if (($matched['booking_date'] ?? '') !== $date || $bookingTime !== $time
    || ($matched['reschedule_count'] ?? null) !== $revision
    || !hash_equals($currentStaffRef, $staffRef)) {
    p001_response(409, 'booking_changed');
}

$rpc = payment_lifecycle_v3_rpc('booking_legacy_free_cancel_apply', [
    'p_tenant_id' => $tenantId,
    'p_booking_id' => $bookingId,
    'p_actor_user_id' => $actorId,
    'p_idempotency_key' => $keyId,
    'p_expected_booking_date' => $date,
    'p_expected_booking_time' => $time,
    'p_expected_revision' => $revision,
    'p_expected_staff_id' => $currentStaffId === '' ? null : $currentStaffId,
    'p_expected_email' => $email,
]);
if (empty($rpc['ok']) || !is_array($rpc['data'] ?? null)) {
    p001_log('booking_cancel_rpc_failed', 503, $tenantId);
    p001_response(503, 'cancellation_unavailable');
}
$result = $rpc['data'];
if (!is_bool($result['applied'] ?? null)
    || !is_bool($result['idempotent'] ?? null)
    || !in_array($result['notification'] ?? null, ['queued', 'previously_enqueued'], true)
    || (($result['applied'] ?? null) === ($result['idempotent'] ?? null))) {
    p001_log('booking_cancel_contract_invalid', 503, $tenantId);
    p001_response(503, 'cancellation_unavailable');
}
p001_log('booking_cancel_success', 200, $tenantId);
p001_response(200, '', 'Rezerwacja została anulowana. Powiadomienie e-mail przekazano do kolejki wysyłki.');
