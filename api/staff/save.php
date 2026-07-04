<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';

start_secure_session();

function staff_save_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function staff_save_request(
    string $method,
    string $url,
    string $supabaseKey,
    string $schema,
    ?array $payload = null,
    bool $returnRepresentation = false
): array {
    $headers = supabaseHeaders($supabaseKey, $schema);

    if ($returnRepresentation) {
        $headers[] = 'Prefer: return=representation';
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'response' => $response,
        'error' => $curlError,
        'httpCode' => $httpCode,
    ];
}

function staff_save_trim_optional($value, int $maxLength): ?string
{
    if (is_array($value) || is_object($value)) {
        staff_save_json([
            'success' => false,
            'error' => 'Nieprawidłowe dane wejściowe'
        ], 422);
    }

    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        return null;
    }

    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        staff_save_json([
            'success' => false,
            'error' => 'Nieprawidłowe dane wejściowe'
        ], 422);
    }

    return $text;
}

function staff_save_trim_nullable_text($value): ?string
{
    if (is_array($value) || is_object($value)) {
        staff_save_json([
            'success' => false,
            'error' => 'Nieprawidłowe dane wejściowe'
        ], 422);
    }

    $text = trim((string) ($value ?? ''));

    return $text === '' ? null : $text;
}

function staff_save_optional_integer($value, string $field, int $min): ?int
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }

    if (is_bool($value) || is_array($value) || is_object($value)
        || (!is_int($value) && !preg_match('/^-?\d+$/', (string) $value))
    ) {
        staff_save_json([
            'success' => false,
            'error' => $field . ' musi być liczbą całkowitą'
        ], 422);
    }

    $integer = (int) $value;

    if ($integer < $min) {
        staff_save_json([
            'success' => false,
            'error' => $field . ' ma nieprawidłową wartość'
        ], 422);
    }

    return $integer;
}

function staff_save_optional_numeric($value, string $field, float $min): ?float
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }

    if (is_bool($value) || is_array($value) || is_object($value)) {
        staff_save_json([
            'success' => false,
            'error' => $field . ' musi być liczbą'
        ], 422);
    }

    $normalized = is_string($value) ? str_replace(',', '.', trim($value)) : $value;

    if (!is_numeric($normalized)) {
        staff_save_json([
            'success' => false,
            'error' => $field . ' musi być liczbą'
        ], 422);
    }

    $number = (float) $normalized;

    if ($number < $min) {
        staff_save_json([
            'success' => false,
            'error' => $field . ' ma nieprawidłową wartość'
        ], 422);
    }

    return $number;
}

function staff_save_optional_boolean($value, string $field): ?bool
{
    if ($value === null) {
        return null;
    }

    if (!is_bool($value)) {
        staff_save_json([
            'success' => false,
            'error' => $field . ' musi być wartością logiczną'
        ], 422);
    }

    return $value;
}

function staff_save_select_fields(): string
{
    return implode(',', [
        'id',
        'display_name',
        'email',
        'phone',
        'description',
        'color',
        'sort_order',
        'is_active',
        'visible_on_front',
        'service_name',
        'service_description',
        'service_duration_minutes',
        'service_break_minutes',
        'booking_buffer_minutes',
        'service_price',
        'payments_enabled',
        'email_subject',
        'email_heading',
        'email_body',
        'created_at',
        'updated_at',
    ]);
}

function staff_save_is_public_staff_ref(string $value): bool
{
    return str_starts_with($value, 'st_');
}

function staff_save_resolve_staff_ref(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffRef,
    string $refSecret
): ?string {
    if ($staffRef === '' || !staff_save_is_public_staff_ref($staffRef)) {
        return null;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $result = staff_save_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '') {
        staff_save_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        staff_save_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić pracownika'
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    $rows = json_decode((string) $result['response'], true);

    if (!is_array($rows)) {
        staff_save_json([
            'success' => false,
            'error' => 'Nieprawidłowa odpowiedź bazy danych'
        ], 500);
    }

    foreach ($rows as $row) {
        $candidateId = trim((string) ($row['id'] ?? ''));

        if ($candidateId === '') {
            continue;
        }

        if (public_response_staff_ref($tenantId, $candidateId, $refSecret) === $staffRef) {
            return $candidateId;
        }
    }

    return null;
}

function staff_save_public_record(array $row, string $tenantId, string $refSecret): array
{
    $staffId = trim((string) ($row['id'] ?? ''));
    $staffRef = $staffId !== ''
        ? public_response_staff_ref($tenantId, $staffId, $refSecret)
        : '';

    $allowedFields = [
        'display_name',
        'email',
        'phone',
        'description',
        'color',
        'sort_order',
        'is_active',
        'visible_on_front',
        'service_name',
        'service_description',
        'service_duration_minutes',
        'service_break_minutes',
        'booking_buffer_minutes',
        'service_price',
        'payments_enabled',
        'email_subject',
        'email_heading',
        'email_body',
        'created_at',
        'updated_at',
    ];

    $record = [];

    if ($staffRef !== '') {
        $record['staff_ref'] = $staffRef;
    }

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $row)) {
            $record[$field] = $row[$field];
        }
    }

    return $record;
}

