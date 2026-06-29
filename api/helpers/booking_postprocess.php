<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/plan_features.php';
require_once __DIR__ . '/google_calendar.php';
require_once __DIR__ . '/booking_mail.php';

function booking_postprocess_config(): array
{
    return [
        'supabase_url' => rtrim((string)getenv('SUPABASE_URL'), '/'),
        'supabase_key' => (string)(getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: ''),
        'schema' => (string)(getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro'),
    ];
}

function booking_postprocess_request(string $method, string $url, ?array $payload = null): array
{
    $config = booking_postprocess_config();
    $headers = supabaseHeaders($config['supabase_key'], $config['schema']);
    $headers[] = 'Accept: application/json';
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $response !== false && $response !== '' ? json_decode((string)$response, true) : null;

    return [
        'ok' => $response !== false
            && $error === ''
            && $httpCode >= 200
            && $httpCode < 300
            && is_array($data),
        'http_code' => $httpCode,
        'error' => $error,
        'data' => $data,
    ];
}

function booking_postprocess_fetch_single(string $table, string $query): ?array
{
    $config = booking_postprocess_config();
    $url = $config['supabase_url'] . '/rest/v1/' . rawurlencode($table) . '?' . $query . '&limit=1';
    $result = booking_postprocess_request('GET', $url);

    if (!$result['ok']) {
        throw new RuntimeException('postprocess_read_failed:' . $table . ':' . (int)$result['http_code']);
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function booking_postprocess_fetch_booking(string $bookingId, string $tenantId): array
{
    $select = implode(',', [
        'id', 'tenant_id', 'booking_date', 'booking_time', 'name', 'email', 'phone', 'notes',
        'status', 'service_id', 'staff_id', 'service_name_snapshot', 'payment_required',
        'payment_status', 'payment_amount', 'payment_currency', 'google_event_id', 'manage_token',
        'manage_token_expires_at',
    ]);
    $booking = booking_postprocess_fetch_single(
        'bookings',
        'select=' . rawurlencode($select)
            . '&id=eq.' . rawurlencode($bookingId)
            . '&tenant_id=eq.' . rawurlencode($tenantId)
    );

    if (!is_array($booking) || empty($booking['id']) || empty($booking['tenant_id'])) {
        throw new RuntimeException('postprocess_booking_not_found');
    }

    if (!hash_equals($tenantId, (string)$booking['tenant_id'])) {
        throw new RuntimeException('postprocess_tenant_mismatch');
    }

    return $booking;
}

function booking_postprocess_feature(array $planContext, string $feature): bool
{
    $features = is_array($planContext['features'] ?? null) ? $planContext['features'] : [];
    return !empty($features[$feature]);
}

function booking_postprocess_manage_token_is_active(string $expiresAt): bool
{
    try {
        return trim($expiresAt) !== ''
            && new DateTimeImmutable($expiresAt) > new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
    } catch (Throwable $e) {
        return false;
    }
}

function booking_postprocess_reschedule_url(string $tenantId, string $token, string $expiresAt): string
{
    if (trim($token) === '' || !booking_postprocess_manage_token_is_active($expiresAt)) {
        return '';
    }

    $domain = booking_postprocess_fetch_single(
        'tenant_domains',
        'select=domain'
            . '&tenant_id=eq.' . rawurlencode($tenantId)
            . '&is_active=eq.true'
    );
    $host = strtolower(trim((string)($domain['domain'] ?? '')));

    if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
        return '';
    }

    return 'https://' . $host . '/przeloz-rezerwacje.html?token=' . rawurlencode($token);
}

function booking_postprocess_admin_html(
    string $companyName,
    array $booking,
    string $staffDisplayName
): string {
    $value = static fn(string $key): string => trim((string)($booking[$key] ?? ''));
    $row = static function (string $label, string $content): string {
        if ($content === '') {
            return '';
        }

        return '<p style="margin:0 0 12px 0;font-size:16px;"><strong>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</strong> '
            . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</p>';
    };
    $intro = '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:#17324d;">'
        . 'W systemie pojawiła się nowa rezerwacja. Szczegóły rezerwacji znajdują się poniżej.'
        . '</p>';
    $details = $row('Imię', $value('name'))
        . $row('E-mail', $value('email'))
        . $row('Telefon', $value('phone'))
        . $row('Data', $value('booking_date'))
        . $row('Personel', $staffDisplayName)
        . $row('Godzina', substr($value('booking_time'), 0, 5));
    $notes = $value('notes') !== ''
        ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">'
            . '<p style="margin:0;font-size:16px;"><strong>Wiadomość klienta:</strong><br>'
            . nl2br(htmlspecialchars($value('notes'), ENT_QUOTES, 'UTF-8')) . '</p></div>'
        : '';

    return '<div style="margin:0;padding:0;background:#f4f7fb;">'
        . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
        . '<div style="background:#0f2d47;padding:32px 24px;text-align:center;color:#ffffff;">'
        . '<h1 style="margin:0;font-size:28px;">Nowa rezerwacja</h1>'
        . '<p style="margin:12px 0 0 0;font-size:16px;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p></div>'
        . '<div style="padding:32px 24px;">' . $intro
        . '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">'
        . $details . '</div>' . $notes . '</div>'
        . booking_mail_build_footer('basic', 'system', '')
        . '</div></div>';
}

function booking_postprocess_execute_job(array $job): array
{
    $config = booking_postprocess_config();

    if ($config['supabase_url'] === '' || $config['supabase_key'] === '') {
        throw new RuntimeException('postprocess_missing_supabase_config');
    }

    $bookingId = (string)$job['booking_id'];
    $tenantId = (string)$job['tenant_id'];
    $tasks = is_array($job['tasks'] ?? null) ? $job['tasks'] : [];
    $booking = booking_postprocess_fetch_booking($bookingId, $tenantId);
    $staffId = trim((string)($booking['staff_id'] ?? ''));
    $serviceId = trim((string)($booking['service_id'] ?? ''));
    $staff = $staffId !== ''
        ? booking_postprocess_fetch_single(
            'staff_profiles',
            'select=id,display_name,service_duration_minutes,email_subject,email_heading,email_body'
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&id=eq.' . rawurlencode($staffId)
        )
        : null;
    $service = $serviceId !== ''
        ? booking_postprocess_fetch_single(
            'tenant_services',
            'select=id,duration_minutes'
                . '&tenant_id=eq.' . rawurlencode($tenantId)
                . '&id=eq.' . rawurlencode($serviceId)
        )
        : null;
    $calendar = booking_postprocess_fetch_single(
        'calendar_settings',
        'select=consultation_duration&tenant_id=eq.' . rawurlencode($tenantId)
    );
    $durationMinutes = max(1, (int)(
        $service['duration_minutes']
        ?? $staff['service_duration_minutes']
        ?? $calendar['consultation_duration']
        ?? 60
    ));
    $staffDisplayName = trim((string)($staff['display_name'] ?? ''));

    if (!in_array($tasks['google_calendar'] ?? '', ['done', 'skipped'], true)) {
        if (trim((string)($booking['google_event_id'] ?? '')) !== '') {
            $tasks['google_calendar'] = 'done';
        } elseif (!tenant_has_feature($tenantId, 'google_calendar')) {
            $tasks['google_calendar'] = 'skipped';
        } else {
            try {
                $googleBooking = $booking;
                unset(
                    $googleBooking['manage_token'],
                    $googleBooking['manage_token_expires_at'],
                    $googleBooking['google_event_id']
                );
                $googleBooking['staff_display_name'] = $staffDisplayName;
                $googleBooking['duration_minutes'] = $durationMinutes;
                $googleExecution = null;
                $googleEventId = createGoogleCalendarEventForBooking(
                    $tenantId,
                    $googleBooking,
                    $googleExecution
                );

                if (($googleExecution['status'] ?? '') === 'skipped') {
                    $tasks['google_calendar'] = 'skipped';
                } elseif (
                    is_string($googleEventId)
                    && $googleEventId !== ''
                    && google_calendar_update_booking_event_id($bookingId, $googleEventId, $tenantId)
                ) {
                    $tasks['google_calendar'] = 'done';
                } else {
                    $tasks['google_calendar'] = 'failed';
                }
            } catch (Throwable $e) {
                $tasks['google_calendar'] = 'failed';
            }
        }
    }

    $needsClientEmail = !in_array($tasks['client_email'] ?? '', ['done', 'skipped'], true);
    $needsAdminEmail = !in_array($tasks['admin_email'] ?? '', ['done', 'skipped'], true);

    if ($needsClientEmail || $needsAdminEmail) {
        $tenantQuery = 'tenant_id=eq.' . rawurlencode($tenantId);
        $emailSettings = booking_postprocess_fetch_single(
            'email_settings',
            $tenantQuery . '&is_active=eq.true&select=smtp_host,smtp_port,smtp_encryption,smtp_username,smtp_password,from_email,from_name,reply_to_email,reply_to_name,admin_notify_email,send_client_confirmation,send_admin_notification'
        );
        $branding = booking_postprocess_fetch_single(
            'tenant_branding',
            $tenantQuery . '&select=client_name,email_footer_mode,email_footer_custom'
        );
        $serviceSettings = booking_postprocess_fetch_single(
            'tenant_service_settings',
            $tenantQuery . '&select=company_full_name,company_email'
        );
        $tenantMailData = array_merge(
            is_array($branding) ? $branding : [],
            is_array($serviceSettings) ? $serviceSettings : []
        );
        $paymentRequired = !empty($booking['payment_required']);

        if ($needsClientEmail) {
            if ($paymentRequired) {
                $tasks['client_email'] = 'skipped';
            } else {
                $emailTemplate = booking_postprocess_fetch_single(
                    'email_templates',
                    $tenantQuery . '&template_key=eq.booking_client_confirmation&is_enabled=eq.true&select=subject,service_name,body_html'
                );
                $effectiveTemplate = is_array($emailTemplate)
                    ? $emailTemplate
                    : booking_mail_default_client_template();

                if (trim((string)($staff['email_subject'] ?? '')) !== '') {
                    $effectiveTemplate['subject'] = (string)$staff['email_subject'];
                }

                if (trim((string)($staff['email_heading'] ?? '')) !== '') {
                    $effectiveTemplate['service_name'] = (string)$staff['email_heading'];
                }

                if (trim((string)($staff['email_body'] ?? '')) !== '') {
                    $effectiveTemplate['body_html'] = (string)$staff['email_body'];
                }

                $planContext = plan_features_get_context($tenantId);
                $rescheduleUrl = booking_postprocess_feature($planContext, 'reschedule_booking')
                    ? booking_postprocess_reschedule_url(
                        $tenantId,
                        (string)($booking['manage_token'] ?? ''),
                        (string)($booking['manage_token_expires_at'] ?? '')
                    )
                    : '';
                $clientSent = booking_mail_send_client_confirmation_with_fallback(
                    is_array($emailSettings) ? $emailSettings : null,
                    $effectiveTemplate,
                    $tenantMailData,
                    array_merge($booking, ['staff_display_name' => $staffDisplayName]),
                    ['reschedule_url' => $rescheduleUrl]
                );
                $tasks['client_email'] = $clientSent ? 'done' : 'failed';
            }
        }

        if ($needsAdminEmail) {
            if (!booking_mail_admin_notification_enabled($emailSettings)) {
                $tasks['admin_email'] = 'skipped';
            } else {
                $configuredAdminEmail = trim((string)($emailSettings['admin_notify_email'] ?? ''));
                $adminAccountEmail = '';

                if ($configuredAdminEmail === '' || !filter_var($configuredAdminEmail, FILTER_VALIDATE_EMAIL)) {
                    $adminAccount = booking_postprocess_fetch_single(
                        'users',
                        $tenantQuery . '&select=email&role=in.(admin,administrator)&is_active=eq.true'
                    );
                    $adminAccountEmail = trim((string)($adminAccount['email'] ?? ''));
                }

                $recipient = booking_mail_admin_notification_email(
                    $tenantMailData,
                    is_array($emailSettings) ? $emailSettings : null,
                    $adminAccountEmail
                );

                if ($recipient === '') {
                    $tasks['admin_email'] = 'skipped';
                } else {
                    $companyName = trim((string)($tenantMailData['client_name'] ?? $tenantMailData['company_full_name'] ?? ''));
                    $subject = 'Nowa rezerwacja – ' . (string)$booking['booking_date'] . ' ' . substr((string)$booking['booking_time'], 0, 5);
                    $html = booking_postprocess_admin_html($companyName, $booking, $staffDisplayName);
                    $altBody = "Nowa rezerwacja\n\n"
                        . 'Imię: ' . (string)$booking['name'] . "\n"
                        . 'E-mail: ' . (string)$booking['email'] . "\n"
                        . 'Telefon: ' . (string)$booking['phone'] . "\n"
                        . 'Data: ' . (string)$booking['booking_date'] . "\n"
                        . ($staffDisplayName !== '' ? 'Personel: ' . $staffDisplayName . "\n" : '')
                        . 'Godzina: ' . substr((string)$booking['booking_time'], 0, 5) . "\n"
                        . (trim((string)($booking['notes'] ?? '')) !== ''
                            ? 'Wiadomość klienta: ' . trim((string)$booking['notes']) . "\n"
                            : '');
                    $adminSent = booking_mail_send_admin_notification_with_fallback(
                        is_array($emailSettings) ? $emailSettings : null,
                        $tenantMailData,
                        array_merge($booking, ['staff_display_name' => $staffDisplayName]),
                        $recipient,
                        $subject,
                        $html,
                        $altBody
                    );
                    $tasks['admin_email'] = $adminSent ? 'done' : 'failed';
                }
            }
        }
    }

    $success = true;

    foreach ($tasks as $status) {
        if (!in_array($status, ['done', 'skipped'], true)) {
            $success = false;
            break;
        }
    }

    return [
        'success' => $success,
        'tasks' => $tasks,
    ];
}
