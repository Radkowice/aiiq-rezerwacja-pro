<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/public_response.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../system/tenant.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
start_secure_session();

function save_staff_email_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus = 400,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $staffId = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && trim($stage) !== '') {
        $details['stage'] = trim($stage);
    }

    $context = [
        'action_key' => 'staff_email_template_save',
        'endpoint' => '/api/email/save-staff-email-template.php',
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'actor_type' => 'tenant_user',
        'severity' => $severity,
        'response_status' => $responseStatus,
        'result' => $result,
        'details' => $details,
    ];

    $tenantId = trim((string) ($tenantId ?? ($_SESSION['user']['tenant_id'] ?? '')));
    if ($tenantId !== '') {
        $context['tenant_id'] = $tenantId;
    }

    $userId = trim((string) ($_SESSION['user']['id'] ?? ''));
    if ($userId !== '') {
        $context['user_id'] = $userId;
    }

    $staffId = trim((string) ($staffId ?? ''));
    if ($staffId !== '') {
        $context['staff_id'] = $staffId;
    }

    security_log_event($eventKey, $context);
}

function save_staff_email_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function save_staff_email_normalize_staff_ref($value): string
{
    if (is_array($value) || is_object($value)) {
        return '';
    }

    $staffRef = trim((string) ($value ?? ''));

    return in_array($staffRef, ['', 'null', 'undefined'], true) ? '' : $staffRef;
}

function save_staff_email_resolve_staff_ref(
    $value,
    string $supabaseUrl,
    string $supabaseKey,
    string $schema,
    string $tenantId,
    string $refSecret
): string {
    $staffRef = save_staff_email_normalize_staff_ref($value);

    if ($staffRef === '') {
        return '';
    }

    $url = $supabaseUrl
        . '/rest/v1/staff_profiles'
        . '?select=id'
        . '&tenant_id=eq.' . rawurlencode($tenantId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => supabaseHeaders($supabaseKey, $schema),
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
        save_staff_email_security_event('staff_email_template_staff_lookup_failed', 'staff_lookup_failed', 500, 'error', 'medium', $tenantId, null, 'staff_lookup');
        save_staff_email_json(['success' => false, 'error' => 'Nie udało się sprawdzić pracownika.'], 500);
    }

    $rows = json_decode((string) $response, true);

    if (!is_array($rows)) {
        save_staff_email_security_event('staff_email_template_staff_lookup_failed', 'staff_lookup_failed', 500, 'error', 'medium', $tenantId, null, 'staff_lookup_response');
        save_staff_email_json(['success' => false, 'error' => 'Nieprawidłowa odpowiedź bazy danych.'], 500);
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $staffId = trim((string) ($row['id'] ?? ''));

        if ($staffId !== '' && hash_equals(public_response_staff_ref($tenantId, $staffId, $refSecret), $staffRef)) {
            return $staffId;
        }
    }

    return '';
}

function save_staff_email_public_staff(array $row, string $tenantId, string $refSecret): array
{
    $staffId = trim((string) ($row['id'] ?? ''));

    // W odpowiedzi wyłącznie pola potrzebne formularzowi administratora.
    return [
        'staff_ref' => $staffId !== ''
            ? public_response_staff_ref($tenantId, $staffId, $refSecret)
            : '',
        'display_name' => (string) ($row['display_name'] ?? ''),
        'email_subject' => $row['email_subject'] ?? null,
        'email_heading' => $row['email_heading'] ?? null,
        'email_body' => $row['email_body'] ?? null,
        'has_custom_template' => true,
    ];
}

function save_staff_email_text($value, int $maxLength, bool $required = false): ?string
{
    if ($value !== null && !is_string($value)) {
        save_staff_email_security_event('staff_email_template_validation_failed', 'validation_failed', 422, 'failed', 'low', null, null, 'text_type');
        save_staff_email_json(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe.'], 422);
    }

    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        if ($required) {
            save_staff_email_security_event('staff_email_template_validation_failed', 'validation_failed', 422, 'failed', 'low', null, null, 'required_field');
            save_staff_email_json(['success' => false, 'error' => 'Uzupełnij temat i treść wiadomości.'], 422);
        }

        return null;
    }

    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        save_staff_email_security_event('staff_email_template_validation_failed', 'validation_failed', 422, 'failed', 'low', null, null, 'text_length');
        save_staff_email_json(['success' => false, 'error' => 'Wpisany tekst jest zbyt długi.'], 422);
    }

    return $text;
}


// Podmiot i nagłówek to tekst zwykły, bez znaczników i znaków sterujących.
function save_staff_email_is_plain_text(?string $text): bool
{
    return $text === null || (
        preg_match('/[\x00-\x1F\x7F]/', $text) !== 1
        && preg_match('~<\s*/?\s*[a-z][^>]*>~i', $text) !== 1
    );
}

