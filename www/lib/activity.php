<?php
declare(strict_types=1);

/**
 * Activity logging — Access Logs + Sync Status (owner request, migration 029).
 *
 * A self-contained subsystem module (like lib/digest.php / lib/spend_alerts.php):
 *   - WRITERS for the access_log (login/logout/page/action) — best-effort, NEVER throw
 *     (a logging failure must never break a page render or abort the cron).
 *   - the sync-run CAPTURE api (sync_run_begin/step/finish) used by cron/sync.php so the
 *     nightly pipeline's per-step outputs/errors land in the DB (not just the web-denied
 *     storage/cron.log).
 *   - access_log_prune() — the nightly ~90-day retention sweep.
 *   - sync_alert_state() — the persistent error-banner state read (used by render_header);
 *     guarded so a missing table / transient error simply shows no banner.
 *
 * The page-facing LIST reads (q_access_log / q_sync_runs / q_sync_run_steps /
 * q_connection_status) live in queries.php (household-wide, NOT VIS-scoped — documented).
 *
 * Both logs are HOUSEHOLD-WIDE diagnostics; reads are not VIS-scoped (the page is reached
 * from Settings, like alert_settings / credit). user_id → name is resolved via
 * household_users() in PHP at render time, so no JOIN is stored.
 */

const ACCESS_LOG_RETENTION_DAYS = 90;

/** Humanize a DURATION in seconds → "just now / N min ago / N hr ago / N days ago / N mo ago".
 *  Takes a pure duration (computed in SQL as TIMESTAMPDIFF, so it's TZ-safe — never re-parse a
 *  MySQL timestamp string in PHP, the S24 server-EDT-vs-app-PDT trap). Null → ''. */
function activity_ago(?int $seconds): string
{
    if ($seconds === null) return '';
    $s = max(0, $seconds);
    if ($s < 60)        return 'just now';
    if ($s < 3600)      return (int)floor($s / 60) . ' min ago';
    if ($s < 86400)     return (int)floor($s / 3600) . ' hr ago';
    if ($s < 2592000)   return (int)floor($s / 86400) . ' days ago';
    return (int)floor($s / 2592000) . ' mo ago';
}

/** Best-effort: clamp a string to $max chars (NULL passes through). */
function activity_trunc(?string $s, int $max): ?string
{
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;
    return mb_substr($s, 0, $max);
}

/** Best-effort client IP — the first X-Forwarded-For hop (the app sits behind a proxy;
 *  bootstrap.php already trusts X-Forwarded-Proto), else REMOTE_ADDR. Validated. */
function activity_client_ip(): ?string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    $ra = $_SERVER['REMOTE_ADDR'] ?? '';
    return ($ra !== '' && filter_var($ra, FILTER_VALIDATE_IP)) ? $ra : null;
}

/**
 * Record one audited event. Best-effort — swallows every error so logging can never
 * affect the caller. $uid is NULL for a pre-auth event (e.g. a rejected login).
 */
function access_log_record(PDO $pdo, ?int $uid, string $event, ?string $label = null, ?string $detail = null): void
{
    try {
        $pdo->prepare(
            'INSERT INTO access_log (user_id, event_type, label, detail, ip, user_agent)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $uid,
            mb_substr($event, 0, 16),
            activity_trunc($label, 160),
            activity_trunc($detail, 255),
            activity_client_ip(),
            activity_trunc($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
        ]);
    } catch (Throwable $e) {
        // best-effort — never surface a logging failure
    }
}

/** Log a page view (event='page'); label = the script path, detail = the query string. */
function access_log_page(PDO $pdo, int $uid): void
{
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $qs   = (string)($_SERVER['QUERY_STRING'] ?? '');
    access_log_record($pdo, $uid, 'page', $path !== '' ? $path : null, $qs !== '' ? $qs : null);
}

/** Log a mutating action (event='action'); label = 'endpoint:action'. */
function access_log_action(PDO $pdo, int $uid, string $endpoint, ?string $action = null, ?string $detail = null): void
{
    $label = ($action !== null && $action !== '') ? "$endpoint:$action" : $endpoint;
    access_log_record($pdo, $uid, 'action', $label, $detail);
}

