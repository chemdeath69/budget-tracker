<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

if (is_logged_in()) {
    header('Location: /');
    exit;
}
// Map a fixed whitelist of error CODES → canned copy. An unknown/garbage ?error= value
// falls back to a generic message, so the query string can never render arbitrary
// attacker-supplied text on the sign-in page (code review 5.2).
$ERROR_MESSAGES = [
    'state'       => 'Your sign-in session expired. Please try signing in again.',
    'token'       => 'Could not complete sign-in with Google. Please try again.',
    'verify'      => 'Your Google email is not verified.',
    'disabled'    => 'Your access to this site has been disabled. Contact an administrator.',
    'not_allowed' => 'This account is not authorised to use this site.',
    'server'      => 'Something went wrong during sign-in. Please try again.',
];
$errCode = (string)($_GET['error'] ?? '');
$error   = $errCode === '' ? '' : ($ERROR_MESSAGES[$errCode] ?? 'Sign-in failed. Please try again.');

// Fresh install (no users yet) → a "become the administrator" welcome. The first
// successful Google sign-in is auto-allowed + made admin (see lib/auth.php bootstrap).
$fresh = false;
try { $fresh = is_fresh_install(db()); } catch (Throwable $e) { /* pre-migration/transient */ }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Budget Tracker</title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
</head>
<body class="login-page">
    <main class="login-card">
        <p class="login-kicker"><?= $fresh ? 'First-time setup' : 'Private finance' ?></p>
        <h1>Budget Tracker</h1>
        <?php if ($fresh): ?>
            <p class="muted">Welcome. Sign in with Google to <strong>become the administrator</strong> —
                the first account to sign in sets up this install and can invite others afterward.</p>
        <?php else: ?>
            <p class="muted">Sign in with your Google account to continue.</p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>
        <a class="btn-google" href="/oauth-start.php">
            <svg viewBox="0 0 18 18" aria-hidden="true" focusable="false">
                <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
                <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
                <path fill="#FBBC05" d="M3.97 10.72a5.41 5.41 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
                <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
            </svg>
            <span>Continue with Google</span>
        </a>
        <p class="login-foot"><?= $fresh
            ? 'Only people you invite will be able to sign in.'
            : 'Your data stays private.' ?></p>
    </main>
</body>
</html>
