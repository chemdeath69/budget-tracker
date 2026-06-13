<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/returns.php';
require __DIR__ . '/lib/allocation.php';   // asset-class control (#32)
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

$securityId = (string)($_GET['security_id'] ?? '');

// Back arrow target — the holding rows that link here live on BOTH pages, so
// carry a whitelisted ?from to return the user where they came from.
$from    = (string)($_GET['from'] ?? '');
$backUrl = $from === 'retirement' ? '/retirement.php'
         : ($from === 'allocation' ? '/allocation.php' : '/investments.php');

// ── VIS gate ──────────────────────────────────────────────────────────────
// Only render if the viewer has visible exposure (a holding OR a buy/sell lot in
// an account they can see). A security held only in the other user's
// private/hidden account 404s here — it must not leak that it exists.
$hasAccess = $securityId !== '' && q_security_has_access($pdo, $uid, $securityId);

if (!$hasAccess) {
    render_header('Security', '', ['back' => $backUrl, 'narrow' => true]);
    ?>
    <div class="empty-state card">
        <h2>Security not found</h2>
        <p class="muted">This security isn't in your holdings, or you don't have access to it.</p>
        <a class="btn" href="<?= e($backUrl) ?>">← Back</a>
    </div>
    <?php
    render_footer();
    exit;
}

/* ---- Assemble ------------------------------------------------------------- */
$sec      = q_security($pdo, $securityId);
$holdings = q_security_holdings($pdo, $uid, $securityId);
$prices   = q_security_prices($pdo, $securityId);
$changes  = q_price_changes($pdo, [$securityId]);
$c        = $changes[$securityId] ?? null;
$divData  = q_security_dividends($pdo, [$securityId])[$securityId] ?? null;

// Asset class (#32): the auto default (from Plaid's security.type) + any household override.
$alDefault    = alloc_default_class($sec['type'] ?? null);
$alOverride   = q_security_asset_classes($pdo)[$securityId] ?? null;
$alIsOverride = alloc_valid_class($alOverride);

$lPage    = page_num('lpage');
$lotsRaw  = q_security_lots($pdo, $uid, $securityId, PAGE_SIZE + 1, page_offset($lPage));
$lotsNext = count($lotsRaw) > PAGE_SIZE;
$lots     = array_slice($lotsRaw, 0, PAGE_SIZE);

// Display identity (raw strings are escaped at render; the chart blob carries no
// names — only dates/closes — so no XSS surface there).
$ticker = $sec['ticker_symbol'] ?? null;
$name   = $sec['name'] ?? null;
$title  = $ticker ?: ($name ?: 'Security');

// Latest price + as-of date (security_prices is freshest; fall back to securities).
$curPrice  = $c['current'] ?? ($sec && $sec['close_price'] !== null ? (float)$sec['close_price'] : null);
$priceAsOf = $c['date'] ?? ($sec['close_price_date'] ?? null);

// Position aggregate across the viewer's visible holdings of this security.
// $costSum/$valCost drive the headline gain/loss and MUST include every cost-bearing
// lot (regardless of quantity) so the figure reconciles with investments.php's
// per-holding strips. Avg-cost/share uses a SEPARATE pair of accumulators that only
// count a lot when it has BOTH a basis and a quantity, so a (rare) basis-without-qty
// lot can't overstate the per-share average.
$totalQty = 0.0; $mvAll = 0.0; $costSum = 0.0; $valCost = 0.0; $haveCost = 0;
$avgBasis = 0.0; $avgQty = 0.0;
foreach ($holdings as $h) {
    $qty = $h['quantity'] !== null ? (float)$h['quantity'] : 0.0;
    $totalQty += $qty;
    $mv = $h['institution_value'] !== null ? (float)$h['institution_value'] : 0.0;
    $mvAll += $mv;
    if ($h['cost_basis'] !== null) {
        $haveCost++;
        $costSum += (float)$h['cost_basis'];
        $valCost += $mv;   // market value of cost-bearing lots, so gain% lines up
        if ($h['quantity'] !== null && $qty != 0.0) {
            $avgBasis += (float)$h['cost_basis'];
            $avgQty   += $qty;
        }
    }
}
// Gain/loss over the cost-bearing portion (mirrors investments.php so it reconciles).
$gain    = $haveCost ? $valCost - $costSum : null;
$gainPct = ($haveCost && $costSum != 0.0) ? $gain / abs($costSum) * 100 : null;
$avgCost = ($avgQty > 0) ? $avgBasis / $avgQty : null;
$partial = $haveCost > 0 && $haveCost < count($holdings);

