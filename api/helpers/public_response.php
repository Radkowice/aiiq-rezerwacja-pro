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
