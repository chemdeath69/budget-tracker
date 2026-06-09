<?php
declare(strict_types=1);
/**
 * Migration 009 — add a user-set account display-name override.
 *
 * Adds `accounts.display_name` (VARCHAR(255) NULL). When set, it is shown
 * everywhere the account name appears (the q_*() reads COALESCE over it); when
 * NULL/empty the original Plaid/manual `name` is used. Like `visibility` and
 * `retirement_flag`, this column is NOT in the Plaid sync UPSERT's UPDATE list
 * (lib/sync.php sync_balances), so a sync never clobbers the owner's rename —
 * the override is a separate column precisely so Plaid's name can keep refreshing
 * underneath it without erasing the chosen display name.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/009_account_display_name.php
 *
 * Idempotent (information_schema-guarded — MySQL 8 has no ADD COLUMN IF NOT EXISTS). CLI-only.
 *
 * ORDERING: harmless to run before OR after the code deploy — the column simply
 * doesn't exist until this runs, and the COALESCE reads tolerate its absence only
 * after it's added, so run this FIRST (the reads reference a.display_name).
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

$exists = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'accounts'
       AND column_name = 'display_name'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec("ALTER TABLE accounts ADD COLUMN display_name VARCHAR(255) NULL AFTER name");
    echo "Migration 009 applied: accounts.display_name added.\n";
} else {
    echo "Migration 009 skipped: accounts.display_name already present.\n";
}
