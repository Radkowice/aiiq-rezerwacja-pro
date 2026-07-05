<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/session.php';
require_once __DIR__ . '/../../helpers/google_calendar.php';
require_once __DIR__ . '/../../helpers/security.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function google_disconnect_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus,
    string $result = 'failed',
    string $severity = 'medium',
    array $context = []
): void {
    $details = [
        'reason' => $reason,
    ];

    if (isset($context['stage']) && is_scalar($context['stage']) && trim((string) $context['stage']) !== '') {
        $details['stage'] = trim((string) $context['stage']);
    }

    security_log_event($eventKey, [
        'action_key' => 'google_calendar_disconnect',
        'endpoint' => '/api/integrations/google/disconnect.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'tenant_id' => $context['tenant_id'] ?? ($_SESSION['user']['tenant_id'] ?? null),
        'user_id' => $context['user_id'] ?? ($_SESSION['user']['id'] ?? null),
        'details' => $details,
    ]);
}

function google_disconnect_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    google_disconnect_security_event('google_calendar_disconnect_method_not_allowed', 'method_not_allowed', 405);

    google_disconnect_response([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    google_disconnect_security_event('google_calendar_disconnect_unauthorized', 'unauthorized', 401);

    google_disconnect_response([
        'success' => false,
        'error' => 'Niezalogowany.'
    ], 401);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$userId = (string) $_SESSION['user']['id'];

if ($tenantId === '') {
    google_disconnect_security_event('google_calendar_disconnect_session_invalid', 'session_invalid', 401);

    google_disconnect_response([
        'success' => false,
        'error' => 'Brak danych sesji.'
    ], 401);
}

try {
    if (!function_exists('google_calendar_disconnect')) {
        google_disconnect_security_event('google_calendar_disconnect_function_missing', 'function_missing', 501, 'error', 'high', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);

        google_disconnect_response([
            'success' => false,
            'error' => 'Funkcja odłączania Google Calendar nie jest jeszcze podłączona.'
        ], 501);
    }

    $result = google_calendar_disconnect($tenantId);

    if (is_array($result) && isset($result['success']) && $result['success'] === false) {
        google_disconnect_security_event('google_calendar_disconnect_failed', 'disconnect_failed', 500, 'error', 'high', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);

        google_disconnect_response($result, 500);
    }

    google_disconnect_security_event('google_calendar_disconnect_success', 'google_calendar_disconnect_success', 200, 'success', 'medium', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
    ]);

    google_disconnect_response([
        'success' => true,
        'message' => 'Integracja Google Calendar została odłączona.'
    ]);
} catch (Throwable $e) {
    google_disconnect_security_event('google_calendar_disconnect_fatal', 'fatal', 500, 'error', 'critical', [
        'tenant_id' => $tenantId ?? null,
        'user_id' => $userId ?? null,
    ]);

    google_disconnect_response([
        'success' => false,
        'error' => 'Nie udało się odłączyć integracji Google Calendar.'
    ], 500);
}
