<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';   // normalize_rule_value()

/**
 * Category rules (#10) — household-shared "always categorize merchant X as Y".
 * POST JSON, auth + CSRF, NO owner check (shared like budgets/alerts). Rules are
 * resolved at READ time by RULE_CAT (lib/queries.php), so there is no apply/backfill
 * step here — adding or deleting a rule changes the reads on the next page load.
 *   action=add    : {match_type:'merchant'|'contains', match_value, category}
 *   action=delete : {id}
 */
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

try {
    if ($action === 'add') {
        // Re-adding the same (type,value) updates its target via ON DUPLICATE KEY
        // (uq_rule). match_value is normalised the same way RULE_MATCH compares it.
        $type  = ($in['match_type'] ?? 'merchant') === 'contains' ? 'contains' : 'merchant';
        $value = normalize_rule_value((string)($in['match_value'] ?? ''));
        $cat   = strtoupper(trim((string)($in['category'] ?? '')));
        $cat   = function_exists('mb_substr') ? mb_substr($cat, 0, 96) : substr($cat, 0, 96);  // category_rules.category is VARCHAR(96)
        if ($value === '' || $cat === '') {
            http_response_code(400);
            echo json_encode(['error' => 'a match value and a category are required']);
            exit;
        }
        // A rule's target drives the true-expense reads (RULE_CAT is folded into EFF_CAT),
        // and those reads exclude TRANSFER_IN/OUT per category. A rule targeting a transfer/
        // income category would therefore silently DROP every matching merchant's spend from
        // cashflow/trends/digest/unusual-spend/budget math — bulk, retroactive, household-wide.
        // Block it on the write path (the only gate the DB trusts), mirroring the split guard
        // (api/account.php set_splits, Session 30 review fix #2).
        if (in_array($cat, RULE_CAT_BLOCKED, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'a rule cannot target a transfer or income category', 'category' => $cat]);
            exit;
        }
        $st = $pdo->prepare(
            'INSERT INTO category_rules (match_type, match_value, category, created_by)
             VALUES (:t, :v, :c, :u)
             ON DUPLICATE KEY UPDATE category = VALUES(category)'
        );
        $st->execute([':t' => $type, ':v' => $value, ':c' => $cat, ':u' => $uid]);
        $g = $pdo->prepare('SELECT id FROM category_rules WHERE match_type = ? AND match_value = ?');
        $g->execute([$type, $value]);
        echo json_encode(['ok' => true, 'rule' => [
            'id'          => (int)$g->fetchColumn(),
            'match_type'  => $type,
            'match_value' => $value,
            'category'    => $cat,
        ]]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'valid id required']);
            exit;
        }
        $del = $pdo->prepare('DELETE FROM category_rules WHERE id = ?');
        $del->execute([$id]);
        echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'could not save rule']);
}
