<?php
declare(strict_types=1);
/**
 * Migration 034 — code-review Phase 3/5 schema (three independent, forward-compatible
 * additions batched into one file per the migration note in docs/CODE_REVIEW_2026-07-05.md):
 *
 *   1. plaid_webhook_keys  (3.2) — a tiny cache of Plaid's ES256 webhook-verification
 *      keys keyed by `kid`. Before this, verify_plaid_webhook() fetched the key from
 *      Plaid on EVERY webhook (an outbound cURL + 30s timeout per hit, driven by an
 *      attacker-supplied kid). With the cache a legit kid is fetched once per 24h; the
 *      distinct-kid count is capped in code (kid-spray guard) so the table can't grow
 *      unbounded. key_json = the raw JWK, fetched_at = when we last pulled it.
 *
 *   2. items.resync_pending (3.4) — TINYINT flag. A SYNC_UPDATES_AVAILABLE webhook that
 *      arrives while the cron/on-demand already holds the per-item advisory lock is
 *      GET_LOCK(...,0)-skipped and its announced update was silently dropped (up to ~24h
 *      stale). Now the skipped caller sets this flag; the lock holder does one more pass
 *      after releasing, and the nightly cron sweeps any left set. Single-retry (no queue).
 *
 *   3. accounts.missing_since (5.9) — DATETIME, forward-seeded here (no code reads/writes
 *      it yet). For the later Phase-5 work: mark an account that a Plaid item stops
 *      returning from /accounts/balance/get (a re-issued account_id → orphan row that
 *      froze + double-counted, the S97 class) instead of leaving it silently stale.
 *      Batched now so Phase 5 needs no second migration (per the migration note).
 *
 *   /usr/local/bin/php83.cli /home/wotwclan/www/budget/lib/migrations/034_webhook_keys_resync_missing.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + information_schema-guarded ADD COLUMN (MySQL 8
 * has no ADD COLUMN IF NOT EXISTS). CLI-only. Run migration-first (webhook.php +
 * plaid_webhook.php + sync.php reference the new table/column after the code deploy).
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

// 1) plaid_webhook_keys ------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS plaid_webhook_keys (
       kid         VARCHAR(128) NOT NULL,             -- Plaid JWT 'kid' (public key id)
       key_json    JSON         NOT NULL,             -- the raw JWK returned by /webhook_verification_key/get
       fetched_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- last pull (24h TTL, checked in code)
       PRIMARY KEY (kid)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "Migration 034: plaid_webhook_keys ensured.\n";

// 2) items.resync_pending ----------------------------------------------------
$hasResync = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'items'
       AND column_name = 'resync_pending'"
)->fetchColumn();
if ($hasResync === 0) {
    $pdo->exec("ALTER TABLE items ADD COLUMN resync_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER last_synced_at");
    echo "Migration 034: items.resync_pending added.\n";
} else {
    echo "Migration 034: items.resync_pending already present.\n";
}

// 3) accounts.missing_since --------------------------------------------------
$hasMissing = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'accounts'
       AND column_name = 'missing_since'"
)->fetchColumn();
if ($hasMissing === 0) {
    $pdo->exec("ALTER TABLE accounts ADD COLUMN missing_since DATETIME NULL AFTER updated_at");
    echo "Migration 034: accounts.missing_since added.\n";
} else {
    echo "Migration 034: accounts.missing_since already present.\n";
}

echo "Migration 034 applied.\n";
