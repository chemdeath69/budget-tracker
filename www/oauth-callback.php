<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

$error = google_handle_callback();
if ($error !== null) {
    header('Location: /login.php?error=' . urlencode($error));
    exit;
}
header('Location: /');
exit;
