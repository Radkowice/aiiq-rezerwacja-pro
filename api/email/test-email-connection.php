<?php
require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$smtp_host = trim($data['smtp_host'] ?? '');
$smtp_port = trim((string)($data['smtp_port'] ?? ''));
$smtp_username = trim($data['smtp_username'] ?? '');
$smtp_password = (string)($data['smtp_password'] ?? '');

if (!$smtp_host || !$smtp_port || !$smtp_username) {
    echo json_encode([
        'success' => false,
        'error' => 'Brak wymaganych danych SMTP'
    ]);
    exit;
}

try {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $smtp_host;
    $mail->SMTPAuth = true;
    $mail->Username = $smtp_username;
    $mail->Password = $smtp_password;
    $mail->SMTPDebug = 0;
    $mail->CharSet = 'UTF-8';

    if ((int)$smtp_port === 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->Port = (int)$smtp_port;

    // Realny test wysyłki — nie samo "connect"
    $mail->setFrom($smtp_username, 'SMTP Test');
    $mail->addAddress($smtp_username);

    $mail->Subject = 'Test SMTP';
    $mail->Body = 'To jest test połączenia SMTP.';

    if (!$mail->send()) {
        throw new Exception($mail->ErrorInfo ?: 'Nie udało się wysłać testowego emaila.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Połączenie SMTP działa poprawnie. Wysłano email testowy.'
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Błąd testu SMTP: ' . $e->getMessage()
    ]);
}