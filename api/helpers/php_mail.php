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

function systemMailIconForTitle(string $title, string $intro = ''): string
{
    $text = mb_strtolower($title . ' ' . $intro, 'UTF-8');

    if (str_contains($text, 'płat') || str_contains($text, 'plat') || str_contains($text, 'abonament') || str_contains($text, 'pro')) {
        return '💳';
    }

    if (str_contains($text, 'usu')) {
        return '⚠️';
    }

    if (str_contains($text, 'hasł') || str_contains($text, 'hasl') || str_contains($text, 'bezpieczeń') || str_contains($text, 'bezpieczen')) {
        return '🔐';
    }

    if (str_contains($text, 'email') || str_contains($text, 'e-mail') || str_contains($text, 'wiadomo')) {
        return '✉️';
    }

    if (str_contains($text, 'aktyw') || str_contains($text, 'konto')) {
        return '✅';
    }

    if (str_contains($text, 'rezerwac')) {
        return '📅';
    }

    return '📬';
}

function buildSystemMailLayout(string $title, string $intro, string $messageHtml, string $footerNote = ''): string
{
    $icon = htmlspecialchars(systemMailIconForTitle($title, $intro), ENT_QUOTES, 'UTF-8');
    $footerNote = trim($footerNote);

    $footerHtml = $footerNote !== ''
        ? '<p style="margin:10px 0 0 0;font-size:12px;line-height:1.6;color:#607284;">' . htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    return '<!doctype html>'
        . '<html lang="pl">'
        . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f7fb;">'
        . '<div style="margin:0;padding:0;background:#f4f7fb;">'
        . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
        . '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">'
        . '<div style="font-size:42px;line-height:1;margin-bottom:12px;" aria-hidden="true">' . $icon . '</div>'
        . '<h1 style="margin:0;font-size:28px;line-height:1.25;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="margin:12px 0 0 0;font-size:16px;line-height:1.55;opacity:0.95;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div>'
        . '<div style="padding:32px 24px;">'
        . '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:0 0 24px 0;">'
        . '<div style="font-size:16px;line-height:1.8;color:#17324d;">' . $messageHtml . '</div>'
        . '</div>'
        . '<p style="margin:24px 0 0 0;font-size:14px;line-height:1.6;color:#4f6478;">🔒 Wiadomość systemowa AI-IQ Rezerwacja Pro. Prosimy nie odpowiadać na tę wiadomość.</p>'
        . $footerHtml
        . '</div>'
        . '<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">'
        . 'Obsługiwane przez <a href="https://ai-iq.pl" target="_blank" style="color:#607284;text-decoration:none;font-weight:600;">AI-IQ</a> | Inteligentne automatyzacje'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}
function buildEmailChangeCodeHtml(string $code, string $oldEmail, string $newEmail): string
{
    $oldEmailEsc = htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8');
    $newEmailEsc = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>Otrzymaliśmy prośbę o zmianę adresu e-mail konta.</strong></p>'
        . '<p style="margin:0 0 10px;">Obecny adres: <strong>' . $oldEmailEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Nowy adres: <strong>' . $newEmailEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Aby potwierdzić zmianę, wpisz poniższy kod w panelu:</p>'
        . '<div style="margin:22px 0;padding:18px 20px;background:#111827;color:#ffffff;'
        . 'font-size:32px;font-weight:700;letter-spacing:0.25em;text-align:center;border-radius:14px;">'
        . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
        . '</div>'
        . '<p style="margin:0 0 10px;">Kod jest ważny przez <strong>10 minut</strong>.</p>'
        . '<p style="margin:10px 0 0;">Jeśli to nie Ty inicjowałeś zmianę, zignoruj tę wiadomość i zabezpiecz konto.</p>';

    return buildSystemMailLayout(
        'Kod potwierdzenia zmiany e-maila',
        'To wiadomość systemowa dotycząca bezpieczeństwa Twojego konta.',
        $message,
        'Nie odpowiadaj na tę wiadomość. Skrzynka nie jest monitorowana.'
    );
}

