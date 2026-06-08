<?php
declare(strict_types=1);
/**
 * Migration 002 — security price history (time series for change-over-time +
 * the day/week/month/year change icons on the Investments page).
 *
 * `securities.close_price` is only a single snapshot (overwritten each sync), so
 * there's no history to chart or diff. This table keeps one close per security
 * per day, upserted from Twelve Data (see lib/prices.php). Idempotent.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/002_security_prices.php
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

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS security_prices (
        security_id  VARCHAR(64)  NOT NULL,
        price_date   DATE         NOT NULL,
        close        DECIMAL(18,4) NOT NULL,
        source       VARCHAR(16)  NOT NULL DEFAULT 'twelvedata',
        updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (security_id, price_date),
        KEY idx_sp_date (price_date),
        CONSTRAINT fk_sp_security FOREIGN KEY (security_id) REFERENCES securities(security_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 002 applied: security_prices (ensured).\n";
