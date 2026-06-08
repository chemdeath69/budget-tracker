<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo   = db();
$uid   = current_user_id();
$holds = q_holdings($pdo, $uid);

/* ---- Portfolio totals ---------------------------------------------------- */
$total    = 0.0;   // market value of every holding
$costSum  = 0.0;   // cost basis where we have it
$valCost  = 0.0;   // market value of holdings that have a cost basis (so gain% lines up)
$haveCost = 0;
foreach ($holds as $h) {
    $val  = (float)($h['institution_value'] ?? 0);
    $total += $val;
    if ($h['cost_basis'] !== null) {
        $haveCost++;
        $costSum += (float)$h['cost_basis'];
        $valCost += $val;
    }
}
$gain     = $haveCost ? $valCost - $costSum : null;
$gainPct  = ($haveCost && $costSum != 0.0) ? round(($gain / abs($costSum)) * 100, 1) : null;
$partial  = $haveCost > 0 && $haveCost < count($holds); // some holdings lack a cost basis

/* ---- Price-based change over time (day / week / month / year) ------------- */
$HORIZONS = ['d1' => 'Today', 'd7' => '1 wk', 'd30' => '1 mo', 'd365' => '1 yr'];
$changes  = q_price_changes($pdo, array_map(fn($h) => $h['security_id'] ?? null, $holds));
$priceAsOf = null;
$pf = [];
foreach (array_keys($HORIZONS) as $k) $pf[$k] = ['abs' => 0.0, 'base' => 0.0, 'has' => false];
foreach ($holds as $h) {
    $c = $changes[$h['security_id']] ?? null;
    if (!$c) continue;
    if ($priceAsOf === null || $c['date'] > $priceAsOf) $priceAsOf = $c['date'];
    if ($h['quantity'] === null || $c['current'] === null) continue;
    $qty = (float)$h['quantity'];
    foreach (array_keys($HORIZONS) as $k) {
        if ($c[$k] === null) continue;
        $pf[$k]['abs']  += ($c['current'] - $c[$k]) * $qty;
        $pf[$k]['base'] += $c[$k] * $qty;
        $pf[$k]['has']   = true;
    }
}
$hasPerf = (bool)array_filter($pf, fn($x) => $x['has']);
$history = q_portfolio_history($pdo, $holds); // [['date','value'],…] at current holdings

/* ---- Group holdings by account (drill-down + freshness + gaps) ------------ */
$byAccount = [];
foreach ($holds as $h) {
    $aid = $h['account_id'];
    if (!isset($byAccount[$aid])) {
        $byAccount[$aid] = [
            'name'    => $h['account_name'],
            'mask'    => $h['mask'],
            'inst'    => $h['institution_name'],
            'source'  => $h['source'],
            'asof'    => $h['last_updated_datetime'],
            'value'   => 0.0,
            'rows'    => [],
        ];
    }
    $byAccount[$aid]['value'] += (float)($h['institution_value'] ?? 0);
    $byAccount[$aid]['rows'][] = $h;
}
// Statement-coverage gaps for the manual accounts that hold positions.
$coverage = [];
foreach ($byAccount as $aid => $g) {
    if (($g['source'] ?? 'plaid') === 'manual') $coverage[$aid] = q_statement_coverage($pdo, $aid);
}

/* ---- Allocation donut (by holding) --------------------------------------- */
$alloc = [];
foreach ($holds as $h) {
    $label = $h['ticker_symbol'] ?: ($h['security_name'] ?: '—');
    $alloc[$label] = ($alloc[$label] ?? 0) + (float)($h['institution_value'] ?? 0);
}
arsort($alloc);

/* ---- Brokerage income + trades (from manual feeds) ----------------------- */
$income = q_investment_activity($pdo, $uid, ['INCOME_DIVIDENDS', 'INCOME_INTEREST'], 50);
$trades = q_investment_activity($pdo, $uid, ['INVESTMENT'], 50);
$incomeTotal = 0.0;
foreach ($income as $r) $incomeTotal += -(float)$r['amount']; // stored − = money in

/* Tax summaries for each manual investment account we're showing. */
$taxByAccount = [];
foreach ($byAccount as $aid => $g) {
    if (($g['source'] ?? 'plaid') === 'manual') {
        $t = q_tax_summaries($pdo, $aid);
        if ($t) $taxByAccount[$aid] = $t;
    }
}

render_header('Investments', 'investments', ['chart' => true]);

/** A holding's value change over $key (e.g. 'd30') as [abs, pct] or null. */
function hold_change(?array $c, ?float $qty, string $key): ?array
{
    if (!$c || $qty === null || $c['current'] === null || ($c[$key] ?? null) === null) return null;
    $abs  = ($c['current'] - $c[$key]) * $qty;
    $base = $c[$key] * $qty;
    return [$abs, $base != 0.0 ? $abs / abs($base) * 100 : null];
}
?>

<?php if (!$holds): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('invest') ?></span>
        <h2>No holdings yet</h2>
        <p class="muted">Link a brokerage account (or re-link an existing one to grant investment access) to see your holdings here.</p>
        <a class="btn" href="/link.php">Link an account</a>
    </div>
