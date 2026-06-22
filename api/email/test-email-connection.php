<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../system/tenant.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

start_secure_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function smtp_test_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    smtp_test_json(405, [
        'success' => false,
        'error' => 'Metoda niedozwolona.'
    ]);
}

if (empty($_SESSION['user']['tenant_id'])) {
    smtp_test_json(401, [
        'success' => false,
        'error' => 'Brak autoryzacji.'
    ]);
}

$tenantId = (string) $_SESSION['user']['tenant_id'];
$supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabaseKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');
$schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

if ($supabaseUrl === '' || $supabaseKey === '') {
    smtp_test_json(500, [
        'success' => false,
        'error' => 'Brak konfiguracji Supabase.'
    ]);
}

if (!session_tenant_matches_current_host($supabaseUrl, $supabaseKey, $schema)) {
    smtp_test_json(401, [
        'success' => false,
        'error' => 'Sesja nie pasuje do domeny'
    ]);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
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
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Uzupełnij host SMTP, port SMTP oraz login SMTP.'
    ]);
}

if ($fromEmail === '') {
    $fromEmail = $smtpUsername;
}

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Adres e-mail nadawcy jest nieprawidłowy.'
    ]);
}

if (!filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
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
        smtp_test_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać zapisanego hasła SMTP.'
        ]);
    }

    $rows = json_decode((string) $response, true);
    $smtpPassword = (string) ($rows[0]['smtp_password'] ?? '');
}

if ($smtpPassword === '') {
    smtp_test_json(422, [
        'success' => false,
        'error' => 'Brak hasła SMTP. Wpisz hasło SMTP lub zapisz je w ustawieniach.'
    ]);
}

try {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->Port = $smtpPort;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->CharSet = 'UTF-8';

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

    smtp_test_json(200, [
        'success' => true,
        'message' => 'Połączenie SMTP działa poprawnie. Wysłano wiadomość testową.'
    ]);
} catch (\Throwable $e) {
    smtp_test_json(500, [
        'success' => false,
        'error' => 'Nie udało się wysłać wiadomości testowej. Sprawdź ustawienia SMTP.'
    ]);
}
