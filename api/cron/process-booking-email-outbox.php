<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/payment_lifecycle_v3.php';
require_once __DIR__ . '/../helpers/booking_mail.php';
require_once __DIR__ . '/../helpers/plan_features.php';

const BOOKING_EMAIL_WORKER_LIMIT = 5;
const BOOKING_EMAIL_WORKER_LEASE_SECONDS = 300;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function booking_email_worker_log(string $event): void
{
    $allowed = [
        'claim_failed',
        'claim_blocked',
        'malformed_claim',
        'context_read_failed',
        'context_invalid',
        'render_failed',
        'adapter_result_invalid',
        'record_result_failed',
        'worker_run_success',
        'worker_run_failed',
    ];

    if (!in_array($event, $allowed, true)) {
        $event = 'worker_run_failed';
    }

    error_log('BOOKING_EMAIL_WORKER ' . $event);
}

function booking_email_worker_id(): string
{
    $host = function_exists('gethostname') ? trim((string) gethostname()) : '';
    $hostHash = substr(hash('sha256', $host !== '' ? $host : 'unknown-host'), 0, 16);
    $pid = function_exists('getmypid') ? max(0, (int) getmypid()) : 0;

    return 'booking-email-v1:h-' . $hostHash . ':p-' . $pid;
}

function booking_email_worker_uuid(string $value): bool
{
    return preg_match(
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
        $value
    ) === 1;
}

function booking_email_worker_safe_text(string $value, int $maxBytes = 128): bool
{
    return $value !== ''
        && $value === trim($value)
        && strlen($value) <= $maxBytes
        && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
}

function booking_email_worker_rows(
    array $config,
    string $table,
    string $query,
    int $limit = 2
): array {
    $allowedTables = [
        'bookings',
        'email_settings',
        'tenant_branding',
        'tenant_service_settings',
        'email_templates',
        'staff_profiles',
        'tenant_domains',
    ];

    if (
        !in_array($table, $allowedTables, true)
        || $limit < 1
        || $limit > 5
        || !function_exists('curl_init')
    ) {
        return [
            'ok' => false,
            'retryable' => false,
            'rows' => [],
            'error_code' => 'email_context_configuration_invalid',
        ];
    }

    $url = $config['url']
        . '/rest/v1/' . rawurlencode($table)
        . '?' . $query
        . '&limit=' . $limit;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'retryable' => true,
            'rows' => [],
            'error_code' => 'email_context_read_failed',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $config['service_role_key'],
            'Authorization: Bearer ' . $config['service_role_key'],
            'Accept: application/json',
            'Accept-Profile: ' . $config['schema'],
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return [
            'ok' => false,
            'retryable' => true,
            'rows' => [],
            'error_code' => 'email_context_read_failed',
        ];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return [
            'ok' => false,
            'retryable' => $httpStatus === 0
                || $httpStatus === 408
                || $httpStatus === 429
                || $httpStatus >= 500,
            'rows' => [],
            'error_code' => 'email_context_read_failed',
        ];
    }

    $decoded = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [
            'ok' => false,
            'retryable' => false,
            'rows' => [],
            'error_code' => 'email_context_response_invalid',
        ];
    }

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            return [
                'ok' => false,
                'retryable' => false,
                'rows' => [],
                'error_code' => 'email_context_response_invalid',
            ];
        }
    }

    return [
        'ok' => true,
        'retryable' => false,
        'rows' => $decoded,
        'error_code' => '',
    ];
}

function booking_email_worker_single(
    array $config,
    string $table,
    string $query,
    bool $required = false
): array {
    $result = booking_email_worker_rows($config, $table, $query, 2);

    if (!$result['ok']) {
        return $result + ['row' => null];
    }

    $rows = $result['rows'];

    if (count($rows) > 1) {
        return [
            'ok' => false,
            'retryable' => false,
            'row' => null,
            'error_code' => 'email_context_collision',
        ];
    }

    if ($required && count($rows) !== 1) {
        return [
            'ok' => false,
            'retryable' => false,
            'row' => null,
            'error_code' => 'email_context_missing',
        ];
    }

    return [
        'ok' => true,
        'retryable' => false,
        'row' => $rows[0] ?? null,
        'error_code' => '',
    ];
}

function booking_email_worker_payload_valid($payload): bool
{
    if (!is_array($payload)) {
        return false;
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded) || strlen($encoded) > 16384) {
        return false;
    }

    foreach (['customer_name', 'service_name', 'booking_date', 'booking_time', 'amount', 'currency'] as $key) {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            continue;
        }

        if (!is_scalar($payload[$key])) {
            return false;
        }

        if (strlen((string) $payload[$key]) > 1024 || str_contains((string) $payload[$key], "\0")) {
            return false;
        }
    }

    return true;
}

