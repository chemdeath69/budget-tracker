<?php
declare(strict_types=1);
/**
 * Migration 017 — goals (TODO #9, schema enabler #22).
 *
 * Household-shared savings goals — "Emergency fund: $15k of $20k". Each goal is either:
 *   - tied to an account  (account_id SET)   → progress = that account's live balance_current
 *   - manual              (account_id NULL)   → progress = current_amount (owner-entered/updated)
 *
 * Progress is derived entirely at READ time in q_goals() (lib/queries.php) — a tied goal LEFT
 * JOINs accounts for the live balance, a manual goal reads its stored current_amount. NO Plaid
 * impact, NO sync hook, NO backfill.
 *
 * Household-shared (one shared set, like budgets / category_rules / alert_settings) — NOT
 * VIS-scoped. created_by records who added it (informational only).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/017_goals.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS. CLI-only. Run migration-first — q_goals() references
 * this table, so the code would 500 if it deployed before the table exists.
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
    "CREATE TABLE IF NOT EXISTS goals (
       id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
       name           VARCHAR(96)   NOT NULL,
       target_amount  DECIMAL(15,2) NOT NULL,
       account_id     VARCHAR(64)   NULL,        -- tied account: progress = its balance; NULL = manual
       current_amount DECIMAL(15,2) NULL,        -- manual goals only (account_id IS NULL)
       created_by     INT UNSIGNED  NULL,
       created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       KEY idx_goals_account (account_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 017 applied: goals ensured.\n";
