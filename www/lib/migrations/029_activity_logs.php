<?php
declare(strict_types=1);
/**
 * Migration 029 — activity logs (Access Logs + Sync Status UI; owner request).
 *
 * Three NEW tables backing the new `activity.php` diagnostic page:
 *
 *   access_log      — one row per audited event: a login/logout, a page view, or a
 *                     mutating action (recategorize, budget edit, refresh, …). Written
 *                     best-effort by lib/activity.php; pruned to ~90 days by the nightly
 *                     cron (access_log_prune). user_id is NULL for a pre-auth event
 *                     (e.g. a rejected login). No FK to users — a deleted user's history
 *                     should survive, and the writer is best-effort.
 *
 *   sync_run        — one row per nightly cron pipeline run (and any other captured sync):
 *                     trigger, started/finished, overall ok, step + error counts. Lets the
 *                     Sync-status page show "when the sync ran" and whether it was clean.
 *
 *   sync_run_step   — one row per pipeline STEP within a run (per-bank item sync, prices,
 *                     dividends, FRED, vehicle revalue, snapshot, balance history, digest,
 *                     spend-alerts, …) with its output summary or error text. This is what
 *                     "the outputs for each sync step + any issues" needs — the cron's
 *                     per-step echoes only went to the web-denied storage/cron.log before.
 *                     FK → sync_run ON DELETE CASCADE (pruning a run drops its steps).
 *
 * Both logs are HOUSEHOLD-WIDE diagnostics (the page is reached from Settings; reads are
 * NOT VIS-scoped, like alert_settings / credit_reports). The page resolves a user_id to a
 * name via household_users() in PHP — no JOIN needed.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/029_activity_logs.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS ×3. CLI-only. Run FIRST (migration-first) — the
 * lib/activity.php writers (cron run capture, page-view + action logging) and the q_* reads
 * would error before the tables exist.
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
    "CREATE TABLE IF NOT EXISTS access_log (
       id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       user_id     INT UNSIGNED NULL,                  -- NULL for a pre-auth event (no FK — survive user delete)
       event_type  VARCHAR(16) NOT NULL,               -- login | logout | page | action
       label       VARCHAR(160) NULL,                  -- page path, or 'endpoint:action'
       detail      VARCHAR(255) NULL,                  -- extra (query string, action target, reason)
       ip          VARCHAR(45)  NULL,                  -- v4/v6
       user_agent  VARCHAR(255) NULL,
       created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       KEY idx_access_created (created_at),
       KEY idx_access_user (user_id, created_at),
       KEY idx_access_event (event_type, created_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sync_run (
       id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       trigger_type VARCHAR(16) NOT NULL,              -- cron | manual | webhook
       started_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       finished_at  DATETIME NULL,                     -- NULL while in progress
       ok           TINYINT(1) NULL,                   -- 1 all steps ok, 0 >=1 failed, NULL in progress
       step_count   INT NOT NULL DEFAULT 0,
       error_count  INT NOT NULL DEFAULT 0,
       PRIMARY KEY (id),
       KEY idx_syncrun_started (started_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sync_run_step (
       id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       run_id      BIGINT UNSIGNED NOT NULL,
       step        VARCHAR(48) NOT NULL,               -- item:<id> | prices | dividends | fred | vehicles | snapshot | ...
       label       VARCHAR(160) NULL,                  -- friendly name (e.g. the bank/institution)
       ok          TINYINT(1) NOT NULL DEFAULT 1,
       message     TEXT NULL,                          -- the output summary or error text
       created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       KEY idx_runstep_run (run_id),
       CONSTRAINT fk_runstep_run FOREIGN KEY (run_id)
         REFERENCES sync_run(id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 029 applied: access_log + sync_run + sync_run_step ensured.\n";
