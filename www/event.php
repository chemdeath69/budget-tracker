<?php
/**
 * Event / trip detail (migration 035).
 *
 * Gate-first (the security.php pattern): ?id= is resolved through q_event(), which applies
 * the EVENT-level gate (EVIS). A missing event and someone else's PRIVATE event render the
 * SAME "not found" shell, so a private trip never confirms its own existence.
 *
 * Honest-number contract on this page: the hero is the net total of EVERY member
 * transaction on a non-hidden account (no VIS) — so both members of a shared trip see the
 * same headline. The breakdown + the transaction list below are VIS-scoped, and the slice
 * this viewer can't itemise is reported as one masked "Private accounts" line rather than
 * silently dropped (the q_goals() precedent).
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

$eventId = (int)($_GET['id'] ?? 0);
$ev      = q_event($pdo, $uid, $eventId);

if ($ev === null) {
    render_header('Event', 'events', ['back' => '/events.php', 'narrow' => true]);
    ?>
    <div class="page-head">
        <p class="eyebrow">Everyday</p>
        <h1>Event</h1>
    </div>
    <div class="empty-state card">
        <h2>Event not found</h2>
        <p class="muted">This event doesn't exist, or it's private to someone else.</p>
        <a class="btn" href="/events.php">← All events</a>
    </div>
    <?php
    render_footer();
    exit;
}

/* ---- Assemble ------------------------------------------------------------- */
$breakdown  = q_event_breakdown($pdo, $uid, $eventId);
$members    = q_event_transactions($pdo, $uid, $eventId, 500);
attach_tx_meta($pdo, $members, (int)$uid);
$suggest    = q_event_suggestions($pdo, $uid, $eventId, $ev['start_date'], $ev['end_date'], 50);
$catOptions = transaction_category_options($pdo, $uid);
$tagOptions = all_tags($pdo);
$evOptions  = q_event_options($pdo, $uid);

// Date-range label for the header + the Details card.
$range = '';
if ($ev['start_date']) {
    $range = date('M j, Y', strtotime((string)$ev['start_date']));
    if ($ev['end_date']) $range .= ' – ' . date('M j, Y', strtotime((string)$ev['end_date']));
} elseif ($ev['end_date']) {
    $range = 'through ' . date('M j, Y', strtotime((string)$ev['end_date']));
}

// Doughnut slices. A category can net NEGATIVE (a refund larger than the spend in it), and
// a negative slice is meaningless in a doughnut — so the chart plots only the positive
// categories (plus the masked private bucket when it's positive) while the list below shows
// every row, negatives included. Percentages are of the plotted total, and the caption says so.
$slices = [];
foreach ($breakdown as $b) {
    if ((float)$b['total'] > 0) $slices[] = ['label' => pretty_cat($b['category']), 'value' => (float)$b['total']];
}
if ($ev['masked'] && (float)$ev['hidden_total'] > 0) {
    $slices[] = ['label' => 'Private accounts', 'value' => (float)$ev['hidden_total']];
}
$sliceTotal = array_sum(array_column($slices, 'value'));

