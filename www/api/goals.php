<?php
/**
 * Savings goals CRUD (#9). Household-shared — NO owner check (like budgets/rules/alerts);
 * either user may add/edit/delete any goal. CSRF on every mutation.
 *
 *   GET                                   → q_goals() (canonical; matches budgets.php)
 *   POST {id?, name, target_amount, source, account_id?, current_amount?}
 *        source='account' → tie to account_id (must be visible to this user); current_amount NULL
 *        source='manual'  → account_id NULL; current_amount = entered value
 *        id>0 → UPDATE that goal, else INSERT (created_by = uid)
 *   DELETE {id}                           → remove
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';

header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'not authenticated']);
    exit;
}

$pdo    = db();
$uid    = (int)current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(q_goals($pdo, $uid), JSON_UNESCAPED_SLASHES);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

// State-changing methods require a valid CSRF token (header from app.js' postJSON).
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid csrf token']);
    exit;
}
access_log_action($pdo, $uid, 'goals', strtolower($method));   // audit (best-effort)

try {
    if ($method === 'POST') {
        $id     = (int)($in['id'] ?? 0);
        $name   = trim((string)($in['name'] ?? ''));
        if (function_exists('mb_substr')) { $name = mb_substr($name, 0, 96); } else { $name = substr($name, 0, 96); }
        $target = (float)($in['target_amount'] ?? 0);
        $source = (string)($in['source'] ?? 'manual');

        if ($name === '' || $target <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'name and a positive target are required']);
            exit;
        }

        $accountId = null;
        $current   = null;
        if ($source === 'account') {
            $accountId = trim((string)($in['account_id'] ?? ''));
            // Verify the account exists AND is visible to this user (VIS clause) so a goal can't
            // be tied to an account the user can't see.
            if ($accountId === '' || q_account($pdo, $uid, $accountId) === null) {
                http_response_code(400);
                echo json_encode(['error' => 'choose a valid account']);
                exit;
            }
        } else {
            $current = max(0, (float)($in['current_amount'] ?? 0));
        }

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE goals SET name = :n, target_amount = :t, account_id = :a, current_amount = :c
                 WHERE id = :id'
            );
            $st->execute([':n' => $name, ':t' => $target, ':a' => $accountId, ':c' => $current, ':id' => $id]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO goals (name, target_amount, account_id, current_amount, created_by)
                 VALUES (:n, :t, :a, :c, :by)'
            );
            $st->execute([':n' => $name, ':t' => $target, ':a' => $accountId, ':c' => $current, ':by' => $uid]);
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
        $del = $pdo->prepare('DELETE FROM goals WHERE id = ?');
        $del->execute([$id]);
        echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
        exit;
    }
} catch (Throwable $e) {
    error_log('api/goals.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'could not save goal']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
