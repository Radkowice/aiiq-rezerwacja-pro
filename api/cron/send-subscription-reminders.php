<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/supabase.php';
require_once __DIR__ . '/../helpers/system_subscription_mail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function subscription_reminder_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscription_reminder_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function subscription_reminder_headers(string $key, string $schema, bool $returnRepresentation = true): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Accept-Profile: ' . $schema,
        'Content-Profile: ' . $schema,
        'Prefer: return=' . ($returnRepresentation ? 'representation' : 'minimal'),
    ];
}

function subscription_reminder_request(string $method, string $url, array $headers, ?array $payload = null): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);

    return [
        'ok' => $raw !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'error' => $curlError ?: null,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function subscription_reminder_fetch_context(string $supabaseUrl, array $headers, string $tenantId): array
{
    $context = [
        'recipient_email' => '',
        'company_name' => '',
        'panel_domain' => '',
    ];

    $settingsUrl = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_service_settings?select=company_full_name,company_email'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&limit=1';
    $settingsResult = subscription_reminder_request('GET', $settingsUrl, $headers);
    $settings = $settingsResult['ok'] && is_array($settingsResult['data'][0] ?? null)
        ? $settingsResult['data'][0]
        : [];

    $context['company_name'] = trim((string) ($settings['company_full_name'] ?? ''));
    $companyEmail = trim((string) ($settings['company_email'] ?? ''));

    $domainUrl = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_domains?select=domain'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&is_active=eq.true'
        . '&order=is_primary.desc'
        . '&limit=1';
    $domainResult = subscription_reminder_request('GET', $domainUrl, $headers);

    if ($domainResult['ok'] && is_array($domainResult['data'][0] ?? null)) {
        $context['panel_domain'] = trim((string) ($domainResult['data'][0]['domain'] ?? ''));
    }

    $userUrl = rtrim($supabaseUrl, '/')
        . '/rest/v1/users?select=email'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&role=in.(administrator,admin)'
        . '&order=created_at.asc'
        . '&limit=1';
    $userResult = subscription_reminder_request('GET', $userUrl, $headers);

    if ($userResult['ok'] && is_array($userResult['data'][0] ?? null)) {
        $context['recipient_email'] = trim((string) ($userResult['data'][0]['email'] ?? ''));
    }

    if (!filter_var($context['recipient_email'], FILTER_VALIDATE_EMAIL) && filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
        $context['recipient_email'] = $companyEmail;
    }

    return $context;
}

function subscription_reminder_type_for_days(int $daysLeft): string
{
    return match ($daysLeft) {
        7 => 'subscription_reminder_7d',
        1 => 'subscription_reminder_1d',
        0 => 'subscription_reminder_0d',
        default => '',
    };
}

function subscription_reminder_days_left(?string $periodEnd, DateTimeImmutable $today, DateTimeZone $timeZone): ?int
{
    $periodEnd = trim((string) $periodEnd);

    if ($periodEnd === '') {
        return null;
    }

    try {
        $end = (new DateTimeImmutable($periodEnd, $timeZone))->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }

    return (int) $today->diff($end)->format('%r%a');
}

function subscription_reminder_fetch_log(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $emailType,
    string $periodEnd
): ?array {
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/subscription_email_logs?select=id,status'
        . '&tenant_id=eq.' . rawurlencode($tenantId)
        . '&email_type=eq.' . rawurlencode($emailType)
        . '&subscription_period_end=eq.' . rawurlencode($periodEnd)
        . '&limit=1';
    $result = subscription_reminder_request('GET', $url, $headers);

    if (!$result['ok']) {
        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : [];
}

function subscription_reminder_create_log(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $emailType,
    string $periodEnd,
    string $recipientEmail
): ?array {
    $now = gmdate('c');
    $url = rtrim($supabaseUrl, '/') . '/rest/v1/subscription_email_logs';
    $result = subscription_reminder_request('POST', $url, $headers, [
        'tenant_id' => $tenantId,
        'email_type' => $emailType,
        'subscription_period_end' => $periodEnd,
        'recipient_email' => $recipientEmail,
        'status' => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if (!$result['ok']) {
        return null;
    }

    return is_array($result['data'][0] ?? null) ? $result['data'][0] : null;
}

function subscription_reminder_update_log(
    string $supabaseUrl,
    array $headers,
    string $logId,
    array $payload
): bool {
    $payload['updated_at'] = gmdate('c');
    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/subscription_email_logs?id=eq.' . rawurlencode($logId);

    return subscription_reminder_request('PATCH', $url, $headers, $payload)['ok'];
}

function subscription_reminder_prepare_log(
    string $supabaseUrl,
    array $headers,
    string $tenantId,
    string $emailType,
    string $periodEnd,
    string $recipientEmail
): array {
    $existing = subscription_reminder_fetch_log($supabaseUrl, $headers, $tenantId, $emailType, $periodEnd);

    if (is_array($existing) && !empty($existing)) {
        $status = strtolower(trim((string) ($existing['status'] ?? '')));

        if (in_array($status, ['sent', 'pending'], true)) {
            return [
                'ok' => true,
                'send_allowed' => false,
                'reason' => $status,
                'log_id' => trim((string) ($existing['id'] ?? '')),
            ];
        }

        if ($status === 'failed') {
            return [
                'ok' => false,
                'send_allowed' => false,
                'reason' => 'failed',
                'log_id' => trim((string) ($existing['id'] ?? '')),
            ];
        }
    } elseif ($existing === null) {
        return [
            'ok' => false,
            'send_allowed' => false,
            'reason' => 'log_fetch_failed',
        ];
    }

    $created = subscription_reminder_create_log($supabaseUrl, $headers, $tenantId, $emailType, $periodEnd, $recipientEmail);

    if (is_array($created) && trim((string) ($created['id'] ?? '')) !== '') {
        return [
            'ok' => true,
            'send_allowed' => true,
            'reason' => 'created',
            'log_id' => trim((string) $created['id']),
        ];
    }

    $afterCreate = subscription_reminder_fetch_log($supabaseUrl, $headers, $tenantId, $emailType, $periodEnd);

    if (is_array($afterCreate) && !empty($afterCreate)) {
        return [
            'ok' => true,
            'send_allowed' => false,
            'reason' => strtolower(trim((string) ($afterCreate['status'] ?? 'existing'))),
            'log_id' => trim((string) ($afterCreate['id'] ?? '')),
        ];
    }

    return [
        'ok' => false,
        'send_allowed' => false,
        'reason' => 'log_create_failed',
    ];
}

try {
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        subscription_reminder_json(405, [
            'success' => false,
            'error' => 'Metoda niedozwolona.',
        ]);
    }

    $cronSecret = trim((string) getenv('SUBSCRIPTION_REMINDER_CRON_SECRET'));

    if ($cronSecret === '') {
        subscription_reminder_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji SUBSCRIPTION_REMINDER_CRON_SECRET.',
        ]);
    }

    $headerSecret = subscription_reminder_header('X-Cron-Secret');

    if ($headerSecret === '' || !hash_equals($cronSecret, $headerSecret)) {
        subscription_reminder_json(401, [
            'success' => false,
            'error' => 'Brak autoryzacji crona.',
        ]);
    }

    $supabaseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $supabaseKey = (string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: '');
    $schema = (string) (getenv('SUPABASE_DB_SCHEMA') ?: 'rezerwacja_pro');

    if ($supabaseUrl === '' || $supabaseKey === '') {
        subscription_reminder_json(500, [
            'success' => false,
            'error' => 'Brak konfiguracji Supabase.',
        ]);
    }

    $headers = subscription_reminder_headers($supabaseKey, $schema);
    $minimalHeaders = subscription_reminder_headers($supabaseKey, $schema, false);
    $logProbeUrl = rtrim($supabaseUrl, '/') . '/rest/v1/subscription_email_logs?select=id&limit=1';
    $logProbe = subscription_reminder_request('GET', $logProbeUrl, $headers);

    if (!$logProbe['ok']) {
        subscription_reminder_json(500, [
            'success' => false,
            'sql_required' => true,
            'error' => 'Brakuje tabeli logów subscription_email_logs albo uprawnień do niej.',
        ]);
    }

    $timeZone = new DateTimeZone((string) (getenv('APP_TIMEZONE') ?: 'Europe/Warsaw'));
    $today = (new DateTimeImmutable('today', $timeZone))->setTime(0, 0, 0);
    $targetDates = [
        $today->modify('+7 days')->format('Y-m-d'),
        $today->modify('+1 day')->format('Y-m-d'),
        $today->format('Y-m-d'),
    ];

    $subscriptionsUrl = rtrim($supabaseUrl, '/')
        . '/rest/v1/tenant_subscriptions'
        . '?select=tenant_id,plan_code,plan_name,billing_period,status,amount,currency,current_period_start,current_period_end,last_reminder_at,reminder_count'
        . '&status=eq.active'
        . '&plan_code=in.(pro,vip,biznes,business)'
        . '&current_period_end=in.(' . implode(',', array_map('rawurlencode', $targetDates)) . ')';
    $subscriptionsResult = subscription_reminder_request('GET', $subscriptionsUrl, $headers);

    if (!$subscriptionsResult['ok']) {
        subscription_reminder_json(500, [
            'success' => false,
            'error' => 'Nie udało się pobrać abonamentów do przypomnień.',
        ]);
    }

    $sent = 0;
    $skipped = 0;
    $failed = 0;
    $checked = 0;

    foreach ($subscriptionsResult['data'] as $subscription) {
        if (!is_array($subscription)) {
            continue;
        }

        $tenantId = trim((string) ($subscription['tenant_id'] ?? ''));
        $periodEnd = trim((string) ($subscription['current_period_end'] ?? ''));
        $daysLeft = subscription_reminder_days_left($periodEnd, $today, $timeZone);

        if ($tenantId === '' || $daysLeft === null || !in_array($daysLeft, [7, 1, 0], true)) {
            $skipped++;
            continue;
        }

        $checked++;
        $emailType = subscription_reminder_type_for_days($daysLeft);

        $context = subscription_reminder_fetch_context($supabaseUrl, $headers, $tenantId);
        $recipientEmail = trim((string) ($context['recipient_email'] ?? ''));

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $failed++;
            continue;
        }

        $logState = subscription_reminder_prepare_log($supabaseUrl, $headers, $tenantId, $emailType, $periodEnd, $recipientEmail);

        if (empty($logState['ok'])) {
            $failed++;
            continue;
        }

        if (empty($logState['send_allowed'])) {
            $skipped++;
            continue;
        }

        $logId = trim((string) ($logState['log_id'] ?? ''));

        if ($logId === '') {
            $failed++;
            continue;
        }

        $html = buildSubscriptionReminderMailHtml($subscription, $context, $daysLeft);

        if (!sendSystemMail($recipientEmail, 'Przypomnienie o abonamencie AI-IQ Rezerwacja Pro', $html)) {
            subscription_reminder_update_log($supabaseUrl, $headers, $logId, [
                'status' => 'failed',
                'failed_at' => gmdate('c'),
            ]);
            $failed++;
            continue;
        }

        subscription_reminder_update_log($supabaseUrl, $headers, $logId, [
            'status' => 'sent',
            'sent_at' => gmdate('c'),
        ]);

        $reminderCount = max(0, (int) ($subscription['reminder_count'] ?? 0)) + 1;
        $subscriptionUpdateUrl = rtrim($supabaseUrl, '/')
            . '/rest/v1/tenant_subscriptions?tenant_id=eq.' . rawurlencode($tenantId);
        subscription_reminder_request('PATCH', $subscriptionUpdateUrl, $minimalHeaders, [
            'last_reminder_at' => gmdate('c'),
            'reminder_count' => $reminderCount,
            'updated_at' => gmdate('c'),
        ]);

        $sent++;
    }

    subscription_reminder_json(200, [
        'success' => true,
        'checked' => $checked,
        'sent' => $sent,
        'skipped' => $skipped,
        'failed' => $failed,
        'target_dates' => $targetDates,
    ]);
} catch (Throwable $e) {
    subscription_reminder_json(500, [
        'success' => false,
        'error' => 'Błąd crona przypomnień abonamentowych.',
    ]);
}
