<?php
declare(strict_types=1);
/**
 * Migration 013 — alert dedup log (TODO #16).
 *
 * Creates `alert_log`, a one-row-per-fired-alert ledger used by the spend-alert
 * consumer (lib/spend_alerts.php) to fire each budget / unusual-spend / bill
 * reminder AT MOST ONCE per logical occurrence — without it the daily cron would
 * re-email the same crossing every night.
 *
 * Dedup is the UNIQUE (alert_type, alert_key, period) key, claimed atomically with
 * INSERT IGNORE (a fresh insert = "this alert is new → email it"):
 *   - budget  → ('budget',  <category>,   'YYYY-MM')     once per category per month
 *   - unusual → ('unusual', <category>,   'YYYY-MM')     once per category per month
 *   - bill    → ('bill',    <account_id>, 'YYYY-MM-DD')  once per due-date (re-arms next cycle)
 * `period` is a string so it can hold either a month ('YYYY-MM') or a date
 * ('YYYY-MM-DD'); it is always a PHP app-TZ value, never MySQL CURDATE() (S24 TZ trap).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/013_alert_log.php
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). cron/sync.php → maybe_send_spend_alerts()
 * reads + writes alert_log, so deploying the code before the table exists would error
 * the (cron-only) spend-alert path until this runs.
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
     WHERE table_schema = DATABASE() AND table_name = 'alert_log'"
)->fetchColumn();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS alert_log (
       id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       alert_type  VARCHAR(24)  NOT NULL,          -- 'budget' | 'unusual' | 'bill'
       alert_key   VARCHAR(96)  NOT NULL,          -- category tag, or account_id for bills
       period      VARCHAR(10)  NOT NULL,          -- 'YYYY-MM' or 'YYYY-MM-DD' (PHP app-TZ)
       sent_on     DATE         NOT NULL,          -- the app-TZ date the alert was emailed
       created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       UNIQUE KEY uq_alert (alert_type, alert_key, period)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo $existed === 0
    ? "Migration 013 applied: alert_log created.\n"
    : "Migration 013 skipped: alert_log already present.\n";