// Projected annual dividend income for the viewer's share count.
$divAnnual   = ($divData && $divData['annual_ps'] !== null && $totalQty > 0)
    ? $totalQty * $divData['annual_ps'] : null;
$divYield    = ($divAnnual !== null && $mvAll > 0) ? $divAnnual / $mvAll * 100 : null;
$divUpcoming = $divData['upcoming'] ?? [];

/* ---- Money-weighted return + benchmark (#29) ----------------------------- */
// Your annualized IRR for THIS security (all visible buy/sell lots + the current
// market value), compared to a user-picked index we already price (default SPY).
// Shown only when the lot history reconciles with the held share count.
$retLots    = q_security_lots($pdo, $uid, $securityId, 100000, 0);
$benchCands = []; $bench = null;
if ($retLots) {
    $benchCands = q_benchmark_candidates($pdo);
    $benchSel   = strtoupper(trim((string)($_GET['bench'] ?? '')));
    $benchPick  = null;
    foreach ($benchCands as $bc) { if (strtoupper((string)$bc['ticker_symbol']) === $benchSel) { $benchPick = $bc; break; } }
    if ($benchPick === null && $benchCands) {
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
}
$secRet    = ret_position($retLots, $totalQty, $mvAll, $bench, date('Y-m-d'));
$hasSecRet = $secRet['irr'] !== null;

$HORIZONS = ['d1' => 'Today', 'd7' => '1 wk', 'd30' => '1 mo', 'd365' => '1 yr'];

/** Per-share % change over $key (e.g. 'd30'), or null. */
function sec_chg(?array $c, string $k): ?float
{
    if (!$c || $c['current'] === null || ($c[$k] ?? null) === null || (float)$c[$k] == 0.0) return null;
    return ($c['current'] - $c[$k]) / abs($c[$k]) * 100;
}

/** Friendly label for a Polygon payout frequency (payouts/yr). */
function sec_freq_label(?int $f): string
{
    return match ((int)$f) {
        1  => 'annual', 2 => 'semi-annual', 4 => 'quarterly',
        12 => 'monthly', 24 => 'bi-monthly', 52 => 'weekly',
        default => 'periodic',
    };
}

render_header($title, '', ['back' => $backUrl, 'narrow' => true, 'chart' => true]);
?>

<!-- Identity + latest price -->
<section class="card hero">
    <div class="hero-top">
        <span class="hero-label"><?= e($ticker ? ($name ?: $ticker) : ($name ?: $title)) ?></span>
        <?php $dc = sec_chg($c, 'd1'); if ($dc !== null): $up = $dc >= 0; ?>
            <span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= number_format(abs($dc), 2) ?>%<span class="delta-sub">today</span></span>
        <?php endif; ?>
    </div>
    <div class="hero-value"><?= $curPrice !== null ? e(usd($curPrice)) : '—' ?></div>
    <div class="muted">
        <?php if ($ticker && $name): ?><?= e($ticker) ?> · <?php endif; ?>
        <?php if ($priceAsOf): ?>close as of <?= e($priceAsOf) ?><?php else: ?>no price data yet<?php endif; ?>
    </div>
    <?php
    $strip = array_filter(array_map(fn($k) => [$k, sec_chg($c, $k)], array_keys($HORIZONS)), fn($p) => $p[1] !== null);
    if ($strip): ?>
    <div class="chg-strip" style="margin-top:.6rem">
        <?php foreach ($HORIZONS as $k => $lbl): $p = sec_chg($c, $k); if ($p === null) continue; $up = $p >= 0; ?>
        <span class="chg <?= $up ? 'up' : 'down' ?>"><span class="chg-l"><?= e($lbl) ?></span> <?= $up ? '▲' : '▼' ?><?= e(number_format(abs($p), 2)) ?>%</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- Price history -->
<?php if (count($prices) > 1): ?>
<section class="block">
    <div class="block-head"><h2>Price history</h2><span class="muted">daily close</span></div>
    <div class="card">
        <div class="chart-wrap tall">
            <canvas id="sec-price-chart" data-chart="line" data-src="sec-price-data"></canvas>
            <script type="application/json" id="sec-price-data"><?= json_encode([
                'labels' => array_column($prices, 'price_date'),
                'values' => array_map('floatval', array_column($prices, 'close')),
            ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Your position -->
<?php if ($holdings): ?>
<section class="block">
    <div class="block-head"><h2>Your position</h2><span class="muted"><?= count($holdings) > 1 ? count($holdings) . ' accounts' : '' ?></span></div>
    <section class="card hero">
        <div class="hero-top">
            <span class="hero-label">Market value</span>
            <?php if ($gainPct !== null): $up = $gain >= 0; ?>
                <span class="delta <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= number_format(abs($gainPct), 1) ?>%<span class="delta-sub">total</span></span>
            <?php endif; ?>
        </div>
        <div class="hero-value"><?= e(usd($mvAll)) ?></div>
        <?php if ($gain !== null): ?>
            <div class="muted"><?= ($gain >= 0 ? '+' : '−') . e(usd(abs($gain))) ?> unrealized gain/loss<?php if ($partial): ?> · <span title="Some lots have no cost basis yet">excludes lots without cost basis</span><?php endif; ?></div>
        <?php else: ?>
            <div class="muted">Cost basis isn't available yet, so gain/loss can't be shown.</div>
        <?php endif; ?>
        <div class="kv-grid" style="margin-top:1rem">
            <div><span class="muted">Shares</span><strong><?= e(number_format($totalQty, 4)) ?></strong></div>
            <?php if ($avgCost !== null): ?><div><span class="muted">Avg cost / share</span><strong><?= e(usd($avgCost)) ?></strong></div><?php endif; ?>
            <?php if ($curPrice !== null): ?><div><span class="muted">Current price</span><strong><?= e(usd($curPrice)) ?></strong></div><?php endif; ?>
            <?php if ($haveCost): ?><div><span class="muted">Cost basis</span><strong><?= e(usd($costSum)) ?></strong></div><?php endif; ?>
        </div>
    </section>

    <?php if (count($holdings) > 1): ?>
    <div class="rows card" style="margin-top:1rem">
        <?php foreach ($holdings as $h):
            $val   = $h['institution_value'] !== null ? (float)$h['institution_value'] : null;
            $cb    = $h['cost_basis'] !== null ? (float)$h['cost_basis'] : null;
            $hgain = ($val !== null && $cb !== null) ? $val - $cb : null;
            $hpct  = ($hgain !== null && $cb != 0.0) ? round($hgain / abs($cb) * 100, 1) : null;
            $isRet = is_retirement_account($h); ?>
        <div class="row">
            <span class="row-main">
                <span class="row-title"><?= e($h['account_name'] ?: 'Account') ?><?= $h['mask'] ? ' <span class="muted">••' . e($h['mask']) . '</span>' : '' ?><?= owner_suffix($h['owner_id'] ?? null) ?><?= $isRet ? ' <span class="mini-tag">retirement</span>' : '' ?></span>
                <span class="row-sub">
                    <?php if ($h['quantity'] !== null): ?><?= e(number_format((float)$h['quantity'], 4)) ?> @ <?= e(usd($h['institution_price'])) ?><?php endif; ?>
                    <?php if ($cb !== null): ?> · cost <?= e(usd($cb)) ?><?php endif; ?>
                </span>
            </span>
            <span class="row-side">
                <span class="row-amt"><?= $val !== null ? e(usd($val)) : '—' ?></span>
                <?php if ($hgain !== null): ?>
                    <span class="delta <?= $hgain >= 0 ? 'up' : 'down' ?>"><?= $hgain >= 0 ? '▲' : '▼' ?> <?= ($hgain >= 0 ? '+' : '−') . e(usd(abs($hgain))) ?><?php if ($hpct !== null): ?> (<?= e(number_format(abs($hpct), 1)) ?>%)<?php endif; ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Asset class (#32) — drives the Allocation page's mix. -->
<section class="block">
    <div class="block-head"><h2>Asset class</h2></div>
    <div class="card">
        <p class="muted" style="margin:0 0 10px">Used by the <a href="/allocation.php">Allocation</a> page.
            Plaid groups all ETFs/funds together, so set the right class for a bond, REIT or crypto fund.
            Applies everywhere the household sees it.</p>
        <select class="select class-select" data-security="<?= e($securityId) ?>" aria-label="Asset class">
            <option value="auto"<?= $alIsOverride ? '' : ' selected' ?>>Auto · <?= e(alloc_class_label($alDefault)) ?></option>
            <?php foreach (ALLOC_CLASSES as $ck => $cl): ?>
                <option value="<?= e($ck) ?>"<?= ($alIsOverride && $alOverride === $ck) ? ' selected' : '' ?>><?= e($cl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</section>

<!-- Return vs benchmark (#29) -->
<?php if ($retLots): ?>
<section class="block">
    <div class="block-head">
        <h2>Return vs benchmark</h2>
        <?php if ($hasSecRet && count($benchCands) > 1): ?>
        <form method="get" class="head-form">
            <input type="hidden" name="security_id" value="<?= e($securityId) ?>">
            <?php if ($from !== ''): ?><input type="hidden" name="from" value="<?= e($from) ?>"><?php endif; ?>
            <select name="bench" class="select" data-autosubmit aria-label="Benchmark index">
                <?php foreach ($benchCands as $bc): $tk = strtoupper((string)$bc['ticker_symbol']); ?>
                    <option value="<?= e($tk) ?>"<?= ($bench && $bench['ticker'] === $tk) ? ' selected' : '' ?>><?= e($tk) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($hasSecRet):
        $up     = $secRet['irr'] >= 0;
        $bTk    = $bench['ticker'] ?? null;
        $bIrr   = $secRet['bench_irr'];
        $spread = ($bIrr !== null) ? $secRet['irr'] - $bIrr : null; ?>
    <section class="card hero">
        <div class="hero-top">
            <span class="hero-label">Your annualized return</span>
            <?php if ($spread !== null): $beat = $spread >= 0; ?>
                <span class="delta <?= $beat ? 'up' : 'down' ?>"><?= $beat ? '▲' : '▼' ?> <?= e(number_format(abs($spread) * 100, 1)) ?> pp<span class="delta-sub">vs <?= e($bTk) ?></span></span>
            <?php endif; ?>
        </div>
        <div class="hero-value <?= $up ? '' : 'neg' ?>" style="font-size:2rem"><?= e(ret_pct($secRet['irr'])) ?></div>
        <div class="hero-split tri">
            <div class="split-cell"><span class="split-label">You</span><span class="split-value <?= $up ? 'pos' : 'neg' ?>"><?= e(ret_pct($secRet['irr'])) ?></span></div>
            <div class="split-cell"><span class="split-label"><?= $bTk ? e($bTk) : 'Index' ?></span><span class="split-value"><?= $bIrr !== null ? e(ret_pct($bIrr)) : '—' ?></span></div>
            <div class="split-cell"><span class="split-label">Difference</span><span class="split-value <?= $spread === null ? '' : ($spread >= 0 ? 'pos' : 'neg') ?>"><?= $spread === null ? '—' : e(($spread >= 0 ? '+' : '−') . number_format(abs($spread) * 100, 1) . ' pp') ?></span></div>
        </div>
        <p class="muted ret-note" style="margin-top:.9rem">
            Money-weighted (annualized IRR)<?php if ($secRet['start']): ?> since <?= e(date('M j, Y', strtotime($secRet['start']))) ?><?php endif; ?> · invested <?= e(usd($secRet['invested'])) ?> · now worth <?= e(usd($secRet['mkt_value'])) ?>.
            <?php if ($bench && $bIrr !== null): ?> <?= e($bTk) ?> = the same contributions placed in <?= e($bTk) ?> over the same dates.<?php elseif ($bench && $bIrr === null): ?> <?= e($bTk) ?> comparison unavailable for this window.<?php endif; ?>
        </p>
    </section>
    <?php else: ?>
    <div class="card"><p class="muted">Not enough buy/sell history to compute a reliable return — your recorded lots don't reconcile with the current share count. It appears once the full lot history is on file.</p></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Dividends -->
<?php if ($divData && ($divAnnual !== null || $divUpcoming)): ?>
<section class="block">
    <div class="block-head">
        <h2>Dividends</h2>
        <?php if ($divAnnual !== null): ?><span class="split-value pos"><?= e(usd($divAnnual)) ?>/yr</span><?php endif; ?>
    </div>
    <div class="card">
        <?php if ($divData['latest']): $lt = $divData['latest']; ?>
        <p class="muted">
            Latest declared <?= e(usd($lt['cash_amount'])) ?>/share<?= $lt['frequency'] ? ' · ' . e(sec_freq_label($lt['frequency'])) : '' ?><?= $lt['ex_date'] ? ' · ex ' . e($lt['ex_date']) : '' ?>.
            <?php if ($divAnnual !== null): ?>Projected for your <?= e(number_format($totalQty, 4)) ?> shares<?= $divYield !== null ? ' · ≈' . e(number_format($divYield, 2)) . '% yield' : '' ?>. Estimate — assumes the latest rate &amp; cadence continue.<?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if ($divUpcoming): ?>
        <h3 class="div-cal-head">Upcoming ex-dividend dates</h3>
        <div class="rows">
            <?php foreach ($divUpcoming as $u): ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title"><?= e(date('D, M j, Y', strtotime($u['ex_date']))) ?></span>
                    <span class="row-sub"><?= e(usd($u['cash_amount'])) ?>/sh<?= $u['pay_date'] ? ' · pays ' . e(date('M j', strtotime($u['pay_date']))) : '' ?></span>
                </span>
                <span class="row-side">
                    <span class="row-amt"><?= $totalQty > 0 ? e(usd($totalQty * $u['cash_amount'])) : '—' ?></span>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- Lot ledger -->
<?php if ($lots || $lPage > 1): ?>
<section class="block">
    <div class="block-head"><h2>Lot ledger</h2><span class="muted">buys &amp; sells</span></div>
    <?php if ($lots): ?>
    <div class="rows card">
        <?php foreach ($lots as $l):
            $isBuy = $l['side'] === 'buy';
            $amt   = $l['amount'] !== null ? (float)$l['amount'] : null;
            $fees  = (float)($l['fees'] ?? 0); ?>
        <div class="row">
            <span class="row-main">
                <span class="row-title"><?= $isBuy ? 'Buy' : 'Sell' ?> <span class="muted"><?= e($l['account_name'] ?: 'Account') ?></span><?= owner_suffix($l['owner_id'] ?? null) ?></span>
                <span class="row-sub">
                    <?= e($l['trade_date']) ?> · <?= e(number_format((float)$l['quantity'], 4)) ?> @ <?= e(usd($l['price'])) ?><?php if ($fees != 0.0): ?> · fee <?= e(usd($fees)) ?><?php endif; ?>
                </span>
            </span>
            <span class="row-side">
                <?php if ($amt !== null): // stored sign: + = money out (buy), − = money in (sell) ?>
                    <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= ($amt < 0 ? '+' : '−') . e(usd(abs($amt))) ?></span>
                <?php else: ?>
                    <span class="row-amt">—</span>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card"><p class="muted">No lots on this page.</p></div>
    <?php endif; ?>
    <?php render_pager($lPage, $lotsNext, ['security_id' => $securityId, 'from' => $from], 'lpage'); ?>
</section>
<?php endif; ?>

<?php render_footer(); ?>
