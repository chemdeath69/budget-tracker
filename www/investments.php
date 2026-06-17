<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/returns.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo   = db();
$uid   = current_user_id();
// Retirement accounts (manual 401(k)s + Plaid IRAs/401k by subtype/override) live
// on the Retirement page instead — keep them off the Investments page.
$holds = array_values(array_filter(q_holdings($pdo, $uid), fn($h) => !is_retirement_account($h)));

/* ---- Portfolio totals ---------------------------------------------------- */
$total    = 0.0;   // market value of every holding
$costSum  = 0.0;   // cost basis where we have it
$valCost  = 0.0;   // market value of holdings that have a cost basis (so gain% lines up)
$haveCost = 0;
foreach ($holds as $h) {
    $val  = (float)($h['institution_value'] ?? 0);
    $total += $val;
    if ($h['cost_basis'] !== null) {
        $haveCost++;
        $costSum += (float)$h['cost_basis'];
        $valCost += $val;
    }
}
$gain     = $haveCost ? $valCost - $costSum : null;
$gainPct  = ($haveCost && $costSum != 0.0) ? round(($gain / abs($costSum)) * 100, 1) : null;
$partial  = $haveCost > 0 && $haveCost < count($holds); // some holdings lack a cost basis

/* ---- Price-based change over time (day / week / month / year) ------------- */
$HORIZONS = ['d1' => 'Today', 'd7' => '1 wk', 'd30' => '1 mo', 'd365' => '1 yr'];
$changes  = q_price_changes($pdo, array_map(fn($h) => $h['security_id'] ?? null, $holds));
$priceAsOf = null;
$pf = [];
foreach (array_keys($HORIZONS) as $k) $pf[$k] = ['abs' => 0.0, 'base' => 0.0, 'has' => false];
foreach ($holds as $h) {
    $c = $changes[$h['security_id']] ?? null;
    if (!$c) continue;
    if ($priceAsOf === null || $c['date'] > $priceAsOf) $priceAsOf = $c['date'];
    if ($h['quantity'] === null || $c['current'] === null) continue;
    $qty = (float)$h['quantity'];
    foreach (array_keys($HORIZONS) as $k) {
        if ($c[$k] === null) continue;
        $pf[$k]['abs']  += ($c['current'] - $c[$k]) * $qty;
        $pf[$k]['base'] += $c[$k] * $qty;
        $pf[$k]['has']   = true;
    }
}
$hasPerf = (bool)array_filter($pf, fn($x) => $x['has']);
$history = q_portfolio_history($pdo, $holds); // [['date','value'],…] at current holdings

/* ---- Group holdings by account (drill-down + freshness + gaps) ------------ */
$byAccount = [];
foreach ($holds as $h) {
    $aid = $h['account_id'];
    if (!isset($byAccount[$aid])) {
        $byAccount[$aid] = [
            'name'    => $h['account_name'],
            'mask'    => $h['mask'],
            'inst'    => $h['institution_name'],
            'owner_id'=> $h['owner_id'] ?? null,
            'source'  => $h['source'],
            'asof'    => $h['last_updated_datetime'],
            'value'   => 0.0,
            'rows'    => [],
        ];
    }
    $byAccount[$aid]['value'] += (float)($h['institution_value'] ?? 0);
    $byAccount[$aid]['rows'][] = $h;
}
// Statement-coverage gaps for the manual accounts that hold positions.
$coverage = [];
foreach ($byAccount as $aid => $g) {
    if (($g['source'] ?? 'plaid') === 'manual') $coverage[$aid] = q_statement_coverage($pdo, $aid);
}

/* ---- Allocation donut (by holding) --------------------------------------- */
$alloc    = [];
$allocSid = [];   // label => security_id (first seen) for the drill-down link
foreach ($holds as $h) {
    $label = $h['ticker_symbol'] ?: ($h['security_name'] ?: '—');
    $alloc[$label] = ($alloc[$label] ?? 0) + (float)($h['institution_value'] ?? 0);
    if (!isset($allocSid[$label]) && !empty($h['security_id'])) {
        $allocSid[$label] = $h['security_id'];
    }
}
arsort($alloc);

