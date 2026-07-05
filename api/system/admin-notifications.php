<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/tenant.php';

start_secure_session();

function admin_notifications_security_event(string $eventKey, string $reason, int $responseStatus = 200, string $result = 'success', string $severity = 'medium', string $stage = ''): void
{
    $details = ['reason' => $reason];
    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'system_admin_notifications',
        'endpoint' => '/api/system/admin-notifications.php',
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

function admin_notifications_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_notifications_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    array $extraHeaders = []
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'response' => $response,
        'error' => $error,
        'httpCode' => $httpCode,
        'data' => json_decode((string) $response, true),
    ];
}

function admin_notifications_fail(string $message, array $result): void
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $details = trim((string) ($data['message'] ?? $data['details'] ?? $result['error'] ?? $result['response'] ?? ''));

    admin_notifications_json([
        'success' => false,
        'error' => $details !== '' ? $message . ': ' . substr($details, 0, 400) : $message,
        'requires_migration' => true,
    ], 500);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    admin_notifications_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.',
    ], 500);
}

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    admin_notifications_json([
        'success' => false,
        'error' => 'Brak aktywnej sesji administratora.',
    ], 401);
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');
$role = (string) ($_SESSION['user']['role'] ?? '');

if ($tenantId === '' || !in_array($role, ['admin', 'administrator'], true)) {
    admin_notifications_json([
        'success' => false,
        'error' => 'Brak uprawnień administratora.',
    ], 403);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    admin_notifications_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny.',
    ], 401);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $listUrl = $supabaseUrl
        . '/rest/v1/tenant_admin_notifications'
        . '?select=type,event_date,event_time,message,is_read,created_at,read_at'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&order=created_at.desc'
        . '&limit=20';

    $listResult = admin_notifications_request('GET', $listUrl, $supabaseKey, $schema);

    if ($listResult['response'] === false || $listResult['error'] !== '' || $listResult['httpCode'] >= 400) {
        admin_notifications_fail('Nie udało się pobrać powiadomień administratora', $listResult);
    }

    $notificationRows = is_array($listResult['data'] ?? null) ? $listResult['data'] : [];
    $notifications = [];

    foreach ($notificationRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $notifications[] = [
            'type' => (string) ($row['type'] ?? ''),
            'event_date' => $row['event_date'] ?? null,
            'event_time' => $row['event_time'] ?? null,
            'message' => (string) ($row['message'] ?? ''),
            'is_read' => ($row['is_read'] ?? false) === true,
            'created_at' => $row['created_at'] ?? null,
            'read_at' => $row['read_at'] ?? null,
        ];
    }

    $countUrl = $supabaseUrl
        . '/rest/v1/tenant_admin_notifications'
        . '?select=is_read'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_read=eq.false';

    $countResult = admin_notifications_request('GET', $countUrl, $supabaseKey, $schema);

    if ($countResult['response'] === false || $countResult['error'] !== '' || $countResult['httpCode'] >= 400) {
        admin_notifications_fail('Nie udało się policzyć nieodczytanych powiadomień', $countResult);
    }

    $unreadRows = is_array($countResult['data'] ?? null) ? $countResult['data'] : [];

    admin_notifications_json([
        'success' => true,
        'unread_count' => count($unreadRows),
        'notifications' => $notifications,
    ]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);

    if (!is_array($input)) {
        admin_notifications_json([
            'success' => false,
            'error' => 'Nieprawidłowy JSON.',
        ], 400);
    }

    $action = (string) ($input['action'] ?? '');

    if ($action !== 'mark_read') {
        admin_notifications_json([
            'success' => false,
            'error' => 'Nieznana akcja.',
        ], 400);
    }

    $url = $supabaseUrl
        . '/rest/v1/tenant_admin_notifications'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_read=eq.false';

    $result = admin_notifications_request(
        'PATCH',
        $url,
        $supabaseKey,
        $schema,
        [
            'is_read' => true,
            'read_at' => gmdate('c'),
        ],
        ['Prefer: return=minimal']
    );

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
        admin_notifications_security_event('system_admin_notifications_mark_read_failed', 'mark_read_failed', 500, 'failed', 'low', 'supabase_patch');
        admin_notifications_fail('Nie udało się oznaczyć powiadomień jako przeczytane', $result);
    }

    admin_notifications_security_event('system_admin_notifications_mark_read_success', 'mark_read_success', 200, 'success', 'low');

    admin_notifications_json([
        'success' => true,
        'unread_count' => 0,
    ]);
}

header('Allow: GET, POST');
admin_notifications_json([
    'success' => false,
    'error' => 'Metoda niedozwolona.',
], 405);
