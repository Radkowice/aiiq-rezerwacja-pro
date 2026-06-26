<?php
declare(strict_types=1);

function public_response_hidden_fields(): array
{
    return [
        'tenant_id',
        'tenantId',
        'company_id',
        'companyId',
        'user_id',
        'userId',
        'owner_id',
        'ownerId',
        'internal_id',
        'internalId',
        'uuid',
        'supabase_id',
        'supabaseId',
    ];
}

function public_response_ref_secret(string $supabaseKey): string
{
    return (string) (
        getenv('BOOKING_PUBLIC_REF_SECRET')
        ?: getenv('APP_SECRET')
        ?: getenv('SUPABASE_SERVICE_ROLE_KEY')
        ?: $supabaseKey
    );
}

function public_response_build_ref(string $prefix, string $kind, string $tenantId, string $recordId, string $secret): string
{
    return $prefix . '_' . substr(hash_hmac('sha256', $kind . '|' . $tenantId . '|' . $recordId, $secret), 0, 48);
}

function public_response_booking_ref(string $tenantId, string $bookingId, string $secret): string
{
    return public_response_build_ref('bk', 'booking', $tenantId, $bookingId, $secret);
}

function public_response_staff_ref(string $tenantId, string $staffId, string $secret): string
{
    return public_response_build_ref('st', 'staff', $tenantId, $staffId, $secret);
}

function public_response_service_ref(string $tenantId, string $serviceId, string $secret): string
{
    return public_response_build_ref('svc', 'service', $tenantId, $serviceId, $secret);
}

function public_response_array_is_list(array $value): bool
{
    $index = 0;

    foreach (array_keys($value) as $key) {
        if ($key !== $index) {
            return false;
        }

        $index++;
    }

    return true;
}

function public_response_sanitize($value, ?array $hiddenFields = null)
{
    $hidden = $hiddenFields ?? public_response_hidden_fields();

    if (!is_array($value)) {
        return $value;
    }

    $isList = public_response_array_is_list($value);
    $sanitized = [];

    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $hidden, true)) {
            continue;
        }

        if (is_int($key) && is_string($item) && in_array($item, $hidden, true)) {
            continue;
        }

        $cleanItem = public_response_sanitize($item, $hidden);

        if ($isList) {
            $sanitized[] = $cleanItem;
        } else {
            $sanitized[$key] = $cleanItem;
        }
    }

    return $sanitized;
}

function public_response_account_ref(?array $branding): string
{
    if (!is_array($branding)) {
        return '';
    }

    return trim((string)($branding['client_number'] ?? ''));
}

function public_response_identity(?array $branding): array
{
    $accountRef = public_response_account_ref($branding);

    return $accountRef !== ''
        ? ['account_ref' => $accountRef]
        : [];
}
