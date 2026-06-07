<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/plaid.php';

header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'not authenticated']);
    exit;
}

try {
    // Stable per-user id for Plaid. Optional ?item_id=... triggers update mode.
    $clientUserId = 'user_' . current_user_id();

    $itemId = $_GET['item_id'] ?? null;
    if ($itemId) {
        require __DIR__ . '/../lib/crypto.php';
        $stmt = db()->prepare('SELECT access_token_enc, institution_id FROM items WHERE item_id = ? AND user_id = ?');
        $stmt->execute([$itemId, current_user_id()]);
        $row = $stmt->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'item not found']); exit; }
        $accessToken = decrypt_secret($row['access_token_enc']);

        // Only request consent for products this institution actually supports
        // (update mode rejects unsupported additional_consented_products).
        $supported = $row['institution_id'] ? plaid_institution_products($row['institution_id']) : [];
        $additional = array_values(array_intersect(['liabilities', 'investments'], $supported));

        $res = plaid_create_link_token($clientUserId, $accessToken, $additional);
    } else {
        $res = plaid_create_link_token($clientUserId);
    }
    echo json_encode(['link_token' => $res['link_token']]);
} catch (Throwable $ex) {
    error_log('link_token error: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not create link token']);
}
