<?php
declare(strict_types=1);

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('booking_mail_replace_placeholders')) {
    function booking_mail_replace_placeholders(string $text, array $data): string
    {
        return str_replace(array_keys($data), array_values($data), $text);
    }
}

if (!function_exists('booking_mail_system_footer')) {
    function booking_mail_system_footer(): string
    {
        return
            '<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">' .
                'Obsługiwane przez <a href="https://ai-iq.pl" target="_blank" style="color:#607284;text-decoration:none;font-weight:600;">AI-IQ</a> | Inteligentne automatyzacje' .
            '</div>';
    }
}

if (!function_exists('booking_mail_build_footer')) {
    function booking_mail_build_footer(string $plan, string $mode, string $custom): string
    {
        if ($plan === 'basic') {
            return booking_mail_system_footer();
        }

        if ($plan === 'pro') {
            return $mode === 'none' ? '' : booking_mail_system_footer();
        }

        if ($plan === 'premium') {
            if ($mode === 'custom' && trim($custom) !== '') {
                return $custom;
            }

            if ($mode === 'none') {
                return '';
            }

            return booking_mail_system_footer();
        }

        return booking_mail_system_footer();
    }
}

if (!function_exists('booking_mail_format_amount')) {
    function booking_mail_format_amount($amount, string $currency = 'PLN'): string
{
    if ($amount === null || $amount === '') {
        return '';
    }

    $displayCurrency = strtoupper(trim($currency)) === 'PLN' ? 'zł' : trim($currency);

    if ($displayCurrency === '') {
        $displayCurrency = 'zł';
    }

    return number_format((float)$amount, 2, ',', ' ') . ' ' . $displayCurrency;
}
}

if (!function_exists('booking_mail_configure_mailer')) {
    function booking_mail_configure_mailer(PHPMailer $mail, array $emailSettings): void
    {
        $smtpHost = trim((string) ($emailSettings['smtp_host'] ?? ''));
        $smtpPort = (int) ($emailSettings['smtp_port'] ?? 587);

        $smtpUser = trim((string) (
            $emailSettings['smtp_user']
            ?? $emailSettings['smtp_username']
            ?? ''
        ));

        $smtpPass = (string) (
            $emailSettings['smtp_pass']
            ?? $emailSettings['smtp_password']
            ?? ''
        );

        $fromEmail = trim((string) (
            $emailSettings['smtp_email']
            ?? $emailSettings['from_email']
            ?? ''
        ));

        $fromName = trim((string) (
            $emailSettings['smtp_name']
            ?? $emailSettings['from_name']
            ?? ''
        ));

        if ($smtpHost === '') {
            throw new Exception('Brak smtp_host w email_settings');
        }

        if ($fromEmail === '') {
            throw new Exception('Brak smtp_email/from_email w email_settings');
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Nieprawidłowy adres nadawcy SMTP');
        }

        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort > 0 ? $smtpPort : 587;
        $mail->SMTPAuth = $smtpUser !== '' || $smtpPass !== '';
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->CharSet = 'UTF-8';

        $encryption = strtolower(trim((string) ($emailSettings['smtp_encryption'] ?? 'tls')));

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === '') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'none') {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : $fromEmail);

        $replyToEmail = trim((string) ($emailSettings['reply_to_email'] ?? ''));

        if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $replyToName = trim((string) ($emailSettings['reply_to_name'] ?? ''));
            $mail->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $replyToEmail);
        }
    }
}

