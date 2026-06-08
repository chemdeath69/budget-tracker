<?php
declare(strict_types=1);
/**
 * Migration 004 — home valuation (AVM) support.
 *
 * Two tables:
 *   api_usage   — per-provider, per-month request counter. The home-value feed
 *                 (lib/home_value.php) reserves a slot here BEFORE every HTTP call
 *                 and refuses once the monthly cap is hit, so we can NEVER exceed
 *                 RentCast's free-tier 50 req/month and incur overage charges.
 *   home_values — one row per valuation run (estimate + low/high range + comps blob),
 *                 keyed by address, so we keep a value-over-time history and the
 *                 dashboard can show home value vs. the linked mortgage balance.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/004_home_values.php
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

// Generic per-provider monthly request meter (the spend guard).
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS api_usage (
        provider      VARCHAR(32)     NOT NULL,
        period        CHAR(7)         NOT NULL,            -- 'YYYY-MM'
        request_count INT UNSIGNED    NOT NULL DEFAULT 0,
        updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (provider, period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Valuation history (one row per AVM run).
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS home_values (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id  VARCHAR(64)     NULL,                  -- linked mortgage account, if mapped
        address     VARCHAR(255)    NOT NULL,
        value       DECIMAL(18,2)   NOT NULL,
        value_low   DECIMAL(18,2)   NULL,
        value_high  DECIMAL(18,2)   NULL,
        as_of       DATE            NOT NULL,              -- date the estimate was run
        source      VARCHAR(32)     NOT NULL DEFAULT 'rentcast',
        raw_json    MEDIUMTEXT      NULL,
        fetched_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_hv_addr_date (address, as_of),
        KEY idx_hv_account (account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 004 applied: api_usage + home_values (ensured).\n";
