<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Month selector (?month=YYYY-MM). Offer the last 24 months and validate the
// request against that set (bogus → current month) so the bind is always sane.
$cur     = new DateTimeImmutable('first day of this month');
$monthOpts = [];
for ($i = 0; $i < 24; $i++) {
    $d = $cur->sub(new DateInterval('P' . $i . 'M'));
    $monthOpts[$d->format('Y-m')] = $d->format('F Y');
}
// Default to the LAST COMPLETED month, not the current partial one: a single-month
// flow needs a whole month to read sensibly (mid-month the paycheck hasn't landed yet,
// so income looks empty and the "saved/drawn" balance is meaningless). The in-progress
// month is still selectable from the dropdown.
$ym = (string)($_GET['month'] ?? '');
if (!isset($monthOpts[$ym])) $ym = $cur->sub(new DateInterval('P1M'))->format('Y-m');

$mf = q_money_flow($pdo, $uid, $ym);

$hasData = $mf['income_total'] > 0 || $mf['expense_total'] > 0;
$rate    = $mf['income_total'] > 0 ? ($mf['net'] / $mf['income_total']) * 100 : null; // savings rate

// Calendar-month window carried into every drill-through link (inclusive bounds).
$first   = new DateTimeImmutable($ym . '-01');
$fromStr = $first->format('Y-m-01');
$toStr   = $first->format('Y-m-t');

// ---- Assemble the Sankey nodes + links from the (folded) flow data ----------
// Three columns: income payers (0) → "Income" hub (1) → spending categories (2).
// The colour is a TOKEN resolved theme-aware in app.js (pos/neg/brand/muted/slice:N).
$nodes = [['id' => 'hub', 'label' => 'Income', 'column' => 1, 'color' => 'brand']];
$links = [];

$inSum = 0.0;
foreach ($mf['income'] as $i => $r) {
    $id  = 'in' . $i;
    $amt = round($r['amount'], 2);
    $nodes[] = [
        'id'     => $id,
        'label'  => $r['other'] ? 'Other income' : $r['payer'],
        'column' => 0,
        'color'  => $r['other'] ? 'muted' : 'pos',
    ];
    $links[] = ['from' => $id, 'to' => 'hub', 'flow' => $amt];
    $inSum  += $amt;
}

$exSum = 0.0;
foreach ($mf['expense'] as $i => $r) {
    $id  = 'ex' . $i;
    $amt = round($r['amount'], 2);
    $nodes[] = [
        'id'     => $id,
        'label'  => $r['other'] ? 'Other' : pretty_cat($r['category']),
        'column' => 2,
        'color'  => $r['other'] ? 'muted' : ('slice:' . $i),
    ];
    $links[] = ['from' => 'hub', 'to' => $id, 'flow' => $amt];
    $exSum  += $amt;
}

// Balance the hub so in-flow == out-flow exactly (off the rounded link sums):
// a surplus drains to a "Saved / unspent" sink, a deficit is fed by a
// "Drawn from savings" source. Both keep the Sankey balanced and name the gap.
$bal = round($inSum - $exSum, 2);
$savedAmt = $bal > 0 ? $bal : 0.0;
$drawnAmt = $bal < 0 ? -$bal : 0.0;
if ($savedAmt > 0) {
    $nodes[] = ['id' => 'saved', 'label' => 'Saved / unspent', 'column' => 2, 'color' => 'pos'];
    $links[] = ['from' => 'hub', 'to' => 'saved', 'flow' => $savedAmt];
} elseif ($drawnAmt > 0) {
    $nodes[] = ['id' => 'drawn', 'label' => 'Drawn from savings', 'column' => 0, 'color' => 'neg'];
    $links[] = ['from' => 'drawn', 'to' => 'hub', 'flow' => $drawnAmt];
}

render_header('Money flow', 'moneyflow', ['sankey' => true]);
?>

<form class="filter-bar" method="get" action="/moneyflow.php">
    <div class="filter-row">
        <select name="month" class="select" data-autosubmit aria-label="Month">
            <?php foreach ($monthOpts as $val => $lbl): ?>
                <option value="<?= e($val) ?>"<?= $val === $ym ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn-ghost" type="submit">Show</button></noscript>
    </div>
</form>

<!-- Hero: money in / out / net for the month -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Net · <?= e($mf['month_label']) ?></span>
        <span class="delta-sub muted">income − spending</span>
    </div>
    <div class="hero-value <?= $mf['net'] < 0 ? 'neg' : '' ?>">
        <?= ($mf['net'] < 0 ? '−' : '') . e(usd(abs($mf['net']))) ?>
    </div>

    <div class="hero-split tri">
        <div class="split-cell">
            <span class="split-label">Money in</span>
            <span class="split-value pos"><?= e(usd($mf['income_total'])) ?></span>
        </div>
        <div class="split-cell">
            <span class="split-label">Money out</span>
            <span class="split-value neg"><?= e(usd($mf['expense_total'])) ?></span>
        </div>
        <div class="split-cell">
            <span class="split-label">Savings rate</span>
            <span class="split-value <?= $rate !== null && $rate < 0 ? 'neg' : '' ?>"><?= $rate === null ? '—' : number_format($rate, 0) . '%' ?></span>
        </div>
    </div>
</section>

