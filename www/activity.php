<?php
/**
 * Activity & diagnostics (owner request) — Access Logs + Sync Status.
 *
 * Reached from Settings (not in the main nav — infrequent). Two tabs (?view=):
 *   sync   — bank-connection health + the nightly sync-run history (each run's steps,
 *            outputs and errors). The persistent error banner (render_header) links here.
 *   access — who logged in / what pages they viewed / what actions they took, filterable
 *            by user + event type, paginated. Pruned to ~90 days by the nightly cron.
 *
 * HOUSEHOLD-WIDE diagnostics — both users see everything (reads are NOT VIS-scoped, like
 * the alerts/credit pages). All data comes from the household-wide q_* readers in
 * queries.php; timestamps are rendered from a SQL-computed age (TZ-safe — see activity.php).
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$view = ($_GET['view'] ?? 'sync') === 'access' ? 'access' : 'sync';
$users = household_users();   // [id => display name]

/** Absolute event time formatted in the APP TZ from a SQL-computed age (S24-safe). */
$absTime = static function ($ageS): string {
    if ($ageS === null) return '—';
    return date('M j, Y · g:i a', time() - (int)$ageS);
};

/** Friendly label for a sync step. Item steps carry the institution name in `label`. */
$stepName = static function (array $s): string {
    if (str_starts_with((string)$s['step'], 'item:')) {
        return ($s['label'] ?? '') !== '' ? 'Bank sync · ' . $s['label'] : 'Bank sync';
    }
    static $map = [
        'prices'           => 'Security prices',
        'dividends'        => 'Dividends',
        'fred'             => 'Economic data (FRED)',
        'vehicles'         => 'Vehicle revaluation',
        'snapshot'         => 'Net-worth snapshot',
        'balance_history'  => 'Account balance history',
        'home_value'       => 'Home value (RentCast)',
        'digest'           => 'Weekly digest',
        'spend_alerts'     => 'Spending alerts',
        'prune_access_log' => 'Access-log cleanup',
    ];
    return $map[$s['step']] ?? ucfirst(str_replace('_', ' ', (string)$s['step']));
};

render_header('Activity & diagnostics', 'activity', ['back' => '/settings.php']);
?>

<div class="tabs" role="tablist">
    <a class="tab<?= $view === 'sync' ? ' active' : '' ?>" href="?view=sync">Sync status</a>
    <a class="tab<?= $view === 'access' ? ' active' : '' ?>" href="?view=access">Access log</a>
</div>

<?php if ($view === 'sync'): ?>

    <?php
    $conns = q_connection_status($pdo);
    $errCount = 0;
    foreach ($conns as $c) if ($c['status'] === 'error') $errCount++;
    ?>
    <section class="block">
        <div class="block-head"><h2>Bank connections</h2>
            <span class="muted"><?= count($conns) ?> linked<?= $errCount ? ' · ' . $errCount . ' need attention' : '' ?></span>
        </div>
        <?php if (!$conns): ?>
            <div class="card"><p class="muted">No linked banks.</p></div>
        <?php else: foreach ($conns as $c):
            $bad = $c['status'] === 'error'; ?>
            <div class="card actv-conn<?= $bad ? ' is-bad' : '' ?>">
                <div class="actv-conn-main">
                    <span class="actv-name"><?= e($c['institution_name'] ?: $c['item_id']) ?></span>
                    <span class="actv-sub muted">
                        <?php if ($c['last_synced_at']): ?>
                            Updated <?= e(activity_ago($c['age_s'] === null ? null : (int)$c['age_s'])) ?>
                        <?php else: ?>
                            Never synced
                        <?php endif; ?>
                        <?php if ($bad && $c['error_code']): ?> · <?= e($c['error_code']) ?><?php endif; ?>
                    </span>
                </div>
                <span class="actv-badge <?= $bad ? 'bad' : 'ok' ?>"><?= $bad ? 'Needs attention' : 'Connected' ?></span>
            </div>
        <?php endforeach; endif; ?>
        <?php if ($errCount): ?>
            <div class="notice warn">A bank needs reconnecting — open <a href="/settings.php">Settings → Banks</a> and use <strong>Re-link</strong> on the affected account.</div>
        <?php endif; ?>
    </section>

    <?php
    $runPage = page_num('runpage');
    $runs = q_sync_runs($pdo, PAGE_SIZE + 1, page_offset($runPage));
    $runHasNext = count($runs) > PAGE_SIZE;
    $runs = array_slice($runs, 0, PAGE_SIZE);
    $stepsByRun = q_sync_run_steps_map($pdo, array_map(fn($r) => (int)$r['id'], $runs));
    ?>
    <section class="block">
        <div class="block-head"><h2>Sync runs</h2><span class="muted">Nightly pipeline history</span></div>
        <?php if (!$runs): ?>
            <div class="card"><p class="muted">No sync runs recorded yet. The nightly cron records one run each night; a manual run will appear here too.</p></div>
        <?php else: foreach ($runs as $r):
            $rok = $r['ok'];
            $badge = $rok === null ? ['run', 'In progress'] : ((int)$rok === 1 ? ['ok', 'Clean'] : ['bad', (int)$r['error_count'] . ' error' . ((int)$r['error_count'] === 1 ? '' : 's')]);
            $steps = $stepsByRun[(int)$r['id']] ?? [];
            $dur = $r['duration_s'] === null ? '' : ' · ' . (int)$r['duration_s'] . 's';
            ?>
            <details class="card actv-run<?= ($rok !== null && (int)$rok === 0) ? ' is-bad' : '' ?>">
                <summary>
                    <span class="actv-run-when">
                        <span class="actv-badge <?= $badge[0] ?>"><?= e($badge[1]) ?></span>
                        <?= e($absTime($r['age_s'])) ?>
                    </span>
                    <span class="actv-sub muted"><?= e($r['trigger_type']) ?> · <?= (int)$r['step_count'] ?> steps<?= e($dur) ?> · <?= e(activity_ago($r['age_s'] === null ? null : (int)$r['age_s'])) ?></span>
                </summary>
                <?php if (!$steps): ?>
                    <p class="muted actv-step-empty">No steps recorded for this run.</p>
                <?php else: ?>
                    <ul class="actv-steps">
                        <?php foreach ($steps as $s): ?>
                            <li class="actv-step<?= (int)$s['ok'] === 0 ? ' is-bad' : '' ?>">
                                <span class="actv-step-icon"><?= (int)$s['ok'] === 1 ? '✓' : '✕' ?></span>
                                <span class="actv-step-name"><?= e($stepName($s)) ?></span>
                                <span class="actv-step-msg"><?= e($s['message'] ?? '') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </details>
        <?php endforeach; endif; ?>
        <?php render_pager($runPage, $runHasNext, ['view' => 'sync'], 'runpage'); ?>
    </section>