/* ---- Brokerage income + trades (manual Webull feeds + Plaid investment txns) ---- */
// Optional account filter (governs both the dividend and trade lists), plus
// independent pagination keys for each list.
$invAcct = trim((string)($_GET['iacct'] ?? ''));
$dPage   = page_num('dpage');
$tPage   = page_num('tpage');
$cPage   = page_num('cpage');

// Activity is scoped to the (non-retirement) investment accounts THIS page renders —
// so a retirement brokerage's dividends/trades stay on retirement.php, not here. The
// picker offers all of them; choosing one narrows the scope.
$invAcctOpts = [];
foreach ($byAccount as $aid => $g) $invAcctOpts[(string)$aid] = $g['name'] ?: 'Account';
$invScope = ($invAcct !== '' && isset($invAcctOpts[$invAcct])) ? [$invAcct] : array_keys($invAcctOpts);

$incomeRaw     = q_investment_activity($pdo, $uid, 'income', $invScope, PAGE_SIZE + 1, page_offset($dPage));
$incomeHasNext = count($incomeRaw) > PAGE_SIZE;
$income        = array_slice($incomeRaw, 0, PAGE_SIZE);

$tradesRaw     = q_investment_activity($pdo, $uid, 'trades', $invScope, PAGE_SIZE + 1, page_offset($tPage));
$tradesHasNext = count($tradesRaw) > PAGE_SIZE;
$trades        = array_slice($tradesRaw, 0, PAGE_SIZE);

// Contributions (Plaid deposits, e.g. payroll) — kept separate so they don't inflate the
// dividend/interest total. Only rendered when present (a taxable brokerage may have none).
$contribRaw     = q_investment_activity($pdo, $uid, 'contributions', $invScope, PAGE_SIZE + 1, page_offset($cPage));
$contribHasNext = count($contribRaw) > PAGE_SIZE;
$contribs       = array_slice($contribRaw, 0, PAGE_SIZE);
$contribTotal   = -q_investment_activity_total($pdo, $uid, 'contributions', $invScope);

// True total across all rows (not just this page); stored − = money in → flip to +.
$incomeTotal = -q_investment_activity_total($pdo, $uid, 'income', $invScope);

/* Tax summaries for each manual investment account we're showing. */
$taxByAccount = [];
foreach ($byAccount as $aid => $g) {
    if (($g['source'] ?? 'plaid') === 'manual') {
        $t = q_tax_summaries($pdo, $aid);
        if ($t) $taxByAccount[$aid] = $t;
    }
}