function booking_email_worker_fetch_context(
    array $config,
    string $tenantId,
    string $bookingId,
    string $eventType
): array {
    $tenantQuery = 'tenant_id=eq.' . rawurlencode($tenantId);

    $bookingResult = booking_email_worker_single(
        $config,
        'bookings',
        'select=id,tenant_id,name,email,phone,booking_date,booking_time,service_name_snapshot,staff_id,'
            . 'payment_amount,payment_currency,payment_status,status,payment_url,payment_expires_at,'
            . 'manage_token,manage_token_expires_at'
            . '&' . $tenantQuery
            . '&id=eq.' . rawurlencode($bookingId),
        true
    );

    if (!$bookingResult['ok']) {
        return $bookingResult;
    }

    $booking = $bookingResult['row'];

    if (
        !is_array($booking)
        || (string) ($booking['tenant_id'] ?? '') !== $tenantId
        || (string) ($booking['id'] ?? '') !== $bookingId
    ) {
        return [
            'ok' => false,
            'retryable' => false,
            'error_code' => 'email_context_binding_invalid',
        ];
    }

    $settingsResult = booking_email_worker_single(
        $config,
        'email_settings',
        'select=tenant_id,smtp_host,smtp_port,smtp_encryption,smtp_auth,smtp_username,smtp_password,'
            . 'from_email,from_name,reply_to_email,reply_to_name,admin_notify_email,'
            . 'send_client_confirmation,send_admin_notification,is_active'
            . '&' . $tenantQuery
            . '&is_active=eq.true'
    );

    if (!$settingsResult['ok']) {
        return $settingsResult;
    }

    $emailSettings = $settingsResult['row'];

    if (is_array($emailSettings) && (string) ($emailSettings['tenant_id'] ?? '') !== $tenantId) {
        return [
            'ok' => false,
            'retryable' => false,
            'error_code' => 'email_context_binding_invalid',
        ];
    }

    $brandingResult = booking_email_worker_single(
        $config,
        'tenant_branding',
        'select=tenant_id,client_name,email_footer_mode,email_footer_custom'
            . '&' . $tenantQuery
    );

    if (!$brandingResult['ok']) {
        return $brandingResult;
    }

    $serviceResult = booking_email_worker_single(
        $config,
        'tenant_service_settings',
        'select=tenant_id,company_full_name,company_email'
            . '&' . $tenantQuery
    );

    if (!$serviceResult['ok']) {
        return $serviceResult;
    }

    $branding = $brandingResult['row'];
    $serviceSettings = $serviceResult['row'];

    foreach ([$branding, $serviceSettings] as $row) {
        if (is_array($row) && (string) ($row['tenant_id'] ?? '') !== $tenantId) {
            return [
                'ok' => false,
                'retryable' => false,
                'error_code' => 'email_context_binding_invalid',
            ];
        }
    }

    $emailTemplate = null;
    $staff = null;
    $tenantDomain = '';

    if ($eventType === 'payment_paid_customer') {
        $templateResult = booking_email_worker_single(
            $config,
            'email_templates',
            'select=tenant_id,template_key,is_enabled,subject,service_name,body_html'
                . '&' . $tenantQuery
                . '&template_key=eq.booking_client_confirmation'
                . '&is_enabled=eq.true'
        );

        if (!$templateResult['ok']) {
            return $templateResult;
        }

        $emailTemplate = $templateResult['row'];

        if (is_array($emailTemplate) && (string) ($emailTemplate['tenant_id'] ?? '') !== $tenantId) {
            return [
                'ok' => false,
                'retryable' => false,
                'error_code' => 'email_context_binding_invalid',
            ];
        }

        $staffId = trim((string) ($booking['staff_id'] ?? ''));

        if ($staffId !== '') {
            if (!booking_email_worker_safe_text($staffId, 128)) {
                return [
                    'ok' => false,
                    'retryable' => false,
                    'error_code' => 'email_context_invalid',
                ];
            }

            $staffResult = booking_email_worker_single(
                $config,
                'staff_profiles',
                'select=id,tenant_id,display_name,email_subject,email_heading,email_body'
                    . '&' . $tenantQuery
                    . '&id=eq.' . rawurlencode($staffId)
            );

            if (!$staffResult['ok']) {
                return $staffResult;
            }

            $staff = $staffResult['row'];

            if (is_array($staff) && (
                (string) ($staff['tenant_id'] ?? '') !== $tenantId
                || (string) ($staff['id'] ?? '') !== $staffId
            )) {
                return [
                    'ok' => false,
                    'retryable' => false,
                    'error_code' => 'email_context_binding_invalid',
                ];
            }
        }

        $token = trim((string) ($booking['manage_token'] ?? ''));
        $expiresAt = trim((string) ($booking['manage_token_expires_at'] ?? ''));
        $mayReschedule = false;

        if ($token !== '' && $expiresAt !== '') {
            try {
                $expires = new DateTimeImmutable($expiresAt);
                $mayReschedule = $expires > new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'))
                    && tenant_has_feature($tenantId, 'reschedule_booking');
            } catch (Throwable $e) {
                $mayReschedule = false;
            }
        }

        if ($mayReschedule) {
            $domainResult = booking_email_worker_rows(
                $config,
                'tenant_domains',
                'select=tenant_id,domain,is_active,is_primary'
                    . '&' . $tenantQuery
                    . '&is_active=eq.true'
                    . '&order=is_primary.desc',
                1
            );

            if (!$domainResult['ok']) {
                return $domainResult;
            }

            $domainRow = $domainResult['rows'][0] ?? null;

            if (is_array($domainRow)) {
                if ((string) ($domainRow['tenant_id'] ?? '') !== $tenantId) {
                    return [
                        'ok' => false,
                        'retryable' => false,
                        'error_code' => 'email_context_binding_invalid',
                    ];
                }

                $candidateDomain = strtolower(trim((string) ($domainRow['domain'] ?? '')));

                if ($candidateDomain !== '' && preg_match('/\A[a-z0-9.-]+\z/D', $candidateDomain) === 1) {
                    $tenantDomain = $candidateDomain;
                }
            }
        }
    }

    return [
        'ok' => true,
        'retryable' => false,
        'error_code' => '',
        'booking' => $booking,
        'email_settings' => $emailSettings,
        'branding' => is_array($branding) ? $branding : [],
        'service_settings' => is_array($serviceSettings) ? $serviceSettings : [],
        'email_template' => is_array($emailTemplate) ? $emailTemplate : null,
        'staff' => is_array($staff) ? $staff : null,
        'tenant_domain' => $tenantDomain,
    ];
}

