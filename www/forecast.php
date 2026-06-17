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

<div class="page-head">
    <p class="eyebrow">Worth</p>
    <h1>Cash flow forecast</h1>
</div>

<?php if (!$hasCash): ?>
    <div class="empty-state card">
        <h2>No cash accounts to forecast</h2>
        <p class="muted">The forecast projects your checking + savings balance forward. Link a bank account
            (or add a manual one) and the projection appears here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Chart leads: the projected low + horizon selector, then the balance line -->
    <section class="card">
        <div class="chart-lead-head">
            <div class="lead-fig">
                <span class="eyebrow">Projected low · next <?= (int)$horizon ?> days</span>
                <div class="big <?= $fc['goes_negative'] ? 'neg' : '' ?>"><?= ($fc['min_balance'] < 0 ? '−' : '') . e(usd(abs($fc['min_balance']))) ?></div>
                <span class="muted">around <strong><?= e((new DateTimeImmutable($fc['min_date']))->format('D, M j')) ?></strong></span>
            </div>
            <form method="get" action="/forecast.php" class="head-form">
                <select name="horizon" class="select" data-autosubmit aria-label="Forecast horizon">
                    <option value="30"<?= $horizon === 30 ? ' selected' : '' ?>>Next 30 days</option>
                    <option value="60"<?= $horizon === 60 ? ' selected' : '' ?>>Next 60 days</option>
                    <option value="90"<?= $horizon === 90 ? ' selected' : '' ?>>Next 90 days</option>
                </select>
                <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
            </form>
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

    <!-- KPI strip: the cash figures at a glance -->
    <div class="kpis">
        <div class="kpi"><span class="eyebrow">Cash today</span><div class="v"><?= e(usd($fc['start_balance'])) ?></div></div>
        <div class="kpi"><span class="eyebrow">Projected end</span><div class="v <?= $fc['end_balance'] < 0 ? 'neg' : '' ?>"><?= ($fc['end_balance'] < 0 ? '−' : '') . e(usd(abs($fc['end_balance']))) ?></div></div>
        <div class="kpi"><span class="eyebrow">Net change</span><div class="v <?= $netChange < 0 ? 'neg' : 'pos' ?>"><?= ($netChange < 0 ? '−' : '+') . e(usd(abs($netChange))) ?></div></div>
        <div class="kpi"><span class="eyebrow">Everyday spend</span><div class="v"><?= e(usd($fc['discretionary_daily'])) ?><span class="muted" style="font-size:.6em">/day</span></div></div>
    </div>

    <!-- What-if: spend less / save more (#35) -->
    <?php
    // A temporary, NON-persisted overlay: trim the modeled everyday (discretionary) spend by
    // $save/month and re-run the SAME forecast_build, comparing the projected low + end against
    // the baseline. "Saving" here = spending less; it raises the cash you keep (moving money to
    // a savings account wouldn't, since savings is already part of the cash total).
    $fcWifGet = function (string $k, float $def): float {
        $val = $_GET[$k] ?? null;
        if (is_array($val) || $val === null || $val === '' || !is_numeric($val)) return $def;
        $val = (float)$val;
        return is_finite($val) ? $val : $def;
    };
    $save       = max(0.0, min(2000.0, $fcWifGet('save', 0.0)));
    $saveActive = isset($_GET['save']);
    $adjSpend   = max(0.0, $avgSpend - $save / 30.4375);   // $/day reduction (avg days per month)
    $fcW        = forecast_build($accounts, $liab, $recur, $adjSpend, $horizon, new DateTimeImmutable('today'));
    $dLow       = $fcW['min_balance'] - $fc['min_balance'];
    $dEnd       = $fcW['end_balance'] - $fc['end_balance'];
    ?>
    <section class="block">
        <div class="block-head">
            <h2>What if…</h2>
            <?php if ($saveActive): ?><a class="block-link" href="/forecast.php<?= $horizon !== 30 ? '?horizon=' . (int)$horizon : '' ?>">Reset</a><?php endif; ?>
        </div>
        <div class="card">
            <p class="muted" style="margin-top:0">See how cutting your everyday spending changes the
                projection. A preview only — nothing is saved.</p>

            <form method="get" action="/forecast.php" class="whatif-form">
                <?php if ($horizon !== 30): ?><input type="hidden" name="horizon" value="<?= (int)$horizon ?>"><?php endif; ?>
                <div class="whatif-knob">
                    <span class="whatif-knob-head"><span>Spend less / save</span><output class="whatif-out" id="save-out" for="save"></output></span>
                    <input class="whatif-range" type="range" id="save" name="save" min="0" max="2000" step="50"
                           value="<?= (int)round($save) ?>" data-out="#save-out" data-fmt="permonth" data-autosubmit
                           aria-label="Monthly spending to cut">
                </div>
                <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
            </form>

            <section class="hero card" style="margin-top:1rem">
                <div class="hero-top">
                    <span class="hero-label">What-if low</span>
                    <?php if (abs($dLow) >= 0.5): ?>
                        <span class="delta <?= $dLow >= 0 ? 'up' : 'down' ?>">
                            <?= $dLow >= 0 ? '▲' : '▼' ?> <?= ($dLow >= 0 ? '+' : '−') . e(usd(abs($dLow))) ?>
                            <span class="delta-sub">vs now</span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="hero-value <?= $fcW['goes_negative'] ? 'neg' : '' ?>">
                    <?= ($fcW['min_balance'] < 0 ? '−' : '') . e(usd(abs($fcW['min_balance']))) ?>
                </div>
                <div class="hero-split">
                    <div class="split-cell">
                        <span class="split-label">Projected end</span>
                        <span class="split-value <?= $fcW['end_balance'] < 0 ? 'neg' : '' ?>">
                            <?= ($fcW['end_balance'] < 0 ? '−' : '') . e(usd(abs($fcW['end_balance']))) ?>
                        </span>
                    </div>
                    <div class="split-cell">
                        <span class="split-label">vs current plan</span>
                        <span class="split-value <?= $dEnd >= 0 ? 'pos' : 'neg' ?>">
                            <?= ($dEnd >= 0 ? '+' : '−') . e(usd(abs($dEnd))) ?>
                        </span>
                    </div>
                </div>
                <div class="chart-wrap tall" style="margin-top:1rem">
                    <canvas data-chart="multiline" data-src="fcw-data"></canvas>
                    <script type="application/json" id="fcw-data"><?= json_encode([
                        'labels' => array_map(fn($d) => (new DateTimeImmutable($d))->format('M j'), $fc['series']['labels']),
                        'series' => [
                            ['label' => 'Current plan', 'values' => $fc['series']['values'], 'color' => 'muted', 'dashed' => true],
                            ['label' => 'With savings',  'values' => $fcW['series']['values'], 'color' => 'brand', 'fill' => true],
                        ],
                    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                </div>
                <p class="chart-cap" style="margin-top:.8rem">
                    Trims your modeled everyday spend
                    <?php if ($save > 0): ?>from <strong><?= e(usd($fc['discretionary_daily'])) ?>/day</strong>
                        to <strong><?= e(usd($fcW['discretionary_daily'])) ?>/day</strong><?php else: ?>(drag the slider)<?php endif; ?>.
                    Scheduled bills &amp; income are unchanged. A what-if preview, not advice.
                </p>
            </section>
        </div>
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
