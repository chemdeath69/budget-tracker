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
require_once __DIR__ . '/../lib/queries.php';   // home_config() (the UI-managed home address)
require __DIR__ . '/../lib/sync.php';
require __DIR__ . '/../lib/prices.php';
require __DIR__ . '/../lib/dividends.php';
require __DIR__ . '/../lib/home_value.php';
require __DIR__ . '/../lib/fred.php';
require __DIR__ . '/../lib/vehicles.php';
require __DIR__ . '/../lib/digest.php';
require __DIR__ . '/../lib/spend_alerts.php';
require __DIR__ . '/../lib/activity.php';

$pdo = db();

// Open a sync-run capture (migration 029) so this nightly pipeline's per-step
// outputs/errors land in the DB (sync_run / sync_run_step), surfaced on the Sync-status
// page — not just the web-denied storage/cron.log. All sync_run_* calls are best-effort
// (a logging failure never aborts the run); $runId is null only if the table is missing.
$runId = sync_run_begin($pdo, 'cron');
// Only Plaid items have a live feed to sync. Manual (source='manual') items are
// updated by document upload and have no access_token — skip them here (their
// balances still flow into the snapshot written below).
$items = $pdo->query('SELECT item_id, user_id, access_token_enc, transactions_cursor, institution_name
                      FROM items WHERE status <> "removed" AND source = "plaid"')->fetchAll();

$ts = date('Y-m-d H:i:s T');
echo "[$ts] cron sync start — " . count($items) . " item(s)\n";

foreach ($items as $item) {
    // Force Plaid to re-poll the bank for brand-new activity BEFORE we sync
    // (added 2026-06-30). /transactions/sync only ever returns what Plaid has
    // already cached — without this kick a quiet item just keeps returning
    // +0/+0/+0 until Plaid's own background poll happens to run, so a freshly
    // linked bank can look "frozen" for days. /transactions/refresh is ASYNC:
    // brand-new charges arrive via the follow-up SYNC_UPDATES_AVAILABLE webhook a
    // few minutes later (its sync_item shares the same advisory lock, so no race),
    // while the balance read in this run's sync_item is already fresh. Best-effort
    // — an institution that doesn't support on-demand refresh (PRODUCTS_NOT_SUPPORTED,
    // e.g. Capital One) or a broken connection (ITEM_LOGIN_REQUIRED) just no-ops
    // here; the sync_item below still records the item's real status/error.
    try {
        $tok = decrypt_secret($item['access_token_enc']);
        if ($tok !== null) plaid_refresh_transactions($tok);
    } catch (Throwable $e) {
        if (!plaid_benign($e)) error_log('cron refresh ' . $item['item_id'] . ': ' . $e->getMessage());
    }

    // Belt-and-suspenders: sync_item() already catches its own Throwables, but
    // guard here too so no single item can ever abort the loop and skip the
    // post-loop snapshot / balance-history / price / home-value steps below.
    try {
        $r = sync_item($pdo, $item, 'cron');
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => $e->getMessage()];
    }
    if (!empty($r['ok'])) {
        // Feed-freshness probe (observability, added 2026-06-30). A bare
        // "+0/+0/+0" can't distinguish "Plaid genuinely has nothing new" from a
        // STALE feed (Plaid not re-polling the bank — /transactions/sync only
        // returns what Plaid already cached; only /transactions/refresh or Plaid's
        // own background poll surfaces a brand-new charge). Recording the newest
        // stored transaction date + its age makes a frozen feed obvious at a glance
        // in cron.log AND on the Sync-status page (activity.php). Best-effort —
        // never break the run. App-TZ date math in PHP (NOT MySQL CURDATE/DATEDIFF,
        // which would read EDT at ~22:13 PDT fire time — the S24 trap).
        $freshNote = '';
        try {
            $fp = $pdo->prepare(
                "SELECT MAX(t.date) AS newest, COUNT(*) AS n
                 FROM transactions t JOIN accounts a ON t.account_id = a.account_id
                 WHERE a.item_id = ?"
            );
            $fp->execute([$item['item_id']]);
            $f = $fp->fetch();
            if ($f && $f['newest'] !== null) {
                $ageD = (int) floor((strtotime(date('Y-m-d')) - strtotime((string)$f['newest'])) / 86400);
                $freshNote = " (newest tx {$f['newest']}, {$ageD}d ago, {$f['n']} total)";
            } elseif ($f) {
                $freshNote = ' (no transactions stored)';
            }
        } catch (Throwable $e) { /* observability only */ }
        $msg = "+{$r['added']} added / ~{$r['modified']} modified / -{$r['removed']} removed{$freshNote}";
        echo "  item {$item['item_id']}: {$msg}\n";
        sync_run_step($pdo, $runId, "item:{$item['item_id']}", true, $msg, $item['institution_name'] ?? null);
    } else {
        $msg = 'FAILED — ' . ($r['error'] ?? '?');
        echo "  item {$item['item_id']}: {$msg}\n";
        sync_run_step($pdo, $runId, "item:{$item['item_id']}", false, $msg, $item['institution_name'] ?? null);
    }
}

