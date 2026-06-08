<?php
/**
 * Daily cron — iterate all Items, sync each (transactions + balances + extras),
 * then write the household net-worth snapshot.
 *
 * Run via cPanel Cron Job with the explicit PHP 8.3 CLI (bare `php` is 5.6):
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/cron/sync.php >> /home/cpuser/www/budget/storage/cron.log 2>&1
 *
 * NB: the log MUST go under the docroot (storage/, web-denied) — the home dir is
 * root-owned (drwxr-x---) so ~/budget-cron.log fails with "Permission denied", and
 * a failed shell redirect means the script never runs at all.
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
require __DIR__ . '/../lib/home_value.php';

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

// Per-account balance history (one row per account per day) — powers the
// mortgage-balance-over-time chart (balance_snapshots only stores household totals).
$abh = $pdo->prepare(
    "INSERT INTO account_balance_history (account_id, snapshot_date, balance)
     SELECT account_id, :d, COALESCE(balance_current, 0) FROM accounts
     ON DUPLICATE KEY UPDATE balance = VALUES(balance)"
);
$abh->execute([':d' => date('Y-m-d')]);
echo "[" . date('Y-m-d H:i:s T') . "] account balance history: {$abh->rowCount()} row(s)\n";

// Refresh security prices (daily close per held ticker). No-op without a key.
// Only here in the daily cron — NOT in lib/sync.php — so webhook-triggered syncs
// don't burn Twelve Data credits on every fire.
$pr = prices_refresh_latest($pdo);
echo "[" . date('Y-m-d H:i:s T') . "] prices: "
   . ($pr['ok'] ? "{$pr['updated']} close(s) across {$pr['symbols']} symbol(s)"
                . (empty($pr['errors']) ? '' : '; errors: ' . implode(', ', array_keys($pr['errors'])))
                : "skipped ({$pr['error']})") . "\n";

// Refresh the home value (RentCast AVM) at most ~monthly. Hard-capped at 50 req/mo
// in lib/home_value.php so an overage charge can never occur. No-op without a key
// or a configured 'home.address'.
$homeAddr = trim((string)($CONFIG['home']['address'] ?? ''));
if ($homeAddr !== '') {
    $hv = home_value_refresh_if_stale($pdo, $homeAddr);
    $u  = hv_usage($pdo);
    $msg = $hv['ok']
        ? (isset($hv['skipped']) ? "fresh as of {$hv['as_of']}" : "stored \$" . number_format((float)$hv['value']))
        : "skipped ({$hv['error']})";
    echo "[" . date('Y-m-d H:i:s T') . "] home value: $msg — quota {$u['used']}/{$u['cap']} this month\n";

    // Property record (~quarterly) + zip market data (~monthly). Same capped path.
    $pr = property_record_refresh_if_stale($pdo, $homeAddr);
    echo "[" . date('Y-m-d H:i:s T') . "] property record: "
       . ($pr['ok'] ? ($pr['skipped'] ?? 'stored') : "skipped ({$pr['error']})") . "\n";

    $zip = hv_zip_from_address($homeAddr);
    $mk  = market_refresh_if_stale($pdo, $zip);
    $u   = hv_usage($pdo);
    echo "[" . date('Y-m-d H:i:s T') . "] market ($zip): "
       . ($mk['ok'] ? ($mk['skipped'] ?? 'stored') : "skipped ({$mk['error']})")
       . " — quota {$u['used']}/{$u['cap']} this month\n";
}
