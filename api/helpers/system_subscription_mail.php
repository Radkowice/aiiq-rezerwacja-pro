<?php
declare(strict_types=1);

require_once __DIR__ . '/php_mail.php';

function system_subscription_mail_escape(?string $value): string
{
    return htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8');
}

function system_subscription_mail_url(?string $domain): string
{
    $domain = strtolower(trim((string) $domain));

    if ($domain === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $domain) === 1) {
        return rtrim($domain, '/');
    }

    return 'https://' . $domain;
}

function system_subscription_mail_format_amount($amount, ?string $currency = 'PLN'): string
{
    if ($amount === null || $amount === '') {
        return '';
    }

    $currency = strtoupper(trim((string) $currency));
    $currencyLabel = $currency === 'PLN' || $currency === '' ? 'zł' : $currency;

    return number_format((float) $amount, 2, ',', ' ') . ' ' . $currencyLabel;
}

function system_subscription_mail_format_date(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function system_subscription_mail_billing_period_label(?string $billingPeriod): string
{
    return match (strtolower(trim((string) $billingPeriod))) {
        'yearly' => 'roczny',
        'monthly' => 'miesięczny',
        default => trim((string) $billingPeriod),
    };
}

function system_subscription_mail_payment_type_label(?string $paymentType): string
{
    return match (strtolower(trim((string) $paymentType))) {
        'subscription_renewal' => 'Przedłużenie Pro',
        'subscription_upgrade' => 'Przejście na Pro',
        default => 'Aktywacja Pro',
    };
}

function system_subscription_mail_panel_url(?array $context): string
{
    return system_subscription_mail_url($context['panel_domain'] ?? '');
}

function system_subscription_mail_admin_login_url(?string $domain): string
{
    $panelUrl = system_subscription_mail_url($domain);

    if ($panelUrl === '') {
        return '';
    }

    if (preg_match('#/logowanie\.html$#i', $panelUrl) === 1) {
        return $panelUrl;
    }

    return $panelUrl . '/logowanie.html';
}

function system_subscription_mail_button(string $url, string $label): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    return '<p style="margin:22px 0 0;text-align:center;">'
        . '<a href="' . system_subscription_mail_escape($url) . '" style="display:inline-block;padding:12px 20px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">'
        . system_subscription_mail_escape($label)
        . '</a>'
        . '</p>';
}

function system_subscription_mail_info_card(string $icon, string $label, ?string $value, string $hint = ''): string
{
    $value = trim((string) $value);
    $hint = trim($hint);

    if ($value === '' && $hint === '') {
        return '';
    }

    return '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:18px;margin:14px 0;">'
        . '<p style="margin:0 0 8px 0;font-size:15px;color:#607284;font-weight:700;">'
        . system_subscription_mail_escape($icon . ' ' . $label)
        . '</p>'
        . ($value !== ''
            ? '<p style="margin:0;font-size:17px;line-height:1.5;color:#17324d;"><strong>' . system_subscription_mail_escape($value) . '</strong></p>'
            : '')
        . ($hint !== ''
            ? '<p style="margin:8px 0 0 0;font-size:14px;line-height:1.55;color:#4f6478;">' . system_subscription_mail_escape($hint) . '</p>'
            : '')
        . '</div>';
}

function system_subscription_mail_details_table(array $rows): string
{
    $html = '';

    foreach ($rows as $row) {
        $label = trim((string) ($row[0] ?? ''));
        $value = trim((string) ($row[1] ?? ''));

        if ($label === '' || $value === '') {
            continue;
        }

        $html .= '<tr>'
            . '<td style="padding:8px 0;color:#607284;">' . system_subscription_mail_escape($label) . ':</td>'
            . '<td style="padding:8px 0;text-align:right;"><strong>' . system_subscription_mail_escape($value) . '</strong></td>'
            . '</tr>';
    }

    if ($html === '') {
        return '';
    }

    return '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:20px 0;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:15px;">'
        . $html
        . '</table>'
        . '</div>';
}

