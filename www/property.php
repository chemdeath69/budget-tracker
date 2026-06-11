<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/home_value.php';      // hv_zip_from_address()
require __DIR__ . '/lib/property_view.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$view = build_property_view($pdo, $uid);

/* tiny local formatters */
$pct   = fn($x) => $x === null ? '—' : number_format((float)$x * 100, 1) . '%';
$fdate = fn($d) => $d ? date('M j, Y', strtotime((string)$d)) : '—';
// JSON_HEX_TAG (+ AMP/APOS/QUOT) so a data-derived label containing "</script>"
// can't break out of the inline <script type="application/json"> chart blobs.
$jenc  = fn($a) => json_encode($a, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

render_header('Home & Mortgage', 'property', ['chart' => true]);

if (!$view):
?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('house') ?></span>
        <h2>No property data yet</h2>
        <p class="muted">Set <code>home.address</code> in config and link a mortgage to see your
            home value, equity and mortgage detail here. Data populates on the next daily sync.</p>
    </div>
<?php
    render_footer();
    return;
endif;

$v = $view['value']; $m = $view['mortgage']; $p = $view['property'];
$mk = $view['market']; $dv = $view['derived']; $ch = $view['charts'];
$rf = $view['refi'] ?? null;
$equity = $dv['equity'] ?? null;
?>

<!-- Hero: equity vs value/mortgage -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label"><?= $equity !== null ? 'Home equity' : 'Home value' ?></span>
        <?php if (isset($dv['ltv'])): ?>
            <span class="delta"><?= e($pct($dv['ltv'])) ?> <span class="delta-sub">LTV</span></span>
        <?php endif; ?>
    </div>
    <div class="hero-value"><?= e(usd($equity !== null ? $equity : ($v['current'] ?? 0))) ?></div>
    <div class="hero-split">
        <div class="split-cell">
            <span class="split-label">Home value<?php if ($v && $v['low'] !== null): ?> <span class="muted">(<?= e(usd($v['low'])) ?>–<?= e(usd($v['high'])) ?>)</span><?php endif; ?></span>
            <span class="split-value pos"><?= e(usd($v['current'] ?? 0)) ?></span>
        </div>
        <?php if ($m): ?>
        <div class="split-cell">
            <span class="split-label"><?= e($m['name']) ?> balance<?= owner_suffix($m['owner_id'] ?? null) ?></span>
            <span class="split-value neg">-<?= e(usd($m['balance'])) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php if (isset($dv['appreciation'])): $up = $dv['appreciation'] >= 0; ?>
    <div class="muted" style="margin-top:.6rem">
        <?= $up ? '▲' : '▼' ?> <?= e(($up ? '+' : '−') . usd(abs($dv['appreciation']))) ?>
        since purchase<?php if (isset($dv['appreciation_annual_pct'])): ?>
            · <?= e($pct($dv['appreciation_annual_pct'])) ?>/yr<?php endif; ?>
        <?php if ($v && $v['as_of']): ?> · est. <?= e($fdate($v['as_of'])) ?><?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<!-- Value over time -->
<?php if (!empty($ch['value'])): ?>
<section class="block">
    <div class="block-head"><h2>Home value over time</h2><span class="muted">est. + range</span></div>
    <div class="card">
        <div class="chart-wrap tall">
            <canvas data-chart="multiline" data-src="c-value"></canvas>
            <script type="application/json" id="c-value"><?= $jenc([
                'labels' => $ch['value']['labels'],
                'series' => [
                    ['label' => '_low',  'values' => $ch['value']['low'],  'color' => 'muted', 'faint' => true, 'legend' => false],
                    ['label' => '_high', 'values' => $ch['value']['high'], 'color' => 'muted', 'faint' => true, 'fillTo' => 0, 'legend' => false],
                    ['label' => 'Estimate', 'values' => $ch['value']['est'], 'color' => 'brand'],
                ],
            ]) ?></script>
        </div>
        <?php if (count($ch['value']['labels']) < 3): ?>
        <p class="muted" style="margin:.4rem 0 0">Starts from your purchase; the trend fills in monthly.</p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- Equity over time -->
<?php if (!empty($ch['equity'])): ?>
<section class="block">
    <div class="block-head"><h2>Equity over time</h2><span class="muted">value − mortgage</span></div>
    <div class="card">
        <div class="chart-wrap tall">
            <canvas data-chart="multiline" data-src="c-equity"></canvas>
            <script type="application/json" id="c-equity"><?= $jenc([
                'labels' => $ch['equity']['labels'],
                'series' => [
                    ['label' => 'Home value', 'values' => $ch['equity']['value'], 'color' => 'brand'],
                    ['label' => 'Mortgage',   'values' => $ch['equity']['debt'],  'color' => 'neg'],
                    ['label' => 'Equity',     'values' => $ch['equity']['equity'], 'color' => 'pos', 'fill' => true],
                ],
            ]) ?></script>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="cols">

<!-- Mortgage payoff -->
<?php if (!empty($ch['payoff'])): ?>
<section class="block">
    <div class="block-head"><h2>Mortgage payoff</h2><span class="muted">projected vs actual</span></div>
    <div class="card">
        <div class="chart-wrap tall">
            <canvas data-chart="multiline" data-src="c-payoff"></canvas>
            <script type="application/json" id="c-payoff"><?= $jenc([
                'labels' => $ch['payoff']['labels'],
                'series' => [
                    ['label' => 'Projected', 'values' => $ch['payoff']['projected'], 'color' => 'muted', 'dashed' => true],
                    ['label' => 'Actual',    'values' => $ch['payoff']['actual'],    'color' => 'brand', 'points' => true],
                ],
            ]) ?></script>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Amortization -->
<?php if (!empty($ch['amort'])): ?>
<section class="block">
    <div class="block-head"><h2>Principal vs interest</h2><span class="muted">cumulative, life of loan</span></div>
    <div class="card">
        <div class="chart-wrap tall">
            <canvas data-chart="multiline" data-src="c-amort"></canvas>
            <script type="application/json" id="c-amort"><?= $jenc([
                'labels' => $ch['amort']['labels'],
                'series' => [
                    ['label' => 'Principal', 'values' => $ch['amort']['principal'], 'color' => 'pos', 'fill' => true],
                    ['label' => 'Interest',  'values' => $ch['amort']['interest'],  'color' => 'neg'],
                ],
            ]) ?></script>
        </div>
    </div>
</section>
<?php endif; ?>

</div><!-- /.cols -->

<div class="cols">

<!-- Mortgage details -->
<?php if ($m): ?>
<section class="block">
    <div class="block-head"><h2>Mortgage details</h2><?php if ($m['pct_paid_off'] !== null): ?><span class="muted"><?= e($pct($m['pct_paid_off'])) ?> paid off</span><?php endif; ?></div>
    <div class="card">
        <div class="kv-grid">
            <div><span class="muted">Balance</span><strong><?= e(usd($m['balance'])) ?></strong></div>
            <?php if ($m['rate'] !== null): ?><div><span class="muted">Interest rate</span><strong><?= e(number_format($m['rate'], 3)) ?>%<?= $m['rate_type'] ? ' ' . e($m['rate_type']) : '' ?></strong></div><?php endif; ?>
            <?php if ($m['loan_term']): ?><div><span class="muted">Term</span><strong><?= e($m['loan_term']) ?><?= $m['loan_type'] ? ' · ' . e($m['loan_type']) : '' ?></strong></div><?php endif; ?>
            <?php if ($m['payment_pi'] !== null): ?><div><span class="muted">Payment (P&amp;I)</span><strong><?= e(usd($m['payment_pi'])) ?>/mo</strong></div><?php endif; ?>
            <?php if ($m['next_payment'] !== null): ?><div><span class="muted">Next payment</span><strong><?= e(usd($m['next_payment'])) ?> · <?= e($fdate($m['next_due_date'])) ?></strong></div><?php endif; ?>
            <?php if ($m['origination_principal'] !== null): ?><div><span class="muted">Original loan</span><strong><?= e(usd($m['origination_principal'])) ?> · <?= e($fdate($m['origination_date'])) ?></strong></div><?php endif; ?>
            <?php if ($m['payoff_date']): ?><div><span class="muted">Payoff date</span><strong><?= e($fdate($m['payoff_date'])) ?></strong></div><?php endif; ?>
            <?php if ($m['interest_to_date'] !== null): ?><div><span class="muted">Interest paid to date</span><strong><?= e(usd($m['interest_to_date'])) ?></strong></div><?php endif; ?>
            <?php if ($m['total_interest_life'] !== null): ?><div><span class="muted">Total interest (life)</span><strong><?= e(usd($m['total_interest_life'])) ?></strong></div><?php endif; ?>
            <?php if ($m['ytd_interest'] !== null): ?><div><span class="muted">YTD interest</span><strong><?= e(usd($m['ytd_interest'])) ?></strong></div><?php endif; ?>
            <?php if ($m['ytd_principal'] !== null): ?><div><span class="muted">YTD principal</span><strong><?= e(usd($m['ytd_principal'])) ?></strong></div><?php endif; ?>
            <?php if ($m['escrow'] !== null): ?><div><span class="muted">Escrow balance</span><strong><?= e(usd($m['escrow'])) ?></strong></div><?php endif; ?>
            <?php if ($m['has_pmi'] !== null): ?><div><span class="muted">PMI</span><strong><?= $m['has_pmi'] ? 'Yes' : 'No' ?></strong></div><?php endif; ?>
            <?php if (!empty($m['past_due'])): ?><div><span class="muted">Past due</span><strong class="neg"><?= e(usd($m['past_due'])) ?></strong></div><?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Rate vs market / refi (FRED 30-yr, #17) -->
<?php if ($rf): ?>
<section class="block">
    <div class="block-head"><h2>Rate vs market</h2><span class="muted">FRED 30-yr · <?= e($fdate($rf['as_of'])) ?></span></div>
    <div class="card refi-card">
        <div class="split">
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
            <p class="refi-note pos">▼ Market is below your rate — refinancing the remaining balance
                could save about <strong><?= e(usd($rf['annual_savings'])) ?>/yr</strong>
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
    </div>
</section>
<?php endif; ?>

<!-- Property facts -->
<?php if ($p): ?>
<section class="block">
    <div class="block-head"><h2>Property</h2></div>
    <div class="card">
        <div class="kv-grid">
            <?php if ($p['purchase_price'] !== null): ?><div><span class="muted">Purchased</span><strong><?= e(usd($p['purchase_price'])) ?><?= $p['purchase_date'] ? ' · ' . e($fdate($p['purchase_date'])) : '' ?></strong></div><?php endif; ?>
            <?php if ($p['type']): ?><div><span class="muted">Type</span><strong><?= e($p['type']) ?></strong></div><?php endif; ?>
            <?php if ($p['beds'] !== null): ?><div><span class="muted">Beds / baths</span><strong><?= e(rtrim(rtrim(number_format((float)$p['beds'],1),'0'),'.')) ?> bd / <?= e(rtrim(rtrim(number_format((float)$p['baths'],1),'0'),'.')) ?> ba</strong></div><?php endif; ?>
            <?php if ($p['sqft'] !== null): ?><div><span class="muted">Living area</span><strong><?= e(number_format((float)$p['sqft'])) ?> sqft</strong></div><?php endif; ?>
            <?php if ($p['lot'] !== null): ?><div><span class="muted">Lot</span><strong><?= e(number_format((float)$p['lot'])) ?> sqft</strong></div><?php endif; ?>
            <?php if ($p['year_built'] !== null): ?><div><span class="muted">Year built</span><strong><?= e($p['year_built']) ?></strong></div><?php endif; ?>
            <?php if ($p['hoa_fee'] !== null): ?><div><span class="muted">HOA</span><strong><?= e(usd($p['hoa_fee'])) ?></strong></div><?php endif; ?>
        </div>
        <?php if ($p['features']): ?>
        <div class="chips" style="margin-top:.7rem">
            <?php foreach ($p['features'] as $f): ?><span class="mini-tag"><?= e($f) ?></span> <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

</div><!-- /.cols -->

<!-- Assessed value + property tax -->
<?php if (!empty($ch['assessed']) || !empty($ch['tax'])): ?>
<div class="cols">
    <?php if (!empty($ch['assessed'])): ?>
    <section class="block">
        <div class="block-head"><h2>Assessed value</h2><span class="muted">by year</span></div>
        <div class="card"><div class="chart-wrap">
            <canvas data-chart="bars" data-src="c-assessed"></canvas>
            <script type="application/json" id="c-assessed"><?= $jenc(['labels' => $ch['assessed']['labels'], 'values' => $ch['assessed']['values'], 'color' => 'brand']) ?></script>
        </div></div>
    </section>
    <?php endif; ?>
    <?php if (!empty($ch['tax'])): ?>
    <section class="block">
        <div class="block-head"><h2>Property tax</h2><span class="muted">by year</span></div>
        <div class="card"><div class="chart-wrap">
            <canvas data-chart="bars" data-src="c-tax"></canvas>
            <script type="application/json" id="c-tax"><?= $jenc(['labels' => $ch['tax']['labels'], 'values' => $ch['tax']['values'], 'color' => 'neg']) ?></script>
        </div></div>
    </section>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Local market context -->
<?php if ($mk): ?>
<section class="block">
    <div class="block-head"><h2>Local market</h2><span class="muted">ZIP <?= e($mk['zip']) ?></span></div>
    <div class="card">
        <div class="hero-split">
            <?php if ($mk['median_price'] !== null): ?><div class="split-cell"><span class="split-label">Median price</span><span class="split-value"><?= e(usd($mk['median_price'])) ?></span></div><?php endif; ?>
            <?php if ($mk['ppsf'] !== null): ?><div class="split-cell"><span class="split-label">$/sqft</span><span class="split-value"><?= e(usd($mk['ppsf'])) ?></span></div><?php endif; ?>
            <?php if ($mk['dom'] !== null): ?><div class="split-cell"><span class="split-label">Days on market</span><span class="split-value"><?= e(number_format((float)$mk['dom'], 0)) ?></span></div><?php endif; ?>
        </div>
        <?php if (!empty($ch['market'])): ?>
        <div class="chart-wrap" style="margin-top:.7rem">
            <canvas data-chart="line" data-src="c-market"></canvas>
            <script type="application/json" id="c-market"><?= $jenc(['labels' => $ch['market']['labels'], 'values' => $ch['market']['values']]) ?></script>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php render_footer(); ?>
