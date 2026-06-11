<?php
declare(strict_types=1);
/**
 * Migration 018 — extend investment_transactions for the Plaid feed (TODO #18).
 *
 * investment_transactions was built (migration 003) for the manual Webull lot model:
 * buy/sell rows only, used to DERIVE average-cost basis. Plaid's
 * /investments/transactions/get returns a RICHER stream — dividends, interest, fees,
 * cash, transfers — none of which are a buy/sell. To store the Plaid feed in the SAME
 * table (owner choice) without disturbing the Webull path we:
 *
 *   - make `side` NULLABLE  — a Plaid dividend/interest/fee row has side=NULL. The
 *     Webull cost-basis derive only SELECTs side IN ('buy','sell'), so NULL-side rows
 *     are ignored there (Plaid holdings already carry cost_basis from
 *     /investments/holdings/get, so no derive is needed for Plaid anyway).
 *   - add `type`    VARCHAR(32)  — Plaid investment_transaction.type
 *                                  (buy|sell|cash|fee|transfer|cancel).
 *   - add `subtype` VARCHAR(48)  — Plaid subtype (dividend|interest|contribution|…);
 *                                  drives the income vs. trades split at read time.
 *   - add `name`    VARCHAR(255) — Plaid's human description (Webull rows leave NULL;
 *                                  the read falls back to the security name).
 *
 * Plaid rows carry ext_source='plaid' (the column already exists, DEFAULT 'webull');
 * inv_tx_id = Plaid's investment_transaction_id (stable, unique) → the UPSERT in
 * sync_investment_transactions() dedups on re-pull. No FK on security_id (unchanged —
 * a fully sold-out security may have no securities row).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/018_plaid_investment_transactions.php
 *
 * Idempotent: information_schema-guarded (MySQL 8 has no ADD COLUMN / MODIFY IF NOT
 * EXISTS). CLI-only. Run migration-first — q_investment_activity() and
 * sync_investment_transactions() reference these columns, so the code would 500/throw
 * if it deployed before the columns exist.
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

/** True if investment_transactions.<col> exists. */
$hasCol = function (string $col) use ($pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'investment_transactions'
           AND column_name = ?"
    );
    $st->execute([$col]);
    return (int)$st->fetchColumn() > 0;
};

// --- side → NULLABLE --------------------------------------------------------
$nullable = (string)$pdo->query(
    "SELECT IS_NULLABLE FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'investment_transactions'
       AND column_name = 'side'"
)->fetchColumn();
if (strtoupper($nullable) !== 'YES') {
    $pdo->exec("ALTER TABLE investment_transactions MODIFY COLUMN side ENUM('buy','sell') NULL");
    echo "Migration 018: investment_transactions.side made NULLABLE.\n";
} else {
    echo "Migration 018: investment_transactions.side already NULLABLE.\n";
}

// --- type / subtype / name columns ------------------------------------------
if (!$hasCol('type')) {
    $pdo->exec("ALTER TABLE investment_transactions ADD COLUMN type VARCHAR(32) NULL AFTER side");
    echo "Migration 018: investment_transactions.type added.\n";
} else {
    echo "Migration 018: investment_transactions.type already present.\n";
}
if (!$hasCol('subtype')) {
    $pdo->exec("ALTER TABLE investment_transactions ADD COLUMN subtype VARCHAR(48) NULL AFTER type");
    echo "Migration 018: investment_transactions.subtype added.\n";
} else {
    echo "Migration 018: investment_transactions.subtype already present.\n";
}
if (!$hasCol('name')) {
    $pdo->exec("ALTER TABLE investment_transactions ADD COLUMN name VARCHAR(255) NULL AFTER subtype");
    echo "Migration 018: investment_transactions.name added.\n";
} else {
    echo "Migration 018: investment_transactions.name already present.\n";
}

echo "Migration 018 applied: investment_transactions extended for the Plaid feed.\n";
