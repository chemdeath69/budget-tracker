<?php
/**
 * Events & trips (migration 035) — the list page.
 *
 * An event groups transactions under a name so you can see what the whole thing cost.
 * Rows are EVIS-scoped (shared events + your own private ones); each shows the HONEST net
 * total (every member on a non-hidden account) with a masked note when part of it sits on
 * an account you can't itemise. Add/edit uses one reused inline form (the goals.php
 * pattern); the API gates rename/dates/visibility/delete on the creator.
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo    = db();
$uid    = current_user_id();
$events = q_events($pdo, $uid);

// Household net across everything visible here — the honest totals, so it matches what the
// other member sees for the same set of events.
$grandTotal = array_sum(array_map(fn($e) => (float)$e['total_all'], $events));
$grandTx    = array_sum(array_map(fn($e) => (int)$e['n_all'], $events));

render_header('Events & trips', 'events', ['narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Everyday</p>
    <h1>Events &amp; trips</h1>
    <p class="sub">Group transactions under a name — a trip, a wedding, a move — to see what it really cost.</p>
</div>

<section class="block">
    <div class="block-head">
        <h2>Your events</h2>
        <button class="btn-ghost" id="add-event-btn" type="button">+ Add</button>
    </div>

    <?php if ($events): ?>
    <div class="card goals-summary">
        <div class="b-head">
            <span class="muted">Across <?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?></span>
            <span class="muted"><?= e(usd($grandTotal)) ?> · <?= (int)$grandTx ?> transaction<?= $grandTx === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <?php endif; ?>

    <form id="add-event-form" class="card goal-form" hidden>
        <input type="hidden" id="event-id" value="">
        <label class="field">
            <span>Event name</span>
            <input id="event-name" class="input" type="text" maxlength="120" placeholder="Maui 2026">
        </label>
        <div class="ev-date-row">
            <label class="field">
                <span>Starts (optional)</span>
                <input id="event-start" class="input" type="date">
            </label>
            <label class="field">
                <span>Ends (optional)</span>
                <input id="event-end" class="input" type="date">
            </label>
        </div>
        <p class="muted ev-hint">Set dates and the event page will suggest transactions in that range for one-click adding.</p>
        <label class="field">
            <span>Who can see it</span>
            <select id="event-visibility" class="input">
                <option value="shared">Shared — everyone in the household</option>
                <option value="private">Private — only me</option>
            </select>
        </label>
        <label class="field">
            <span>Note (optional)</span>
            <input id="event-note" class="input" type="text" maxlength="500" placeholder="Anniversary trip">
        </label>
        <div class="form-actions">
            <button class="btn" type="submit">Save event</button>
            <button class="btn-ghost" type="button" id="event-cancel">Cancel</button>
        </div>
    </form>

    <div id="events-list" class="budgets-list">
        <?php if (!$events): ?>
            <div class="empty-state card" id="events-empty">
                <span class="empty-ic"><?= nav_icon('calendar') ?></span>
                <h2>No events yet</h2>
                <p class="muted">Add one above, then attach transactions from the ledger with the
                    &ldquo;+ event&rdquo; button on any row.</p>
            </div>
        <?php else: foreach ($events as $ev):
            $href = '/event.php?id=' . (int)$ev['id'];
            // Date range label: "Mar 3 – Mar 11, 2026", one date, or nothing.
            $range = '';
            if ($ev['start_date']) {
                $range = date('M j, Y', strtotime((string)$ev['start_date']));
                if ($ev['end_date']) $range .= ' – ' . date('M j, Y', strtotime((string)$ev['end_date']));
            } elseif ($ev['end_date']) {
                $range = 'through ' . date('M j, Y', strtotime((string)$ev['end_date']));
            } ?>
        <div class="budget-row card ev-row" data-id="<?= (int)$ev['id'] ?>"
             data-name="<?= e($ev['name']) ?>"
             data-start="<?= e((string)($ev['start_date'] ?? '')) ?>"
             data-end="<?= e((string)($ev['end_date'] ?? '')) ?>"
             data-visibility="<?= e($ev['visibility']) ?>"
             data-note="<?= e((string)($ev['note'] ?? '')) ?>">
            <div class="b-head">
                <span>
                    <a class="cat-link" href="<?= e($href) ?>"><?= e($ev['name']) ?></a>
                    <?php if ($ev['visibility'] === 'private'): ?>
                        <span class="ev-badge" title="Only you can see this event">Private</span>
                    <?php endif; ?>
                </span>
                <span class="muted"><?= e(usd($ev['total_all'])) ?>
                    <?php if ($ev['can_manage']): ?>
                        <button class="goal-edit event-edit" data-id="<?= (int)$ev['id'] ?>" type="button" aria-label="Edit event">✎</button>
                        <button class="goal-del event-del" data-id="<?= (int)$ev['id'] ?>" type="button" aria-label="Delete event">✕</button>
                    <?php endif; ?>
                </span>
            </div>
            <p class="muted goal-foot">
                <?php if ($range !== ''): ?><?= e($range) ?> · <?php endif; ?>
                <?= (int)$ev['n_all'] ?> transaction<?= (int)$ev['n_all'] === 1 ? '' : 's' ?>
                <?php if ($ev['masked']): ?>
                    · <span class="ev-masked-note"><?= (int)$ev['hidden_n'] ?> on private accounts</span>
                <?php endif; ?>
                <?php if (!$ev['can_manage']): ?>
                    · added by <?= e(owner_first_name($ev['created_by'])) ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($ev['note'])): ?>
                <p class="muted goal-foot"><?= e($ev['note']) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <p class="muted load-note">A shared event is visible to everyone in the household; anyone can add or
        remove the transactions <em>they</em> can see. Only the person who created an event can rename,
        re-date or delete it. Money coming back in (a refund or reimbursement) subtracts, so the total is
        what the event <em>net</em> cost.</p>
</section>

<?php render_footer(); ?>
