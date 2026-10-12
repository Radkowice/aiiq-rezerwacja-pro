<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

function cancel_review_reply(int $http, string $code, array $more=[]): never {
    http_response_code($http);
    echo json_encode(array_merge(['success'=>false,'code'=>$code],$more),
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    header('Allow: POST'); cancel_review_reply(405,'method_not_allowed');
}
$raw=file_get_contents('php://input',false,null,0,2049);
if (!is_string($raw) || strlen($raw)>2048) cancel_review_reply(400,'invalid_request');
$input=json_decode($raw,true);
if (!is_array($input) || array_is_list($input) || count($input)!==3
    || array_diff(array_keys($input),['token','expected_booking_date','expected_booking_time'])) {
    cancel_review_reply(400,'invalid_request');
}
$token=$input['token']??null;
$date=$input['expected_booking_date']??null;
$time=$input['expected_booking_time']??null;
if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/iD',$token)!==1
    || !is_string($date) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date)!==1
    || DateTimeImmutable::createFromFormat('!Y-m-d',$date)?->format('Y-m-d')!==$date
    || !is_string($time) || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D',$time)!==1) {
    cancel_review_reply(400,'invalid_request');
}
// Never trust tenant/booking ID from browser: tenant comes from verified Host.
try {
    $cfg=payment_lifecycle_v3_config();
    $tenant=getTenantIdFromHost($cfg['url'],$cfg['service_role_key'],$cfg['schema']);
    if (!is_string($tenant) || $tenant==='') cancel_review_reply(404,'not_found');
    $limit=security_rate_limit_check('booking_cancel_review_request',[
        'tenant_id'=>$tenant,
        'token_fingerprint'=>hash_hmac('sha256',$token,$cfg['service_role_key']),
    ],['endpoint'=>'/api/booking/cancel-request.php','http_method'=>'POST']);
    if (empty($limit['ok'])) cancel_review_reply(503,'temporarily_unavailable');
    if (empty($limit['allowed'])) cancel_review_reply(429,'rate_limited');
    // Minimal tenant+token lookup. SQL RPC independently validates live token and booking state.
    $url=$cfg['url'].'/rest/v1/bookings?select=id,booking_date,booking_time,reschedule_count,staff_id'
      .'&tenant_id=eq.'.rawurlencode($tenant)
      .'&manage_token=eq.'.rawurlencode($token).'&limit=2';
    $ch=curl_init($url);
    if ($ch===false) cancel_review_reply(503,'temporarily_unavailable');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
       CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>[
        'apikey: '.$cfg['service_role_key'],'Authorization: Bearer '.$cfg['service_role_key'],
        'Accept: application/json','Accept-Profile: '.$cfg['schema']]]);
    $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $rows=is_string($body)&&$http===200 ? json_decode($body,true) : null;
    if (!is_array($rows) || !array_is_list($rows)) cancel_review_reply(503,'temporarily_unavailable');
    if (count($rows)!==1) cancel_review_reply(404,'not_found');
    $booking=$rows[0];
    $id=$booking['id']??null;
    if (!is_string($id)||!preg_match('/\A[0-9a-f-]{36}\z/iD',$id)) cancel_review_reply(503,'temporarily_unavailable');
    if (($booking['booking_date']??null)!==$date || substr((string)($booking['booking_time']??''),0,5)!==$time) {
        cancel_review_reply(409,'state_changed');
    }
    // Stable per-token+schedule key; retries do not create duplicate requests.
    $idemMaterial=$tenant.'|'.$id.'|'.$date.'|'.$time.'|'
        .(string)($booking['reschedule_count']??'').'|'
        .(string)($booking['staff_id']??'').'|cancel_review';
    $hex=hash_hmac('sha256',$idemMaterial,$cfg['service_role_key']);
    $uuid=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3)
       .'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
    $reply=payment_lifecycle_v3_rpc('booking_cancel_review_request_create',[
       'p_tenant_id'=>$tenant,'p_booking_id'=>$id,'p_manage_token'=>$token,
       'p_idempotency_key'=>$uuid,'p_expected_date'=>$date,'p_expected_time'=>$time]);
    if (empty($reply['ok']) || ($reply['data']['accepted']??null)!==true
        || ($reply['data']['booking_cancelled']??null)!==false) {
        cancel_review_reply(409,'request_not_accepted');
    }
    cancel_review_reply(200,'request_received',[
      'success'=>true,'booking_cancelled'=>false,
      'message'=>'Wniosek zapisano. Skontaktuj się z firmą, aby potwierdzić jego rozpatrzenie. Rezerwacja nadal jest aktywna.']);
} catch (Throwable $e) {
    cancel_review_reply(503,'temporarily_unavailable');
}
