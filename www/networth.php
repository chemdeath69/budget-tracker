<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo      = db();
$uid      = current_user_id();
$accounts = q_accounts($pdo, $uid);
$stats    = q_stats($accounts);
$snaps    = q_networth($pdo);
$change   = q_networth_change($pdo, $stats['net_worth'], 30);

$assets = array_filter($accounts, fn($a) => !is_liability($a));
$debts  = array_filter($accounts, fn($a) => is_liability($a));

render_header('Net worth', 'networth', ['chart' => true]);

/** Render a list of accounts with balances. */
function nw_account_rows(array $rows, bool $debt): void
{
    foreach ($rows as $a):
        $bal = (float)($a['balance_current'] ?? 0); ?>
        <a class="row" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>">
            <span class="row-main">
                <span class="row-title"><?= e($a['name'] ?: 'Account') ?></span>
                <span class="row-sub"><?= e($a['institution_name'] ?: '') ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?></span>
            </span>
            <span class="row-amt <?= $debt ? 'neg' : '' ?>"><?= e(($debt && $bal > 0 ? '-' : '') . usd(abs($bal))) ?></span>
            <span class="chev" aria-hidden="true">›</span>
        </a>
    <?php endforeach;
}
?>

<section class="card hero">
    <div class="hero-top">
        <span class="hero-label">Net worth</span>
        <?php if ($change['pct'] !== null): $up = $change['pct'] >= 0; ?>
            <span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= number_format(abs($change['pct']), 1) ?>%<span class="delta-sub">30d</span></span>
        <?php endif; ?>
    </div>
    <div class="hero-value"><?= e(usd($stats['net_worth'])) ?></div>
    <?php if ($change['abs'] !== null): ?>
        <div class="muted"><?= ($change['abs'] >= 0 ? '+' : '−') . e(usd(abs($change['abs']))) ?> over the last 30 days</div>
    <?php endif; ?>

    <?php if (count($snaps) > 1): ?>
        <div class="chart-wrap tall">
            <canvas id="nw-chart" data-chart="line" data-src="nw-data"></canvas>
            <script type="application/json" id="nw-data"><?= json_encode([
                'labels' => array_column($snaps, 'snapshot_date'),
                'values' => array_map('floatval', array_column($snaps, 'net_worth')),
            ], JSON_UNESCAPED_SLASHES) ?></script>
        </div>
    <?php else: ?>
        <p class="muted">Net-worth history will appear as daily snapshots accumulate.</p>
    <?php endif; ?>
</section>

<div class="cols">
<section class="block">
    <div class="block-head"><h2>Assets</h2><span class="split-value pos"><?= e(usd($stats['assets'])) ?></span></div>
    <div class="rows">
        <?php $assets ? nw_account_rows($assets, false) : print('<p class="muted" style="padding:1rem">No asset accounts.</p>'); ?>
    </div>
</section>

<section class="block">
    <div class="block-head"><h2>Liabilities</h2><span class="split-value neg"><?= e(usd($stats['liabilities'])) ?></span></div>
    <div class="rows">
        <?php $debts ? nw_account_rows($debts, true) : print('<p class="muted" style="padding:1rem">No debts. 🎉</p>'); ?>
    </div>
</section>
</div><!-- /.cols -->

<?php render_footer(); ?>
