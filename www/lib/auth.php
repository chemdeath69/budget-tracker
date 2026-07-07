<?php
declare(strict_types=1);

/**
 * Google OAuth 2.0 (Authorization Code flow) + email allowlist.
 * No external libraries — just cURL against Google's endpoints.
 * On successful login the user is upserted into the `users` table and the
 * session carries the user id + email + name.
 */

require_once __DIR__ . '/activity.php';   // best-effort access-log writers (login/logout)

function is_logged_in(): bool
{
    if (empty($_SESSION['user_email'])) {
        return false;
    }
    // Re-validate the live session against the DB on every request (once per
    // request — see enforce_account_status()): a mid-session disable/demote
    // takes effect immediately instead of surviving until the user logs out.
    enforce_account_status();
    return !empty($_SESSION['user_email']);
}

/**
 * Re-check the signed-in account's status + role against the `users` table and
 * enforce the result on the live session. Runs at most once per request (a
 * static guard collapses the many is_logged_in() calls a page/endpoint makes
 * into a single indexed lookup).
 *
 *   - status='disabled' (or the row is gone) → destroy the session now, so a
 *     revoked user is bounced on their very next click, not at next login.
 *   - otherwise → refresh $_SESSION['role'] from the stored row, so a demote/
 *     promote takes effect immediately (an ex-admin loses users.php /
 *     factory_reset the instant they act, not at next login).
 *
 * BREAK-GLASS (config['allowed_emails']) accounts are un-lockoutable — always
 * admin, never disabled — decided WITHOUT a DB read, exactly as
 * access_decision() does. A transient DB error is swallowed (keep the cached
 * role for this request; never lock out a real user on a hiccup — cf. 5.4).
 */
