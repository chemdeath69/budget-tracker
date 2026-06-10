<?php
declare(strict_types=1);
/**
 * Migration 011 — alert settings table (TODO #14).
 *
 * Creates `alert_settings`, a single global (household-shared) row (id=1) holding
 * notification preferences moved out of config.php: a master email kill-switch,
 * large-transaction on/off + threshold, the bank-connection-broken alert, and
 * (stored-but-not-yet-consumed) flags for the weekly digest (#15), budget-exceeded
 * + unusual-spend alerts (#16) and bill reminders (#4).
 *
 * Defaults reproduce the PRE-#14 behaviour exactly — large-tx + connection alerts
 * ON, everything new OFF — so deploying the code changes nothing until the owner
 * toggles something. large_tx_threshold NULL = fall back to
 * config['alerts']['large_tx_threshold']. Read via alert_settings() (lib/mailer.php)
 * / the thin q_alert_settings() wrapper (lib/queries.php).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/011_alert_settings.php
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS + a row-count-guarded seed INSERT). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). settings.php + api/alerts.php + sync.php
 * read this table; deploying the code before the table exists would fall back to
 * config (the reader is defensive) but the settings UI would have nothing to persist
 * to — so apply the migration before deploying.
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
    "CREATE TABLE IF NOT EXISTS alert_settings (
       id                       TINYINT UNSIGNED NOT NULL DEFAULT 1,
       email_enabled            TINYINT(1)    NOT NULL DEFAULT 1,
       large_tx_enabled         TINYINT(1)    NOT NULL DEFAULT 1,
       large_tx_threshold       DECIMAL(15,2) NULL,
       connection_alert_enabled TINYINT(1)    NOT NULL DEFAULT 1,
       budget_alert_enabled     TINYINT(1)    NOT NULL DEFAULT 0,
       budget_alert_pct         TINYINT UNSIGNED NOT NULL DEFAULT 90,
       unusual_spend_enabled    TINYINT(1)    NOT NULL DEFAULT 0,
       bill_reminder_enabled    TINYINT(1)    NOT NULL DEFAULT 0,
       bill_reminder_days       TINYINT UNSIGNED NOT NULL DEFAULT 5,
       digest_enabled           TINYINT(1)    NOT NULL DEFAULT 0,
       updated_by               INT UNSIGNED  NULL,
       updated_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$has = (int)$pdo->query("SELECT COUNT(*) FROM alert_settings WHERE id = 1")->fetchColumn();
if ($has === 0) {
    $pdo->exec("INSERT INTO alert_settings (id) VALUES (1)");   // all DB defaults
    echo "Migration 011 applied: alert_settings created + seeded (id=1).\n";
} else {
    echo "Migration 011 skipped: alert_settings row id=1 already present.\n";
}
