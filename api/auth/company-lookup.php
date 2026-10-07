<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../helpers/company_lookup.php';
require_once __DIR__ . '/../helpers/public_response.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

const COMPANY_LOOKUP_ENDPOINT = '/api/auth/company-lookup.php';
const COMPANY_LOOKUP_MAX_REQUEST_BYTES = 4096;

function company_lookup_endpoint_response(array $payload, int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        public_response_sanitize($payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function company_lookup_endpoint_request_id(): ?string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return null;
    }
}

function company_lookup_endpoint_log(
    string $eventKey,
    string $reason,
    int $statusCode,
    string $result,
    string $severity,
    ?string $requestId,
    array $details = []
): void {
    $safeDetails = ['reason' => $reason];

    foreach ($details as $key => $value) {
        if (!is_string($key) || !is_scalar($value)) {
            continue;
        }

        $safeDetails[$key] = (string) $value;
    }

    security_log_event($eventKey, [
        'action_key' => 'company_lookup_ip',
        'severity' => $severity,
        'actor_type' => 'unknown',
        'ip_address' => security_client_ip(),
        'endpoint' => COMPANY_LOOKUP_ENDPOINT,
        'http_method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
        'response_status' => $statusCode,
        'result' => $result,
        'request_id' => $requestId,
        'details' => $safeDetails,
    ]);
}

function company_lookup_endpoint_retry_after(?int $seconds): void
{
    if ($seconds === null || $seconds < 1) {
        return;
    }

    header('Retry-After: ' . min($seconds, 3600));
}

$requestId = company_lookup_endpoint_request_id();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$clientIp = security_client_ip();

if ($clientIp === null) {
    company_lookup_endpoint_log(
        'company_lookup_client_ip_unavailable',
        'client_ip_unavailable',
        503,
        'failed',
        'high',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Automatyczne pobranie danych firmy jest chwilowo niedostępne. Wpisz dane ręcznie.',
        'manual_entry_allowed' => true,
    ], 503);
}

$rateLimit = security_rate_limit_check(
    'company_lookup_ip',
    ['ip' => $clientIp],
    [
        'ip_address' => $clientIp,
        'scope_type' => 'ip',
        'endpoint' => COMPANY_LOOKUP_ENDPOINT,
        'http_method' => $method,
        'request_id' => $requestId,
        'metadata' => [
            'operation' => 'company_lookup',
        ],
    ]
);

$rateLimitRuleFound = (
    is_array($rateLimit['raw'] ?? null)
    && ($rateLimit['raw']['rule_found'] ?? false) === true
);

if (empty($rateLimit['ok']) || !$rateLimitRuleFound) {
    company_lookup_endpoint_log(
        'company_lookup_rate_limit_unavailable',
        'rate_limit_unavailable',
        503,
        'failed',
        'high',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Automatyczne pobranie danych firmy jest chwilowo niedostępne. Wpisz dane ręcznie.',
        'manual_entry_allowed' => true,
    ], 503);
}

if (empty($rateLimit['allowed'])) {
    $retryAfter = isset($rateLimit['retry_after_seconds'])
        && is_numeric($rateLimit['retry_after_seconds'])
            ? max(0, (int) $rateLimit['retry_after_seconds'])
            : null;

    company_lookup_endpoint_retry_after($retryAfter);

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Zbyt wiele prób pobrania danych firmy. Spróbuj ponownie za chwilę lub wpisz dane ręcznie.',
        'manual_entry_allowed' => true,
        'retry_after_seconds' => $retryAfter,
    ], 429);
}

if ($method !== 'POST') {
    header('Allow: POST');

    company_lookup_endpoint_log(
        'company_lookup_method_not_allowed',
        'method_not_allowed',
        405,
        'blocked',
        'low',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Metoda niedozwolona.',
        'manual_entry_allowed' => true,
    ], 405);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));

if (
    $contentType === ''
    || !str_starts_with($contentType, 'application/json')
) {
    company_lookup_endpoint_log(
        'company_lookup_content_type_invalid',
        'content_type_invalid',
        415,
        'blocked',
        'low',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Nieprawidłowy format żądania.',
        'manual_entry_allowed' => true,
    ], 415);
}

$contentLength = isset($_SERVER['CONTENT_LENGTH'])
    && is_numeric($_SERVER['CONTENT_LENGTH'])
        ? (int) $_SERVER['CONTENT_LENGTH']
        : null;

if (
    $contentLength !== null
    && ($contentLength < 0 || $contentLength > COMPANY_LOOKUP_MAX_REQUEST_BYTES)
) {
    company_lookup_endpoint_log(
        'company_lookup_request_too_large',
        'request_too_large',
        413,
        'blocked',
        'low',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Żądanie jest zbyt duże.',
        'manual_entry_allowed' => true,
    ], 413);
}

