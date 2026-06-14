<?php
declare(strict_types=1);
/**
 * Migration 026 — refund_watch (TODO2 #34, refund tracking).
 *
 * One row per PURCHASE the user has flagged "expecting a refund" (the purchase is an
 * expense — Plaid amount > 0). Like the #8 annotation tables (tags / transaction_splits)
 * this is a SIDE table keyed by transaction_id, so it is NEVER touched by a Plaid sync
 * and survives re-sync (the flag isn't a transactions column). The matching refund
 * CREDIT, once confirmed, is recorded in matched_tx_id.
 *
 *   transaction_id  — the watched purchase (PK; FK → transactions ON DELETE CASCADE, so a
 *                     Plaid 'removed' purchase cleans up its watch, like tags/splits).
 *   status          — pending (still waiting) | received (the refund landed / confirmed).
 *                     "no longer expecting / dismiss" = DELETE the row (api refund_unflag),
 *                     so two states are enough.
 *   matched_tx_id   — the credit transaction that fulfilled it (set on confirm; NO FK —
 *                     Plaid churns credits and goals/investment_transactions follow the same
 *                     "no FK, tolerate a stale id" rule; the read LEFT JOINs + degrades).
 *   resolved_at     — when it was marked received (NULL while pending).
 *
 * Visibility-only feature: it never changes spend math — a refund credit is already its
 * own money-in (amount < 0) transaction the spend aggregations exclude (they count
 * amount > 0). Reads are VIS-scoped per viewer (q_refund_watches / q_refund_credits).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/026_refund_watch.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS. CLI-only. Run FIRST (migration-first) — the
 * q_refund_* reads + the attach_tx_meta fold-in would error before the table exists.
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
    "CREATE TABLE IF NOT EXISTS refund_watch (
       transaction_id VARCHAR(64) NOT NULL,                 -- the watched purchase (amount > 0)
       status         ENUM('pending','received') NOT NULL DEFAULT 'pending',
       matched_tx_id  VARCHAR(64) NULL,                     -- the fulfilling credit (no FK — Plaid churns)
       created_by     INT UNSIGNED NULL,
       created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       resolved_at    DATETIME NULL,
       PRIMARY KEY (transaction_id),
       KEY idx_refund_status (status),
       KEY idx_refund_matched (matched_tx_id),
       CONSTRAINT fk_refund_tx FOREIGN KEY (transaction_id)
         REFERENCES transactions(transaction_id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 026 applied: refund_watch ensured.\n";
