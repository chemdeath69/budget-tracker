<?php
declare(strict_types=1);
/**
 * Migration 030 — per-user preferences (UI redesign Phase 2 + 3).
 *
 * One row per user, keyed by users.id, holding a small JSON blob of that user's
 * personal preferences. Phase 2 stores `theme` (light|dark|auto). Phase 3 will add
 * the customizable-dashboard layout (`dashboard`) + the default-landing pref to the
 * SAME blob — no further migration needed.
 *
 *   user_prefs — user_id (PK), prefs JSON, updated_at.
 *
 * NOT VIS-scoped — it's the viewer's OWN prefs, keyed by user_id (read by
 * q_user_prefs(), written by api/prefs.php). No FK to users (mirrors the no-FK-churn
 * convention used by goals / security_* side tables; users.id is stable anyway).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/030_user_prefs.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS (no seed — absence = the default prefs).
 * CLI-only. Run migration-first — q_user_prefs() is defensive (a missing table →
 * the defaults), but api/prefs.php writes to the table, so deploy the schema first.
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
    "CREATE TABLE IF NOT EXISTS user_prefs (
       user_id    INT UNSIGNED NOT NULL,
       prefs      JSON         NULL,
       updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (user_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 030 applied: user_prefs ensured.\n";
