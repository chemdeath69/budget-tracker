<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/allocation.php';
require __DIR__ . '/lib/layout.php';
require_login();

/**
 * Allocation vs target + drift (#32). Whole-portfolio asset mix (investments + retirement
 * holdings) bucketed into six fixed asset classes, compared to an owner-set target mix.
 *
 * Two writes:
 *  - the TARGET mix is edited by the plain CSRF <form method=post> below (like
 *    retirement_settings.php / safe_to_spend.php — no app.js);
 *  - each holding's CLASS override is a `.class-select` → api/allocation.php (initAllocation).
 * Both tables are household-shared (NOT VIS-scoped). Holdings are VIS-scoped via q_holdings.
 */

$pdo = db();
$uid = current_user_id();

/** "60"/"60%"/"1,2" → float clamped to [0,100] (blank/garbage → 0). */
function alloc_pct_in($v): float
{
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    $v = str_replace([',', '%', ' '], '', $v);
    if (!is_numeric($v)) return 0.0;
    return max(0.0, min(100.0, (float)$v));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /allocation.php');
        exit;
    }
    $posted = is_array($_POST['target'] ?? null) ? $_POST['target'] : [];
    $vals = [];
    foreach (ALLOC_CLASSES as $key => $label) {
        $p = alloc_pct_in($posted[$key] ?? '');
        if ($p > 0) $vals[$key] = $p;   // 0/blank = "want none" → simply not stored
    }
    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM allocation_targets');
        if ($vals) {
            $ins = $pdo->prepare(
                'INSERT INTO allocation_targets (asset_class, target_pct, updated_by) VALUES (?, ?, ?)'
            );
            foreach ($vals as $k => $v) $ins->execute([$k, $v, $uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('allocation target save error: ' . $e->getMessage());
        flash_set('error', 'Could not save the target mix.');
        header('Location: /allocation.php');
        exit;
    }
    flash_set('ok', 'Target mix saved.');
    header('Location: /allocation.php');
    exit;
}

// Whole portfolio: q_holdings returns ALL visible holdings (investments + retirement) —
// unlike investments.php, this page does NOT filter retirement out.
$holds     = q_holdings($pdo, $uid);
$targets   = q_allocation_targets($pdo);
$overrides = q_security_asset_classes($pdo);
$av        = build_allocation_view($holds, $targets, $overrides);

// Prefill the editor from the stored targets (blank when 0 — dodge the S25 comma trap by never
// formatting; these are simple 0–100 numbers shown plain).
$prefill = [];
foreach (ALLOC_CLASSES as $key => $label) {
    $prefill[$key] = isset($targets[$key]) && $targets[$key] > 0
        ? rtrim(rtrim(number_format($targets[$key], 3, '.', ''), '0'), '.')
        : '';
}

render_header('Allocation', 'allocation', ['narrow' => true, 'chart' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if ($av['total'] <= 0): ?>
    <div class="empty-state card">
        <h2>No holdings to allocate yet</h2>
        <p class="muted">Asset allocation compares your investment &amp; retirement <em>holdings</em>
            against a target mix. Once a brokerage or retirement account reports holdings, they'll
            appear here.</p>
        <a class="btn" href="/investments.php">Open Investments ›</a>
    </div>
    <?php render_footer(); exit; ?>
<?php endif; ?>

<?php
// Largest-drift summary for the hero.
$summary = 'Set a target mix below to see how your portfolio compares.';
if ($av['has_targets']) {
    if ($av['max_drift_val'] <= ALLOC_DRIFT_FLOOR) {
        $summary = 'Your portfolio is on target.';
    } else {
        // The biggest single drift.
        $top = null;
        foreach ($av['classes'] as $c) {
            if ($c['drift_val'] === null) continue;
            if ($top === null || abs($c['drift_val']) > abs($top['drift_val'])) $top = $c;
        }
        if ($top) {
            $dir = $top['drift_val'] > 0 ? 'overweight' : 'underweight';
            $summary = $top['label'] . ' is ' . usd(abs($top['drift_val'])) . ' ' . $dir
                     . ' (' . number_format($top['actual_pct'], 0) . '% vs '
                     . number_format($top['target_pct'], 0) . '% target).';
        }
    }
}
?>

<!-- Hero: total portfolio value + the headline drift. -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Portfolio</span>
        <span class="delta-sub muted">whole portfolio · holdings</span>
    </div>
    <div class="hero-value"><?= e(usd($av['total'])) ?></div>
    <p class="muted" style="margin:6px 0 0"><?= e($summary) ?></p>
</section>

<?php
// Doughnut of the ACTUAL mix (classes with value > 0, in fixed order). Labels are our own
// fixed strings (not raw Plaid data) but we keep JSON_HEX flags for consistency with the app.
$donutLabels = [];
$donutVals   = [];
foreach ($av['classes'] as $c) {
    if ($c['actual_val'] > 0) { $donutLabels[] = $c['label']; $donutVals[] = round($c['actual_val'], 2); }
}
?>
<section class="block">
    <div class="block-head"><h2>Current mix<?= $av['has_targets'] ? ' vs target' : '' ?></h2></div>
    <div class="card">
        <div class="chart-wrap">
            <canvas id="alloc-chart" data-chart="doughnut" data-src="alloc-class-data"></canvas>
            <script type="application/json" id="alloc-class-data"><?= json_encode([
                'labels' => $donutLabels,
                'values' => $donutVals,
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>

        <?php if ($av['has_targets'] && abs($av['target_sum'] - 100) > 0.5): ?>
            <div class="notice warn" style="margin-top:12px">
                Your targets add up to <strong><?= e(number_format($av['target_sum'], 1)) ?>%</strong> —
                set them to total 100% for the drift to balance.
            </div>
        <?php endif; ?>

        <div class="alloc-list" style="margin-top:14px">
            <?php $dHue = 0; foreach ($av['classes'] as $c):
                $hue = $c['actual_val'] > 0 ? ($dHue * 67) % 360 : null;
                if ($c['actual_val'] > 0) $dHue++;
                $swatch = $hue !== null ? "hsl($hue,65%,55%)" : 'var(--muted)';
            ?>
            <div class="alloc-row">
                <div class="alloc-head">
                    <span class="alloc-name"><span class="cat-swatch" style="background:<?= $swatch ?>"></span> <?= e($c['label']) ?></span>
                    <span class="alloc-figs">
                        <span class="alloc-actual"><?= e(number_format($c['actual_pct'], 1)) ?>% · <?= e(usd($c['actual_val'])) ?></span>
                        <?php if ($c['target_pct'] !== null): ?>
                            <span class="muted"> / target <?= e(number_format($c['target_pct'], 1)) ?>%</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="alloc-bar" role="img"
                     aria-label="<?= e($c['label']) ?> <?= e(number_format($c['actual_pct'], 0)) ?>%<?= $c['target_pct'] !== null ? ' of ' . e(number_format($c['target_pct'], 0)) . '% target' : '' ?>">
                    <span class="alloc-bar-fill" style="width:<?= round(min(100, $c['actual_pct']), 2) ?>%;background:<?= $swatch ?>"></span>
                    <?php if ($c['target_pct'] !== null): ?>
                        <span class="alloc-bar-target" style="left:<?= round(min(100, $c['target_pct']), 2) ?>%" title="Target <?= e(number_format($c['target_pct'], 1)) ?>%"></span>
                    <?php endif; ?>
                </div>
                <?php if ($c['drift_val'] !== null && abs($c['drift_val']) > ALLOC_DRIFT_FLOOR): ?>
                    <div class="alloc-drift">
                        <?= $c['drift_val'] > 0 ? '▲ ' : '▼ ' ?><?= e(usd(abs($c['drift_val']))) ?>
                        <?= $c['drift_val'] > 0 ? 'overweight' : 'underweight' ?>
                        (<?= ($c['drift_pct'] > 0 ? '+' : '−') . e(number_format(abs($c['drift_pct']), 1)) ?> pts)
                    </div>
                <?php elseif ($c['drift_val'] !== null): ?>
                    <div class="alloc-drift muted">On target</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($av['has_targets'] && ($av['sells'] || $av['buys'])): ?>
<!-- Rebalance hints. -->
<section class="card">
    <h2>Rebalance to target</h2>
    <p class="muted">An estimate to bring your mix back to target — excludes taxes, fees, and trading
        costs, and doesn't account for which account the holdings sit in.</p>
    <div class="alloc-rebal">
        <?php foreach ($av['sells'] as $s): ?>
            <div class="alloc-rebal-row"><span class="neg">Trim</span> <strong><?= e($s['label']) ?></strong> by <?= e(usd($s['amount'])) ?></div>
        <?php endforeach; ?>
        <?php foreach ($av['buys'] as $b): ?>
            <div class="alloc-rebal-row"><span class="pos">Add</span> <strong><?= e($b['label']) ?></strong> <?= e(usd($b['amount'])) ?></div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Target mix editor (household-shared). -->
<section class="card">
    <h2>Target mix</h2>
    <p class="muted">The asset allocation you're aiming for. Percentages should total 100%. Shared
        across the household. Leave a class blank (or 0) if you don't want to hold any of it.</p>
    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <?php foreach (ALLOC_CLASSES as $key => $label): ?>
        <label class="field">
            <span class="field-label"><?= e($label) ?></span>
            <input class="input alloc-target-input" type="text" inputmode="decimal"
                   name="target[<?= e($key) ?>]" value="<?= e($prefill[$key]) ?>" placeholder="0">
        </label>
        <?php endforeach; ?>
        <button class="btn" type="submit">Save target mix</button>
    </form>
</section>

<!-- Per-holding asset class (override the auto-derived class). -->
<section class="block">
    <div class="block-head"><h2>Holdings &amp; classes</h2></div>
    <div class="card">
        <p class="muted">Each holding's class is auto-derived from its security type. Plaid groups all
            ETFs/funds together, so set the right class for a bond, REIT or crypto ETF here — it applies
            everywhere this household sees it.</p>
        <div class="rows">
            <?php foreach ($av['holdings'] as $h): ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title">
                        <?php if (!empty($h['security_id'])): ?>
                            <a href="/security.php?security_id=<?= e(urlencode($h['security_id'])) ?>&amp;from=allocation"><?= e($h['label']) ?></a>
                        <?php else: ?>
                            <?= e($h['label']) ?>
                        <?php endif; ?>
                    </span>
                    <span class="row-sub">
                        <?= e(usd($h['value'])) ?> · <?= e(number_format($h['pct'], 1)) ?>% ·
                        <?php if (!empty($h['security_id'])): ?>
                            <select class="select class-select" data-security="<?= e($h['security_id']) ?>" aria-label="Asset class for <?= e($h['label']) ?>">
                                <option value="auto"<?= $h['source'] === 'auto' ? ' selected' : '' ?>>Auto · <?= e($h['default_label']) ?></option>
                                <?php foreach (ALLOC_CLASSES as $ck => $cl): ?>
                                    <option value="<?= e($ck) ?>"<?= ($h['source'] === 'override' && $h['class'] === $ck) ? ' selected' : '' ?>><?= e($cl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <span class="muted"><?= e($h['class_label']) ?></span>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="row-amt"><?= e(number_format($h['pct'], 1)) ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<p class="muted load-note">Allocation is based on reported <strong>holdings</strong> — a brokerage's
    uninvested cash (not held as a security) isn't counted, and an account that reports no per-holding
    breakdown contributes nothing here. Retirement and taxable accounts are combined.</p>

<?php render_footer(); ?>
