<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/retirement.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$view = build_retirement_view($pdo, $uid);

$accounts = $view['accounts'];
$total    = $view['total'];
$vs       = $view['value_series'];
$prev     = count($vs) >= 2 ? (float)$vs[count($vs) - 2]['value'] : null;
$chgAbs   = $prev !== null ? $total - $prev : null;
$chgPct   = ($prev !== null && $prev != 0.0) ? $chgAbs / abs($prev) * 100 : null;
$proj     = $view['projection'];

/* ---- Investment activity for the retirement accounts shown here (#18, #25) ----
 * Dividends/interest + trades come from q_investment_activity() scoped to THIS page's
 * retirement accounts: Plaid brokerages (e.g. Betterment) AND manual 401(k)s whose
 * statement import (Session 55, #25) wrote dividend/capital-gain/fee rows tagged
 * ext_source='manual_ret'. A manual account with no imported activity simply
 * contributes no rows. */
$invAcct     = trim((string)($_GET['iacct'] ?? ''));
$rdPage      = page_num('dpage');
$rtPage      = page_num('tpage');
$rcPage      = page_num('cpage');
$retAcctOpts = [];
foreach ($view['cards'] as $c) {
    $retAcctOpts[(string)$c['account']['account_id']] = $c['account']['name'] ?: 'Account';
}
$retScope = ($invAcct !== '' && isset($retAcctOpts[$invAcct])) ? [$invAcct] : array_keys($retAcctOpts);

$retIncomeRaw  = $retScope ? q_investment_activity($pdo, $uid, 'income', $retScope, PAGE_SIZE + 1, page_offset($rdPage)) : [];
$retIncomeNext = count($retIncomeRaw) > PAGE_SIZE;
$retIncome     = array_slice($retIncomeRaw, 0, PAGE_SIZE);
$retTradesRaw  = $retScope ? q_investment_activity($pdo, $uid, 'trades', $retScope, PAGE_SIZE + 1, page_offset($rtPage)) : [];
$retTradesNext = count($retTradesRaw) > PAGE_SIZE;
$retTrades     = array_slice($retTradesRaw, 0, PAGE_SIZE);
// Contributions (payroll 401(k) deposits, Plaid) — separate so they don't inflate the
// dividend/interest total; the account balance already reflects them.
$retContribRaw  = $retScope ? q_investment_activity($pdo, $uid, 'contributions', $retScope, PAGE_SIZE + 1, page_offset($rcPage)) : [];
$retContribNext = count($retContribRaw) > PAGE_SIZE;
$retContribs    = array_slice($retContribRaw, 0, PAGE_SIZE);
$retContribTotal = $retScope ? -q_investment_activity_total($pdo, $uid, 'contributions', $retScope) : 0.0;
$retIncomeTotal = $retScope ? -q_investment_activity_total($pdo, $uid, 'income', $retScope) : 0.0;

/** Friendly description of the growth-rate basis. */
function ret_rate_note(array $view): string
{
    $pct = number_format($view['rate'] * 100, 1) . '%';
    switch ($view['rate_basis']) {
        case 'override': return $pct . ' — your set rate';
        case 'derived':  return $pct . ' — derived from ' . $view['derived']['pairs']
            . ' period' . ($view['derived']['pairs'] === 1 ? '' : 's')
            . ' of history';
        default:         return $pct . ' — default (derives from ≥6 months of account history)';
    }
}

