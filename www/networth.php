<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/fred.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo      = db();
$uid      = current_user_id();
$accounts = q_accounts($pdo, $uid);
$homeVal  = q_home_value($pdo);            // estimated house value (0 if none)
$stats    = q_stats($accounts, $homeVal);  // net worth includes the home as an asset
$snaps    = q_networth($pdo);
$change   = q_networth_change($pdo, $stats['net_worth'], 30);

// Real (inflation-adjusted) net worth (#17): a second chart line in today's dollars
// + a real 30-day change chip. Null/empty when there's no cached CPI data, in which
// case the page falls back to the plain nominal line (unchanged behaviour).
$realVals   = fred_real_series($pdo, $snaps);   // null when no CPI data
$realChange = ['pct' => null, 'abs' => null];
if ($realVals !== null && ($change['date'] ?? null) !== null) {
    // Reindex against the SAME baseline snapshot the nominal "30d" chip used
    // ($change['date']) so the two chips can't diverge off different clocks (S24 trap).
    $realChange = fred_real_change($pdo, $stats['net_worth'], (float)$change['from'], (string)$change['date']);
}

// Net-worth composition over time (#6): how the cash/investments/retirement/home/debt mix
// shifted. Derived at read time from account_balance_history (household-wide; sums to the
// net-worth line above). Window selector (6/12/24 mo) via ?comp=, validated to a fixed set.
$compWindows = [180 => 'Last 6 months', 365 => 'Last 12 months', 730 => 'Last 24 months'];
$compDays    = (int)($_GET['comp'] ?? 365);
if (!isset($compWindows[$compDays])) $compDays = 365;
$comp = q_networth_composition($pdo, $compDays);

$assets = array_filter($accounts, fn($a) => !is_liability($a));
$debts  = array_filter($accounts, fn($a) => is_liability($a));

render_header('Net worth', 'networth', ['chart' => true]);

