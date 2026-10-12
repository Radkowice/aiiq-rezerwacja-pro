<?php
declare(strict_types=1);
/** READ ONLY: server-side PayU status observation for an approved review.
 *  Never issues a cancel or refund; never writes a payment state.
 */
require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/payu.php';
require_once __DIR__ . '/../system/tenant.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function review_payu_reply(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); review_payu_reply(405, ['success' => false]);
}
$sessionUser = $_SESSION['user'] ?? null;
$tenant = is_array($sessionUser) ? ($sessionUser['tenant_id'] ?? null) : null;
$actor = is_array($sessionUser) ? ($sessionUser['id'] ?? null) : null;
if (!is_string($tenant) || $tenant === '' || strlen($tenant) > 128
    || !is_string($actor) || !payu_valid_uuid($actor)) {
    review_payu_reply(401, ['success' => false]);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 512) review_payu_reply(413, ['success' => false]);
$raw = file_get_contents('php://input', false, null, 0, 513);
$data = is_string($raw) && strlen($raw) <= 512 ? json_decode($raw, true) : null;
if (!is_array($data) || array_is_list($data) || count($data) !== 1
    || !is_string($data['request_ref'] ?? null)
    || preg_match('/\Acr_[0-9a-f]{32}\z/D', $data['request_ref']) !== 1) {
    review_payu_reply(400, ['success' => false]);
}
try {
    require_csrf_token();
    $cfg = payment_lifecycle_v3_config();
    if (!session_tenant_matches_current_host($cfg['url'], $cfg['service_role_key'], $cfg['schema'])) {
        review_payu_reply(401, ['success' => false]);
    }
    $rate = security_rate_limit_check('booking_cancel_review_payu_lookup', [
        'tenant_id' => $tenant, 'user_id' => $actor,
        'session_id' => session_id(),
    ], ['endpoint' => '/api/booking/cancel-review-payu-status.php', 'http_method' => 'POST']);
    if (empty($rate['ok'])) review_payu_reply(503, ['success' => false]);
    if (empty($rate['allowed'])) review_payu_reply(429, ['success' => false]);
    $context = payment_lifecycle_v3_rpc('booking_cancel_review_payu_context', [
        'p_tenant_id' => $tenant,
        'p_admin_user_id' => $actor,
        'p_request_ref' => $data['request_ref'],
    ]);
    if (empty($context['ok']) || !is_array($context['data'] ?? null)) {
        review_payu_reply(409, ['success' => false, 'error' => 'provider_verification_unavailable']);
    }
    $p = $context['data'];
    $paymentId = $p['payment_id'] ?? null;
    $versionId = $p['config_version_id'] ?? null;
    $orderId = $p['order_id'] ?? null;
    if (!is_string($paymentId) || !payu_valid_uuid($paymentId)
        || !is_string($versionId) || !payu_valid_uuid($versionId)
        || !is_string($orderId) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $orderId)) {
        review_payu_reply(503, ['success' => false]);
    }
    $integration = payu_get_bound_integration($tenant, $paymentId, $versionId);
    if (!is_array($integration)) review_payu_reply(503, ['success' => false]);
    // A single provider read only. No automatic retry of an unknown result.
    $observed = payu_retrieve_order($integration, $orderId);
    if (($observed['success'] ?? false) !== true
        || ($observed['result_kind'] ?? '') !== 'provider_result'
        || ($observed['order_id'] ?? null) !== $orderId
        || ($observed['ext_order_id'] ?? null) !== ($p['ext_order_id'] ?? null)
        || ($observed['amount_minor'] ?? null) !== ($p['amount_minor'] ?? null)
        || ($observed['currency'] ?? null) !== ($p['currency'] ?? null)) {
        review_payu_reply(503, ['success' => false, 'error' => 'provider_result_unresolved']);
    }
    review_payu_reply(200, [
        'success' => true,
        'provider_status' => $observed['payu_status'] ?? 'UNKNOWN',
        'booking_cancelled' => false,
        'refund_executed' => false,
        'message' => 'Status operatora odczytano. Nie wykonano anulowania ani zwrotu.',
    ]);
} catch (Throwable $e) {
    review_payu_reply(503, ['success' => false]);
}
