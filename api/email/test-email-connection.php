<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/plan_features.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

start_secure_session();
require_csrf_token();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function smtp_test_security_event(
    string $eventKey,
    string $reason,
    int $responseStatus = 400,
    string $result = 'failed',
    string $severity = 'medium',
    ?string $tenantId = null,
    ?string $userId = null,
    ?string $stage = null
): void {
    $details = ['reason' => $reason];

    if ($stage !== null && trim($stage) !== '') {
        $details['stage'] = trim($stage);
    }

    $context = [
        'action_key' => 'email_smtp_test',
        'endpoint' => '/api/email/test-email-connection.php',
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

    $userId = trim((string) ($userId ?? ($_SESSION['user']['id'] ?? '')));
    if ($userId !== '') {
        $context['user_id'] = $userId;
    }

    security_log_event($eventKey, $context);
}

function smtp_test_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function smtp_test_allowed_ports(): array
{
    $default = [25, 465, 587, 2525];
    $raw = trim((string) getenv('SMTP_TENANT_ALLOWED_PORTS'));

    if ($raw === '') {
        return $default;
    }

    $ports = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $port = (int) $part;
        if ($port >= 1 && $port <= 65535) {
            $ports[$port] = $port;
        }
        if (count($ports) >= 16) {
            break;
        }
    }

    return $ports !== [] ? array_values($ports) : $default;
}

function smtp_test_public_target(string $host): array
{
    $host = strtolower(rtrim(trim($host), '.'));

    if (
        $host === ''
        || strlen($host) > 253
        || preg_match('/[\x00-\x20\x7F]/', $host) === 1
        || str_contains($host, '://')
        || str_contains($host, '/')
        || str_contains($host, '\\')
        || str_contains($host, '@')
    ) {
        return ['ok' => false];
    }

    $literal = trim($host, '[]');
    if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
        if (filter_var(
            $literal,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return ['ok' => false];
        }

        return [
            'ok' => true,
            'connect_host' => str_contains($literal, ':') ? '[' . $literal . ']' : $literal,
            'peer_name' => $literal,
        ];
    }

    if (
        !PHPMailer::isValidHost($host)
        || !str_contains($host, '.')
        || $host === 'localhost'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.internal')
        || str_ends_with($host, '.lan')
    ) {
        return ['ok' => false];
    }

    $ips = [];
    $ipv4 = @gethostbynamel($host);
    if (is_array($ipv4)) {
        foreach ($ipv4 as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ips[$ip] = $ip;
            }
        }
    }

    if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
        $ipv6Records = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                $ip = $record['ipv6'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    $ips[$ip] = $ip;
                }
            }
        }
    }

    if ($ips === []) {
        return ['ok' => false];
    }

    foreach ($ips as $ip) {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return ['ok' => false];
        }
    }

    $selected = null;
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $selected = $ip;
            break;
        }
    }
    if ($selected === null) {
        $selected = reset($ips);
    }

    return [
        'ok' => true,
        'connect_host' => str_contains((string) $selected, ':') ? '[' . $selected . ']' : (string) $selected,
        'peer_name' => $host,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    smtp_test_security_event('email_smtp_test_method_not_allowed', 'method_not_allowed', 405, 'failed', 'low');
    smtp_test_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ]);
}

if (empty($_SESSION['user']['tenant_id'])) {
    smtp_test_security_event('email_smtp_test_unauthorized', 'unauthorized', 401, 'denied', 'medium');
    smtp_test_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$sessionRole = strtolower(trim((string) ($_SESSION['user']['role'] ?? '')));
if (!in_array($sessionRole, ['admin', 'administrator'], true)) {
    smtp_test_security_event('email_smtp_test_forbidden', 'forbidden_role', 403, 'denied', 'high');
    smtp_test_json(403, [
        'success' => false,
        'error' => 'Brak uprawnień.'
    ]);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$userId = (string) ($_SESSION['user']['id'] ?? '');
$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    smtp_test_security_event('email_smtp_test_env_missing', 'env_missing', 500, 'error', 'high', $tenantId, $userId, 'supabase_config');
    smtp_test_json(500, [
        'success' => false,
        'error' => 'Nie udało się wczytać konfiguracji systemu.'
    ]);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    smtp_test_security_event('email_smtp_test_tenant_denied', 'tenant_denied', 401, 'denied', 'high', $tenantId, $userId);
    smtp_test_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$planContext = plan_features_get_context($tenantId);

if (empty($planContext['is_paid_plan_active'])) {
    smtp_test_security_event('email_smtp_test_feature_denied', 'feature_denied', 403, 'denied', 'medium', $tenantId, $userId, 'paid_plan');
    smtp_test_json(403, [
        'success' => false,
        'error' => 'Test własnego SMTP jest dostępny w wyższym planie.',
        'upgrade_required' => true,
    ]);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    smtp_test_security_event('email_smtp_test_invalid_json', 'invalid_json', 400, 'failed', 'low', $tenantId, $userId);
    smtp_test_json(400, [
        'success' => false,
        'error' => 'Brak danych wejściowych.'
    ]);
}

$smtpHost = trim((string) ($data['smtp_host'] ?? ''));
$smtpPort = (int) ($data['smtp_port'] ?? 587);
$smtpUsername = trim((string) ($data['smtp_username'] ?? ''));
$smtpPassword = (string) ($data['smtp_password'] ?? '');

$fromEmail = trim((string) ($data['smtp_email'] ?? ''));
$fromName = trim((string) ($data['smtp_name'] ?? ''));

if ($smtpHost === '' || $smtpPort <= 0 || $smtpUsername === '') {
    smtp_test_security_event('email_smtp_test_validation_failed', 'validation_failed', 422, 'failed', 'low', $tenantId, $userId, 'required_smtp_fields');
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Uzupełnij host SMTP, port SMTP oraz login SMTP.'
    ]);
}