<?php else: ?>
    <section class="card hero">
        <div class="hero-top">
            <span class="hero-label">Total holdings value</span>
            <?php if ($gainPct !== null): $up = $gain >= 0; ?>
                <span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= number_format(abs($gainPct), 1) ?>%<span class="delta-sub">total</span></span>
            <?php endif; ?>
        </div>
        <div class="hero-value"><?= e(usd($total)) ?></div>
        <?php if ($gain !== null): ?>
            <div class="muted">
                <?= ($gain >= 0 ? '+' : '−') . e(usd(abs($gain))) ?> total gain/loss<?php if ($partial): ?> · <span title="Some holdings have no cost basis yet">excludes holdings without cost basis</span><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="muted">Cost basis isn't available yet, so gain/loss can't be shown.</div>
        <?php endif; ?>
    </section>

    <?php if ($hasPerf): ?>
    <section class="block">
        <div class="block-head"><h2>Performance</h2><?php if ($priceAsOf): ?><span class="muted">as of <?= e($priceAsOf) ?></span><?php endif; ?></div>
        <div class="card perf-grid">
            <?php foreach ($HORIZONS as $k => $lbl): $x = $pf[$k]; ?>
            <div class="perf-cell">
                <span class="perf-label"><?= e($lbl) ?></span>
                <?php if ($x['has']): $up = $x['abs'] >= 0; $pct = $x['base'] != 0.0 ? ($x['abs'] / abs($x['base'])) * 100 : null; ?>
                    <span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= $pct !== null ? e(number_format(abs($pct), 1)) . '%' : '' ?></span>
                    <span class="perf-sub muted"><?= ($x['abs'] >= 0 ? '+' : '−') . e(usd(abs($x['abs']))) ?></span>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (count($history) > 1): ?>
    <section class="block">
        <div class="block-head"><h2>Value over time</h2><span class="muted">at current holdings</span></div>
        <div class="card">
            <div class="chart-wrap tall">
                <canvas id="pv-chart" data-chart="line" data-src="pv-data"></canvas>
                <script type="application/json" id="pv-data"><?= json_encode([
                    'labels' => array_column($history, 'date'),
                    'values' => array_map('floatval', array_column($history, 'value')),
                ], JSON_UNESCAPED_SLASHES) ?></script>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="cols">
    <!-- Allocation -->
    <section class="block">
        <div class="block-head"><h2>Allocation</h2></div>
        <div class="card">
            <div class="chart-wrap">
                <canvas id="alloc-chart" data-chart="doughnut" data-src="alloc-data"></canvas>
                <script type="application/json" id="alloc-data"><?= json_encode([
                    'labels' => array_keys($alloc),
                    'values' => array_map(fn($v) => round($v, 2), array_values($alloc)),
                ], JSON_UNESCAPED_SLASHES) ?></script>
            </div>
            <div class="rows">
                <?php $i = 0; foreach ($alloc as $label => $val): if ($total <= 0) break; ?>
                <div class="row">
                    <span class="row-main">
                        <span class="row-title"><span class="cat-swatch" style="background:hsl(<?= ($i * 67) % 360 ?>,65%,55%)"></span> <?= e($label) ?></span>
                    </span>
                    <span class="row-amt"><?= e(number_format($val / $total * 100, 1)) ?>%</span>
                </div>
                <?php $i++; endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Brokerage income -->
    <section class="block">
        <div class="block-head"><h2>Dividends &amp; interest</h2><?php if ($incomeTotal > 0): ?><span class="split-value pos"><?= e(usd($incomeTotal)) ?></span><?php endif; ?></div>
        <div class="rows card">
            <?php if (!$income): ?>
                <p class="muted" style="padding:1rem">No dividend or interest activity recorded yet. It appears here as brokerage statements are uploaded.</p>
            <?php else: foreach ($income as $r): $in = -(float)$r['amount']; ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title"><?= e($r['merchant_name'] ?: ($r['name'] ?: 'Income')) ?></span>
                    <span class="row-sub"><span class="tx-date"><?= e($r['date']) ?></span> · <?= e(pretty_cat($r['pfc_primary'])) ?></span>
                </span>
                <span class="row-amt pos">+<?= e(usd($in)) ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </section>
    </div><!-- /.cols -->

    <!-- Holdings, grouped by account -->
    <?php foreach ($byAccount as $aid => $g):
        $cov = $coverage[$aid] ?? null;
        $asof = $cov['latest'] ?? ($g['asof'] ? substr((string)$g['asof'], 0, 7) : null); ?>
    <section class="block">
        <div class="block-head">
            <h2><a href="/account.php?account_id=<?= e(urlencode($aid)) ?>"><?= e($g['name'] ?: 'Account') ?></a><?= $g['mask'] ? ' <span class="muted">••' . e($g['mask']) . '</span>' : '' ?></h2>
            <span class="split-value"><?= e(usd($g['value'])) ?></span>
        </div>
        <?php if ($asof): ?>
            <p class="muted asof-line">Holdings as of <?= e($asof) ?><?= ($g['source'] ?? '') === 'manual' ? ' statement' : '' ?>.</p>
        <?php endif; ?>
        <?php if ($cov && $cov['missing']): ?>
            <div class="notice warn">Missing statement<?= count($cov['missing']) > 1 ? 's' : '' ?> for <?= e(implode(', ', $cov['missing'])) ?> — dividend, trade and tax figures for this account may be incomplete.</div>
        <?php endif; ?>
        <div class="rows card">
            <?php foreach ($g['rows'] as $h):
                $sec   = ($h['ticker_symbol'] ? $h['ticker_symbol'] . ' — ' : '') . ($h['security_name'] ?: '—');
                $val   = $h['institution_value'] !== null ? (float)$h['institution_value'] : null;
                $cb    = $h['cost_basis'] !== null ? (float)$h['cost_basis'] : null;
                $hgain = ($val !== null && $cb !== null) ? $val - $cb : null;
                $hpct  = ($hgain !== null && $cb != 0.0) ? round($hgain / abs($cb) * 100, 1) : null;
                $c     = $changes[$h['security_id']] ?? null;
                $qty   = $h['quantity'] !== null ? (float)$h['quantity'] : null; ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title"><?= e($sec) ?></span>
                    <span class="row-sub">
                        <?php if ($h['quantity'] !== null): ?><?= e(number_format((float)$h['quantity'], 4)) ?> @ <?= e(usd($h['institution_price'])) ?><?php endif; ?>
                        <?php if ($cb !== null): ?> · cost <?= e(usd($cb)) ?><?php endif; ?>
                    </span>
                    <?php if ($c && $qty !== null && $c['current'] !== null): ?>
                    <span class="chg-strip">
                        <?php foreach ($HORIZONS as $k => $lbl): $d = hold_change($c, $qty, $k); if (!$d) continue; $up = $d[0] >= 0; ?>
                        <span class="chg <?= $up ? 'up' : 'down' ?>"><span class="chg-l"><?= e($lbl) ?></span> <?= $up ? '▲' : '▼' ?><?= $d[1] !== null ? e(number_format(abs($d[1]), 1)) . '%' : '' ?></span>
                        <?php endforeach; ?>
                    </span>
                    <?php endif; ?>
                </span>
                <span class="row-side">
                    <span class="row-amt"><?= $val !== null ? e(usd($val)) : '—' ?></span>
                    <?php if ($hgain !== null): ?>
                        <span class="delta <?= $hgain >= 0 ? 'up' : 'down' ?>"><?= $hgain >= 0 ? '▲' : '▼' ?> <?= ($hgain >= 0 ? '+' : '−') . e(usd(abs($hgain))) ?><?php if ($hpct !== null): ?> (<?= e(number_format(abs($hpct), 1)) ?>%)<?php endif; ?></span>
                    <?php elseif ($val !== null): ?>
                        <span class="muted mini-tag">cost basis pending</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <!-- Recent trades -->
    <?php if ($trades): ?>
    <section class="block">
        <div class="block-head"><h2>Recent trades</h2><span class="count-pill"><?= count($trades) ?></span></div>
        <div class="rows card">
            <?php foreach ($trades as $t): $amt = (float)$t['amount']; ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title"><?= e($t['name'] ?: ($t['merchant_name'] ?: 'Trade')) ?></span>
                    <span class="row-sub"><span class="tx-date"><?= e($t['date']) ?></span> · <?= e($t['account_name'] ?: '') ?></span>
                </span>
                <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Tax summaries (manual 1099) -->
    <?php foreach ($taxByAccount as $aid => $taxes): ?>
    <section class="block">
        <div class="block-head"><h2>Tax summary (1099) — <?= e($byAccount[$aid]['name'] ?? 'Account') ?></h2></div>
        <div class="card">
        <?php foreach ($taxes as $tx): ?>
        <div class="tax-year">
            <div class="tax-year-head"><strong><?= e($tx['tax_year']) ?></strong></div>
            <div class="kv-grid">
                <div><span class="muted">Ordinary dividends</span><strong><?= e(usd($tx['ordinary_dividends'])) ?></strong></div>
                <div><span class="muted">Qualified dividends</span><strong><?= e(usd($tx['qualified_dividends'])) ?></strong></div>
                <?php if ($tx['interest_income'] !== null): ?><div><span class="muted">Interest income</span><strong><?= e(usd($tx['interest_income'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['capital_gain_distributions'] !== null): ?><div><span class="muted">Cap. gain distributions</span><strong><?= e(usd($tx['capital_gain_distributions'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['proceeds'] !== null): ?><div><span class="muted">Sale proceeds</span><strong><?= e(usd($tx['proceeds'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['cost_basis'] !== null): ?><div><span class="muted">Cost basis (sold)</span><strong><?= e(usd($tx['cost_basis'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['net_gain_loss'] !== null): ?><div><span class="muted">Net gain/loss</span><strong><?= e(usd($tx['net_gain_loss'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['federal_tax_withheld'] !== null): ?><div><span class="muted">Fed. tax withheld</span><strong><?= e(usd($tx['federal_tax_withheld'])) ?></strong></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_footer(); ?>
