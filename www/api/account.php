<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';

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

$pdo = db();
$uid = current_user_id();
$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

if ($action === 'visibility') {
    $accountId  = (string)($in['account_id'] ?? '');
    // shared (default) · private (hidden from the other user, still counts) ·
    // hidden (registered nowhere but the owner's settings page — migration 008).
    $visibility = in_array($in['visibility'] ?? '', ['private', 'hidden'], true)
        ? $in['visibility'] : 'shared';

    // Only the owner of the Item that holds this account may change visibility.
    $own = $pdo->prepare(
        'SELECT i.user_id FROM accounts a JOIN items i ON a.item_id = i.item_id WHERE a.account_id = ?'
    );
    $own->execute([$accountId]);
    $ownerId = $own->fetchColumn();
    if ($ownerId === false) { http_response_code(404); echo json_encode(['error' => 'account not found']); exit; }
    if ((int)$ownerId !== $uid) { http_response_code(403); echo json_encode(['error' => 'only the owner can change visibility']); exit; }

    $pdo->prepare('UPDATE accounts SET visibility = ? WHERE account_id = ?')->execute([$visibility, $accountId]);
    echo json_encode(['ok' => true, 'visibility' => $visibility]);
    exit;
}

if ($action === 'retirement') {
    // Classify an account as retirement (Retirement page) or not. 'auto' = NULL =
    // classify by subtype/manual_type; 'yes' = 1 (force in); 'no' = 0 (force out).
    $accountId = (string)($in['account_id'] ?? '');
    $val = (string)($in['retirement'] ?? 'auto');
    $flag = $val === 'yes' ? 1 : ($val === 'no' ? 0 : null);

    // Owner-only (same as visibility).
    $own = $pdo->prepare(
        'SELECT i.user_id FROM accounts a JOIN items i ON a.item_id = i.item_id WHERE a.account_id = ?'
    );
    $own->execute([$accountId]);
    $ownerId = $own->fetchColumn();
    if ($ownerId === false) { http_response_code(404); echo json_encode(['error' => 'account not found']); exit; }
    if ((int)$ownerId !== $uid) { http_response_code(403); echo json_encode(['error' => 'only the owner can change this']); exit; }

    $pdo->prepare('UPDATE accounts SET retirement_flag = ? WHERE account_id = ?')->execute([$flag, $accountId]);
    echo json_encode(['ok' => true, 'retirement' => $val]);
    exit;
}

if ($action === 'cadence') {
    // Expected-statement cadence override for a manual account (migration 010).
    // 'auto' = NULL = default by type (401k→quarterly, other manual→monthly);
    // 'monthly'/'quarterly'/'annually' set it explicitly; 'off' = never warn.
    // Drives the dashboard "statements overdue" warning.
    $accountId = (string)($in['account_id'] ?? '');
    $val = (string)($in['cadence'] ?? 'auto');
    $store = in_array($val, ['monthly', 'quarterly', 'annually', 'off'], true) ? $val : null;

    // Owner-only (same as visibility / retirement / rename).
    $own = $pdo->prepare(
        'SELECT i.user_id FROM accounts a JOIN items i ON a.item_id = i.item_id WHERE a.account_id = ?'
    );
    $own->execute([$accountId]);
    $ownerId = $own->fetchColumn();
    if ($ownerId === false) { http_response_code(404); echo json_encode(['error' => 'account not found']); exit; }
    if ((int)$ownerId !== $uid) { http_response_code(403); echo json_encode(['error' => 'only the owner can change this']); exit; }

    $pdo->prepare('UPDATE accounts SET statement_cadence = ? WHERE account_id = ?')->execute([$store, $accountId]);
    echo json_encode(['ok' => true, 'cadence' => $store ?? 'auto']);
    exit;
}

if ($action === 'rename') {
    // Owner-set display-name override (migration 009). Shown everywhere the account
    // appears; a blank value clears it back to the original Plaid/manual name. Stored
    // in its own column so a Plaid sync never clobbers it (see lib/sync.php).
    $accountId = (string)($in['account_id'] ?? '');
    $name = trim((string)($in['name'] ?? ''));
    if (function_exists('mb_substr')) { $name = mb_substr($name, 0, 255); } else { $name = substr($name, 0, 255); }
    $display = $name === '' ? null : $name;   // blank → NULL → revert to original name

    // Owner-only (same as visibility / retirement).
    $own = $pdo->prepare(
        'SELECT i.user_id FROM accounts a JOIN items i ON a.item_id = i.item_id WHERE a.account_id = ?'
    );
    $own->execute([$accountId]);
    $ownerId = $own->fetchColumn();
    if ($ownerId === false) { http_response_code(404); echo json_encode(['error' => 'account not found']); exit; }
    if ((int)$ownerId !== $uid) { http_response_code(403); echo json_encode(['error' => 'only the owner can rename this']); exit; }

    $pdo->prepare('UPDATE accounts SET display_name = ? WHERE account_id = ?')->execute([$display, $accountId]);
    echo json_encode(['ok' => true, 'display_name' => $display]);
    exit;
}

if ($action === 'recategorize') {
    $txId = (string)($in['transaction_id'] ?? '');
    $cat  = trim((string)($in['category'] ?? ''));
    $cat  = $cat === '' ? null : strtoupper($cat);

    // The user must be able to see this transaction (shared OR owns the item).
    $vis = $pdo->prepare(
        'SELECT 1 FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE t.transaction_id = ?
           AND (a.visibility <> "hidden" AND (a.visibility = "shared" OR i.user_id = ?))'
    );
    $vis->execute([$txId, $uid]);
    if (!$vis->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $pdo->prepare('UPDATE transactions SET category_override = ? WHERE transaction_id = ?')
        ->execute([$cat, $txId]);
    echo json_encode(['ok' => true, 'category' => $cat]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