render_header('Retirement', 'retirement', ['chart' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Invest</p>
    <h1>Retirement</h1>
</div>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if (!$accounts): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('nest') ?></span>
        <h2>No retirement accounts yet</h2>
        <p class="muted">Track 401(k)s that only send paper statements. Add one, then enter each
            statement (balance + contributions) to chart your growth and project your retirement.</p>
        <a class="btn" href="/retirement_add.php">Add a 401(k)</a>
    </div>
<?php else: ?>

    <!-- Chart leads: combined total + change, then the value-over-time line -->
    <section class="card">
        <div class="chart-lead-head">
            <div class="lead-fig">
                <span class="eyebrow">Retirement total</span>
                <div class="big"><?= e(usd($total)) ?></div>
            </div>
            <?php if ($chgPct !== null): $up = $chgPct >= 0; ?>
            <div class="lead-deltas">
                <span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= number_format(abs($chgPct), 1) ?>%<span class="delta-sub">since last</span></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if (count($vs) > 1): ?>
        <div class="chart-wrap tall">
            <canvas data-chart="line" data-src="ret-value-data"></canvas>
            <script type="application/json" id="ret-value-data"><?= json_encode([
                'labels' => array_column($vs, 'date'),
                'values' => array_map('floatval', array_column($vs, 'value')),
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted chart-cap">Value at each statement.</p>
        <?php else: ?>
        <p class="muted">Value history will appear as statements and balances accumulate.</p>
        <?php endif; ?>
    </section>

    <!-- KPI strip: accounts, contributions, growth at a glance -->
    <div class="kpis">
        <div class="kpi"><span class="eyebrow">Accounts</span><div class="v"><?= count($accounts) ?></div></div>
        <div class="kpi"><span class="eyebrow">Contributed YTD</span><div class="v pos"><?= e(usd($view['ytd']['total'])) ?></div></div>
        <div class="kpi"><span class="eyebrow">Last 12 months</span><div class="v"><?= e(usd($view['ttm_contrib'])) ?></div></div>
        <div class="kpi"><span class="eyebrow">Growth rate</span><div class="v"><?= e(number_format($view['rate'] * 100, 1)) ?>%</div></div>
    </div>

    <!-- Contributions -->
    <section class="block">
        <div class="block-head"><h2>Contributions</h2><span class="muted">this year</span></div>
        <section class="card">
            <div class="kv-grid">
                <div><span class="muted">Your contribution YTD</span><strong><?= e(usd($view['ytd']['employee'])) ?></strong></div>
                <div><span class="muted">Employer match YTD</span><strong class="pos"><?= e(usd($view['ytd']['employer'])) ?></strong></div>
                <div><span class="muted">Total YTD</span><strong><?= e(usd($view['ytd']['total'])) ?></strong></div>
                <div><span class="muted">Last 12 months</span><strong><?= e(usd($view['ttm_contrib'])) ?></strong></div>
            </div>
            <?php if ($view['contrib_periods']): ?>
            <div class="chart-wrap" style="height:200px;margin-top:1rem">
                <canvas data-chart="bars" data-src="ret-contrib-data"></canvas>
            </div>
            <script type="application/json" id="ret-contrib-data"><?= json_encode([
                'labels' => array_keys($view['contrib_periods']),
                'values' => array_map(fn($p) => round($p['ee'] + $p['er'], 2), array_values($view['contrib_periods'])),
                'color'  => 'pos',
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <?php endif; ?>
        </section>
    </section>

    <!-- Projection -->
    <section class="block">
        <div class="block-head">
            <h2>Projection</h2>
            <a class="block-link" href="/retirement_settings.php">Assumptions ›</a>
        </div>
        <?php if (!$proj): ?>
        <section class="card">
            <p class="muted">Set a target retirement year to project your combined balance forward.</p>
            <a class="btn" href="/retirement_settings.php">Set assumptions</a>
        </section>
        <?php else: ?>
        <section class="hero card">
            <div class="hero-top">
                <span class="hero-label">Projected at <?= e((string)$proj['target_year']) ?></span>
                <span class="delta-sub muted"><?= $proj['years'] ?> yr<?= $proj['years'] === 1 ? '' : 's' ?></span>
            </div>
            <div class="hero-value"><?= e(usd($proj['projected'])) ?></div>
            <div class="chart-wrap" style="height:240px">
                <canvas data-chart="multiline" data-src="ret-proj-data"></canvas>
            </div>
            <?php
            $series = [[
                'label'  => 'Projected balance',
                'values' => array_map(fn($p) => $p['value'], $proj['series']),
                'color'  => 'brand', 'fill' => true,
            ]];
            if ($proj['target_amount']) {
                $series[] = [
                    'label'  => 'Target',
                    'values' => array_fill(0, count($proj['series']), (float)$proj['target_amount']),
                    'color'  => 'muted', 'dashed' => true,
                ];
            }
            ?>
            <script type="application/json" id="ret-proj-data"><?= json_encode([
                'labels' => array_map(fn($p) => (string)$p['year'], $proj['series']),
                'series' => $series,
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

            <div class="kv-grid" style="margin-top:1rem">
                <div><span class="muted">Growth rate</span><strong><?= e(ret_rate_note($view)) ?></strong></div>
                <div><span class="muted">Annual contribution</span><strong><?= e(usd($proj['annual_contrib'])) ?><?= $view['settings']['annual_contribution'] === null ? ' <span class="muted">(last 12 mo)</span>' : '' ?></strong></div>
                <?php if ($proj['progress'] !== null): ?>
                <div><span class="muted">Toward <?= e(usd($proj['target_amount'])) ?></span><strong><?= e(number_format($proj['progress'], 1)) ?>%</strong></div>
                <?php endif; ?>
            </div>
            <?php if ($proj['progress'] !== null): ?>
            <div class="spend-track" style="margin-top:.5rem"><span style="width:<?= round(min(100, max(2, $proj['progress']))) ?>%"></span></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </section>

    <!-- Probability of success (Monte Carlo, #36) -->
    <?php $mc = $view['monte_carlo']; if ($mc && $mc['years'] >= 1): ?>
    <section class="block">
        <div class="block-head">
            <h2>Probability of success</h2>
            <span class="muted"><?= number_format($mc['runs']) ?> simulations</span>
        </div>
        <section class="hero card">
            <?php if ($mc['success_pct'] !== null):
                $sp = (float)$mc['success_pct'];
                $spClass = $sp >= 70 ? 'pos' : ($sp >= 50 ? '' : 'neg');
                // A 2,000-run sim can't honestly assert literal certainty — cap a ≥99.5% read at ">99%".
                $spLabel = $sp >= 99.5 ? '&gt;99' : e(number_format($sp, 0)); ?>
                <div class="hero-top">
                    <span class="hero-label">Chance of reaching <?= e(usd($mc['target'])) ?> by <?= e((string)$proj['target_year']) ?></span>
                </div>
                <div class="hero-value <?= $spClass ?>"><?= $spLabel ?>%</div>
                <div class="mc-track"><span class="<?= $spClass ?>" style="width:<?= round(min(100, max(1, $sp))) ?>%"></span></div>
            <?php else: ?>
                <div class="hero-top"><span class="hero-label">Range of outcomes by <?= e((string)$proj['target_year']) ?></span></div>
                <div class="hero-value"><?= e(usd($mc['end_median'])) ?> <span class="muted" style="font-size:.5em">median</span></div>
                <p class="muted" style="margin:.4rem 0 0">Set a <a href="/retirement_settings.php">target amount</a> to see your probability of reaching it.</p>
            <?php endif; ?>

            <?php
            $b      = $mc['bands'];
            $labels = array_map(fn($r) => (string)$r['year'], $b);
            // Fan chart: a faint 10th–90th band, a darker 25th–75th band, a solid median
            // line, the straight-line (deterministic) projection for comparison, + the target.
            // Each band is a lower edge (no fill) + an upper edge that fills DOWN to the lower
            // edge's dataset index (multiline fillTo); indices match this series order.
            $series = [
                ['label' => '10th pct',  'values' => array_map(fn($r) => $r['p10'], $b), 'color' => 'brand', 'faint' => true, 'legend' => false],
                ['label' => '10th–90th percentile', 'values' => array_map(fn($r) => $r['p90'], $b), 'color' => 'brand', 'faint' => true, 'fillTo' => 0],
                ['label' => '25th pct',  'values' => array_map(fn($r) => $r['p25'], $b), 'color' => 'brand', 'faint' => true, 'legend' => false],
                ['label' => '25th–75th percentile', 'values' => array_map(fn($r) => $r['p75'], $b), 'color' => 'brand', 'faint' => true, 'fillTo' => 2],
                ['label' => 'Median outcome', 'values' => array_map(fn($r) => $r['p50'], $b), 'color' => 'brand'],
            ];
            if ($proj && $proj['series']) {
                $series[] = ['label' => 'Straight-line', 'values' => array_map(fn($p) => $p['value'], $proj['series']), 'color' => 'muted', 'dashed' => true];
            }
            if ($mc['target'] !== null) {
                $series[] = ['label' => 'Target', 'values' => array_fill(0, count($b), (float)$mc['target']), 'color' => 'pos', 'dashed' => true];
            }
            ?>
            <div class="chart-wrap" style="height:260px;margin-top:1rem">
                <canvas data-chart="multiline" data-src="ret-mc-data"></canvas>
            </div>
            <script type="application/json" id="ret-mc-data"><?= json_encode([
                'labels' => $labels,
                'series' => $series,
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

            <div class="kv-grid" style="margin-top:1rem">
                <div><span class="muted">Pessimistic (10th)</span><strong><?= e(usd($mc['end_low'])) ?></strong></div>
                <div><span class="muted">Median (50th)</span><strong><?= e(usd($mc['end_median'])) ?></strong></div>
                <div><span class="muted">Optimistic (90th)</span><strong><?= e(usd($mc['end_high'])) ?></strong></div>
                <div><span class="muted">Median in today's $</span><strong><?= e(usd($mc['median_real'])) ?></strong></div>
            </div>
            <p class="chart-cap" style="margin-top:.8rem">
                <?= number_format($mc['runs']) ?> runs over <?= (int)$mc['years'] ?> year<?= $mc['years'] === 1 ? '' : 's' ?>,
                drawing each year's return from a normal distribution: average
                <strong><?= e(number_format($mc['mean'] * 100, 1)) ?>%</strong>
                (<?= e($view['rate_basis']) ?>), volatility
                <strong><?= e(number_format($mc['volatility'] * 100, 1)) ?>%</strong><?= $mc['vol_is_default'] ? ' (default)' : '' ?>.
                Today's-$ figure deflates by ≈<?= e(number_format($mc['inflation'] * 100, 1)) ?>% inflation.
                <?php if ($mc['target'] !== null): ?>The success figure measures your target as a
                <strong>future (nominal)</strong> amount — <?= e(usd($mc['target'])) ?> by
                <?= e((string)$proj['target_year']) ?> ≈ <?= e(usd($mc['target_real'])) ?> in today's dollars
                (enter a higher target if you mean today's purchasing power).<?php endif; ?>
                Volatility drag means the straight-line projection sits above the median outcome.
                An estimate, not financial advice — set your assumptions on the
                <a href="/retirement_settings.php">Assumptions</a> page.
            </p>
        </section>
    </section>
    <?php endif; ?>

    <!-- What-if scenario (#35) -->
    <?php if ($proj):
        // A temporary, NON-persisted overlay: re-run the projection (ret_project) AND the
        // Monte Carlo (ret_monte_carlo) from the SAME pure helpers the baseline above uses,
        // with the slider values, and compare against the saved-assumptions baseline. Nothing
        // here writes to retirement_settings — the Assumptions page still owns that. The current
        // balance ($total) is the fixed starting point; the knobs change the path from here.
        $curYear  = (int)$view['cur_year'];
        $baseYear = (int)$proj['target_year'];
        $baseRatePct = round($view['rate'] * 100, 1);
        $baseVolPct  = round(($mc['volatility'] ?? RET_DEFAULT_VOLATILITY) * 100);
        $baseContribAnnual = (float)$proj['annual_contrib'];
        $target   = $proj['target_amount'] !== null ? (float)$proj['target_amount'] : null;

        // Read a scalar GET value → a finite float, else the supplied baseline default.
        $wifGet = function (string $k, float $def): float {
            $v = $_GET[$k] ?? null;
            if (is_array($v) || $v === null || $v === '' || !is_numeric($v)) return $def;
            $v = (float)$v;
            return is_finite($v) ? $v : $def;
        };
        // Slider bounds (server clamp == slider min/max so a hand-edited URL can't drift the UI).
        $yMin = $curYear + 1;
        $yMax = max($curYear + 60, $baseYear);
        $wExtra  = max(0.0,   min(5000.0, $wifGet('we', 0.0)));                      // $/month, on top of the plan
        $wYear   = (int)max($yMin, min($yMax, $wifGet('wy', (float)max($baseYear, $yMin))));
        $wGrowth = max(0.0,   min(20.0,  $wifGet('wg', $baseRatePct)));              // % per year
        $wVol    = max(0.0,   min(40.0,  $wifGet('wv', (float)$baseVolPct)));        // % per year
        $wActive = isset($_GET['we']) || isset($_GET['wy']) || isset($_GET['wg']) || isset($_GET['wv']);

        $wContribAnnual = $baseContribAnnual + $wExtra * 12;
        $wRate = $wGrowth / 100;
        $wVolF = $wVol / 100;
        $wN    = $wYear - $curYear;                                                  // >= 1 by the clamp
        $wSeries    = ret_project($total, $wRate, $wContribAnnual, $curYear, $wN);
        $wProjected = (float)$wSeries[count($wSeries) - 1]['value'];
        $wMc = ret_monte_carlo($total, $wRate, $wVolF, $wContribAnnual, $curYear, $wN, $target, RET_MC_RUNS);

        $dProjected  = $wProjected - (float)$proj['projected'];
        $baseSuccess = ($mc && $mc['success_pct'] !== null) ? (float)$mc['success_pct'] : null;
        // Delta in whole points off the ROUNDED percentages, so the chip equals the
        // difference of the two integers the user actually sees (no 35→70 / "+36" artifact).
        $dSuccess    = ($wMc['success_pct'] !== null && $baseSuccess !== null)
            ? (int)(round((float)$wMc['success_pct']) - round($baseSuccess)) : null;
        // Restate the (nominal) target in today's dollars over THIS scenario's horizon, mirroring
        // the baseline block's framing so the success % isn't read as silently optimistic.
        $wInfl       = ($mc && isset($mc['inflation'])) ? (float)$mc['inflation'] : RET_DEFAULT_INFLATION;
        $wTargetReal = $target !== null ? round($target * pow(1 + $wInfl, -$wN), 2) : null;
    ?>
    <section class="block">
        <div class="block-head">
            <h2>What if…</h2>
            <?php if ($wActive): ?><a class="block-link" href="/retirement.php">Reset</a><?php endif; ?>
        </div>
        <section class="card">
            <p class="muted" style="margin-top:0">Drag a slider to explore a different plan — this is a
                preview only, it never changes your saved
                <a href="/retirement_settings.php">assumptions</a>.</p>

            <form method="get" action="/retirement.php" class="whatif-form">
                <div class="whatif-knob">
                    <span class="whatif-knob-head"><span>Extra contribution</span><output class="whatif-out" id="we-out" for="we"></output></span>
                    <input class="whatif-range" type="range" id="we" name="we" min="0" max="5000" step="50"
                           value="<?= (int)round($wExtra) ?>" data-out="#we-out" data-fmt="permonth" data-autosubmit
                           aria-label="Extra monthly contribution">
                </div>
                <div class="whatif-knob">
                    <span class="whatif-knob-head"><span>Retire in</span><output class="whatif-out" id="wy-out" for="wy"></output></span>
                    <input class="whatif-range" type="range" id="wy" name="wy" min="<?= $yMin ?>" max="<?= $yMax ?>" step="1"
                           value="<?= (int)$wYear ?>" data-out="#wy-out" data-fmt="year" data-autosubmit
                           aria-label="Target retirement year">
                </div>
                <div class="whatif-knob">
                    <span class="whatif-knob-head"><span>Growth rate</span><output class="whatif-out" id="wg-out" for="wg"></output></span>
                    <input class="whatif-range" type="range" id="wg" name="wg" min="0" max="20" step="0.5"
                           value="<?= e(rtrim(rtrim(number_format($wGrowth, 1), '0'), '.')) ?>" data-out="#wg-out" data-fmt="pct" data-autosubmit
                           aria-label="Expected annual growth rate">
                </div>
                <div class="whatif-knob">
                    <span class="whatif-knob-head"><span>Volatility</span><output class="whatif-out" id="wv-out" for="wv"></output></span>
                    <input class="whatif-range" type="range" id="wv" name="wv" min="0" max="40" step="1"
                           value="<?= (int)round($wVol) ?>" data-out="#wv-out" data-fmt="pct" data-autosubmit
                           aria-label="Expected volatility">
                </div>
                <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
            </form>

            <section class="hero card" style="margin-top:1rem">
                <div class="hero-top">
                    <span class="hero-label">What-if at <?= (int)$wYear ?></span>
                    <?php if (abs($dProjected) >= 0.5): ?>
                        <span class="delta <?= $dProjected >= 0 ? 'up' : 'down' ?>">
                            <?= $dProjected >= 0 ? '▲' : '▼' ?> <?= ($dProjected >= 0 ? '+' : '−') . e(usd(abs($dProjected))) ?>
                            <span class="delta-sub">vs your plan</span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="hero-value"><?= e(usd($wProjected)) ?></div>

                <?php if ($wMc['success_pct'] !== null):
                    $wsp = (float)$wMc['success_pct'];
                    $wspClass = $wsp >= 70 ? 'pos' : ($wsp >= 50 ? '' : 'neg');
                    $wspLabel = $wsp >= 99.5 ? '&gt;99' : e(number_format($wsp, 0)); ?>
                <div class="hero-split">
                    <div class="split-cell">
                        <span class="split-label">Chance of reaching <?= e(usd($target)) ?></span>
                        <span class="split-value <?= $wspClass ?>"><?= $wspLabel ?>%<?php if ($dSuccess !== null && abs($dSuccess) >= 0.5): ?>
                            <span class="delta <?= $dSuccess >= 0 ? 'up' : 'down' ?>" style="font-size:.62em"><?= $dSuccess >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($dSuccess), 0)) ?> pts</span><?php endif; ?></span>
                    </div>
                    <div class="split-cell">
                        <span class="split-label">Median outcome</span>
                        <span class="split-value"><?= e(usd($wMc['end_median'])) ?></span>
                    </div>
                </div>
                <div class="mc-track"><span class="<?= $wspClass ?>" style="width:<?= round(min(100, max(1, $wsp))) ?>%"></span></div>
                <?php endif; ?>

                <?php
                $wb      = $wMc['bands'];
                $wlabels = array_map(fn($r) => (string)$r['year'], $wb);
                $wseries = [
                    ['label' => '10th pct',  'values' => array_map(fn($r) => $r['p10'], $wb), 'color' => 'brand', 'faint' => true, 'legend' => false],
                    ['label' => '10th–90th percentile', 'values' => array_map(fn($r) => $r['p90'], $wb), 'color' => 'brand', 'faint' => true, 'fillTo' => 0],
                    ['label' => '25th pct',  'values' => array_map(fn($r) => $r['p25'], $wb), 'color' => 'brand', 'faint' => true, 'legend' => false],
                    ['label' => '25th–75th percentile', 'values' => array_map(fn($r) => $r['p75'], $wb), 'color' => 'brand', 'faint' => true, 'fillTo' => 2],
                    ['label' => 'What-if median', 'values' => array_map(fn($r) => $r['p50'], $wb), 'color' => 'brand'],
                ];
                if ($target !== null) {
                    $wseries[] = ['label' => 'Target', 'values' => array_fill(0, count($wb), $target), 'color' => 'pos', 'dashed' => true];
                }
                ?>
                <div class="chart-wrap" style="height:260px;margin-top:1rem">
                    <canvas data-chart="multiline" data-src="ret-wif-data"></canvas>
                </div>
                <script type="application/json" id="ret-wif-data"><?= json_encode([
                    'labels' => $wlabels,
                    'series' => $wseries,
                ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

                <p class="chart-cap" style="margin-top:.8rem">
                    Same <?= number_format($wMc['runs']) ?>-run engine as your plan, at
                    <strong><?= e(number_format($wGrowth, 1)) ?>%</strong> average growth and
                    <strong><?= e(number_format($wVol, 0)) ?>%</strong> volatility, adding
                    <strong><?= e(usd($wContribAnnual)) ?>/yr</strong><?= $wExtra > 0 ? ' (+' . e(usd($wExtra)) . '/mo over your plan)' : '' ?>.
                    <?php if ($target !== null): ?>Success measures <?= e(usd($target)) ?> as a
                    <strong>future (nominal)</strong> amount by <?= (int)$wYear ?> ≈
                    <?= e(usd($wTargetReal)) ?> in today's dollars.<?php endif; ?>
                    A what-if preview, not financial advice.
                </p>
            </section>
        </section>
    </section>
    <?php endif; ?>

    <!-- Accounts -->
    <section class="block">
        <div class="block-head"><h2>Your retirement accounts</h2><span class="count-pill"><?= count($accounts) ?></span></div>
        <?php foreach ($view['cards'] as $c):
            $a = $c['account'];
            $manual = $c['manual'];
            $stale = $manual && $c['stale_days'] !== null && $c['stale_days'] > 120;
            $owner = (int)$a['owner_id'] === $uid; ?>
        <section class="card" id="account-<?= e($a['account_id']) ?>">
            <div class="acct-card" style="padding:0">
                <span class="acct-icon"><?= nav_icon('nest') ?></span>
                <span class="acct-main">
                    <a class="acct-name" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>"><?= e($a['name'] ?: 'Retirement') ?></a>
                    <span class="acct-sub">
                        <?= e($a['institution_name'] ?: 'Retirement') ?><?= owner_suffix($a['owner_id'] ?? null) ?>
                        <?php if ($manual): ?>
                            <?php if ($c['last_date']): ?> · as of <?= e((string)$c['last_date']) ?><?php else: ?> · <span class="mini-tag">no statements</span><?php endif; ?>
                            <?php if ($stale): ?> <span class="mini-tag warn"><?= (int)floor($c['stale_days'] / 30) ?> mo old</span><?php endif; ?>
                        <?php else: ?>
                            · <span class="mini-tag">auto-synced</span><?php if ($c['last_date']): ?> · as of <?= e((string)$c['last_date']) ?><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($a['visibility'] === 'private'): ?> <span class="mini-tag">private</span><?php endif; ?>
                    </span>
                </span>
                <span class="row-side">
                    <span class="acct-bal"><?= e(usd($c['balance'])) ?></span>
                    <?php if ($manual && $owner): ?><a class="btn-ghost sm" href="/retirement_statement.php?account_id=<?= e(urlencode($a['account_id'])) ?>">Add statement</a><?php endif; ?>
                </span>
            </div>
            <?php if ($c['holdings']): ?>
            <div class="rows" style="margin-top:.6rem;border-top:1px solid var(--line)">
                <?php foreach ($c['holdings'] as $h):
                    $sec  = ($h['ticker_symbol'] ? $h['ticker_symbol'] . ' — ' : '') . ($h['security_name'] ?: '—');
                    $val  = $h['institution_value'] !== null ? (float)$h['institution_value'] : null;
                    $cb   = $h['cost_basis'] !== null ? (float)$h['cost_basis'] : null;
                    $hgain = ($val !== null && $cb !== null) ? $val - $cb : null;
                    $hpct  = ($hgain !== null && $cb != 0.0) ? round($hgain / abs($cb) * 100, 1) : null; ?>
                <div class="row">
                    <span class="row-main">
                        <span class="row-title"><a href="/security.php?security_id=<?= e(urlencode($h['security_id'])) ?>&amp;from=retirement"><?= e($sec) ?></a></span>
                        <span class="row-sub">
                            <?php if ($h['quantity'] !== null): ?><?= e(number_format((float)$h['quantity'], 4)) ?> @ <?= e(usd($h['institution_price'])) ?><?php endif; ?>
                            <?php if ($cb !== null): ?> · cost <?= e(usd($cb)) ?><?php endif; ?>
                        </span>
                    </span>
                    <span class="row-side">
                        <span class="row-amt"><?= $val !== null ? e(usd($val)) : '—' ?></span>
                        <?php if ($hgain !== null): ?>
                            <span class="delta <?= $hgain >= 0 ? 'up' : 'down' ?>"><?= $hgain >= 0 ? '▲' : '▼' ?> <?= ($hgain >= 0 ? '+' : '−') . e(usd(abs($hgain))) ?><?php if ($hpct !== null): ?> (<?= e(number_format(abs($hpct), 1)) ?>%)<?php endif; ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
        <div style="margin-top:1rem">
            <a class="btn-ghost" href="/retirement_add.php">Add a manual 401(k)</a>
            <span class="muted" style="margin-left:.5rem">Plaid-linked retirement accounts (IRA / 401k) appear here automatically.</span>
        </div>
    </section>

    <?php if ($retScope): /* Plaid retirement brokerage(s) present → show their activity (#18) */ ?>
    <?php render_investment_activity('Dividends & interest', $retIncome, [
        'head_right'   => $retIncomeTotal > 0 ? '<span class="split-value pos">' . e(usd($retIncomeTotal)) . '</span>' : '',
        'page'         => $rdPage,
        'has_next'     => $retIncomeNext,
        'pager_key'    => 'dpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($rtPage > 1 ? ['tpage' => $rtPage] : []) + ($rcPage > 1 ? ['cpage' => $rcPage] : []),
        'empty'        => 'No dividend or interest activity in the synced window.',
        'filter'       => ['opts' => $retAcctOpts, 'current' => $invAcct, 'action' => '/retirement.php'],
    ]); ?>
    <?php if ($retTrades || $invAcct !== '' || $rtPage > 1): ?>
    <?php render_investment_activity('Recent trades', $retTrades, [
        'head_right'   => $retTrades ? '<span class="count-pill">' . count($retTrades) . '</span>' : '',
        'page'         => $rtPage,
        'has_next'     => $retTradesNext,
        'pager_key'    => 'tpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($rdPage > 1 ? ['dpage' => $rdPage] : []) + ($rcPage > 1 ? ['cpage' => $rcPage] : []),
        'empty'        => 'No trades for this filter.',
        'filter'       => ['opts' => $retAcctOpts, 'current' => $invAcct, 'action' => '/retirement.php'],
    ]); ?>
    <?php endif; ?>
    <?php if ($retContribs || $rcPage > 1): ?>
    <?php render_investment_activity('Recent contributions', $retContribs, [
        'head_right'   => $retContribTotal > 0 ? '<span class="split-value pos">' . e(usd($retContribTotal)) . '</span>' : '',
        'page'         => $rcPage,
        'has_next'     => $retContribNext,
        'pager_key'    => 'cpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($rdPage > 1 ? ['dpage' => $rdPage] : []) + ($rtPage > 1 ? ['tpage' => $rtPage] : []),
        'empty'        => 'No contributions in the synced window.',
        'filter'       => ['opts' => $retAcctOpts, 'current' => $invAcct, 'action' => '/retirement.php'],
    ]); ?>
    <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
