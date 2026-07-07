<?php
/**
 * User / allowlist management — ADMIN-ONLY (migration 032).
 *
 * Body (JSON): { action, ... }
 *   add        {email, role?}          — invite an email (pending until first login); re-enables a disabled one
 *   set_role   {id, role}              — admin | member
 *   set_status {id, status}            — active | disabled  (disabled = access revoked, data KEPT)
 *   delete     {id}                    — hard-remove a PENDING invite only (never logged in, owns nothing)
 *
 * Protections (so an admin can't lock the household out):
 *   - the config['allowed_emails'] break-glass accounts can't be demoted/disabled/deleted
 *   - you can't demote/disable/delete yourself
 *   - you can't demote/disable the last active admin
 *
 * auth + CSRF + is_admin(); returns the fresh roster (q_users) so the page re-renders.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';

header('Content-Type: application/json');

if (!is_logged_in())                       { http_response_code(401); echo json_encode(['error' => 'not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit; }
if (!csrf_check_request())                 { http_response_code(403); echo json_encode(['error' => 'invalid csrf token']); exit; }
if (!is_admin())                           { http_response_code(403); echo json_encode(['error' => 'administrators only']); exit; }

$pdo        = db();
$uid        = (int)current_user_id();
$in         = json_decode(file_get_contents('php://input'), true) ?: [];
$action     = (string)($in['action'] ?? '');
$breakGlass = config_break_glass_emails();

access_log_action($pdo, $uid, 'users', $action !== '' ? $action : 'unknown',
    (string)($in['email'] ?? $in['id'] ?? ''));   // audit (best-effort)

/** Count of active admins (last-admin protection). */
$activeAdmins = static function () use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
};
/** Load one target row, or null. */
$loadTarget = static function (int $id) use ($pdo): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare(
        "SELECT u.id, u.email, u.role, u.status,
                (u.google_sub IS NOT NULL) AS has_logged_in,
                (SELECT COUNT(*) FROM items i WHERE i.user_id = u.id) AS item_count
         FROM users u WHERE u.id = :id"
    );
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
};
$fail = static function (int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
};
$ok = static function (?string $note = null) use ($pdo): void {
    echo json_encode(['ok' => true, 'note' => $note, 'users' => q_users($pdo)]);
    exit;
};

try {
    if ($action === 'add') {
        $email = strtolower(trim((string)($in['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fail(422, 'Enter a valid email address.');
        }
        $role = (($in['role'] ?? 'member') === 'admin') ? 'admin' : 'member';
        $st = $pdo->prepare('SELECT id, status, role FROM users WHERE email = :e');
        $st->execute([':e' => $email]);
        $ex = $st->fetch();
        if ($ex) {
            // Upsert semantics (code review 5.16): 'add' on an existing email applies the
            // requested role and re-enables a disabled account — the role was previously
            // ignored here. A demotion to member is guarded exactly as set_role is, so 'add'
            // can't strip the last admin / a break-glass account / your own admin role.
            $exId = (int)$ex['id'];
            if ($role === 'member') {
                if (in_array($email, $breakGlass, true)) {
                    $fail(422, 'This account is in the server config allowlist and is always an admin.');
                }
                if ($exId === $uid) $fail(422, 'You cannot remove your own admin role.');
                if (($ex['role'] ?? '') === 'admin' && ($ex['status'] ?? '') === 'active' && $activeAdmins() <= 1) {
                    $fail(422, 'There must be at least one admin.');
                }
            }
            if (($ex['status'] ?? '') === 'disabled') {
                $pdo->prepare("UPDATE users SET status = 'active', role = :r WHERE id = :id")
                    ->execute([':r' => $role, ':id' => $exId]);
                $ok('Existing user re-enabled as ' . $role . '.');
            }
            $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $exId]);
            $ok('That email is already a user — role set to ' . $role . '.');
        }
        $pdo->prepare("INSERT INTO users (email, role, status, added_by) VALUES (:e, :r, 'active', :by)")
            ->execute([':e' => $email, ':r' => $role, ':by' => $uid]);
        $ok('Invited. They can sign in with Google once OAuth allows their email (see the note above).');
    }

    if ($action === 'set_role') {
        $id = (int)($in['id'] ?? 0);
        $t  = $loadTarget($id);
        if (!$t) $fail(404, 'User not found.');
        $role = (($in['role'] ?? '') === 'admin') ? 'admin' : 'member';
        if ($role === 'member') {
            if (in_array(strtolower((string)$t['email']), $breakGlass, true)) {
                $fail(422, 'This account is in the server config allowlist and is always an admin.');
            }
            if ($id === $uid) $fail(422, 'You cannot remove your own admin role.');
            if ($t['role'] === 'admin' && $t['status'] === 'active' && $activeAdmins() <= 1) {
                $fail(422, 'There must be at least one admin.');
            }
        }
        $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $id]);
        $ok();
    }

    if ($action === 'set_status') {
        $id = (int)($in['id'] ?? 0);
        $t  = $loadTarget($id);
        if (!$t) $fail(404, 'User not found.');
        $status = (($in['status'] ?? '') === 'disabled') ? 'disabled' : 'active';
        if ($status === 'disabled') {
            if (in_array(strtolower((string)$t['email']), $breakGlass, true)) {
                $fail(422, 'This account is in the server config allowlist and cannot be disabled here.');
            }
            if ($id === $uid) $fail(422, 'You cannot disable your own access.');
            if ($t['role'] === 'admin' && $t['status'] === 'active' && $activeAdmins() <= 1) {
                $fail(422, 'There must be at least one active admin.');
            }
        }
        $pdo->prepare('UPDATE users SET status = :s WHERE id = :id')->execute([':s' => $status, ':id' => $id]);
        $ok($status === 'disabled' ? 'Access revoked — their data is kept.' : 'Access restored.');
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        $t  = $loadTarget($id);
        if (!$t) $fail(404, 'User not found.');
        if ($id === $uid) $fail(422, 'You cannot delete yourself.');
        if (in_array(strtolower((string)$t['email']), $breakGlass, true)) {
            $fail(422, 'This account is in the server config allowlist.');
        }
        if ((int)$t['has_logged_in'] === 1 || (int)$t['item_count'] > 0) {
            $fail(422, 'Only a pending invite (never signed in, owns no accounts) can be deleted — disable this user instead.');
        }
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
        $ok('Pending invite removed.');
    }

    $fail(400, 'Unknown action.');
} catch (Throwable $e) {
    error_log('api/users: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server error']);
}
