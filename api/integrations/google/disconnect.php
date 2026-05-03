<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/session.php';
require_once __DIR__ . '/../../helpers/google_calendar.php';

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function google_disconnect_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    google_disconnect_response([
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    google_disconnect_response([
        'success' => false,
        'error' => 'Niezalogowany.'
    ], 401);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];

if ($tenantId === '') {
    google_disconnect_response([
        'success' => false,
        'error' => 'Brak tenant_id w sesji.'
    ], 401);
}

try {
    if (!function_exists('google_calendar_disconnect')) {
        google_disconnect_response([
            'success' => false,
            'error' => 'Funkcja odłączania Google Calendar nie jest jeszcze podłączona.'
        ], 501);
    }

    $result = google_calendar_disconnect($tenantId);

    if (is_array($result) && isset($result['success']) && $result['success'] === false) {
        google_disconnect_response($result, 500);
    }

    google_disconnect_response([
        'success' => true,
        'message' => 'Integracja Google Calendar została odłączona.'
    ]);
} catch (Throwable $e) {
    google_disconnect_response([
        'success' => false,
        'error' => 'Nie udało się odłączyć integracji Google Calendar.'
    ], 500);
}