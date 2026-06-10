<?php
declare(strict_types=1);
/**
 * Migration 016 — category_rules (TODO #10, schema enabler #22).
 *
 * Persistent "always categorize merchant X as Y" rules, so a merchant only has to be
 * fixed once instead of per transaction by each of the 2 users.
 *
 *   - match_type 'merchant' : exact (case-insensitive) match on transactions.merchant_name
 *   - match_type 'contains' : substring match on the raw descriptor (name / merchant_name)
 *   - match_value           : stored UPPER-normalised (LIKE metachars % _ \ stripped)
 *   - category              : target PFC-style tag (UPPER, like category_override)
 *
 * Resolved entirely at READ time by the RULE_CAT scalar subquery folded into EFF_CAT
 * (lib/queries.php) — precedence split > per-tx category_override > RULE > Plaid
 * pfc_primary > UNCATEGORIZED. So a rule retroactively re-buckets every matching
 * transaction across all six spend aggregations, and deleting a rule reverts instantly
 * — NO transactions-table column, NO backfill pass, NO sync hook.
 *
 * Household-shared (one global vocabulary, like tags / alert_settings / budgets) — NOT
 * VIS-scoped. UNIQUE (match_type, match_value) so re-adding a merchant updates its target
 * (api/rules.php ON DUPLICATE KEY UPDATE).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/016_category_rules.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS. CLI-only. Run migration-first — the RULE_CAT
 * read references this table, so the code would 500 if it deployed before the table exists.
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
    "CREATE TABLE IF NOT EXISTS category_rules (
       id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
       match_type  ENUM('merchant','contains') NOT NULL DEFAULT 'merchant',
       match_value VARCHAR(255) NOT NULL,           -- stored UPPER-normalised (no LIKE metachars)
       category    VARCHAR(96)  NOT NULL,           -- target PFC tag, UPPER (like category_override)
       priority    INT NOT NULL DEFAULT 0,          -- higher wins; UI sets 0 for v1
       created_by  INT UNSIGNED NULL,
       created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       UNIQUE KEY uq_rule (match_type, match_value)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 016 applied: category_rules ensured.\n";
