<?php
declare(strict_types=1);

function activation_link_secret(): string
{
    return trim((string) (
        getenv('ACTIVATION_LINK_SECRET')
        ?: getenv('SUPABASE_SERVICE_ROLE_KEY')
        ?: getenv('SUPABASE_KEY')
        ?: ''
    ));
}

function activation_link_is_uuid(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        trim($value)
    ) === 1;
}

function activation_link_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function activation_link_base64url_decode(string $value): ?string
{
    if ($value === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $value) !== 1) {
        return null;
    }

    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

    return $decoded === false ? null : $decoded;
}

function activation_link_signature(string $token, string $payload): string
{
    $secret = activation_link_secret();

    if ($secret === '') {
        return '';
    }

    return hash_hmac('sha256', "activation-link-v1\n{$token}\n{$payload}", $secret);
}

function activation_link_build_ref(string $token, string $tenantId, string $userId): string
{
    $token = trim($token);
    $tenantId = strtolower(trim($tenantId));
    $userId = strtolower(trim($userId));

    if (
        preg_match('/^[a-f0-9]{64}$/i', $token) !== 1
        || !activation_link_is_uuid($tenantId)
        || !activation_link_is_uuid($userId)
    ) {
        return '';
    }

    $json = json_encode([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($json) || $json === '') {
        return '';
    }

    $payload = activation_link_base64url_encode($json);
    $signature = activation_link_signature($token, $payload);

    return $signature !== '' ? 'v1.' . $payload . '.' . $signature : '';
}

function activation_link_parse_ref(string $token, string $ref): ?array
{
    $token = trim($token);
    $ref = trim($ref);

    if (
        preg_match('/^[a-f0-9]{64}$/i', $token) !== 1
        || preg_match('/^v1\.([a-zA-Z0-9_-]+)\.([a-f0-9]{64})$/', $ref, $matches) !== 1
    ) {
        return null;
    }

    $payload = (string) ($matches[1] ?? '');
    $providedSignature = strtolower((string) ($matches[2] ?? ''));
    $expectedSignature = activation_link_signature($token, $payload);

    if ($expectedSignature === '' || !hash_equals($expectedSignature, $providedSignature)) {
        return null;
    }

    $decoded = activation_link_base64url_decode($payload);
    $data = $decoded !== null ? json_decode($decoded, true) : null;

    if (!is_array($data)) {
        return null;
    }

    $tenantId = strtolower(trim((string) ($data['tenant_id'] ?? '')));
    $userId = strtolower(trim((string) ($data['user_id'] ?? '')));

    if (!activation_link_is_uuid($tenantId) || !activation_link_is_uuid($userId)) {
        return null;
    }

    return [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
    ];
}
