<?php
declare(strict_types=1);
/**
 * Migration 007 — retirement classification override.
 *
 * Adds `accounts.retirement_flag` (NULL = auto-classify by manual_type/subtype;
 * 1 = force "this is a retirement account"; 0 = force "not retirement"). Lets the
 * Retirement page pick up Plaid-linked retirement accounts (IRA/Roth/401k subtypes)
 * automatically AND lets the owner override when Plaid mislabels one (e.g. a
 * brokerage-labelled rollover). See is_retirement_account() in queries.php.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/007_retirement_flag.php
 *
 * Idempotent (information_schema-guarded — MySQL 8 has no ADD COLUMN IF NOT EXISTS). CLI-only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$CONFIG = require __DIR__ . '/../config.php';
$d = $CONFIG['db'];
$dsn = !empty($d['socket'])
    ? "mysql:unix_socket={$d['socket']};dbname={$d['name']};charset=utf8mb4"
    : "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4";
$pdo = new PDO($dsn, $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$exists = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'accounts'
       AND column_name = 'retirement_flag'"
)->fetchColumn();

if ((int)$exists === 0) {
    $pdo->exec("ALTER TABLE accounts ADD COLUMN retirement_flag TINYINT NULL AFTER subtype");
    echo "Migration 007 applied: accounts.retirement_flag added.\n";
} else {
    echo "Migration 007 skipped: accounts.retirement_flag already present.\n";
}
