<?php
declare(strict_types=1);
/**
 * Migration 022 — spending_plan (TODO2 #31, "Safe to spend").
 *
 * A single household-shared settings row (id=1) holding the owner-set MONTHLY SAVINGS TARGET that
 * the "Safe to spend" number subtracts (income − committed bills − savings target − spent = safe).
 * Single-row pattern like alert_settings / retirement_settings — NOT VIS-scoped, one household value.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/022_spending_plan.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + a row-count-guarded seed INSERT (id=1, target 0). CLI-only.
 * Run migration-first — q_spending_plan() references this table, so the code would 500 (or fall to
 * its defensive 0 default) if it deployed before the table exists.
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

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS spending_plan (
       id                     INT UNSIGNED  NOT NULL,
       monthly_savings_target DECIMAL(15,2) NOT NULL DEFAULT 0,
       updated_by             INT UNSIGNED  NULL,
       updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$n = (int)$pdo->query("SELECT COUNT(*) FROM spending_plan")->fetchColumn();
if ($n === 0) {
    $pdo->exec("INSERT INTO spending_plan (id, monthly_savings_target) VALUES (1, 0)");
    echo "Migration 022 applied: spending_plan created + seeded (id=1, target 0).\n";
} else {
    echo "Migration 022: spending_plan already present ({$n} row(s)) — no change.\n";
}
