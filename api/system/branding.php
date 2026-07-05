<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function branding_security_event(string $eventKey, string $reason, int $responseStatus = 200, string $result = 'success', string $severity = 'medium', string $stage = ''): void
{
    $details = ['reason' => $reason];
    if ($stage !== '') {
        $details['stage'] = $stage;
    }

    security_log_event($eventKey, [
        'action_key' => 'system_branding_save',
        'endpoint' => '/api/system/branding.php',
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionUser = $_SESSION['user'] ?? null;

if (!is_array($sessionUser) || empty($sessionUser['id']) || empty($sessionUser['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if (!in_array($role, ['admin', 'administrator'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Brak uprawnień administratora'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Nieprawidłowe dane wejściowe'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = (string) $sessionUser['tenant_id'];
$userId = (string) $sessionUser['id'];

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tenantId === '' || $userId === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak danych użytkownika w sesji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function branding_feature_error(): void
{
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Personalizacja wyglądu jest niedostępna w aktualnym planie.',
        'upgrade_required' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function branding_require_feature(string $tenantId, string $featureKey): void
{
    if (tenant_has_feature($tenantId, $featureKey)) {
        return;
    }

    branding_feature_error();
}

$url = $supabaseUrl
    . '/rest/v1/tenant_branding'
    . '?tenant_id=eq.' . rawurlencode($tenantId);

$data = [
    'tenant_id' => $tenantId,
];

if (array_key_exists('company_id', $input)) {
    $company_id = trim((string) $input['company_id']);

    if ($company_id === '') {
        $company_id = str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    }

    $data['company_id'] = $company_id;
}

if (array_key_exists('client_name', $input)) {
    $data['client_name'] = trim((string) $input['client_name']);
}

if (array_key_exists('client_number', $input)) {
    $data['client_number'] = trim((string) $input['client_number']);
}

if (array_key_exists('admin_theme', $input)) {
    branding_require_feature($tenantId, 'branding_colors');
    $data['admin_theme'] = trim((string) $input['admin_theme']);
}

if (array_key_exists('service_title_front', $input)) {
    branding_require_feature($tenantId, 'calendar_appearance');
    $data['service_title_front'] = trim((string) $input['service_title_front']);
}

if (array_key_exists('reservations_style', $input) && is_array($input['reservations_style'])) {
    branding_require_feature($tenantId, 'branding_colors');
    $allowedStyleKeys = [
        'bg_color',
        'card_color',
        'table_color',
        'header_color',
        'border_color',
        'radius',
        'text_color',
        'muted_color',
        'button_text_color',
        'button_border_color',
    ];

    $style = [];

    foreach ($allowedStyleKeys as $key) {
        if (array_key_exists($key, $input['reservations_style'])) {
            $style[$key] = trim((string) $input['reservations_style'][$key]);
        }
    }

    if (!empty($style)) {
        $data['reservations_style'] = $style;
    }
}

if (array_key_exists('calendar_front_style', $input) && is_array($input['calendar_front_style'])) {
    branding_require_feature($tenantId, 'calendar_appearance');
    $allowedFrontStyleKeys = [
        'bg_color',
        'card_color',
        'cell_color',
        'active_color',
        'blocked_color',
        'border_color',
        'text_color',
        'radius',
        'width'
    ];

    $frontStyle = [];

    foreach ($allowedFrontStyleKeys as $key) {
        if (array_key_exists($key, $input['calendar_front_style'])) {
            $frontStyle[$key] = trim((string) $input['calendar_front_style'][$key]);
        }
    }

    if (!empty($frontStyle)) {
        $data['calendar_front_style'] = $frontStyle;
    }
}

if (array_key_exists('calendar_form_fields', $input) && is_array($input['calendar_form_fields'])) {
    branding_require_feature($tenantId, 'calendar_appearance');
    $allowedFormFieldKeys = [
        'name_label',
        'email_label',
        'phone_label',
        'notes_label',
        'show_phone',
        'show_notes'
    ];

    $formFields = [
        'show_email' => true,
    ];

    foreach ($allowedFormFieldKeys as $key) {
        if (!array_key_exists($key, $input['calendar_form_fields'])) {
            continue;
        }

        if (in_array($key, ['show_phone', 'show_notes'], true)) {
            $formFields[$key] = (bool) $input['calendar_form_fields'][$key];
        } else {
            $formFields[$key] = trim((string) $input['calendar_form_fields'][$key]);
        }
    }

    $data['calendar_form_fields'] = $formFields;
}

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseKey,
'Authorization: Bearer ' . $supabaseKey,
        'Content-Type: application/json',
        'Prefer: return=representation',
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
    ],
    CURLOPT_RETURNTRANSFER => true,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false || $curlError) {
    branding_security_event('system_branding_save_failed', 'supabase_request_failed', 500, 'failed', 'medium', 'supabase_patch');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd połączenia z Supabase'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    branding_security_event('system_branding_save_failed', 'supabase_response_failed', $httpCode > 0 ? $httpCode : 500, 'failed', 'medium', 'supabase_patch');
    http_response_code($httpCode > 0 ? $httpCode : 500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać brandingu'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

branding_security_event('system_branding_save_success', 'system_branding_save_success', 200, 'success', 'medium');

echo json_encode(public_response_sanitize([
    'success' => true,
    'message' => 'Branding zapisany',
    'saved_fields' => array_keys($data)
]), JSON_UNESCAPED_UNICODE);