/** Render a list of accounts with balances. */
function nw_account_rows(array $rows, bool $debt): void
{
    foreach ($rows as $a):
        $bal = (float)($a['balance_current'] ?? 0);
        $hay = strtolower(($a['name'] ?: 'Account') . ' ' . ($a['institution_name'] ?: '')); ?>
        <a class="row" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>" data-search="<?= e($hay) ?>">
            <span class="row-main">
                <span class="row-title"><?= e($a['name'] ?: 'Account') ?></span>
                <span class="row-sub"><?= e($a['institution_name'] ?: '') ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?><?= owner_suffix($a['owner_id'] ?? null) ?></span>
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
        <?php if ($realChange['pct'] !== null): $ru = $realChange['pct'] >= 0; ?>
            <span class="delta <?= $ru ? 'up' : 'down' ?>" title="Inflation-adjusted (CPI), vs ~30 days ago"><?= $ru ? '▲' : '▼' ?> <?= number_format(abs($realChange['pct']), 1) ?>%<span class="delta-sub">real 30d</span></span>
        <?php endif; ?>
    </div>
    <div class="hero-value"><?= e(usd($stats['net_worth'])) ?></div>
    <?php if ($change['abs'] !== null): ?>
        <div class="muted"><?= ($change['abs'] >= 0 ? '+' : '−') . e(usd(abs($change['abs']))) ?> over the last 30 days</div>
    <?php endif; ?>

    <?php if (count($snaps) > 1): ?>
        <div class="chart-wrap tall">
            <?php if ($realVals !== null): ?>
                <canvas id="nw-chart" data-chart="multiline" data-src="nw-data"></canvas>
                <script type="application/json" id="nw-data"><?= json_encode([
                    'labels' => array_column($snaps, 'snapshot_date'),
                    'series' => [
                        ['label' => 'Net worth', 'values' => array_map('floatval', array_column($snaps, 'net_worth')), 'color' => 'brand'],
                        ['label' => "Real (today's $)", 'values' => $realVals, 'color' => 'muted', 'dashed' => true],
                    ],
                ], JSON_UNESCAPED_SLASHES) ?></script>
                <p class="muted chart-cap">Dashed line is net worth in today's dollars (CPI-adjusted).</p>
            <?php else: ?>
                <canvas id="nw-chart" data-chart="line" data-src="nw-data"></canvas>
                <script type="application/json" id="nw-data"><?= json_encode([
                    'labels' => array_column($snaps, 'snapshot_date'),
                    'values' => array_map('floatval', array_column($snaps, 'net_worth')),
                ], JSON_UNESCAPED_SLASHES) ?></script>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="muted">Net-worth history will appear as daily snapshots accumulate.</p>
    <?php endif; ?>
</section>

<?php if (count($comp['labels']) > 1): ?>
<section class="card">
    <div class="block-head">
        <h2>Composition over time</h2>
        <form method="get" action="/networth.php" class="head-form">
            <select name="comp" class="select" data-autosubmit aria-label="Period">
                <?php foreach ($compWindows as $d => $lbl): ?>
                    <option value="<?= $d ?>"<?= $d === $compDays ? ' selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
        </form>
    </div>
    <div class="chart-wrap tall">
        <canvas id="comp-chart" data-chart="stackarea" data-src="comp-data"></canvas>
        <script type="application/json" id="comp-data"><?= json_encode([
            'labels' => $comp['labels'],
            'series' => $comp['series'],
        ], JSON_UNESCAPED_SLASHES) ?></script>
    </div>
    <p class="muted chart-cap">The stack nets to your net-worth line above — debt is shown below the axis. History fills in as daily snapshots accumulate.</p>

    <?php // Current mix: the latest column, each band as a share of net worth.
    $cnet = (float)($comp['current']['net'] ?? 0); ?>
    <div class="comp-mix">
        <?php foreach ($comp['series'] as $s):
            $val = end($s['values']);
            if (abs((float)$val) < 0.005) continue;
            $pct   = $cnet != 0.0 ? round($val / $cnet * 100) : 0;
            $debt  = strcasecmp($s['label'], 'Debt') === 0; ?>
            <div class="comp-row">
                <span class="comp-label"><?= e($s['label']) ?></span>
                <span class="comp-amt<?= $debt ? ' neg' : '' ?>"><?= e(($debt && $val < 0 ? '−' : '') . usd(abs((float)$val))) ?></span>
                <span class="comp-pct muted"><?= (int)$pct ?>%</span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (count($accounts) > 8): ?>
<div class="search-bar">
    <input type="search" class="search-input" data-filter="#nw-lists" placeholder="Filter by account or bank…">
</div>
<?php endif; ?>
<div class="cols" id="nw-lists">
<section class="block" data-filter-group>
    <div class="block-head"><h2>Assets</h2><span class="split-value pos"><?= e(usd($stats['assets'])) ?></span></div>
    <div class="rows">
        <?php if ($homeVal > 0): ?>
        <a class="row" href="/property.php" data-search="home market value rentcast estimated">
            <span class="row-main">
                <span class="row-title">Home <span class="mini-tag">estimated</span></span>
                <span class="row-sub">Market value (RentCast AVM)</span>
            </span>
            <span class="row-amt"><?= e(usd($homeVal)) ?></span>
            <span class="chev" aria-hidden="true">›</span>
        </a>
        <?php endif; ?>
        <?php $assets ? nw_account_rows($assets, false) : ($homeVal > 0 ? null : print('<p class="muted" style="padding:1rem">No asset accounts.</p>')); ?>
    </div>
</section>

<section class="block" data-filter-group>
    <div class="block-head"><h2>Liabilities</h2><span class="split-value neg"><?= e(usd($stats['liabilities'])) ?></span></div>
    <div class="rows">
        <?php $debts ? nw_account_rows($debts, true) : print('<p class="muted" style="padding:1rem">No debts. 🎉</p>'); ?>
    </div>
</section>
</div><!-- /.cols -->

<?php render_footer(); ?>
