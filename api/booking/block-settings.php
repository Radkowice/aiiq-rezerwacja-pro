<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$SUPABASE_URL = rtrim(getenv('SUPABASE_URL') ?: '', '/');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$SUPABASE_DB_SCHEMA = getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro';

function block_settings_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function block_settings_security_event(string $eventKey, array $context = []): void
{
    $reason = isset($context['reason']) && is_scalar($context['reason'])
        ? trim((string) $context['reason'])
        : $eventKey;

    if ($reason === '') {
        $reason = $eventKey;
    }

    $details = [
        'reason' => $reason,
    ];

    if (isset($context['stage']) && is_scalar($context['stage'])) {
        $stage = trim((string) $context['stage']);
        if ($stage !== '') {
            $details['stage'] = $stage;
        }
    }

    $eventContext = [
        'action_key' => 'booking_block_settings',
        'endpoint' => '/api/booking/block-settings.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'actor_type' => isset($context['actor_type']) && is_scalar($context['actor_type'])
            ? (string) $context['actor_type']
            : 'tenant_user',
        'severity' => isset($context['severity']) && is_scalar($context['severity'])
            ? (string) $context['severity']
            : 'medium',
        'response_status' => isset($context['response_status']) ? (int) $context['response_status'] : null,
        'result' => isset($context['result']) && is_scalar($context['result'])
            ? (string) $context['result']
            : 'failed',
        'details' => $details,
    ];

    if (isset($context['tenant_id']) && is_scalar($context['tenant_id'])) {
        $tenantId = trim((string) $context['tenant_id']);
        if ($tenantId !== '') {
            $eventContext['tenant_id'] = $tenantId;
        }
    }

    try {
        security_log_event($eventKey, $eventContext);
    } catch (Throwable $exception) {
        // Audyt security nie może przerwać zapisu/odczytu ustawień blokad.
    }
}

function block_settings_supabase_request(
    string $method,
    string $url,
    string $serviceRoleKey,
    string $schema,
    ?array $payload = null,
    array $extraHeaders = []
): array {
    $headers = [
        'apikey: ' . $serviceRoleKey,
        'Authorization: Bearer ' . $serviceRoleKey,
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ];

    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($error !== '') {
        return [
            'ok' => false,
            'status' => 500,
            'data' => null,
            'error' => $error,
        ];
    }

    $data = json_decode((string) $body, true);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => is_array($data) ? $data : null,
        'error' => $body,
    ];
}

if ($SUPABASE_URL === '' || $SUPABASE_KEY === '') {
    block_settings_security_event('booking_block_settings_env_missing', [
        'actor_type' => 'public',
        'severity' => 'high',
        'response_status' => 500,
        'result' => 'error',
        'reason' => 'env_missing',
    ]);

    block_settings_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase',
    ], 500);
}

if ($method === 'GET') {
    $tenantId = getTenantIdFromHost($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA);

    if (!$tenantId) {
        block_settings_security_event('booking_block_settings_tenant_denied', [
            'actor_type' => 'public',
            'severity' => 'medium',
            'response_status' => 400,
            'result' => 'failed',
            'reason' => 'tenant_denied',
            'stage' => 'get',
        ]);

        block_settings_json([
            'success' => false,
            'error' => 'Nie udało się ustalić tenant po domenie',
        ], 400);
    }

    $url = $SUPABASE_URL
        . '/rest/v1/block_settings?select=block_saturdays,block_sundays,block_holidays'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';

    $result = block_settings_supabase_request(
        'GET',
        $url,
        $SUPABASE_KEY,
        $SUPABASE_DB_SCHEMA
    );

    if (!$result['ok']) {
        block_settings_security_event('booking_block_settings_fetch_failed', [
            'actor_type' => 'public',
            'tenant_id' => (string) $tenantId,
            'severity' => 'medium',
            'response_status' => $result['status'] ?: 500,
            'result' => 'failed',
            'reason' => 'fetch_failed',
            'stage' => 'get',
        ]);

        block_settings_json([
            'success' => false,
            'error' => 'Nie udało się pobrać ustawień blokad',
        ], $result['status'] ?: 500);
    }

    $settings = $result['data'][0] ?? null;

    block_settings_json([
        'success' => true,
        'data' => $settings,
    ]);
}

