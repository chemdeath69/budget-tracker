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

<form class="filter-bar" method="get" action="/cashflow.php">
    <div class="filter-row">
        <select name="months" class="select" data-autosubmit aria-label="Period">
            <option value="6"<?=  $months === 6  ? ' selected' : '' ?>>Last 6 months</option>
            <option value="12"<?= $months === 12 ? ' selected' : '' ?>>Last 12 months</option>
            <option value="24"<?= $months === 24 ? ' selected' : '' ?>>Last 24 months</option>
        </select>
        <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
    </div>
</form>

<!-- Net cash flow hero: income − expense over the window, with savings rate -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Net cash flow</span>
        <span class="delta-sub muted">last <?= (int)$months ?> months</span>
    </div>
    <div class="hero-value <?= $cf['net'] < 0 ? 'neg' : '' ?>">
        <?= ($cf['net'] < 0 ? '−' : '') . e(usd(abs($cf['net']))) ?>
    </div>

    <div class="hero-split tri">
        <div class="split-cell">
            <span class="split-label">Income</span>
            <span class="split-value pos"><?= e(usd($cf['income'])) ?></span>
        </div>
        <div class="split-cell">
            <span class="split-label">Expenses</span>
            <span class="split-value neg"><?= e(usd($cf['expense'])) ?></span>
        </div>
        <div class="split-cell">
            <span class="split-label">Savings rate</span>
            <span class="split-value <?= $rate !== null && $rate < 0 ? 'neg' : '' ?>"><?= $rate === null ? '—' : number_format($rate, 0) . '%' ?></span>
        </div>
    </div>
</section>

<?php if (!$hasData): ?>
    <div class="empty-state card">
        <h2>No cash flow to show yet</h2>
        <p class="muted">Once a bank is linked and transactions have synced, your monthly income vs. expenses appear here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Monthly income / expense bars + net line -->
    <section class="card">
        <div class="block-head">
            <h2>By month</h2>
            <span class="muted">avg <?= e(usd($avgNet)) ?>/mo net</span>
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
