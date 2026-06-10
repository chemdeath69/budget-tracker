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
            ], JSON_UNESCAPED_SLASHES) ?></script>
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

<!-- Insight: savings-rate context -->
<?php if ($sv): ?>
<section class="block">
    <div class="block-head"><h2>Cash vs benchmark</h2><span class="muted">idle checking + savings</span></div>
    <div class="card">
        <div class="hero-split tri">
            <div class="split-cell">
                <span class="split-label">Idle cash</span>
                <span class="split-value"><?= e(usd($sv['cash'])) ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label"><?= e($sv['bench_label']) ?></span>
                <span class="split-value"><?= $sv['bench_rate'] === null ? '—' : e(number_format($sv['bench_rate'], 2)) . '%' ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">≈ per year</span>
                <span class="split-value pos"><?= $sv['annual_at_bench'] === null ? '—' : e(usd($sv['annual_at_bench'])) ?></span>
            </div>
        </div>
        <p class="muted chart-cap">Your idle cash could earn roughly
            <?= $sv['annual_at_bench'] === null ? '—' : '<strong>' . e(usd($sv['annual_at_bench'])) . '/yr</strong>' ?>
            at the <?= e(strtolower($sv['bench_label'])) ?><?php if ($sv['t10']): ?>
            (10-yr Treasury: <?= e(number_format((float)$sv['t10']['value'], 2)) ?>%)<?php endif; ?> —
            a rough risk-free comparison, not advice.</p>
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
            ], JSON_UNESCAPED_SLASHES) ?></script>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php render_footer(); ?>
