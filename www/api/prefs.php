<?php
// Per-user preferences writer (UI redesign Phase 2; migration 030). POST JSON, auth+CSRF.
// Scoped to the CURRENT user — user_prefs is keyed by user_id (the viewer's own prefs,
// NOT household-shared, NOT VIS-scoped). Read-merge-write the single JSON blob so a future
// key (Phase 3's dashboard layout) is never clobbered by a theme-only save and vice-versa.
// Phase 2 accepts `theme` (light|dark|auto); unknown keys are ignored.
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';
require __DIR__ . '/../lib/dashboard.php';   // dash_sanitize_layout() for the dashboard branch (Phase 3)

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

try {
    $pdo = db();
    $uid = (int)current_user_id();
    $in  = json_decode(file_get_contents('php://input'), true) ?: [];
    access_log_action($pdo, $uid, 'prefs', 'save');   // audit (best-effort)

    // Read-merge-write: start from the stored blob so we only touch the given keys.
    $prefs = q_user_prefs($pdo, $uid);

    if (array_key_exists('theme', $in)) {
        $t = $in['theme'];
        if (!in_array($t, USER_PREF_THEMES, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'invalid theme']);
            exit;
        }
        $prefs['theme'] = $t;
    }

    // Dashboard layout (Phase 3) — the "Customize home" designer posts the full layout.
    // dash_sanitize_layout keeps only known widgets + valid sizes (the security boundary).
    if (array_key_exists('dashboard', $in)) {
        $layout = dash_sanitize_layout($in['dashboard']);
        if ($layout === null) {
            http_response_code(422);
            echo json_encode(['error' => 'invalid dashboard layout']);
            exit;
        }
        $prefs['dashboard'] = $layout;
    }

    $pdo->prepare(
        'INSERT INTO user_prefs (user_id, prefs)
         VALUES (:uid, :prefs)
         ON DUPLICATE KEY UPDATE prefs = VALUES(prefs), updated_at = CURRENT_TIMESTAMP'
    )->execute([
        ':uid'   => $uid,
        ':prefs' => json_encode($prefs, JSON_UNESCAPED_SLASHES),
    ]);

    echo json_encode(['ok' => true, 'prefs' => $prefs]);
} catch (Throwable $e) {
    error_log('api/prefs.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'could not save preferences']);
}