/* ---- Dividend income & calendar (Polygon feed → security_dividends) -------- */
// Projected ANNUAL dividend income from current share counts (latest declared rate ×
// payout frequency) + an UPCOMING ex-dividend agenda (next 90 days). Scoped to the
// non-retirement holdings already in $holds; "today"/horizon are PHP app-TZ.
$divs         = q_security_dividends($pdo, array_map(fn($h) => $h['security_id'] ?? null, $holds));
$divProjTotal = 0.0;     // Σ qty × per-share annual dividend
$divRows      = [];      // per-holding projection rows (dividend-payers only)
$divUpcoming  = [];      // ex_date => ['pay_date'=>?, 'items'=>[[ticker,amount],…], 'total'=>f]
$divHorizon   = date('Y-m-d', strtotime('+90 days'));
foreach ($holds as $h) {
    $sid = $h['security_id'] ?? null;
    $qty = $h['quantity'] !== null ? (float)$h['quantity'] : 0.0;
    if ($sid === null || $qty <= 0 || !isset($divs[$sid])) continue;
    $d      = $divs[$sid];
    $ticker = $h['ticker_symbol'] ?: ($h['security_name'] ?: '—');

    if ($d['annual_ps'] !== null) {
        $proj = $qty * $d['annual_ps'];
        $divProjTotal += $proj;
        $divRows[] = ['ticker' => $ticker, 'qty' => $qty,
                      'cash' => $d['latest']['cash_amount'], 'freq' => $d['latest']['frequency'],
                      'annual' => $proj];
    }
    foreach ($d['upcoming'] as $u) {
        if ($u['ex_date'] > $divHorizon) continue;
        $ex = $u['ex_date'];
        if (!isset($divUpcoming[$ex])) $divUpcoming[$ex] = ['pay_date' => $u['pay_date'], 'items' => [], 'total' => 0.0];
        $divUpcoming[$ex]['items'][] = ['ticker' => $ticker, 'amount' => $qty * $u['cash_amount']];
        $divUpcoming[$ex]['total']  += $qty * $u['cash_amount'];
    }
}
usort($divRows, fn($a, $b) => $b['annual'] <=> $a['annual']);
ksort($divUpcoming);
$divYield   = ($total > 0 && $divProjTotal > 0) ? round($divProjTotal / $total * 100, 2) : null;
$hasDivData = $divRows || $divUpcoming;

/* ---- Money-weighted return + benchmark (#29) ----------------------------- */
// Buy/sell cash flows over the accounts this page shows (non-retirement) + the
// current market value → an annualized IRR, compared to a user-picked index we
// already price (default SPY). Only shown where the lot history reconciles with
// the current share count (else a missing lot would make the return wrong).
$retAcctIds = array_keys($byAccount);
$retLots    = $retAcctIds ? q_investment_lots($pdo, $uid, $retAcctIds) : [];
$benchCands = []; $bench = null; $retView = ['portfolio' => ['irr' => null], 'accounts' => []];
if ($retLots) {
    $benchCands = q_benchmark_candidates($pdo);
    $benchSel   = strtoupper(trim((string)($_GET['bench'] ?? '')));
    $benchPick  = null;
    foreach ($benchCands as $bc) { if (strtoupper((string)$bc['ticker_symbol']) === $benchSel) { $benchPick = $bc; break; } }
    if ($benchPick === null && $benchCands) {        // default: SPY, else the most-history candidate
        foreach ($benchCands as $bc) { if (strtoupper((string)$bc['ticker_symbol']) === 'SPY') { $benchPick = $bc; break; } }
        if ($benchPick === null) $benchPick = $benchCands[0];
    }
    if ($benchPick) {
        [$bAsOf, $bLatest] = ret_bench_lookup(q_security_prices($pdo, $benchPick['security_id'], 4000));
        if ($bLatest > 0) {
            $bench = ['asof' => $bAsOf, 'latest' => $bLatest,
                      'ticker' => strtoupper((string)$benchPick['ticker_symbol']), 'name' => $benchPick['name']];
        }
    }
    $retView = build_investment_returns($holds, $retLots, $bench, date('Y-m-d'));
}
$ret    = $retView['portfolio'];
$hasRet = $ret['irr'] !== null;

render_header('Investments', 'investments', ['chart' => true]);

/** A holding's value change over $key (e.g. 'd30') as [abs, pct] or null. */
function hold_change(?array $c, ?float $qty, string $key): ?array
{
    if (!$c || $qty === null || $c['current'] === null || ($c[$key] ?? null) === null) return null;
    $abs  = ($c['current'] - $c[$key]) * $qty;
    $base = $c[$key] * $qty;
    return [$abs, $base != 0.0 ? $abs / abs($base) * 100 : null];
}

/** Friendly label for a Polygon payout frequency (payouts/yr). */
function div_freq_label(?int $f): string
{
    return match ((int)$f) {
        1  => 'annual',
        2  => 'semi-annual',
        4  => 'quarterly',
        12 => 'monthly',
        24 => 'bi-monthly',
        52 => 'weekly',
        default => 'periodic',
    };
}
?>

