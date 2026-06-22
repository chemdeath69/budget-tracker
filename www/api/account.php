<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';   // normalize_tag() for the tag actions (#8)

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

$pdo = db();
$uid = current_user_id();
$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
access_log_action(db(), (int)$uid, 'account', $action !== '' ? $action : null);   // audit (best-effort)

/** True if $uid may see this transaction (shared OR owns the Item; excludes hidden) — the
 * same VIS rule q_transactions applies. Shared by the note/tag/split actions (#8) and
 * mirrors the inline check recategorize uses. */
function tx_visible(PDO $pdo, string $txId, int $uid): bool
{
    if ($txId === '') return false;
    $st = $pdo->prepare(
        'SELECT 1 FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE t.transaction_id = ?
           AND (a.visibility <> "hidden" AND (a.visibility = "shared" OR i.user_id = ?))'
    );
    $st->execute([$txId, $uid]);
    return (bool)$st->fetchColumn();
}

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
    if ($cat !== null) {
        $cat = substr($cat, 0, 96);                       // parity with custom_categories tag cap
        // A transfer/income category would silently DROP this tx from every true-expense
        // aggregation (EFF_CAT resolution). Mirror the api/rules.php + set_splits guards.
        if (in_array($cat, RULE_CAT_BLOCKED, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'That category can\'t be assigned to a transaction.']);
            exit;
        }
    }

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

if ($action === 'set_note') {
    // Free-text note (#8) on a transaction. Stored in transactions.note (NOT in the
    // sync UPSERT, so it survives re-sync like category_override). Blank → NULL.
    $txId = (string)($in['transaction_id'] ?? '');
    $note = trim((string)($in['note'] ?? ''));
    $note = function_exists('mb_substr') ? mb_substr($note, 0, 500) : substr($note, 0, 500);
    $store = $note === '' ? null : $note;

    if (!tx_visible($pdo, $txId, $uid)) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $pdo->prepare('UPDATE transactions SET note = ? WHERE transaction_id = ?')->execute([$store, $txId]);
    echo json_encode(['ok' => true, 'note' => $store]);
    exit;
}

if ($action === 'add_tag') {
    // Attach a free-form tag (#8). Auto-creates the tag in the shared household
    // vocabulary (INSERT IGNORE on the UNIQUE name), then links it to the tx.
    $txId = (string)($in['transaction_id'] ?? '');
    $name = normalize_tag((string)($in['tag'] ?? ''));
    if ($name === '') { http_response_code(400); echo json_encode(['error' => 'empty tag']); exit; }
    if (!tx_visible($pdo, $txId, $uid)) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $pdo->prepare('INSERT IGNORE INTO tags (name, created_by) VALUES (?, ?)')->execute([$name, $uid]);
    $g = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
    $g->execute([$name]);
    $tagId = (int)$g->fetchColumn();
    $pdo->prepare('INSERT IGNORE INTO transaction_tags (transaction_id, tag_id, created_by) VALUES (?, ?, ?)')
        ->execute([$txId, $tagId, $uid]);
    echo json_encode(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name]]);
    exit;
}