// Resync sweep (code review 3.4). Any item flagged resync_pending during this run — a
// webhook that lost the per-item advisory lock while the cron held it — gets one more pass
// so its announced update isn't dropped for ~24h. sync_item() clears the flag on acquire,
// so this only re-runs the genuine stragglers (usually none). Best-effort.
try {
    $pend = $pdo->query("SELECT item_id, user_id, access_token_enc, transactions_cursor, institution_name
                         FROM items WHERE resync_pending = 1 AND status <> 'removed' AND source = 'plaid'")->fetchAll();
    $reSynced = 0;
    foreach ($pend as $item) {
        try { $r = sync_item($pdo, $item, 'cron'); }
        catch (Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
        $ok  = !empty($r['ok']) && empty($r['skipped']);
        if ($ok) $reSynced++;
        $msg = $ok ? "resync +{$r['added']}/~{$r['modified']}/-{$r['removed']}"
                   : (empty($r['skipped']) ? 'FAILED — ' . ($r['error'] ?? '?') : 'still locked — skipped');
        echo "  resync {$item['item_id']}: {$msg}\n";
        sync_run_step($pdo, $runId, "resync:{$item['item_id']}", $ok || !empty($r['skipped']), $msg, $item['institution_name'] ?? null);
    }
    if ($reSynced > 0) write_networth_snapshot($pdo);
    if ($pend) echo "[" . date('Y-m-d H:i:s T') . "] resync sweep: {$reSynced}/" . count($pend) . " re-synced\n";
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s T') . "] resync sweep: FAILED — " . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'resync_sweep', false, 'FAILED — ' . $e->getMessage());
}

// Re-age manual vehicle assets (#40) into accounts.balance_current BEFORE the snapshot +
// balance-history below read it, so the depreciation curve advances day by day and net
// worth/history pick it up. Pure local math (no external call); try/catch per the S22
// resilience contract. No-op when there are no vehicles.
try {
    $vn = vehicle_revalue_all($pdo);
    if ($vn > 0) echo "[" . date('Y-m-d H:i:s T') . "] vehicles: revalued {$vn} asset(s)\n";
    sync_run_step($pdo, $runId, 'vehicles', true, "revalued {$vn} asset(s)");
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s T') . "] vehicles: FAILED — " . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'vehicles', false, 'FAILED — ' . $e->getMessage());
}

try {
    write_networth_snapshot($pdo);
    echo "[" . date('Y-m-d H:i:s T') . "] cron sync done; snapshot written.\n";
    sync_run_step($pdo, $runId, 'snapshot', true, 'net-worth snapshot written');
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s T') . "] snapshot: FAILED — " . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'snapshot', false, 'FAILED — ' . $e->getMessage());
}

// Per-account balance history (one row per account per day) — powers the
// mortgage-balance-over-time chart (balance_snapshots only stores household totals).
// 'hidden' accounts stop accruing recorded history while hidden (registered nowhere).
// Existing rows are left in place — if un-hidden later the chart resumes with a gap.
// Wrapped in try/catch per the Session 22 resilience contract — a transient PDOException
// (deadlock / lock-wait timeout) here must not abort the run before prices/fred/digest/
// spend-alerts/prune/sync_run_finish below. Self-heals on the next run.
try {
    $abh = $pdo->prepare(
        "INSERT INTO account_balance_history (account_id, snapshot_date, balance)
         SELECT account_id, :d, COALESCE(balance_current, 0) FROM accounts
         WHERE visibility <> 'hidden'
         ON DUPLICATE KEY UPDATE balance = VALUES(balance)"
    );
    $abh->execute([':d' => date('Y-m-d')]);
    echo "[" . date('Y-m-d H:i:s T') . "] account balance history: {$abh->rowCount()} row(s)\n";
    sync_run_step($pdo, $runId, 'balance_history', true, "{$abh->rowCount()} row(s)");
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s T') . "] account balance history: FAILED — " . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'balance_history', false, 'FAILED — ' . $e->getMessage());
}

// Refresh security prices (daily close per held ticker). No-op without a key.
// Only here in the daily cron — NOT in lib/sync.php — so webhook-triggered syncs
// don't burn Twelve Data credits on every fire.
// In a try/catch per the Session 22 resilience contract — prices_refresh_latest() maps
// per-ticker HTTP errors into its returned 'errors' array, but the DB I/O underneath
// (tracked-securities read + the upsert loop) can throw an uncaught PDOException that would
// otherwise abort the run before fred/digest/spend-alerts/prune/sync_run_finish.
try {
    $pr = prices_refresh_latest($pdo);
    $prMsg = $pr['ok'] ? "{$pr['updated']} close(s) across {$pr['symbols']} symbol(s)"
                       . (empty($pr['errors']) ? '' : '; errors: ' . implode(', ', array_keys($pr['errors'])))
                       : "skipped ({$pr['error']})";
    echo "[" . date('Y-m-d H:i:s T') . "] prices: {$prMsg}\n";
    // Informational step: a no-key skip or a single bad-ticker error isn't banner-worthy
    // (captured in the message for visibility) — only a thrown exception marks a step failed.
    sync_run_step($pdo, $runId, 'prices', true, $prMsg);
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s T') . "] prices: FAILED — " . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'prices', false, 'FAILED — ' . $e->getMessage());
}

// Refresh per-security dividend data (Polygon.io free feed → security_dividends).
// Staleness-gated (≤weekly per security) so the nightly cost stays near zero and the
// 5/min free limit is never approached. FREE (no per-request billing), so safe to wire
// here. No-op without a key. In a try/catch (the S22 per-step resilience contract) so a
// dividend-feed hiccup can never abort the cron before the digest/alert steps below.
try {
    $dv = dividends_refresh_if_stale($pdo);
    $dvMsg = $dv['ok'] ? "{$dv['refreshed']} refreshed / {$dv['skipped']} fresh, {$dv['stored']} row(s) across {$dv['symbols']} symbol(s)"
                       . (empty($dv['errors']) ? '' : '; errors: ' . implode(', ', array_keys($dv['errors'])))
                       : "skipped ({$dv['error']})";
    echo "[" . date('Y-m-d H:i:s T') . "] dividends: {$dvMsg}\n";
    sync_run_step($pdo, $runId, 'dividends', true, $dvMsg);
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s T') . "] dividends: FAILED — " . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'dividends', false, 'FAILED — ' . $e->getMessage());
}

// Refresh the home value (RentCast AVM) at most ~monthly. Hard-capped at 50 req/mo
// in lib/home_value.php so an overage charge can never occur. No-op without a key.
// The address now comes from the UI-managed home_config row (migration 031); a
// removed (sold) home is skipped so we don't keep paying RentCast for a sold house.
// In a try/catch per the Session 22 resilience contract — RentCast transport errors are soft-handled
// by the lib, but the DB ops underneath (hv_reserve_slot / *_store INSERTs, home_config read) can throw
// an uncaught PDOException that would otherwise abort the run before fred/digest/spend-alerts/
// prune/sync_run_finish below.
try {
    $hc       = home_config($pdo);
    $homeAddr = $hc['removed_now'] ? '' : $hc['address'];
    if ($homeAddr !== '') {
        $hv = home_value_refresh_if_stale($pdo, $homeAddr);
        $u  = hv_usage($pdo);
        $msg = $hv['ok']
            ? (isset($hv['skipped']) ? "fresh as of {$hv['as_of']}" : "stored \$" . number_format((float)$hv['value']))
            : "skipped ({$hv['error']})";
        $hvMsg = "$msg — quota {$u['used']}/{$u['cap']} this month";
        echo "[" . date('Y-m-d H:i:s T') . "] home value: {$hvMsg}\n";

        // Property record (~quarterly) + zip market data (~monthly). Same capped path.
        $prc = property_record_refresh_if_stale($pdo, $homeAddr);
        $prcMsg = $prc['ok'] ? ($prc['skipped'] ?? 'stored') : "skipped ({$prc['error']})";
        echo "[" . date('Y-m-d H:i:s T') . "] property record: {$prcMsg}\n";

        $zip = hv_zip_from_address($homeAddr);
        $mk  = market_refresh_if_stale($pdo, $zip);
        $u   = hv_usage($pdo);
        $mkMsg = ($mk['ok'] ? ($mk['skipped'] ?? 'stored') : "skipped ({$mk['error']})")
               . " — quota {$u['used']}/{$u['cap']} this month";
        echo "[" . date('Y-m-d H:i:s T') . "] market ($zip): {$mkMsg}\n";

        sync_run_step($pdo, $runId, 'home_value', true, "value: {$hvMsg}; property: {$prcMsg}; market ($zip): {$mkMsg}");
    } else {
        $skip = $hc['address'] === '' ? 'no home configured' : 'home removed';
        echo "[" . date('Y-m-d H:i:s T') . "] home value: skipped ({$skip})\n";
        sync_run_step($pdo, $runId, 'home_value', true, "skipped ({$skip})");
    }
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s T') . "] home value: FAILED — " . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'home_value', false, 'FAILED — ' . $e->getMessage());
}

// FRED economic series (TODO #17) — refresh CPI / 30-yr mortgage rate / Treasury +
// Fed-funds yields into the fred_series cache (powers the Economic page + the inline
// real-net-worth / refi / savings-context insights). FREE feed, no per-request
// billing. No-op without a key. Wrapped in try/catch per the Session 22 resilience
// contract so a transient failure logs one line instead of aborting the run.
try {
    $fr = fred_refresh_latest($pdo);
    $frMsg = $fr['ok'] ? "{$fr['updated']} obs across {$fr['series']} series"
                       . (empty($fr['errors']) ? '' : '; errors: ' . implode(', ', array_keys($fr['errors'])))
                       : "skipped ({$fr['error']})";
    echo "[" . date('Y-m-d H:i:s T') . "] fred: {$frMsg}\n";
    sync_run_step($pdo, $runId, 'fred', true, $frMsg);
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s T') . '] fred: FAILED — ' . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'fred', false, 'FAILED — ' . $e->getMessage());
}

// Weekly email digest (TODO #15) — only actually sends on Sunday (app TZ) or as a
// catch-up after a missed Sunday, and only when alert_settings.digest_enabled is on.
// Runs last, so it summarises the data this run just refreshed. All day/idempotency
// logic is in PHP app-TZ (NOT MySQL CURDATE()) — see lib/digest.php. Wrapped in
// try/catch to match the cron's per-step resilience contract (Session 22) so a
// transient DB error here logs one line instead of dumping a stack trace.
try {
    maybe_send_weekly_digest($pdo);
    sync_run_step($pdo, $runId, 'digest', true, 'ran (sends only on Sunday / catch-up)');
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s T') . '] digest: FAILED — ' . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'digest', false, 'FAILED — ' . $e->getMessage());
}

