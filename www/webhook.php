<?php
/**
 * Plaid webhook receiver (no login). Verifies Plaid's signed JWT
 * (Plaid-Verification header) before acting. On SYNC_UPDATES_AVAILABLE /
 * transaction webhooks, runs an immediate sync for the affected Item.
 * Every hit is logged (with verified flag).
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/sync.php';
require __DIR__ . '/lib/plaid_webhook.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

$type   = $body['webhook_type'] ?? '';
$code   = $body['webhook_code'] ?? '';
$itemId = $body['item_id'] ?? null;
// item_id is stored in VARCHAR(64) columns (webhook_log/items); a >64-char value (real Plaid
// item_ids are well under this) would 500 the log insert before the ack. Clamp it (code review 5.18).
if (is_string($itemId)) $itemId = substr($itemId, 0, 64);

$pdo = db();
[$verified, $reason] = verify_plaid_webhook($raw, $pdo);   // $pdo → 24h key cache (code review 3.2)

// This endpoint is intentionally public (it verifies Plaid's signed JWT below instead of
// requiring login), and it logs EVERY hit — including unverified ones — before the bail-out,
// because the orphan-item detection (further down) counts these rows.
//
// The `payload` column is JSON, so a non-JSON or oversized body would make the INSERT throw
// → HTTP 500 BEFORE we can ack (code review 3.1). And an unauthenticated caller must not be
// able to store arbitrary large bodies (storage amplification, 3.2). So:
//   - unverified request        → log the type/code/verified=0 row, but payload = NULL
//   - verified, valid JSON <16KB → store the body verbatim
//   - verified but odd body      → store a small, always-valid envelope
$logPayload = null;
if ($verified) {
    json_decode($raw);
    $validJson = ($raw !== '' && json_last_error() === JSON_ERROR_NONE);
    if ($validJson && strlen($raw) <= 16384) {
        $logPayload = $raw;
    } elseif ($raw !== '') {
        $logPayload = json_encode(
            ['truncated' => true, 'bytes' => strlen($raw), 'prefix' => substr($raw, 0, 200)],
            JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
// Never let the log insert break the ack path (belt-and-braces try/catch).
try {
    $pdo->prepare('INSERT INTO webhook_log (webhook_type, webhook_code, item_id, verified, payload) VALUES (?,?,?,?,?)')
        ->execute([$type, $code, $itemId, $verified ? 1 : 0, $logPayload]);
} catch (Throwable $e) {
    error_log('webhook_log insert failed: ' . $e->getMessage());
}

if (!$verified) {
    error_log('Rejected Plaid webhook: ' . $reason);
    http_response_code(401);
    echo json_encode(['error' => 'verification failed']);
    exit;
}

http_response_code(200);
echo json_encode(['received' => true]); // ack fast

if (!$itemId) exit;

// Actually send the 200 to Plaid NOW, before the (potentially multi-second) sync
// runs — otherwise the body isn't flushed until the script ends and a slow sync
// can exceed Plaid's webhook timeout, triggering retries. Under PHP-FPM
// fastcgi_finish_request closes the request immediately; else flush the buffers.
// (Orphan detection + the syncs below all run AFTER this ack so none can delay it.)
ignore_user_abort(true);
@set_time_limit(0);   // a HISTORICAL_UPDATE backfill sync can outrun the web SAPI's limit (code review 3.8)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

// Untracked-item detection (Session 96). A VERIFIED webhook for an item_id that is
// NOT in our `items` table is an ORPHAN — almost always an abandoned Link session
// (Plaid created the Item but the public_token was never exchanged into our DB, so we
// never received its access_token). Plaid has no list-items API, so a webhook is the
// ONLY way we ever discover such an Item. We CANNOT clean it up programmatically:
// /item/remove requires the access_token, which Plaid never returns from an item_id.
// So the best we can do is surface it — log a warning + (once, on first sighting) email
// the household, gated on the connection-alert toggle. The full orphan list is shown on
// Activity → Sync status (q_orphan_webhook_items). The type handlers below would simply
// find no row and no-op, so we exit here.
$known = $pdo->prepare('SELECT 1 FROM items WHERE item_id = ?');
$known->execute([$itemId]);
if (!$known->fetchColumn()) {
    error_log("Plaid webhook for UNTRACKED item $itemId ($type/$code) — orphan; cannot /item/remove (no access token).");
    // Dedup to one alert per orphan: we just inserted this VERIFIED webhook_log row, so a
    // verified count of 1 for this item_id means this is its first-ever genuine sighting.
    // Count only verified=1 rows so an attacker can't pre-seed unverified rows to push the
    // count past 1 and suppress the first-sighting alert (code review 5.18).
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM webhook_log WHERE item_id = ? AND verified = 1');
    $cnt->execute([$itemId]);
    if ((int)$cnt->fetchColumn() === 1) {
        require_once __DIR__ . '/lib/mailer.php';
        $ac = alert_settings($pdo);
        if (!empty($ac['email_enabled']) && !empty($ac['connection_alert_enabled'])) {
            send_alert('Untracked Plaid item received a webhook',
                "Plaid sent a $type/$code webhook for item $itemId, which is not one of your linked banks.\n\n" .
                "This is usually a bank-link attempt that was started but never finished connecting. It can't be " .
                "removed automatically — Plaid only allows removing an Item with its access token, which we never " .
                "received. It's dormant and won't sync. See Activity → Sync status for the full list.");
        }
    }
    exit;
}

$triggers = ['SYNC_UPDATES_AVAILABLE', 'DEFAULT_UPDATE', 'INITIAL_UPDATE', 'HISTORICAL_UPDATE', 'TRANSACTIONS_REMOVED'];
if ($type === 'TRANSACTIONS' && in_array($code, $triggers, true)) {
    $item = $pdo->prepare('SELECT item_id, user_id, access_token_enc, transactions_cursor FROM items WHERE item_id = ?');
    $item->execute([$itemId]);
    $row = $item->fetch();
    if ($row) {
        sync_item($pdo, $row, 'webhook');
        write_networth_snapshot($pdo);
    }
}

// Investments: Plaid signals fresh holdings (HOLDINGS) or new investment
// transactions (INVESTMENTS_TRANSACTIONS). Re-pull the relevant feed for this Item.
// (#18 — this is what makes a Plaid brokerage's holdings, e.g. Betterment, refresh
// promptly instead of waiting for the nightly cron.) Token decrypted per the existing
// pattern; failures are logged, never fatal (the 200 is already flushed).
if ($type === 'HOLDINGS' && $code === 'DEFAULT_UPDATE') {
    $st = $pdo->prepare('SELECT item_id, access_token_enc FROM items WHERE item_id = ?');
    $st->execute([$itemId]);
    if ($row = $st->fetch()) {
        $tok = decrypt_secret($row['access_token_enc']);
        if ($tok !== null) {
            try { sync_investments($pdo, $itemId, $tok); write_networth_snapshot($pdo); }
            catch (Throwable $e) { if (!plaid_benign($e)) error_log('holdings webhook: ' . $e->getMessage()); }
        }
    }
}

if ($type === 'INVESTMENTS_TRANSACTIONS' && in_array($code, ['DEFAULT_UPDATE', 'HISTORICAL_UPDATE'], true)) {
    $st = $pdo->prepare('SELECT item_id, access_token_enc FROM items WHERE item_id = ?');
    $st->execute([$itemId]);
    if ($row = $st->fetch()) {
        $tok = decrypt_secret($row['access_token_enc']);
        if ($tok !== null) {
            try { sync_investment_transactions($pdo, $row, $tok); }
            catch (Throwable $e) { if (!plaid_benign($e)) error_log('invtx webhook: ' . $e->getMessage()); }
        }
    }
}

if ($type === 'ITEM' && in_array($code, ['ERROR', 'PENDING_EXPIRATION', 'USER_PERMISSION_REVOKED'], true)) {
    require_once __DIR__ . '/lib/mailer.php';
    // Route through send_connection_alert() so this shares ONE dedup with sync.php's
    // PlaidException branch (code review 3.3) — otherwise a broken bank emailed on every
    // webhook retry AND every nightly sync. It honours the household alert toggles too, so
    // it stays in lock-step with sync.php (turning off connection alerts silences both).
    $ac = alert_settings($pdo);
    send_connection_alert($pdo, (string)$itemId, $code, $ac);
    $pdo->prepare('UPDATE items SET status="error", error_code=? WHERE item_id=?')->execute([$code, $itemId]);
}
