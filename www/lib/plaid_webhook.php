<?php
declare(strict_types=1);

require_once __DIR__ . '/plaid.php';

/**
 * Verify a Plaid webhook per https://plaid.com/docs/api/webhooks/webhook-verification/
 *
 *  1. Read the JWT from the Plaid-Verification header (alg must be ES256).
 *  2. Fetch the EC public key for the JWT's `kid` via /webhook_verification_key/get.
 *  3. Verify the ES256 signature over "header.payload".
 *  4. Check the payload's request_body_sha256 matches SHA-256 of the raw body.
 *  5. Reject tokens older than 5 minutes (replay protection).
 *
 * Pure PHP + OpenSSL (no external JWT library).
 * Returns [bool ok, string reason].
 */
function verify_plaid_webhook(string $rawBody): array
{
    $jwt = $_SERVER['HTTP_PLAID_VERIFICATION'] ?? '';
    if ($jwt === '') return [false, 'missing Plaid-Verification header'];

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return [false, 'malformed JWT'];
    [$h64, $p64, $s64] = $parts;

    $header = json_decode(b64url_decode($h64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'ES256' || empty($header['kid'])) {
        return [false, 'bad JWT header'];
    }

    // Fetch the public key for this kid.
    try {
        $res = plaid_call('/webhook_verification_key/get', ['key_id' => $header['kid']]);
    } catch (Throwable $e) {
        return [false, 'could not fetch verification key: ' . $e->getMessage()];
    }
    $jwk = $res['key'] ?? null;
    if (!$jwk || ($jwk['crv'] ?? '') !== 'P-256' || empty($jwk['x']) || empty($jwk['y'])) {
        return [false, 'unexpected verification key'];
    }

    $pem = ec_p256_jwk_to_pem($jwk['x'], $jwk['y']);
    $der = ecdsa_raw_to_der(b64url_decode($s64));

    $ok = openssl_verify($h64 . '.' . $p64, $der, $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return [false, 'signature verification failed'];

    $payload = json_decode(b64url_decode($p64), true);
    if (!is_array($payload)) return [false, 'bad JWT payload'];

    // Freshness (replay protection): iat within 5 minutes.
    if (!isset($payload['iat']) || (time() - (int)$payload['iat']) > 300) {
        return [false, 'stale webhook token'];
    }

    // Body integrity.
    $expected = $payload['request_body_sha256'] ?? '';
    $actual   = hash('sha256', $rawBody);
    if (!is_string($expected) || !hash_equals($expected, $actual)) {
        return [false, 'body hash mismatch'];
    }

    return [true, 'ok'];
}

function b64url_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/'), true);
}

/** Build a PEM SubjectPublicKeyInfo for an EC P-256 public key from base64url x,y. */
function ec_p256_jwk_to_pem(string $x64, string $y64): string
{
    $x = b64url_decode($x64);
    $y = b64url_decode($y64);
    // Left-pad coordinates to 32 bytes.
    $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);
    // SPKI prefix for prime256v1 + uncompressed point marker 0x04.
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')
         . "\x04" . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/** Convert a raw 64-byte (R||S) ECDSA signature to DER for openssl_verify. */
function ecdsa_raw_to_der(string $raw): string
{
    if (strlen($raw) !== 64) return ''; // invalid; verify will fail
    $int = function (string $b): string {
        $b = ltrim($b, "\x00");
        if ($b === '') $b = "\x00";
        if (ord($b[0]) & 0x80) $b = "\x00" . $b; // keep positive
        return "\x02" . chr(strlen($b)) . $b;
    };
    $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
    return "\x30" . chr(strlen($seq)) . $seq;
}
