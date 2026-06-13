<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/debt.php';
require __DIR__ . '/lib/layout.php';
require_login();

/**
 * Debt payoff planner (#33). Compares the SNOWBALL (smallest balance first) and AVALANCHE
 * (highest APR first) strategies vs a minimums-only baseline, from q_debts (every visible
 * credit/loan account + its Plaid liabilities detail). Read-only, no writes — the "extra $/mo"
 * and "include mortgage" inputs are GET params (a what-if), so no schema/endpoint.
 */

$pdo = db();
$uid = current_user_id();

// Extra $/month (a what-if). is_numeric + is_finite guards an overflow sci-notation (the S40 trap).
$extraRaw = $_GET['extra'] ?? '';
$extra = (is_numeric($extraRaw) && is_finite((float)$extraRaw)) ? max(0.0, (float)$extraRaw) : 0.0;
$extra = min($extra, 10000000.0);
$includeMortgage = (($_GET['mortgage'] ?? '') === '1');

$debtRows = q_debts($pdo, $uid);
$plan     = build_debt_plan($debtRows, $extra, $includeMortgage);

/** Format a months-from-now count as a "Mon YYYY" debt-free label (first-of-month math = no overflow). */
function debt_date_label(int $months): string
{
    return (new DateTimeImmutable('first day of this month'))->modify("+{$months} months")->format('M Y');
}

render_header('Debt payoff', 'debt', ['narrow' => true, 'chart' => true]);
?>

<?php if (!$plan['debts']): ?>
    <div class="empty-state card">
        <h2>No debts to plan<?= $plan['has_mortgage'] && !$includeMortgage ? ' (except your mortgage)' : '' ?></h2>
        <p class="muted">A debt payoff plan works from your credit cards and loans — their balance,
            interest rate and minimum payment. None are showing here right now.
            <?php if ($plan['has_mortgage'] && !$includeMortgage): ?>
                Your mortgage is excluded by default — turn it on below to include it.
            <?php endif; ?>
        </p>
        <?php if ($plan['has_mortgage'] && !$includeMortgage): ?>
            <a class="btn" href="?extra=<?= e((string)(int)$extra) ?>&amp;mortgage=1">Include the mortgage ›</a>
        <?php endif; ?>
    </div>
    <?php render_footer(); exit; ?>
<?php endif; ?>

<?php
$sc        = $plan['scenarios'];
$aval      = $sc['avalanche'];
$snow      = $sc['snowball'];
$baseline  = $sc['baseline'];

// Quick-link query builder that preserves the mortgage toggle.
$qs = fn(float $e): string => '?extra=' . rawurlencode((string)(int)round($e)) . ($includeMortgage ? '&amp;mortgage=1' : '');

// Headline: lead with avalanche (cheapest interest); also surface snowball's first quick win.
$avalFree = $aval['infeasible'] ? '—' : debt_date_label($aval['months']);
$snowFirstId = $snow['order'][0] ?? null;
$snowFirstMo = $snowFirstId !== null ? ($snow['payoff'][$snowFirstId] ?? null) : null;
?>

<!-- Hero: total debt + the headline plan. -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Total debt<?= $includeMortgage ? '' : ' (excl. mortgage)' ?></span>
        <span class="delta-sub muted"><?= count($plan['debts']) ?> debt<?= count($plan['debts']) === 1 ? '' : 's' ?></span>
    </div>
    <div class="hero-value"><?= e(usd($plan['total'])) ?></div>
    <p class="muted" style="margin:6px 0 0">
        <?php if ($aval['infeasible']): ?>
            With the current minimum payments alone, this debt isn't projected to be cleared within
            <?= DEBT_MAX_MONTHS / 12 ?> years — add an extra monthly payment below to build a plan.
        <?php else: ?>
            <strong>Avalanche</strong> clears it by <strong><?= e($avalFree) ?></strong><?php
                if ($aval['interest_saved'] !== null && $aval['interest_saved'] > 0): ?>, saving
                <strong><?= e(usd($aval['interest_saved'])) ?></strong> in interest vs paying minimums only<?php
                endif; ?>.
        <?php endif; ?>
    </p>
