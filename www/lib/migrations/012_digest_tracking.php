<?php
declare(strict_types=1);
/**
 * Migration 012 — weekly-digest sent marker (TODO #15).
 *
 * Adds `alert_settings.digest_sent_on DATE NULL` — the "last digest emailed on"
 * stamp used by maybe_send_weekly_digest() (lib/digest.php) to avoid sending the
 * Sunday-night digest twice if the daily cron runs more than once in a day
 * (manual re-run + scheduled run).
 *
 * NULL = never sent. The value is written/compared as a PHP app-TZ date string
 * (date('Y-m-d')), NOT MySQL CURDATE() — the cron fires ~22:13 PDT which is already
 * Monday in the server's EDT clock, so all the digest's day logic stays on one
 * clock (the documented S24 timezone trap).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/012_digest_tracking.php
 *
 * Idempotent (information_schema-guarded — MySQL 8 has no ADD COLUMN IF NOT EXISTS). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). maybe_send_weekly_digest() reads and
 * writes alert_settings.digest_sent_on, so deploying the code before the column
 * exists would error the (cron-only) digest path until this runs.
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

$exists = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'alert_settings'
       AND column_name = 'digest_sent_on'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec(
        "ALTER TABLE alert_settings
         ADD COLUMN digest_sent_on DATE NULL
         AFTER digest_enabled"
    );
    echo "Migration 012 applied: alert_settings.digest_sent_on added.\n";
} else {
    echo "Migration 012 skipped: alert_settings.digest_sent_on already present.\n";
}
