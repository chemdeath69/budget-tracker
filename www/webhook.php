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
$pdo->prepare('INSERT INTO webhook_log (webhook_type, webhook_code, item_id, verified, payload) VALUES (?,?,?,?,?)')
    ->execute([$type, $code, $itemId, $verified ? 1 : 0, $raw]);

if (!$verified) {
    error_log('Rejected Plaid webhook: ' . $reason);
    http_response_code(401);
    echo json_encode(['error' => 'verification failed']);
    exit;
}

http_response_code(200);
echo json_encode(['received' => true]); // ack fast

if (!$itemId) exit;

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

if ($type === 'ITEM' && in_array($code, ['ERROR', 'PENDING_EXPIRATION', 'USER_PERMISSION_REVOKED'], true)) {
    require __DIR__ . '/lib/mailer.php';
    send_alert('Bank connection needs attention',
        "Plaid reported an ITEM $code for item $itemId. You may need to re-link this bank.");
    $pdo->prepare('UPDATE items SET status="error", error_code=? WHERE item_id=?')->execute([$code, $itemId]);
}