</section>

<!-- What-if controls: extra payment + mortgage toggle. -->
<section class="card">
    <form method="get" action="/debt.php" class="debt-controls">
        <label class="field">
            <span class="field-label">Extra payment / month</span>
            <input class="input debt-extra-input" type="number" inputmode="decimal" min="0" step="10"
                   name="extra" value="<?= e($extra > 0 ? (string)(int)round($extra) : '') ?>" placeholder="0">
        </label>
        <div class="debt-presets">
            <?php foreach ([0, 100, 250, 500, 1000] as $p): ?>
                <a class="chip<?= (int)round($extra) === $p ? ' chip-on' : '' ?>" href="<?= $qs((float)$p) ?>">+$<?= e(number_format($p)) ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($plan['has_mortgage']): ?>
        <label class="debt-toggle">
            <input type="checkbox" class="switch" name="mortgage" value="1" data-autosubmit<?= $includeMortgage ? ' checked' : '' ?>>
            <span>Include the mortgage</span>
        </label>
        <?php endif; ?>
        <button class="btn" type="submit">Update plan</button>
    </form>
</section>

<!-- Strategy comparison. -->
<?php
// Render one strategy column.
$renderStrat = function (string $title, array $s, string $tag): string {
    $free   = $s['infeasible'] ? '—' : debt_date_label($s['months']);
    $yrs    = intdiv($s['months'], 12);
    $mos    = $s['months'] % 12;
    $dur    = $s['infeasible'] ? ('> ' . (DEBT_MAX_MONTHS / 12) . ' yr')
            : (($yrs ? $yrs . ' yr ' : '') . ($mos || !$yrs ? $mos . ' mo' : ''));
    ob_start(); ?>
    <div class="debt-strat card">
        <div class="debt-strat-head">
            <h3><?= e($title) ?></h3>
            <span class="debt-strat-tag muted"><?= e($tag) ?></span>
        </div>
        <div class="debt-strat-free">
            <span class="debt-strat-date"><?= e($free) ?></span>
            <span class="muted">debt-free · <?= e(trim($dur)) ?></span>
        </div>
        <div class="cr-kv debt-strat-kv">
            <div><span class="muted">Interest paid</span><strong><?= e(usd($s['total_interest'])) ?></strong></div>
            <?php if ($s['interest_saved'] !== null): ?>
                <div><span class="muted">Interest saved</span><strong class="pos"><?= e(usd($s['interest_saved'])) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($s['months_saved'])): ?>
                <div><span class="muted">Sooner by</span><strong class="pos"><?= e((string)$s['months_saved']) ?> mo</strong></div>
            <?php endif; ?>
        </div>
    </div>
    <?php return (string)ob_get_clean();
};
?>
<section class="block">
    <div class="block-head"><h2>Two strategies</h2></div>
    <div class="cols debt-strats">
        <?= $renderStrat('Avalanche', $aval, 'Cheapest — highest APR first') ?>
        <?= $renderStrat('Snowball', $snow, 'Fastest wins — smallest balance first') ?>
    </div>
    <p class="muted debt-strat-note">
        <strong>Avalanche</strong> pays the least interest.
        <?php if ($snowFirstMo !== null): ?>
            <strong>Snowball</strong> clears your first debt soonest — in about <?= e((string)$snowFirstMo) ?>
            month<?= $snowFirstMo === 1 ? '' : 's' ?> — which some people find more motivating.
        <?php else: ?>
            <strong>Snowball</strong> clears your smallest balance first, which some people find more motivating.
        <?php endif; ?>
        Both assume the same total monthly payment.
    </p>
</section>

