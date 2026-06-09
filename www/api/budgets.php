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

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Budgets (shared household) + current-month spend per category (household-wide).
    $month = date('Y-m');
    $spendRows = $pdo->prepare(
        "SELECT COALESCE(t.category_override, t.pfc_primary, 'UNCATEGORIZED') AS category,
                SUM(t.amount) AS spent
         FROM transactions t
         WHERE t.pending = 0 AND t.amount > 0
           AND DATE_FORMAT(t.date, '%Y-%m') = :m
         GROUP BY category"
    );
    $spendRows->execute([':m' => $month]);
    $spent = [];
    foreach ($spendRows->fetchAll() as $r) $spent[$r['category']] = (float)$r['spent'];

    $budgets = $pdo->query('SELECT id, category, monthly_limit FROM budgets ORDER BY category')->fetchAll();
    foreach ($budgets as &$b) {
        $b['monthly_limit'] = (float)$b['monthly_limit'];
        $b['spent'] = round($spent[$b['category']] ?? 0, 2);
    }
    echo json_encode(['month' => $month, 'budgets' => $budgets], JSON_UNESCAPED_SLASHES);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

// State-changing methods require a valid CSRF token (header from app.js' postJSON).
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid csrf token']);
    exit;
}

if ($method === 'POST') {
    $category = strtoupper(trim((string)($in['category'] ?? '')));
    $limit    = (float)($in['monthly_limit'] ?? 0);
    if ($category === '' || $limit <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'category and positive monthly_limit required']);
        exit;
    }
    // Shared, recurring monthly budget (effective_month NULL). MySQL treats each
    // NULL as distinct in the unique key, so ON DUPLICATE KEY UPDATE never fires
    // for the NULL row — that would silently insert a duplicate. Update the NULL
    // row(s) explicitly, then insert only when none exists. (rowCount() is not
    // reliable here: an unchanged value reports 0 affected rows.)
    $upd = $pdo->prepare(
        'UPDATE budgets SET monthly_limit = :l WHERE category = :c AND effective_month IS NULL'
    );
    $upd->execute([':c' => $category, ':l' => $limit]);
    $has = $pdo->prepare('SELECT 1 FROM budgets WHERE category = :c AND effective_month IS NULL LIMIT 1');
    $has->execute([':c' => $category]);
    if (!$has->fetchColumn()) {
        $pdo->prepare('INSERT INTO budgets (category, monthly_limit, effective_month) VALUES (:c,:l,NULL)')
            ->execute([':c' => $category, ':l' => $limit]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'valid id required']);
        exit;
    }
    $del = $pdo->prepare('DELETE FROM budgets WHERE id = ?');
    $del->execute([$id]);
    echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