function staff_save_ensure_no_duplicate(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    ?string $email,
    string $displayName,
    ?string $excludeStaffId = null
): void {
    $query = 'select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true';

    if ($email !== null) {
        $query .= '&email=eq.' . rawurlencode($email);
    } else {
        $query .= '&display_name=eq.' . rawurlencode($displayName);
    }

    if ($excludeStaffId !== null && $excludeStaffId !== '') {
        $query .= '&id=neq.' . rawurlencode($excludeStaffId);
    }

    $query .= '&limit=1';

    $url = $supabaseUrl . '/rest/v1/staff_profiles?' . $query;
    $result = staff_save_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '') {
        staff_save_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        staff_save_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić duplikatu pracownika'
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    $rows = json_decode((string) $result['response'], true);

    if (!is_array($rows)) {
        staff_save_json([
            'success' => false,
            'error' => 'Nieprawidłowa odpowiedź bazy danych'
        ], 500);
    }

    if (!empty($rows[0]['id'])) {
        security_log_event('staff_save_duplicate_conflict', [
            'action_key' => 'staff_save',
            'endpoint' => '/api/staff/save.php',
            'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
            'actor_type' => 'tenant_user',
            'response_status' => 409,
            'result' => 'failed',
            'severity' => 'medium',
            'details' => [
                'reason' => 'staff_save_duplicate_conflict',
            ],
        ]);

        staff_save_json([
            'success' => false,
            'error' => 'Pracownik o takich danych już istnieje'
        ], 409);
    }
}


function staff_save_sync_staff_account_email(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $staffId,
    ?string $email
): void {
    if ($staffId === '' || $email === null || $email === '') {
        return;
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_accounts'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&staff_id=eq.' . rawurlencode($staffId);

    $result = staff_save_request('PATCH', $url, $supabaseKey, $schema, [
        'email' => $email,
    ]);

    if ($result['response'] === false || $result['error'] !== '' || $result['httpCode'] >= 400) {
        staff_save_json([
            'success' => false,
            'error' => 'Dane pracownika zostały zapisane, ale nie udało się zaktualizować e-maila logowania pracownika.'
        ], 500);
    }
}

function staff_save_count_active_staff(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): int {
    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true';

    $result = staff_save_request('GET', $url, $supabaseKey, $schema);

    if ($result['response'] === false || $result['error'] !== '') {
        staff_save_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        staff_save_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić limitu pracowników'
        ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
    }

    $rows = json_decode((string) $result['response'], true);

    return is_array($rows) ? count($rows) : 0;
}

function staff_save_enforce_staff_limit(
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId
): void {
    $context = plan_features_get_context($tenantId);
    $limit = $context['limits']['staff_count'] ?? null;

    if ($limit === null) {
        return;
    }

    $limit = (int) $limit;

    if ($limit <= 0) {
        staff_save_json([
            'success' => false,
            'error' => 'Twój obecny plan nie obejmuje modułu personelu. Potrzebujesz większego limitu? Napisz do producenta.',
            'limit_reached' => true,
            'limit_type' => 'staff_count',
        ], 403);
    }

    $activeStaffCount = staff_save_count_active_staff($supabaseUrl, $supabaseKey, $schema, $tenantId);

    if ($activeStaffCount >= $limit) {
        staff_save_json([
            'success' => false,
            'error' => 'Osiągnąłeś limit w swoim planie: maksymalnie ' . $limit . ' pracowników. Potrzebujesz większego limitu? Napisz do producenta: kontakt@ai-iq.pl',
            'limit_reached' => true,
            'limit_type' => 'staff_count',
        ], 403);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    staff_save_json([
        'success' => false,
        'error' => 'Metoda niedozwolona'
    ], 405);
}

if (empty($_SESSION['user']['id']) || empty($_SESSION['user']['tenant_id'])) {
    staff_save_json([
        'success' => false,
        'error' => 'Brak autoryzacji'
    ], 401);
}

$role = (string) ($_SESSION['user']['role'] ?? '');

if ($role !== 'administrator') {
    staff_save_json([
        'success' => false,
        'error' => 'Brak uprawnień'
    ], 403);
}

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    staff_save_json([
        'success' => false,
        'error' => 'Brak konfiguracji Supabase'
    ], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    staff_save_json([
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ], 403);
}

$tenantId = (string) ($_SESSION['user']['tenant_id'] ?? '');
$refSecret = public_response_ref_secret($supabaseKey);

if ($tenantId === '') {
    staff_save_json([
        'success' => false,
        'error' => 'Nieprawidłowa sesja'
    ], 401);
}

require_tenant_feature($tenantId, 'staff_module');

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($input)) {
    staff_save_json([
        'success' => false,
        'error' => 'Nieprawidłowy JSON'
    ], 400);
}

$rawStaffRef = trim((string) ($input['staff_ref'] ?? ''));
$staffId = '';

if ($rawStaffRef !== '') {
    $staffId = staff_save_resolve_staff_ref($supabaseUrl, $supabaseKey, $schema, $tenantId, $rawStaffRef, $refSecret) ?? '';

    if ($staffId === '') {
        staff_save_json([
            'success' => false,
            'error' => 'Nie znaleziono pracownika'
        ], 404);
    }
}

$isUpdate = $staffId !== '';

$displayName = trim((string) ($input['display_name'] ?? ''));
$displayNameLength = mb_strlen($displayName, 'UTF-8');

if ($displayNameLength < 2 || $displayNameLength > 120) {
    staff_save_json([
        'success' => false,
        'error' => 'Nazwa pracownika musi mieć od 2 do 120 znaków'
    ], 422);
}

$email = staff_save_trim_optional($input['email'] ?? null, 255);

if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    staff_save_json([
        'success' => false,
        'error' => 'Podaj poprawny adres email'
    ], 422);
}