// Spending alerts (TODO #16) — budget-exceeded / unusual-spend / bill reminders.
// Runs daily (the alert_log table dedups so a crossing emails at most once per
// occurrence), gated on alert_settings. Runs last like the digest, summarising the
// data this run just refreshed. All day/period logic is PHP app-TZ (see
// lib/spend_alerts.php). Wrapped in try/catch per the Session 22 resilience contract.
try {
    maybe_send_spend_alerts($pdo);
    sync_run_step($pdo, $runId, 'spend_alerts', true, 'ran (gated on alert_settings)');
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s T') . '] spend-alerts: FAILED — ' . $e->getMessage() . "\n";
    sync_run_step($pdo, $runId, 'spend_alerts', false, 'FAILED — ' . $e->getMessage());
}

// Prune the access + webhook logs to the ~90-day retention window, then close out the run
// capture (stamps finished_at + rolls up step_count / error_count / ok). All best-effort.
$pruned = access_log_prune($pdo);
echo "[" . date('Y-m-d H:i:s T') . "] access log: pruned {$pruned} old row(s)\n";
sync_run_step($pdo, $runId, 'prune_access_log', true, "pruned {$pruned} old row(s)");

$prunedWh = webhook_log_prune($pdo);   // code review 3.2 — webhook_log previously grew unbounded
echo "[" . date('Y-m-d H:i:s T') . "] webhook log: pruned {$prunedWh} old row(s)\n";
sync_run_step($pdo, $runId, 'prune_webhook_log', true, "pruned {$prunedWh} old row(s)");
sync_run_finish($pdo, $runId);
echo "[" . date('Y-m-d H:i:s T') . "] sync run #" . ($runId ?? 0) . " recorded.\n";
