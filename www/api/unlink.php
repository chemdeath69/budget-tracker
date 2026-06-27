<?php
/**
 * Remove an account source — owner-only, DESTRUCTIVE. Works for BOTH a Plaid bank
 * AND a manual account / vehicle / 401(k) (every `source='manual'` item).
 *
 * Body (JSON): { "item_id": string }
 *
 * Steps:
 *   1. (Plaid only) Revoke the Item at Plaid (/item/remove) so it stops syncing +
 *      billing. The benign "already gone" codes are treated as success; any other
 *      Plaid / transport failure ABORTS (we keep our row so the user can retry, rather
 *      than orphan a still-active Plaid item we can no longer see). A manual item has
 *      no token, so this step is skipped.
 *   2. Permanently DELETE the Item, its accounts, and ALL their child rows.
 *      There are NO ON DELETE cascades on the items→accounts→children chain, so the
 *      deletes are issued explicitly, child-first, inside one transaction. (The side
 *      tables transaction_tags / transaction_splits / refund_watch DO cascade off
 *      transactions, so deleting transactions clears them.)
 *   3. Rewrite the household net-worth snapshot so the removal shows immediately.
 *
 * Owner-only (mirrors rename/visibility/cadence): only the user who linked/created the
 * Item may remove it — EXCEPT an admin may remove another member's Item when EVERY account
 * on it is shared (so the admin can see everything that will be deleted; never silently
 * destroys a private/hidden account they can't see). Mirrors the shared-only admin re-link
 * scope (Session 95). settings.php's "Other members' banks" section surfaces the button only
 * for all-shared items, but the server enforces it regardless.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/plaid.php';
require __DIR__ . '/../lib/crypto.php';
require __DIR__ . '/../lib/sync.php';   // write_networth_snapshot()

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

$pdo    = db();
$uid    = (int)current_user_id();
$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$itemId = trim((string)($in['item_id'] ?? ''));
access_log_action($pdo, $uid, 'unlink', 'remove', $itemId !== '' ? $itemId : null);   // audit (best-effort)

if ($itemId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing item_id']);
    exit;
}

// Load the Item + verify ownership (only the linker may remove it).
$st = $pdo->prepare(
    'SELECT item_id, user_id, source, access_token_enc, institution_name
     FROM items WHERE item_id = ?'
);
$st->execute([$itemId]);
$item = $st->fetch();
if (!$item) { http_response_code(404); echo json_encode(['error' => 'account not found']); exit; }
if ((int)$item['user_id'] !== $uid) {
    // An admin may remove another member's bank ONLY when every account on the Item is
    // shared — so they can see everything that will be deleted (never silently destroy a
    // private/hidden account they can't see). Mirrors the shared-only admin re-link scope.
    $okAdmin = false;
    if (is_admin()) {
        $vc = $pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(visibility = 'shared') AS shared
             FROM accounts WHERE item_id = ?"
        );
        $vc->execute([$itemId]);
        $vrow = $vc->fetch();
        $okAdmin = (int)$vrow['total'] > 0 && (int)$vrow['total'] === (int)$vrow['shared'];
    }
    if (!$okAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'only the owner can remove this account']);
        exit;
    }
}
$institution = (string)($item['institution_name'] ?: 'this account');
$isPlaid     = (($item['source'] ?? 'plaid') === 'plaid');

// The accounts this Item owns (drives the per-account deletes + the response label).
// For a manual account the account's own (possibly renamed) name is the friendliest
// label — e.g. "F-150" / "Webull Brokerage"; for a Plaid bank the institution is.
$ac = $pdo->prepare(
    "SELECT account_id, COALESCE(NULLIF(display_name, ''), name) AS name
     FROM accounts WHERE item_id = ?"
);
$ac->execute([$itemId]);
$accountRows = $ac->fetchAll(PDO::FETCH_ASSOC);
$accountIds  = array_column($accountRows, 'account_id');
$label = $isPlaid
    ? $institution
    : (($accountRows[0]['name'] ?? '') !== '' ? (string)$accountRows[0]['name'] : $institution);

// 1) Revoke at Plaid (Plaid items only — a manual account has no token to revoke).
//    Benign "already gone" codes are fine; anything else aborts so we never delete our
//    copy while the token is still live + billing at Plaid.
if ($isPlaid) {
    $token = $item['access_token_enc'] !== null ? decrypt_secret($item['access_token_enc']) : null;
    if ($token) {
        try {
            plaid_item_remove($token);
        } catch (PlaidException $e) {
            $code = $e->plaidCode ?? '';
            if (!in_array($code, ['ITEM_NOT_FOUND', 'INVALID_ACCESS_TOKEN'], true)) {
                error_log('unlink: plaid /item/remove failed for ' . $itemId . ' — ' . $e->getMessage());
                http_response_code(502);
                echo json_encode(['error' => 'Could not revoke access at Plaid — please try again in a moment.']);
                exit;
            }
            // else: already gone at Plaid → proceed with the local delete.
        } catch (Throwable $e) {
            error_log('unlink: plaid /item/remove transport error for ' . $itemId . ' — ' . $e->getMessage());
            http_response_code(502);
            echo json_encode(['error' => 'Could not reach Plaid — please try again in a moment.']);
            exit;
        }
    }
}

// 2) Local purge — child-first, in one transaction.
try {
    $pdo->beginTransaction();

    if ($accountIds) {
        $ph = implode(',', array_fill(0, count($accountIds), '?'));
        // Order matters only in that transactions must precede accounts; the side
        // tables (tags/splits/refund_watch) cascade off transactions automatically.
        // Each row references accounts(account_id) with NO cascade, so delete each.
        foreach ([
            'transactions',
            'holdings',
            'investment_transactions',
            'liabilities',
            'recurring_streams',
            'manual_documents',
            'manual_tax_summaries',
            'retirement_statements',
            'account_balance_history',
            'vehicle_assets',
        ] as $tbl) {
            $pdo->prepare("DELETE FROM {$tbl} WHERE account_id IN ($ph)")->execute($accountIds);
        }
        // A savings goal tied to a now-deleted account: drop the tie but keep the
        // goal (q_goals renders an untied goal as "(account unavailable)").
        $pdo->prepare("UPDATE goals SET account_id = NULL WHERE account_id IN ($ph)")->execute($accountIds);
    }

    $pdo->prepare('DELETE FROM accounts    WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM sync_log    WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM webhook_log WHERE item_id = ?')->execute([$itemId]);
    $pdo->prepare('DELETE FROM items       WHERE item_id = ?')->execute([$itemId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('unlink: local delete failed for ' . $itemId . ' — ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The bank was disconnected at Plaid but its data could not be removed — please try again.']);
    exit;
}

// 3) Refresh the household net-worth snapshot so the dashboard reflects the removal.
try {
    write_networth_snapshot($pdo);
} catch (Throwable $e) {
    error_log('unlink: snapshot rewrite failed after removing ' . $itemId . ' — ' . $e->getMessage());
    // Non-fatal — the next sync/cron will rewrite it.
}

echo json_encode([
    'ok'       => true,
    'removed'  => $label,
    'accounts' => count($accountIds),
]);
