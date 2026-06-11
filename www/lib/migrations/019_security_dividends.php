<?php
declare(strict_types=1);
/**
 * Migration 019 — security dividend cache (TODO #20).
 *
 * Creates `security_dividends`, the local cache of declared/historical cash
 * dividends per security (ex-date, per-share amount, payout frequency) filled by
 * lib/dividends.php → dividends_refresh_if_stale() from Polygon.io's free dividends
 * endpoint. One row per (security_id, ex_date); the natural composite PRIMARY KEY
 * makes the upsert idempotent (ON DUPLICATE KEY). Powers the Investments page's
 * "Dividend income & calendar" section (projected annual income + upcoming ex-dates).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/019_security_dividends.php
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). cron/sync.php → dividends_refresh_if_stale()
 * and the q_security_dividends() read (investments.php) touch security_dividends, so
 * deploying the code before the table exists would error those paths until this runs.
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
     WHERE table_schema = DATABASE() AND table_name = 'security_dividends'"
)->fetchColumn();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS security_dividends (
       security_id      VARCHAR(64)   NOT NULL,        -- joins holdings/securities.security_id
       ex_date          DATE          NOT NULL,        -- ex-dividend date (Polygon, stored as-is)
       cash_amount      DECIMAL(18,6) NOT NULL,        -- dividend per share
       frequency        SMALLINT      NULL,            -- payouts/yr: 1,2,4,12,24,52 (0/NULL=unknown)
       pay_date         DATE          NULL,
       record_date      DATE          NULL,
       declaration_date DATE          NULL,
       currency         VARCHAR(8)    NOT NULL DEFAULT 'USD',
       dividend_type    VARCHAR(16)   NULL,            -- e.g. 'CD' (consistent), 'SC'/'LT'/'ST'
       source           VARCHAR(16)   NOT NULL DEFAULT 'polygon',
       updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (security_id, ex_date)              -- natural key → idempotent upsert
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo $existed === 0
    ? "Migration 019 applied: security_dividends created.\n"
    : "Migration 019 skipped: security_dividends already present.\n";
