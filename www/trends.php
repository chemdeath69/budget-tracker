<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Window selector (?months=). Keep to a small fixed set so the stack stays legible.
$months = (int)($_GET['months'] ?? 12);
if (!in_array($months, [6, 12, 24], true)) $months = 12;

$tr      = q_spending_trend($pdo, $uid, $months);
$hasData = array_sum(array_map(fn($m) => (float)$m['total'], $tr['months'])) > 0;

// Stacked-bar series, in the query's top-7-then-Other colour order.
$series = [];
foreach ($tr['cat_order'] as $cat) {
    $series[] = [
        'label'  => $cat === 'OTHER' ? 'Other' : pretty_cat($cat),
        'values' => array_map(fn($m) => round($m['cats'][$cat], 2), $tr['months']),
    ];
}
// Map each category to its colour index so the delta-table swatches match the chart.
$catIdx = array_flip($tr['cat_order']);

// Current-month window carried into every category click-through.
$curFrom = date('Y-m-01');
$curTo   = date('Y-m-t');

/**
 * Signed % delta of $cur vs a baseline $base, rendered as a coloured chip.
 * For SPENDING an increase is "worse" → red (neg); a decrease is green (pos).
 * Returns an em-dash when there's no baseline to compare against.
 */
$deltaChip = function (float $cur, float $base): string {
    if ($base <= 0) return '<span class="delta-chip muted">—</span>';
    $pct = ($cur - $base) / $base * 100;
    if (abs($pct) < 0.5) return '<span class="delta-chip muted">≈ flat</span>';
    $up  = $pct > 0;
    $cls = $up ? 'neg' : 'pos';
    $arr = $up ? '▲' : '▼';
    return '<span class="delta-chip ' . $cls . '">' . $arr . ' ' . number_format(abs($pct), 0) . '%</span>';
};

/** KPI delta: arrow + |%| text + a colour class (for SPENDING an increase is "worse" → neg/red). */
$kpiDelta = function (float $cur, float $base): array {
    if ($base <= 0) return ['—', ''];
    $pct = ($cur - $base) / $base * 100;
    if (abs($pct) < 0.5) return ['≈ flat', ''];
    $up = $pct > 0;
    return [($up ? '▲ ' : '▼ ') . number_format(abs($pct), 0) . '%', $up ? 'neg' : 'pos'];
};

render_header('Spending trends', 'trends', ['chart' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Spend</p>
    <h1>Spending trends</h1>
</div>

<?php if (!$hasData): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('bars') ?></span>
        <h2>No spending trend yet</h2>
        <p class="muted">Once a bank is linked and transactions have synced, your month-by-month spending by category appears here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Chart leads: this-month spend + period selector, then the stacked bars -->
    <section class="card">
        <div class="chart-lead-head">
            <div class="lead-fig">
                <span class="eyebrow">Spending · <?= e($tr['month_label']) ?> · so far this month</span>
                <div class="big"><?= e(usd($tr['this_total'])) ?></div>
            </div>
            <form method="get" action="/trends.php" class="head-form">
                <select name="months" class="select" data-autosubmit aria-label="Period">
                    <option value="6"<?=  $months === 6  ? ' selected' : '' ?>>Last 6 months</option>
                    <option value="12"<?= $months === 12 ? ' selected' : '' ?>>Last 12 months</option>
                    <option value="24"<?= $months === 24 ? ' selected' : '' ?>>Last 24 months</option>
                </select>
                <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
            </form>
        </div>
        <div class="chart-wrap tall">
            <canvas id="trend-chart" data-chart="stackbars" data-src="trend-data"></canvas>
            <script type="application/json" id="trend-data"><?= json_encode([
                'labels' => array_column($tr['months'], 'label'),
                'series' => $series,
            // JSON_HEX_TAG keeps a category/series label that contains "</script>"
            // from breaking out of this <script> element (defense-in-depth — these
            // labels are pretty_cat()'d codes today, but the flag makes the blob safe
            // regardless of what feeds it). app.js reads via textContent + JSON.parse.
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted load-note">Top 7 categories. Excludes internal transfers between your own accounts and credit-card payments, so money isn't counted twice. The current month is still filling in.</p>
    </section>

    <!-- KPI strip: this month vs recent history -->
    <?php [$d3txt, $d3cls] = $kpiDelta($tr['this_total'], $tr['avg3_total']);
          [$dytxt, $dycls] = $kpiDelta($tr['this_total'], $tr['lastyear_total']); ?>
    <div class="kpis">
        <div class="kpi"><span class="eyebrow">3-month avg</span><div class="v"><?= e(usd($tr['avg3_total'])) ?></div></div>
        <div class="kpi"><span class="eyebrow">vs 3-mo avg</span><div class="v <?= $d3cls ?>"><?= e($d3txt) ?></div></div>
        <div class="kpi"><span class="eyebrow"><?= e(date('M Y', strtotime('first day of this month -12 months'))) ?></span><div class="v"><?= e(usd($tr['lastyear_total'])) ?></div></div>
        <div class="kpi"><span class="eyebrow">vs last year</span><div class="v <?= $dycls ?>"><?= e($dytxt) ?></div></div>
    </div>

    <!-- This month by category → click a row to see those transactions -->
    <section class="block">
        <div class="block-head"><h2>This month by category</h2><span class="count-pill"><?= count($tr['deltas']) ?></span></div>
        <?php if (!$tr['deltas']): ?>
            <p class="muted">No spending recorded yet this month.</p>
        <?php else:
            $max = max(array_map(fn($d) => (float)$d['this'], $tr['deltas'])) ?: 1.0;
            // Carry the calendar-month window into the link. NOTE: the transactions page
            // is the full ledger (it also shows pending, refunds and the transfers/CC
            // payments this trend excludes), so the linked list is a superset — only the
            // category + month window match, it won't sum to this expense-only figure.
            ?>
        <div class="cat-list card">
            <?php foreach ($tr['deltas'] as $d):
                $cat   = $d['category'];
                $i     = $catIdx[$cat] ?? 0;
                $w     = max(3, ($d['this'] / $max) * 100);
                $other = $cat === 'OTHER';
                $label = $other ? 'Other' : pretty_cat($cat);
                $inner = '<span class="cat-swatch' . ($other ? ' other' : '') . '" style="--i:' . (int)$i . '"></span>'
                       . '<span class="cat-name">' . e($label) . '</span>'
                       . '<span class="cat-track"><span style="width:' . round($w) . '%"></span></span>'
                       . '<span class="cat-amt">' . e(usd($d['this'])) . ' ' . $deltaChip($d['this'], $d['avg3']) . '</span>';
                if ($other):
                    // 'Other' is an aggregate bucket, not a real category — no drill-through.
                    echo '<div class="cat-row is-static">' . $inner . '</div>';
                else:
                    $href = '/transactions.php?' . http_build_query([
                        'category' => $cat,
                        'from'     => $curFrom,
                        'to'       => $curTo,
                    ]);
                    echo '<a class="cat-row" href="' . e($href) . '">' . $inner . '</a>';
                endif;
            endforeach; ?>
        </div>
        <p class="muted load-note">Delta compares spending so far this month to the same point in the previous 3 months.</p>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php render_footer(); ?>
