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

$uid = current_user_id();
$pdo = db();

/*
 * Visibility rule (mostly-joint, some-private):
 * a user sees an account when it is shared OR they own the Item that holds it.
 */
$vis = '(a.visibility <> "hidden" AND (a.visibility = "shared" OR i.user_id = :uid))';

/* ---- Accounts (visible) ---- */
$accts = $pdo->prepare(
    "SELECT a.account_id, a.name, a.official_name, a.mask, a.type, a.subtype,
            a.balance_available, a.balance_current, a.balance_limit,
            a.visibility, i.institution_name, i.user_id AS owner_id, a.item_id
     FROM accounts a JOIN items i ON a.item_id = i.item_id
     WHERE $vis
     ORDER BY i.institution_name, a.name"
);
$accts->execute([':uid' => $uid]);
$accounts = $accts->fetchAll();

/* ---- Net-worth stat (live, from visible accounts) ---- */
$assets = 0.0; $liabilities = 0.0;
foreach ($accounts as $a) {
    $bal = (float)($a['balance_current'] ?? 0);
    if (in_array($a['type'], ['credit', 'loan'], true)) {
        $liabilities += $bal;
    } else {
        $assets += $bal;
    }
}
$netWorth = $assets - $liabilities;

/* ---- Net-worth history (snapshots are household-wide) ---- */
$snaps = $pdo->query(
    "SELECT snapshot_date, net_worth FROM balance_snapshots
     ORDER BY snapshot_date ASC LIMIT 730"
)->fetchAll();

/* ---- Spending by category, last 30 days (Pacific), outflows only ---- */
$spend = $pdo->prepare(
    "SELECT COALESCE(t.category_override, t.pfc_primary, 'UNCATEGORIZED') AS category,
            SUM(t.amount) AS total
     FROM transactions t
     JOIN accounts a ON t.account_id = a.account_id
     JOIN items i ON a.item_id = i.item_id
     WHERE $vis AND t.pending = 0 AND t.amount > 0
       AND t.date >= (CURDATE() - INTERVAL 30 DAY)
     GROUP BY category
     ORDER BY total DESC"
);
$spend->execute([':uid' => $uid]);
$spending = $spend->fetchAll();

/* ---- Recent transactions (visible) ---- */
$txq = $pdo->prepare(
    "SELECT t.transaction_id, t.date, t.merchant_name, t.name, t.amount, t.pending,
            COALESCE(t.category_override, t.pfc_primary) AS category,
            a.name AS account_name, a.mask
     FROM transactions t
     JOIN accounts a ON t.account_id = a.account_id
     JOIN items i ON a.item_id = i.item_id
     WHERE $vis
     ORDER BY t.date DESC, t.imported_at DESC
     LIMIT 100"
);
$txq->execute([':uid' => $uid]);
$transactions = $txq->fetchAll();

/* ---- Liabilities (visible) ---- */
$liabq = $pdo->prepare(
    "SELECT l.liability_type, l.apr_percentage, l.outstanding_balance, l.last_payment_amount,
            l.last_payment_date, l.next_payment_due_date, l.minimum_payment_amount,
            a.name AS account_name, a.mask, a.balance_current
     FROM liabilities l
     JOIN accounts a ON l.account_id = a.account_id
     JOIN items i ON a.item_id = i.item_id
     WHERE $vis
     ORDER BY a.name"
);
$liabq->execute([':uid' => $uid]);
$liabilityRows = $liabq->fetchAll();

/* ---- Investment holdings (visible) ---- */
$holdq = $pdo->prepare(
    "SELECT s.ticker_symbol, s.name AS security_name, h.quantity, h.institution_price,
            h.institution_value, a.name AS account_name, a.mask
     FROM holdings h
     JOIN accounts a ON h.account_id = a.account_id
     JOIN items i ON a.item_id = i.item_id
     LEFT JOIN securities s ON h.security_id = s.security_id
     WHERE $vis
     ORDER BY h.institution_value DESC"
);
$holdq->execute([':uid' => $uid]);
$holdings = $holdq->fetchAll();

/* ---- Recurring streams (visible) ---- */
$recq = $pdo->prepare(
    "SELECT r.stream_id, r.direction, r.description, r.merchant_name, r.frequency,
            r.average_amount, r.last_amount, r.last_date, r.is_active, r.category_primary,
            a.name AS account_name, a.mask
     FROM recurring_streams r
     JOIN accounts a ON r.account_id = a.account_id
     JOIN items i ON a.item_id = i.item_id
     WHERE $vis AND r.is_active = 1
     ORDER BY r.direction, r.average_amount DESC"
);
$recq->execute([':uid' => $uid]);
$recurring = $recq->fetchAll();

echo json_encode([
    'user'        => current_user_email(),
    'user_id'     => $uid,
    'liabilities' => $liabilityRows,
    'holdings'    => $holdings,
    'recurring'   => $recurring,
    'stats'       => [
        'net_worth'   => round($netWorth, 2),
        'assets'      => round($assets, 2),
        'liabilities' => round($liabilities, 2),
        'accounts'    => count($accounts),
    ],
    'accounts'     => $accounts,
    'networth'     => $snaps,
    'spending'     => $spending,
    'transactions' => $transactions,
], JSON_UNESCAPED_SLASHES);
