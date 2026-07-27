<?php
declare(strict_types=1);
/**
 * Migration 035 — events / trips (docs/EVENTS_PLAN.md).
 *
 * Group transactions into a named EVENT (a trip, a wedding, a move) to see its total cost
 * and category breakdown. Two tables, structurally mirroring tags + transaction_tags:
 *
 *   events              one row per event. ⚠️ This is the app's FIRST user-owned object with
 *                       its own visibility flag (until now only `accounts` had one; goals /
 *                       budgets / rules are household-shared). `shared` = every member sees
 *                       it; `private` = only `created_by` sees it. The event-level gate
 *                       (EVIS in queries.php) decides whether you see the EVENT AT ALL; the
 *                       usual account-level VIS clause still decides which of its member
 *                       transactions you can see inside it.
 *   event_transactions  the junction. Whole-transaction membership only (no partial amounts
 *                       — use Splits for that). A tx may belong to several events.
 *
 * FKs: event_id ON DELETE CASCADE (deleting an event drops its memberships) and
 * transaction_id ON DELETE CASCADE (a Plaid `removed` DELETEs the transactions row — same
 * contract transaction_tags / transaction_splits / refund_watch already rely on). NB the
 * pending→posted hand-off is handled in code, not by the FK: Plaid issues a NEW
 * transaction_id when a pending tx posts and removes the old row, so lib/sync.php
 * (remap_tx_meta) copies the membership forward before the cascade fires.
 *
 * No FK on created_by / added_by (users churn-free but the no-FK-to-users convention holds
 * across goals / tags / custom_categories).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/035_events.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS ×2. CLI-only. Run migration-first — q_events() and
 * the attach_tx_meta() fold-in reference these tables, so the code would 500 if it deployed
 * before they exist.
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
    "CREATE TABLE IF NOT EXISTS events (
       event_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
       name       VARCHAR(120) NOT NULL,
       visibility ENUM('shared','private') NOT NULL DEFAULT 'shared',
       created_by INT UNSIGNED NOT NULL,          -- users.id; the creator manages it
       start_date DATE         NULL,              -- optional; drives the suggestions list
       end_date   DATE         NULL,
       note       VARCHAR(500) NULL,
       created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (event_id),
       KEY idx_events_vis (visibility, created_by)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "events ensured.\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS event_transactions (
       event_id       INT UNSIGNED NOT NULL,
       transaction_id VARCHAR(64)  NOT NULL,      -- transactions PK is VARCHAR(64)
       added_by       INT UNSIGNED NOT NULL,
       added_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (event_id, transaction_id),
       KEY idx_et_tx (transaction_id),
       CONSTRAINT fk_et_event FOREIGN KEY (event_id) REFERENCES events (event_id) ON DELETE CASCADE,
       CONSTRAINT fk_et_tx    FOREIGN KEY (transaction_id) REFERENCES transactions (transaction_id)
         ON DELETE CASCADE                        -- Plaid `removed` DELETEs the tx row
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "event_transactions ensured.\n";

echo "Migration 035 applied: events + event_transactions ensured.\n";
