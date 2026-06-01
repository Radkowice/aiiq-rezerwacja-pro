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
        string $emailHeading,
        string $footerHtml,
        string $name,
        string $email,
        string $date,
        string $time,
        string $note,
        array $payment = [],
        string $bookedServiceName = '',
        string $staffDisplayName = ''
    ): string {
        $paymentStatus = trim((string)($payment['status_label'] ?? ''));
        $paymentAmount = trim((string)($payment['amount_text'] ?? ''));
        $rescheduleUrl = trim((string)($payment['reschedule_url'] ?? ''));
        $bookedServiceName = trim($bookedServiceName);
        $staffDisplayName = trim($staffDisplayName);

        $paymentRows = '';

        if ($bookedServiceName !== '') {
            $paymentRows .= '<p style="margin:12px 0 0 0;font-size:16px;"><strong>📋 Usługa:</strong> ' . htmlspecialchars($bookedServiceName, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($paymentStatus !== '') {
            $paymentRows .= '<p style="margin:12px 0 0 0;font-size:16px;"><strong>✅ Status płatności:</strong> ' . htmlspecialchars($paymentStatus, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($paymentAmount !== '') {
            $paymentRows .= '<p style="margin:12px 0 0 0;font-size:16px;"><strong>💳 Kwota:</strong> ' . htmlspecialchars($paymentAmount, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($staffDisplayName !== '') {
            $paymentRows .= '<p style="margin:12px 0 0 0;font-size:16px;"><strong>👥 Osoba obsługująca:</strong> ' . htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $rescheduleSection = $rescheduleUrl !== ''
            ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;text-align:center;">' .
                '<h2 style="margin:0 0 10px 0;font-size:20px;color:#17324d;">Chcesz zmienić termin?</h2>' .
                '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#4f6478;">Jeśli ten termin Ci nie pasuje, możesz przełożyć rezerwację na inny dostępny termin.</p>' .
                '<a href="' . htmlspecialchars($rescheduleUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 20px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">Przełóż rezerwację</a>' .
                '<p style="margin:14px 0 0 0;font-size:13px;line-height:1.5;color:#607284;">Link jest ważny do momentu rozpoczęcia rezerwacji.</p>' .
              '</div>'
            : '';

        return
            '<div style="margin:0;padding:0;background:#f4f7fb;">' .
                '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">' .

                    '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">' .
                        '<div style="font-size:42px;line-height:1;margin-bottom:12px;">📅</div>' .
                        '<h1 style="margin:0;font-size:28px;">Rezerwacja potwierdzona</h1>' .
                        '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($emailHeading, ENT_QUOTES, 'UTF-8') . ' | ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' .
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

                        $rescheduleSection .

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
        $staffDisplayName = trim((string)($booking['staff_display_name'] ?? ''));

        $companyName = (string)($tenantData['client_name'] ?? '');
        $plan = (string)($tenantData['plan'] ?? 'basic');
        $footerMode = (string)($tenantData['email_footer_mode'] ?? 'system');
        $footerCustom = (string)($tenantData['email_footer_custom'] ?? '');
        $emailHeading = trim((string)($emailTemplate['service_name'] ?? ''));

        if ($emailHeading === '') {
            $emailHeading = 'Dziękujemy za rezerwację';
        }

        $bookedServiceName = trim((string)($booking['service_name_snapshot'] ?? ''));

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
            $emailHeading,
            $footerHtml,
            $name,
            $email,
            $date,
            $time,
            $note,
            [
                'status_label' => (string)($payment['status_label'] ?? ''),
                'amount_text' => $paymentAmountText,
                'reschedule_url' => (string)($payment['reschedule_url'] ?? ''),
            ],
            $bookedServiceName,
            $staffDisplayName
        );

        $clientAltBody =
            "Rezerwacja potwierdzona\n\n" .
            "{$emailHeading} | {$companyName}\n\n" .
            "Imię: {$name}\n" .
            "E-mail: {$email}\n" .
            "Data: {$date}\n" .
            "Godzina: {$time}\n" .
            ($bookedServiceName !== '' ? "Usługa: {$bookedServiceName}\n" : '') .
            ((string)($payment['status_label'] ?? '') !== '' ? "Status płatności: {$payment['status_label']}\n" : '') .
            ($paymentAmountText !== '' ? "Kwota: {$paymentAmountText}\n" : '') .
            ($staffDisplayName !== '' ? "Osoba obsługująca: {$staffDisplayName}\n" : '') .
            ($note !== '' ? "Wiadomość: {$note}\n" : '') .
            ((string)($payment['reschedule_url'] ?? '') !== ''
                ? "\nChcesz zmienić termin?\nJeśli ten termin Ci nie pasuje, możesz przełożyć rezerwację na inny dostępny termin.\nPrzełóż rezerwację: {$payment['reschedule_url']}\nLink jest ważny do momentu rozpoczęcia rezerwacji.\n"
                : '') .
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

if (!function_exists('booking_mail_send_reschedule_confirmation')) {
    function booking_mail_send_reschedule_confirmation(
        array $emailSettings,
        array $tenantData,
        array $booking
    ): bool {
        $email = trim((string)($booking['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (empty($emailSettings['send_client_confirmation'])) {
            return false;
        }

        $name = trim((string)($booking['name'] ?? ''));
        $phone = trim((string)($booking['phone'] ?? ''));
        $serviceName = trim((string)($booking['service_name_snapshot'] ?? ''));
        $staffDisplayName = trim((string)($booking['staff_display_name'] ?? ''));
        $previousDate = trim((string)($booking['previous_date_label'] ?? ''));
        $newDate = trim((string)($booking['new_date_label'] ?? ''));
        $paymentStatus = trim((string)($booking['payment_status_label'] ?? ''));
        $rescheduleCount = max(0, (int)($booking['reschedule_count'] ?? 0));
        $rescheduleLimit = 3;
        $companyName = (string)($tenantData['client_name'] ?? '');
        $plan = (string)($tenantData['plan'] ?? 'basic');
        $footerMode = (string)($tenantData['email_footer_mode'] ?? 'system');
        $footerCustom = (string)($tenantData['email_footer_custom'] ?? '');
        $footerHtml = booking_mail_build_footer($plan, $footerMode, $footerCustom);
        $subjectSuffix = $serviceName !== '' ? ': ' . $serviceName : '';
        $clientSubject = 'Zmiana terminu rezerwacji' . $subjectSuffix;
        $adminSubject = 'Klient zmienił termin rezerwacji' . $subjectSuffix;

        $row = static function (string $label, string $value): string {
            if (trim($value) === '') {
                return '';
            }

            return '<tr><td style="padding:8px 0;color:#607284;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</td>'
                . '<td style="padding:8px 0;text-align:right;"><strong>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>';
        };

        $section = static function (string $label): string {
            return '<tr><td colspan="2" style="padding:14px 0 8px 0;color:#17324d;font-weight:700;font-size:16px;">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        };

        $detailsRows =
            $section('👤 Twoje dane')
            . $row('Imię i nazwisko', $name)
            . $row('E-mail', $email)
            . $row('Telefon', $phone)
            . $section('🛠 Rezerwacja')
            . $row('Usługa', $serviceName)
            . $row('Osoba obsługująca', $staffDisplayName)
            . $row('Status płatności', $paymentStatus)
            . $section('📅 Zmiana terminu')
            . $row('Poprzedni termin', $previousDate)
            . $row('Nowy termin', $newDate);

        $html =
            '<div style="margin:0;padding:0;background:#f4f7fb;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
            . '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">'
            . '<div style="font-size:42px;line-height:1;margin-bottom:12px;">📅</div>'
            . '<h1 style="margin:0;font-size:28px;">Zmiana terminu rezerwacji</h1>'
            . '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>'
            . '<div style="padding:32px 24px;">'
            . '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">Termin Twojej rezerwacji został zmieniony.</p>'
            . '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:15px;">'
            . $detailsRows
            . '</table>'
            . '</div>'
            . '<p style="font-size:14px;line-height:1.6;color:#4f6478;">Jeśli rezerwacja była już opłacona, płatność pozostaje bez zmian.</p>'
            . '<p style="font-size:14px;line-height:1.6;color:#4f6478;">W razie pytań po prostu odpowiedz na tę wiadomość.</p>'
            . '</div>'
            . $footerHtml
            . '</div>'
            . '</div>';

        $altBody =
            $clientSubject . "\n\n"
            . "Termin Twojej rezerwacji został zmieniony.\n\n"
            . "Twoje dane:\n"
            . ($name !== '' ? "Imię i nazwisko: {$name}\n" : '')
            . "E-mail: {$email}\n"
            . ($phone !== '' ? "Telefon: {$phone}\n" : '')
            . "\nRezerwacja:\n"
            . ($serviceName !== '' ? "Usługa: {$serviceName}\n" : '')
            . ($staffDisplayName !== '' ? "Osoba obsługująca: {$staffDisplayName}\n" : '')
            . ($paymentStatus !== '' ? "Status płatności: {$paymentStatus}\n" : '')
            . "\nZmiana terminu:\n"
            . ($previousDate !== '' ? "Poprzedni termin: {$previousDate}\n" : '')
            . ($newDate !== '' ? "Nowy termin: {$newDate}\n" : '')
            . "\nJeśli rezerwacja była już opłacona, płatność pozostaje bez zmian.\n";

        $mail = new PHPMailer(true);
        booking_mail_configure_mailer($mail, $emailSettings);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $clientSubject;
        $mail->Body = $html;
        $mail->AltBody = $altBody;
        $mail->send();

        if (!empty($emailSettings['send_admin_notification'])) {
            $adminNotifyEmail = trim((string)($emailSettings['admin_notify_email'] ?? ''));

            if ($adminNotifyEmail !== '' && filter_var($adminNotifyEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $adminRows =
                        $section('👤 Dane klienta')
                        . $row('Imię i nazwisko', $name)
                        . $row('E-mail', $email)
                        . $row('Telefon', $phone)
                        . $section('🛠 Rezerwacja')
                        . $row('Usługa', $serviceName)
                        . $row('Osoba obsługująca', $staffDisplayName)
                        . $row('Status płatności', $paymentStatus)
                        . $section('📅 Zmiana terminu')
                        . $row('Poprzedni termin', $previousDate)
                        . $row('Nowy termin', $newDate)
                        . $row('Liczba zmian terminu', $rescheduleCount . ' z ' . $rescheduleLimit);

                    $adminHtml =
                        '<div style="margin:0;padding:0;background:#f4f7fb;">'
                        . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
                        . '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">'
                        . '<div style="font-size:42px;line-height:1;margin-bottom:12px;">🔔</div>'
                        . '<h1 style="margin:0;font-size:28px;">Klient zmienił termin rezerwacji</h1>'
                        . '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>'
                        . '</div>'
                        . '<div style="padding:32px 24px;">'
                        . '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">Klient samodzielnie zmienił termin rezerwacji przez link z wiadomości e-mail.</p>'
                        . '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">'
                        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:15px;">'
                        . $adminRows
                        . '</table>'
                        . '</div>'
                        . '</div>'
                        . $footerHtml
                        . '</div>'
                        . '</div>';

                    $adminAltBody =
                        $adminSubject . "\n\n"
                        . "Klient samodzielnie zmienił termin rezerwacji przez link z wiadomości e-mail.\n\n"
                        . ($name !== '' ? "Imię i nazwisko: {$name}\n" : '')
                        . "E-mail: {$email}\n"
                        . ($phone !== '' ? "Telefon: {$phone}\n" : '')
                        . ($serviceName !== '' ? "Usługa: {$serviceName}\n" : '')
                        . ($staffDisplayName !== '' ? "Osoba obsługująca: {$staffDisplayName}\n" : '')
                        . ($previousDate !== '' ? "Poprzedni termin: {$previousDate}\n" : '')
                        . ($newDate !== '' ? "Nowy termin: {$newDate}\n" : '')
                        . ($paymentStatus !== '' ? "Status płatności: {$paymentStatus}\n" : '')
                        . "Liczba zmian terminu: {$rescheduleCount} z {$rescheduleLimit}\n";

                    $adminMail = new PHPMailer(true);
                    booking_mail_configure_mailer($adminMail, $emailSettings);
                    $adminMail->addAddress($adminNotifyEmail);
                    $adminMail->isHTML(true);
                    $adminMail->Subject = $adminSubject;
                    $adminMail->Body = $adminHtml;
                    $adminMail->AltBody = $adminAltBody;
                    $adminMail->send();
                } catch (Throwable $e) {
                    // Powiadomienie admina nie może blokować maila do klienta ani zmiany terminu.
                }
            }
        }

        return true;
    }
}

if (!function_exists('booking_mail_send_booking_reminder')) {
    function booking_mail_send_booking_reminder(
        array $emailSettings,
        array $tenantData,
        array $booking,
        string $type
    ): bool {
        $type = trim($type);

        if (!in_array($type, ['day_before', 'same_day'], true)) {
            return false;
        }

        $email = trim((string)($booking['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (empty($emailSettings['send_client_confirmation'])) {
            return false;
        }

        $name = trim((string)($booking['name'] ?? ''));
        $displayName = $name !== '' ? $name : 'Kliencie';
        $date = trim((string)($booking['booking_date'] ?? $booking['date'] ?? ''));
        $time = trim((string)($booking['booking_time'] ?? $booking['time'] ?? ''));
        $serviceName = trim((string)($booking['service_name_snapshot'] ?? $booking['service_name'] ?? ''));
        $staffDisplayName = trim((string)($booking['staff_display_name'] ?? ''));

        $companyName = trim((string)($tenantData['client_name'] ?? ''));
        $plan = (string)($tenantData['plan'] ?? 'basic');
        $footerMode = (string)($tenantData['email_footer_mode'] ?? 'system');
        $footerCustom = (string)($tenantData['email_footer_custom'] ?? '');
        $footerHtml = booking_mail_build_footer($plan, $footerMode, $footerCustom);

        $subject = $type === 'day_before'
            ? 'Przypomnienie o jutrzejszej wizycie'
            : 'Przypomnienie o dzisiejszej wizycie';

        $headline = $subject;
        $intro = $type === 'day_before'
            ? 'przypominamy o jutrzejszej rezerwacji.'
            : 'przypominamy, że Twoja wizyta jest zaplanowana na dziś.';

        $row = static function (string $label, string $value): string {
            $value = trim($value);

            if ($value === '') {
                return '';
            }

            return '<tr><td style="padding:8px 0;color:#607284;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</td>'
                . '<td style="padding:8px 0;text-align:right;"><strong>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>';
        };

        $termText = trim($date . ($time !== '' ? ' o ' . $time : ''));
        $detailsRows = '';

        if ($serviceName !== '') {
            $detailsRows .= $row('Usługa', $serviceName);
        }

        if ($staffDisplayName !== '') {
            $detailsRows .= $row('Osoba obsługująca', $staffDisplayName);
        }

        if ($type === 'day_before') {
            $detailsRows .= $row('Termin', $termText);
        } else {
            $detailsRows .= $row('Godzina', $time);
        }

        $closing = $type === 'day_before'
            ? 'W razie pytań możesz odpowiedzieć na tę wiadomość.'
            : 'Do zobaczenia.';

        $html =
            '<div style="margin:0;padding:0;background:#f4f7fb;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
            . '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">'
            . '<h1 style="margin:0;font-size:28px;">' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</h1>'
            . ($companyName !== ''
                ? '<p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>'
                : '')
            . '</div>'
            . '<div style="padding:32px 24px;">'
            . '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">Dzień dobry ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
            . ($detailsRows !== ''
                ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">'
                    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:15px;">'
                    . $detailsRows
                    . '</table>'
                    . '</div>'
                : '')
            . '<p style="font-size:14px;line-height:1.6;color:#4f6478;">' . htmlspecialchars($closing, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>'
            . $footerHtml
            . '</div>'
            . '</div>';

        $altBody =
            $subject . "\n\n"
            . "Dzień dobry {$displayName},\n\n"
            . $intro . "\n\n"
            . ($serviceName !== '' ? "Usługa: {$serviceName}\n" : '')
            . ($staffDisplayName !== '' ? "Osoba obsługująca: {$staffDisplayName}\n" : '')
            . ($type === 'day_before'
                ? ($termText !== '' ? "Termin: {$termText}\n" : '')
                : ($time !== '' ? "Godzina: {$time}\n" : ''))
            . "\n{$closing}\n";

        $mail = new PHPMailer(true);
        booking_mail_configure_mailer($mail, $emailSettings);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $altBody;
        $mail->send();

        return true;
    }
}