/** Prune access_log rows older than $days (the nightly retention sweep). Returns rows deleted.
 *  Uses the DB clock on both sides (created_at default + NOW()), so no PHP/MySQL TZ mismatch. */
function access_log_prune(PDO $pdo, int $days = ACCESS_LOG_RETENTION_DAYS): int
{
    try {
        $st = $pdo->prepare('DELETE FROM access_log WHERE created_at < (NOW() - INTERVAL ? DAY)');
        $st->execute([max(1, $days)]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/* --- Sync-run capture (cron/sync.php) ------------------------------------- */

/** Open a sync run; returns its id (or null if capture is unavailable — callers no-op). */
function sync_run_begin(PDO $pdo, string $trigger): ?int
{
    try {
        $pdo->prepare('INSERT INTO sync_run (trigger_type) VALUES (?)')
            ->execute([mb_substr($trigger, 0, 16)]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

/** Record one step of a run. No-op when $runId is null (capture unavailable). Best-effort. */
function sync_run_step(PDO $pdo, ?int $runId, string $step, bool $ok, ?string $message = null, ?string $label = null): void
{
    if ($runId === null) return;
    try {
        $pdo->prepare(
            'INSERT INTO sync_run_step (run_id, step, label, ok, message) VALUES (?,?,?,?,?)'
        )->execute([
            $runId,
            mb_substr($step, 0, 48),
            activity_trunc($label, 160),
            $ok ? 1 : 0,
            activity_trunc($message, 60000),
        ]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/** Close a run — stamp finished_at + roll up step_count / error_count / ok. Best-effort. */
function sync_run_finish(PDO $pdo, ?int $runId): void
{
    if ($runId === null) return;
    try {
        $pdo->prepare(
            'UPDATE sync_run r
                SET finished_at = NOW(),
                    step_count  = (SELECT COUNT(*) FROM sync_run_step s WHERE s.run_id = r.id),
                    error_count = (SELECT COUNT(*) FROM sync_run_step s WHERE s.run_id = r.id AND s.ok = 0),
                    ok          = CASE WHEN (SELECT COUNT(*) FROM sync_run_step s WHERE s.run_id = r.id AND s.ok = 0) = 0 THEN 1 ELSE 0 END
              WHERE r.id = ?'
        )->execute([$runId]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/* --- Error-banner state (render_header) ----------------------------------- */

/**
 * Whether an unresolved sync/connection error should raise the persistent banner.
 * Two conditions: (a) a Plaid Item whose connection is broken (status='error'), or
 * (b) the most recent FINISHED sync run failed (a later clean run clears it).
 *
 * Returns ['error'=>bool, 'reasons'=>[strings], 'signature'=>string]. The signature
 * encodes the current condition so a per-session dismissal re-appears on a NEW error.
 * Fully guarded — any failure (missing table, transient DB) → no banner.
 */
function sync_alert_state(PDO $pdo): array
{
    $reasons = [];
    $sig     = [];
    try {
        // (a) broken bank connections
        $items = $pdo->query(
            "SELECT item_id, institution_name, error_code
               FROM items
              WHERE source = 'plaid' AND status = 'error'
              ORDER BY item_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($items) {
            $names = array_map(static fn($i) => $i['institution_name'] ?: $i['item_id'], $items);
            $reasons[] = count($items) === 1
                ? 'Bank connection needs attention: ' . $names[0]
                : count($items) . ' bank connections need attention';
            foreach ($items as $i) $sig[] = 'i:' . $i['item_id'];
        }

        // (b) the latest finished nightly run failed
        $run = $pdo->query(
            'SELECT id, ok, error_count FROM sync_run
              WHERE finished_at IS NOT NULL
              ORDER BY started_at DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if ($run && (int)$run['ok'] === 0) {
            $n = (int)$run['error_count'];
            $reasons[] = 'Last sync run had ' . ($n > 0 ? $n . ' failed step' . ($n === 1 ? '' : 's') : 'errors');
            $sig[] = 'r:' . $run['id'];
        }
    } catch (Throwable $e) {
        return ['error' => false, 'reasons' => [], 'signature' => ''];
    }

    return [
        'error'     => !empty($reasons),
        'reasons'   => $reasons,
        'signature' => $sig ? substr(sha1(implode('|', $sig)), 0, 16) : '',
    ];
}
