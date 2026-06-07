<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();
$rows = q_recurring($pdo, $uid);

$out = array_filter($rows, fn($r) => $r['direction'] !== 'inflow');
$in  = array_filter($rows, fn($r) => $r['direction'] === 'inflow');

render_header('Recurring', 'recurring');

function recurring_rows(array $rows): void
{
    foreach ($rows as $r):
        $amt = abs((float)$r['average_amount']);
        $inflow = $r['direction'] === 'inflow';
        $acct = ($r['account_name'] ?: '') . ($r['mask'] ? ' ••' . $r['mask'] : ''); ?>
        <div class="row">
            <span class="row-main">
                <span class="row-title"><?= e($r['merchant_name'] ?: ($r['description'] ?: '—')) ?></span>
                <span class="row-sub">
                    <?= e(pretty_cat($r['frequency'] ?: '')) ?>
                    <?php if ($r['category_primary']): ?>· <?= e(pretty_cat($r['category_primary'])) ?><?php endif; ?>
                    · <?= e($acct) ?>
                </span>
            </span>
            <span class="row-amt <?= $inflow ? 'pos' : '' ?>"><?= ($inflow ? '+' : '') . e(usd($amt)) ?></span>
        </div>
    <?php endforeach;
}
?>

<?php if (!$rows): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('repeat') ?></span>
        <h2>No recurring activity detected</h2>
        <p class="muted">Plaid identifies subscriptions and recurring bills automatically once enough transaction history is synced.</p>
    </div>
<?php else: ?>
    <?php if ($out): ?>
    <section class="block">
        <div class="block-head"><h2>Subscriptions &amp; bills</h2><span class="count-pill"><?= count($out) ?></span></div>
        <div class="rows card"><?php recurring_rows($out); ?></div>
    </section>
    <?php endif; ?>

    <?php if ($in): ?>
    <section class="block">
        <div class="block-head"><h2>Recurring income</h2><span class="count-pill"><?= count($in) ?></span></div>
        <div class="rows card"><?php recurring_rows($in); ?></div>
    </section>
    <?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