if ($action === 'remove_tag') {
    // Unlink a tag from a tx (#8). The tag stays in the vocabulary for reuse.
    $txId  = (string)($in['transaction_id'] ?? '');
    $tagId = (int)($in['tag_id'] ?? 0);
    if (!tx_visible($pdo, $txId, $uid)) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $pdo->prepare('DELETE FROM transaction_tags WHERE transaction_id = ? AND tag_id = ?')->execute([$txId, $tagId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'set_splits') {
    // Replace a transaction's split set (#8). Splits DRIVE the spend math (the
    // aggregation LEFT JOIN drops no remainder), so they must sum to the parent
    // amount. Only an expense (amount > 0) can be split. Empty array = un-split.
    $txId   = (string)($in['transaction_id'] ?? '');
    $splits = is_array($in['splits'] ?? null) ? $in['splits'] : [];
    if (!tx_visible($pdo, $txId, $uid)) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $pa = $pdo->prepare('SELECT amount FROM transactions WHERE transaction_id = ?');
    $pa->execute([$txId]);
    $parentRaw = $pa->fetchColumn();
    if ($parentRaw === false) { http_response_code(404); echo json_encode(['error' => 'transaction not found']); exit; }
    $parent = (float)$parentRaw;

    // A split sub-categorises an EXPENSE across spending categories. Disallow inflow/
    // transfer categories: the five true-expense aggregations exclude TRANSFER_IN/OUT
    // per-split, so a split tagged as a transfer would silently drop that slice of real
    // spend from cash-flow/trends/digest/anomaly/budget math (Session 30 review fix #2).
    $SPLIT_CAT_BLOCKED = ['TRANSFER_IN', 'TRANSFER_OUT', 'INCOME'];

    // Normalise + validate the rows.
    $clean = [];
    $sum   = 0.0;
    foreach ($splits as $s) {
        $cat = strtoupper(trim((string)($s['category'] ?? '')));
        $amt = round((float)($s['amount'] ?? 0), 2);
        $sn  = trim((string)($s['note'] ?? ''));
        $sn  = function_exists('mb_substr') ? mb_substr($sn, 0, 255) : substr($sn, 0, 255);
        if ($cat === '' || $amt <= 0) continue;
        if (in_array($cat, $SPLIT_CAT_BLOCKED, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'a split cannot use a transfer or income category', 'category' => $cat]);
            exit;
        }
        $clean[] = ['category' => $cat, 'amount' => $amt, 'note' => ($sn === '' ? null : $sn)];
        $sum += $amt;
    }

    if ($clean) {
        if ($parent <= 0) { http_response_code(422); echo json_encode(['error' => 'only an expense can be split']); exit; }
        if (count($clean) < 2) { http_response_code(422); echo json_encode(['error' => 'a split needs at least two parts']); exit; }
        if (abs($sum - $parent) >= 0.005) {
            http_response_code(422);
            echo json_encode(['error' => 'splits must sum to the transaction amount',
                              'expected' => round($parent, 2), 'got' => round($sum, 2)]);
            exit;
        }
    }

    // Replace atomically (delete existing → insert the new set; empty = un-split).
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM transaction_splits WHERE transaction_id = ?')->execute([$txId]);
        if ($clean) {
            $ins = $pdo->prepare(
                'INSERT INTO transaction_splits (transaction_id, category, amount, note, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($clean as $c) { $ins->execute([$txId, $c['category'], $c['amount'], $c['note'], $uid]); }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code(500);
        echo json_encode(['error' => 'could not save splits']);
        exit;
    }
    echo json_encode(['ok' => true, 'splits' => count($clean)]);
    exit;
}

if ($action === 'refund_flag') {
    // Flag a PURCHASE (#34) as "expecting a refund" → a pending refund_watch row. Only an
    // EXPENSE (amount > 0) can be flagged. Re-flagging a previously-received watch resets it
    // to pending. Gated by the same "can the user see this tx" VIS check as the other tx
    // annotations (NOT owner-only) — a refund flag is visibility-only, never touches spend.
    $txId = (string)($in['transaction_id'] ?? '');
    if (!tx_visible($pdo, $txId, $uid)) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $pa = $pdo->prepare('SELECT amount FROM transactions WHERE transaction_id = ?');
    $pa->execute([$txId]);
    $amt = $pa->fetchColumn();
    if ($amt === false) { http_response_code(404); echo json_encode(['error' => 'transaction not found']); exit; }
    if ((float)$amt <= 0) { http_response_code(422); echo json_encode(['error' => 'only a purchase (expense) can expect a refund']); exit; }

    $pdo->prepare(
        'INSERT INTO refund_watch (transaction_id, status, created_by)
         VALUES (?, \'pending\', ?)
         ON DUPLICATE KEY UPDATE status = \'pending\', matched_tx_id = NULL, resolved_at = NULL'
    )->execute([$txId, $uid]);
    echo json_encode(['ok' => true, 'status' => 'pending']);
    exit;
}

if ($action === 'refund_unflag') {
    // Remove the refund flag entirely (#34) — "no longer expecting / dismiss". DELETE the
    // watch row (the table is keyed by transaction_id).
    $txId = (string)($in['transaction_id'] ?? '');
    if (!tx_visible($pdo, $txId, $uid)) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $pdo->prepare('DELETE FROM refund_watch WHERE transaction_id = ?')->execute([$txId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'refund_resolve') {
    // Resolve a flagged purchase (#34): mark it received (optionally confirming the matching
    // CREDIT) or reopen it to pending. The watch must already exist. A confirmed matched_tx_id
    // must be a VIS-visible CREDIT (amount < 0) — so a forged POST can't pin a purchase or an
    // invisible row as the "refund". Visibility-only; never touches spend math.
    $txId   = (string)($in['transaction_id'] ?? '');
    $status = (string)($in['status'] ?? '');
    if (!in_array($status, ['pending', 'received'], true)) { http_response_code(400); echo json_encode(['error' => 'bad status']); exit; }
    if (!tx_visible($pdo, $txId, $uid)) { http_response_code(403); echo json_encode(['error' => 'not allowed']); exit; }

    $matchedId = null;
    if ($status === 'received') {
        $matchedId = trim((string)($in['matched_tx_id'] ?? ''));
        if ($matchedId === '') {
            $matchedId = null;                         // "mark received, no specific credit"
        } else {
            if ($matchedId === $txId) { http_response_code(422); echo json_encode(['error' => 'a purchase cannot be its own refund']); exit; }
            if (!tx_visible($pdo, $matchedId, $uid)) { http_response_code(422); echo json_encode(['error' => 'that credit is not visible to you']); exit; }
            $ma = $pdo->prepare('SELECT amount FROM transactions WHERE transaction_id = ?');
            $ma->execute([$matchedId]);
            $mAmt = $ma->fetchColumn();
            if ($mAmt === false || (float)$mAmt >= 0) { http_response_code(422); echo json_encode(['error' => 'the match must be a credit (money in)']); exit; }
            // A credit fulfils at most ONE purchase — the UI already excludes a used credit from
            // suggestions, but guard against a forged POST pinning it to two watches (review #34).
            $dup = $pdo->prepare('SELECT 1 FROM refund_watch WHERE matched_tx_id = ? AND transaction_id <> ?');
            $dup->execute([$matchedId, $txId]);
            if ($dup->fetchColumn()) { http_response_code(422); echo json_encode(['error' => 'that credit is already matched to another refund']); exit; }
        }
    }

    if ($status === 'received') {
        $upd = $pdo->prepare(
            'UPDATE refund_watch SET status = \'received\', matched_tx_id = ?, resolved_at = NOW()
             WHERE transaction_id = ?'
        );
        $upd->execute([$matchedId, $txId]);
    } else {
        $upd = $pdo->prepare(
            'UPDATE refund_watch SET status = \'pending\', matched_tx_id = NULL, resolved_at = NULL
             WHERE transaction_id = ?'
        );
        $upd->execute([$txId]);
    }
    if ($upd->rowCount() === 0) {
        // No change can mean the row is unflagged (gone) or already in this exact state. Only
        // a truly-missing watch is an error; re-confirm existence to distinguish.
        $ex = $pdo->prepare('SELECT 1 FROM refund_watch WHERE transaction_id = ?');
        $ex->execute([$txId]);
        if (!$ex->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'not flagged for a refund']); exit; }
    }
    echo json_encode(['ok' => true, 'status' => $status, 'matched_tx_id' => $matchedId]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
