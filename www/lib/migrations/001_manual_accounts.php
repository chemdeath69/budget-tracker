<?php
declare(strict_types=1);
/**
 * Migration 001 — manual (non-Plaid) account support.
 *
 * Idempotent and re-runnable: every change is guarded by an information_schema
 * check (MySQL 8 has no `ADD COLUMN IF NOT EXISTS`). Run on the server with the
 * PHP 8.3 CLI (it reads the live config.php for DB creds):
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/001_manual_accounts.php
 *
 * CLI-only guard so it can't be hit over the web.
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
$dbName = $d['name'];

/** Does table.column already exist? */
function col_exists(PDO $pdo, string $db, string $table, string $col): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$db, $table, $col]);
    return (int)$st->fetchColumn() > 0;
}

/** Does table.index already exist? */
function idx_exists(PDO $pdo, string $db, string $table, string $index): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$db, $table, $index]);
    return (int)$st->fetchColumn() > 0;
}

/** Is table.column nullable? */
function col_nullable(PDO $pdo, string $db, string $table, string $col): bool
{
    $st = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$db, $table, $col]);
    return strtoupper((string)$st->fetchColumn()) === 'YES';
}

$did = [];

// --- items: source / manual_type + make access_token_enc nullable ----------
if (!col_exists($pdo, $dbName, 'items', 'source')) {
    $pdo->exec("ALTER TABLE items
        ADD COLUMN source ENUM('plaid','manual') NOT NULL DEFAULT 'plaid' AFTER user_id");
    $did[] = 'items.source';
}
if (!col_exists($pdo, $dbName, 'items', 'manual_type')) {
    $pdo->exec("ALTER TABLE items ADD COLUMN manual_type VARCHAR(32) NULL AFTER source");
    $did[] = 'items.manual_type';
}
if (!col_nullable($pdo, $dbName, 'items', 'access_token_enc')) {
    $pdo->exec("ALTER TABLE items MODIFY COLUMN access_token_enc VARBINARY(512) NULL");
    $did[] = 'items.access_token_enc->NULL';
}

// --- transactions: ext_source / ext_period + index -------------------------
if (!col_exists($pdo, $dbName, 'transactions', 'ext_source')) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN ext_source VARCHAR(16) NULL AFTER payment_channel");
    $did[] = 'transactions.ext_source';
}
if (!col_exists($pdo, $dbName, 'transactions', 'ext_period')) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN ext_period VARCHAR(16) NULL AFTER ext_source");
    $did[] = 'transactions.ext_period';
}
if (!idx_exists($pdo, $dbName, 'transactions', 'idx_tx_ext')) {
    $pdo->exec("ALTER TABLE transactions ADD KEY idx_tx_ext (account_id, ext_source, ext_period)");
    $did[] = 'transactions.idx_tx_ext';
}

// --- manual_documents ------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS manual_documents (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id     VARCHAR(64) NOT NULL,
        manual_type    VARCHAR(32) NOT NULL,
        doc_type       VARCHAR(32) NOT NULL,
        period_key     VARCHAR(16) NOT NULL,
        file_sha256    CHAR(64) NOT NULL,
        stored_path    VARCHAR(255) NULL,
        original_name  VARCHAR(255) NULL,
        byte_size      INT UNSIGNED NULL,
        summary        JSON NULL,
        uploaded_by    INT UNSIGNED NULL,
        uploaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_doc_bucket (account_id, doc_type, period_key),
        KEY idx_doc_hash (file_sha256),
        KEY idx_doc_account (account_id),
        CONSTRAINT fk_doc_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$did[] = 'manual_documents (ensured)';

// --- manual_tax_summaries --------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS manual_tax_summaries (
        id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id                  VARCHAR(64) NOT NULL,
        tax_year                    CHAR(4) NOT NULL,
        ordinary_dividends          DECIMAL(15,2) NULL,
        qualified_dividends         DECIMAL(15,2) NULL,
        capital_gain_distributions  DECIMAL(15,2) NULL,
        nondividend_distributions   DECIMAL(15,2) NULL,
        section_199a_dividends      DECIMAL(15,2) NULL,
        interest_income             DECIMAL(15,2) NULL,
        federal_tax_withheld        DECIMAL(15,2) NULL,
        foreign_tax_paid            DECIMAL(15,2) NULL,
        proceeds                    DECIMAL(15,2) NULL,
        cost_basis                  DECIMAL(15,2) NULL,
        net_gain_loss               DECIMAL(15,2) NULL,
        document_id                 INT UNSIGNED NULL,
        raw                         JSON NULL,
        updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tax_account_year (account_id, tax_year),
        CONSTRAINT fk_tax_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$did[] = 'manual_tax_summaries (ensured)';

echo "Migration 001 applied. Changes: " . (count($did) ? implode(', ', $did) : 'none') . "\n";
