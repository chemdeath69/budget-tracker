<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';     // the VIS-scoped q_*() helpers the tools wrap
require __DIR__ . '/../lib/retirement.php';  // build_retirement_view (get_retirement tool)
require __DIR__ . '/../lib/bills.php';       // bill_occurrences (get_upcoming_bills tool)
require __DIR__ . '/../lib/forecast.php';    // forecast_build (get_cash_forecast tool)
require __DIR__ . '/../lib/safe_to_spend.php'; // safe_to_spend_build (get_safe_to_spend tool)
require __DIR__ . '/../lib/debt.php';        // build_debt_plan (get_debt_plan tool)
require __DIR__ . '/../lib/home_value.php';   // hv_zip_from_address (get_property → build_property_view)
require __DIR__ . '/../lib/property_view.php';// build_property_view (get_property tool)
require __DIR__ . '/../lib/allocation.php';   // build_allocation_view (get_allocation tool)
require __DIR__ . '/../lib/fees.php';         // build_fees_view (get_fees tool)
require __DIR__ . '/../lib/returns.php';      // ret_position / ret_bench_lookup (get_security tool)
require __DIR__ . '/../lib/peers.php';        // build_peer_view (get_peer_comparison tool)
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

// Cross-request spend ceiling (code review 4.3) — before we do any paid work.
if (assistant_rate_limited($pdo, (int)$uid, $CONFIG)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => "You've reached the assistant's question limit for now. Please try again later."]);
    exit;
}

$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];
access_log_action($pdo, (int)$uid, 'assistant', 'ask');   // audit (best-effort; question text not logged)

// Don't hold the per-session file lock for the whole multi-second agentic loop (code review
// 4.1) — it would block the user's other tabs. Everything we need from the session is read.
session_write_close();

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