function booking_email_worker_format_datetime(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Europe/Warsaw'))
            ->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return '—';
    }
}

function booking_email_worker_summary_html(array $booking, bool $includeContact): string
{
    $fields = [];

    if ($includeContact) {
        $fields['Klient'] = trim((string) ($booking['name'] ?? ''));
        $fields['E-mail'] = trim((string) ($booking['email'] ?? ''));
        $fields['Telefon'] = trim((string) ($booking['phone'] ?? ''));
    }

    $fields['Usługa'] = trim((string) ($booking['service_name_snapshot'] ?? ''));
    $fields['Data'] = trim((string) ($booking['booking_date'] ?? ''));
    $fields['Godzina'] = substr(trim((string) ($booking['booking_time'] ?? '')), 0, 5);

    $amount = booking_mail_format_amount(
        $booking['payment_amount'] ?? null,
        (string) ($booking['payment_currency'] ?? 'PLN')
    );

    if ($amount !== '') {
        $fields['Kwota'] = $amount;
    }

    $rows = '';

    foreach ($fields as $label => $value) {
        if ($value === '') {
            continue;
        }

        $rows .= '<tr><td style="padding:7px 0;color:#6b7280;">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ':</td><td style="padding:7px 0;text-align:right;"><strong>'
            . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</strong></td></tr>';
    }

    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">'
        . $rows
        . '</table>';
}

