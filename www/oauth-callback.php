<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

$error = google_handle_callback();
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
