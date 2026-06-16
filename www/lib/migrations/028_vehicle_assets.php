<?php
declare(strict_types=1);
/**
 * Migration 028 — vehicle assets (TODO2 #40, vehicle / other-asset valuation).
 *
 * A vehicle is a MANUAL account (items.source='manual', manual_type='vehicle';
 * accounts.type='vehicle') so it counts in net worth automatically once
 * accounts.balance_current is set — exactly like a manual 401(k). This side table
 * holds the depreciation BASIS + decoded identity for each such account; the cron
 * (and the save path) recompute balance_current from it via lib/vehicles.php.
 *
 *   vehicle_assets — one row per vehicle account (keyed by account_id):
 *     · VIN + decoded year/make/model/trim/body (from the free NHTSA vPIC decode)
 *     · purchase_price + purchase_date + a depreciation model the OWNER sets
 *       (declining-balance default, or straight-line; annual_rate as a PERCENT/yr)
 *     · manual_value — an optional point-in-time OVERRIDE (e.g. a real KBB quote the
 *       owner looked up); when set it WINS and stops the modelled depreciation.
 *
 *   No free valuation feed exists (KBB / Black Book are paid), so the current value
 *   is a transparent owner-set depreciation model, not a market quote — see
 *   lib/vehicles.php. NHTSA vPIC (VIN → identity) is free, no key.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/028_vehicle_assets.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS (no seed — starts empty). CLI-only. Run
 * migration-first — q_vehicle_asset() is defensive (a missing table → null) but
 * vehicle_add.php / lib/vehicles.php write to the table, so deploy the schema first.
 * No FK to accounts (mirrors security_asset_class / security_expense_ratio — and a
 * vehicle account has no UI delete path, so orphan risk is nil).
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
    "CREATE TABLE IF NOT EXISTS vehicle_assets (
       account_id          VARCHAR(64)  NOT NULL,
       vin                 VARCHAR(32)  NULL,
       year                SMALLINT     NULL,
       make                VARCHAR(64)  NULL,
       model               VARCHAR(96)  NULL,
       trim                VARCHAR(96)  NULL,
       body_class          VARCHAR(64)  NULL,
       purchase_price      DECIMAL(15,2) NULL,
       purchase_date       DATE         NULL,
       depreciation_method ENUM('declining','straight') NOT NULL DEFAULT 'declining',
       annual_rate         DECIMAL(6,3) NOT NULL DEFAULT 15.000,   -- depreciation %/yr
       manual_value        DECIMAL(15,2) NULL,   -- override; wins + stops depreciation when set
       manual_value_date   DATE         NULL,
       updated_by          INT UNSIGNED NULL,
       created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (account_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 028 applied: vehicle_assets ensured.\n";
