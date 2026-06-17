<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Window selector (?months=). Keep to a small fixed set so the chart stays legible.
$months = (int)($_GET['months'] ?? 12);
if (!in_array($months, [6, 12, 24], true)) $months = 12;

$cf      = q_cashflow($pdo, $uid, $months);
$rate    = $cf['income'] > 0 ? ($cf['net'] / $cf['income']) * 100 : null; // savings rate
$avgNet  = $months > 0 ? $cf['net'] / $months : 0.0;
$hasData = $cf['income'] > 0 || $cf['expense'] > 0;

// Month list shown newest-first; each row links to that month's transactions.
$rows = array_reverse($cf['months']);

render_header('Cash flow', 'cashflow', ['chart' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Spend</p>
    <h1>Cash flow</h1>
</div>

<?php if (!$hasData): ?>
    <div class="empty-state card">
        <h2>No cash flow to show yet</h2>
        <p class="muted">Once a bank is linked and transactions have synced, your monthly income vs. expenses appear here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Chart leads: net figure + period selector, then the monthly bars + net line -->
    <section class="card">
        <div class="chart-lead-head">
            <div class="lead-fig">
                <span class="eyebrow">Net cash flow · last <?= (int)$months ?> months</span>
                <div class="big <?= $cf['net'] < 0 ? 'neg' : '' ?>"><?= ($cf['net'] < 0 ? '−' : '') . e(usd(abs($cf['net']))) ?></div>
            </div>
            <form method="get" action="/cashflow.php" class="head-form">
                <select name="months" class="select" data-autosubmit aria-label="Period">
                    <option value="6"<?=  $months === 6  ? ' selected' : '' ?>>Last 6 months</option>
                    <option value="12"<?= $months === 12 ? ' selected' : '' ?>>Last 12 months</option>
                    <option value="24"<?= $months === 24 ? ' selected' : '' ?>>Last 24 months</option>
                </select>
                <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
            </form>
        </div>
        <div class="chart-wrap tall">
            <canvas id="cf-chart" data-chart="cashflow" data-src="cf-data"></canvas>
            <script type="application/json" id="cf-data"><?= json_encode([
                'labels'  => array_column($cf['months'], 'label'),
                'income'  => array_map(fn($m) => round($m['income'], 2), $cf['months']),
                'expense' => array_map(fn($m) => round($m['expense'], 2), $cf['months']),
                'net'     => array_map(fn($m) => round($m['net'], 2), $cf['months']),
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted load-note">Excludes internal transfers between your own accounts and credit-card payments, so money isn't counted twice.</p>
    </section>

    <!-- KPI strip: the supporting figures at a glance -->
    <div class="kpis">
        <div class="kpi"><span class="eyebrow">Income</span><div class="v pos"><?= e(usd($cf['income'])) ?></div></div>
        <div class="kpi"><span class="eyebrow">Expenses</span><div class="v neg"><?= e(usd($cf['expense'])) ?></div></div>
        <div class="kpi"><span class="eyebrow">Savings rate</span><div class="v <?= $rate !== null && $rate < 0 ? 'neg' : '' ?>"><?= $rate === null ? '—' : number_format($rate, 0) . '%' ?></div></div>
        <div class="kpi"><span class="eyebrow">Avg / mo net</span><div class="v <?= $avgNet < 0 ? 'neg' : '' ?>"><?= ($avgNet < 0 ? '−' : '') . e(usd(abs($avgNet))) ?></div></div>
    </div>

    <!-- Per-month breakdown → click a month to see its transactions -->
    <section class="block">
        <div class="block-head"><h2>Months</h2><span class="count-pill"><?= count($rows) ?></span></div>
        <div class="rows card">
            <?php foreach ($rows as $m):
                $end  = (new DateTimeImmutable($m['ym'] . '-01'))->format('Y-m-t');
                $href = '/transactions.php?' . http_build_query(['from' => $m['ym'] . '-01', 'to' => $end]);
            ?>
            <a class="row" href="<?= e($href) ?>">
                <span class="row-main">
                    <span class="row-title"><?= e($m['label']) ?></span>
                    <span class="row-sub">
                        <span class="pos">+<?= e(usd($m['income'])) ?></span> in ·
                        <span class="neg"><?= e(usd($m['expense'])) ?></span> out
                    </span>
                </span>
                <span class="row-amt <?= $m['net'] < 0 ? 'neg' : 'pos' ?>">
                    <?= ($m['net'] < 0 ? '−' : '+') . e(usd(abs($m['net']))) ?>
                </span>
                <span class="chev" aria-hidden="true">›</span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

<?php endif; ?>

<?php render_footer(); ?>