// Serializer nie przenosi żadnych atrybutów ani elementów spoza allowlisty.
// Nie wykonuje HTML. Dla niedozwolonej konstrukcji zwraca null (fail closed).
function save_staff_email_html_nodes(DOMNode $parent, int $depth = 0): ?string
{
    if ($depth > 32) {
        return null;
    }

    $safe = '';

    foreach ($parent->childNodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $safe .= htmlspecialchars(
                (string) $node->nodeValue,
                ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            );
            continue;
        }

        if (!$node instanceof DOMElement || $node->nodeType !== XML_ELEMENT_NODE) {
            return null;
        }

        $tag = strtolower($node->tagName);
        if (!in_array($tag, ['b', 'strong', 'i', 'em', 'br', 'a'], true)) {
            return null;
        }

        if ($tag === 'br') {
            if ($node->attributes->length !== 0 || $node->hasChildNodes()) {
                return null;
            }
            $safe .= '<br>';
            continue;
        }

        $href = null;
        if ($tag === 'a') {
            foreach ($node->attributes as $attribute) {
                $attrName = strtolower($attribute->name);
                if (!in_array($attrName, ['href', 'target', 'rel'], true)) {
                    return null;
                }
                if ($attrName === 'target' && $attribute->value !== '_blank') {
                    return null;
                }
                if ($attrName === 'rel'
                    && trim((string) $attribute->value) !== 'noopener noreferrer') {
                    return null;
                }
            }

            if (!$node->hasAttribute('href')) {
                return null;
            }

            $href = trim($node->getAttribute('href'));
            $parts = parse_url($href);
            if (
                $href === '' || strlen($href) > 2048
                || preg_match('/[\x00-\x20\x7F]/', $href) === 1
                || !is_array($parts)
                || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
                || (string) $parts['host'] === ''
                || isset($parts['user']) || isset($parts['pass'])
                || filter_var($href, FILTER_VALIDATE_URL) === false
            ) {
                return null;
            }
        } elseif ($node->attributes->length !== 0) {
            return null;
        }

        $children = save_staff_email_html_nodes($node, $depth + 1);
        if ($children === null) {
            return null;
        }

        if ($tag === 'a') {
            $escapedHref = htmlspecialchars(
                $href,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            );
            $safe .= '<a href="' . $escapedHref
                . '" target="_blank" rel="noopener noreferrer">'
                . $children . '</a>';
        } else {
            $safe .= '<' . $tag . '>' . $children . '</' . $tag . '>';
        }
    }

    return $safe;
}

