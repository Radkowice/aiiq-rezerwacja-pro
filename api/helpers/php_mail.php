<?php
declare(strict_types=1);

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function systemMailEnv(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $value = trim((string) $value);
    return $value !== '' ? $value : $default;
}

function buildSystemMailLayout(string $title, string $intro, string $messageHtml, string $footerNote = ''): string
{
    $appName = htmlspecialchars(systemMailEnv('SMTP_SYSTEM_NAME', 'AI-IQ'), ENT_QUOTES, 'UTF-8');
    $footerNote = trim($footerNote);

    $footerHtml = $footerNote !== ''
        ? '<p style="margin:12px 0 0;font-size:12px;line-height:1.6;color:#7b8794;">' . htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    return '<!doctype html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f7fb;margin:0;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;">
                    <tr>
                        <td align="center" style="background:linear-gradient(135deg,#111827 0%,#1f2937 100%);padding:28px 32px;text-align:center;">
                            <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#cbd5e1;font-weight:bold;text-align:center;">
                                ' . $appName . '
                            </div>
                            <div style="margin:10px auto 0;font-size:28px;line-height:1.25;font-weight:700;color:#ffffff;text-align:center;">
                                ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '
                            </div>
                            <div style="margin:10px auto 0;font-size:15px;line-height:1.7;color:#e5e7eb;text-align:center;">
                                ' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;">
                                <tr>
                                    <td style="padding:22px 22px 18px 22px;">
                                        <div style="font-size:16px;line-height:1.8;color:#111827;">
                                            ' . $messageHtml . '
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:22px;">
                                <tr>
                                    <td style="font-size:14px;line-height:1.8;color:#4b5563;">
                                        <p style="margin:0 0 10px;"><strong>🔒 Wiadomość systemowa</strong></p>
                                        <p style="margin:0;">
                                            Email został wysłany automatycznie przez system. Prosimy nie odpowiadać na tę wiadomość — ta skrzynka nie jest monitorowana.
                                        </p>
                                        ' . $footerHtml . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                   <tr>
    <td style="padding:18px 32px;background:#eef2f7;border-top:1px solid #e5e7eb;text-align:center;">
        <div style="font-size:12px;line-height:1.6;color:#6b7280;">
            © ' . date('Y') . ' AI-IQ | Inteligentne systemy ·
            <a href="https://www.ai-iq.pl" target="_blank" rel="noopener noreferrer" style="color:#6b7280;text-decoration:none;">
                Powiadomienie systemowe
            </a>
        </div>
    </td>
</tr>
    </table>
</body>
</html>';
}

function buildEmailChangeOldAddressHtml(string $oldEmail, string $newEmail): string
{
    $oldEmailEsc = htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8');
    $newEmailEsc = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>📩 Zmieniono adres email przypisany do Twojego konta.</strong></p>'
        . '<p style="margin:0 0 10px;">Poprzedni adres: <strong>' . $oldEmailEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Nowy adres: <strong>' . $newEmailEsc . '</strong></p>'
        . '<p style="margin:16px 0 0;">Jeśli to była Twoja zmiana, nie musisz nic robić.</p>'
        . '<p style="margin:10px 0 0;">Jeśli nie rozpoznajesz tej operacji, jak najszybciej zabezpiecz konto.</p>';

    return buildSystemMailLayout(
        'Zmiana adresu email',
        'Wykryto zmianę adresu email na Twoim koncie.',
        $message,
        'To powiadomienie trafiło na poprzedni adres email, aby zwiększyć bezpieczeństwo konta.'
    );
}

function buildEmailChangeNewAddressHtml(string $oldEmail, string $newEmail): string
{
    $oldEmailEsc = htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8');
    $newEmailEsc = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>✅ Twój nowy adres email został ustawiony poprawnie.</strong></p>'
        . '<p style="margin:0 0 10px;">Poprzedni adres: <strong>' . $oldEmailEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Nowy adres: <strong>' . $newEmailEsc . '</strong></p>'
        . '<p style="margin:16px 0 0;">Od teraz ten adres będzie używany do komunikacji dotyczącej konta.</p>';

    return buildSystemMailLayout(
        'Potwierdzenie zmiany email',
        'To potwierdzenie zostało wysłane na nowo ustawiony adres.',
        $message,
        'Zachowaj tę wiadomość na wypadek późniejszej weryfikacji zmian na koncie.'
    );
}

function sendSystemMail(string $to, string $subject, string $html): bool
{
    try {
        $host       = systemMailEnv('SMTP_SYSTEM_HOST');
        $port       = (int) systemMailEnv('SMTP_SYSTEM_PORT', '587');
        $username   = systemMailEnv('SMTP_SYSTEM_USER');
        $password   = systemMailEnv('SMTP_SYSTEM_PASS');
        $fromEmail  = systemMailEnv('SMTP_SYSTEM_FROM');
        $fromName   = systemMailEnv('SMTP_SYSTEM_NAME', 'AI-IQ');
        $encryption = strtolower(systemMailEnv('SMTP_SYSTEM_ENCRYPTION', 'tls'));
        $replyTo    = systemMailEnv('SMTP_SYSTEM_REPLY_TO', $fromEmail);
        $replyName  = systemMailEnv('SMTP_SYSTEM_REPLY_TO_NAME', 'No Reply');

        if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
            throw new Exception('Brak pełnej konfiguracji SMTP_SYSTEM_* w ENV');
        }

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->CharSet    = 'UTF-8';

        if ($encryption === 'ssl' || $encryption === 'smtps') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo, $replyName);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $mail->send();

    } catch (\Throwable $e) {
        error_log('MAIL ERROR: ' . $e->getMessage());
        return false;
    }
}