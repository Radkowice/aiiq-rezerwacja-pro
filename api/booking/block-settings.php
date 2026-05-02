<?php
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();

if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST'], true) && empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once 'supabase.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {

  require_once __DIR__ . '/../system/tenant.php';
$tenantId = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA);
if (!$tenantId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się ustalić tenant po domenie'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

  $response = supabaseRequest(
    "block_settings?tenant_id=eq.$tenantId&limit=1",
    'GET'
  );

  echo json_encode([
    'success' => true,
    'data' => $response[0] ?? null
  ]);

  exit;
}
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// przygotowanie payloadu
$payload = [
  'block_weekends' => $data['block_weekends'] ?? false,
  'block_saturdays' => $data['block_saturdays'] ?? false,
  'block_sundays' => $data['block_sundays'] ?? false,
  'block_holidays' => $data['block_holidays'] ?? false,
  'holiday_overrides' => json_encode($data['holiday_overrides'] ?? []),
  'tenant_id' => $tenantId 
];

// zapis do Supabase (PostgREST)
$response = supabaseRequest(
  'block_settings?on_conflict=tenant_id',
  'POST',
  $payload,
  [
    'Prefer: resolution=merge-duplicates'
  ]
);

echo json_encode([
  'success' => true,
  'data' => $response
]);