$phone = staff_save_trim_optional($input['phone'] ?? null, 40);
$description = staff_save_trim_optional($input['description'] ?? null, 1000);
$color = staff_save_trim_optional($input['color'] ?? null, 30);
$serviceName = staff_save_trim_optional($input['service_name'] ?? null, 255);
$serviceDescription = staff_save_trim_optional($input['service_description'] ?? null, 1500);
$emailSubject = staff_save_trim_nullable_text($input['email_subject'] ?? null);
$emailHeading = staff_save_trim_nullable_text($input['email_heading'] ?? null);
$emailBody = staff_save_trim_nullable_text($input['email_body'] ?? null);

$sortOrderRaw = $input['sort_order'] ?? 0;

if (is_string($sortOrderRaw) && trim($sortOrderRaw) === '') {
    $sortOrderRaw = 0;
}

if (!is_int($sortOrderRaw) && !ctype_digit((string) $sortOrderRaw) && !preg_match('/^-?\d+$/', (string) $sortOrderRaw)) {
    staff_save_json([
        'success' => false,
        'error' => 'sort_order musi być liczbą całkowitą'
    ], 422);
}

$sortOrder = (int) $sortOrderRaw;

if ($sortOrder < 0) {
    staff_save_json([
        'success' => false,
        'error' => 'Kolejność nie może być mniejsza niż 0.',
        'field' => 'sort_order',
    ], 422);
}

$payload = [
    'display_name' => $displayName,
    'email' => $email,
    'phone' => $phone,
    'description' => $description,
    'color' => $color,
    'sort_order' => $sortOrder,
];

// Pola usługi i e-maila pracownika zostają obsługiwane tylko dla zgodności
// wstecznej. Uproszczony Personel już ich nie wysyła, więc zwykła edycja
// pracownika nie czyści istniejących wartości w staff_profiles.
if (array_key_exists('service_name', $input)) {
    $payload['service_name'] = $serviceName;
}

if (array_key_exists('service_description', $input)) {
    $payload['service_description'] = $serviceDescription;
}

if (array_key_exists('service_duration_minutes', $input)) {
    $payload['service_duration_minutes'] = staff_save_optional_integer(
        $input['service_duration_minutes'],
        'service_duration_minutes',
        1
    );
}

if (array_key_exists('service_break_minutes', $input)) {
    $payload['service_break_minutes'] = staff_save_optional_integer(
        $input['service_break_minutes'],
        'service_break_minutes',
        0
    );
}

if (array_key_exists('booking_buffer_minutes', $input)) {
    $payload['booking_buffer_minutes'] = staff_save_optional_integer(
        $input['booking_buffer_minutes'],
        'booking_buffer_minutes',
        0
    );
}

if (array_key_exists('service_price', $input)) {
    $payload['service_price'] = staff_save_optional_numeric($input['service_price'], 'service_price', 0);
}

if (array_key_exists('payments_enabled', $input)) {
    $payload['payments_enabled'] = staff_save_optional_boolean($input['payments_enabled'], 'payments_enabled');
}

if (array_key_exists('email_subject', $input)) {
    $payload['email_subject'] = $emailSubject;
}

if (array_key_exists('email_heading', $input)) {
    $payload['email_heading'] = $emailHeading;
}