if (!function_exists('booking_mail_build_client_html')) {
    function booking_mail_build_client_html(
        string $introHtml,
        string $companyName,
        string $serviceName,
        string $footerHtml,
        string $name,
        string $email,
        string $date,
        string $time,
        string $note,
        array $payment = []
    ): string {
        $paymentStatus = trim((string)($payment['status_label'] ?? ''));
        $paymentAmount = trim((string)($payment['amount_text'] ?? ''));

        $paymentRows = '';

        if ($paymentStatus !== '') {
            $paymentRows .= '<p style="margin:12px 0 0 0;font-size:16px;"><strong>✅ Status płatności:</strong> ' . htmlspecialchars($paymentStatus, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($paymentAmount !== '') {
            $paymentRows .= '<p style="margin:12px 0 0 0;font-size:16px;"><strong>💳 Kwota:</strong> ' . htmlspecialchars($paymentAmount, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return
            '<div style="margin:0;padding:0;background:#f4f7fb;">' .
                '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">' .

                    '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">' .
                        '<div style="font-size:42px;line-height:1;margin-bottom:12px;">📅</div>' .
                        '<h1 style="margin:0;font-size:28px;">Rezerwacja potwierdzona</h1>' .
                        '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">Dziękujemy za umówienie ' . htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') . ' | ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' .
                    '</div>' .

                    '<div style="padding:32px 24px;">' .
                        $introHtml .

                        '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                            '<p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>' .
                            '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>' .
                            '<p style="margin:0 0 12px 0;font-size:16px;"><strong>📆 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>' .
                            '<p style="margin:0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>' .
                            $paymentRows .
                        '</div>' .

                        (
                            trim($note) !== ''
                                ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">' .
                                    '<p style="margin:0;font-size:16px;"><strong>💬 Twoja wiadomość:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' .
                                  '</div>'
                                : ''
                        ) .

                        '<p style="font-size:14px;line-height:1.6;color:#4f6478;">W razie pytań po prostu odpowiedz na tę wiadomość.</p>' .
                    '</div>' .

                    $footerHtml .

                '</div>' .
            '</div>';
    }
}

if (!function_exists('booking_mail_send_client_confirmation')) {
    function booking_mail_send_client_confirmation(
        array $emailSettings,
        array $emailTemplate,
        array $tenantData,
        array $booking,
        array $payment = []
    ): bool {
        $email = trim((string)($booking['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (empty($emailSettings['send_client_confirmation'])) {
            return false;
        }

        $name = trim((string)($booking['name'] ?? ''));
        $date = trim((string)($booking['booking_date'] ?? $booking['date'] ?? ''));
        $time = trim((string)($booking['booking_time'] ?? $booking['time'] ?? ''));
        $note = trim((string)($booking['notes'] ?? $booking['note'] ?? ''));

        $companyName = (string)($tenantData['client_name'] ?? '');
        $plan = (string)($tenantData['plan'] ?? 'basic');
        $footerMode = (string)($tenantData['email_footer_mode'] ?? 'system');
        $footerCustom = (string)($tenantData['email_footer_custom'] ?? '');
        $serviceName = (string)($emailTemplate['service_name'] ?? 'wizyty');

        $placeholders = [
            '{name}'    => $name,
            '{date}'    => $date,
            '{time}'    => $time,
            '{email}'   => $email,
            '{phone}'   => (string)($booking['phone'] ?? ''),
            '{message}' => $note,
        ];

        $finalSubject = booking_mail_replace_placeholders((string)($emailTemplate['subject'] ?? ''), $placeholders);
        $introHtml = booking_mail_replace_placeholders((string)($emailTemplate['body_html'] ?? ''), $placeholders);
        $footerHtml = booking_mail_build_footer($plan, $footerMode, $footerCustom);

        $paymentAmountText = booking_mail_format_amount(
            $payment['amount'] ?? $booking['payment_amount'] ?? null,
            (string)($payment['currency'] ?? $booking['payment_currency'] ?? 'PLN')
        );

        $clientHtml = booking_mail_build_client_html(
            $introHtml,
            $companyName,
            $serviceName,
            $footerHtml,
            $name,
            $email,
            $date,
            $time,
            $note,
            [
                'status_label' => (string)($payment['status_label'] ?? ''),
                'amount_text' => $paymentAmountText,
            ]
        );

        $clientAltBody =
            "Rezerwacja potwierdzona\n\n" .
            "Dziękujemy za umówienie {$serviceName} z {$companyName}\n\n" .
            "Imię: {$name}\n" .
            "E-mail: {$email}\n" .
            "Data: {$date}\n" .
            "Godzina: {$time}\n" .
            ((string)($payment['status_label'] ?? '') !== '' ? "Status płatności: {$payment['status_label']}\n" : '') .
            ($paymentAmountText !== '' ? "Kwota: {$paymentAmountText}\n" : '') .
            ($note !== '' ? "Wiadomość: {$note}\n" : '') .
            "\n";

        $mail = new PHPMailer(true);
        booking_mail_configure_mailer($mail, $emailSettings);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $finalSubject !== '' ? $finalSubject : ('Potwierdzenie rezerwacji – ' . $date . ' ' . $time);
        $mail->Body = $clientHtml;
        $mail->AltBody = $clientAltBody;
        $mail->send();

        return true;
    }
}