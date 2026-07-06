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
require __DIR__ . '/../lib/home_value.php';   // hv_zip_from_address (get_property)
require __DIR__ . '/../lib/property_view.php';// build_property_view (get_property)
require __DIR__ . '/../lib/allocation.php';   // build_allocation_view (get_allocation)
require __DIR__ . '/../lib/fees.php';         // build_fees_view (get_fees)
require __DIR__ . '/../lib/returns.php';      // ret_position (get_security)
require __DIR__ . '/../lib/peers.php';        // build_peer_view (get_peer_comparison)
require __DIR__ . '/../lib/assistant.php';

/**
 * Streaming variant of api/assistant.php — SAME auth/CSRF/household-scope, but returns
 * Server-Sent Events so the chat UI can show a per-round status line ("Searching
 * transactions…") while the tool-use loop runs (the "status line" progress UX). The heavy
 * lifting is identical: `assistant_respond()` runs the same read-only, hard-whitelisted loop;
 * here we hand it a progress callback that emits an SSE `status` frame each tool round.
 *
 * Wire protocol (each frame `event: <name>\ndata: <json>\n\n`):
 *   open   — {ok:true}                              once, immediately (flushes the connection)
 *   status — {label:"…", tools:[…]}                 zero or more, one per tool round
 *   done   — {ok:true, reply:"…", tools:[…]}        exactly one terminal frame (success), OR
 *   error  — {error:"…"}                            exactly one terminal frame (failure)
 *
 * The client (app.js assistantStream) reads these; on ANY transport problem — this endpoint
 * missing (mid-rollout 404), a proxy that buffers/strips the stream, a parse failure — it falls
 * back to the non-streaming api/assistant.php, so streaming is purely additive. Because the body
 * is a stream we surface auth/method/CSRF failures as an `error` FRAME (HTTP 200) rather than an
 * HTTP status code, so the same client parser handles them.
 */

// SSE frames must not be buffered by PHP/FastCGI/the web server, or the status line only appears
// once the whole answer is ready (defeating the point). Best-effort un-buffering.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) @ob_end_flush();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');   // proxy hint: don't buffer this response

// ⚠️ This host (Apache + PHP-FCGI) buffers PHP output and flushes only when its buffer crosses a
// ~8 KB threshold (or at script end) — verified empirically: plain flush()ed frames all arrived
// together at the end, but a frame followed by ~8 KB of padding flushes immediately (frame1 @ +0s,
// frame2 @ +2s, …). So EACH frame is followed by an 8 KB SSE comment (`:` lines are ignored by the
// client) to push it past the threshold. Junk bytes only (a few tens of KB per question — trivial
// for a 2-user app); if a host ever streamed without it, it'd just be harmless extra comments.
const SSE_PAD = 8192;

/** Emit one SSE frame + padding, then flush it past the host's FCGI buffer to the client. */
$sse = function (string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    echo ': ' . str_repeat(' ', SSE_PAD) . "\n\n";   // padding comment → forces the buffer to flush
    @ob_flush();
    @flush();
};

if (!is_logged_in())                       { $sse('error', ['error' => 'not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $sse('error', ['error' => 'method not allowed']); exit; }
if (!csrf_check_request())                 { $sse('error', ['error' => 'invalid csrf token']); exit; }

global $CONFIG;
if (!assistant_enabled($CONFIG))           { $sse('error', ['error' => 'the assistant is not configured']); exit; }

$pdo = db();
$uid = current_user_id();
$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];
access_log_action($pdo, (int)$uid, 'assistant', 'ask');   // audit (best-effort; question text not logged)

// Don't hold the per-session file lock for the whole multi-second loop — it would block the
// user's other tabs/requests. We've read everything we need from the session already.
session_write_close();

// Open the stream right away so any intermediary flushes the first byte and the client knows
// streaming is live before the first (slow) model round returns.
$sse('open', ['ok' => true]);

$onProgress = function (array $tools) use ($sse) {
    $labels = array_values(array_unique(array_map('assistant_tool_label', $tools)));
    $sse('status', ['label' => implode(' · ', $labels), 'tools' => array_values($tools)]);
};

try {
    $res = assistant_respond($pdo, $uid, $messages, $CONFIG, $onProgress);
    if (!empty($res['ok'])) {
        $sse('done', ['ok' => true, 'reply' => $res['reply'], 'tools' => $res['tools']]);
    } else {
        // A controlled failure (bad input, transport) — surface the friendly message.
        $sse('error', ['error' => $res['error'] ?? 'could not answer that']);
    }
} catch (Throwable $e) {
    error_log('api/assistant_stream: ' . $e->getMessage());
    $sse('error', ['error' => 'the assistant hit an error']);
}
