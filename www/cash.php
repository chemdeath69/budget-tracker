<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Window selector (?window=). Small fixed set so the chart stays legible.
$window = (int)($_GET['window'] ?? 90);
if (!in_array($window, [30, 90, 365], true)) $window = 90;

$accounts = q_accounts($pdo, $uid);

// Live liquid cash = checking + savings, split for the KPI strip + breakdown.
$cashAccts = [];
$checkTotal = 0.0; $saveTotal = 0.0;
foreach ($accounts as $a) {
    $g = account_group($a);
    if ($g !== 'checking' && $g !== 'savings') continue;
    $cashAccts[] = $a;
    $bal = (float)($a['balance_current'] ?? 0);
    if ($g === 'checking') $checkTotal += $bal; else $saveTotal += $bal;
}
$cashTotal = $checkTotal + $saveTotal;
usort($cashAccts, fn($x, $y) => abs((float)($y['balance_current'] ?? 0)) <=> abs((float)($x['balance_current'] ?? 0)));

$hist = q_cash_history($pdo, $uid, $window);

// Change over the window, anchored to a STABLE account population (accounts present at the
// window's first snapshot) so a newly-linked account doesn't inflate "since {date}" (5.14).
// Only shown when there's a genuine earlier baseline (as_of before today).
$today  = (new DateTimeImmutable('today'))->format('Y-m-d');
$change = q_cash_change($pdo, $uid, $window);
$hasBaseline = $change !== null && $change['as_of'] < $today;
$chgAbs = $hasBaseline ? ($change['current'] - $change['baseline']) : null;
$chgPct = ($hasBaseline && abs($change['baseline']) >= 1.0) ? ($chgAbs / abs($change['baseline'])) * 100 : null;

render_header('Cash on hand', 'cash', ['chart' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Worth</p>
    <h1>Cash on hand</h1>
</div>
<?php render_nav_chips('worth', 'cash'); ?>

<?php if (!$cashAccts): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('bank') ?></span>
        <h2>No cash accounts yet</h2>
        <p class="muted">This page tracks the money in your checking &amp; savings accounts over time.
            Link a bank (or add a manual account) and your liquid cash appears here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Chart leads: liquid cash now + change over the window, then the balance line -->
    <section class="card">
        <div class="chart-lead-head">
            <div class="lead-fig">
                <span class="eyebrow">Liquid cash</span>
                <div class="big"><?= e(usd($cashTotal)) ?></div>
                <?php if ($chgPct !== null): $up = $chgAbs >= 0; ?>
                    <span class="delta <?= $up ? 'up' : 'down' ?>">
                        <?= $up ? '▲' : '▼' ?> <?= ($up ? '+' : '−') . e(usd(abs($chgAbs))) ?>
                        <span class="delta-sub">since <?= e((new DateTimeImmutable($change['as_of']))->format('M j')) ?></span>
                    </span>
                <?php endif; ?>
            </div>
            <form method="get" action="/cash.php" class="head-form">
                <select name="window" class="select" data-autosubmit aria-label="Time window">
                    <option value="30"<?= $window === 30 ? ' selected' : '' ?>>Last 30 days</option>
                    <option value="90"<?= $window === 90 ? ' selected' : '' ?>>Last 90 days</option>
                    <option value="365"<?= $window === 365 ? ' selected' : '' ?>>Last 12 months</option>
                </select>
                <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
            </form>
        </div>
        <?php if (count($hist) > 1): ?>
            <div class="chart-wrap tall">
                <canvas id="cash-chart" data-chart="line" data-src="cash-data"></canvas>
                <script type="application/json" id="cash-data"><?= json_encode([
                    'labels' => array_map(fn($h) => (new DateTimeImmutable($h['snapshot_date']))->format('M j'), $hist),
                    'values' => array_map(fn($h) => (float)$h['balance'], $hist),
                ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            </div>
        <?php else: ?>
            <p class="muted">Your cash history will appear here as daily balance snapshots accumulate.</p>
        <?php endif; ?>
        <p class="muted load-note">
            The combined balance of your checking &amp; savings accounts, recorded daily. Money-market,
            CD and cash-management accounts are counted as savings — handy as a total, but a CD isn't
            always immediately spendable.
        </p>
    </section>

    <!-- KPI strip: the cash split at a glance -->
    <div class="kpis">
        <div class="kpi"><span class="eyebrow">Checking</span><div class="v"><?= e(usd($checkTotal)) ?></div></div>
        <div class="kpi"><span class="eyebrow">Savings</span><div class="v"><?= e(usd($saveTotal)) ?></div></div>
        <div class="kpi"><span class="eyebrow">Total liquid</span><div class="v"><?= e(usd($cashTotal)) ?></div></div>
        <div class="kpi"><span class="eyebrow">Accounts</span><div class="v"><?= count($cashAccts) ?></div></div>
    </div>

    <!-- Per-account breakdown -->
    <section class="block">
        <div class="block-head">
            <h2>Your cash accounts</h2>
            <span class="count-pill"><?= count($cashAccts) ?></span>
        </div>
        <div class="rows card">
            <?php foreach ($cashAccts as $a):
                $bal = (float)($a['balance_current'] ?? 0);
                $sub = $a['institution_name'] ?: pretty_cat($a['subtype'] ?: $a['type']);
            ?>
            <a class="row" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>">
                <span class="row-main">
                    <span class="row-title"><?= e($a['name'] ?: ($a['official_name'] ?: 'Account')) ?></span>
                    <span class="row-sub">
                        <?= e($sub) ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?><?= owner_suffix($a['owner_id'] ?? null) ?>
                        <?php if ($a['visibility'] === 'private'): ?><span class="mini-tag">private</span><?php endif; ?>
                    </span>
                </span>
                <span class="row-amt"><?= e(usd($bal)) ?></span>
                <span class="chev" aria-hidden="true">›</span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

<?php endif; ?>

<?php render_footer(); ?>
