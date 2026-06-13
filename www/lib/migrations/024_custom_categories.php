<?php
declare(strict_types=1);
/**
 * Migration 024 — custom_categories (custom user-defined categories feature).
 *
 * Until now "categories" were the 16 hard-coded Plaid PFC tags (pfc_primary_categories()).
 * A user could already STORE an arbitrary string in transactions.category_override /
 * category_rules.category / transaction_splits.category / budgets.category, and the
 * EFF_CAT chain resolved it — but there was no way to CREATE / name / manage a category,
 * and a custom one only appeared in a picker once a transaction already used it.
 *
 * This table makes a custom category a first-class, household-shared entity:
 *   - tag                   : the canonical code written into the four free-text slots
 *                             above (UPPER, [A-Z0-9_] only — normalize_category_tag()),
 *                             so EFF_CAT resolves it with NO change to the resolution chain.
 *   - label                 : the display text the user typed (preserves fidelity that
 *                             pretty_cat()'s underscore-titlecase would lose, e.g. "AT&T").
 *   - exclude_from_spending : when 1, the category is dropped from the true-expense
 *                             aggregations exactly like TRANSFER_IN/OUT (a "Reimbursable" /
 *                             "Internal" bucket) — applied via expense_exclude_clause()
 *                             in lib/queries.php.
 *
 * Household-shared (one global vocabulary, like category_rules / tags / budgets) — NOT
 * VIS-scoped. UNIQUE (tag) so a tag is a stable identity; renaming regenerates the tag and
 * rewrites the four reference slots (api/categories.php).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/024_custom_categories.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS. CLI-only. Run migration-first — q_custom_categories()
 * / custom_category_map() read this table, so the code would error if it deployed first.
 * (The reads are defensive — try/catch → [] — so a code-first deploy degrades gracefully
 * rather than 500ing, but migration-first is still the rule.)
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
    "CREATE TABLE IF NOT EXISTS custom_categories (
       id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
       tag                   VARCHAR(96)  NOT NULL,           -- canonical code, UPPER [A-Z0-9_]
       label                 VARCHAR(96)  NOT NULL,           -- display text the user typed
       exclude_from_spending TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = treat like a transfer (drop from spend math)
       created_by            INT UNSIGNED NULL,
       created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       UNIQUE KEY uq_tag (tag)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 024 applied: custom_categories ensured.\n";
