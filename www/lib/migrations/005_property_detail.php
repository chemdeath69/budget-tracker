<?php
declare(strict_types=1);
/**
 * Migration 005 — property-detail page support.
 *
 * Three tables, all fed by lib/home_value.php (RentCast) and cron/sync.php:
 *   property_facts        — latest RentCast property record per address (purchase
 *                           price/date, features, HOA + raw JSON holding the tax /
 *                           assessment history). Refreshed ~quarterly.
 *   market_stats          — latest RentCast market data per zip (median price,
 *                           $/sqft, days-on-market + history blob). Refreshed ~monthly.
 *   account_balance_history — one balance per account per day, written by the cron,
 *                           so we can chart the mortgage balance (and any account)
 *                           over time. (balance_snapshots only stores household totals.)
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/005_property_detail.php
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). CLI-only guard.
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
    "CREATE TABLE IF NOT EXISTS property_facts (
        address        VARCHAR(255)  NOT NULL,
        property_type  VARCHAR(48)   NULL,
        bedrooms       DECIMAL(5,1)  NULL,
        bathrooms      DECIMAL(5,1)  NULL,
        square_footage INT UNSIGNED  NULL,
        lot_size       INT UNSIGNED  NULL,
        year_built     SMALLINT      NULL,
        hoa_fee        DECIMAL(12,2) NULL,
        purchase_price DECIMAL(18,2) NULL,
        purchase_date  DATE          NULL,
        raw_json       MEDIUMTEXT    NULL,
        fetched_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS market_stats (
        zip                   VARCHAR(12)   NOT NULL,
        median_sale_price     DECIMAL(18,2) NULL,
        median_price_per_sqft DECIMAL(12,2) NULL,
        median_days_on_market DECIMAL(8,1)  NULL,
        raw_json              MEDIUMTEXT     NULL,
        fetched_at            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (zip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS account_balance_history (
        account_id    VARCHAR(64)   NOT NULL,
        snapshot_date DATE          NOT NULL,
        balance       DECIMAL(18,2) NOT NULL,
        created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (account_id, snapshot_date),
        KEY idx_abh_date (snapshot_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 005 applied: property_facts + market_stats + account_balance_history (ensured).\n";
