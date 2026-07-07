<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

// CSRF-safe logout (code review 5.1). A plain GET link — including a forged <img>/<a> that
// points here — must not be able to sign you out silently. A real logout therefore requires
// a POST carrying the CSRF token; a GET just renders a one-button confirm form. (The nav /
// settings "Sign out" links are GET, so they land on this confirm screen.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check_request()) {
    logout();
    header('Location: /login.php');
    exit;
}
if (!is_logged_in()) {          // already signed out → nothing to confirm
    header('Location: /login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign out · Budget Tracker</title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
</head>
<body class="login-page">
    <main class="login-card">
        <p class="login-kicker">Budget Tracker</p>
        <h1>Sign out?</h1>
        <p class="muted">You’ll need to sign in with Google again to get back in.</p>
        <form method="post" action="/logout.php">
            <?= csrf_field() ?>
            <button class="btn" type="submit">Sign out</button>
        </form>
        <p class="login-foot"><a href="/">Stay signed in</a></p>
    </main>
</body>
</html>
