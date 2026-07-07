<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

// google_handle_callback() returns null on success or a short error CODE (login.php maps
// codes → canned copy, 5.2). Any uncaught DB/transport fault becomes a 'server' code rather
// than a blank 500 mid-OAuth (code review 5.6).
try {
    $error = google_handle_callback();
} catch (Throwable $e) {
    error_log('oauth-callback fatal: ' . $e->getMessage());
    $error = 'server';
}
if ($error !== null) {
    header('Location: /login.php?error=' . urlencode($error));
    exit;
}
// First-ever (bootstrap) login → the guided setup checklist; everyone else → dashboard.
if (!empty($_SESSION['needs_setup'])) {
    unset($_SESSION['needs_setup']);
    header('Location: /setup.php');
    exit;
}
header('Location: /');
exit;