if ($method !== 'POST') {
    block_settings_security_event('booking_block_settings_method_not_allowed', [
        'actor_type' => 'public',
        'severity' => 'low',
        'response_status' => 405,
        'result' => 'failed',
        'reason' => 'method_not_allowed',
    ]);

    header('Allow: GET, POST');
    block_settings_json([
        'success' => false,
        'error' => 'Metoda niedozwolona',
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    block_settings_security_event('booking_block_settings_unauthorized', [
        'actor_type' => 'tenant_user',
        'severity' => 'medium',
        'response_status' => 401,
        'result' => 'denied',
        'reason' => 'unauthorized',
        'stage' => 'post',
    ]);

    block_settings_json([
        'success' => false,
        'error' => 'Brak autoryzacji',
    ], 401);
}

if (!session_tenant_matches_current_host($SUPABASE_URL, $SUPABASE_KEY, $SUPABASE_DB_SCHEMA)) {
    block_settings_security_event('booking_block_settings_session_invalid', [
        'actor_type' => 'tenant_user',
        'severity' => 'medium',
        'response_status' => 401,
        'result' => 'denied',
        'reason' => 'session_invalid',
        'stage' => 'post',
    ]);

    block_settings_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny',
    ], 401);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

foreach (['block_saturdays', 'block_sundays', 'block_holidays'] as $field) {
    if (array_key_exists($field, $data) && !is_bool($data[$field])) {
        block_settings_security_event('booking_block_settings_validation_failed', [
            'actor_type' => 'tenant_user',
            'tenant_id' => $tenantId,
            'severity' => 'medium',
            'response_status' => 400,
            'result' => 'failed',
            'reason' => 'validation_failed',
            'stage' => 'post',
        ]);

        block_settings_json([
            'success' => false,
            'error' => 'Nieprawidłowe dane wejściowe',
        ], 400);
    }
}

$payload = [
    'tenant_id' => $tenantId,
    'block_saturdays' => (bool) ($data['block_saturdays'] ?? false),
    'block_sundays' => (bool) ($data['block_sundays'] ?? false),
    'block_holidays' => (bool) ($data['block_holidays'] ?? false),
];

$url = $SUPABASE_URL . '/rest/v1/block_settings?on_conflict=tenant_id';

$result = block_settings_supabase_request(
    'POST',
    $url,
    $SUPABASE_KEY,
    $SUPABASE_DB_SCHEMA,
    $payload,
    [
        'Prefer: resolution=merge-duplicates,return=representation',
    ]
);

if (!$result['ok']) {
    block_settings_security_event('booking_block_settings_save_failed', [
        'actor_type' => 'tenant_user',
        'tenant_id' => $tenantId,
        'severity' => 'medium',
        'response_status' => $result['status'] ?: 500,
        'result' => 'failed',
        'reason' => 'save_failed',
        'stage' => 'post',
    ]);

    block_settings_json([
        'success' => false,
        'error' => 'Nie udało się zapisać ustawień blokad',
    ], $result['status'] ?: 500);
}

$saved = $result['data'][0] ?? null;

if (is_array($saved)) {
    unset(
        $saved['id'],
        $saved['tenant_id'],
        $saved['created_at'],
        $saved['updated_at']
    );
}

block_settings_security_event('booking_block_settings_save_success', [
    'actor_type' => 'tenant_user',
    'tenant_id' => $tenantId,
    'severity' => 'medium',
    'response_status' => 200,
    'result' => 'success',
    'reason' => 'save_success',
    'stage' => 'post',
]);

block_settings_json([
    'success' => true,
    'data' => $saved,
]);
