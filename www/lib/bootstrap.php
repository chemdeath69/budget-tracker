<?php
declare(strict_types=1);

/**
 * Loads config, sets timezone, configures a secure session, exposes $CONFIG.
 * Every entry-point script includes this first.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak errors to the browser
ini_set('log_errors', '1');

date_default_timezone_set('America/Los_Angeles'); // US Pacific (per requirements)

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing lib/config.php — copy config.sample.php and fill it in.');
}

$CONFIG = require $configPath;

// --- Secure session ---------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('budget_sess');
session_start();

/** Small helper: HTML-escape. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Format a USD amount for display. */
function usd($n): string
{
    return '$' . number_format((float)$n, 2);
}
