<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo   = db();
$uid   = current_user_id();
$spend = q_spending($pdo, $uid, 30);
$total = array_sum(array_map(fn($r) => (float)$r['total'], $spend));
$bud   = q_budgets($pdo);
$catOptions = budget_category_options($pdo, $uid);

// Budget-history window (?months=) for the per-row trend (TODO #11). Small fixed set.
$months = (int)($_GET['months'] ?? 6);
if (!in_array($months, [6, 12, 24], true)) $months = 6;
$hist = q_budget_history($pdo, $months);

// Signed % delta of $cur vs a baseline $base, rendered as a coloured chip (red = spend
// up, green = down). Mirrors trends.php's helper.
$deltaChip = function (float $cur, float $base): string {
    if ($base <= 0) return '<span class="delta-chip muted">—</span>';
    $pct = ($cur - $base) / $base * 100;
    if (abs($pct) < 0.5) return '<span class="delta-chip muted">≈ flat</span>';
    $cls = $pct > 0 ? 'neg' : 'pos';
    $arr = $pct > 0 ? '▲' : '▼';
    return '<span class="delta-chip ' . $cls . '">' . $arr . ' ' . number_format(abs($pct), 0) . '%</span>';
};

render_header('Spending & budgets', 'spending', ['chart' => true]);
?>

<div class="cols">
<!-- Spending by category -->
<section class="card">
    <div class="block-head">
        <h2>By category</h2>
        <span class="muted">Last 30 days</span>
    </div>
    <?php if (!$spend): ?>
        <p class="muted">No spending recorded in the last 30 days.</p>
    <?php else: ?>
        <div class="chart-wrap">
            <canvas id="spend-chart" data-chart="doughnut" data-src="spend-data"></canvas>
            <script type="application/json" id="spend-data"><?= json_encode([
                'labels' => array_map('pretty_cat', array_column($spend, 'category')),
                'values' => array_map('floatval', array_column($spend, 'total')),
            ], JSON_UNESCAPED_SLASHES) ?></script>
        </div>
        <div class="cat-list">
            <?php $max = (float)$spend[0]['total'];
            // Carry q_spending's rolling 30-day window into the link. NOTE: the
            // transactions page is the full ledger (it also shows pending, refunds and
            // ext_source rows q_spending excludes), so the linked list is a superset —
            // only the date window matches, it won't sum to this outflow-only figure.
            $spendFrom = date('Y-m-d', strtotime('-30 days'));
            foreach ($spend as $i => $c):
                $pct  = $total > 0 ? ($c['total'] / $total) * 100 : 0;
                $w    = $max > 0 ? max(3, ($c['total'] / $max) * 100) : 0;
                $href = '/transactions.php?' . http_build_query([
                    'category' => $c['category'],
                    'from'     => $spendFrom,
                ]); ?>
            <a class="cat-row" href="<?= e($href) ?>">
                <span class="cat-swatch" style="--i:<?= $i ?>"></span>
                <span class="cat-name"><?= e(pretty_cat($c['category'])) ?></span>
                <span class="cat-track"><span style="width:<?= round($w) ?>%"></span></span>
                <span class="cat-amt"><?= e(usd($c['total'])) ?><span class="cat-pct"><?= round($pct) ?>%</span></span>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Budgets -->
<section class="block">
    <div class="block-head">
        <h2>Monthly budgets</h2>
        <div class="head-actions">
            <?php if ($bud['budgets']): ?>
            <form method="get" class="head-form">
                <select name="months" class="select" data-autosubmit aria-label="History window">
                    <option value="6"<?=  $months === 6  ? ' selected' : '' ?>>Last 6 months</option>
                    <option value="12"<?= $months === 12 ? ' selected' : '' ?>>Last 12 months</option>
                    <option value="24"<?= $months === 24 ? ' selected' : '' ?>>Last 24 months</option>
                </select>
            </form>
            <?php endif; ?>
            <button class="btn-ghost" id="add-budget-btn" type="button">+ Add</button>
        </div>
    </div>

    <form id="add-budget-form" class="card budget-form" hidden>
        <label class="field">
            <span>Category</span>
            <select id="budget-cat" class="input">
                <option value="" disabled selected>Choose a category…</option>
                <?php foreach ($catOptions as $o): ?>
                    <option value="<?= e($o['value']) ?>"><?= e($o['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Monthly limit ($)</span>
            <input id="budget-limit" type="number" min="1" step="1" placeholder="500">
        </label>
        <button class="btn" type="submit">Save budget</button>
    </form>

    <div id="budgets-list" class="budgets-list">
        <?php if (!$bud['budgets']): ?>
            <p class="muted" id="budgets-empty">No budgets yet. Add one to track spending against a monthly limit.</p>
        <?php else:
            // Carry q_budgets' calendar-month window into the link. (Same caveat as the
            // spending links above: the destination is the full ledger, a superset of the
            // outflow-only 'spent' figure — only the window matches.)
            $budFrom = $bud['month'] . '-01';
            $budTo   = date('Y-m-t', strtotime($budFrom));
            foreach ($bud['budgets'] as $b):
            $pct  = $b['monthly_limit'] > 0 ? min(100, ($b['spent'] / $b['monthly_limit']) * 100) : 0;
            $over = $b['spent'] > $b['monthly_limit'];
            $bHref = '/transactions.php?' . http_build_query([
                'category' => $b['category'],
                'from'     => $budFrom,
                'to'       => $budTo,
            ]);
            $h = $hist[$b['category']] ?? null; ?>
        <div class="budget-row card" data-id="<?= (int)$b['id'] ?>">
            <div class="b-head">
                <span><a class="cat-link" href="<?= e($bHref) ?>"><?= e(pretty_cat($b['category'])) ?></a> <?= $over ? '⚠️' : '' ?>
                    <?php if ($h): ?><?= $deltaChip((float)$h['this'], (float)$h['avg3']) ?><?php endif; ?></span>
                <span class="muted"><?= e(usd($b['spent'])) ?> / <?= e(usd($b['monthly_limit'])) ?>
                    <button class="budget-del" data-id="<?= (int)$b['id'] ?>" aria-label="Delete budget">✕</button></span>
            </div>
            <div class="budget-bar<?= $over ? ' over' : '' ?>"><span style="width:<?= round($pct) ?>%"></span></div>
            <?php if ($h && $b['monthly_limit'] > 0):
                // Mini month-bar history: bar height ∝ spend, scaled so the limit sits at a
                // fixed reference line (70% height); a month over the limit clamps full + red.
                $lim = (float)$b['monthly_limit']; $ref = 70; ?>
            <div class="bud-spark" role="img"
                 aria-label="<?= e('Monthly spend over the last ' . $months . ' months vs the ' . usd($lim) . ' limit') ?>">
                <span class="bud-spark-limit" style="bottom:<?= $ref ?>%"></span>
                <?php foreach ($h['months'] as $m):
                    $sp = (float)$m['spent'];
                    $ht = $sp <= 0 ? 0 : min(100, ($sp / $lim) * $ref);
                    $bOver = $sp > $lim; ?>
                <span class="bud-spark-bar<?= $bOver ? ' over' : '' ?>"
                      style="height:<?= round(max($sp > 0 ? 3 : 0, $ht)) ?>%"
                      title="<?= e($m['label'] . ': ' . usd($sp)) ?>"></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <p class="muted load-note">Budgets are shared across the household and reset monthly.</p>
</section>
</div><!-- /.cols -->

<?php render_footer(); ?>
