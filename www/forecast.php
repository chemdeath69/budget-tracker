<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/forecast.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Horizon selector (?horizon=). Keep to a small fixed set so the chart stays legible.
$horizon = (int)($_GET['horizon'] ?? 30);
if (!in_array($horizon, [30, 60, 90], true)) $horizon = 30;

$accounts = q_accounts($pdo, $uid);
$liab     = q_liabilities($pdo, $uid);
$recur    = q_recurring($pdo, $uid);
$avgSpend = q_avg_daily_spend($pdo, $uid, 90);   // trailing-90-day discretionary baseline

$fc = forecast_build($accounts, $liab, $recur, $avgSpend, $horizon, new DateTimeImmutable('today'));

// Is there any spendable cash account to anchor the projection on?
$hasCash = false;
foreach ($accounts as $a) {
    if (in_array(account_group($a), FORECAST_CASH_GROUPS, true)) { $hasCash = true; break; }
}

$netChange = $fc['end_balance'] - $fc['start_balance'];

render_header('Cash flow forecast', 'forecast', ['chart' => true, 'narrow' => true]);
?>

<form class="filter-bar" method="get" action="/forecast.php">
    <div class="filter-row">
        <select name="horizon" class="select" data-autosubmit aria-label="Forecast horizon">
            <option value="30"<?= $horizon === 30 ? ' selected' : '' ?>>Next 30 days</option>
            <option value="60"<?= $horizon === 60 ? ' selected' : '' ?>>Next 60 days</option>
            <option value="90"<?= $horizon === 90 ? ' selected' : '' ?>>Next 90 days</option>
        </select>
        <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
    </div>
</form>

<?php if (!$hasCash): ?>
    <div class="empty-state card">
        <h2>No cash accounts to forecast</h2>
        <p class="muted">The forecast projects your checking + savings balance forward. Link a bank account
            (or add a manual one) and the projection appears here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Projected-low hero: the dip the forecast is all about. -->
    <section class="hero card">
        <div class="hero-top">
            <span class="hero-label">Projected low</span>
            <span class="delta-sub muted">next <?= (int)$horizon ?> days</span>
        </div>
        <div class="hero-value <?= $fc['goes_negative'] ? 'neg' : '' ?>">
            <?= ($fc['min_balance'] < 0 ? '−' : '') . e(usd(abs($fc['min_balance']))) ?>
        </div>
        <p class="muted" style="margin:2px 0 0">
            around <strong><?= e((new DateTimeImmutable($fc['min_date']))->format('D, M j')) ?></strong>
        </p>

        <div class="hero-split tri">
            <div class="split-cell">
                <span class="split-label">Cash today</span>
                <span class="split-value"><?= e(usd($fc['start_balance'])) ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">Projected end</span>
                <span class="split-value <?= $fc['end_balance'] < 0 ? 'neg' : '' ?>">
                    <?= ($fc['end_balance'] < 0 ? '−' : '') . e(usd(abs($fc['end_balance']))) ?>
                </span>
            </div>
            <div class="split-cell">
                <span class="split-label">Net change</span>
                <span class="split-value <?= $netChange < 0 ? 'neg' : 'pos' ?>">
                    <?= ($netChange < 0 ? '−' : '+') . e(usd(abs($netChange))) ?>
                </span>
            </div>
        </div>
    </section>

    <?php if ($fc['goes_negative']): ?>
        <div class="notice warn">
            ⚠️ Your checking + savings balance is projected to dip <strong>below $0</strong>
            around <?= e((new DateTimeImmutable($fc['min_date']))->format('M j')) ?> — a scheduled bill
            may land before money comes in. Move cash over or reschedule a payment to stay ahead.
        </div>
    <?php elseif ($fc['income_count'] === 0): ?>
        <div class="notice warn">
            No recurring income was detected, so this projection only subtracts spending and will trend
            down. Once a regular paycheck/deposit is recognized it'll be added automatically.
        </div>
    <?php elseif (!$fc['has_income']): ?>
        <div class="notice warn">
            Recurring income was detected, but Plaid hasn't reported an amount for it yet — so it can't be
            added to this projection, which only subtracts spending until then.
        </div>
    <?php endif; ?>

    <!-- Projected balance line over the horizon -->
    <section class="card">
        <div class="block-head">
            <h2>Projected balance</h2>
            <span class="muted">checking + savings</span>
        </div>
        <div class="chart-wrap tall">
            <canvas id="fc-chart" data-chart="line" data-src="fc-data"></canvas>
            <script type="application/json" id="fc-data"><?= json_encode([
                'labels' => array_map(fn($d) => (new DateTimeImmutable($d))->format('M j'), $fc['series']['labels']),
                'values' => $fc['series']['values'],
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted load-note">
            Starts from today's cash, adds projected recurring income, subtracts scheduled bills, and spreads
            your average daily spending of <strong><?= e(usd($fc['discretionary_daily'])) ?>/day</strong>
            <?php if ($fc['avg_daily_spend'] > $fc['discretionary_daily'] + 0.005): ?>
                (everyday spend, after recurring bills)
            <?php endif; ?>
            across the <?= (int)$horizon ?> days. An estimate — irregular income isn't projected.
        </p>
    </section>

    <!-- Upcoming money events that shape the curve -->
    <section class="block">
        <div class="block-head">
            <h2>Upcoming money events</h2>
            <span class="count-pill"><?= count($fc['events']) ?></span>
        </div>
        <?php if (!$fc['events']): ?>
            <div class="card"><p class="muted" style="margin:0">No scheduled income or bills detected in the next <?= (int)$horizon ?> days — the projection is your average daily spending only.</p></div>
        <?php else: ?>
            <div class="rows card">
                <?php foreach ($fc['events'] as $e):
                    $isIncome = $e['source'] === 'income';
                    $href = '/account.php?account_id=' . rawurlencode($e['account_id']);
                ?>
                <a class="row" href="<?= e($href) ?>">
                    <span class="row-main">
                        <span class="row-title"><?= e($e['label']) ?><?= owner_suffix($e['owner_id']) ?></span>
                        <span class="row-sub">
                            <span class="bill-kind bill-kind-<?= $isIncome ? 'income' : $e['source'] ?>"><?= e($e['kind']) ?></span>
                            <?= e((new DateTimeImmutable($e['date']))->format('D, M j')) ?>
                            <?= $e['sublabel'] !== '' ? '· ' . e($e['sublabel']) : '' ?>
                        </span>
                    </span>
                    <span class="row-amt <?= $isIncome ? 'pos' : 'neg' ?>">
                        <?php if ($e['amount'] === null): ?>—<?php
                        else: ?><?= ($isIncome ? '+' : '−') . e(usd($e['amount'])) ?><?php endif; ?>
                    </span>
                    <span class="chev" aria-hidden="true">›</span>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php render_footer(); ?>
