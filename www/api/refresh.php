<?php
/**
 * On-demand "Refresh now" — owner-triggered (any logged-in household user).
 *
 * For each targeted Plaid Item: force an immediate institution check via Plaid
 * /transactions/refresh (async — brand-new charges arrive later via the normal
 * SYNC_UPDATES_AVAILABLE webhook → sync), then immediately run sync_item() to pull
 * whatever Plaid already has plus a FRESH balance read, and rewrite the net-worth
 * snapshot once at the end.
 *
 * Body (JSON): { "item_id"?: string }
 *   - item_id given  → refresh just that Plaid item (Settings per-bank button)
 *   - item_id absent → refresh ALL live household Plaid items (Dashboard button)
 *
 * Scope is household-wide by design (no owner check): either user may refresh any
 * or all banks. sync_item()'s per-item advisory lock prevents a concurrent
 * webhook/cron from double-running; a soft per-item cooldown avoids hammering Plaid.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/plaid.php';
require __DIR__ . '/../lib/crypto.php';
require __DIR__ . '/../lib/sync.php';

header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid csrf token']);
    exit;
}

const REFRESH_COOLDOWN_SECONDS = 60;   // skip a just-synced item (avoid Plaid hammering)

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$itemId = trim((string)($in['item_id'] ?? ''));

$toRun = [];
try {
    $pdo = db();

    // Only live Plaid items have a token + feed to refresh (manual items are
    // updated by upload). Optionally narrow to one item. The cooldown age is computed
    // in SQL (TIMESTAMPDIFF against NOW()) — NEVER compare last_synced_at to PHP
    // time(): last_synced_at is written by MySQL NOW() (sync.php), and on this host
    // the MySQL server clock (EDT) differs from the app's PHP timezone (PDT), so a
    // PHP-vs-MySQL comparison would read every recent sync as ~3h in the future and
    // skip it. Keeping both sides on the MySQL clock makes it timezone-proof.
    $sql = "SELECT item_id, user_id, access_token_enc, transactions_cursor,
                   TIMESTAMPDIFF(SECOND, last_synced_at, NOW()) AS age_secs
            FROM items WHERE status <> 'removed' AND source = 'plaid'";
    $params = [];
    if ($itemId !== '') { $sql .= ' AND item_id = ?'; $params[] = $itemId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    if (!$items) {
        http_response_code($itemId !== '' ? 404 : 200);
        echo json_encode($itemId !== ''
            ? ['error' => 'no such Plaid account']
            : ['ok' => true, 'started' => false, 'queued' => 0, 'skipped' => 0,
               'note' => 'No linked banks to refresh.']);
        exit;
    }

    // Decide synchronously which items to run (60s soft cooldown) so we can ack the
    // exact count to the browser BEFORE the slow work starts. age_secs is NULL for a
    // never-synced item (→ run) and seconds-since-last-sync otherwise.
    $skipped = 0;
    foreach ($items as $item) {
        $age = $item['age_secs'];
        if ($age !== null && (int)$age >= 0 && (int)$age < REFRESH_COOLDOWN_SECONDS) { $skipped++; continue; }
        $toRun[] = $item;
    }

    $started = count($toRun) > 0;
    echo json_encode([
        'ok'      => true,
        'started' => $started,
        'queued'  => count($toRun),
        'skipped' => $skipped,
        'note'    => $started
            ? 'Refreshing your banks in the background — new transactions will appear shortly.'
            : 'Already up to date — try again in a moment.',
    ]);
} catch (Throwable $ex) {
    error_log('refresh ack error: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not refresh right now.']);
    exit;
}

// ---- Ack is sent; now do the slow work AFTER the connection closes ----------
// /transactions/refresh + a full sync of every Item runs sequentially (~1 min for
// several banks). We finish the HTTP response first so the click is instant — the
// user is told via a toast that data lands shortly (and brand-new charges arrive via
// the SYNC_UPDATES_AVAILABLE webhook regardless). session_write_close() FIRST so we
// don't hold the session lock during the long run (which would block the user's other
// page loads). Same PHP-FPM fastcgi_finish_request technique webhook.php uses.
if (!empty($toRun)) {
    session_write_close();
    ignore_user_abort(true);
    @set_time_limit(0);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) ob_end_flush();
        flush();
    }

    $didSync = false;
    foreach ($toRun as $item) {
        try {
            $token = decrypt_secret($item['access_token_enc']);
            if ($token === null) continue;

            // Kick Plaid to check the bank now (async). A broken connection
            // (ITEM_LOGIN_REQUIRED etc.) throws — the sync below still records the
            // item's real status.
            try { plaid_refresh_transactions($token); }
            catch (PlaidException $pe) { /* note: sync_item will mark the Item */ }

            // Pull what's available now + fresh balances (reuses the cursor loop,
            // advisory lock, large-tx alerts, per-item error handling). Only count it
            // as a real sync (→ snapshot worth rewriting) if it actually ran — not a
            // lock-skip or a failure.
            $r = sync_item($pdo, $item, 'manual');
            if (!empty($r['ok']) && empty($r['skipped'])) $didSync = true;
        } catch (Throwable $e) {
            // One bad bank can't abort the rest.
            error_log('refresh item ' . ($item['item_id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    // Recompute the household net-worth snapshot once (only if we actually synced).
    if ($didSync) {
        try { write_networth_snapshot($pdo); }
        catch (Throwable $e) { error_log('refresh snapshot: ' . $e->getMessage()); }
    }
}
