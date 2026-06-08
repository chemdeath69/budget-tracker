<?php
declare(strict_types=1);
/**
 * Migration 003 — investment_transactions (buy/sell lots with qty + price).
 *
 * The Webull parser extracts per-trade quantity and price, but they were
 * discarded at the `transactions` insert (which only keeps the net cash amount).
 * This table keeps them so we can DERIVE per-position cost basis (average-cost),
 * which fills holdings.cost_basis → unrealized gain/loss on the Investments page.
 * Idempotent.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/003_investment_transactions.php
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

// No FK to securities: a fully sold-out security may have no securities row, but
// we still want to keep its trade history. account_id FK is safe.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS investment_transactions (
        inv_tx_id   VARCHAR(64)   NOT NULL,
        account_id  VARCHAR(64)   NOT NULL,
        security_id VARCHAR(64)   NOT NULL,
        side        ENUM('buy','sell') NOT NULL,
        quantity    DECIMAL(20,6) NOT NULL,
        price       DECIMAL(18,4) NOT NULL,
        fees        DECIMAL(18,4) NOT NULL DEFAULT 0,
        amount      DECIMAL(18,4) NULL,            -- net cash effect (+ out / - in)
        trade_date  DATE          NOT NULL,
        ext_source  VARCHAR(16)   NOT NULL DEFAULT 'webull',
        ext_period  VARCHAR(16)   NULL,            -- doc bucket (YYYY-MM) for re-ingest
        updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (inv_tx_id),
        KEY idx_itx_acct_sec (account_id, security_id, trade_date),
        KEY idx_itx_bucket (account_id, ext_source, ext_period),
        CONSTRAINT fk_itx_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 003 applied: investment_transactions (ensured).\n";
