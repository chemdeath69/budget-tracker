<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

if (is_logged_in()) {
    header('Location: /');
    exit;
}
$error = $_GET['error'] ?? '';
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
        <h1>Budget Tracker</h1>
        <p class="muted">Private. Sign in with your Google account.</p>
        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>
        <a class="btn-google" href="/oauth-start.php">
            <span>Sign in with Google</span>
        </a>
    </main>
</body>
</html>
