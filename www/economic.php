<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/economic.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$view = build_economic_view($pdo, $uid);

// Per-unit latest-value formatter: rates as "6.91%", the CPI index as a plain level.
$fval = function (array $s): string {
    if (!$s['latest']) return '—';
    $v = (float)$s['latest']['value'];
    return $s['unit'] === 'pct' ? number_format($v, 2) . '%' : number_format($v, 1);
};
$fdate = fn($d) => $d ? date('M j, Y', strtotime((string)$d)) : '';

render_header('Economic', 'economic', ['chart' => true, 'narrow' => true]);
?>

<?php if (!$view['has_data']): ?>
    <div class="empty-state card">
        <h2>Economic indicators</h2>
        <p class="muted">This page pulls free macro data from <strong>FRED</strong> (the St. Louis
            Fed) — inflation (CPI), the 30-year mortgage rate and Treasury / Fed-funds yields — to
            show your <em>real</em> (inflation-adjusted) net worth, whether your mortgage beats the
            market, and how your cash compares to risk-free rates.</p>
        <p class="muted">Set <code>fred.api_key</code> in config (a free key from
            <code>fredaccount.stlouisfed.org</code>). Data populates on the next daily sync.</p>
    </div>
    <?php render_footer(); return; ?>
<?php endif; ?>

<?php $r = $view['real']; $infl = $view['inflation']; $rf = $view['refi']; $sv = $view['savings']; ?>

