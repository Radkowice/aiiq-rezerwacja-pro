<?php

/**
 * Helper szyfrowania danych wrażliwych integracji.
 *
 * Wymaga ENV:
 * APP_ENCRYPTION_KEY
 *
 * Klucz powinien mieć 32 bajty po base64_decode.
 * Przykład formatu:
 * APP_ENCRYPTION_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 */

function get_app_encryption_key(): string
{
    $raw = trim((string) getenv('APP_ENCRYPTION_KEY'));

    if ($raw === '') {
        throw new RuntimeException('Brak APP_ENCRYPTION_KEY w ENV');
    }

    if (str_starts_with($raw, 'hex:')) {
        $hex = substr($raw, 4);

        if (!preg_match('/^[a-f0-9]{64}$/i', $hex)) {
            throw new RuntimeException('Nieprawidłowy APP_ENCRYPTION_KEY hex. Wymagane 64 znaki hex.');
        }

        $key = hex2bin($hex);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Nieprawidłowy APP_ENCRYPTION_KEY hex. Wymagane 32 bajty.');
        }

        return $key;
    }

    if (str_starts_with($raw, 'base64:')) {
        $raw = substr($raw, 7);
    }

    $key = base64_decode($raw, true);

    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Nieprawidłowy APP_ENCRYPTION_KEY. Wymagane 32 bajty base64.');
    }

    return $key;
}

function encrypt_json_secret(array $plainData): array
{
    if (empty($plainData)) {
        return [];
    }

    $key = get_app_encryption_key();

    $iv = random_bytes(12);
    $plainJson = json_encode($plainData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($plainJson === false) {
        throw new RuntimeException('Nie udało się zakodować danych do szyfrowania');
    }

    $tag = '';

    $cipherText = openssl_encrypt(
        $plainJson,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipherText === false) {
        throw new RuntimeException('Nie udało się zaszyfrować danych');
    }

    return [
        '_encrypted' => true,
        'alg' => 'aes-256-gcm',
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($cipherText),
    ];
}

function decrypt_json_secret(array $encryptedData): array
{
    if (empty($encryptedData)) {
        return [];
    }

    /**
     * Kompatybilność z obecnymi testowymi rekordami,
     * które były zapisane jawnie przed wdrożeniem szyfrowania.
     * Po ponownym zapisie endpoint zapisze je już jako zaszyfrowane.
     */
    if (($encryptedData['_encrypted'] ?? false) !== true) {
        return $encryptedData;
    }

    if (($encryptedData['alg'] ?? '') !== 'aes-256-gcm') {
        throw new RuntimeException('Nieobsługiwany algorytm szyfrowania');
    }

    $key = get_app_encryption_key();

    $iv = base64_decode((string) ($encryptedData['iv'] ?? ''), true);
    $tag = base64_decode((string) ($encryptedData['tag'] ?? ''), true);
    $cipherText = base64_decode((string) ($encryptedData['data'] ?? ''), true);

    if ($iv === false || $tag === false || $cipherText === false) {
        throw new RuntimeException('Uszkodzone dane szyfrowania');
    }

    $plainJson = openssl_decrypt(
        $cipherText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plainJson === false) {
        throw new RuntimeException('Nie udało się odszyfrować danych');
    }

    $plainData = json_decode($plainJson, true);

    if (!is_array($plainData)) {
        throw new RuntimeException('Nieprawidłowy format odszyfrowanych danych');
    }

    return $plainData;
}
/**
 * Szyfruje hasło SMTP tenantu przed zapisem do email_settings.smtp_password.
 *
 * Jawny prefiks pozwala odróżnić nowy ciphertext od legacy plaintext
 * podczas kontrolowanego okresu migracyjnego.
 */
function encrypt_smtp_password_secret(string $plainPassword): string
{
    if ($plainPassword === '') {
        return '';
    }

    if (strlen($plainPassword) > 4096 || str_contains($plainPassword, "\0")) {
        throw new RuntimeException('Nieprawidłowe hasło SMTP');
    }

    $encrypted = encrypt_json_secret([
        'kind' => 'smtp_password',
        'value' => $plainPassword,
    ]);

    $json = json_encode($encrypted, JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Nie udało się zakodować zaszyfrowanego hasła SMTP');
    }

    return 'aiiq-smtp:v1:' . base64_encode($json);
}

/**
 * Odszyfrowuje nowe hasło SMTP i tymczasowo obsługuje legacy plaintext.
 *
 * Jeżeli wartość posiada prefiks ciphertextu, odszyfrowanie musi się udać.
 * Uszkodzony lub zmodyfikowany ciphertext nigdy nie jest traktowany jako
 * plaintext — operacja kończy się fail-closed.
 */
function decrypt_smtp_password_secret(string $storedPassword): string
{
    if ($storedPassword === '') {
        return '';
    }

    $prefix = 'aiiq-smtp:v1:';

    if (!str_starts_with($storedPassword, $prefix)) {
        if (strlen($storedPassword) > 4096 || str_contains($storedPassword, "\0")) {
            throw new RuntimeException('Nieprawidłowe hasło SMTP');
        }

        return $storedPassword;
    }

    $encoded = substr($storedPassword, strlen($prefix));
    $json = base64_decode($encoded, true);

    if ($encoded === '' || $json === false) {
        throw new RuntimeException('Uszkodzone zaszyfrowane hasło SMTP');
    }

    $encrypted = json_decode($json, true);

    if (!is_array($encrypted)) {
        throw new RuntimeException('Uszkodzone zaszyfrowane hasło SMTP');
    }

    $plain = decrypt_json_secret($encrypted);

    if (
        ($plain['kind'] ?? null) !== 'smtp_password'
        || !array_key_exists('value', $plain)
        || !is_string($plain['value'])
    ) {
        throw new RuntimeException('Nieprawidłowy format zaszyfrowanego hasła SMTP');
    }

    $password = $plain['value'];

    if (strlen($password) > 4096 || str_contains($password, "\0")) {
        throw new RuntimeException('Nieprawidłowe hasło SMTP');
    }

    return $password;
}