$allowedPorts = smtp_test_allowed_ports();

if (!in_array($smtpPort, $allowedPorts, true)) {
    smtp_test_security_event('email_smtp_test_validation_failed', 'validation_failed', 422, 'failed', 'low', $tenantId, $userId, 'smtp_port');
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Port SMTP jest niedozwolony.'
    ]);
}

$smtpTarget = smtp_test_public_target($smtpHost);
if (empty($smtpTarget['ok'])) {
    smtp_test_security_event('email_smtp_test_validation_failed', 'validation_failed', 422, 'failed', 'high', $tenantId, $userId, 'smtp_host');
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Host SMTP jest nieprawidłowy albo niedozwolony.'
    ]);
}

if ($fromEmail === '') {
    $fromEmail = $smtpUsername;
}

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    smtp_test_security_event('email_smtp_test_validation_failed', 'validation_failed', 422, 'failed', 'low', $tenantId, $userId, 'from_email');
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Adres e-mail nadawcy jest nieprawidłowy.'
    ]);
}

if (!filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
    smtp_test_security_event('email_smtp_test_validation_failed', 'validation_failed', 422, 'failed', 'low', $tenantId, $userId, 'smtp_username');
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Login SMTP powinien być poprawnym adresem e-mail.'
    ]);
}

/**
 * Jeśli pole hasła w panelu jest puste, pobieramy zapisane hasło SMTP z bazy.
 * Dzięki temu test działa także po odświeżeniu panelu, gdy hasła nie pokazujemy w formularzu.
 */
if ($smtpPassword === '') {

    $url = $supabaseUrl
        . '/rest/v1/email_settings?select=smtp_password'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&limit=1';

    $headers = supabaseHeaders($supabaseKey, $schema);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $curlError || $httpCode < 200 || $httpCode >= 300) {
        smtp_test_security_event('email_smtp_test_password_fetch_failed', 'password_fetch_failed', 500, 'error', 'medium', $tenantId, $userId, 'stored_credentials_lookup');
        smtp_test_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać zapisanego hasła SMTP.'
        ]);
    }

    $rows = json_decode((string) $response, true);
    $smtpPassword = (string) ($rows[0]['smtp_password'] ?? '');
}

if ($smtpPassword === '') {
    smtp_test_security_event('email_smtp_test_password_missing', 'password_missing', 422, 'failed', 'medium', $tenantId, $userId, 'credentials_missing');
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Brak hasła SMTP. Wpisz hasło SMTP lub zapisz je w ustawieniach.'
    ]);
}

try {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = (string) $smtpTarget['connect_host'];
    $mail->Port = $smtpPort;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 12;
    $mail->SMTPAutoTLS = false;
    $mail->SMTPDebug = 0;
    $mail->Debugoutput = static function (): void {
        // Celowo odrzucamy transcript SMTP, aby nie logować PII ani credentiali.
    };
    $mail->getSMTPInstance()->Timelimit = 20;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => (string) $smtpTarget['peer_name'],
            'SNI_enabled' => true,
        ],
    ];

    if ($smtpPort === 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : $fromEmail);
    $mail->addAddress($fromEmail, $fromName !== '' ? $fromName : $fromEmail);
    $mail->addReplyTo($fromEmail, $fromName !== '' ? $fromName : $fromEmail);

    $mail->isHTML(true);
    $mail->Subject = 'Test połączenia SMTP — AI-IQ Rezerwacja Pro';
    $mail->Body = ''
        . '<p><strong>📩 Test połączenia SMTP</strong></p>'
        . '<p>To jest testowa wiadomość SMTP z panelu AI-IQ Rezerwacja Pro.</p>'
        . '<p>Jeśli ją widzisz, konfiguracja poczty działa poprawnie.</p>';
    $mail->AltBody = "To jest testowa wiadomość SMTP z panelu AI-IQ Rezerwacja Pro.\nJeśli ją widzisz, konfiguracja poczty działa poprawnie.";

    $mail->send();

    smtp_test_security_event('email_smtp_test_success', 'email_smtp_test_success', 200, 'success', 'low', $tenantId, $userId);

    smtp_test_json(200, [
        'success' => true,
        'message' => 'Połączenie SMTP działa poprawnie. Wysłano wiadomość testową.'
    ]);
} catch (\Throwable $e) {
    smtp_test_security_event('email_smtp_test_provider_failed', 'provider_failed', 500, 'failed', 'medium', $tenantId, $userId, 'smtp_send');
    smtp_test_json(500, [
        'success' => false,
        'error' => 'Nie udało się wysłać wiadomości testowej. Sprawdź ustawienia SMTP.'
    ]);
}
