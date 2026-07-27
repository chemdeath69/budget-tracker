<?php
/**
 * Events / trips CRUD (migration 035). Unlike goals/budgets/rules, an event is NOT purely
 * household-shared: it carries its own visibility, and MANAGEMENT is creator-only.
 *
 *   GET                                                → q_events() (EVIS-scoped list)
 *   POST {id?, name, visibility?, start_date?, end_date?, note?}
 *        id absent/0 → INSERT (created_by = uid, so the creator manages it)
 *        id > 0      → UPDATE, gated created_by = uid (403 otherwise)
 *   DELETE {id}                                        → remove, gated created_by = uid
 *
 * Attaching/detaching TRANSACTIONS is deliberately NOT here — that's a per-transaction
 * annotation like a tag, so it lives beside add_tag/remove_tag in api/account.php
 * (`add_to_event` / `remove_from_event`, gated on can-you-see-this-tx, not on ownership).
 *
 * Flipping shared→private is non-destructive: other members' attachments are KEPT, they
 * just stop being visible to them. Flipping back restores the event exactly as it was.
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
    echo json_encode(q_events($pdo, $uid), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

// State-changing methods require a valid CSRF token (header from app.js' postJSON).
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid csrf token']);
    exit;
}
access_log_action($pdo, $uid, 'events', strtolower($method));   // audit (best-effort)

/** Strict Y-m-d (round-trips, so "2026-02-31"/garbage is rejected), '' → null. */
$ymd = static function ($v): array {
    $s = trim((string)($v ?? ''));
    if ($s === '') return [true, null];
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    return ($dt !== false && $dt->format('Y-m-d') === $s) ? [true, $s] : [false, null];
};

/** The creator gate for rename / dates / note / visibility / delete. Returns an error
 *  string, or null when $uid may manage the event. A row the viewer can't even SEE gets
 *  the same "not found" as a missing one, so a private event never confirms it exists. */
$requireCreator = static function (PDO $pdo, int $uid, int $id): ?string {
    $st = $pdo->prepare('SELECT created_by, visibility FROM events WHERE event_id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return 'not_found';
    if ((int)$row['created_by'] !== $uid) {
        return ($row['visibility'] === 'shared') ? 'not_creator' : 'not_found';
    }
    return null;
};

try {
    if ($method === 'POST') {
        $id   = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'a name is required']);
            exit;
        }

        $visibility = in_array($in['visibility'] ?? '', ['shared', 'private'], true)
            ? (string)$in['visibility'] : 'shared';

        [$okFrom, $start] = $ymd($in['start_date'] ?? '');
        [$okTo,   $end]   = $ymd($in['end_date'] ?? '');
        if (!$okFrom || !$okTo) {
            http_response_code(400);
            echo json_encode(['error' => 'dates must be YYYY-MM-DD']);
            exit;
        }
        if ($start !== null && $end !== null && $end < $start) {
            http_response_code(400);
            echo json_encode(['error' => 'the end date cannot be before the start date']);
            exit;
        }

        $note = trim((string)($in['note'] ?? ''));
        $note = function_exists('mb_substr') ? mb_substr($note, 0, 500) : substr($note, 0, 500);
        $note = $note === '' ? null : $note;

        if ($id > 0) {
            $deny = $requireCreator($pdo, $uid, $id);
            if ($deny === 'not_found') { http_response_code(404); echo json_encode(['error' => 'event not found']); exit; }
            if ($deny !== null)        { http_response_code(403); echo json_encode(['error' => 'only the person who created this event can change it']); exit; }

            $st = $pdo->prepare(
                'UPDATE events SET name = :n, visibility = :v, start_date = :s, end_date = :e, note = :o
                 WHERE event_id = :id'
            );
            $st->execute([':n' => $name, ':v' => $visibility, ':s' => $start,
                          ':e' => $end, ':o' => $note, ':id' => $id]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO events (name, visibility, created_by, start_date, end_date, note)
                 VALUES (:n, :v, :by, :s, :e, :o)'
            );
            $st->execute([':n' => $name, ':v' => $visibility, ':by' => $uid,
                          ':s' => $start, ':e' => $end, ':o' => $note]);
            $id = (int)$pdo->lastInsertId();
        }
        // Echo the id + name so the caller can chain (the "＋ New event…" quick-create in the
        // per-transaction picker creates then immediately attaches).
        echo json_encode(['ok' => true, 'event' => ['id' => $id, 'name' => $name, 'visibility' => $visibility]],
                         JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'valid id required']);
            exit;
        }
        $deny = $requireCreator($pdo, $uid, $id);
        if ($deny === 'not_found') { http_response_code(404); echo json_encode(['error' => 'event not found']); exit; }
        if ($deny !== null)        { http_response_code(403); echo json_encode(['error' => 'only the person who created this event can delete it']); exit; }

        // event_transactions FKs event_id ON DELETE CASCADE → the memberships go with it.
        // The transactions themselves are never touched.
        $del = $pdo->prepare('DELETE FROM events WHERE event_id = ?');
        $del->execute([$id]);
        echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
        exit;
    }
} catch (Throwable $e) {
    error_log('api/events.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'could not save event']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