function system_subscription_mail_layout(string $headline, string $intro, string $icon, string $bodyHtml, string $footerNote = ''): string
{
    $footerNote = trim($footerNote);
    $footerHtml = $footerNote !== ''
        ? '<p style="margin:10px 0 0 0;font-size:12px;line-height:1.6;color:#607284;">' . system_subscription_mail_escape($footerNote) . '</p>'
        : '';

    return '<!doctype html>'
        . '<html lang="pl">'
        . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . system_subscription_mail_escape($headline) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f7fb;">'
        . '<div style="margin:0;padding:0;background:#f4f7fb;">'
        . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
        . '<div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">'
        . '<div style="font-size:42px;line-height:1;margin-bottom:12px;">' . system_subscription_mail_escape($icon) . '</div>'
        . '<h1 style="margin:0;font-size:28px;line-height:1.25;">' . system_subscription_mail_escape($headline) . '</h1>'
        . '<p style="margin:12px 0 0 0;font-size:16px;line-height:1.55;opacity:0.95;">' . system_subscription_mail_escape($intro) . '</p>'
        . '</div>'
        . '<div style="padding:32px 24px;">'
        . $bodyHtml
        . '<p style="margin:24px 0 0 0;font-size:14px;line-height:1.6;color:#4f6478;">To wiadomość systemowa AI-IQ Rezerwacja Pro. Prosimy nie odpowiadać na tę wiadomość.</p>'
        . $footerHtml
        . '</div>'
        . '<div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">'
        . 'Obsługiwane przez <a href="https://ai-iq.pl" target="_blank" style="color:#607284;text-decoration:none;font-weight:600;">AI-IQ</a> | Inteligentne automatyzacje'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}

function buildRegistrationConfirmationMailHtml(array $data): string
{
    $companyName = trim((string) ($data['company_name'] ?? ''));
    $plan = strtolower(trim((string) ($data['plan'] ?? 'free')));
    $panelUrl = system_subscription_mail_admin_login_url($data['panel_domain'] ?? '');
    $activationUrl = trim((string) ($data['activation_url'] ?? ''));
    $activationExpiresLabel = trim((string) ($data['activation_expires_label'] ?? ''));
    $isPro = $plan === 'pro';

    $planLabel = $isPro ? 'Wybrany plan: Pro' : 'Plan: Free';
    $statusLabel = $isPro
        ? 'Oczekuje na opłacenie i aktywację po potwierdzeniu PayU'
        : 'Konto Free zostało utworzone';

    $body = '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">Konto w systemie AI-IQ Rezerwacja Pro zostało utworzone.</p>'
        . system_subscription_mail_info_card('🧾', 'Plan', $planLabel, $isPro ? 'Plan Pro nie jest jeszcze aktywny. Funkcje Pro zostaną włączone dopiero po poprawnym potwierdzeniu płatności PayU.' : '')
        . system_subscription_mail_info_card('✅', 'Status', $statusLabel)
        . system_subscription_mail_info_card('🏢', 'Firma', $companyName)
        . system_subscription_mail_info_card('🔗', 'Panel administratora', $panelUrl)
        . ($activationUrl !== ''
            ? system_subscription_mail_info_card('✅', 'Aktywacja konta', 'Wymagana aktywacja konta administratora', $activationExpiresLabel !== '' ? 'Link aktywacyjny jest ważny ' . $activationExpiresLabel . '.' : '')
                . system_subscription_mail_button($activationUrl, 'Aktywuj konto')
            : system_subscription_mail_info_card('✅', 'Aktywacja konta', 'Konto jest gotowe do logowania'));

    return system_subscription_mail_layout(
        'Konto zostało utworzone',
        'Potwierdzenie rejestracji w AI-IQ Rezerwacja Pro.',
        '✅',
        $body,
        'Jeśli nie zakładałeś tego konta, zignoruj tę wiadomość.'
    );
}

function buildAccountActivatedMailHtml(array $context): string
{
    $panelUrl = system_subscription_mail_admin_login_url($context['panel_domain'] ?? '');
    $companyName = trim((string) ($context['company_name'] ?? ''));
    $plan = trim((string) ($context['plan'] ?? 'Free'));

    $body = '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">Konto administratora zostało aktywowane. Możesz zalogować się do panelu i rozpocząć korzystanie z AI-IQ Rezerwacja Pro.</p>'
        . system_subscription_mail_info_card('✅', 'Status', 'Konto aktywne')
        . system_subscription_mail_info_card('🧾', 'Plan', $plan)
        . system_subscription_mail_info_card('🏢', 'Firma', $companyName)
        . system_subscription_mail_info_card('🔗', 'Panel administratora', $panelUrl)
        . system_subscription_mail_button($panelUrl, 'Przejdź do panelu');

    return system_subscription_mail_layout(
        'Konto administratora aktywne',
        'Potwierdzenie aktywacji konta w AI-IQ Rezerwacja Pro.',
        '✅',
        $body,
        'Jeśli nie aktywowałeś tego konta, skontaktuj się z obsługą AI-IQ.'
    );
}