$rawBody = file_get_contents('php://input');

if (
    !is_string($rawBody)
    || $rawBody === ''
    || strlen($rawBody) > COMPANY_LOOKUP_MAX_REQUEST_BYTES
) {
    company_lookup_endpoint_log(
        'company_lookup_request_invalid',
        'request_body_invalid',
        400,
        'failed',
        'low',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Nieprawidłowe żądanie.',
        'manual_entry_allowed' => true,
    ], 400);
}

try {
    $input = json_decode(
        $rawBody,
        true,
        16,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    $input = null;
}

if (!is_array($input)) {
    company_lookup_endpoint_log(
        'company_lookup_json_invalid',
        'invalid_json',
        400,
        'failed',
        'low',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Nieprawidłowe żądanie.',
        'manual_entry_allowed' => true,
    ], 400);
}

if (
    count($input) !== 1
    || !array_key_exists('nip', $input)
    || !is_string($input['nip'])
) {
    company_lookup_endpoint_log(
        'company_lookup_request_contract_invalid',
        'request_contract_invalid',
        400,
        'blocked',
        'low',
        $requestId
    );

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Nieprawidłowe żądanie.',
        'manual_entry_allowed' => true,
    ], 400);
}

$rawNip = $input['nip'];

if (!company_lookup_is_valid_polish_nip($rawNip)) {
    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Podaj poprawny NIP.',
        'manual_entry_allowed' => true,
    ], 400);
}

$result = company_lookup_find($rawNip, $requestId);
$state = (string) ($result['state'] ?? 'unavailable');

if (!empty($result['ok']) && $state === 'found') {
    $data = is_array($result['data'] ?? null)
        ? $result['data']
        : [];

    $legalName = trim((string) ($data['legal_name'] ?? ''));
    $street = trim((string) ($data['street'] ?? ''));
    $postalCode = trim((string) ($data['postal_code'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));

    if ($legalName === '') {
        company_lookup_endpoint_log(
            'company_lookup_normalized_data_invalid',
            'normalized_data_invalid',
            503,
            'failed',
            'high',
            $requestId
        );

        company_lookup_endpoint_response([
            'success' => false,
            'error' => 'Automatyczne pobranie danych firmy jest chwilowo niedostępne. Wpisz dane ręcznie.',
            'manual_entry_allowed' => true,
        ], 503);
    }

    company_lookup_endpoint_response([
        'success' => true,
        'found' => true,
        'company' => [
            'full_name' => $legalName,
            'street' => $street,
            'postal_code' => $postalCode,
            'city' => $city,
            'address_complete' => ($data['address_complete'] ?? false) === true,
        ],
        'manual_entry_allowed' => true,
    ]);
}

if (!empty($result['ok']) && $state === 'not_found') {
    company_lookup_endpoint_response([
        'success' => true,
        'found' => false,
        'company' => null,
        'message' => 'Nie znaleziono danych dla tego NIP. Wpisz dane firmy ręcznie.',
        'manual_entry_allowed' => true,
    ]);
}

if ($state === 'invalid_nip') {
    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Podaj poprawny NIP.',
        'manual_entry_allowed' => true,
    ], 400);
}

if ($state === 'rate_limited') {
    $retryAfter = isset($result['retry_after_seconds'])
        && is_numeric($result['retry_after_seconds'])
            ? max(0, (int) $result['retry_after_seconds'])
            : null;

    company_lookup_endpoint_retry_after($retryAfter);

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Automatyczne pobranie danych firmy jest chwilowo przeciążone. Spróbuj ponownie za chwilę lub wpisz dane ręcznie.',
        'manual_entry_allowed' => true,
        'retry_after_seconds' => $retryAfter,
    ], 429);
}

if ($state === 'busy') {
    $retryAfter = isset($result['retry_after_seconds'])
        && is_numeric($result['retry_after_seconds'])
            ? max(1, (int) $result['retry_after_seconds'])
            : 2;

    company_lookup_endpoint_retry_after($retryAfter);

    company_lookup_endpoint_response([
        'success' => false,
        'error' => 'Dane firmy są właśnie pobierane. Spróbuj ponownie za chwilę lub wpisz je ręcznie.',
        'manual_entry_allowed' => true,
        'retry_after_seconds' => $retryAfter,
    ], 503);
}

company_lookup_endpoint_log(
    'company_lookup_unavailable',
    'lookup_unavailable',
    503,
    'failed',
    'medium',
    $requestId,
    ['state' => $state]
);

company_lookup_endpoint_response([
    'success' => false,
    'error' => 'Automatyczne pobranie danych firmy jest chwilowo niedostępne. Wpisz dane ręcznie.',
    'manual_entry_allowed' => true,
], 503);