function buildEmailChangeOldAddressHtml(string $oldEmail, string $newEmail): string
{
    $oldEmailEsc = htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8');
    $newEmailEsc = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>📩 Zmieniono adres e-mail przypisany do Twojego konta.</strong></p>'
        . '<p style="margin:0 0 10px;">Poprzedni adres: <strong>' . $oldEmailEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Nowy adres: <strong>' . $newEmailEsc . '</strong></p>'
        . '<p style="margin:16px 0 0;">Jeśli to była Twoja zmiana, nie musisz nic robić.</p>'
        . '<p style="margin:10px 0 0;">Jeśli nie rozpoznajesz tej operacji, jak najszybciej zabezpiecz konto.</p>';

    return buildSystemMailLayout(
        'Zmiana adresu e-mail',
        'Wykryto zmianę adresu e-mail na Twoim koncie.',
        $message,
        'To powiadomienie trafiło na poprzedni adres e-mail, aby zwiększyć bezpieczeństwo konta.'
    );
}

function buildEmailChangeNewAddressHtml(string $oldEmail, string $newEmail): string
{
    $oldEmailEsc = htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8');
    $newEmailEsc = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');

    $message = ''
        . '<p style="margin:0 0 14px;"><strong>✅ Twój nowy adres e-mail został ustawiony poprawnie.</strong></p>'
        . '<p style="margin:0 0 10px;">Poprzedni adres: <strong>' . $oldEmailEsc . '</strong></p>'
        . '<p style="margin:0 0 10px;">Nowy adres: <strong>' . $newEmailEsc . '</strong></p>'
        . '<p style="margin:16px 0 0;">Od teraz ten adres będzie używany do komunikacji dotyczącej konta.</p>';

    return buildSystemMailLayout(
        'Potwierdzenie zmiany e-maila',
        'To potwierdzenie zostało wysłane na nowo ustawiony adres.',
        $message,
        'Zachowaj tę wiadomość na wypadek późniejszej weryfikacji zmian na koncie.'
    );
}

function sendSystemMail(
    string $to,
    string $subject,
    string $html,
    ?string $replyToOverride = null,
    string $replyNameOverride = ''
): bool {
    try {
        $host       = systemMailEnv('SMTP_SYSTEM_HOST');
        $port       = (int) systemMailEnv('SMTP_SYSTEM_PORT', '587');
        $username   = systemMailEnv('SMTP_SYSTEM_USER');
        $password   = systemMailEnv('SMTP_SYSTEM_PASS');
        $fromEmail  = systemMailEnv('SMTP_SYSTEM_FROM');
        $fromName   = systemMailEnv('SMTP_SYSTEM_NAME', 'AI-IQ');
        $encryption = strtolower(systemMailEnv('SMTP_SYSTEM_ENCRYPTION', 'tls'));
        $replyTo    = $replyToOverride !== null
            ? trim($replyToOverride)
            : systemMailEnv('SMTP_SYSTEM_REPLY_TO', $fromEmail);
        $replyName  = $replyToOverride !== null
            ? trim($replyNameOverride)
            : systemMailEnv('SMTP_SYSTEM_REPLY_TO_NAME', 'No Reply');

        $timeout = (int) systemMailEnv('SMTP_SYSTEM_TIMEOUT', '20');
        $timeLimit = (int) systemMailEnv('SMTP_SYSTEM_TIMELIMIT', '20');

        $timeout = max(5, min(60, $timeout));
        $timeLimit = max(5, min(60, $timeLimit));

        if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
            throw new Exception('Brak pełnej konfiguracji SMTP_SYSTEM_* w ENV');
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Nieprawidłowy adres odbiorcy');
        }

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = $timeout;

        $mail->getSMTPInstance()->Timelimit = $timeLimit;

        if ($encryption === 'ssl' || $encryption === 'smtps') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo, $replyName);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(
            html_entity_decode(
                strip_tags(
                    str_replace(
                        ['<br>', '<br/>', '<br />'],
                        "\n",
                        $html
                    )
                ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        return $mail->send();

    } catch (\Throwable $e) {
        error_log('SYSTEM_MAIL_ERROR ' . json_encode([
            'error_class' => get_class($e),
            'message_trace' => substr(hash('sha256', $e->getMessage()), 0, 16),
        ], JSON_UNESCAPED_SLASHES));

        return false;
    }
}
