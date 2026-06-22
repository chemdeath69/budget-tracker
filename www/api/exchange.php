<?php
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

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$publicToken = $in['public_token'] ?? '';
$institution = $in['institution'] ?? [];
access_log_action(db(), (int)current_user_id(), 'exchange', 'link_bank',
    is_array($institution) ? ($institution['name'] ?? null) : null);   // audit (best-effort)

if (!$publicToken) {
    http_response_code(400);
    echo json_encode(['error' => 'missing public_token']);
    exit;
}

try {
    $res = plaid_exchange_public_token($publicToken);
    $accessToken = $res['access_token'];
    $itemId      = $res['item_id'];

    $pdo = db();
    $pdo->prepare(
        'INSERT INTO items (item_id, user_id, institution_id, institution_name, access_token_enc, status)
         VALUES (:id,:uid,:iid,:iname,:tok,"active")
         ON DUPLICATE KEY UPDATE
             institution_id=VALUES(institution_id), institution_name=VALUES(institution_name),
             access_token_enc=VALUES(access_token_enc), status="active", error_code=NULL'
    )->execute([
        ':id'    => $itemId,
        ':uid'   => current_user_id(),
        ':iid'   => $institution['institution_id'] ?? null,
        ':iname' => $institution['name'] ?? null,
        ':tok'   => encrypt_secret($accessToken),
    ]);

    // Initial sync (balances + transactions history + extras), then snapshot.
    $item = $pdo->query('SELECT item_id, user_id, access_token_enc, transactions_cursor FROM items WHERE item_id = ' . $pdo->quote($itemId))->fetch();
    sync_item($pdo, $item, 'manual');
    write_networth_snapshot($pdo);

    echo json_encode(['ok' => true, 'item_id' => $itemId]);
} catch (Throwable $ex) {
    error_log('exchange error: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not link the account.']);
}
