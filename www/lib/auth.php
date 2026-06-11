<?php
declare(strict_types=1);

/**
 * Google OAuth 2.0 (Authorization Code flow) + email allowlist.
 * No external libraries — just cURL against Google's endpoints.
 * On successful login the user is upserted into the `users` table and the
 * session carries the user id + email + name.
 */

function is_logged_in(): bool
{
    return !empty($_SESSION['user_email']);
}

function current_user_email(): ?string
{
    return $_SESSION['user_email'] ?? null;
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/** Redirect to login page if not authenticated. */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

/** Build the Google consent-screen URL and remember an anti-CSRF state + nonce. */
function google_auth_url(): string
{
    global $CONFIG;
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_nonce'] = $nonce;  // bound back into the id_token's `nonce` claim

    $params = [
        'client_id'     => $CONFIG['google']['client_id'],
        'redirect_uri'  => $CONFIG['google']['redirect_uri'],
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'nonce'         => $nonce,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Handle the OAuth callback: validate state, exchange the code for tokens,
 * read the verified email from the id_token, enforce the allowlist, and
 * upsert the user. On success sets the session; on failure returns an error.
 */
function google_handle_callback(): ?string
{
    global $CONFIG;

    $state = $_GET['state'] ?? '';
    $code  = $_GET['code'] ?? '';

    if (!$state || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        return 'Invalid session state. Please try signing in again.';
    }
    unset($_SESSION['oauth_state']);

    if (!$code) {
        return 'No authorization code returned by Google.';
    }

    $post = http_build_query([
        'code'          => $code,
        'client_id'     => $CONFIG['google']['client_id'],
        'client_secret' => $CONFIG['google']['client_secret'],
        'redirect_uri'  => $CONFIG['google']['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        error_log('OAuth token exchange failed: ' . $err);
        return 'Could not reach Google to complete sign-in.';
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['id_token'])) {
        error_log('OAuth token response missing id_token: ' . $resp);
        return 'Google did not return an identity token.';
    }

    $claims = decode_jwt_payload($data['id_token']);
    if ($claims === null) {
        return 'Could not read the identity token.';
    }

    // The id_token came directly from Google's token endpoint over TLS in THIS
    // exchange (authorization-code flow), so we don't re-verify its RS256
    // signature against Google's JWKS. But we still enforce the standard claims
    // — audience, issuer, expiry, and the per-request nonce — so a token minted
    // for a different client, an expired token, or a replayed login is rejected.
    $expectNonce = (string)($_SESSION['oauth_nonce'] ?? '');
    unset($_SESSION['oauth_nonce']);   // one-shot, consumed whether or not it matches

    if (!hash_equals((string)$CONFIG['google']['client_id'], (string)($claims['aud'] ?? ''))) {
        error_log('OAuth id_token aud mismatch: ' . (string)($claims['aud'] ?? ''));
        return 'The identity token was issued for a different application.';
    }
    $iss = (string)($claims['iss'] ?? '');
    if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') {
        error_log('OAuth id_token iss mismatch: ' . $iss);
        return 'The identity token came from an unexpected issuer.';
    }
    if ((int)($claims['exp'] ?? 0) <= time()) {
        return 'The identity token has expired. Please try signing in again.';
    }
    if ($expectNonce === '' || !hash_equals($expectNonce, (string)($claims['nonce'] ?? ''))) {
        return 'Invalid sign-in nonce. Please try signing in again.';
    }

    $email    = strtolower(trim((string)($claims['email'] ?? '')));
    $verified = (bool)($claims['email_verified'] ?? false);
    $name     = (string)($claims['name'] ?? $email);
    $sub      = (string)($claims['sub'] ?? '');
    $picture  = (string)($claims['picture'] ?? '');

    if (!$email || !$verified) {
        return 'Your Google email is not verified.';
    }

    $allow = array_map('strtolower', $CONFIG['allowed_emails']);
    if (!in_array($email, $allow, true)) {
        error_log('Rejected login attempt from: ' . $email);
        return 'This account is not authorised to use this site.';
    }

    // Upsert the user and load their id.
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, name, google_sub, last_login_at)
         VALUES (:email, :name, :sub, NOW())
         ON DUPLICATE KEY UPDATE name = VALUES(name),
                                 google_sub = VALUES(google_sub),
                                 last_login_at = NOW()'
    );
    $stmt->execute([':email' => $email, ':name' => $name, ':sub' => $sub]);

    $uid = (int)$pdo->query('SELECT id FROM users WHERE email = ' . $pdo->quote($email))->fetchColumn();

    // Success — establish the session.
    session_regenerate_id(true);
    $_SESSION['user_id']    = $uid;
    $_SESSION['user_email'] = $email;
    $_SESSION['name']       = $name;
    $_SESSION['picture']    = $picture; // Google profile photo URL (may be empty)
    return null;
}

/** Decode (without signature verification) the payload of a JWT. */
function decode_jwt_payload(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    if ($payload === false) {
        return null;
    }
    $claims = json_decode($payload, true);
    return is_array($claims) ? $claims : null;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
