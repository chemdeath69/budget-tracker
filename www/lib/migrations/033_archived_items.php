<?php
declare(strict_types=1);
/**
 * Migration 033 — archived_items (permanent historical record of removed Items).
 *
 * WHY: our two destroy paths — api/unlink.php (remove a bank) and api/factory_reset.php
 * (wipe) — PURGE the items + accounts rows, so after a removal we lose the Plaid item_id,
 * the (encrypted) access_token, the institution, and the account metadata FOREVER. Plaid
 * has NO list-items API and no way to recover an access_token from an item_id, so once our
 * row is gone we can never look that connection up again — exactly what's needed to file a
 * Plaid support ticket (e.g. "please remove these Items" — they want item_ids, and a token
 * lets us prove ownership). This table is the long-term archive: every Item we remove is
 * snapshotted here FIRST, and this table is NEVER purged (NOT in factory_reset's wipe list).
 *
 *   item_id            the Plaid item_id (or mnl_… for a manual account). NOT unique — an
 *                      item could be linked → removed → re-linked → removed again.
 *   access_token_enc   the still-ENCRYPTED token at archive time (copied verbatim, never
 *                      decrypted here). Dead after /item/remove, but kept for the record.
 *   accounts_json      a JSON snapshot of the Item's accounts (id, mask, name, type,
 *                      subtype, balances, visibility) at archive time.
 *   archive_reason     'unlink' | 'factory_reset' (extensible).
 *   plaid_removed      1 if we successfully revoked it at Plaid (/item/remove) before archiving.
 *
 * No FK (must survive a user delete / accounts churn, like access_log). Written best-effort
 * by archive_item() (lib/sync.php) from the destroy endpoints.
 *
 *   /usr/local/bin/php83.cli /home/wotwclan/www/budget/lib/migrations/033_archived_items.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS. CLI-only. Run migration-first (the destroy
 * endpoints reference the table; archive_item is best-effort but migration-first is the rule).
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
    "CREATE TABLE IF NOT EXISTS archived_items (
       id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       item_id            VARCHAR(64)  NOT NULL,          -- Plaid item_id or mnl_… (NOT unique — relink/remove cycles)
       user_id            INT UNSIGNED NULL,              -- original owner (no FK — survive a user delete)
       source             VARCHAR(16)  NULL,              -- plaid | manual
       manual_type        VARCHAR(32)  NULL,
       institution_id     VARCHAR(64)  NULL,
       institution_name   VARCHAR(255) NULL,
       access_token_enc   VARBINARY(512) NULL,            -- still-encrypted token at archive time (dead after /item/remove)
       status             VARCHAR(32)  NULL,              -- items.status at archive time
       error_code         VARCHAR(64)  NULL,
       consent_expiration DATETIME NULL,
       item_created_at    DATETIME NULL,                  -- original items.created_at
       last_synced_at     DATETIME NULL,
       account_count      INT NOT NULL DEFAULT 0,
       accounts_json      JSON NULL,                      -- snapshot of the Item's accounts at archive time
       archive_reason     VARCHAR(32)  NOT NULL,          -- unlink | factory_reset
       plaid_removed      TINYINT(1)   NOT NULL DEFAULT 0,-- did we /item/remove it at Plaid before archiving?
       archived_by        INT UNSIGNED NULL,              -- who triggered the removal
       archived_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       KEY idx_architems_item (item_id),
       KEY idx_architems_inst (institution_name),
       KEY idx_architems_when (archived_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Migration 033 applied: archived_items ensured (permanent removed-Item archive).\n";
