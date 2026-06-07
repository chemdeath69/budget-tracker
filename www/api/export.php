<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('not authenticated');
}

$uid = current_user_id();
$pdo = db();

// Optional filters: ?from=YYYY-MM-DD&to=YYYY-MM-DD&q=text&account_id=...
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;
$q    = trim((string)($_GET['q'] ?? ''));
$acct = trim((string)($_GET['account_id'] ?? ''));

$where = ['(a.visibility = "shared" OR i.user_id = :uid)'];
$params = [':uid' => $uid];
if ($from) { $where[] = 't.date >= :from'; $params[':from'] = $from; }
if ($to)   { $where[] = 't.date <= :to';   $params[':to']   = $to; }
if ($acct !== '') { $where[] = 't.account_id = :acct'; $params[':acct'] = $acct; }
if ($q !== '') {
    $where[] = '(t.merchant_name LIKE :q OR t.name LIKE :q OR COALESCE(t.category_override,t.pfc_primary) LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$sql = 'SELECT t.date, t.merchant_name, t.name, t.amount, t.iso_currency_code,
               COALESCE(t.category_override, t.pfc_primary) AS category, t.pending,
               a.name AS account_name, a.mask, i.institution_name
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        JOIN items i ON a.item_id = i.item_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Merchant', 'Description', 'Amount', 'Currency', 'Category', 'Pending', 'Account', 'Mask', 'Institution']);
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        $r['date'],
        $r['merchant_name'],
        $r['name'],
        $r['amount'],
        $r['iso_currency_code'],
        $r['category'],
        $r['pending'] ? 'yes' : 'no',
        $r['account_name'],
        $r['mask'],
        $r['institution_name'],
    ]);
}
fclose($out);
