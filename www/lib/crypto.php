<?php
declare(strict_types=1);

/**
 * Authenticated encryption for secrets at rest (Plaid access_tokens).
 * libsodium secretbox (XSalsa20-Poly1305). The 32-byte key lives base64-encoded
 * in config.php as encryption_key. Ciphertext format: nonce || box, base64-encoded.
 *
 * Losing the key means every Item must be re-linked.
 */

function enc_key(): string
{
    global $CONFIG;
    $key = base64_decode($CONFIG['encryption_key'] ?? '', true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        error_log('Invalid encryption_key: must be base64 of 32 bytes.');
        http_response_code(500);
        exit('Server crypto misconfigured.');
    }
    return $key;
}

/** Encrypt a plaintext string -> base64(nonce||ciphertext). */
function encrypt_secret(string $plain): string
{
    $key   = enc_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box   = sodium_crypto_secretbox($plain, $nonce, $key);
    $out   = base64_encode($nonce . $box);
    sodium_memzero($plain);
    return $out;
}

/** Decrypt base64(nonce||ciphertext) -> plaintext, or null if tampered/invalid. */
function decrypt_secret(string $b64): ?string
{
    $key = enc_key();
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        return null;
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
    return $plain === false ? null : $plain;
}
