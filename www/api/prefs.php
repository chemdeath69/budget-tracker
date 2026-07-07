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

    // Build a PATCH of only the keys this request touches, then apply it server-side with
    // JSON_MERGE_PATCH (code review 5.12). The old read-merge-write raced across tabs — a
    // theme-only save read the blob, then wrote the WHOLE blob back, clobbering a concurrent
    // dashboard save (and vice-versa). Merging in a single statement removes the read step and
    // the race. NB MERGE_PATCH deletes keys set to JSON null; this API never patches a key to
    // null (theme is validated to a value, dashboard to a layout), so nothing is dropped.
    $patch = [];

    if (array_key_exists('theme', $in)) {
        $t = $in['theme'];
        if (!in_array($t, USER_PREF_THEMES, true)) {
            error_log('prefs.php 422 invalid theme uid=' . $uid . ' payload=' . substr((string)json_encode($in), 0, 300));
            http_response_code(422);
            echo json_encode(['error' => 'invalid theme']);
            exit;
        }
        $patch['theme'] = $t;
    }

    // Dashboard layout (Phase 3) — the "Customize home" designer posts the full layout.
    // dash_sanitize_layout keeps only known widgets + valid sizes (the security boundary).
    if (array_key_exists('dashboard', $in)) {
        $layout = dash_sanitize_layout($in['dashboard']);
        if ($layout === null) {
            error_log('prefs.php 422 invalid dashboard layout uid=' . $uid . ' payload=' . substr((string)json_encode($in), 0, 500));
            http_response_code(422);
            echo json_encode(['error' => 'invalid dashboard layout']);
            exit;
        }
        $patch['dashboard'] = $layout;
    }

    if ($patch) {
        // Distinct :patch/:patch2 bound to the same JSON — native prepares (emulation off)
        // reject a reused named placeholder (HY093).
        $json = json_encode($patch, JSON_UNESCAPED_SLASHES);
        $pdo->prepare(
            'INSERT INTO user_prefs (user_id, prefs)
             VALUES (:uid, :patch)
             ON DUPLICATE KEY UPDATE prefs = JSON_MERGE_PATCH(COALESCE(prefs, JSON_OBJECT()), :patch2),
                                     updated_at = CURRENT_TIMESTAMP'
        )->execute([':uid' => $uid, ':patch' => $json, ':patch2' => $json]);
    }

    // Echo the authoritative merged prefs (one small read, post-write).
    echo json_encode(['ok' => true, 'prefs' => q_user_prefs($pdo, $uid)]);
} catch (Throwable $e) {
    error_log('api/prefs.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'could not save preferences']);
}
