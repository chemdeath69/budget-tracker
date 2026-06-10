<?php
declare(strict_types=1);
/**
 * Migration 014 — FRED economic-data cache (TODO #17).
 *
 * Creates `fred_series`, the local cache of Federal Reserve Economic Data series
 * (CPI, 30-yr mortgage rate, Treasury/Fed-funds yields) filled nightly by
 * lib/fred.php → fred_refresh_latest(). One observation per (series_id, obs_date);
 * the natural composite PRIMARY KEY makes the upsert idempotent (ON DUPLICATE KEY).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/014_fred_series.php
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). cron/sync.php → fred_refresh_latest()
 * and the economic.php reads touch fred_series, so deploying the code before the
 * table exists would error those (cron-only / page) paths until this runs.
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

$existed = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'fred_series'"
)->fetchColumn();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS fred_series (
       series_id   VARCHAR(32)   NOT NULL,          -- e.g. 'CPIAUCSL', 'MORTGAGE30US'
       obs_date    DATE          NOT NULL,          -- observation date (FRED, stored as-is)
       value       DECIMAL(14,4) NOT NULL,          -- index level or percent, per series
       fetched_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (series_id, obs_date)            -- natural key → idempotent upsert
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo $existed === 0
    ? "Migration 014 applied: fred_series created.\n"
    : "Migration 014 skipped: fred_series already present.\n";
