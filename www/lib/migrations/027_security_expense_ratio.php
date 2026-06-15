<?php
declare(strict_types=1);
/**
 * Migration 027 — per-security expense ratio (TODO2 #39, investment fee analyzer).
 *
 * One household-shared table (NOT VIS-scoped — like security_asset_class / spending_plan):
 *
 *   security_expense_ratio — the owner-entered annual expense ratio for a fund/ETF, as a
 *                            PERCENT (e.g. 0.0945 = 0.0945%, a target-date fund ≈ 0.50%).
 *                            Keyed by security_id; absence = "not entered yet" (an unknown
 *                            fund) OR an auto-0 for a non-fund holding (a single stock/coin
 *                            has no expense ratio — resolved in lib/fees.php, not stored).
 *
 *   No reliably-free expense-ratio feed exists (verified Session 70: Twelve Data = paid,
 *   Polygon = none, FMP `/stable/etf/info` = HTTP 402 Restricted on the free tier, legacy
 *   `/api/v3/etf-info` deprecated Aug 31 2025), so ratios are entered manually — a small,
 *   one-time-per-fund task (~a handful of holdings) that survives Plaid syncs.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/027_security_expense_ratio.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS (no seed — starts empty). CLI-only. Run
 * migration-first — q_security_expense_ratios() is defensive (a missing table → []) but
 * fees.php writes to the table, so deploy the schema first. No FK to securities (mirrors
 * security_asset_class / security_dividends — securities churn).
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
    "CREATE TABLE IF NOT EXISTS security_expense_ratio (
       security_id   VARCHAR(64)  NOT NULL,
       expense_ratio DECIMAL(7,4) NOT NULL DEFAULT 0,   -- annual expense ratio, as a PERCENT (0.50 = 0.50%)
       updated_by    INT UNSIGNED NULL,
       updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (security_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 027 applied: security_expense_ratio ensured.\n";
