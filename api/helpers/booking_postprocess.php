<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/plan_features.php';
require_once __DIR__ . '/payment_lifecycle_v3.php';
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
    $details = $row('👤 Imię', $value('name'))
        . $row('📧 E-mail', $value('email'))
        . $row('📞 Telefon', $value('phone'))
        . $row('📅 Data', $value('booking_date'))
        . $row('Personel', $staffDisplayName)
        . $row('⏰ Godzina', substr($value('booking_time'), 0, 5));
    $clientMessageNotice = !empty($booking['has_client_message'])
        ? '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">'
            . '<p style="margin:0;font-size:16px;">💬 Wiadomość od klienta zobaczysz w swoim panelu rezerwacji.</p></div>'
        : '';

    return '<div style="margin:0;padding:0;background:#f4f7fb;">'
        . '<div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">'
        . '<div style="background:#0f2d47;padding:32px 24px;text-align:center;color:#ffffff;">'
        . '<div style="font-size:42px;line-height:1;margin-bottom:12px;">📅</div>'
        . '<h1 style="margin:0;font-size:28px;">Nowa rezerwacja</h1>'
        . '<p style="margin:12px 0 0 0;font-size:16px;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p></div>'
        . '<div style="padding:32px 24px;">' . $intro
        . '<div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">'
        . $details . '</div>' . $clientMessageNotice . '</div>'
        . booking_mail_build_footer('basic', 'system', '')
        . '</div></div>';
}

function booking_postprocess_execute_job(array $job): array
{
    $config = booking_postprocess_config();

    if ($config['supabase_url'] === '' || $config['supabase_key'] === '') {
        throw new RuntimeException('postprocess_missing_supabase_config');
    }

    $bookingId = trim((string)($job['booking_id'] ?? ''));
    $tenantId = trim((string)($job['tenant_id'] ?? ''));
    $tasks = is_array($job['tasks'] ?? null) ? $job['tasks'] : [];

    if ($bookingId === '' || $tenantId === '') {
        throw new RuntimeException('postprocess_job_context_invalid');
    }

    $booking = booking_postprocess_fetch_booking($bookingId, $tenantId);
    $paymentRequired = !empty($booking['payment_required']);

    // The durable legacy postprocess contract is intentionally limited to the
    // current public no-payment producer. Paid bookings use the V1 payment
    // lifecycle/outboxes and must never be routed through this compatibility path.
    if ($paymentRequired) {
        foreach (['google_calendar', 'client_email', 'admin_email'] as $task) {
            if (!in_array($tasks[$task] ?? '', ['done', 'skipped'], true)) {
                $tasks[$task] = 'failed';
            }
        }

        return [
            'success' => false,
            'tasks' => $tasks,
        ];
    }

    $needsCalendar = !in_array($tasks['google_calendar'] ?? '', ['done', 'skipped'], true);
    $needsClientEmail = !in_array($tasks['client_email'] ?? '', ['done', 'skipped'], true);
    $needsAdminEmail = !in_array($tasks['admin_email'] ?? '', ['done', 'skipped'], true);

    $enqueueCalendar = false;

    if ($needsCalendar) {
        if (trim((string)($booking['google_event_id'] ?? '')) !== '') {
            $tasks['google_calendar'] = 'done';
        } elseif (!tenant_has_feature($tenantId, 'google_calendar')) {
            $tasks['google_calendar'] = 'skipped';
        } else {
            $enqueueCalendar = true;
        }
    }

    if (!$enqueueCalendar && !$needsClientEmail && !$needsAdminEmail) {
        return [
            'success' => true,
            'tasks' => $tasks,
        ];
    }

    $rpc = payment_lifecycle_v3_rpc('booking_legacy_postprocess_enqueue', [
        'p_tenant_id' => $tenantId,
        'p_booking_id' => $bookingId,
        'p_enqueue_calendar' => $enqueueCalendar,
        'p_enqueue_client_email' => $needsClientEmail,
        'p_enqueue_admin_email' => $needsAdminEmail,
    ]);

    if (empty($rpc['ok']) || !is_array($rpc['data'] ?? null)) {
        if ($enqueueCalendar) {
            $tasks['google_calendar'] = 'failed';
        }
        if ($needsClientEmail) {
            $tasks['client_email'] = 'failed';
        }
        if ($needsAdminEmail) {
            $tasks['admin_email'] = 'failed';
        }

        return [
            'success' => false,
            'tasks' => $tasks,
        ];
    }

    $data = $rpc['data'];
    $calendarStatus = strtolower(trim((string)($data['calendar_status'] ?? '')));
    $clientStatus = strtolower(trim((string)($data['client_email_status'] ?? '')));
    $adminStatus = strtolower(trim((string)($data['admin_email_status'] ?? '')));

    if ($enqueueCalendar) {
        if (in_array($calendarStatus, ['queued', 'already_bound'], true)) {
            $tasks['google_calendar'] = 'done';
        } else {
            $tasks['google_calendar'] = 'failed';
        }
    }

    if ($needsClientEmail) {
        $tasks['client_email'] = $clientStatus === 'queued' ? 'done' : 'failed';
    }

    if ($needsAdminEmail) {
        if ($adminStatus === 'queued') {
            $tasks['admin_email'] = 'done';
        } elseif ($adminStatus === 'disabled') {
            $tasks['admin_email'] = 'skipped';
        } else {
            $tasks['admin_email'] = 'failed';
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
