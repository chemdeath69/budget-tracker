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
 *
 * $pdo (optional) enables the plaid_webhook_keys cache (code review 3.2): without it the
 * key is fetched from Plaid on every call (an outbound cURL + up-to-30s timeout per hit,
 * with an attacker-supplied kid). Pass a PDO so a legit kid is fetched once per 24h.
 */
function verify_plaid_webhook(string $rawBody, ?PDO $pdo = null): array
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

    // Fetch (or read from the 24h cache) the public key for this kid.
    $jwk = plaid_webhook_key($pdo, (string)$header['kid']);
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

/**
 * Return the JWK (assoc array) for a Plaid webhook `kid`, using the plaid_webhook_keys
 * cache when a PDO is given (code review 3.2). A cached kid <24h old is returned without
 * hitting Plaid; otherwise we fetch from /webhook_verification_key/get and cache it.
 * The distinct-kid count is capped (KID_CACHE_MAX) so an attacker spraying random kids
 * can't grow the table unbounded (an unknown kid still fails the live fetch → not cached).
 * Returns null on any failure. Never throws.
 */
function plaid_webhook_key(?PDO $pdo, string $kid): ?array
{
    $KID_CACHE_MAX = 10;   // cap distinct cached kids (kid-spray growth guard)

    if ($pdo) {
        try {
            $st = $pdo->prepare(
                'SELECT key_json FROM plaid_webhook_keys
                 WHERE kid = ? AND fetched_at > (NOW() - INTERVAL 24 HOUR)'
            );
            $st->execute([$kid]);
            $cached = $st->fetchColumn();
            if ($cached !== false) {
                $k = json_decode((string)$cached, true);
                if (is_array($k)) return $k;
            }
        } catch (Throwable $e) {
            // Cache read failed → fall through to a live fetch (never fatal).
        }
    }

    try {
        $res = plaid_call('/webhook_verification_key/get', ['key_id' => $kid]);
    } catch (Throwable $e) {
        error_log('plaid webhook key fetch failed for kid ' . $kid . ': ' . $e->getMessage());
        return null;
    }
    $jwk = $res['key'] ?? null;
    if (!is_array($jwk)) return null;

    if ($pdo) {
        try {
            // Only write if this kid already exists (a refresh) or we're under the cap —
            // the kid-spray growth guard.
            $ex = $pdo->prepare('SELECT 1 FROM plaid_webhook_keys WHERE kid = ?');
            $ex->execute([$kid]);
            $known = (bool)$ex->fetchColumn();
            $count = (int)$pdo->query('SELECT COUNT(*) FROM plaid_webhook_keys')->fetchColumn();
            if ($known || $count < $KID_CACHE_MAX) {
                $pdo->prepare(
                    'INSERT INTO plaid_webhook_keys (kid, key_json, fetched_at) VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE key_json = VALUES(key_json), fetched_at = NOW()'
                )->execute([$kid, json_encode($jwk)]);
            }
        } catch (Throwable $e) {
            // Cache write is best-effort — verification still proceeds with the live key.
        }
    }
    return $jwk;
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
