<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';


function services_security_event(string $eventKey, array $context = []): void
{
    $details = [];

    if (isset($context['reason']) && is_scalar($context['reason'])) {
        $reason = trim((string) $context['reason']);

        if ($reason !== '') {
            $details['reason'] = $reason;
        }
    }

    if (isset($context['stage']) && is_scalar($context['stage'])) {
        $stage = trim((string) $context['stage']);

        if ($stage !== '') {
            $details['stage'] = $stage;
        }
    }

    security_log_event($eventKey, [
        'action_key' => isset($context['action_key']) && is_scalar($context['action_key']) && trim((string) $context['action_key']) !== ''
            ? trim((string) $context['action_key'])
            : 'services_manage',
        'endpoint' => isset($context['endpoint']) && is_scalar($context['endpoint']) && trim((string) $context['endpoint']) !== ''
            ? trim((string) $context['endpoint'])
            : ($_SERVER['SCRIPT_NAME'] ?? '/api/services'),
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'actor_type' => isset($context['actor_type']) && is_scalar($context['actor_type']) && trim((string) $context['actor_type']) !== ''
            ? trim((string) $context['actor_type'])
            : 'tenant_user',
        'tenant_id' => isset($context['tenant_id']) && is_scalar($context['tenant_id'])
            ? trim((string) $context['tenant_id'])
            : null,
        'user_id' => isset($context['user_id']) && is_scalar($context['user_id'])
            ? trim((string) $context['user_id'])
            : null,
        'severity' => isset($context['severity']) && is_scalar($context['severity']) && trim((string) $context['severity']) !== ''
            ? trim((string) $context['severity'])
            : 'medium',
        'response_status' => isset($context['response_status']) ? (int) $context['response_status'] : null,
        'result' => isset($context['result']) && is_scalar($context['result']) && trim((string) $context['result']) !== ''
            ? trim((string) $context['result'])
            : null,
        'details' => $details,
    ]);
}

function services_security_context(array $context, array $extra = []): array
{
    return array_merge([
        'tenant_id' => (string) ($context['tenantId'] ?? ($_SESSION['user']['tenant_id'] ?? '')),
        'user_id' => (string) ($_SESSION['user']['id'] ?? ''),
        'actor_type' => 'tenant_user',
    ], $extra);
}

function services_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(public_response_sanitize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function services_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    bool $returnRepresentation = false,
    array $extraHeaders = []
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if ($returnRepresentation) {
        $headers[] = 'Prefer: return=representation';
    }

    foreach ($extraHeaders as $header) {
        if (is_string($header) && trim($header) !== '') {
            $headers[] = $header;
        }
    }

    if ($payload !== null) {
        $hasContentType = false;

        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $hasContentType = true;
                break;
            }
        }

        if (!$hasContentType) {
            $headers[] = 'Content-Type: application/json';
        }
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

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'response' => $response,
        'error' => $curlError,
        'httpCode' => $httpCode,
        'data' => json_decode((string) $response, true),
    ];
}

function services_require_context(array $allowedMethods): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        services_security_event('services_manage_method_not_allowed', [
            'severity' => 'low',
            'response_status' => 405,
            'result' => 'failed',
            'reason' => 'method_not_allowed',
        ]);
        services_json([
            'success' => false,
            'error' => 'Metoda niedozwolona'
        ], 405);
    }

    start_secure_session();

    if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
        services_security_event('services_manage_unauthorized', [
            'severity' => 'medium',
            'response_status' => 401,
            'result' => 'denied',
            'reason' => 'unauthorized',
        ]);
        services_json([
            'success' => false,
            'error' => 'Brak autoryzacji'
        ], 401);
    }

    $role = (string) ($_SESSION['user']['role'] ?? '');

    if (!in_array($role, ['admin', 'administrator'], true)) {
        services_security_event('services_manage_forbidden', [
            'severity' => 'medium',
            'response_status' => 403,
            'result' => 'denied',
            'reason' => 'forbidden',
        ]);
        services_json([
            'success' => false,
            'error' => 'Brak uprawnień'
        ], 403);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        services_security_event('services_manage_env_missing', [
            'severity' => 'high',
            'response_status' => 500,
            'result' => 'error',
            'reason' => 'env_missing',
        ]);
        services_json([
            'success' => false,
            'error' => 'Brak konfiguracji Supabase'
        ], 500);
    }

    if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
        services_security_event('services_manage_tenant_denied', [
            'tenant_id' => (string) ($_SESSION['user']['tenant_id'] ?? ''),
            'user_id' => (string) ($_SESSION['user']['id'] ?? ''),
            'severity' => 'medium',
            'response_status' => 403,
            'result' => 'denied',
            'reason' => 'tenant_denied',
        ]);
        services_json([
            'success' => false,
            'error' => 'Sesja nie pasuje do domeny'
        ], 403);
    }

    $tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');

    if ($tenantId === '') {
        services_security_event('services_manage_session_invalid', [
            'user_id' => (string) ($_SESSION['user']['id'] ?? ''),
            'severity' => 'medium',
            'response_status' => 401,
            'result' => 'denied',
            'reason' => 'session_invalid',
        ]);
        services_json([
            'success' => false,
            'error' => 'Nieprawidłowa sesja'
        ], 401);
    }

    return [
        'method' => $method,
        'supabaseUrl' => $supabaseUrl,
        'supabaseKey' => $supabaseKey,
        'schema' => $schema,
        'tenantId' => $tenantId,
    ];
}

function services_is_uuid($value): bool
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

function services_read_json_input(): array
{
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);

    if (!is_array($input)) {
        services_json([
            'success' => false,
            'error' => 'Nieprawidłowy JSON'
        ], 400);
    }

    return $input;
}

function services_select_fields(): string
{
    return implode(',', [
        'id',
        'tenant_id',
        'name',
        'description',
        'duration_minutes',
        'break_minutes',
        'booking_buffer_minutes',
        'price_amount',
        'price_currency',
        'payments_enabled',
        'payment_message',
        'is_active',
        'visible_on_front',
        'sort_order',
        'created_at',
        'updated_at',
    ]);
}

function services_normalize_record(array $row, array $staffIds = [], array $staff = []): array
{
    return [
        'name' => (string) ($row['name'] ?? ''),
        'description' => $row['description'] ?? null,
        'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
        'break_minutes' => isset($row['break_minutes']) ? (int) $row['break_minutes'] : null,
        'booking_buffer_minutes' => isset($row['booking_buffer_minutes']) ? (int) $row['booking_buffer_minutes'] : null,
        'price_amount' => array_key_exists('price_amount', $row) && $row['price_amount'] !== null
            ? (string) $row['price_amount']
            : null,
        'price_currency' => (string) ($row['price_currency'] ?? 'PLN'),
        'payments_enabled' => (bool) ($row['payments_enabled'] ?? false),
        'payment_message' => $row['payment_message'] ?? null,
        'is_active' => (bool) ($row['is_active'] ?? false),
        'visible_on_front' => (bool) ($row['visible_on_front'] ?? false),
        'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
        'staff' => array_values($staff),
    ];
}