<?php
// Balance-over-time chart: Avalanche vs Snowball vs Minimums-only. Build labels from the longest
// series; shorter datasets simply end (Chart.js leaves the rest empty).
$series = [
    ['label' => 'Avalanche',     'values' => $aval['series'],     'color' => 'brand'],
    ['label' => 'Snowball',      'values' => $snow['series'],     'color' => 'pos'],
    ['label' => 'Minimums only', 'values' => $baseline['series'], 'color' => 'muted', 'faint' => true, 'dashed' => true],
];
$maxLen = 0;
foreach ($series as $s) $maxLen = max($maxLen, count($s['values']));
$labels = [];
$base   = new DateTimeImmutable('first day of this month');
for ($i = 0; $i < $maxLen; $i++) $labels[] = $base->modify("+{$i} months")->format("M 'y");
?>
<section class="block">
    <div class="block-head"><h2>Balance over time</h2></div>
    <div class="card">
        <div class="chart-wrap tall">
            <canvas id="debt-chart" data-chart="multiline" data-src="debt-data"></canvas>
            <script type="application/json" id="debt-data"><?= json_encode([
                'labels' => $labels,
                'series' => $series,
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted chart-cap">Total remaining balance month by month. The flatter, longer dashed line
            is paying only the minimums.</p>
    </div>
</section>

<!-- Per-debt detail, in avalanche payoff order. -->
<section class="block">
    <div class="block-head"><h2>Your debts</h2></div>
    <div class="card">
        <p class="muted">In <strong>avalanche</strong> order (highest APR first). The payoff date is for the
            avalanche plan with your current extra payment.</p>
        <div class="rows">
            <?php
            $byId = [];
            foreach ($plan['debts'] as $d) $byId[$d['id']] = $d;
            $rank = 0;
            foreach ($aval['order'] as $id):
                $d = $byId[$id] ?? null;
                if (!$d) continue;
                $rank++;
                $poMo = $aval['payoff'][$id] ?? null;
            ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title">
                        <span class="debt-rank"><?= $rank ?></span>
                        <?= e($d['name']) ?><?php if ($d['mask'] !== ''): ?> <span class="muted">••<?= e($d['mask']) ?></span><?php endif; ?><?= owner_suffix($d['owner_id']) ?>
                    </span>
                    <span class="row-sub">
                        <?php if ($d['apr_unknown']): ?>
                            <span class="muted">APR n/a</span>
                        <?php else: ?>
                            <?= e(number_format($d['apr'], 2)) ?>% APR
                        <?php endif; ?>
                        · min <?= e(usd($d['min_payment'])) ?><?php if ($d['min_source'] !== 'plaid'): ?> <span class="muted">(est.)</span><?php endif; ?>
                        <?php if ($d['is_mortgage']): ?> · <span class="muted">mortgage</span><?php endif; ?>
                    </span>
                </span>
                <span class="row-amt">
                    <?= e(usd($d['balance'])) ?>
                    <span class="row-amt-sub muted"><?= $poMo !== null ? 'paid ' . e(debt_date_label($poMo)) : 'beyond plan' ?></span>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<p class="muted load-note">
    Estimates only. Interest is modeled monthly from the reported APR; a debt with no reported rate
    is modeled at 0% (so its interest is under-counted, never over-counted)<?php
        if ($plan['any_apr_unknown']): ?> — that applies to at least one debt here<?php endif; ?>.
    Minimum payments are treated as fixed (a real card minimum drifts down as the balance falls)<?php
        if ($plan['any_min_estimated']): ?>, and where your bank didn't report one it's the last payment
        or an estimate<?php endif; ?>. The modeled rate is the first APR your bank reports for a card, which
    could be a promotional or balance-transfer rate rather than the everyday purchase APR.<?php
        if ($includeMortgage): ?> An included mortgage uses your last payment as its monthly amount, which
    may bundle escrow (property tax &amp; insurance) that doesn't reduce the loan — so its payoff can look
    faster than reality.<?php endif; ?> New spending, fees and taxes aren't modeled. Both strategies assume
    you keep paying the same total each month and roll a paid-off debt's payment into the next.
</p>

<?php render_footer(); ?>