function booking_email_worker_render_paid_customer(
    string $tenantId,
    string $recipientEmail,
    array $context
): array {
    $booking = $context['booking'];

    $template = is_array($context['email_template'])
        ? $context['email_template']
        : booking_mail_default_client_template();
    $staff = is_array($context['staff']) ? $context['staff'] : null;

    if ($staff !== null) {
        foreach ([
            'email_subject' => 'subject',
            'email_heading' => 'service_name',
            'email_body' => 'body_html',
        ] as $staffKey => $templateKey) {
            $value = trim((string) ($staff[$staffKey] ?? ''));

            if ($value !== '') {
                $template[$templateKey] = $value;
            }
        }
    }

    $branding = $context['branding'];
    $serviceSettings = $context['service_settings'];
    $companyName = trim((string) ($branding['client_name'] ?? ''));

    if ($companyName === '') {
        $companyName = trim((string) ($serviceSettings['company_full_name'] ?? ''));
    }

    $name = trim((string) ($booking['name'] ?? ''));
    $date = trim((string) ($booking['booking_date'] ?? ''));
    $time = substr(trim((string) ($booking['booking_time'] ?? '')), 0, 5);
    $phone = trim((string) ($booking['phone'] ?? ''));
    $serviceName = trim((string) ($booking['service_name_snapshot'] ?? ''));
    $staffDisplayName = is_array($staff) ? trim((string) ($staff['display_name'] ?? '')) : '';

    $placeholders = [
        '{name}' => $name,
        '{date}' => $date,
        '{time}' => $time,
        '{email}' => $recipientEmail,
        '{phone}' => $phone,
        '{message}' => '',
    ];

    $subject = trim(booking_mail_replace_placeholders(
        (string) ($template['subject'] ?? ''),
        $placeholders
    ));

    if ($subject === '') {
        $subject = trim('Potwierdzenie rezerwacji - ' . $date . ' ' . $time);
    }

    $introHtml = booking_mail_replace_html_placeholders(
        (string) ($template['body_html'] ?? ''),
        $placeholders
    );

    if (trim(strip_tags($introHtml)) === '') {
        $introHtml = booking_mail_default_client_template()['body_html'];
    }

    $emailHeading = trim((string) ($template['service_name'] ?? ''));
    if ($emailHeading === '') {
        $emailHeading = 'Dziękujemy za rezerwację';
    }

    $planCode = 'free';
    try {
        $planContext = plan_features_get_context($tenantId);
        $candidatePlan = strtolower(trim((string) ($planContext['plan_code'] ?? 'free')));
        if (in_array($candidatePlan, ['free', 'pro', 'vip', 'business'], true)) {
            $planCode = $candidatePlan;
        }
    } catch (Throwable $e) {
        $planCode = 'free';
    }

    $footerPlan = match ($planCode) {
        'pro' => 'pro',
        'vip', 'business' => 'premium',
        default => 'basic',
    };

    $footerHtml = booking_mail_build_footer(
        $footerPlan,
        (string) ($branding['email_footer_mode'] ?? 'system'),
        (string) ($branding['email_footer_custom'] ?? '')
    );

    $rescheduleUrl = '';
    $tenantDomain = trim((string) ($context['tenant_domain'] ?? ''));
    $manageToken = trim((string) ($booking['manage_token'] ?? ''));

    if ($tenantDomain !== '' && $manageToken !== '') {
        $rescheduleUrl = 'https://' . $tenantDomain
            . '/przeloz-rezerwacje.html?token=' . rawurlencode($manageToken);
    }

    $amountText = booking_mail_format_amount(
        $booking['payment_amount'] ?? null,
        (string) ($booking['payment_currency'] ?? 'PLN')
    );

    $html = booking_mail_build_client_html(
        $introHtml,
        $companyName,
        $emailHeading,
        $footerHtml,
        $name,
        $recipientEmail,
        $date,
        $time,
        [
            'status_label' => 'Opłacono',
            'amount_text' => $amountText,
            'reschedule_url' => $rescheduleUrl,
        ],
        $serviceName,
        $staffDisplayName
    );

    $alt = "Rezerwacja potwierdzona\n\n"
        . ($emailHeading !== '' ? $emailHeading . ($companyName !== '' ? ' | ' . $companyName : '') . "\n\n" : '')
        . ($name !== '' ? "Imię: {$name}\n" : '')
        . "E-mail: {$recipientEmail}\n"
        . ($date !== '' ? "Data: {$date}\n" : '')
        . ($time !== '' ? "Godzina: {$time}\n" : '')
        . ($serviceName !== '' ? "Usługa: {$serviceName}\n" : '')
        . "Status płatności: Opłacono\n"
        . ($amountText !== '' ? "Kwota: {$amountText}\n" : '')
        . ($staffDisplayName !== '' ? "Osoba obsługująca: {$staffDisplayName}\n" : '')
        . ($rescheduleUrl !== '' ? "\nPrzełóż rezerwację: {$rescheduleUrl}\n" : '');

    return [
        'ok' => true,
        'retryable' => false,
        'error_code' => '',
        'subject' => $subject,
        'html' => $html,
        'alt' => $alt,
    ];
}