<div class="page-head">
    <p class="eyebrow">Invest</p>
    <h1>Investments</h1>
</div>

<?php if (!$holds): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('invest') ?></span>
        <h2>No holdings yet</h2>
        <p class="muted">Link a brokerage account (or re-link an existing one to grant investment access) to see your holdings here.</p>
        <a class="btn" href="/link.php">Link an account</a>
    </div>
<?php else: ?>
    <!-- Chart leads: total value + total gain, then value over time -->
    <section class="card">
        <div class="chart-lead-head">
            <div class="lead-fig">
                <span class="eyebrow">Total holdings value</span>
                <div class="big"><?= e(usd($total)) ?></div>
                <?php if ($gain !== null): ?>
                    <span class="muted" style="font-size:.84rem"><?= ($gain >= 0 ? '+' : '−') . e(usd(abs($gain))) ?> total gain/loss<?php if ($partial): ?> · <span title="Some holdings have no cost basis yet">excludes holdings without cost basis</span><?php endif; ?></span>
                <?php else: ?>
                    <span class="muted" style="font-size:.84rem">Cost basis isn't available yet, so gain/loss can't be shown.</span>
                <?php endif; ?>
            </div>
            <?php if ($gainPct !== null): $up = $gain >= 0; ?>
            <div class="lead-deltas"><span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= number_format(abs($gainPct), 1) ?>%<span class="delta-sub">total</span></span></div>
            <?php endif; ?>
        </div>
        <?php if (count($history) > 1): ?>
        <div class="chart-wrap tall">
            <canvas id="pv-chart" data-chart="line" data-src="pv-data"></canvas>
            <script type="application/json" id="pv-data"><?= json_encode([
                'labels' => array_column($history, 'date'),
                'values' => array_map('floatval', array_column($history, 'value')),
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
        <p class="muted chart-cap">Value over time at current holdings.</p>
        <?php endif; ?>
    </section>

    <?php if ($hasPerf): ?>
    <section class="block">
        <div class="block-head"><h2>Performance</h2><?php if ($priceAsOf): ?><span class="muted">as of <?= e($priceAsOf) ?></span><?php endif; ?></div>
        <div class="card perf-grid">
            <?php foreach ($HORIZONS as $k => $lbl): $x = $pf[$k]; ?>
            <div class="perf-cell">
                <span class="perf-label"><?= e($lbl) ?></span>
                <?php if ($x['has']): $up = $x['abs'] >= 0; $pct = $x['base'] != 0.0 ? ($x['abs'] / abs($x['base'])) * 100 : null; ?>
                    <span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= $pct !== null ? e(number_format(abs($pct), 1)) . '%' : '' ?></span>
                    <span class="perf-sub muted"><?= ($x['abs'] >= 0 ? '+' : '−') . e(usd(abs($x['abs']))) ?></span>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php /* ---- Return vs benchmark (#29) ---- */ ?>
    <?php if ($retLots): ?>
    <section class="block">
        <div class="block-head">
            <h2>Return vs benchmark</h2>
            <?php if ($hasRet && count($benchCands) > 1): ?>
            <form method="get" class="head-form">
                <select name="bench" class="select" data-autosubmit aria-label="Benchmark index">
                    <?php foreach ($benchCands as $bc): $tk = strtoupper((string)$bc['ticker_symbol']); ?>
                        <option value="<?= e($tk) ?>"<?= ($bench && $bench['ticker'] === $tk) ? ' selected' : '' ?>><?= e($tk) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($hasRet):
            $up     = $ret['irr'] >= 0;
            $bTk    = $bench['ticker'] ?? null;
            $bIrr   = $ret['bench_irr'];
            $spread = ($bIrr !== null) ? $ret['irr'] - $bIrr : null; ?>
        <section class="card hero">
            <div class="hero-top">
                <span class="hero-label">Your annualized return</span>
                <?php if ($spread !== null): $beat = $spread >= 0; ?>
                    <span class="delta <?= $beat ? 'up' : 'down' ?>"><?= $beat ? '▲' : '▼' ?> <?= e(number_format(abs($spread) * 100, 1)) ?> pp<span class="delta-sub">vs <?= e($bTk) ?></span></span>
                <?php endif; ?>
            </div>
            <div class="hero-value <?= $up ? '' : 'neg' ?>"><?= e(ret_pct($ret['irr'])) ?></div>
            <div class="hero-split tri">
                <div class="split-cell">
                    <span class="split-label">You</span>
                    <span class="split-value <?= $up ? 'pos' : 'neg' ?>"><?= e(ret_pct($ret['irr'])) ?></span>
                </div>
                <div class="split-cell">
                    <span class="split-label"><?= $bTk ? e($bTk) : 'Index' ?></span>
                    <span class="split-value"><?= $bIrr !== null ? e(ret_pct($bIrr)) : '—' ?></span>
                </div>
                <div class="split-cell">
                    <span class="split-label">Difference</span>
                    <span class="split-value <?= $spread === null ? '' : ($spread >= 0 ? 'pos' : 'neg') ?>"><?= $spread === null ? '—' : e(($spread >= 0 ? '+' : '−') . number_format(abs($spread) * 100, 1) . ' pp') ?></span>
                </div>
            </div>
            <p class="muted ret-note" style="margin-top:.9rem">
                Money-weighted (annualized IRR)<?php if ($ret['start']): ?> since <?= e(date('M j, Y', strtotime($ret['start']))) ?><?php endif; ?> · invested <?= e(usd($ret['invested'])) ?> · now worth <?= e(usd($ret['mkt_value'])) ?>.
                <?php if ($bench && $bIrr !== null): ?> <?= e($bTk) ?> = the same contributions placed in <?= e($bTk) ?> over the same dates.<?php elseif ($benchCands && $bench && $bIrr === null): ?> <?= e($bTk) ?> comparison unavailable — the benchmark's price history doesn't cover your full window.<?php elseif (!$benchCands): ?> No index benchmark yet (we compare against a broad-market fund you hold, e.g. SPY).<?php endif; ?>
            </p>
            <?php if (($ret['excl_count'] ?? 0) > 0): ?>
            <p class="muted ret-note">Excludes <?= (int)$ret['excl_count'] ?> holding<?= $ret['excl_count'] > 1 ? 's' : '' ?> (<?= e(usd($ret['excl_value'])) ?>) without a complete buy/sell history on file.</p>
            <?php endif; ?>
        </section>

        <?php if (count($retView['accounts']) > 1): ?>
        <div class="card">
            <div class="ret-acct ret-acct-head">
                <span class="ret-name muted">Account</span>
                <span class="ret-mine muted">You</span>
                <span class="ret-bench"><?= $bench ? e($bench['ticker']) : '—' ?></span>
            </div>
            <?php foreach ($retView['accounts'] as $a): $au = $a['irr'] >= 0; ?>
            <div class="ret-acct">
                <span class="ret-name"><a href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>"><?= e($a['account_name']) ?></a></span>
                <span class="ret-mine <?= $au ? 'ret-pos' : 'ret-neg' ?>"><?= e(ret_pct($a['irr'])) ?></span>
                <span class="ret-bench"><?= $a['bench_irr'] !== null ? e(ret_pct($a['bench_irr'])) : '—' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="card"><p class="muted">We can't compute a reliable return yet — your recorded buy/sell history doesn't reconcile with the current share counts (a statement or transaction is likely missing). Once the full lot history is on file, an annualized return vs an index appears here.</p></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="cols">
    <!-- Allocation -->
    <section class="block">
        <div class="block-head"><h2>Allocation</h2></div>
        <div class="card">
            <div class="chart-wrap">
                <canvas id="alloc-chart" data-chart="doughnut" data-src="alloc-data"></canvas>
                <script type="application/json" id="alloc-data"><?= json_encode([
                    // labels are ticker_symbol ?: security_name — RAW Plaid strings, so
                    // JSON_HEX_TAG is REQUIRED: a security name containing "</script>"
                    // would otherwise close this <script> element early (stored XSS).
                    'labels' => array_keys($alloc),
                    'values' => array_map(fn($v) => round($v, 2), array_values($alloc)),
                ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            </div>
            <div class="rows">
                <?php $i = 0; foreach ($alloc as $label => $val): if ($total <= 0) break; ?>
                <div class="row">
                    <span class="row-main">
                        <span class="row-title"><span class="cat-swatch" style="background:<?= chart_slice_color($i) ?>"></span> <?php if (!empty($allocSid[$label])): ?><a href="/security.php?security_id=<?= e(urlencode($allocSid[$label])) ?>&amp;from=investments"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?></span>
                    </span>
                    <span class="row-amt"><?= e(number_format($val / $total * 100, 1)) ?>%</span>
                </div>
                <?php $i++; endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Brokerage income -->
    <?php render_investment_activity('Dividends & interest', $income, [
        'head_right'   => $incomeTotal > 0 ? '<span class="split-value pos">' . e(usd($incomeTotal)) . '</span>' : '',
        'page'         => $dPage,
        'has_next'     => $incomeHasNext,
        'pager_key'    => 'dpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($tPage > 1 ? ['tpage' => $tPage] : []) + ($cPage > 1 ? ['cpage' => $cPage] : []),
        'empty'        => $invAcct !== '' || $dPage > 1
            ? 'No dividend or interest activity for this filter.'
            : 'No dividend or interest activity recorded yet. It appears as brokerage feeds sync or statements are uploaded.',
        'filter'       => ['opts' => $invAcctOpts, 'current' => $invAcct, 'action' => '/investments.php'],
    ]); ?>
    </div><!-- /.cols -->

    <!-- Dividend income & calendar (projected from current holdings, Polygon feed) -->
    <section class="block">
        <div class="block-head">
            <h2>Dividend income &amp; calendar</h2>
            <?php if ($divProjTotal > 0): ?><span class="split-value pos"><?= e(usd($divProjTotal)) ?>/yr</span><?php endif; ?>
        </div>
        <?php if (!$hasDivData): ?>
            <p class="muted">Projected annual dividend income and an upcoming ex-dividend calendar will appear here once the dividend feed has data for your holdings.</p>
        <?php else: ?>
            <?php if ($divRows): ?>
            <p class="muted div-proj-note">Projected forward income from current share counts<?= $divYield !== null ? ' · ≈' . e(number_format($divYield, 2)) . '% yield on ' . e(usd($total)) : '' ?>. Estimate — assumes the latest declared rate &amp; cadence continue.</p>
            <div class="rows card">
                <?php foreach ($divRows as $r): ?>
                <div class="row">
                    <span class="row-main">
                        <span class="row-title"><?= e($r['ticker']) ?></span>
                        <span class="row-sub"><?= e(number_format($r['qty'], 4)) ?> sh · <?= e(usd($r['cash'])) ?>/sh · <?= e(div_freq_label($r['freq'])) ?></span>
                    </span>
                    <span class="row-side">
                        <span class="row-amt"><?= e(usd($r['annual'])) ?> <span class="muted">/yr</span></span>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($divUpcoming): ?>
            <h3 class="div-cal-head">Upcoming ex-dividend dates <span class="muted">(next 90 days)</span></h3>
            <div class="rows card">
                <?php foreach ($divUpcoming as $ex => $day):
                    $tickers = implode(', ', array_map(fn($i) => $i['ticker'], $day['items'])); ?>
                <div class="row">
                    <span class="row-main">
                        <span class="row-title"><?= e(date('D, M j', strtotime($ex))) ?></span>
                        <span class="row-sub"><?= e($tickers) ?><?= $day['pay_date'] ? ' · pays ' . e(date('M j', strtotime($day['pay_date']))) : '' ?></span>
                    </span>
                    <span class="row-side">
                        <span class="row-amt"><?= e(usd($day['total'])) ?></span>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Holdings, grouped by account -->
    <?php $gi = 0; foreach ($byAccount as $aid => $g): $gi++;
        $cov = $coverage[$aid] ?? null;
        $asof = $cov['latest'] ?? ($g['asof'] ? substr((string)$g['asof'], 0, 7) : null); ?>
    <section class="block">
        <div class="block-head">
            <h2><a href="/account.php?account_id=<?= e(urlencode($aid)) ?>"><?= e($g['name'] ?: 'Account') ?></a><?= $g['mask'] ? ' <span class="muted">••' . e($g['mask']) . '</span>' : '' ?><?= owner_suffix($g['owner_id'] ?? null) ?></h2>
            <span class="split-value"><?= e(usd($g['value'])) ?></span>
        </div>
        <?php if ($asof): ?>
            <p class="muted asof-line">Holdings as of <?= e($asof) ?><?= ($g['source'] ?? '') === 'manual' ? ' statement' : '' ?>.</p>
        <?php endif; ?>
        <?php if ($cov && $cov['missing']): ?>
            <div class="notice warn">Missing statement<?= count($cov['missing']) > 1 ? 's' : '' ?> for <?= e(implode(', ', $cov['missing'])) ?> — dividend, trade and tax figures for this account may be incomplete.</div>
        <?php endif; ?>
        <?php if (count($g['rows']) > 8): ?>
        <div class="search-bar">
            <input type="search" class="search-input" data-filter="#hold-<?= (int)$gi ?>" placeholder="Filter holdings…">
        </div>
        <?php endif; ?>
        <div class="rows card" id="hold-<?= (int)$gi ?>">
            <?php foreach ($g['rows'] as $h):
                $sec   = ($h['ticker_symbol'] ? $h['ticker_symbol'] . ' — ' : '') . ($h['security_name'] ?: '—');
                $val   = $h['institution_value'] !== null ? (float)$h['institution_value'] : null;
                $cb    = $h['cost_basis'] !== null ? (float)$h['cost_basis'] : null;
                $hgain = ($val !== null && $cb !== null) ? $val - $cb : null;
                $hpct  = ($hgain !== null && $cb != 0.0) ? round($hgain / abs($cb) * 100, 1) : null;
                $c     = $changes[$h['security_id']] ?? null;
                $qty   = $h['quantity'] !== null ? (float)$h['quantity'] : null; ?>
            <div class="row" data-search="<?= e(strtolower($sec)) ?>">
                <span class="row-main">
                    <span class="row-title"><a href="/security.php?security_id=<?= e(urlencode($h['security_id'])) ?>&amp;from=investments"><?= e($sec) ?></a></span>
                    <span class="row-sub">
                        <?php if ($h['quantity'] !== null): ?><?= e(number_format((float)$h['quantity'], 4)) ?> @ <?= e(usd($h['institution_price'])) ?><?php endif; ?>
                        <?php if ($cb !== null): ?> · cost <?= e(usd($cb)) ?><?php endif; ?>
                    </span>
                    <?php if ($c && $qty !== null && $c['current'] !== null): ?>
                    <span class="chg-strip">
                        <?php foreach ($HORIZONS as $k => $lbl): $d = hold_change($c, $qty, $k); if (!$d) continue; $up = $d[0] >= 0; ?>
                        <span class="chg <?= $up ? 'up' : 'down' ?>"><span class="chg-l"><?= e($lbl) ?></span> <?= $up ? '▲' : '▼' ?><?= $d[1] !== null ? e(number_format(abs($d[1]), 1)) . '%' : '' ?></span>
                        <?php endforeach; ?>
                    </span>
                    <?php endif; ?>
                </span>
                <span class="row-side">
                    <span class="row-amt"><?= $val !== null ? e(usd($val)) : '—' ?></span>
                    <?php if ($hgain !== null): ?>
                        <span class="delta <?= $hgain >= 0 ? 'up' : 'down' ?>"><?= $hgain >= 0 ? '▲' : '▼' ?> <?= ($hgain >= 0 ? '+' : '−') . e(usd(abs($hgain))) ?><?php if ($hpct !== null): ?> (<?= e(number_format(abs($hpct), 1)) ?>%)<?php endif; ?></span>
                    <?php elseif ($val !== null): ?>
                        <span class="muted mini-tag">cost basis pending</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <!-- Recent trades -->
    <?php if ($trades || $invAcct !== '' || $tPage > 1): ?>
    <?php render_investment_activity('Recent trades', $trades, [
        'head_right'   => $trades ? '<span class="count-pill">' . count($trades) . '</span>' : '',
        'page'         => $tPage,
        'has_next'     => $tradesHasNext,
        'pager_key'    => 'tpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($dPage > 1 ? ['dpage' => $dPage] : []) + ($cPage > 1 ? ['cpage' => $cPage] : []),
        'empty'        => 'No trades for this filter.',
        'filter'       => ['opts' => $invAcctOpts, 'current' => $invAcct, 'action' => '/investments.php'],
    ]); ?>
    <?php endif; ?>

    <!-- Contributions (Plaid deposits) — only when present -->
    <?php if ($contribs || $cPage > 1): ?>
    <?php render_investment_activity('Recent contributions', $contribs, [
        'head_right'   => $contribTotal > 0 ? '<span class="split-value pos">' . e(usd($contribTotal)) . '</span>' : '',
        'page'         => $cPage,
        'has_next'     => $contribHasNext,
        'pager_key'    => 'cpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($dPage > 1 ? ['dpage' => $dPage] : []) + ($tPage > 1 ? ['tpage' => $tPage] : []),
        'empty'        => 'No contributions for this filter.',
        'filter'       => ['opts' => $invAcctOpts, 'current' => $invAcct, 'action' => '/investments.php'],
    ]); ?>
    <?php endif; ?>

    <!-- Tax summaries (manual 1099) -->
    <?php foreach ($taxByAccount as $aid => $taxes): ?>
    <section class="block">
        <div class="block-head"><h2>Tax summary (1099) — <?= e($byAccount[$aid]['name'] ?? 'Account') ?></h2></div>
        <div class="card">
        <?php foreach ($taxes as $tx): ?>
        <div class="tax-year">
            <div class="tax-year-head"><strong><?= e($tx['tax_year']) ?></strong></div>
            <div class="kv-grid">
                <div><span class="muted">Ordinary dividends</span><strong><?= e(usd($tx['ordinary_dividends'])) ?></strong></div>
                <div><span class="muted">Qualified dividends</span><strong><?= e(usd($tx['qualified_dividends'])) ?></strong></div>
                <?php if ($tx['interest_income'] !== null): ?><div><span class="muted">Interest income</span><strong><?= e(usd($tx['interest_income'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['capital_gain_distributions'] !== null): ?><div><span class="muted">Cap. gain distributions</span><strong><?= e(usd($tx['capital_gain_distributions'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['proceeds'] !== null): ?><div><span class="muted">Sale proceeds</span><strong><?= e(usd($tx['proceeds'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['cost_basis'] !== null): ?><div><span class="muted">Cost basis (sold)</span><strong><?= e(usd($tx['cost_basis'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['net_gain_loss'] !== null): ?><div><span class="muted">Net gain/loss</span><strong><?= e(usd($tx['net_gain_loss'])) ?></strong></div><?php endif; ?>
                <?php if ($tx['federal_tax_withheld'] !== null): ?><div><span class="muted">Fed. tax withheld</span><strong><?= e(usd($tx['federal_tax_withheld'])) ?></strong></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_footer(); ?>
