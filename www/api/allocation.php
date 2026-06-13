<?php
/**
 * Per-security asset-class override (Session 62, TODO2 #32).
 *
 * Household-shared, **NO owner check** (like budgets/rules/goals/alerts — joint finances).
 * The target mix itself is edited by a plain CSRF form on allocation.php; this endpoint only
 * handles the per-holding class override (a `.class-select` → reload).
 *
 *   action=set_class {security_id, asset_class}
 *     asset_class ∈ ALLOC_CLASSES → upsert; '' or 'auto' → delete the override (revert to auto).
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/allocation.php';   // ALLOC_CLASSES / alloc_valid_class()

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

try {
    if (($in['action'] ?? '') === 'set_class') {
        $sid = trim((string)($in['security_id'] ?? ''));
        $cls = (string)($in['asset_class'] ?? '');
        if ($sid === '') { http_response_code(400); echo json_encode(['error' => 'missing security_id']); exit; }

        if ($cls === '' || $cls === 'auto') {
            $pdo->prepare('DELETE FROM security_asset_class WHERE security_id = ?')->execute([$sid]);
            echo json_encode(['ok' => true, 'asset_class' => 'auto']);
            exit;
        }
        if (!alloc_valid_class($cls)) {
            http_response_code(400);
            echo json_encode(['error' => 'unknown asset class']);
            exit;
        }
        $pdo->prepare(
            'INSERT INTO security_asset_class (security_id, asset_class, updated_by)
             VALUES (:sid, :cls, :by)
             ON DUPLICATE KEY UPDATE asset_class = VALUES(asset_class),
                                     updated_by  = VALUES(updated_by),
                                     updated_at  = CURRENT_TIMESTAMP'
        )->execute([':sid' => $sid, ':cls' => $cls, ':by' => $uid]);
        echo json_encode(['ok' => true, 'asset_class' => $cls]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    error_log('api/allocation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server error']);
}
