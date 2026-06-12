<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';     // the VIS-scoped q_*() helpers the tools wrap
require __DIR__ . '/../lib/retirement.php';  // build_retirement_view (get_retirement tool)
require __DIR__ . '/../lib/bills.php';       // bill_occurrences (get_upcoming_bills tool)
require __DIR__ . '/../lib/assistant.php';

/**
 * Natural-language AI assistant (#27, Session 57). POST JSON {messages:[{role,content}…]}
 * ending with the user's new question. auth + CSRF; HOUSEHOLD-SCOPED to the caller's $uid
 * (so the assistant's read tools inherit the per-user visibility rule). Read-only — the
 * tool dispatcher is a hard whitelist that never writes anything. Returns the final reply
 * + the trail of tools it called. The tool-call/result round-trips are generated inside
 * assistant_respond() and are NOT persisted to the client (the next POST resends only the
 * visible user/assistant text turns).
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

global $CONFIG;
if (!assistant_enabled($CONFIG)) {
    http_response_code(503);
    echo json_encode(['error' => 'the assistant is not configured']);
    exit;
}

$pdo = db();
$uid = current_user_id();
$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];

try {
    $res = assistant_respond($pdo, $uid, $messages, $CONFIG);
    if (!$res['ok']) {
        // A controlled failure (bad input, transport) — surface the friendly message, not a 500.
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'could not answer that']);
        exit;
    }
    echo json_encode([
        'ok'    => true,
        'reply' => $res['reply'],
        'tools' => $res['tools'],
    ]);
} catch (Throwable $e) {
    error_log('api/assistant: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'the assistant hit an error']);
}