<?php else: /* access log */ ?>

    <?php
    $fEvent = $_GET['event'] ?? '';
    if (!in_array($fEvent, access_event_types(), true)) $fEvent = '';
    $fUser  = ($_GET['fuser'] ?? '') !== '' ? (int)$_GET['fuser'] : '';
    $acPage = page_num('acpage');
    $rows = q_access_log($pdo, PAGE_SIZE + 1, page_offset($acPage), ['event' => $fEvent, 'user_id' => $fUser]);
    $acHasNext = count($rows) > PAGE_SIZE;
    $rows = array_slice($rows, 0, PAGE_SIZE);
    ?>
    <section class="block">
        <div class="block-head"><h2>Access log</h2><span class="muted">Last <?= ACCESS_LOG_RETENTION_DAYS ?> days · both users</span></div>

        <form class="filter-bar" method="get" action="/activity.php">
            <input type="hidden" name="view" value="access">
            <div class="filter-row">
                <select name="fuser" data-autosubmit aria-label="Filter by user">
                    <option value="">All users</option>
                    <?php foreach ($users as $id => $nm): ?>
                        <option value="<?= (int)$id ?>"<?= $fUser === (int)$id ? ' selected' : '' ?>><?= e($nm) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="event" data-autosubmit aria-label="Filter by event type">
                    <option value="">All events</option>
                    <?php foreach (access_event_types() as $et): ?>
                        <option value="<?= e($et) ?>"<?= $fEvent === $et ? ' selected' : '' ?>><?= ucfirst($et) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn-ghost sm" type="submit">Apply</button></noscript>
            </div>
        </form>

        <?php if (!$rows): ?>
            <div class="card"><p class="muted">No matching activity.</p></div>
        <?php else: ?>
            <div class="card actv-log-card">
                <table class="actv-log">
                    <thead><tr><th>When</th><th>Who</th><th>Event</th><th>Detail</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $ev = $row['event_type'];
                        $who = $row['user_id'] !== null ? ($users[(int)$row['user_id']] ?? ('#' . (int)$row['user_id'])) : '—';
                        $evCls = $ev === 'login' ? ($row['label'] === 'rejected' ? 'bad' : 'ok')
                               : ($ev === 'action' ? 'brand' : ($ev === 'logout' ? 'muted' : 'neutral'));
                        $detail = (string)($row['label'] ?? '');
                        if (($row['detail'] ?? '') !== '') $detail .= ($detail !== '' ? ' · ' : '') . $row['detail'];
                        ?>
                        <tr<?= ($ev === 'login' && $row['label'] === 'rejected') ? ' class="is-bad"' : '' ?>>
                            <td class="actv-when" title="<?= e($absTime($row['age_s'])) ?>"><?= e(activity_ago($row['age_s'] === null ? null : (int)$row['age_s'])) ?></td>
                            <td><?= e($who) ?></td>
                            <td><span class="actv-badge <?= $evCls ?>"><?= e($ev) ?></span></td>
                            <td class="actv-detail"><?= e($detail) ?></td>
                            <td class="actv-ip muted"><?= e($row['ip'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php render_pager($acPage, $acHasNext, ['view' => 'access', 'event' => $fEvent, 'fuser' => $fUser === '' ? '' : (string)$fUser], 'acpage'); ?>
    </section>

<?php endif; ?>

<?php render_footer(); ?>