render_header($ev['name'], 'events', ['back' => '/events.php', 'narrow' => true, 'chart' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Event</p>
    <h1><?= e($ev['name']) ?></h1>
    <?php if ($range !== '' || $ev['visibility'] === 'private'): ?>
    <p class="sub">
        <?= $range !== '' ? e($range) : '' ?>
        <?php if ($range !== '' && $ev['visibility'] === 'private'): ?> · <?php endif; ?>
        <?php if ($ev['visibility'] === 'private'): ?><span class="ev-badge">Private</span><?php endif; ?>
    </p>
    <?php endif; ?>
</div>

<section class="stat-hero">
    <p class="eyebrow">Net cost</p>
    <p class="big<?= (float)$ev['total_all'] < 0 ? ' pos' : '' ?>"><?= e(usd($ev['total_all'])) ?></p>
    <p class="stat-sub">
        <?= (int)$ev['n_all'] ?> transaction<?= (int)$ev['n_all'] === 1 ? '' : 's' ?>
        <?php if ($ev['masked']): ?>
            · <?= (int)$ev['hidden_n'] ?> on account<?= (int)$ev['hidden_n'] === 1 ? '' : 's' ?> you can't see
        <?php endif; ?>
    </p>
</section>

<?php if ((float)$ev['total_all'] < 0): ?>
    <p class="muted load-note">This event is net <strong>positive</strong> — the money that came back
        (refunds or reimbursements) exceeds what went out.</p>
<?php endif; ?>

<!-- Where the money went -->
<?php if ($breakdown || $ev['masked']): ?>
<section class="card">
    <div class="block-head">
        <h2>Where it went</h2>
        <span class="muted">By category</span>
    </div>

    <?php if ($slices): ?>
    <div class="chart-wrap">
        <canvas id="event-chart" data-chart="doughnut" data-src="event-cat-data"></canvas>
        <script type="application/json" id="event-cat-data"><?= json_encode([
            'labels' => array_column($slices, 'label'),
            'values' => array_column($slices, 'value'),
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>
    <?php endif; ?>

    <div class="cat-list">
        <?php
        $maxSlice = $slices ? max(array_column($slices, 'value')) : 0.0;
        $i = 0;
        foreach ($breakdown as $b):
            $amt  = (float)$b['total'];
            $w    = $maxSlice > 0 ? max(3, (abs($amt) / $maxSlice) * 100) : 0;
            $pct  = ($sliceTotal > 0 && $amt > 0) ? ($amt / $sliceTotal) * 100 : null;
            $href = '/transactions.php?' . http_build_query(['event' => (int)$ev['id'], 'category' => $b['category']]);
        ?>
        <a class="cat-row" href="<?= e($href) ?>">
            <?php // A net-negative category isn't plotted, so it gets the muted swatch rather
                  // than a palette colour that maps to no slice. ?>
            <?php if ($amt > 0): ?><span class="cat-swatch" style="--i:<?= $i++ ?>"></span>
            <?php else: ?><span class="cat-swatch other"></span><?php endif; ?>
            <span class="cat-name"><?= e(pretty_cat($b['category'])) ?></span>
            <span class="cat-track"><span style="width:<?= round($w) ?>%"></span></span>
            <span class="cat-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?><?php
                if ($pct !== null): ?><span class="cat-pct"><?= round($pct) ?>%</span><?php endif; ?></span>
        </a>
        <?php endforeach; ?>

        <?php if ($ev['masked']): ?>
        <div class="cat-row ev-masked-row" title="These sit on an account that's private to another household member. They count toward the total, but their details aren't shown to you.">
            <?php if ((float)$ev['hidden_total'] > 0): ?><span class="cat-swatch" style="--i:<?= $i ?>"></span>
            <?php else: ?><span class="cat-swatch other"></span><?php endif; ?>
            <span class="cat-name">Private accounts</span>
            <span class="cat-track"><span style="width:<?= $maxSlice > 0 ? round(max(3, (abs((float)$ev['hidden_total']) / $maxSlice) * 100)) : 0 ?>%"></span></span>
            <span class="cat-amt"><?= e(usd($ev['hidden_total'])) ?><span class="cat-pct"><?= (int)$ev['hidden_n'] ?> txn</span></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($ev['masked']): ?>
    <p class="muted load-note">“Private accounts” covers <?= (int)$ev['hidden_n'] ?> transaction<?= (int)$ev['hidden_n'] === 1 ? '' : 's' ?>
        on an account another member keeps private. They're counted in the total above so the number is
        honest — the details just aren't yours to see.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- The transactions in this event -->
<section class="block">
    <div class="block-head">
        <h2>Transactions</h2>
        <?php if ($members): ?><a class="btn-ghost" href="/transactions.php?event=<?= (int)$ev['id'] ?>">Open in ledger</a><?php endif; ?>
    </div>

    <?php if (!$members): ?>
        <div class="empty-state card">
            <span class="empty-ic"><?= nav_icon('list') ?></span>
            <h2>Nothing added yet</h2>
            <p class="muted">Attach transactions with the &ldquo;+ event&rdquo; button on any row in the
                ledger<?= $ev['start_date'] ? ', or pick from the suggestions below' : '' ?>.</p>
            <a class="btn" href="/transactions.php">Go to transactions</a>
        </div>
    <?php else: ?>
    <div class="rows tx-list">
        <?php $lastDate = null; foreach ($members as $t):
            $merchant = $t['merchant_name'] ?: ($t['name'] ?: '—');
            $amt      = (float)$t['amount'];
            $acctLbl  = ($t['account_name'] ?: '') . ($t['mask'] ? ' ••' . $t['mask'] : '');
            if ($t['date'] !== $lastDate): $lastDate = $t['date']; ?>
            <div class="tx-day"><?= e($t['date']) ?></div>
        <?php endif; ?>
        <div class="row tx-row">
            <span class="row-main">
                <span class="row-title"><?php if (!empty($t['logo_url'])): ?><img class="merchant-logo" src="<?= e($t['logo_url']) ?>" alt="" loading="lazy"><?php endif; ?><?= e(display_merchant($merchant)) ?><?= $t['pending'] ? ' <span class="mini-tag">pending</span>' : '' ?></span>
                <span class="row-sub">
                    <button type="button" class="cat-chip" data-tx="<?= e($t['transaction_id']) ?>" data-tag="<?= e($t['category'] ?? '') ?>"><?= $t['category'] ? e(pretty_cat($t['category'])) : 'Set category' ?></button>
                    <span class="muted">· <?php if (!empty($t['account_id']) && $acctLbl !== ''): ?><a href="/account.php?account_id=<?= rawurlencode($t['account_id']) ?>"><?= e($acctLbl) ?></a><?php else: ?><?= e($acctLbl) ?><?php endif; ?><?= owner_suffix($t['owner_id'] ?? null) ?></span>
                </span>
                <?= render_tx_meta($t) ?>
            </span>
            <span class="row-side">
                <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
                <button type="button" class="btn-ghost ev-remove" data-tx="<?= e($t['transaction_id']) ?>" data-event="<?= (int)$ev['id'] ?>">Remove</button>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($members) < (int)$ev['n_vis']): // never truncate silently ?>
        <p class="muted load-note">Showing the newest <?= count($members) ?> of <?= (int)$ev['n_vis'] ?> —
            <a href="/transactions.php?event=<?= (int)$ev['id'] ?>">see them all in the ledger</a>.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>

<!-- Suggestions: in-range transactions not yet attached -->
<?php if ($ev['start_date'] && $suggest): ?>
<section class="block">
    <div class="block-head">
        <h2>Add from this date range</h2>
        <span class="muted"><?= count($suggest) ?> not added</span>
    </div>
    <div class="rows tx-list ev-suggest">
        <?php foreach ($suggest as $t):
            $merchant = $t['merchant_name'] ?: ($t['name'] ?: '—');
            $amt      = (float)$t['amount'];
            $acctLbl  = ($t['account_name'] ?: '') . ($t['mask'] ? ' ••' . $t['mask'] : ''); ?>
        <div class="row tx-row ev-suggest-row" data-tx="<?= e($t['transaction_id']) ?>">
            <span class="row-main">
                <span class="row-title"><?php if (!empty($t['logo_url'])): ?><img class="merchant-logo" src="<?= e($t['logo_url']) ?>" alt="" loading="lazy"><?php endif; ?><?= e(display_merchant($merchant)) ?><?= $t['pending'] ? ' <span class="mini-tag">pending</span>' : '' ?></span>
                <span class="row-sub">
                    <span class="tx-date"><?= e($t['date']) ?></span>
                    <span class="muted">· <?= e(pretty_cat($t['category'] ?? '')) ?> · <?= e($acctLbl) ?><?= owner_suffix($t['owner_id'] ?? null) ?></span>
                </span>
            </span>
            <span class="row-side">
                <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
                <button type="button" class="btn-ghost ev-suggest-add" data-tx="<?= e($t['transaction_id']) ?>" data-event="<?= (int)$ev['id'] ?>">+ Add</button>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="muted load-note">Everything you can see dated <?= e($range) ?> that isn't in this event yet
        (investment activity excluded). Showing up to 50.</p>
</section>
<?php endif; ?>

<!-- Details -->
<section class="card">
    <p class="rail-head">Details</p>
    <div class="kv">
        <div class="r"><span class="k">Dates</span><span class="v"><?= $range !== '' ? e($range) : '—' ?></span></div>
        <div class="r"><span class="k">Visibility</span><span class="v"><?= $ev['visibility'] === 'private' ? 'Private — only you' : 'Shared with the household' ?></span></div>
        <div class="r"><span class="k">Created by</span><span class="v"><?= $ev['can_manage'] ? 'You' : e(owner_first_name($ev['created_by'])) ?></span></div>
        <div class="r"><span class="k">Transactions</span><span class="v"><?= (int)$ev['n_all'] ?></span></div>
        <?php if (!empty($ev['note'])): ?>
        <div class="r"><span class="k">Note</span><span class="v"><?= e($ev['note']) ?></span></div>
        <?php endif; ?>
    </div>
</section>

<!-- Manage (creator only) -->
<?php if ($ev['can_manage']): ?>
<section class="card" id="event-manage">
    <p class="rail-head">Manage</p>
    <form id="event-manage-form" class="goal-form">
        <input type="hidden" id="mev-id" value="<?= (int)$ev['id'] ?>">
        <label class="field">
            <span>Event name</span>
            <input id="mev-name" class="input" type="text" maxlength="120" value="<?= e($ev['name']) ?>">
        </label>
        <div class="ev-date-row">
            <label class="field">
                <span>Starts</span>
                <input id="mev-start" class="input" type="date" value="<?= e((string)($ev['start_date'] ?? '')) ?>">
            </label>
            <label class="field">
                <span>Ends</span>
                <input id="mev-end" class="input" type="date" value="<?= e((string)($ev['end_date'] ?? '')) ?>">
            </label>
        </div>
        <label class="field">
            <span>Who can see it</span>
            <select id="mev-visibility" class="input">
                <option value="shared"<?= $ev['visibility'] === 'shared' ? ' selected' : '' ?>>Shared — everyone in the household</option>
                <option value="private"<?= $ev['visibility'] === 'private' ? ' selected' : '' ?>>Private — only me</option>
            </select>
        </label>
        <label class="field">
            <span>Note</span>
            <input id="mev-note" class="input" type="text" maxlength="500" value="<?= e((string)($ev['note'] ?? '')) ?>">
        </label>
        <div class="form-actions">
            <button class="btn" type="submit">Save changes</button>
            <button class="btn-ghost danger" type="button" id="mev-delete" data-id="<?= (int)$ev['id'] ?>">Delete event</button>
        </div>
    </form>
    <p class="muted load-note">Making a shared event private keeps everyone's attachments — they just stop
        being visible to anyone else. Deleting the event never deletes any transaction.</p>
</section>
<?php endif; ?>

<script type="application/json" id="cat-options"><?= json_encode($catOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="tag-options"><?= json_encode($tagOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="event-options"><?= json_encode($evOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php render_footer(); ?>