// Wejście jest fragmentem wiadomości, a nie całym dokumentem HTML.
function save_staff_email_validate_html(string $html): ?string
{
    if (!mb_check_encoding($html, 'UTF-8')
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html) === 1
        || preg_match('/<\s*[!?]/', $html) === 1) {
        return null;
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $prior = libxml_use_internal_errors(true);
    try {
        $loaded = $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
            . '<body><div id="aiiq-email-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
    }

    if (!$loaded || !$dom->documentElement instanceof DOMElement) {
        return null;
    }

    $htmlElement = $dom->documentElement;
    if (strtolower($htmlElement->tagName) !== 'html'
        || $htmlElement->childNodes->length !== 2
        || $htmlElement->firstChild?->nodeName !== 'head'
        || $htmlElement->lastChild?->nodeName !== 'body') {
        return null;
    }

    $head = $htmlElement->firstChild;
    if ($head->childNodes->length !== 1
        || $head->firstChild?->nodeName !== 'meta'
        || !$head->firstChild instanceof DOMElement
        || $head->firstChild->getAttribute('charset') !== 'UTF-8') {
        return null;
    }

    $body = $htmlElement->lastChild;
    if ($body->childNodes->length !== 1
        || !$body->firstChild instanceof DOMElement
        || strtolower($body->firstChild->tagName) !== 'div'
        || $body->firstChild->getAttribute('id') !== 'aiiq-email-root'
        || $body->firstChild->attributes->length !== 1) {
        return null;
    }

    $safe = save_staff_email_html_nodes($body->firstChild);
    return $safe !== null && mb_strlen($safe, 'UTF-8') <= 10000 ? $safe : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    save_staff_email_security_event('staff_email_template_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    header('Allow: POST');
    save_staff_email_json(['success' => false, 'error' => 'Metoda niedozwolona.'], 405);
}

if (empty($_SESSION['user']['tenant_id'])) {
    save_staff_email_security_event('staff_email_template_unauthorized', 'unauthorized', 401, 'denied', 'medium');
    save_staff_email_json(['success' => false, 'error' => 'Brak autoryzacji.'], 401);
}

$sessionRole = strtolower(trim((string) ($_SESSION['user']['role'] ?? '')));
if (!in_array($sessionRole, ['admin', 'administrator'], true)) {
    save_staff_email_security_event('staff_email_template_forbidden', 'forbidden_role', 403, 'denied', 'high');
    save_staff_email_json(['success' => false, 'error' => 'Brak uprawnień.'], 403);
}

// CSRF jest wymagany dla każdej autoryzowanej próby zapisu.
require_csrf_token();

$tenantId = (string) $_SESSION['user']['tenant_id'];
$rawBody = file_get_contents('php://input', false, null, 0, 131073);
if ($rawBody === false || strlen($rawBody) > 131072) {
    save_staff_email_security_event('staff_email_template_validation_failed', 'payload_too_large', 413, 'failed', 'low', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Żądanie jest zbyt duże.'], 413);
}

// JSON musi zawierać obiekt, nie listę ani wartość skalarną.
$decoded = json_decode($rawBody, false, 32);
if (!$decoded instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
    save_staff_email_security_event('staff_email_template_invalid_json', 'invalid_json', 400, 'failed', 'low', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Brak danych wejściowych.'], 400);
}
$input = get_object_vars($decoded);

$staffRef = save_staff_email_normalize_staff_ref($input['staff_ref'] ?? null);

if ($staffRef === '') {
    save_staff_email_security_event('staff_email_template_staff_ref_missing', 'staff_ref_missing', 422, 'failed', 'low', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Wybierz pracownika, aby edytować jego szablon e-mail.'], 422);
}

if (preg_match('/\Ast_[0-9a-f]{48}\z/', $staffRef) !== 1) {
    save_staff_email_security_event('staff_email_template_validation_failed', 'invalid_staff_ref', 400, 'failed', 'low', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Nieprawidłowa referencja pracownika.'], 400);
}

$subject = save_staff_email_text($input['email_subject'] ?? null, 255, true);
$heading = save_staff_email_text($input['email_heading'] ?? null, 255, false);
$body = save_staff_email_text($input['email_body'] ?? null, 10000, true);

if (!save_staff_email_is_plain_text($subject) || !save_staff_email_is_plain_text($heading)) {
    save_staff_email_security_event('staff_email_template_validation_failed', 'invalid_plain_text', 422, 'failed', 'low', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Nieprawidłowy temat lub nagłówek.'], 422);
}

$safeBody = save_staff_email_validate_html($body);
if ($safeBody === null) {
    save_staff_email_security_event('staff_email_template_validation_failed', 'invalid_html', 422, 'failed', 'medium', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Treść wiadomości zawiera niedozwolone formatowanie HTML.'], 422);
}
$body = $safeBody;

$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    save_staff_email_security_event('staff_email_template_env_missing', 'env_missing', 500, 'error', 'high', $tenantId, null, 'supabase_config');
    save_staff_email_json(['success' => false, 'error' => 'Brak konfiguracji Supabase.'], 500);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    save_staff_email_security_event('staff_email_template_tenant_denied', 'tenant_denied', 403, 'denied', 'high', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Sesja nie pasuje do domeny.'], 403);
}

if (!tenant_has_feature($tenantId, 'staff_module')) {
    save_staff_email_security_event('staff_email_template_feature_denied', 'feature_denied', 403, 'denied', 'medium', $tenantId, null, 'staff_module');
    save_staff_email_json([
        'success' => false,
        'error' => 'Szablony e-mail pracowników są dostępne w wyższym planie.',
        'upgrade_required' => true,
    ], 403);
}

$refSecret = public_response_ref_secret($supabaseKey);
$staffId = save_staff_email_resolve_staff_ref($staffRef, $supabaseUrl, $supabaseKey, $schema, $tenantId, $refSecret);

if ($staffId === '') {
    save_staff_email_security_event('staff_email_template_staff_not_found', 'staff_not_found', 404, 'failed', 'medium', $tenantId);
    save_staff_email_json(['success' => false, 'error' => 'Nie znaleziono pracownika.'], 404);
}

$url = $supabaseUrl
    . '/rest/v1/staff_profiles'
    . '?tenant_id=eq.' . rawurlencode($tenantId)
    . '&id=eq.' . rawurlencode($staffId)
    . '&is_active=eq.true'
    . '&select=id,display_name,email_subject,email_heading,email_body';

$payload = [
    'email_subject' => $subject,
    'email_heading' => $heading,
    'email_body' => $body,
];

$headers = supabaseHeaders($supabaseKey, $schema);
$headers[] = 'Prefer: return=representation';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
    save_staff_email_security_event('staff_email_template_save_failed', 'template_save_failed', 500, 'error', 'medium', $tenantId, $staffId, 'staff_profile_patch');
    save_staff_email_json(['success' => false, 'error' => 'Nie udało się zapisać zmian. Spróbuj ponownie.'], 500);
}

$rows = json_decode((string) $response, true);

if (!is_array($rows) || empty($rows[0])) {
    save_staff_email_security_event('staff_email_template_staff_not_found', 'staff_not_found', 404, 'failed', 'medium', $tenantId, $staffId, 'patch_response');
    save_staff_email_json(['success' => false, 'error' => 'Nie znaleziono pracownika.'], 404);
}

$staff = save_staff_email_public_staff($rows[0], $tenantId, $refSecret);
$staff['has_custom_template'] = true;

save_staff_email_security_event('staff_email_template_save_success', 'staff_email_template_save_success', 200, 'success', 'low', $tenantId, $staffId);

save_staff_email_json([
    'success' => true,
    'message' => 'Szablon e-mail pracownika został zapisany.',
    'staff' => $staff,
]);
