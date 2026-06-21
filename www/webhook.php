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

[$verified, $reason] = verify_plaid_webhook($raw);

$pdo = db();
// This endpoint is intentionally public (it verifies Plaid's signed JWT below instead of
// requiring login), and it logs EVERY hit — including unverified ones — before the bail-out.
// Cap the stored payload so an unauthenticated caller can't write arbitrarily large rows
// (storage amplification); a genuine Plaid webhook body is well under 16 KB.
$logPayload = strlen($raw) > 16384 ? substr($raw, 0, 16384) : $raw;
$pdo->prepare('INSERT INTO webhook_log (webhook_type, webhook_code, item_id, verified, payload) VALUES (?,?,?,?,?)')
    ->execute([$type, $code, $itemId, $verified ? 1 : 0, $logPayload]);

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
ignore_user_abort(true);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) ob_end_flush();
    flush();
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
    require __DIR__ . '/lib/mailer.php';
    // Honour the household alert toggles (Session 25, TODO #14) so this matches the
    // parallel connection-broken alert in sync.php — otherwise turning off connection
    // alerts (or the master email switch) on settings.php would silence sync.php's path
    // but not this one.
    $ac = alert_settings($pdo);
    if ($ac['email_enabled'] && $ac['connection_alert_enabled']) {
        send_alert('Bank connection needs attention',
            "Plaid reported an ITEM $code for item $itemId. You may need to re-link this bank.");
    }
    $pdo->prepare('UPDATE items SET status="error", error_code=? WHERE item_id=?')->execute([$code, $itemId]);
}