<?php if (!$hasData): ?>
    <div class="empty-state card">
        <h2>No money flow for <?= e($mf['month_label']) ?></h2>
        <p class="muted">Pick another month, or once transactions have synced for this period your income sources and spending categories will flow here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- The Sankey: income sources → Income → spending categories -->
    <section class="card">
        <div class="block-head">
            <h2>Where the money went</h2>
            <span class="muted"><?= e($mf['month_label']) ?></span>
        </div>
        <div class="chart-wrap sankey">
            <canvas id="flow-chart" data-chart="sankey" data-src="flow-data"></canvas>
            <script type="application/json" id="flow-data"><?= json_encode([
                'nodes' => $nodes,
                'links' => $links,
            // JSON_HEX_TAG is REQUIRED here: node labels carry raw payer strings
            // (merchant_name/name from Plaid, unsanitized), so without it a payer
            // containing "</script>" would close this <script> element early and
            // inject live markup. app.js reads the blob via textContent + JSON.parse,
            // which decodes the < escapes transparently (no JS change needed).
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted load-note">Income (left) flows through the month's total into spending categories (right). Excludes internal transfers between your own accounts and credit-card payments, so money isn't counted twice.</p>
    </section>

    <div class="cols">
        <!-- Money in → click a source to see those transactions -->
        <section class="block">
            <div class="block-head"><h2>Money in</h2><span class="count-pill"><?= count($mf['income']) ?></span></div>
            <?php if (!$mf['income']): ?>
                <p class="muted card">No income recorded this month.</p>
            <?php else:
                $imax = max(array_map(fn($r) => (float)$r['amount'], $mf['income'])) ?: 1.0;
                if ($drawnAmt > 0) $imax = max($imax, $drawnAmt);
            ?>
            <div class="cat-list card">
                <?php foreach ($mf['income'] as $r):
                    $w     = max(3, ($r['amount'] / $imax) * 100);
                    $label = $r['other'] ? 'Other income' : $r['payer'];
                    $inner = '<span class="cat-swatch ' . ($r['other'] ? 'other' : 'pos') . '"></span>'
                           . '<span class="cat-name">' . e($label) . '</span>'
                           . '<span class="cat-track"><span style="width:' . round($w) . '%"></span></span>'
                           . '<span class="cat-amt">' . e(usd($r['amount'])) . '</span>';
                    if ($r['other']):
                        echo '<div class="cat-row is-static">' . $inner . '</div>';
                    else:
                        $href = '/transactions.php?' . http_build_query([
                            'merchant' => $r['payer'],
                            'from'     => $fromStr,
                            'to'       => $toStr,
                        ]);
                        echo '<a class="cat-row" href="' . e($href) . '">' . $inner . '</a>';
                    endif;
                endforeach;
                if ($drawnAmt > 0):
                    $w = max(3, ($drawnAmt / $imax) * 100);
                    echo '<div class="cat-row is-static">'
                       . '<span class="cat-swatch neg"></span>'
                       . '<span class="cat-name">Drawn from savings</span>'
                       . '<span class="cat-track"><span style="width:' . round($w) . '%"></span></span>'
                       . '<span class="cat-amt">' . e(usd($drawnAmt)) . '</span>'
                       . '</div>';
                endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- Where it went → click a category to see those transactions -->
        <section class="block">
            <div class="block-head"><h2>Where it went</h2><span class="count-pill"><?= count($mf['expense']) ?></span></div>
            <?php if (!$mf['expense']): ?>
                <p class="muted card">No spending recorded this month.</p>
            <?php else:
                $emax = max(array_map(fn($r) => (float)$r['amount'], $mf['expense'])) ?: 1.0;
                if ($savedAmt > 0) $emax = max($emax, $savedAmt);
            ?>
            <div class="cat-list card">
                <?php foreach ($mf['expense'] as $i => $r):
                    $w     = max(3, ($r['amount'] / $emax) * 100);
                    $label = $r['other'] ? 'Other' : pretty_cat($r['category']);
                    $sw    = $r['other'] ? '<span class="cat-swatch other"></span>'
                                         : '<span class="cat-swatch" style="--i:' . (int)$i . '"></span>';
                    $inner = $sw
                           . '<span class="cat-name">' . e($label) . '</span>'
                           . '<span class="cat-track"><span style="width:' . round($w) . '%"></span></span>'
                           . '<span class="cat-amt">' . e(usd($r['amount'])) . '</span>';
                    if ($r['other']):
                        echo '<div class="cat-row is-static">' . $inner . '</div>';
                    else:
                        $href = '/transactions.php?' . http_build_query([
                            'category' => $r['category'],
                            'from'     => $fromStr,
                            'to'       => $toStr,
                        ]);
                        echo '<a class="cat-row" href="' . e($href) . '">' . $inner . '</a>';
                    endif;
                endforeach;
                if ($savedAmt > 0):
                    $w = max(3, ($savedAmt / $emax) * 100);
                    echo '<div class="cat-row is-static">'
                       . '<span class="cat-swatch pos"></span>'
                       . '<span class="cat-name">Saved / unspent</span>'
                       . '<span class="cat-track"><span style="width:' . round($w) . '%"></span></span>'
                       . '<span class="cat-amt">' . e(usd($savedAmt)) . '</span>'
                       . '</div>';
                endif; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <p class="muted load-note">Tip: a source/category row links to that month's transactions (the full ledger — a superset that also shows pending, refunds and the transfers this view excludes — so only the month + payer/category match, it won't sum to the figure above).</p>

<?php endif; ?>

<?php render_footer(); ?>