function buildSubscriptionProActivatedMailHtml(array $payment, array $subscription, array $context): string
{
    $panelUrl = system_subscription_mail_admin_login_url($context['panel_domain'] ?? '');
    $companyName = trim((string) ($context['company_name'] ?? ''));
    $periodStart = (string) ($subscription['current_period_start'] ?? $payment['subscription_period_start'] ?? '');
    $periodEnd = (string) ($subscription['current_period_end'] ?? $payment['subscription_period_end'] ?? '');
    $periodText = trim(system_subscription_mail_format_date($periodStart) . ' - ' . system_subscription_mail_format_date($periodEnd));
    $amountText = system_subscription_mail_format_amount($payment['amount'] ?? null, $payment['currency'] ?? 'PLN');
    $billingPeriod = system_subscription_mail_billing_period_label($payment['billing_period'] ?? $subscription['billing_period'] ?? '');
    $paymentType = system_subscription_mail_payment_type_label($payment['payment_type'] ?? '');

    $body = '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">Funkcje Pro są już aktywne w Twoim panelu.</p>'
        . system_subscription_mail_info_card('✅', 'Status', 'Opłacono')
        . system_subscription_mail_info_card('🧾', 'Plan', 'Pro', $paymentType)
        . system_subscription_mail_info_card('💳', 'Płatność', $amountText, $billingPeriod !== '' ? 'Płatność rozliczeniowa: ' . $billingPeriod : '')
        . system_subscription_mail_info_card('📅', 'Abonament ważny do', $periodText, system_subscription_mail_format_date($periodEnd) !== '' ? 'Aktywny do: ' . system_subscription_mail_format_date($periodEnd) : '')
        . system_subscription_mail_info_card('🏢', 'Firma', $companyName)
        . system_subscription_mail_info_card('🔗', 'Panel administratora', $panelUrl)
        . system_subscription_mail_button($panelUrl, 'Przejdź do panelu');

    return system_subscription_mail_layout(
        'Plan Pro aktywny',
        'Potwierdzenie płatności abonamentowej AI-IQ Rezerwacja Pro.',
        '💳',
        $body,
        'Ten e-mail dotyczy wyłącznie abonamentu systemowego AI-IQ.'
    );
}

function buildSubscriptionReminderMailHtml(array $subscription, array $context, int $daysLeft): string
{
    $panelUrl = system_subscription_mail_panel_url($context);
    $companyName = trim((string) ($context['company_name'] ?? ''));
    $planName = trim((string) ($subscription['plan_name'] ?? $subscription['plan_code'] ?? 'Pro'));
    $periodEnd = (string) ($subscription['current_period_end'] ?? '');
    $periodEndLabel = system_subscription_mail_format_date($periodEnd);
    $billingPeriod = system_subscription_mail_billing_period_label($subscription['billing_period'] ?? '');

    $title = $daysLeft === 0
        ? 'Abonament kończy się dzisiaj'
        : 'Abonament kończy się za ' . $daysLeft . ' ' . ($daysLeft === 1 ? 'dzień' : 'dni');

    $body = '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">To przypomnienie o kończącym się abonamencie w AI-IQ Rezerwacja Pro.</p>'
        . system_subscription_mail_info_card('⏰', 'Przypomnienie', $title)
        . system_subscription_mail_info_card('🧾', 'Plan', $planName, $billingPeriod !== '' ? 'Płatność rozliczeniowa: ' . $billingPeriod : '')
        . system_subscription_mail_info_card('📅', 'Termin końca abonamentu', $periodEndLabel)
        . system_subscription_mail_info_card('✅', 'Status', 'Aktywny')
        . system_subscription_mail_info_card('🏢', 'Firma', $companyName)
        . system_subscription_mail_info_card('🔗', 'Panel', $panelUrl)
        . system_subscription_mail_button($panelUrl, 'Przejdź do panelu');

    return system_subscription_mail_layout(
        $title,
        'Przypomnienie abonamentowe AI-IQ Rezerwacja Pro.',
        '⏰',
        $body,
        'Na tym etapie system nie przełącza automatycznie planu na Free.'
    );
}
