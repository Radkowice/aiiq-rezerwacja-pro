<?php
declare(strict_types=1);

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/php_mail.php';
require_once __DIR__ . '/crypto.php';

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

if (!function_exists('booking_mail_has_usable_smtp')) {
    function booking_mail_has_usable_smtp(?array $emailSettings): bool
    {
        if (!$emailSettings) {
            return false;
        }

        $smtpHost = trim((string)($emailSettings['smtp_host'] ?? ''));
        $fromEmail = trim((string)($emailSettings['smtp_email'] ?? $emailSettings['from_email'] ?? ''));

        return $smtpHost !== '' && $fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('booking_mail_default_client_template')) {
    function booking_mail_default_client_template(): array
    {
        return [
            'subject' => 'Potwierdzenie rezerwacji - {date} {time}',
            'service_name' => 'Dziękujemy za rezerwację',
            'body_html' => '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">Twoja rezerwacja została przyjęta. Szczegóły znajdziesz poniżej.</p>',
        ];
    }
}

if (!function_exists('booking_mail_company_contact_email')) {
    function booking_mail_company_contact_email(array $tenantData, ?array $emailSettings = null): string
    {
        $candidates = [
            $tenantData['company_email'] ?? '',
            $tenantData['admin_email'] ?? '',
            $tenantData['contact_email'] ?? '',
            $tenantData['email'] ?? '',
            $emailSettings['reply_to_email'] ?? '',
            $emailSettings['admin_notify_email'] ?? '',
            $emailSettings['smtp_email'] ?? '',
            $emailSettings['from_email'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $email = trim((string)$candidate);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }
}

if (!function_exists('booking_mail_payment_status_label')) {
    function booking_mail_payment_status_label(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid', 'completed', 'success' => 'Opłacono',
            'pending', 'pending_payment' => 'Oczekuje na płatność',
            'cancelled', 'canceled' => 'Anulowano',
            'failed' => 'Płatność nieudana',
            'expired' => 'Płatność wygasła',
            'not_required' => 'Nie wymaga płatności',
            default => '',
        };
    }
}

if (!function_exists('booking_mail_system_confirmation_html')) {
    function booking_mail_system_confirmation_html(array $tenantData, array $booking, array $payment = [], string $companyEmail = ''): string
    {
        $companyName = trim((string)($tenantData['client_name'] ?? $tenantData['company_full_name'] ?? ''));
        $serviceName = trim((string)($booking['service_name_snapshot'] ?? $booking['service_name'] ?? ''));
        $name = trim((string)($booking['name'] ?? ''));
        $email = trim((string)($booking['email'] ?? ''));
        $phone = trim((string)($booking['phone'] ?? ''));
        $date = trim((string)($booking['booking_date'] ?? $booking['date'] ?? ''));
        $time = trim((string)($booking['booking_time'] ?? $booking['time'] ?? ''));
        $staffDisplayName = trim((string)($booking['staff_display_name'] ?? ''));
        $paymentStatus = trim((string)($payment['status_label'] ?? ''));

        if ($paymentStatus === '') {
            $paymentStatus = booking_mail_payment_status_label((string)($booking['payment_status'] ?? ''));
        }

        $amountText = booking_mail_format_amount(
            $payment['amount'] ?? $booking['payment_amount'] ?? null,
            (string)($payment['currency'] ?? $booking['payment_currency'] ?? 'PLN')
        );

        $row = static function (string $icon, string $label, string $value): string {
            if (trim($value) === '') {
                return '';
            }

            return '<tr>'
                . '<td style="padding:8px 0;color:#6b7280;"><span style="display:inline-block;width:24px;" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</td>'
                . '<td style="padding:8px 0;text-align:right;"><strong>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong></td>'
                . '</tr>';
        };

        $message = ''
            . '<p style="margin:0 0 14px;"><strong>Twoja rezerwacja została potwierdzona.</strong></p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;border-collapse:collapse;">'
            . $row('🏢', 'Firma', $companyName)
            . $row('📋', 'Usługa', $serviceName)
            . $row('📅', 'Data', $date)
            . $row('🕒', 'Godzina', $time)
            . $row('🙋', 'Osoba obsługująca', $staffDisplayName)
            . $row('💳', 'Status płatności', $paymentStatus)
            . $row('💰', 'Kwota', $amountText)
            . $row('👤', 'Klient', $name)
            . $row('✉️', 'Twój e-mail', $email)
            . $row('📞', 'Twój telefon', $phone)
            . $row('📬', 'Kontakt do firmy', $companyEmail)
            . '</table>'
            . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">To jest automatyczna wiadomość wysłana przez system RezerwIQ.</p>'
            . '<p style="margin:10px 0 0;color:#374151;line-height:1.6;">Odpowiedzi na ten adres mogą nie być obsługiwane.</p>'
            . '<p style="margin:10px 0 0;color:#374151;line-height:1.6;">W sprawie rezerwacji skontaktuj się z firmą: <strong>' . htmlspecialchars($companyEmail !== '' ? $companyEmail : 'brak adresu kontaktowego', ENT_QUOTES, 'UTF-8') . '</strong>.</p>';

        return buildSystemMailLayout(
            'Potwierdzenie rezerwacji',
            'Podstawowe potwierdzenie rezerwacji wysłane przez RezerwIQ.',
            $message,
            'Wiadomość została wysłana awaryjnie, ponieważ firma nie skonfigurowała własnej wysyłki e-mail lub szablonu wiadomości.'
        );
    }
}

if (!function_exists('booking_mail_send_system_confirmation')) {
    function booking_mail_send_system_confirmation(array $tenantData, array $booking, array $payment = [], ?array $emailSettings = null): bool
    {
        $email = trim((string)($booking['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $companyEmail = booking_mail_company_contact_email($tenantData, $emailSettings);
        $html = booking_mail_system_confirmation_html($tenantData, $booking, $payment, $companyEmail);
        $date = trim((string)($booking['booking_date'] ?? $booking['date'] ?? ''));
        $time = trim((string)($booking['booking_time'] ?? $booking['time'] ?? ''));
        $subject = trim('Potwierdzenie rezerwacji ' . $date . ' ' . $time);

        return sendSystemMail(
            $email,
            $subject !== 'Potwierdzenie rezerwacji' ? $subject : 'Potwierdzenie rezerwacji',
            $html,
            $companyEmail !== '' ? $companyEmail : null,
            trim((string)($tenantData['client_name'] ?? $tenantData['company_full_name'] ?? ''))
        );
    }
}

if (!function_exists('booking_mail_send_client_confirmation_with_fallback')) {
    function booking_mail_send_client_confirmation_with_fallback(
        ?array $emailSettings,
        ?array $emailTemplate,
        array $tenantData,
        array $booking,
        array $payment = []
    ): bool {
        unset($booking['notes'], $booking['note'], $booking['message']);
        $hasSmtp = booking_mail_has_usable_smtp($emailSettings);
        $hasTemplate = is_array($emailTemplate) && trim((string)($emailTemplate['body_html'] ?? '')) !== '';

        if ($hasSmtp) {
            $settingsForSend = $emailSettings;
            $settingsForSend['send_client_confirmation'] = true;
            $templateForSend = $hasTemplate ? $emailTemplate : booking_mail_default_client_template();

            try {
                if (booking_mail_send_client_confirmation(
                    $settingsForSend,
                    $templateForSend,
                    $tenantData,
                    $booking,
                    $payment
                )) {
                    return true;
                }
            } catch (Throwable $e) {
                error_log('BOOKING_MAIL_FALLBACK_SMTP_FAILED: ' . get_class($e));
            }
        }

        return booking_mail_send_system_confirmation($tenantData, $booking, $payment, $emailSettings);
    }
}

if (!function_exists('booking_mail_admin_notification_enabled')) {
    function booking_mail_admin_notification_enabled(?array $emailSettings): bool
    {
        return !is_array($emailSettings)
            || !array_key_exists('send_admin_notification', $emailSettings)
            || !empty($emailSettings['send_admin_notification']);
    }
}

if (!function_exists('booking_mail_admin_notification_email')) {
    function booking_mail_admin_notification_email(
        array $tenantData,
        ?array $emailSettings = null,
        string $adminAccountEmail = ''
    ): string {
        $candidates = [
            $emailSettings['admin_notify_email'] ?? '',
            $adminAccountEmail,
            $tenantData['admin_email'] ?? '',
            $tenantData['company_email'] ?? '',
            $tenantData['contact_email'] ?? '',
            $tenantData['email'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $email = trim((string)$candidate);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }
}

if (!function_exists('booking_mail_system_admin_notification_html')) {
    function booking_mail_system_admin_notification_html(array $tenantData, array $booking): string
    {
        $companyName = trim((string)($tenantData['client_name'] ?? $tenantData['company_full_name'] ?? ''));
        $name = trim((string)($booking['name'] ?? ''));
        $email = trim((string)($booking['email'] ?? ''));
        $phone = trim((string)($booking['phone'] ?? ''));
        $date = trim((string)($booking['booking_date'] ?? $booking['date'] ?? ''));
        $time = trim((string)($booking['booking_time'] ?? $booking['time'] ?? ''));
        $serviceName = trim((string)($booking['service_name_snapshot'] ?? $booking['service_name'] ?? ''));
        $staffDisplayName = trim((string)($booking['staff_display_name'] ?? ''));
        $hasClientMessage = !empty($booking['has_client_message']);

        $row = static function (string $icon, string $label, string $value): string {
            if ($value === '') {
                return '';
            }

            return '<tr>'
                . '<td style="padding:8px 0;color:#6b7280;"><span style="display:inline-block;width:24px;" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</td>'
                . '<td style="padding:8px 0;text-align:right;"><strong>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong></td>'
                . '</tr>';
        };

        $message = ''
            . '<p style="margin:0 0 14px;"><strong>W systemie pojawiła się nowa rezerwacja.</strong></p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;border-collapse:collapse;">'
            . $row('🏢', 'Firma', $companyName)
            . $row('👤', 'Klient', $name)
            . $row('✉️', 'E-mail', $email)
            . $row('📞', 'Telefon', $phone)
            . $row('📋', 'Usługa', $serviceName)
            . $row('🙋', 'Personel', $staffDisplayName)
            . $row('📅', 'Data', $date)
            . $row('🕒', 'Godzina', $time)
            . $row(
                '📝',
                'Wiadomość klienta',
                $hasClientMessage ? 'Wiadomość od klienta zobaczysz w swoim panelu rezerwacji.' : ''
            )
            . '</table>'
            . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Powiadomienie zostało wysłane z systemowego adresu AI-IQ, ponieważ własna wysyłka SMTP nie jest skonfigurowana lub chwilowo nie zadziałała.</p>';

        return buildSystemMailLayout(
            'Nowa rezerwacja',
            'Powiadomienie administratora o rezerwacji zapisanej w systemie.',
            $message,
            'Wysłanie tego powiadomienia nie wpływa na zapis rezerwacji.'
        );
    }
}

if (!function_exists('booking_mail_send_admin_notification_with_fallback')) {
    function booking_mail_send_admin_notification_with_fallback(
        ?array $emailSettings,
        array $tenantData,
        array $booking,
        string $recipient,
        string $subject,
        string $tenantHtml,
        string $tenantAltBody
    ): bool {
        $recipient = trim($recipient);
        $hasClientMessage = !empty($booking['has_client_message'])
            || trim((string)($booking['notes'] ?? $booking['note'] ?? $booking['message'] ?? '')) !== '';
        unset($booking['notes'], $booking['note'], $booking['message']);
        $booking['has_client_message'] = $hasClientMessage;

        if (
            !booking_mail_admin_notification_enabled($emailSettings)
            || $recipient === ''
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
        ) {
            return false;
        }

        if (booking_mail_has_usable_smtp($emailSettings)) {
            try {
                $mail = new PHPMailer(true);
                booking_mail_configure_mailer($mail, $emailSettings);
                $mail->addAddress($recipient);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $tenantHtml;
                $mail->AltBody = $tenantAltBody;
                $mail->send();

                return true;
            } catch (Throwable $e) {
                error_log('BOOKING_ADMIN_MAIL_SMTP_FAILED: ' . get_class($e));
            }
        }

        $companyEmail = booking_mail_company_contact_email($tenantData, $emailSettings);

        return sendSystemMail(
            $recipient,
            $subject,
            booking_mail_system_admin_notification_html($tenantData, $booking),
            $companyEmail !== '' ? $companyEmail : null,
            trim((string)($tenantData['client_name'] ?? $tenantData['company_full_name'] ?? ''))
        );
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

        $smtpPass = decrypt_smtp_password_secret((string) (
            $emailSettings['smtp_pass']
            ?? $emailSettings['smtp_password']
            ?? ''
        ));

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

        if (!in_array($smtpPort, booking_mail_v7_allowed_tenant_smtp_ports(), true)) {
            throw new Exception('Niedozwolony port SMTP');
        }

        $smtpTarget = booking_mail_v7_public_smtp_target($smtpHost);
        if (empty($smtpTarget['ok'])) {
            throw new Exception('Niedozwolony host SMTP');
        }

        $mail->isSMTP();
        $mail->Host = (string) $smtpTarget['connect_host'];
        $mail->Port = $smtpPort;
        $mail->SMTPAuth = $smtpUser !== '' || $smtpPass !== '';
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 12;
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = static function (): void {
            // Nie logujemy transcriptu SMTP: może zawierać PII i dane uwierzytelniające.
        };
        $mail->SMTPKeepAlive = false;
        $mail->SMTPAutoTLS = false;
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

        $encryption = strtolower(trim((string) ($emailSettings['smtp_encryption'] ?? 'tls')));

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === '') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            throw new Exception('Niedozwolone szyfrowanie SMTP');
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
            '{message}' => '',
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

if (!function_exists('booking_mail_reminder_subject_context')) {
    function booking_mail_reminder_subject_context(array $tenantData, array $booking): string
    {
        $context = trim((string)($booking['service_name_snapshot'] ?? $booking['service_name'] ?? ''));

        if ($context === '') {
            $context = trim((string)(
                $tenantData['client_name']
                ?? $tenantData['company_full_name']
                ?? $tenantData['company_name']
                ?? ''
            ));
        }

        if ($context === '') {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($context, 'UTF-8') > 64) {
            return rtrim(mb_substr($context, 0, 61, 'UTF-8')) . '...';
        }

        if (!function_exists('mb_strlen') && strlen($context) > 64) {
            return rtrim(substr($context, 0, 61)) . '...';
        }

        return $context;
    }
}

if (!function_exists('booking_mail_booking_reminder_subject')) {
    function booking_mail_booking_reminder_subject(array $tenantData, array $booking, string $type): string
    {
        $base = $type === 'day_before'
            ? 'Przypomnienie o jutrzejszej wizycie'
            : 'Przypomnienie o dzisiejszej wizycie';
        $context = booking_mail_reminder_subject_context($tenantData, $booking);

        return $context !== '' ? $base . ': ' . $context : $base;
    }
}

if (!function_exists('booking_mail_send_system_booking_reminder')) {
    function booking_mail_send_system_booking_reminder(
        array $tenantData,
        array $booking,
        string $type,
        ?array $emailSettings = null
    ): bool {
        $type = trim($type);

        if (!in_array($type, ['day_before', 'same_day'], true)) {
            return false;
        }

        $email = trim((string)($booking['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $name = trim((string)($booking['name'] ?? ''));
        $displayName = $name !== '' ? $name : 'Kliencie';
        $date = trim((string)($booking['booking_date'] ?? $booking['date'] ?? ''));
        $time = trim((string)($booking['booking_time'] ?? $booking['time'] ?? ''));
        $serviceName = trim((string)($booking['service_name_snapshot'] ?? $booking['service_name'] ?? ''));
        $staffDisplayName = trim((string)($booking['staff_display_name'] ?? ''));
        $companyEmail = booking_mail_company_contact_email($tenantData, $emailSettings);
        $subject = booking_mail_booking_reminder_subject($tenantData, $booking, $type);
        $intro = $type === 'day_before'
            ? 'przypominamy o jutrzejszej rezerwacji.'
            : 'przypominamy, że Twoja wizyta jest zaplanowana na dziś.';

        $row = static function (string $label, string $value): string {
            $value = trim($value);

            if ($value === '') {
                return '';
            }

            return '<tr><td style="padding:8px 0;color:#6b7280;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</td>'
                . '<td style="padding:8px 0;text-align:right;"><strong>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>';
        };

        $termText = trim($date . ($time !== '' ? ' o ' . $time : ''));
        $detailsRows = ''
            . $row('📋 Usługa', $serviceName)
            . $row('👥 Osoba obsługująca', $staffDisplayName)
            . ($type === 'day_before' ? $row('📅 Termin', $termText) : $row('🕒 Godzina', $time));
        $contactText = $companyEmail !== '' ? $companyEmail : 'brak adresu kontaktowego';
        $reminderIcon = $type === 'day_before' ? '📅' : '⏰';

        $message = ''
            . '<div style="font-size:36px;line-height:1;margin:0 0 12px;text-align:center;">' . $reminderIcon . '</div>'
            . '<p style="margin:0 0 14px;"><strong>' . $reminderIcon . ' Przypomnienie o rezerwacji.</strong></p>'
            . '<p style="margin:0 0 14px;">Dzień dobry ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ', ' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
            . ($detailsRows !== ''
                ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;border-collapse:collapse;">' . $detailsRows . '</table>'
                : '')
            . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Ta wiadomość została wysłana z adresu systemowego, ponieważ usługodawca nie skonfigurował własnej skrzynki e-mail. W razie potrzeby skontaktuj się z usługodawcą: <strong>' . htmlspecialchars($contactText, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';

        $html = buildSystemMailLayout(
            $subject,
            'Przypomnienie o rezerwacji.',
            $message,
            'To przypomnienie zostało wysłane przez system RezerwIQ.'
        );

        return sendSystemMail(
            $email,
            $subject,
            $html,
            $companyEmail !== '' ? $companyEmail : null,
            trim((string)($tenantData['client_name'] ?? $tenantData['company_full_name'] ?? ''))
        );
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

        $subject = booking_mail_booking_reminder_subject($tenantData, $booking, $type);

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
            $detailsRows .= $row('📋 Usługa', $serviceName);
        }

        if ($staffDisplayName !== '') {
            $detailsRows .= $row('👥 Osoba obsługująca', $staffDisplayName);
        }

        if ($type === 'day_before') {
            $detailsRows .= $row('📅 Termin', $termText);
        } else {
            $detailsRows .= $row('🕒 Godzina', $time);
        }

        $closing = $type === 'day_before'
            ? 'W razie pytań możesz odpowiedzieć na tę wiadomość.'
            : 'Do zobaczenia.';

        $html =
            '<div style="margin:0;padding:0;background:#f4f7fb;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
            . '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">'
            . '<div style="font-size:42px;line-height:1;margin-bottom:12px;">' . ($type === 'day_before' ? '📅' : '⏰') . '</div>'
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

if (!function_exists('booking_mail_send_booking_reminder_with_fallback')) {
    function booking_mail_send_booking_reminder_with_fallback(
        ?array $emailSettings,
        array $tenantData,
        array $booking,
        string $type
    ): bool {
        if (booking_mail_has_usable_smtp($emailSettings)) {
            try {
                $settingsForSend = $emailSettings;
                $settingsForSend['send_client_confirmation'] = true;

                if (booking_mail_send_booking_reminder($settingsForSend, $tenantData, $booking, $type)) {
                    return true;
                }
            } catch (Throwable $e) {
                error_log('BOOKING_REMINDER_FALLBACK_SMTP_FAILED: ' . get_class($e));
            }
        }

        return booking_mail_send_system_booking_reminder($tenantData, $booking, $type, $emailSettings);
    }
}

/*
 * Booking V7 SMTP transport adapter.
 * Dormant until a DB-claim worker calls booking_mail_v7_deliver().
 * Legacy mail flows above remain unchanged.
 */
if (!class_exists('BookingMailV7TrackedSMTP', false)) {
    class BookingMailV7TrackedSMTP extends \PHPMailer\PHPMailer\SMTP
    {
        private string $v7DataState = 'not_started';
        private int $v7DataSmtpCode = 0;
        private bool $v7AuthAttempted = false;
        private bool $v7AuthAccepted = false;
        private int $v7AuthSmtpCode = 0;
        private bool $v7RecipientAttempted = false;
        private bool $v7RecipientAccepted = false;
        private int $v7RecipientSmtpCode = 0;

        public function authenticate($username, $password, $authtype = null, $OAuth = null)
        {
            $this->v7AuthAttempted = true;
            $ok = parent::authenticate($username, $password, $authtype, $OAuth);
            $this->v7AuthAccepted = $ok;
            if (!$ok) {
                $error = $this->getError();
                $this->v7AuthSmtpCode = (int)($error['smtp_code'] ?? 0);
            }

            return $ok;
        }

        public function recipient($address, $dsn = '')
        {
            $this->v7RecipientAttempted = true;
            $ok = parent::recipient($address, $dsn);
            $this->v7RecipientAccepted = $ok;
            if (!$ok) {
                $error = $this->getError();
                $this->v7RecipientSmtpCode = (int)($error['smtp_code'] ?? 0);
            }

            return $ok;
        }

        public function data($msg_data)
        {
            $this->v7DataState = 'data_started';
            $this->v7DataSmtpCode = 0;

            try {
                $ok = parent::data($msg_data);
            } catch (\Throwable $e) {
                $this->v7DataState = 'result_unknown';
                throw $e;
            }

            if ($ok) {
                $this->v7DataState = 'accepted';
                return true;
            }

            $error = $this->getError();
            $errorName = trim((string)($error['error'] ?? ''));
            $smtpCode = (int)($error['smtp_code'] ?? 0);
            $this->v7DataSmtpCode = $smtpCode;

            if (str_starts_with($errorName, 'DATA command failed')) {
                $this->v7DataState = 'data_command_rejected';
            } elseif (
                str_starts_with($errorName, 'DATA END command failed')
                && $smtpCode >= 400
                && $smtpCode <= 599
            ) {
                $this->v7DataState = 'data_end_rejected';
            } else {
                $this->v7DataState = 'result_unknown';
            }

            return false;
        }

        public function v7DataState(): string
        {
            return $this->v7DataState;
        }

        public function v7DataSmtpCode(): int
        {
            return $this->v7DataSmtpCode;
        }

        public function v7AuthFailureSmtpCode(): int
        {
            return $this->v7AuthAttempted && !$this->v7AuthAccepted
                ? $this->v7AuthSmtpCode
                : 0;
        }

        public function v7RecipientFailureSmtpCode(): int
        {
            return $this->v7RecipientAttempted && !$this->v7RecipientAccepted
                ? $this->v7RecipientSmtpCode
                : 0;
        }
    }
}

if (!function_exists('booking_mail_v7_result')) {
    function booking_mail_v7_result(
        string $result,
        string $errorCode = '',
        ?string $deliverySource = null
    ): array {
        $allowedResults = ['sent', 'failed', 'blocked'];
        $allowedCodes = [
            '',
            'mail_input_invalid',
            'mail_delivery_channel_invalid',
            'mail_tenant_binding_invalid',
            'smtp_configuration_missing',
            'smtp_configuration_invalid',
            'smtp_insecure_configuration',
            'smtp_host_invalid',
            'smtp_host_not_public',
            'smtp_host_unresolved',
            'smtp_transport_failure',
            'smtp_temporary_reject',
            'smtp_permanent_reject',
            'delivery_result_unknown',
            'mail_adapter_failed',
        ];
        $allowedSources = [null, 'tenant_smtp', 'system_smtp'];

        if (
            !in_array($result, $allowedResults, true)
            || !in_array($errorCode, $allowedCodes, true)
            || !in_array($deliverySource, $allowedSources, true)
            || ($result === 'sent' && $errorCode !== '')
        ) {
            return [
                'result' => 'blocked',
                'error_code' => 'mail_adapter_failed',
                'delivery_source' => null,
            ];
        }

        return [
            'result' => $result,
            'error_code' => $errorCode,
            'delivery_source' => $deliverySource,
        ];
    }
}

if (!function_exists('booking_mail_v7_valid_identifier')) {
    function booking_mail_v7_valid_identifier(string $value, int $maxBytes = 128): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maxBytes
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}

if (!function_exists('booking_mail_v7_allowed_tenant_smtp_ports')) {
    function booking_mail_v7_allowed_tenant_smtp_ports(): array
    {
        $default = [25, 465, 587, 2525];
        $raw = trim((string)getenv('SMTP_TENANT_ALLOWED_PORTS'));

        if ($raw === '') {
            return $default;
        }

        $ports = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }

            $port = (int)$part;
            if ($port >= 1 && $port <= 65535) {
                $ports[$port] = $port;
            }

            if (count($ports) >= 16) {
                break;
            }
        }

        return $ports !== [] ? array_values($ports) : $default;
    }
}

if (!function_exists('booking_mail_v7_public_smtp_target')) {
    function booking_mail_v7_public_smtp_target(string $host): array
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
            return ['ok' => false, 'retryable' => false, 'error_code' => 'smtp_host_invalid'];
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (
                filter_var(
                    $literal,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                return ['ok' => false, 'retryable' => false, 'error_code' => 'smtp_host_not_public'];
            }

            return [
                'ok' => true,
                'retryable' => false,
                'error_code' => '',
                'connect_host' => str_contains($literal, ':') ? '[' . $literal . ']' : $literal,
                'peer_name' => $literal,
            ];
        }

        if (
            !PHPMailer::isValidHost($host)
            || !str_contains($host, '.')
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
        ) {
            return ['ok' => false, 'retryable' => false, 'error_code' => 'smtp_host_invalid'];
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
            return ['ok' => false, 'retryable' => true, 'error_code' => 'smtp_host_unresolved'];
        }

        foreach ($ips as $ip) {
            if (
                filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                return ['ok' => false, 'retryable' => false, 'error_code' => 'smtp_host_not_public'];
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
            'retryable' => false,
            'error_code' => '',
            'connect_host' => str_contains((string)$selected, ':') ? '[' . $selected . ']' : (string)$selected,
            'peer_name' => $host,
        ];
    }
}

if (!function_exists('booking_mail_v7_validate_message')) {
    function booking_mail_v7_validate_message(
        string $tenantId,
        string $recipientEmail,
        string $subject,
        string $html,
        string &$altBody
    ): ?array {
        if (!booking_mail_v7_valid_identifier($tenantId)) {
            return booking_mail_v7_result('blocked', 'mail_input_invalid');
        }

        $recipientEmail = trim($recipientEmail);
        if (
            $recipientEmail === ''
            || strlen($recipientEmail) > 254
            || preg_match('/[\r\n\x00]/', $recipientEmail) === 1
            || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            return booking_mail_v7_result('blocked', 'mail_input_invalid');
        }

        $subject = trim($subject);
        if (
            $subject === ''
            || strlen($subject) > 255
            || preg_match('/[\r\n\x00]/', $subject) === 1
        ) {
            return booking_mail_v7_result('blocked', 'mail_input_invalid');
        }

        if ($html === '' || strlen($html) > 262144 || str_contains($html, "\0")) {
            return booking_mail_v7_result('blocked', 'mail_input_invalid');
        }

        if ($altBody === '') {
            $altBody = trim(
                html_entity_decode(
                    strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );
        }

        if (strlen($altBody) > 131072 || str_contains($altBody, "\0")) {
            return booking_mail_v7_result('blocked', 'mail_input_invalid');
        }

        return null;
    }
}

if (!function_exists('booking_mail_v7_classify_send')) {
    function booking_mail_v7_classify_send(
        BookingMailV7TrackedSMTP $smtp,
        string $deliverySource
    ): array {
        $dataState = $smtp->v7DataState();
        if ($dataState === 'accepted') {
            return booking_mail_v7_result('sent', '', $deliverySource);
        }

        if ($dataState === 'result_unknown' || $dataState === 'data_started') {
            return booking_mail_v7_result('blocked', 'delivery_result_unknown', $deliverySource);
        }

        $smtpCode = $smtp->v7DataSmtpCode();
        if ($smtpCode === 0) {
            $smtpCode = $smtp->v7RecipientFailureSmtpCode();
        }
        if ($smtpCode === 0) {
            $smtpCode = $smtp->v7AuthFailureSmtpCode();
        }
        if ($smtpCode === 0) {
            $error = $smtp->getError();
            $smtpCode = (int)($error['smtp_code'] ?? 0);
        }

        if ($smtpCode >= 500 && $smtpCode <= 599) {
            return booking_mail_v7_result('blocked', 'smtp_permanent_reject', $deliverySource);
        }

        if ($smtpCode >= 400 && $smtpCode <= 499) {
            return booking_mail_v7_result('failed', 'smtp_temporary_reject', $deliverySource);
        }

        return booking_mail_v7_result('failed', 'smtp_transport_failure', $deliverySource);
    }
}

if (!function_exists('booking_mail_v7_send_configured')) {
    function booking_mail_v7_send_configured(
        PHPMailer $mail,
        BookingMailV7TrackedSMTP $smtp,
        string $deliverySource
    ): array {
        try {
            $mail->send();
        } catch (\Throwable $e) {
            // Never log or return PHPMailer/cURL/SMTP raw errors; they may contain PII or credentials.
        }

        return booking_mail_v7_classify_send($smtp, $deliverySource);
    }
}

if (!function_exists('booking_mail_v7_prepare_common_mailer')) {
    function booking_mail_v7_prepare_common_mailer(
        PHPMailer $mail,
        BookingMailV7TrackedSMTP $smtp,
        string $connectHost,
        string $peerName,
        int $port,
        string $encryption,
        bool $smtpAuth,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName,
        string $recipientEmail,
        string $subject,
        string $html,
        string $altBody,
        ?string $replyToEmail,
        string $replyToName,
        int $timeout,
        int $timeLimit
    ): void {
        $mail->setSMTPInstance($smtp);
        $mail->isSMTP();
        $mail->Host = $connectHost;
        $mail->Port = $port;
        $mail->SMTPAuth = $smtpAuth;
        $mail->Username = $smtpAuth ? $username : '';
        $mail->Password = $smtpAuth ? $password : '';
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = $timeout;
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = static function (): void {
            // Intentionally discard SMTP transcript to prevent credential/PII leakage.
        };
        $mail->SMTPKeepAlive = false;
        $mail->SMTPAutoTLS = false;
        $smtp->Timelimit = $timeLimit;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $peerName,
                'SNI_enabled' => true,
            ],
        ];

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : $fromEmail);
        $mail->addAddress($recipientEmail);

        if ($replyToEmail !== null && $replyToEmail !== '') {
            $mail->addReplyTo($replyToEmail, $replyToName !== '' ? $replyToName : $replyToEmail);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $altBody;
    }
}

if (!function_exists('booking_mail_v7_send_tenant_smtp')) {
    function booking_mail_v7_send_tenant_smtp(
        string $tenantId,
        array $emailSettings,
        string $recipientEmail,
        string $subject,
        string $html,
        string $altBody
    ): array {
        if (($emailSettings['tenant_id'] ?? null) !== $tenantId) {
            return booking_mail_v7_result('blocked', 'mail_tenant_binding_invalid');
        }

        if (array_key_exists('is_active', $emailSettings) && $emailSettings['is_active'] !== true) {
            return booking_mail_v7_result('blocked', 'smtp_configuration_missing', 'tenant_smtp');
        }

        $host = trim((string)($emailSettings['smtp_host'] ?? ''));
        $port = (int)($emailSettings['smtp_port'] ?? 0);
        $encryption = strtolower(trim((string)($emailSettings['smtp_encryption'] ?? 'tls')));
        $smtpAuth = array_key_exists('smtp_auth', $emailSettings)
            ? (bool)$emailSettings['smtp_auth']
            : true;
        $username = trim((string)($emailSettings['smtp_username'] ?? $emailSettings['smtp_user'] ?? ''));
        $password = decrypt_smtp_password_secret((string)($emailSettings['smtp_password'] ?? $emailSettings['smtp_pass'] ?? ''));
        $fromEmail = trim((string)($emailSettings['from_email'] ?? $emailSettings['smtp_email'] ?? ''));
        $fromName = trim((string)($emailSettings['from_name'] ?? $emailSettings['smtp_name'] ?? ''));
        $replyToEmail = trim((string)($emailSettings['reply_to_email'] ?? ''));
        $replyToName = trim((string)($emailSettings['reply_to_name'] ?? ''));

        if (
            $host === ''
            || !in_array($port, booking_mail_v7_allowed_tenant_smtp_ports(), true)
            || !in_array($encryption, ['ssl', 'tls'], true)
            || ($smtpAuth && ($username === '' || $password === ''))
            || strlen($username) > 255
            || strlen($password) > 4096
            || preg_match('/[\r\n\x00]/', $username) === 1
            || str_contains($password, "\0")
            || $fromEmail === ''
            || strlen($fromEmail) > 254
            || preg_match('/[\r\n\x00]/', $fromEmail) === 1
            || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false
            || strlen($fromName) > 255
            || preg_match('/[\r\n\x00]/', $fromName) === 1
            || strlen($replyToName) > 255
            || preg_match('/[\r\n\x00]/', $replyToName) === 1
        ) {
            $error = $encryption === 'none'
                ? 'smtp_insecure_configuration'
                : 'smtp_configuration_invalid';
            return booking_mail_v7_result('blocked', $error, 'tenant_smtp');
        }

        if ($replyToEmail !== '') {
            if (
                strlen($replyToEmail) > 254
                || preg_match('/[\r\n\x00]/', $replyToEmail) === 1
                || filter_var($replyToEmail, FILTER_VALIDATE_EMAIL) === false
            ) {
                return booking_mail_v7_result('blocked', 'smtp_configuration_invalid', 'tenant_smtp');
            }
        }

        $target = booking_mail_v7_public_smtp_target($host);
        if (($target['ok'] ?? false) !== true) {
            return booking_mail_v7_result(
                !empty($target['retryable']) ? 'failed' : 'blocked',
                (string)($target['error_code'] ?? 'smtp_host_invalid'),
                'tenant_smtp'
            );
        }

        try {
            $mail = new PHPMailer(true);
            $smtp = new BookingMailV7TrackedSMTP();
            booking_mail_v7_prepare_common_mailer(
                $mail,
                $smtp,
                (string)$target['connect_host'],
                (string)$target['peer_name'],
                $port,
                $encryption,
                $smtpAuth,
                $username,
                $password,
                $fromEmail,
                $fromName,
                $recipientEmail,
                $subject,
                $html,
                $altBody,
                $replyToEmail !== '' ? $replyToEmail : null,
                $replyToName,
                15,
                15
            );

            return booking_mail_v7_send_configured($mail, $smtp, 'tenant_smtp');
        } catch (\Throwable $e) {
            return booking_mail_v7_result('blocked', 'smtp_configuration_invalid', 'tenant_smtp');
        }
    }
}

if (!function_exists('booking_mail_v7_send_system_smtp')) {
    function booking_mail_v7_send_system_smtp(
        string $recipientEmail,
        string $subject,
        string $html,
        string $altBody,
        ?array $emailSettings = null
    ): array {
        $host = trim(systemMailEnv('SMTP_SYSTEM_HOST'));
        $port = (int)systemMailEnv('SMTP_SYSTEM_PORT', '587');
        $username = trim(systemMailEnv('SMTP_SYSTEM_USER'));
        $password = systemMailEnv('SMTP_SYSTEM_PASS');
        $fromEmail = trim(systemMailEnv('SMTP_SYSTEM_FROM'));
        $fromName = trim(systemMailEnv('SMTP_SYSTEM_NAME', 'AI-IQ'));
        $encryption = strtolower(trim(systemMailEnv('SMTP_SYSTEM_ENCRYPTION', 'tls')));
        $timeout = max(5, min(30, (int)systemMailEnv('SMTP_SYSTEM_TIMEOUT', '20')));
        $timeLimit = max(5, min(30, (int)systemMailEnv('SMTP_SYSTEM_TIMELIMIT', '20')));

        $replyToEmail = '';
        $replyToName = '';
        if (is_array($emailSettings)) {
            $candidate = trim((string)($emailSettings['reply_to_email'] ?? $emailSettings['from_email'] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $replyToEmail = $candidate;
                $replyToName = trim((string)($emailSettings['reply_to_name'] ?? $emailSettings['from_name'] ?? ''));
            }
        }

        if (
            $host === ''
            || $port < 1
            || $port > 65535
            || $username === ''
            || $password === ''
            || strlen($username) > 255
            || strlen($password) > 4096
            || preg_match('/[\r\n\x00]/', $username) === 1
            || str_contains($password, "\0")
            || !in_array($encryption, ['ssl', 'smtps', 'tls', 'starttls'], true)
            || $fromEmail === ''
            || strlen($fromEmail) > 254
            || preg_match('/[\r\n\x00]/', $fromEmail) === 1
            || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false
            || strlen($fromName) > 255
            || preg_match('/[\r\n\x00]/', $fromName) === 1
            || strlen($replyToName) > 255
            || preg_match('/[\r\n\x00]/', $replyToName) === 1
        ) {
            return booking_mail_v7_result('blocked', 'smtp_configuration_missing', 'system_smtp');
        }

        $target = booking_mail_v7_public_smtp_target($host);
        if (($target['ok'] ?? false) !== true) {
            return booking_mail_v7_result(
                !empty($target['retryable']) ? 'failed' : 'blocked',
                (string)($target['error_code'] ?? 'smtp_host_invalid'),
                'system_smtp'
            );
        }

        try {
            $mail = new PHPMailer(true);
            $smtp = new BookingMailV7TrackedSMTP();
            booking_mail_v7_prepare_common_mailer(
                $mail,
                $smtp,
                (string)$target['connect_host'],
                (string)$target['peer_name'],
                $port,
                in_array($encryption, ['ssl', 'smtps'], true) ? 'ssl' : 'tls',
                true,
                $username,
                $password,
                $fromEmail,
                $fromName,
                $recipientEmail,
                $subject,
                $html,
                $altBody,
                $replyToEmail !== '' ? $replyToEmail : null,
                $replyToName,
                $timeout,
                $timeLimit
            );

            return booking_mail_v7_send_configured($mail, $smtp, 'system_smtp');
        } catch (\Throwable $e) {
            return booking_mail_v7_result('blocked', 'smtp_configuration_invalid', 'system_smtp');
        }
    }
}

if (!function_exists('booking_mail_v7_deliver')) {
    function booking_mail_v7_deliver(
        string $tenantId,
        string $deliveryChannel,
        string $recipientEmail,
        string $subject,
        string $html,
        string $altBody = '',
        ?array $emailSettings = null
    ): array {
        $tenantId = trim($tenantId);
        $deliveryChannel = trim($deliveryChannel);
        $recipientEmail = trim($recipientEmail);
        $subject = trim($subject);

        $validation = booking_mail_v7_validate_message(
            $tenantId,
            $recipientEmail,
            $subject,
            $html,
            $altBody
        );
        if ($validation !== null) {
            return $validation;
        }

        if (!in_array($deliveryChannel, ['tenant_smtp_with_system_fallback', 'system_smtp'], true)) {
            return booking_mail_v7_result('blocked', 'mail_delivery_channel_invalid');
        }

        if ($deliveryChannel === 'system_smtp') {
            return booking_mail_v7_send_system_smtp(
                $recipientEmail,
                $subject,
                $html,
                $altBody,
                $emailSettings
            );
        }

        if (is_array($emailSettings) && ($emailSettings['tenant_id'] ?? null) !== $tenantId) {
            return booking_mail_v7_result('blocked', 'mail_tenant_binding_invalid');
        }

        if (is_array($emailSettings)) {
            $tenantResult = booking_mail_v7_send_tenant_smtp(
                $tenantId,
                $emailSettings,
                $recipientEmail,
                $subject,
                $html,
                $altBody
            );

            if (($tenantResult['result'] ?? null) === 'sent') {
                return $tenantResult;
            }

            if (($tenantResult['error_code'] ?? null) === 'delivery_result_unknown') {
                // SMTP may already have accepted the message: never send a fallback duplicate.
                return $tenantResult;
            }
        }

        // Safe fallback is allowed only when tenant SMTP has definitely not accepted the message.
        return booking_mail_v7_send_system_smtp(
            $recipientEmail,
            $subject,
            $html,
            $altBody,
            $emailSettings
        );
    }
}
