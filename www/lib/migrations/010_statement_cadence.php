<?php
declare(strict_types=1);
/**
 * Migration 010 — add a per-account expected-statement cadence.
 *
 * Adds `accounts.statement_cadence` ENUM('monthly','quarterly','annually','off') NULL.
 * Drives the dashboard "statements overdue" warning for MANUAL accounts (Webull-style
 * uploaded statements + hand-entered 401(k)s): when no new statement has landed within
 * the cadence + a grace buffer, the account is flagged as needing an update.
 *
 * NULL = "auto" — resolve the effective cadence by type (manual 401(k) → quarterly,
 * other manual/uploaded → monthly); see statement_cadence_effective() in queries.php.
 * An explicit value (including 'off') overrides the auto default. Like
 * `retirement_flag`/`display_name`, this is an owner-set override column the Plaid sync
 * never touches (manual accounts don't sync anyway, but keep the pattern).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/010_statement_cadence.php
 *
 * Idempotent (information_schema-guarded — MySQL 8 has no ADD COLUMN IF NOT EXISTS). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). The new code reads a.statement_cadence
 * (q_account + q_manual_statement_status), so deploying the code before the column
 * exists would 500 those reads until this runs.
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
       AND column_name = 'statement_cadence'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec(
        "ALTER TABLE accounts
         ADD COLUMN statement_cadence ENUM('monthly','quarterly','annually','off') NULL
         AFTER retirement_flag"
    );
    echo "Migration 010 applied: accounts.statement_cadence added.\n";
} else {
    echo "Migration 010 skipped: accounts.statement_cadence already present.\n";
}