<!-- Insight: real (inflation-adjusted) net worth -->
<?php if ($r): ?>
<section class="block">
    <div class="block-head"><h2>Real net worth</h2><span class="muted">today's dollars (CPI-adjusted)</span></div>
    <div class="card">
        <div class="hero-split tri">
            <div class="split-cell">
                <span class="split-label">Net worth</span>
                <span class="split-value"><?= e(usd($r['current'])) ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">Nominal growth</span>
                <span class="split-value <?= ($r['nominal_growth'] ?? 0) < 0 ? 'neg' : 'pos' ?>"><?= $r['nominal_growth'] === null ? '—' : (($r['nominal_growth'] >= 0 ? '+' : '−') . number_format(abs($r['nominal_growth']), 1) . '%') ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">Real growth</span>
                <span class="split-value <?= ($r['real_growth'] ?? 0) < 0 ? 'neg' : 'pos' ?>"><?= $r['real_growth'] === null ? '—' : (($r['real_growth'] >= 0 ? '+' : '−') . number_format(abs($r['real_growth']), 1) . '%') ?></span>
            </div>
        </div>
        <div class="chart-wrap tall">
            <canvas data-chart="multiline" data-src="econ-real"></canvas>
            <script type="application/json" id="econ-real"><?= json_encode([
                'labels' => $r['labels'],
                'series' => [
                    ['label' => 'Nominal', 'values' => $r['nominal'], 'color' => 'brand'],
                    ['label' => "Real (today's $)", 'values' => $r['real'], 'color' => 'muted', 'dashed' => true],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted chart-cap">Growth measured since <?= e($fdate($r['from_date'])) ?>. The gap
            between the lines is inflation's bite on purchasing power.</p>
    </div>
</section>
<?php endif; ?>

<!-- Insight: mortgage rate vs market / refi -->
<?php if ($rf): ?>
<section class="block">
    <div class="block-head"><h2>Mortgage vs market</h2><span class="muted">FRED 30-yr · <?= e($fdate($rf['as_of'])) ?></span></div>
    <div class="card refi-card">
        <div class="hero-split">
            <div class="split-cell">
                <span class="split-label">Your rate</span>
                <span class="split-value"><?= e(number_format($rf['your_rate'], 3)) ?>%</span>
            </div>
            <div class="split-cell">
                <span class="split-label">Market 30-yr</span>
                <span class="split-value"><?= e(number_format($rf['market_rate'], 2)) ?>%</span>
            </div>
        </div>
        <?php if ($rf['beneficial']): ?>
            <p class="refi-note pos">▼ Refinancing the remaining balance could save about
                <strong><?= e(usd($rf['annual_savings'])) ?>/yr</strong>
                (<?= e(usd($rf['monthly_savings'])) ?>/mo), up to
                <strong><?= e(usd($rf['lifetime_interest_savings'])) ?></strong> in interest over the
                remaining term. <span class="muted">Estimate — excludes closing costs &amp; points.</span></p>
        <?php elseif ($rf['market_rate'] >= $rf['your_rate']): ?>
            <p class="refi-note muted">Your rate beats (or matches) today's market 30-yr average — no
                refinancing benefit right now.</p>
        <?php else: ?>
            <p class="refi-note muted">Today's 30-yr is only <?= e(number_format($rf['your_rate'] - $rf['market_rate'], 2)) ?>pp
                below your rate — likely not worth refinancing once closing costs are factored in.</p>
        <?php endif; ?>
        <p class="muted chart-cap"><a href="/property.php">See full mortgage detail →</a></p>
    </div>
</section>
<?php endif; ?>

<!-- Insight: savings-rate benchmark (#38) -->
<?php if ($sv && $sv['has_accounts']): ?>
<section class="block">
    <div class="block-head"><h2>Savings rate</h2><span class="muted">your cash vs the market</span></div>
    <div class="card">
        <div class="hero-split tri">
            <div class="split-cell">
                <span class="split-label">National average</span>
                <span class="split-value"><?= $sv['national_rate'] === null ? '—' : e(number_format($sv['national_rate'], 2)) . '%' ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">Top high-yield</span>
                <span class="split-value"><?= $sv['top_rate'] === null ? '—' : e(number_format($sv['top_rate'], 2)) . '%' ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">You could earn</span>
                <span class="split-value pos"><?= ($sv['opportunity'] === null || $sv['opportunity'] < 1) ? '—' : '+' . e(usd($sv['opportunity'])) . '/yr' ?></span>
            </div>
        </div>
        <?php if ($sv['opportunity'] !== null && $sv['opportunity'] >= 1): ?>
            <p class="refi-note pos">▲ Some of your cash is earning less than a competitive high-yield account.
                Moving it could earn about <strong><?= e(usd($sv['opportunity'])) ?>/yr</strong> more — see the
                per-account breakdown below.</p>
        <?php elseif ($sv['has_estimate']): ?>
            <p class="refi-note muted">Your cash is already earning about as much as a top-yield account — nice.</p>
        <?php endif; ?>
        <?php if ($sv['blended_rate'] !== null): ?>
            <p class="muted chart-cap">Your cash currently earns about
                <strong><?= e(number_format($sv['blended_rate'], 2)) ?>%</strong> blended across your accounts.</p>
        <?php endif; ?>

        <?php if ($sv['accounts']): ?>
        <div class="apy-list">
            <?php foreach ($sv['accounts'] as $row): ?>
            <div class="apy-row">
                <span class="apy-name"><?= e($row['name']) ?><?= owner_suffix($row['owner_id']) ?></span>
                <span class="apy-bal"><?= e(usd($row['balance'])) ?></span>
                <span class="apy-rate"><?php if ($row['rate'] === null): ?>—<?php else: ?><?= e(number_format($row['rate'], 2)) ?>%<?php if ($row['confidence'] === 'low'): ?><span class="apy-est"> est</span><?php endif; ?><?php endif; ?></span>
                <span class="apy-could"><?php if ($row['could_earn'] !== null && $row['could_earn'] >= 1): ?><span class="pos">+<?= e(usd($row['could_earn'])) ?>/yr</span><?php else: ?>—<?php endif; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p class="muted chart-cap">Your rate is estimated from the interest your bank actually credited over
            the last 12 months, measured against your current balance — an approximation, not your bank's
            stated APY (a one-off bonus or a balance that moved a lot will skew it; <em>est</em> = limited
            history). National average is the FDIC national savings rate<?php if (!empty($sv['national_as_of'])): ?>
            (<?= e($fdate($sv['national_as_of'])) ?>)<?php endif; ?>; top high-yield uses the Fed-funds rate as
            a proxy for a competitive online savings account. Keep enough cash liquid for spending — a rough
            comparison, not advice.</p>
    </div>
</section>
<?php endif; ?>

<!-- Raw series trends -->
<section class="block">
    <div class="block-head"><h2>Indicators</h2><span class="muted">latest · FRED</span></div>
    <div class="cols">
        <?php $i = 0; foreach ($view['series'] as $sid => $s): if (!$s['latest']) continue; $i++; ?>
        <div class="card econ-series">
            <div class="econ-series-head">
                <span class="econ-series-label"><?= e($s['label']) ?></span>
                <span class="econ-series-val"><?= e($fval($s)) ?><?php
                    if ($sid === 'CPIAUCSL' && $infl): $iu = $infl['yoy'] >= 0; ?>
                    <span class="muted econ-yoy"><?= $iu ? '+' : '−' ?><?= e(number_format(abs($infl['yoy']), 1)) ?>% YoY</span>
                <?php endif; ?></span>
            </div>
            <span class="muted econ-series-date">as of <?= e($fdate($s['latest']['date'])) ?></span>
            <?php if (count($s['history']) > 1): ?>
            <div class="sparkline">
                <canvas data-chart="spark" data-src="econ-spark-<?= (int)$i ?>" height="48"></canvas>
            </div>
            <script type="application/json" id="econ-spark-<?= (int)$i ?>"><?= json_encode([
                'labels' => array_column($s['history'], 'date'),
                'values' => array_map('floatval', array_column($s['history'], 'value')),
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php render_footer(); ?>
