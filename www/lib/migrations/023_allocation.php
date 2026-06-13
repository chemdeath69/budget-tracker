<?php
declare(strict_types=1);
/**
 * Migration 023 — allocation targets + per-security asset-class override (TODO2 #32).
 *
 * Two household-shared tables (NOT VIS-scoped — like spending_plan / alert_settings):
 *
 *   allocation_targets    — the owner-set TARGET asset mix, one row per asset class
 *                           (stocks/bonds/cash/crypto/real_estate/other → target_pct).
 *                           Empty = no target set yet (the page shows an empty-state).
 *   security_asset_class  — a per-security OVERRIDE of the auto class (Plaid's security.type
 *                           lumps every ETF together, so the owner can tag a bond/REIT/crypto
 *                           ETF into the right bucket). Keyed by security_id; absence = auto.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/023_allocation.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS ×2 (no seed — both start empty). CLI-only. Run
 * migration-first — q_allocation_targets()/q_security_asset_classes() are defensive (a missing
 * table → []) but the writer endpoints reference the tables, so deploy the schema first.
 * No FK to securities (mirrors goals / security_dividends — securities churn).
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
    "CREATE TABLE IF NOT EXISTS allocation_targets (
       asset_class VARCHAR(32)   NOT NULL,
       target_pct  DECIMAL(6,3)  NOT NULL DEFAULT 0,
       updated_by  INT UNSIGNED  NULL,
       updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (asset_class)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS security_asset_class (
       security_id VARCHAR(64)  NOT NULL,
       asset_class VARCHAR(32)  NOT NULL,
       updated_by  INT UNSIGNED NULL,
       updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (security_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 023 applied: allocation_targets + security_asset_class ensured.\n";