function enforce_account_status(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (empty($_SESSION['user_email'])) {
        return;
    }
    $email = strtolower((string)$_SESSION['user_email']);

    global $CONFIG;
    $allow = array_map('strtolower', array_map('trim', (array)($CONFIG['allowed_emails'] ?? [])));
    if (in_array($email, $allow, true)) {
        $_SESSION['role'] = 'admin';   // break-glass — always admin, never revoked
        return;
    }

    try {
        $st = db()->prepare('SELECT role, status FROM users WHERE email = :e');
        $st->execute([':e' => $email]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        // Pre-migration DB / transient error — do NOT revoke or downgrade on a
        // hiccup; keep the current session as-is for this request.
        return;
    }

    // No row (deleted from the allowlist) or explicitly disabled → revoke now.
    if (!$row || ($row['status'] ?? 'active') === 'disabled') {
        logout();   // clears $_SESSION + destroys the session cookie
        return;
    }

    $_SESSION['role'] = (($row['role'] ?? 'member') === 'admin') ? 'admin' : 'member';
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

/**
 * The signed-in user's role ('admin' | 'member'), or null if not logged in.
 * Set at login from access_decision(). Defensively backfilled for a session that
 * predates roles (logged in before migration 032) — config break-glass emails are
 * always admin, else the stored users.role, else 'member'.
 */
function current_user_role(): ?string
{
    if (!is_logged_in()) {
        return null;
    }
    if (isset($_SESSION['role'])) {
        return $_SESSION['role'];
    }
    $email = strtolower((string)current_user_email());
    try {
        global $CONFIG;
        $allow = array_map('strtolower', array_map('trim', (array)($CONFIG['allowed_emails'] ?? [])));
        if (in_array($email, $allow, true)) {
            $_SESSION['role'] = 'admin';           // break-glass — always admin; safe to cache
            return 'admin';
        }
        $st = db()->prepare('SELECT role FROM users WHERE email = :e');
        $st->execute([':e' => $email]);
        $stored = $st->fetchColumn();
        $role = ($stored === 'admin') ? 'admin' : 'member';
        $_SESSION['role'] = $role;                 // cache only a value we actually read
        return $role;
    } catch (Throwable $e) {
        // Pre-migration DB / transient error — return the least privilege for THIS request
        // only, and DO NOT cache it: caching 'member' on a hiccup would permanently pin a
        // real admin to member for the rest of their session (code review 5.4).
        return 'member';
    }
}

function is_admin(): bool
{
    return current_user_role() === 'admin';
}

/** Page guard: must be a logged-in admin, else bounce to Settings with a flash. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash_set('error', 'That page is for administrators only.');
        header('Location: /settings.php');
        exit;
    }
}

/**
 * The config['allowed_emails'] break-glass set (lowercased). These accounts are
 * always allowed + always admin and are PROTECTED from demote/disable/delete in the
 * Users UI (a UI change wouldn't stick — they re-promote on next login).
 */
function config_break_glass_emails(): array
{
    global $CONFIG;
    return array_values(array_unique(array_map(
        'strtolower',
        array_map('trim', (array)($CONFIG['allowed_emails'] ?? []))
    )));
}

/** True on a brand-new install — the allowlist (users table) is empty. */
function is_fresh_install(PDO $pdo): bool
{
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    } catch (Throwable $e) {
        return false;   // pre-migration / transient — never claim "fresh"
    }
}

/**
 * Decide whether $email may sign in and at what role. Two safety layers sit on top
 * of the DB allowlist (users.status='active'):
 *   - BREAK-GLASS: config['allowed_emails'] are always allowed + always admin (so an
 *     admin can never be locked out via the UI). force_admin makes the stored row match.
 *   - BOOTSTRAP: if the users table is empty, the first login is allowed + admin.
 * Returns ['allow'=>bool, 'role'=>?string, 'force_admin'=>bool, 'reason'=>?string].
 */
function access_decision(PDO $pdo, string $email): array
{
    global $CONFIG;
    $email = strtolower(trim($email));
    $allow = array_map('strtolower', array_map('trim', (array)($CONFIG['allowed_emails'] ?? [])));

    if (in_array($email, $allow, true)) {
        return ['allow' => true, 'role' => 'admin', 'force_admin' => true, 'reason' => 'break_glass'];
    }

    $st = $pdo->prepare('SELECT role, status FROM users WHERE email = :e');
    $st->execute([':e' => $email]);
    $row = $st->fetch();

    if ($row) {
        if (($row['status'] ?? 'active') === 'disabled') {
            return ['allow' => false, 'role' => null, 'force_admin' => false, 'reason' => 'disabled'];
        }
        $role = ($row['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        return ['allow' => true, 'role' => $role, 'force_admin' => false, 'reason' => null];
    }

    if (is_fresh_install($pdo)) {
        return ['allow' => true, 'role' => 'admin', 'force_admin' => true, 'reason' => 'bootstrap'];
    }

    return ['allow' => false, 'role' => null, 'force_admin' => false, 'reason' => 'not_allowed'];
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
 * upsert the user. On success sets the session; on failure returns a short
 * ERROR CODE (not a human message) — login.php maps a fixed whitelist of codes
 * to canned copy so ?error= can't be used to inject arbitrary phishing text
 * (code review 5.2). Codes: state | token | verify | disabled | not_allowed | server.
 */
function google_handle_callback(): ?string
{
    global $CONFIG;

    $state = $_GET['state'] ?? '';
    $code  = $_GET['code'] ?? '';

    if (!$state || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        return 'state';
    }
    unset($_SESSION['oauth_state']);

    if (!$code) {
        return 'state';
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
        return 'token';
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['id_token'])) {
        // Log only the KEYS present, never the values — the token response can carry a live
        // access_token we must not write to the error log (code review 5.7).
        error_log('OAuth token response missing id_token (keys: '
            . implode(',', array_keys(is_array($data) ? $data : [])) . ')');
        return 'token';
    }

    $claims = decode_jwt_payload($data['id_token']);
    if ($claims === null) {
        return 'token';
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
        return 'token';
    }
    $iss = (string)($claims['iss'] ?? '');
    if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') {
        error_log('OAuth id_token iss mismatch: ' . $iss);
        return 'token';
    }
    if ((int)($claims['exp'] ?? 0) <= time()) {
        return 'state';
    }
    if ($expectNonce === '' || !hash_equals($expectNonce, (string)($claims['nonce'] ?? ''))) {
        return 'state';
    }

    $email    = strtolower(trim((string)($claims['email'] ?? '')));
    $verified = (bool)($claims['email_verified'] ?? false);
    $name     = (string)($claims['name'] ?? $email);
    $sub      = (string)($claims['sub'] ?? '');
    $picture  = (string)($claims['picture'] ?? '');

    if (!$email || !$verified) {
        return 'verify';
    }

    // DB-backed allowlist + bootstrap (first login → admin) + config break-glass. Serialize
    // the decide-then-insert behind an advisory lock so two concurrent first logins on a fresh
    // install can't BOTH pass the empty-table bootstrap check and both become admin (TOCTOU,
    // code review 5.3). Logins are infrequent, so a brief global lock is cheap.
    $pdo = db();
    $lockKey = 'bt_login_decide';
    $pdo->prepare('SELECT GET_LOCK(:k, 3)')->execute([':k' => $lockKey]);
    try {
        $decision = access_decision($pdo, $email);
        if (!$decision['allow']) {
            error_log('Rejected login attempt from: ' . $email . ' (' . ($decision['reason'] ?? '') . ')');
            access_log_record($pdo, null, 'login', 'rejected', $email);   // audit (best-effort)
            return ($decision['reason'] ?? '') === 'disabled' ? 'disabled' : 'not_allowed';
        }
        $role      = $decision['role'] ?? 'member';
        $bootstrap = ($decision['reason'] ?? '') === 'bootstrap';

        // Upsert the user and load their id. On CREATE the role/status come from the
        // decision; an existing row KEEPS its stored role/status (login never downgrades).
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, name, role, status, google_sub, last_login_at)
             VALUES (:email, :name, :role, \'active\', :sub, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name),
                                     google_sub = VALUES(google_sub),
                                     last_login_at = NOW()'
        );
        $stmt->execute([':email' => $email, ':name' => $name, ':role' => $role, ':sub' => $sub]);

        // Break-glass / bootstrap: ensure the stored row is admin + active (so the Users
        // page reflects it and a config-listed admin can never be locked out).
        if (!empty($decision['force_admin'])) {
            $pdo->prepare("UPDATE users SET role = 'admin', status = 'active' WHERE email = :e")
                ->execute([':e' => $email]);
            $role = 'admin';
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(:k)')->execute([':k' => $lockKey]);
    }

    $uid = (int)$pdo->query('SELECT id FROM users WHERE email = ' . $pdo->quote($email))->fetchColumn();

    // Success — establish the session.
    session_regenerate_id(true);
    $_SESSION['user_id']    = $uid;
    $_SESSION['user_email'] = $email;
    $_SESSION['name']       = $name;
    $_SESSION['role']       = $role;
    $_SESSION['picture']    = $picture; // Google profile photo URL (may be empty)
    if ($bootstrap) {
        $_SESSION['needs_setup'] = true;  // first-run → oauth-callback redirects to /setup.php
    }

    access_log_record($pdo, $uid, 'login', null, $email);   // audit (best-effort)
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
    $uid = current_user_id();
    if ($uid !== null) access_log_record(db(), $uid, 'logout', null, current_user_email());
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
