<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/refunds.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Flagged purchases the viewer can see (VIS-scoped). The candidate-credit pool only needs to
// reach back to the earliest watched purchase (a refund lands on/after the purchase), so derive
// $since from the watches (PHP app-TZ date string — never CURDATE()). No watches ⇒ no pool query.
$watches = q_refund_watches($pdo, $uid);
$since = date('Y-m-d');
foreach ($watches as $w) {
    if ((string)$w['date'] < $since) $since = (string)$w['date'];
}
$credits = $watches ? q_refund_credits($pdo, $uid, $since) : [];
$view = build_refunds_view($watches, $credits, date('Y-m-d'));

/** Render a purchase/credit row's sub-line: date · account ••mask · Owner. */
function refund_subline(array $row): string
{
    $acct = ($row['account_name'] ?? '') . (!empty($row['mask']) ? ' ••' . $row['mask'] : '');
    $out  = e((string)$row['date']);
    if ($acct !== '') $out .= ' · ' . e($acct);
    if (!empty($row['owner_id'])) $out .= owner_suffix($row['owner_id']);
    return $out;
}

render_header('Refunds', 'refunds', ['narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Everyday</p>
    <h1>Refunds</h1>
</div>

<section class="block">
    <div class="block-head">
        <h2>Tracked refunds</h2>
        <a class="block-link" href="/transactions.php">Transactions ›</a>
    </div>

    <?php if (!$watches): ?>
        <p class="empty-state">No refunds tracked yet. On any purchase (in
            <a href="/transactions.php">Transactions</a> or an account), tap <strong>⟳ expect refund</strong>
            to flag it — this page then watches for the matching credit to land.</p>
    <?php else: ?>

    <section class="hero card">
        <div class="hero-split tri">
            <div class="split-cell">
                <span class="split-label">Outstanding</span>
                <span class="split-value"><?= e(usd($view['outstanding'])) ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">Pending</span>
                <span class="split-value"><?= (int)$view['pending_count'] ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">Received</span>
                <span class="split-value pos"><?= (int)$view['received_count'] ?></span>
            </div>
        </div>
    </section>

    <?php if ($view['pending']): ?>
    <div class="block-head refund-subhead">
        <h3>Waiting on</h3>
        <span class="muted"><?= (int)$view['pending_count'] ?> · <?= e(usd($view['outstanding'])) ?> outstanding</span>
    </div>
    <div class="rows refund-list">
        <?php foreach ($view['pending'] as $p): ?>
        <div class="card refund-item" data-tx="<?= e($p['transaction_id']) ?>">
            <div class="refund-top">
                <span class="refund-merch">
                    <?php if (!empty($p['logo_url'])): ?><img class="merchant-logo" src="<?= e($p['logo_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                    <?= e($p['merchant']) ?>
                </span>
                <span class="refund-amt"><?= e(usd($p['amount'])) ?></span>
            </div>
            <p class="row-sub muted refund-sub"><?= refund_subline($p) ?></p>

            <?php if ($p['suggestions']): ?>
                <p class="refund-suggest-h muted">Possible match<?= count($p['suggestions']) === 1 ? '' : 'es' ?> — confirm if it's your refund:</p>
                <div class="refund-cands">
                    <?php foreach ($p['suggestions'] as $s): ?>
                    <div class="refund-cand">
                        <span class="refund-cand-info">
                            <span class="refund-cand-amt pos">+<?= e(usd($s['amount'])) ?></span>
                            <span class="refund-cand-merch"><?= e($s['merchant']) ?></span>
                            <span class="muted refund-cand-meta"><?= e((string)$s['date']) ?><?= $s['days_after'] > 0 ? ' · ' . (int)$s['days_after'] . 'd after' : '' ?><?php if (!empty($s['account_name'])): ?> · <?= e($s['account_name'] . (!empty($s['mask']) ? ' ••' . $s['mask'] : '')) ?><?php endif; ?></span>
                            <?php if (!empty($s['likely'])): ?><span class="refund-badge">likely</span>
                            <?php elseif (!empty($s['exact'])): ?><span class="refund-badge soft">exact amount</span><?php endif; ?>
                        </span>
                        <button type="button" class="btn-ghost refund-confirm" data-tx="<?= e($p['transaction_id']) ?>" data-match="<?= e($s['transaction_id']) ?>">Confirm</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted refund-nomatch">No matching credit has landed yet.</p>
            <?php endif; ?>

            <div class="refund-actions">
                <button type="button" class="btn-ghost refund-received" data-tx="<?= e($p['transaction_id']) ?>">Mark received</button>
                <button type="button" class="btn-ghost refund-dismiss" data-tx="<?= e($p['transaction_id']) ?>">Dismiss</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($view['received']): ?>
    <div class="block-head refund-subhead">
        <h3>Received</h3>
        <span class="muted"><?= (int)$view['received_count'] ?> · <?= e(usd($view['received_total'])) ?></span>
    </div>
    <div class="rows refund-list">
        <?php foreach ($view['received'] as $r): ?>
        <div class="card refund-item is-received" data-tx="<?= e($r['transaction_id']) ?>">
            <div class="refund-top">
                <span class="refund-merch">
                    <?php if (!empty($r['logo_url'])): ?><img class="merchant-logo" src="<?= e($r['logo_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                    <?= e($r['merchant']) ?> <span class="refund-chip is-received">✓ refunded</span>
                </span>
                <span class="refund-amt"><?= e(usd($r['amount'])) ?></span>
            </div>
            <p class="row-sub muted refund-sub"><?= refund_subline($r) ?></p>
            <?php if (!empty($r['matched'])): ?>
                <p class="muted refund-matched">Matched a <span class="pos">+<?= e(usd($r['matched']['amount'])) ?></span> credit
                    — <?= e($r['matched']['merchant']) ?> · <?= e((string)$r['matched']['date']) ?></p>
            <?php else: ?>
                <p class="muted refund-matched">Marked received (no linked credit).</p>
            <?php endif; ?>
            <div class="refund-actions">
                <button type="button" class="btn-ghost refund-reopen" data-tx="<?= e($r['transaction_id']) ?>">Reopen</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="muted load-note">Suggestions are based on a settled money-in transaction of a similar amount
        landing on or after the purchase — confirm before trusting one. Refund tracking is informational: it
        does <strong>not</strong> change your spending totals (a refund is already its own money-in transaction).</p>

    <?php endif; ?>
</section>

<?php render_footer(); ?>
