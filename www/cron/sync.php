<?php
/**
 * Daily cron — iterate all Items, sync each (transactions + balances + extras),
 * then write the household net-worth snapshot.
 *
 * Run via cPanel Cron Job with the explicit PHP 8.3 CLI (bare `php` is 5.6):
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/cron/sync.php >> ~/budget-cron.log 2>&1
 *
 * CLI-only guard so it can't be hit over the web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/sync.php';
require __DIR__ . '/../lib/prices.php';

$pdo = db();
// Only Plaid items have a live feed to sync. Manual (source='manual') items are
// updated by document upload and have no access_token — skip them here (their
// balances still flow into the snapshot written below).
$items = $pdo->query('SELECT item_id, user_id, access_token_enc, transactions_cursor
                      FROM items WHERE status <> "removed" AND source = "plaid"')->fetchAll();

$ts = date('Y-m-d H:i:s T');
echo "[$ts] cron sync start — " . count($items) . " item(s)\n";

foreach ($items as $item) {
    $r = sync_item($pdo, $item, 'cron');
    if (!empty($r['ok'])) {
        echo "  item {$item['item_id']}: +{$r['added']} ~{$r['modified']} -{$r['removed']}\n";
    } else {
        echo "  item {$item['item_id']}: FAILED — " . ($r['error'] ?? '?') . "\n";
    }
}

write_networth_snapshot($pdo);
echo "[" . date('Y-m-d H:i:s T') . "] cron sync done; snapshot written.\n";

// Refresh security prices (daily close per held ticker). No-op without a key.
// Only here in the daily cron — NOT in lib/sync.php — so webhook-triggered syncs
// don't burn Twelve Data credits on every fire.
$pr = prices_refresh_latest($pdo);
echo "[" . date('Y-m-d H:i:s T') . "] prices: "
   . ($pr['ok'] ? "{$pr['updated']} close(s) across {$pr['symbols']} symbol(s)"
                . (empty($pr['errors']) ? '' : '; errors: ' . implode(', ', array_keys($pr['errors'])))
                : "skipped ({$pr['error']})") . "\n";
