<?php
declare(strict_types=1);
/** Administrative review of customer cancellation requests.
 *  An accepted eligible unpaid legacy request may atomically cancel via DB RPC.
 *  Paid bookings are NEVER cancelled or refunded by this endpoint.
 */
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cancel_admin_reply(int $status, array $response): never
{
    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST');
    cancel_admin_reply(405, ['success' => false, 'error' => 'method_not_allowed']);
}
$sessionUser = $_SESSION['user'] ?? null;
$tenantId = is_array($sessionUser) ? ($sessionUser['tenant_id'] ?? '') : '';
$actorId = is_array($sessionUser) ? ($sessionUser['id'] ?? '') : '';
if (!is_string($tenantId) || !is_string($actorId)
    || $tenantId === '' || strlen($tenantId) > 128
    || preg_match('/\A[0-9a-f-]{36}\z/iD', $actorId) !== 1) {
    cancel_admin_reply(401, ['success' => false, 'error' => 'unauthorized']);
}
try {
    $config = payment_lifecycle_v3_config();
    if (!session_tenant_matches_current_host(
        $config['url'], $config['service_role_key'], $config['schema']
    )) {
        cancel_admin_reply(401, ['success' => false, 'error' => 'unauthorized']);
    }
    $rateLimit = security_rate_limit_check('booking_cancel_admin_review', [
        'tenant_id' => $tenantId,
        'user_id' => $actorId,
        'session_id' => session_id(),
    ], ['endpoint' => '/api/booking/cancel-review-admin.php', 'http_method' => $method]);
    if (empty($rateLimit['ok'])) {
        cancel_admin_reply(503, ['success' => false, 'error' => 'temporarily_unavailable']);
    }
    if (empty($rateLimit['allowed'])) {
        cancel_admin_reply(429, ['success' => false, 'error' => 'rate_limited']);
    }
    if ($method === 'GET') {
        $result = payment_lifecycle_v3_rpc('booking_cancel_review_admin_list', [
            'p_tenant_id' => $tenantId,
            'p_admin_user_id' => $actorId,
            'p_limit' => 30,
        ]);
        if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
            cancel_admin_reply(403, ['success' => false, 'error' => 'not_available']);
        }
        $items = $result['data']['requests'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            cancel_admin_reply(503, ['success' => false, 'error' => 'temporarily_unavailable']);
        }
        cancel_admin_reply(200, ['success' => true, 'requests' => $items]);
    }
    require_csrf_token();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) {
        cancel_admin_reply(413, ['success' => false, 'error' => 'invalid_request']);
    }
    $body = file_get_contents('php://input', false, null, 0, 2049);
    $input = is_string($body) && strlen($body) <= 2048
        ? json_decode($body, true) : null;
    if (!is_array($input) || array_is_list($input) || count($input) !== 3
        || array_diff(array_keys($input), ['request_ref','decision','idempotency_key'])) {
        cancel_admin_reply(400, ['success' => false, 'error' => 'invalid_request']);
    }
    $ref = $input['request_ref'] ?? null;
    $decision = $input['decision'] ?? null;
    $key = $input['idempotency_key'] ?? null;
    if (!is_string($ref) || preg_match('/\Acr_[a-f0-9]{32}\z/D', $ref) !== 1
        || !in_array($decision, ['reject','accept_for_review'], true)
        || !is_string($key)
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $key) !== 1) {
        cancel_admin_reply(400, ['success' => false, 'error' => 'invalid_request']);
    }
    $result = payment_lifecycle_v3_rpc('booking_cancel_review_admin_decide', [
        'p_tenant_id' => $tenantId,
        'p_admin_user_id' => $actorId,
        'p_request_ref' => $ref,
        'p_decision' => $decision,
        'p_idempotency_key' => $key,
    ]);
    if (empty($result['ok']) || ($result['data']['updated'] ?? false) !== true
        || !is_bool($result['data']['booking_cancelled'] ?? null)) {
        cancel_admin_reply(409, ['success' => false, 'error' => 'review_conflict']);
    }
    cancel_admin_reply(200, [
        'success' => true,
        'booking_cancelled' => $result['data']['booking_cancelled'],
        'review_status' => $result['data']['review_status'] ?? 'pending_review',
        'message' => ($result['data']['booking_cancelled'] === true
            ? 'Bezpłatna rezerwacja została anulowana i zapisano powiadomienie.'
            : 'Decyzja została zapisana; dla płatności wymagany jest dalszy proces weryfikacji.'),
    ]);
} catch (Throwable $e) {
    cancel_admin_reply(503, ['success' => false, 'error' => 'temporarily_unavailable']);
}
