<?php
declare(strict_types=1);
/**
 * Migration 006 — Retirement (manual 401(k)) tracking.
 *
 * Two tables, fed by the Retirement page (no Plaid, no PDF parser — the household
 * types each quarterly statement in by hand):
 *   retirement_statements — one row per 401(k) account per quarter. Holds the total
 *                           balance plus the period's employee/employer contributions
 *                           (and optional YTD). (account_id, period_key) is the dedup
 *                           bucket: re-entering a quarter UPDATES it (a correction),
 *                           mirroring manual_documents. Source of truth for the page's
 *                           value-over-time + contributions charts.
 *   retirement_settings   — a single global row (id=1) holding the projection
 *                           assumptions: target retirement year, expected ongoing
 *                           annual contribution, a growth-rate override (NULL = derive
 *                           from history), a default growth rate (used until there is
 *                           enough history to derive one), and an optional target amount.
 *
 * A 401(k) itself is a manual account (items.source='manual',
 * manual_type='retirement_401k'; accounts.type='investment', subtype='401k'); because
 * it is an investment account it already counts as an asset in q_stats() /
 * write_networth_snapshot(), so net worth folds it in once balance_current is set.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/006_retirement.php
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS + INSERT IGNORE for the settings row). CLI-only.
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
    "CREATE TABLE IF NOT EXISTS retirement_statements (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id        VARCHAR(64)   NOT NULL,
        period_key        VARCHAR(8)    NOT NULL,           -- 'YYYY-Qn'
        statement_date    DATE          NOT NULL,
        balance           DECIMAL(18,2) NOT NULL,
        employee_contrib  DECIMAL(15,2) NULL,               -- this period's contribution
        employer_contrib  DECIMAL(15,2) NULL,               -- this period's employer match
        employee_ytd      DECIMAL(15,2) NULL,
        employer_ytd      DECIMAL(15,2) NULL,
        note              VARCHAR(255)  NULL,
        created_by        INT UNSIGNED  NULL,
        created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ret_bucket (account_id, period_key),
        KEY idx_ret_acct_date (account_id, statement_date),
        CONSTRAINT fk_ret_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS retirement_settings (
        id                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
        retirement_year     SMALLINT      NULL,
        annual_contribution DECIMAL(15,2) NULL,             -- expected ongoing; NULL = derive
        growth_rate_override DECIMAL(6,4)  NULL,            -- e.g. 0.0700; NULL = derive from history
        growth_default      DECIMAL(6,4)  NOT NULL DEFAULT 0.0600,
        target_amount       DECIMAL(18,2) NULL,
        updated_by          INT UNSIGNED  NULL,
        updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Seed the single settings row (id=1) if absent. Harmless on re-run.
$pdo->exec("INSERT IGNORE INTO retirement_settings (id, growth_default) VALUES (1, 0.0600)");

echo "Migration 006 applied: retirement_statements + retirement_settings (ensured).\n";
