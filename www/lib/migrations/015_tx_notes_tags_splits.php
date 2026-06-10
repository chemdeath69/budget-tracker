<?php
declare(strict_types=1);
/**
 * Migration 015 — transaction notes, tags & splits (TODO #8, schema enabler #22).
 *
 * Adds the per-transaction annotation layer:
 *   - transactions.note VARCHAR(500) NULL  — free-text note. Added to NEITHER the
 *     INSERT column list NOR the ON DUPLICATE KEY UPDATE list in lib/sync.php, so
 *     (like category_override / large_tx_alerted) it is never written by a Plaid
 *     sync and survives re-sync.
 *   - tags(id, name UNIQUE)                — household-shared free-form tag vocabulary
 *     (e.g. 'reimbursable', 'vacation'); normalised lowercase by normalize_tag().
 *   - transaction_tags(transaction_id, tag_id)  — many-to-many junction.
 *   - transaction_splits(id, transaction_id, category, amount, note)  — divide one
 *     charge across categories. The split amounts MUST sum to the parent's amount
 *     (enforced at write time in api/account.php — the spend-aggregation LEFT JOIN
 *     in queries.php drops no remainder, so a partial split would lose money).
 *
 * All three child tables FK to transactions(transaction_id) ON DELETE CASCADE, so a
 * Plaid 'removed' transaction (DELETE in sync.php) cleans up its tags/splits. (When a
 * pending tx settles, Plaid issues a NEW transaction_id — note/tags/splits don't
 * migrate, same known limitation as category_override.)
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/015_tx_notes_tags_splits.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS for the tables, information_schema-guarded
 * ADD COLUMN for transactions.note (MySQL 8 has no ADD COLUMN IF NOT EXISTS). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). The split-explosion reads in
 * queries.php (LEFT JOIN transaction_splits) and the note column read would error if
 * the code deployed before the tables/column exist.
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

// --- transactions.note column (information_schema-guarded) -------------------
$hasNote = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'note'"
)->fetchColumn();
if ($hasNote === 0) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN note VARCHAR(500) NULL AFTER category_override");
    echo "Migration 015: transactions.note added.\n";
} else {
    echo "Migration 015: transactions.note already present.\n";
}

// --- tags (household-shared vocabulary) -------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS tags (
       id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
       name        VARCHAR(48)  NOT NULL,             -- normalised: lowercase, [a-z0-9-], no leading '#'
       created_by  INT UNSIGNED NULL,
       created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       UNIQUE KEY uq_tag_name (name)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// --- transaction_tags (many-to-many junction) ------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transaction_tags (
       transaction_id VARCHAR(64)  NOT NULL,
       tag_id         INT UNSIGNED NOT NULL,
       created_by     INT UNSIGNED NULL,
       created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (transaction_id, tag_id),
       KEY idx_txtag_tag (tag_id),
       CONSTRAINT fk_txtag_tx  FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
       CONSTRAINT fk_txtag_tag FOREIGN KEY (tag_id)         REFERENCES tags(id)                     ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// --- transaction_splits (one charge across categories) ---------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transaction_splits (
       id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
       transaction_id VARCHAR(64)   NOT NULL,
       category       VARCHAR(96)   NOT NULL,         -- PFC-style tag, like category_override
       amount         DECIMAL(15,2) NOT NULL,         -- positive portion; splits sum to the parent amount
       note           VARCHAR(255)  NULL,
       created_by     INT UNSIGNED  NULL,
       created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       KEY idx_split_tx (transaction_id),
       CONSTRAINT fk_split_tx FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 015 applied: tags / transaction_tags / transaction_splits ensured.\n";
