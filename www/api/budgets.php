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
    // Reuse the canonical q_budgets() so this read can't drift from the
    // server-rendered spending.php page (it JOINs accounts/items and excludes
    // hidden accounts + manual ext_source feeds — which an inline query here did not).
    require_once __DIR__ . '/../lib/queries.php';
    echo json_encode(q_budgets($pdo), JSON_UNESCAPED_SLASHES);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

// State-changing methods require a valid CSRF token (header from app.js' postJSON).
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid csrf token']);
    exit;
}
access_log_action($pdo, (int)current_user_id(), 'budgets', strtolower($method));   // audit (best-effort)

if ($method === 'POST') {
    // Clamp/validate before touching the DB (code review 4.5) so garbage input returns a
    // clean 400/500 JSON instead of a blank 500 (VARCHAR(96) overflow, json INF, etc.) —
    // matching the {ok:false} contract every sibling endpoint honors.
    $category = strtoupper(trim((string)($in['category'] ?? '')));
    $category = mb_substr($category, 0, 96);                 // budgets.category is VARCHAR(96)
    $limit    = (float)($in['monthly_limit'] ?? 0);
    $rollover = !empty($in['rollover']) ? 1 : 0;   // #11b — carry unspent forward
    if ($category === '' || !is_finite($limit) || $limit <= 0 || $limit > 9999999) {
        http_response_code(400);
        echo json_encode(['error' => 'category and a positive monthly_limit (≤ 9,999,999) required']);
        exit;
    }
    // Shared, recurring monthly budget (effective_month NULL). MySQL treats each
    // NULL as distinct in the unique key, so ON DUPLICATE KEY UPDATE never fires
    // for the NULL row — that would silently insert a duplicate. Update the NULL
    // row(s) explicitly, then insert only when none exists. (rowCount() is not
    // reliable here: an unchanged value reports 0 affected rows.) Two concurrent
    // first-time POSTs for the same category could each see no row and both insert
    // (5.11) — serialize this category's upsert behind a short advisory lock.
    try {
        $lockName = 'bt_budget_' . substr(md5($category), 0, 16);
        $lk = $pdo->prepare('SELECT GET_LOCK(?, 2)');
        $lk->execute([$lockName]);
        $gotLock = ((int)$lk->fetchColumn() === 1);
        try {
            $upd = $pdo->prepare(
                'UPDATE budgets SET monthly_limit = :l, rollover = :r WHERE category = :c AND effective_month IS NULL'
            );
            $upd->execute([':c' => $category, ':l' => $limit, ':r' => $rollover]);
            $has = $pdo->prepare('SELECT 1 FROM budgets WHERE category = :c AND effective_month IS NULL LIMIT 1');
            $has->execute([':c' => $category]);
            if (!$has->fetchColumn()) {
                $pdo->prepare('INSERT INTO budgets (category, monthly_limit, rollover, effective_month) VALUES (:c,:l,:r,NULL)')
                    ->execute([':c' => $category, ':l' => $limit, ':r' => $rollover]);
            }
        } finally {
            if ($gotLock) { $rl = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $rl->execute([$lockName]); }
        }
    } catch (Throwable $e) {
        error_log('api/budgets POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'could not save the budget']);
        exit;
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
    try {
        $del = $pdo->prepare('DELETE FROM budgets WHERE id = ?');
        $del->execute([$id]);
    } catch (Throwable $e) {
        error_log('api/budgets DELETE: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'could not delete the budget']);
        exit;
    }
    echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
