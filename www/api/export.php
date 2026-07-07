<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';   // normalize_tag() for the tag filter (#8)

if (!is_logged_in()) {
    http_response_code(401);
    exit('not authenticated');
}

$uid = current_user_id();
$pdo = db();
access_log_action($pdo, (int)$uid, 'export', 'csv');   // audit (best-effort)

// Optional filters: ?from=YYYY-MM-DD&to=YYYY-MM-DD&q=text&account_id=...&category=TAG&tag=name
// Must stay in lock-step with q_transactions (lib/queries.php) so the CSV matches
// the on-screen filtered list it's exported from.
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;
$q    = trim((string)($_GET['q'] ?? ''));
$acct = trim((string)($_GET['account_id'] ?? ''));
$cat  = trim((string)($_GET['category'] ?? ''));
$tag  = trim((string)($_GET['tag'] ?? ''));
$merch = trim((string)($_GET['merchant'] ?? ''));
$amin = trim((string)($_GET['amin'] ?? ''));   // amount-range filter (#12b) — dollar magnitude
$amax = trim((string)($_GET['amax'] ?? ''));

// Validate ?from/?to as strict Y-m-d (round-trip so "2026-02-31"/garbage is rejected) → 400,
// rather than passing an arbitrary string into the query (code review 5.15).
$validYmd = static function ($d): bool {
    if (!is_string($d) || $d === '') return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
};
if ($from !== null && $from !== '' && !$validYmd($from)) { http_response_code(400); exit('invalid from date'); }
if ($to   !== null && $to   !== '' && !$validYmd($to))   { http_response_code(400); exit('invalid to date'); }

$where = ['(a.visibility <> "hidden" AND (a.visibility = "shared" OR i.user_id = :uid))'];
$params = [':uid' => $uid];
if ($from) { $where[] = 't.date >= :from'; $params[':from'] = $from; }
if ($to)   { $where[] = 't.date <= :to';   $params[':to']   = $to; }
// Amount magnitude range — same ABS(t.amount) semantics + is_numeric/is_finite guard as q_transactions
// (is_finite rejects "1e400" → INF, which MySQL would coerce to 0 and silently invert the filter).
if ($amin !== '' && is_numeric($amin) && is_finite((float)$amin)) { $where[] = 'ABS(t.amount) >= :amin'; $params[':amin'] = (float)$amin; }
if ($amax !== '' && is_numeric($amax) && is_finite((float)$amax)) { $where[] = 'ABS(t.amount) <= :amax'; $params[':amax'] = (float)$amax; }
if ($acct !== '') { $where[] = 't.account_id = :acct'; $params[':acct'] = $acct; }
if ($merch !== '') {
    // Exact merchant match (#5) — same display expression q_transactions' `merchant` opt uses,
    // so a CSV from a merchant-filtered view (the leaderboard click-through) matches the page.
    $where[] = "COALESCE(NULLIF(t.merchant_name, ''), t.name) = :merch";
    $params[':merch'] = $merch;
}
if ($cat !== '') {
    // 3-arg COALESCE mirrors q_transactions so an UNCATEGORIZED click-through matches;
    // RULE_CAT (#10) keeps a rule-driven category filterable; also match a SPLIT
    // category (#8) so a split-driven drill-through exports too.
    // Distinct placeholders (:cat / :cat_s) — native prepares (emulation off) reject a
    // reused named placeholder (HY093). Keep in lock-step with q_transactions.
    $where[] = "(COALESCE(t.category_override, " . RULE_CAT . ", t.pfc_primary, 'UNCATEGORIZED') = :cat
                 OR EXISTS (SELECT 1 FROM transaction_splits s
                            WHERE s.transaction_id = t.transaction_id AND s.category = :cat_s))";
    $params[':cat']   = $cat;
    $params[':cat_s'] = $cat;
}
if ($tag !== '') {
    $where[] = "EXISTS (SELECT 1 FROM transaction_tags tt JOIN tags tg ON tg.id = tt.tag_id
                        WHERE tt.transaction_id = t.transaction_id AND tg.name = :tag)";
    $params[':tag'] = normalize_tag($tag);
}
if ($q !== '') {
    // Escape the user's own LIKE metacharacters (% _ \) so a literal '_' (common in
    // PFC tags) or '%' doesn't over-match relative to the page (matches q_transactions).
    $term = addcslashes($q, '\\%_');
    // Distinct placeholders per occurrence — native prepares (emulation off) reject a
    // reused named placeholder (HY093). Keep in lock-step with q_transactions.
    $clauses = ["t.merchant_name LIKE :q1 ESCAPE '\\\\'",
                "t.name LIKE :q2 ESCAPE '\\\\'",
                "COALESCE(t.category_override, t.pfc_primary) LIKE :q3 ESCAPE '\\\\'"];
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $term . '%';
    // #12a: also match the account owner's first name (resolved in PHP via owner_ids_matching);
    // OR-only, distinct :qo* placeholders → HY093-safe. Keep in lock-step with q_transactions.
    foreach (owner_ids_matching($q) as $k => $oid) {
        $clauses[] = "i.user_id = :qo$k";
        $params[":qo$k"] = $oid;
    }
    $where[] = '(' . implode(' OR ', $clauses) . ')';
}
// Note + tags (#8) join the export. Tags are a per-tx GROUP_CONCAT correlated
// subquery (kept one row per parent transaction — splits stay parent-level here).
$sql = 'SELECT t.date, t.merchant_name, t.name, t.amount, t.iso_currency_code,
               COALESCE(t.category_override, ' . RULE_CAT . ', t.pfc_primary) AS category, t.pending, t.note,
               COALESCE(NULLIF(a.display_name, \'\'), a.name) AS account_name, a.mask, i.institution_name,
               (SELECT GROUP_CONCAT(tg.name ORDER BY tg.name SEPARATOR \';\')
                  FROM transaction_tags tt JOIN tags tg ON tg.id = tt.tag_id
                 WHERE tt.transaction_id = t.transaction_id) AS tags
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        JOIN items i ON a.item_id = i.item_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.date DESC';
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (Throwable $e) {
    error_log('api/export.php query failed: ' . $e->getMessage());
    http_response_code(500);
    exit('could not export transactions');
}

// CSV formula-injection guard (code review 5.15): a cell whose first char is =,+,-,@ or a
// control char (tab/CR/LF) is evaluated as a formula by Excel/Sheets. Merchant/description/
// category/account/institution/note/tags are external data, so prefix such cells with a single
// quote to force text. (Date/amount/currency/mask/pending are our own numeric/enum values.)
$csvSafe = static function ($v): string {
    $s = (string)($v ?? '');
    if ($s !== '' && strpos("=+-@\t\r\n", $s[0]) !== false) return "'" . $s;
    return $s;
};

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Merchant', 'Description', 'Amount', 'Currency', 'Category', 'Pending', 'Account', 'Mask', 'Institution', 'Note', 'Tags']);
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        $r['date'],
        $csvSafe($r['merchant_name']),
        $csvSafe($r['name']),
        $r['amount'],
        $r['iso_currency_code'],
        $csvSafe($r['category']),
        $r['pending'] ? 'yes' : 'no',
        $csvSafe($r['account_name']),
        $r['mask'],
        $csvSafe($r['institution_name']),
        $csvSafe($r['note']),
        $csvSafe($r['tags']),
    ]);
}
fclose($out);