function booking_email_worker_valid_payu_url(string $url): bool
{
    $url = trim($url);

    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
        return false;
    }

    $parts = parse_url($url);

    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    $port = $parts['port'] ?? null;

    return $scheme === 'https'
        && in_array($host, ['secure.payu.com', 'secure.snd.payu.com'], true)
        && ($port === null || (int) $port === 443)
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function booking_email_worker_render_generic(
    string $eventType,
    string $recipientEmail,
    array $context
): array {
    $booking = $context['booking'];
    $branding = $context['branding'];
    $serviceSettings = $context['service_settings'];
    $companyName = trim((string) ($branding['client_name'] ?? ''));

    if ($companyName === '') {
        $companyName = trim((string) ($serviceSettings['company_full_name'] ?? ''));
    }

    $summaryCustomer = booking_email_worker_summary_html($booking, false);
    $summaryAdmin = booking_email_worker_summary_html($booking, true);
    $paymentUrl = trim((string) ($booking['payment_url'] ?? ''));
    $safePaymentUrl = htmlspecialchars($paymentUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $expires = booking_email_worker_format_datetime((string) ($booking['payment_expires_at'] ?? ''));

    $title = '';
    $preheader = '';
    $message = '';
    $footer = '';
    $subject = '';

    switch ($eventType) {
        case 'payment_pending_customer':
            if (!booking_email_worker_valid_payu_url($paymentUrl)) {
                return ['ok' => false, 'retryable' => true, 'error_code' => 'payment_url_missing'];
            }

            $subject = 'Twoja rezerwacja — link do płatności';
            $title = $subject;
            $preheader = 'Jeżeli płatność została przerwana, możesz wrócić do niej z tego maila.';
            $message = '<p style="margin:0 0 14px;"><strong>Twoja rezerwacja została rozpoczęta.</strong></p>'
                . $summaryCustomer
                . '<p style="margin:16px 0 0;color:#374151;line-height:1.6;">Termin płatności: <strong>'
                . htmlspecialchars($expires, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong>.</p>'
                . '<p style="margin:12px 0 0;color:#374151;line-height:1.6;">Jeżeli płatność została już wykonana, nie musisz nic robić — status zostanie zaktualizowany po potwierdzeniu przez PayU.</p>'
                . '<div style="margin-top:22px;text-align:center;"><a href="' . $safePaymentUrl . '" style="display:inline-block;padding:13px 22px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">Opłać rezerwację</a></div>';
            $footer = 'Email zawiera link do płatności za rezerwację.';
            break;

        case 'payment_reminder_customer':
            if (!booking_email_worker_valid_payu_url($paymentUrl)) {
                return ['ok' => false, 'retryable' => true, 'error_code' => 'payment_url_missing'];
            }

            $subject = 'Przypomnienie o płatności za rezerwację';
            $title = 'Przypomnienie o płatności';
            $preheader = 'Rezerwacja nadal oczekuje na potwierdzenie płatności.';
            $message = '<p style="margin:0 0 14px;"><strong>Przypomnienie o płatności za rezerwację.</strong></p>'
                . '<p style="margin:0 0 14px;">Nie odnotowaliśmy jeszcze potwierdzenia płatności z PayU.</p>'
                . $summaryCustomer
                . '<div style="margin-top:22px;text-align:center;"><a href="' . $safePaymentUrl . '" style="display:inline-block;padding:13px 22px;border-radius:999px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;">Przejdź do płatności</a></div>';
            $footer = 'Jeżeli płatność została wykonana chwilę temu, PayU może potrzebować czasu na przesłanie potwierdzenia.';
            break;

        case 'payment_expired_customer':
            $subject = 'Płatność za rezerwację nie została odnotowana';
            $title = 'Płatność nie została odnotowana';
            $preheader = 'Rezerwacja wymaga decyzji administratora.';
            $message = '<p style="margin:0 0 14px;"><strong>Płatność za rezerwację nie została odnotowana w wyznaczonym czasie.</strong></p>'
                . '<p style="margin:0 0 14px;">Rezerwacja została oznaczona w systemie jako nieopłacona.</p>'
                . $summaryCustomer
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Administrator otrzymał informację o braku płatności i podejmie decyzję, co dalej z rezerwacją.</p>';
            $footer = 'Wiadomość została wysłana automatycznie po przekroczeniu czasu płatności.';
            break;

        case 'payment_expired_admin':
            $subject = 'Rezerwacja nieopłacona — wymagana decyzja';
            $title = 'Rezerwacja nieopłacona';
            $preheader = 'System oznaczył rezerwację jako nieopłaconą.';
            $message = '<p style="margin:0 0 14px;"><strong>Rezerwacja nie została opłacona w wyznaczonym czasie.</strong></p>'
                . $summaryAdmin
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Sprawdź rezerwację w panelu i podejmij decyzję zgodnie z aktualnym stanem płatności.</p>';
            $footer = 'System nie usuwa tej rezerwacji automatycznie.';
            break;

        case 'booking_created_admin':
            $settings = $context['email_settings'];
            if (is_array($settings) && array_key_exists('send_admin_notification', $settings)) {
                if ($settings['send_admin_notification'] !== true) {
                    return [
                        'ok' => false,
                        'retryable' => false,
                        'error_code' => 'admin_notification_disabled',
                    ];
                }
            }

            $paymentStatus = strtolower(trim((string) ($booking['payment_status'] ?? '')));
            $paymentLabel = match ($paymentStatus) {
                'paid' => 'Opłacono',
                'expired' => 'Płatność wygasła',
                'failed' => 'Płatność nieudana',
                'pending' => 'Oczekuje na płatność',
                default => 'Status wymaga sprawdzenia',
            };
            $subject = match ($paymentStatus) {
                'paid' => 'Nowa rezerwacja — opłacona',
                'expired' => 'Nowa rezerwacja — płatność wygasła',
                'failed' => 'Nowa rezerwacja — płatność nieudana',
                'pending' => 'Nowa rezerwacja — oczekuje na płatność',
                default => 'Nowa rezerwacja',
            };
            $title = 'Nowa rezerwacja';
            $preheader = 'System zarejestrował nową rezerwację.';
            $message = '<p style="margin:0 0 14px;"><strong>System zarejestrował nową rezerwację.</strong></p>'
                . $summaryAdmin
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Aktualny status płatności: <strong>'
                . htmlspecialchars($paymentLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong>.</p>';
            $footer = 'Stan płatności należy interpretować na podstawie danych zapisanych po stronie backendu i PayU.';
            break;

        case 'appointment_reminder_day_before':
        case 'appointment_reminder_same_day':
            $type = $eventType === 'appointment_reminder_day_before' ? 'day_before' : 'same_day';
            $tenantData = array_merge($serviceSettings, $branding);
            $subject = booking_mail_booking_reminder_subject($tenantData, $booking, $type);
            $title = $subject;
            $preheader = $type === 'day_before'
                ? 'Przypomnienie o jutrzejszej rezerwacji.'
                : 'Przypomnienie o dzisiejszej rezerwacji.';
            $message = '<p style="margin:0 0 14px;"><strong>Przypomnienie o rezerwacji.</strong></p>'
                . $summaryCustomer
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Do zobaczenia w umówionym terminie.</p>';
            $footer = $companyName !== ''
                ? 'Wiadomość dotycząca rezerwacji w ' . htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.'
                : 'Wiadomość dotycząca Twojej rezerwacji.';
            break;

        case 'late_paid_review_admin':
            $subject = 'Płatność po zwolnieniu terminu — wymagana weryfikacja';
            $title = 'Wymagana ręczna weryfikacja płatności';
            $preheader = 'PayU potwierdziło płatność dla rezerwacji wymagającej decyzji administratora.';
            $message = '<p style="margin:0 0 14px;"><strong>Wykryto płatność wymagającą ręcznej decyzji administratora.</strong></p>'
                . $summaryAdmin
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Sprawdź bieżący stan rezerwacji i otwartą sprawę rozliczeniową w panelu przed podjęciem działania.</p>';
            $footer = 'Nie wykonuj ręcznej zmiany statusu bez weryfikacji stanu płatności.';
            break;

        case 'multiple_paid_review_admin':
            $subject = 'Wiele opłaconych prób — wymagana weryfikacja';
            $title = 'Wymagana ręczna weryfikacja płatności';
            $preheader = 'System wykrył więcej niż jedną opłaconą próbę dla rezerwacji.';
            $message = '<p style="margin:0 0 14px;"><strong>System wykrył wiele opłaconych prób płatności dla tej rezerwacji.</strong></p>'
                . $summaryAdmin
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Sprawdź sprawę rozliczeniową w panelu przed zwrotem lub inną operacją finansową.</p>';
            $footer = 'Operacje finansowe wymagają weryfikacji po stronie operatora płatności.';
            break;

        case 'reconciliation_review_admin':
            $subject = 'Reconciliation PayU — wymagana ręczna weryfikacja';
            $title = 'Nie udało się automatycznie uzgodnić płatności';
            $preheader = 'Automatyczna reconciliation osiągnęła limit prób.';
            $message = '<p style="margin:0 0 14px;"><strong>Automatyczne uzgadnianie płatności nie dało jednoznacznego wyniku.</strong></p>'
                . $summaryAdmin
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Sprawdź stan płatności w PayU i otwartą sprawę rozliczeniową w panelu.</p>';
            $footer = 'Nie zakładaj wyniku płatności na podstawie samego przekierowania klienta.';
            break;

        case 'calendar_review_admin':
            $subject = 'Google Calendar — wymagana ręczna weryfikacja';
            $title = 'Niepewny wynik operacji Google Calendar';
            $preheader = 'System nie może bezpiecznie powtórzyć operacji bez ręcznej weryfikacji.';
            $message = '<p style="margin:0 0 14px;"><strong>Wynik operacji Google Calendar jest niejednoznaczny lub wymaga kompensacji.</strong></p>'
                . $summaryAdmin
                . '<p style="margin:18px 0 0;color:#374151;line-height:1.6;">Sprawdź kalendarz i otwartą sprawę w panelu przed wykonaniem kolejnej operacji.</p>';
            $footer = 'System celowo nie powtarza automatycznie niejednoznacznego utworzenia wydarzenia.';
            break;

        default:
            return [
                'ok' => false,
                'retryable' => false,
                'error_code' => 'unsupported_email_type',
            ];
    }

    $html = buildSystemMailLayout($title, $preheader, $message, $footer);
    $alt = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return [
        'ok' => true,
        'retryable' => false,
        'error_code' => '',
        'subject' => $subject,
        'html' => $html,
        'alt' => $alt,
    ];
}

function booking_email_worker_render(
    string $tenantId,
    string $eventType,
    string $recipientEmail,
    array $context
): array {
    if ($eventType === 'payment_paid_customer') {
        return booking_email_worker_render_paid_customer(
            $tenantId,
            $recipientEmail,
            $context
        );
    }

    return booking_email_worker_render_generic(
        $eventType,
        $recipientEmail,
        $context
    );
}

function booking_email_worker_record_result(
    string $outboxId,
    string $claimToken,
    string $result,
    string $errorCode
): bool {
    $payload = [
        'p_email_outbox_id' => $outboxId,
        'p_claim_token' => $claimToken,
        'p_result' => $result,
        'p_error_code' => $errorCode,
    ];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $rpc = payment_lifecycle_v3_rpc('booking_email_record_result', $payload);

        if (!empty($rpc['ok']) && is_array($rpc['data'] ?? null)) {
            $data = $rpc['data'];
            $recorded = ($data['recorded'] ?? false) === true;
            $idempotent = ($data['idempotent'] ?? false) === true;
            $status = strtolower(trim((string) ($data['status'] ?? '')));

            if ($recorded || $idempotent) {
                if ($result === 'sent' && $status === 'sent') {
                    return true;
                }

                if ($result === 'blocked' && $status === 'blocked') {
                    return true;
                }

                if ($result === 'failed' && in_array($status, ['pending', 'failed'], true)) {
                    return true;
                }
            }
        }

        if ($attempt === 0) {
            usleep(250000);
        }
    }

    return false;
}

function booking_email_worker_record_pre_send_failure(
    string $outboxId,
    string $claimToken,
    bool $retryable,
    string $errorCode
): bool {
    return booking_email_worker_record_result(
        $outboxId,
        $claimToken,
        $retryable ? 'failed' : 'blocked',
        $errorCode
    );
}

$processed = 0;
$sent = 0;
$requeued = 0;
$blocked = 0;
$runFailed = false;

try {
    $config = payment_lifecycle_v3_config();
    $workerId = booking_email_worker_id();

    for ($index = 0; $index < BOOKING_EMAIL_WORKER_LIMIT; $index++) {
        $claimRpc = payment_lifecycle_v3_rpc('booking_email_claim', [
            'p_worker_id' => $workerId,
            'p_lease_seconds' => BOOKING_EMAIL_WORKER_LEASE_SECONDS,
        ]);

        if (empty($claimRpc['ok']) || !is_array($claimRpc['data'] ?? null)) {
            booking_email_worker_log('claim_failed');
            $runFailed = true;
            break;
        }

        $claim = $claimRpc['data'];

        if (!array_key_exists('claimed', $claim) || !is_bool($claim['claimed'])) {
            booking_email_worker_log('malformed_claim');
            $runFailed = true;
            break;
        }

        if ($claim['claimed'] === false) {
            if (($claim['blocked'] ?? false) === true) {
                booking_email_worker_log('claim_blocked');
                $blocked++;
                continue;
            }

            if (($claim['superseded'] ?? false) === true) {
                continue;
            }

            break;
        }

        $processed++;

        $outboxId = trim((string) ($claim['email_outbox_id'] ?? ''));
        $claimToken = trim((string) ($claim['claim_token'] ?? ''));
        $tenantId = trim((string) ($claim['tenant_id'] ?? ''));
        $bookingId = trim((string) ($claim['booking_id'] ?? ''));
        $paymentId = trim((string) ($claim['payment_id'] ?? ''));
        $eventType = strtolower(trim((string) ($claim['event_type'] ?? '')));
        $recipientEmail = strtolower(trim((string) ($claim['recipient_email'] ?? '')));
        $deliveryChannel = trim((string) ($claim['delivery_channel'] ?? ''));
        $payload = $claim['payload'] ?? null;

        $tenantChannelEvents = [
            'payment_pending_customer',
            'payment_reminder_customer',
            'payment_paid_customer',
            'payment_expired_customer',
            'booking_created_admin',
            'appointment_reminder_day_before',
            'appointment_reminder_same_day',
        ];
        $systemChannelEvents = [
            'payment_expired_admin',
            'late_paid_review_admin',
            'multiple_paid_review_admin',
            'reconciliation_review_admin',
            'calendar_review_admin',
        ];
        $deferredEvents = [
            'booking_rescheduled_customer',
            'booking_staff_changed_customer',
            'booking_staff_detached_customer',
        ];
        $allowedEvents = array_merge($tenantChannelEvents, $systemChannelEvents, $deferredEvents);

        $claimValid = booking_email_worker_uuid($outboxId)
            && booking_email_worker_uuid($claimToken)
            && booking_email_worker_uuid($bookingId)
            && booking_email_worker_uuid($paymentId)
            && booking_email_worker_safe_text($tenantId, 128)
            && in_array($eventType, $allowedEvents, true)
            && $recipientEmail !== ''
            && strlen($recipientEmail) <= 254
            && preg_match('/[\r\n\x00]/', $recipientEmail) !== 1
            && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) !== false
            && booking_email_worker_payload_valid($payload)
            && (
                (in_array($eventType, $tenantChannelEvents, true) && $deliveryChannel === 'tenant_smtp_with_system_fallback')
                || (in_array($eventType, $systemChannelEvents, true) && $deliveryChannel === 'system_smtp')
                || in_array($eventType, $deferredEvents, true)
            );

        if (!$claimValid) {
            booking_email_worker_log('malformed_claim');

            if (
                booking_email_worker_uuid($outboxId)
                && booking_email_worker_uuid($claimToken)
                && !booking_email_worker_record_pre_send_failure(
                    $outboxId,
                    $claimToken,
                    false,
                    'email_claim_context_invalid'
                )
            ) {
                booking_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            $blocked++;
            continue;
        }

        if (in_array($eventType, $deferredEvents, true)) {
            booking_email_worker_log('context_invalid');

            if (!booking_email_worker_record_pre_send_failure(
                $outboxId,
                $claimToken,
                false,
                'booking_change_contract_not_cut_over'
            )) {
                booking_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            $blocked++;
            continue;
        }

        $context = booking_email_worker_fetch_context(
            $config,
            $tenantId,
            $bookingId,
            $eventType
        );

        if (empty($context['ok'])) {
            $retryable = ($context['retryable'] ?? false) === true;
            $errorCode = trim((string) ($context['error_code'] ?? 'email_context_invalid'));
            booking_email_worker_log($retryable ? 'context_read_failed' : 'context_invalid');

            if (!booking_email_worker_record_pre_send_failure(
                $outboxId,
                $claimToken,
                $retryable,
                $errorCode
            )) {
                booking_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            if ($retryable) {
                $requeued++;
            } else {
                $blocked++;
            }

            continue;
        }

        $booking = $context['booking'];
        $currentPaymentStatus = strtolower(trim((string) ($booking['payment_status'] ?? '')));
        $currentBookingStatus = strtolower(trim((string) ($booking['status'] ?? '')));

        $stateValid = match ($eventType) {
            'payment_pending_customer', 'payment_reminder_customer' =>
                $currentPaymentStatus === 'pending' && $currentBookingStatus === 'pending_payment',
            'payment_paid_customer', 'appointment_reminder_day_before', 'appointment_reminder_same_day' =>
                $currentPaymentStatus === 'paid' && $currentBookingStatus === 'confirmed',
            'payment_expired_customer', 'payment_expired_admin' =>
                $currentPaymentStatus === 'expired',
            default => true,
        };

        if (!$stateValid) {
            booking_email_worker_log('context_invalid');

            if (!booking_email_worker_record_pre_send_failure(
                $outboxId,
                $claimToken,
                true,
                'email_context_changed'
            )) {
                booking_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            $requeued++;
            continue;
        }

        if (in_array($eventType, [
            'payment_pending_customer',
            'payment_reminder_customer',
            'payment_paid_customer',
            'payment_expired_customer',
            'appointment_reminder_day_before',
            'appointment_reminder_same_day',
        ], true)) {
            $currentRecipient = strtolower(trim((string) ($booking['email'] ?? '')));

            if ($currentRecipient === '' || !hash_equals($currentRecipient, $recipientEmail)) {
                booking_email_worker_log('context_invalid');

                if (!booking_email_worker_record_pre_send_failure(
                    $outboxId,
                    $claimToken,
                    true,
                    'recipient_changed_after_claim'
                )) {
                    booking_email_worker_log('record_result_failed');
                    $runFailed = true;
                    break;
                }

                $requeued++;
                continue;
            }
        }

        $rendered = booking_email_worker_render(
            $tenantId,
            $eventType,
            $recipientEmail,
            $context
        );

        if (empty($rendered['ok'])) {
            $retryable = ($rendered['retryable'] ?? false) === true;
            $errorCode = trim((string) ($rendered['error_code'] ?? 'email_render_failed'));
            booking_email_worker_log('render_failed');

            if (!booking_email_worker_record_pre_send_failure(
                $outboxId,
                $claimToken,
                $retryable,
                $errorCode
            )) {
                booking_email_worker_log('record_result_failed');
                $runFailed = true;
                break;
            }

            if ($retryable) {
                $requeued++;
            } else {
                $blocked++;
            }

            continue;
        }

        $subject = trim((string) ($rendered['subject'] ?? ''));
        $html = (string) ($rendered['html'] ?? '');
        $alt = (string) ($rendered['alt'] ?? '');

        $adapter = booking_mail_v7_deliver(
            $tenantId,
            $deliveryChannel,
            $recipientEmail,
            $subject,
            $html,
            $alt,
            is_array($context['email_settings']) ? $context['email_settings'] : null
        );

        $result = is_string($adapter['result'] ?? null)
            ? strtolower(trim((string) $adapter['result']))
            : '';
        $errorCode = is_string($adapter['error_code'] ?? null)
            ? strtolower(trim((string) $adapter['error_code']))
            : '';

        $adapterValid = in_array($result, ['sent', 'failed', 'blocked'], true)
            && ($result === 'sent' || preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $errorCode) === 1)
            && !($result === 'sent' && $errorCode !== '');

        if (!$adapterValid) {
            booking_email_worker_log('adapter_result_invalid');
            $result = 'blocked';
            $errorCode = 'mail_adapter_contract_invalid';
        }

        if (!booking_email_worker_record_result(
            $outboxId,
            $claimToken,
            $result,
            $errorCode
        )) {
            booking_email_worker_log('record_result_failed');
            $runFailed = true;
            break;
        }

        if ($result === 'sent') {
            $sent++;
        } elseif ($result === 'failed') {
            $requeued++;
        } else {
            $blocked++;
        }
    }
} catch (Throwable $e) {
    booking_email_worker_log('worker_run_failed');
    $runFailed = true;
}

if ($runFailed) {
    booking_email_worker_log('worker_run_failed');
    echo json_encode([
        'success' => false,
        'processed' => $processed,
        'sent' => $sent,
        'requeued' => $requeued,
        'blocked' => $blocked,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

booking_email_worker_log('worker_run_success');

if ($processed > 0 || $blocked > 0 || $requeued > 0) {
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'sent' => $sent,
        'requeued' => $requeued,
        'blocked' => $blocked,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit(0);