if (array_key_exists('email_body', $input)) {
    $payload['email_body'] = $emailBody;
}

if (
    ($payload['payments_enabled'] ?? null) === true
    && array_key_exists('service_price', $payload)
    && (($payload['service_price'] ?? null) === null || (float) $payload['service_price'] <= 0)
) {
    staff_save_json([
        'success' => false,
        'message' => 'Podaj kwotę usługi większą od 0, jeśli płatność jest włączona.',
        'error' => 'Podaj kwotę usługi większą od 0, jeśli płatność jest włączona.'
    ], 422);
}

if (array_key_exists('is_active', $input)) {
    $payload['is_active'] = (bool) $input['is_active'];
} elseif (!$isUpdate) {
    $payload['is_active'] = true;
}

if (array_key_exists('visible_on_front', $input)) {
    $payload['visible_on_front'] = (bool) $input['visible_on_front'];
} elseif (!$isUpdate) {
    $payload['visible_on_front'] = true;
}

$existingIsActive = false;

if ($isUpdate) {
    $staffUrl = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id,is_active'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&limit=1';

    $staffResult = staff_save_request('GET', $staffUrl, $supabaseKey, $schema);

    if ($staffResult['response'] === false || $staffResult['error'] !== '') {
        staff_save_json([
            'success' => false,
            'error' => 'Błąd połączenia z bazą danych'
        ], 500);
    }

    if ($staffResult['httpCode'] < 200 || $staffResult['httpCode'] >= 300) {
        staff_save_json([
            'success' => false,
            'error' => 'Nie udało się sprawdzić pracownika'
        ], $staffResult['httpCode'] > 0 ? $staffResult['httpCode'] : 500);
    }

    $staffRows = json_decode((string) $staffResult['response'], true);

    if (!is_array($staffRows)) {
        staff_save_json([
            'success' => false,
            'error' => 'Nieprawidłowa odpowiedź bazy danych'
        ], 500);
    }

    if (empty($staffRows[0]['id'])) {
        staff_save_json([
            'success' => false,
            'error' => 'Nie znaleziono pracownika'
        ], 404);
    }

    $existingIsActive = !empty($staffRows[0]['is_active']);

    if (($payload['is_active'] ?? false) === true && !$existingIsActive) {
        staff_save_enforce_staff_limit($supabaseUrl, $supabaseKey, $schema, $tenantId);
    }

    staff_save_ensure_no_duplicate(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $tenantId,
        $email,
        $displayName,
        $staffId
    );

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?tenant_id=eq.' . rawurlencode($tenantId)
        . '&id=eq.' . rawurlencode($staffId)
        . '&select=' . rawurlencode(staff_save_select_fields());

    $result = staff_save_request('PATCH', $url, $supabaseKey, $schema, $payload, true);
} else {
    if (($payload['is_active'] ?? true) === true) {
        staff_save_enforce_staff_limit($supabaseUrl, $supabaseKey, $schema, $tenantId);
    }

    staff_save_ensure_no_duplicate(
        $supabaseUrl,
        $supabaseKey,
        $schema,
        $tenantId,
        $email,
        $displayName
    );

    $payload['tenant_id'] = $tenantId;

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=' . rawurlencode(staff_save_select_fields());

    $result = staff_save_request('POST', $url, $supabaseKey, $schema, $payload, true);
}

if ($result['response'] === false || $result['error'] !== '') {
    staff_save_json([
        'success' => false,
        'error' => 'Błąd połączenia z bazą danych'
    ], 500);
}

if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
    staff_save_json([
        'success' => false,
        'error' => $isUpdate
            ? 'Nie udało się zaktualizować pracownika'
            : 'Nie udało się utworzyć pracownika'
    ], $result['httpCode'] > 0 ? $result['httpCode'] : 500);
}

$savedRows = json_decode((string) $result['response'], true);

if ($isUpdate) {
    staff_save_sync_staff_account_email($supabaseUrl, $supabaseKey, $schema, $tenantId, $staffId, $email);
}

if (!is_array($savedRows) || empty($savedRows[0]) || !is_array($savedRows[0])) {
    staff_save_json([
        'success' => false,
        'error' => 'Nieprawidłowa odpowiedź bazy danych'
    ], 500);
}

if ($isUpdate && $existingIsActive && array_key_exists('is_active', $payload) && $payload['is_active'] === false) {
    security_log_event('staff_save_deactivate_success', [
        'action_key' => 'staff_save',
        'endpoint' => '/api/staff/save.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'response_status' => 200,
        'result' => 'success',
        'severity' => 'medium',
        'details' => [
            'reason' => 'staff_save_deactivate_success',
        ],
    ]);
}

staff_save_json([
    'success' => true,
    'staff' => staff_save_public_record($savedRows[0], $tenantId, $refSecret)
]);
