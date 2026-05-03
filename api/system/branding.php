<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak autoryzacji'
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

$tenantId = (string) $_SESSION['user']['tenant_id'];
$userId = (string) $_SESSION['user']['id'];

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

if ($tenantId === '' || $userId === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Brak danych użytkownika w sesji'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = $supabaseUrl . '/rest/v1/tenant_branding?on_conflict=tenant_id';

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
    $data['admin_theme'] = trim((string) $input['admin_theme']);
}

if (array_key_exists('service_title_front', $input)) {
    $data['service_title_front'] = trim((string) $input['service_title_front']);
}

if (array_key_exists('logo_url_front', $input)) {
    $data['logo_url_front'] = trim((string) $input['logo_url_front']);
}

if (array_key_exists('favicon_url_front', $input)) {
    $data['favicon_url_front'] = trim((string) $input['favicon_url_front']);
}

if (array_key_exists('reservations_style', $input) && is_array($input['reservations_style'])) {
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
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([$data], JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => array_merge(
        supabaseHeaders($supabaseKey, $schema),
        ['Prefer: resolution=merge-duplicates']
    ),
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false || $curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd połączenia z Supabase',
        'debug' => $curlError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode > 0 ? $httpCode : 500);
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się zapisać brandingu',
        'debug' => $response
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Branding zapisany',
    'saved_fields' => array_keys($data)
], JSON_UNESCAPED_UNICODE);