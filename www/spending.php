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
            <?php $max = (float)$spend[0]['total']; foreach ($spend as $i => $c):
                $pct = $total > 0 ? ($c['total'] / $total) * 100 : 0;
                $w   = $max > 0 ? max(3, ($c['total'] / $max) * 100) : 0; ?>
            <div class="cat-row">
                <span class="cat-swatch" style="--i:<?= $i ?>"></span>
                <span class="cat-name"><?= e(pretty_cat($c['category'])) ?></span>
                <span class="cat-track"><span style="width:<?= round($w) ?>%"></span></span>
                <span class="cat-amt"><?= e(usd($c['total'])) ?><span class="cat-pct"><?= round($pct) ?>%</span></span>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Budgets -->
<section class="block">
    <div class="block-head">
        <h2>Monthly budgets</h2>
        <button class="btn-ghost" id="add-budget-btn" type="button">+ Add</button>
    </div>

    <form id="add-budget-form" class="card budget-form" hidden>
        <label class="field">
            <span>Category</span>
            <input id="budget-cat" placeholder="e.g. FOOD_AND_DRINK" autocomplete="off">
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
        <?php else: foreach ($bud['budgets'] as $b):
            $pct  = $b['monthly_limit'] > 0 ? min(100, ($b['spent'] / $b['monthly_limit']) * 100) : 0;
            $over = $b['spent'] > $b['monthly_limit']; ?>
        <div class="budget-row card" data-id="<?= (int)$b['id'] ?>">
            <div class="b-head">
                <span><?= e(pretty_cat($b['category'])) ?> <?= $over ? '⚠️' : '' ?></span>
                <span class="muted"><?= e(usd($b['spent'])) ?> / <?= e(usd($b['monthly_limit'])) ?>
                    <button class="budget-del" data-id="<?= (int)$b['id'] ?>" aria-label="Delete budget">✕</button></span>
            </div>
            <div class="budget-bar<?= $over ? ' over' : '' ?>"><span style="width:<?= round($pct) ?>%"></span></div>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <p class="muted load-note">Budgets are shared across the household and reset monthly.</p>
</section>
</div><!-- /.cols -->

<?php render_footer(); ?>
