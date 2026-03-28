<?php
if (!defined('ABSPATH')) exit;

define('GALLERY_SYNC_LICENSE_OPT', 'gallery_sync_license_key');
define('GALLERY_SYNC_LICENSE_CACHE_PREFIX', 'gallery_sync_license_status_');

function gallery_sync_license_crypto_key(): string {
    return hash('sha256', AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY, true);
}

function gallery_sync_license_encrypt_with_sodium(string $value): string {
    if (!function_exists('sodium_crypto_secretbox')) {
        return '';
    }

    $key = substr(gallery_sync_license_crypto_key(), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($value, $nonce, $key);
    return 'sod:' . base64_encode($nonce . $ciphertext);
}

function gallery_sync_license_decrypt_with_sodium(string $value): string {
    if (!function_exists('sodium_crypto_secretbox_open')) {
        return '';
    }

    $payload = str_starts_with($value, 'sod:') ? substr($value, 4) : $value;
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }

    $key = substr(gallery_sync_license_crypto_key(), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    return is_string($plaintext) ? $plaintext : '';
}

function gallery_sync_license_encrypt_with_openssl(string $value): string {
    if (!function_exists('openssl_encrypt')) {
        return '';
    }

    $cipher_algo = 'aes-256-gcm';
    $iv_length = openssl_cipher_iv_length($cipher_algo);
    $iv = random_bytes($iv_length);
    $tag = '';

    $salt = hash('sha256', SECURE_AUTH_KEY, true);
    $key = hash_pbkdf2('sha256', AUTH_KEY, $salt, 600000, 32, true);

    $ciphertext = openssl_encrypt(
        $value,
        $cipher_algo,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        return '';
    }

    return 'gcm:' . base64_encode($iv . $tag . $ciphertext);
}

function gallery_sync_license_decrypt_with_openssl(string $value): string {
    if (!function_exists('openssl_decrypt')) {
        return '';
    }

    $payload = str_starts_with($value, 'gcm:') ? substr($value, 4) : $value;
    $raw = base64_decode($payload, true);
    if ($raw === false) {
        return '';
    }

    $cipher_algo = 'aes-256-gcm';
    $iv_length = openssl_cipher_iv_length($cipher_algo);
    $tag_length = 16;

    if (strlen($raw) <= $iv_length + $tag_length) {
        return '';
    }

    $iv = substr($raw, 0, $iv_length);
    $tag = substr($raw, $iv_length, $tag_length);
    $ciphertext = substr($raw, $iv_length + $tag_length);

    $salt = hash('sha256', SECURE_AUTH_KEY, true);
    $key = hash_pbkdf2('sha256', AUTH_KEY, $salt, 600000, 32, true);

    $plaintext = openssl_decrypt(
        $ciphertext,
        $cipher_algo,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return is_string($plaintext) ? $plaintext : '';
}

function gallery_sync_license_encrypt(string $value): string {
    if ($value === '') {
        return '';
    }

    $sodium = gallery_sync_license_encrypt_with_sodium($value);
    if ($sodium !== '') {
        return $sodium;
    }

    return gallery_sync_license_encrypt_with_openssl($value);
}

function gallery_sync_license_decrypt(string $value): string {
    if ($value === '') {
        return '';
    }

    if (str_starts_with($value, 'sod:')) {
        return gallery_sync_license_decrypt_with_sodium($value);
    }

    if (str_starts_with($value, 'gcm:')) {
        return gallery_sync_license_decrypt_with_openssl($value);
    }

    // Backward compatibility for legacy values without prefixes.
    $decrypted = gallery_sync_license_decrypt_with_sodium($value);
    if ($decrypted !== '') {
        return $decrypted;
    }

    return gallery_sync_license_decrypt_with_openssl($value);
}

function gallery_sync_update_license_key(string $plain_key): void {
    $plain_key = trim($plain_key);
    $encrypted = gallery_sync_license_encrypt($plain_key);
    update_option(GALLERY_SYNC_LICENSE_OPT, $encrypted, false);
}

function gallery_sync_get_license_key_raw(): string {
    $key = get_option(GALLERY_SYNC_LICENSE_OPT, '');
    return is_string($key) ? trim($key) : '';
}

function gallery_sync_maybe_migrate_license_key(string $raw): string {
    if ($raw === '') {
        return '';
    }

    $decrypted = gallery_sync_license_decrypt($raw);
    if ($decrypted !== '') {
        if (!str_starts_with($raw, 'sod:') && function_exists('sodium_crypto_secretbox')) {
            gallery_sync_update_license_key($decrypted);
        }
        return $decrypted;
    }

    // If decrypt failed, treat raw as legacy plaintext and migrate.
    $legacy = trim($raw);
    if ($legacy !== '') {
        gallery_sync_update_license_key($legacy);
    }

    return $legacy;
}

function gallery_sync_get_license_key(): string {
    $raw = gallery_sync_get_license_key_raw();
    return gallery_sync_maybe_migrate_license_key($raw);
}

function gallery_sync_validate_license_key(string $key, bool $force = false): bool {
    $key = trim($key);
    if ($key === '') {
        return false;
    }

    $cache_key = GALLERY_SYNC_LICENSE_CACHE_PREFIX . md5($key);
    if (!$force) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (bool) $cached;
        }
    }

    if (!function_exists('gallery_sync_licensing_client')) {
        set_transient($cache_key, 0, MINUTE_IN_SECONDS * 10);
        return false;
    }

    $status = gallery_sync_licensing_client()->validate($force);
    $is_valid = !empty($status['valid']);

    $ttl = isset($status['ttl']) && is_numeric($status['ttl']) ? (int) $status['ttl'] : (int) HOUR_IN_SECONDS * 6;
    if ($ttl <= 0) {
        $ttl = (int) HOUR_IN_SECONDS * 6;
    }

    set_transient($cache_key, $is_valid ? 1 : 0, $ttl);
    return $is_valid;
}
