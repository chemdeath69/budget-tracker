<?php
declare(strict_types=1);

/**
 * Investment return rate + benchmark (#29) — PURE math + assembler, no DB of its
 * own (the page passes in already-VIS-scoped q_holdings / q_investment_lots /
 * benchmark price rows), mirroring lib/bills.php. Computes a **money-weighted
 * annualized return (IRR)** from recorded buy/sell lots + the current market
 * value, and a **dollar-matched benchmark** ("what if the same money, at the same
 * times, had gone into SPY") for an apples-to-apples comparison.
 *
 * ⚠️ HONEST-NUMBER GATE (the S34/S58 lesson): a return is only shown when the lot
 * history is COMPLETE — i.e. the net shares the lots reconstruct (Σ buy qty − Σ
 * sell qty) reconcile with the currently-held quantity. If a buy/sell is missing
 * (an un-uploaded statement, a pre-window Plaid gap), the IRR would be wrong, so
 * we suppress it rather than show a misleading figure.
 *
 * Sign convention everywhere here is the INVESTOR's: a cash flow is NEGATIVE when
 * money leaves your pocket (a buy / contribution) and POSITIVE when it comes back
 * (a sell / the terminal market value). investment_transactions.amount uses the
 * opposite "+ = money out (buy), − = money in (sell)" convention, so investor
 * CF = −amount. Dividends are NOT counted as separate flows — they're reflected
 * in the holdings' value (reinvested) and excluding them keeps the portfolio-vs-
 * benchmark comparison fair (we have price, not total-return, for the benchmark).
 */

const RET_MIN_SPAN_DAYS = 45;     // below this, annualizing a return is unreliable → suppress
const RET_RECONCILE_FRAC = 0.02;  // lot-vs-held share tolerance (or RET_RECONCILE_MIN, whichever larger)
const RET_RECONCILE_MIN  = 0.5;

/** The net cash effect of one lot, investor perspective (+ = money received, − = money invested). */
function ret_lot_cashflow(array $l): ?float
{
    if (isset($l['amount']) && $l['amount'] !== null) {
        return -(float)$l['amount'];   // stored + = out (buy) → investor −; stored − = in (sell) → investor +
    }
    // Fallback when amount is absent: derive from qty × price ± fees by side.
    if (!isset($l['side']) || !isset($l['quantity']) || !isset($l['price'])) return null;
    $gross = (float)$l['quantity'] * (float)$l['price'];
    $fee   = (float)($l['fees'] ?? 0);
    $out   = $l['side'] === 'buy' ? ($gross + $fee) : -($gross - $fee);   // + out / − in
    return -$out;
}

/** Money INVESTED across the lots (Σ of the buy outflows, as a positive number). */
function ret_invested(array $lots): float
{
    $sum = 0.0;
    foreach ($lots as $l) { $cf = ret_lot_cashflow($l); if ($cf !== null && $cf < 0) $sum += -$cf; }
    return $sum;
}

/**
 * Investor cash flows for a position: one entry per lot (CF = −amount) plus a
 * terminal POSITIVE flow = the current market value at $asOf. Returns
 * [['date'=>'YYYY-MM-DD','amount'=>float], …].
 */
function ret_flows(array $lots, float $marketValue, string $asOf): array
{
    $flows = [];
    foreach ($lots as $l) {
        $cf = ret_lot_cashflow($l);
        if ($cf === null) continue;
        $flows[] = ['date' => (string)$l['trade_date'], 'amount' => $cf];
    }
    if (abs($marketValue) > 0.0049) $flows[] = ['date' => $asOf, 'amount' => $marketValue];
    return $flows;
}

/**
 * Does the lot history reconstruct the currently-held share count? Σ(buy − sell)
 * qty must equal $heldQty within tolerance, else a lot is missing and any return
 * over it is untrustworthy. A fully-sold position (held 0) reconciles when the
 * lots net to ~0.
 */
function ret_reconcile(array $lots, float $heldQty): bool
{
    if (!$lots) return false;            // no lots ⇒ no basis to compute a return
    $net = 0.0;
    foreach ($lots as $l) {
        $q = (float)($l['quantity'] ?? 0);
        $net += ($l['side'] === 'buy') ? $q : -$q;
    }
    $tol = max(RET_RECONCILE_MIN, abs($heldQty) * RET_RECONCILE_FRAC);
    return abs($net - $heldQty) <= $tol;
}

/**
 * Annualized money-weighted internal rate of return (IRR) for dated investor cash
 * flows. Solves Σ cf_i × (1+r)^(years_before_asOf_i) = 0 for r by bisection.
 * Returns the annual rate (0.082 = 8.2%/yr), or null when there's no positive AND
 * negative flow, the span is shorter than RET_MIN_SPAN_DAYS, or no root brackets.
 */
function ret_irr(array $flows, string $asOf): ?float
{
    $asOfTs = strtotime($asOf);
    if ($asOfTs === false) return null;

    $rows = []; $hasPos = false; $hasNeg = false; $minTs = null;
    foreach ($flows as $f) {
        $amt = (float)$f['amount'];
        if ($amt == 0.0) continue;
        $ts = strtotime((string)$f['date']);
        if ($ts === false) continue;
        if ($ts > $asOfTs) $ts = $asOfTs;                       // a future-dated lot → treat as today
        $yrs = ($asOfTs - $ts) / (365.25 * 86400.0);
        $rows[] = [$yrs, $amt];
        if ($amt > 0) $hasPos = true; else $hasNeg = true;
        $minTs = ($minTs === null) ? $ts : min($minTs, $ts);
    }
    if (!$hasPos || !$hasNeg || $minTs === null) return null;
    if (($asOfTs - $minTs) / 86400.0 < RET_MIN_SPAN_DAYS) return null;

    $npv = function (float $r) use ($rows): float {
        $base = 1.0 + $r;
        if ($base < 1e-9) $base = 1e-9;            // guard r ≤ −100%
        $s = 0.0;
        foreach ($rows as [$yrs, $amt]) $s += $amt * ($yrs == 0.0 ? 1.0 : pow($base, $yrs));
        return $s;
    };

    // Normal shape: early buys (negative, large exponent) make NPV → −∞ as r→∞, and
    // the terminal value (exponent 0) makes NPV → +terminal as r→−1, so NPV crosses
    // zero once. Bracket [lo, hi] then bisect.
    $lo = -0.999; $hi = 1.0;
    $flo = $npv($lo); $fhi = $npv($hi);
    $tries = 0;
    while ($flo * $fhi > 0 && $hi < 1e7 && $tries < 60) { $hi *= 1.8; $fhi = $npv($hi); $tries++; }
    if ($flo * $fhi > 0) return null;               // no bracketed root in a plausible range

    for ($i = 0; $i < 200; $i++) {
        $mid = ($lo + $hi) / 2.0;
        $fm  = $npv($mid);
        if (abs($fm) < 1e-6 || ($hi - $lo) < 1e-10) return $mid;
        if ($flo * $fm <= 0) { $hi = $mid; }
        else { $lo = $mid; $flo = $fm; }
    }
    return ($lo + $hi) / 2.0;
}

/**
 * Build a "benchmark close on/before a date" lookup from an ASC [['date','close'],…]
 * series. Returns [callable $priceAsOf(string $date): ?float, float $latest,
 * ?string $earliest] — $priceAsOf returns null when the date predates coverage.
 */
function ret_bench_lookup(array $priceRows): array
{
    $pts = [];
    foreach ($priceRows as $r) {
        $d = (string)($r['price_date'] ?? $r['date'] ?? '');
        $c = $r['close'] ?? null;
        if ($d === '' || $c === null) continue;
        $pts[] = [$d, (float)$c];
    }
    usort($pts, fn($a, $b) => strcmp($a[0], $b[0]));
    $earliest = $pts ? $pts[0][0] : null;
    $latest   = $pts ? $pts[count($pts) - 1][1] : 0.0;
    $asOf = function (string $date) use ($pts): ?float {
        $close = null;
        foreach ($pts as [$d, $c]) { if ($d <= $date) $close = $c; else break; }
        return $close;
    };
    return [$asOf, $latest, $earliest];
}

/**
 * Dollar-matched benchmark terminal value: replay the SAME buys/sells into the
 * benchmark and value the resulting share count at the latest close. A buy invests
 * |amount| at that day's close (adds shares); a sell withdraws |amount| (removes
 * shares, floored at 0). Returns null if ANY lot date predates benchmark coverage
 * (so we never compare against a gappy benchmark).
 *
 * ⚠️ ORDER-SENSITIVE: a sell replayed before its buys hits the max(0,…) floor and
 * silently loses its withdrawal, overstating the terminal value. Callers pass lots
 * in whatever order the query returned (security.php's q_security_lots is DESC), so
 * we sort a COPY by trade_date ASC here — chronological is the only correct replay.
 */
function ret_benchmark_value(array $lots, callable $priceAsOf, float $latest): ?float
{
    usort($lots, fn($a, $b) => strcmp((string)$a['trade_date'], (string)$b['trade_date']));
    $shares = 0.0;
    foreach ($lots as $l) {
        $cf = ret_lot_cashflow($l);                 // investor CF: − = buy, + = sell
        if ($cf === null) continue;
        $px = $priceAsOf((string)$l['trade_date']);
        if ($px === null || $px <= 0) return null;  // outside coverage → unfair compare
        if ($cf < 0)       $shares += (-$cf) / $px;            // buy: invest |cf|
        else               $shares = max(0.0, $shares - $cf / $px);  // sell: withdraw
    }
    return $shares * $latest;
}

/**
 * One position's return result, given its lots, current held qty, current market
 * value, an optional benchmark, and $asOf. $bench (or null) = ['asof'=>callable,
 * 'latest'=>float, 'ticker'=>str]. Returns a self-describing array; 'irr' is null
 * when the lot history doesn't reconcile or the span is too short.
 */
function ret_position(array $lots, float $heldQty, float $marketValue, ?array $bench, string $asOf): array
{
    $reconciled = ret_reconcile($lots, $heldQty);
    $invested   = ret_invested($lots);
    $start = null;
    foreach ($lots as $l) { $d = (string)$l['trade_date']; if ($start === null || $d < $start) $start = $d; }

    $irr = $reconciled ? ret_irr(ret_flows($lots, $marketValue, $asOf), $asOf) : null;

    $benchIrr = null; $benchVal = null;
    if ($irr !== null && $bench !== null) {
        $benchVal = ret_benchmark_value($lots, $bench['asof'], $bench['latest']);
        if ($benchVal !== null) $benchIrr = ret_irr(ret_flows($lots, $benchVal, $asOf), $asOf);
    }

    $spanDays = $start !== null ? (int)round((strtotime($asOf) - strtotime($start)) / 86400.0) : 0;
    return [
        'reconciled'  => $reconciled,
        'irr'         => $irr,
        'bench_irr'   => $benchIrr,
        'bench_value' => $benchVal,
        'invested'    => $invested,
        'mkt_value'   => $marketValue,
        'start'       => $start,
        'span_days'   => $spanDays,
    ];
}

/**
 * Whole-portfolio + per-account returns for the Investments page. $holds /
 * $lots are already VIS-scoped + scoped to the page's accounts (non-retirement).
 * $bench (or null) is the chosen benchmark (['asof','latest','ticker','name']).
 *
 * Groups by security to apply the per-security reconcile gate, then combines every
 * RECONCILING security's lots + terminal value into one cash-flow stream for the
 * portfolio IRR (money-weighted across the whole book) and the same for each
 * account. Securities that don't reconcile (missing lots, or a Plaid holding with
 * no lot history) are EXCLUDED and reported so the figure stays honest.
 */
function build_investment_returns(array $holds, array $lots, ?array $bench, string $asOf): array
{
    // Held qty + market value per security, and per (account, security).
    $heldQty = []; $mktVal = []; $acctName = [];
    $heldQtyA = []; $mktValA = [];
    foreach ($holds as $h) {
        $sid = $h['security_id'] ?? null; if ($sid === null) continue;
        $aid = $h['account_id'];
        $q = $h['quantity'] !== null ? (float)$h['quantity'] : 0.0;
        $v = $h['institution_value'] !== null ? (float)$h['institution_value'] : 0.0;
        $heldQty[$sid] = ($heldQty[$sid] ?? 0.0) + $q;
        $mktVal[$sid]  = ($mktVal[$sid] ?? 0.0) + $v;
        $heldQtyA[$aid][$sid] = ($heldQtyA[$aid][$sid] ?? 0.0) + $q;
        $mktValA[$aid][$sid]  = ($mktValA[$aid][$sid] ?? 0.0) + $v;
        $acctName[$aid] = $h['account_name'] ?? ($h['name'] ?? 'Account');
    }
    // Lots per security, and per (account, security).
    $lotsBySec = []; $lotsByAcctSec = [];
    foreach ($lots as $l) {
        $sid = $l['security_id']; $aid = $l['account_id'];
        $lotsBySec[$sid][] = $l;
        $lotsByAcctSec[$aid][$sid][] = $l;
        if (!isset($acctName[$aid])) $acctName[$aid] = $l['account_name'] ?? 'Account';
    }

    // One pass that aggregates the reconciling securities into a scope's flows.
    $aggregate = function (array $secIds, array $lotsMap, array $heldMap, array $valMap) use ($bench, $asOf): array {
        $inclLots = []; $terminal = 0.0; $invested = 0.0; $start = null;
        $exclCount = 0; $exclValue = 0.0;
        foreach ($secIds as $sid) {
            $sl  = $lotsMap[$sid] ?? [];
            $hq  = $heldMap[$sid] ?? 0.0;
            $mv  = $valMap[$sid]  ?? 0.0;
            if ($sl && ret_reconcile($sl, $hq)) {
                foreach ($sl as $l) { $inclLots[] = $l; $d = (string)$l['trade_date']; if ($start === null || $d < $start) $start = $d; }
                $terminal += $mv;
                $invested += ret_invested($sl);
            } elseif (abs($mv) > 0.0049) {
                $exclCount++; $exclValue += $mv;
            }
        }
        $irr = $inclLots ? ret_irr(ret_flows($inclLots, $terminal, $asOf), $asOf) : null;
        $benchIrr = null; $benchVal = null;
        if ($irr !== null && $bench !== null) {
            $benchVal = ret_benchmark_value($inclLots, $bench['asof'], $bench['latest']);
            if ($benchVal !== null) $benchIrr = ret_irr(ret_flows($inclLots, $benchVal, $asOf), $asOf);
        }
        return [
            'irr'         => $irr,
            'bench_irr'   => $benchIrr,
            'bench_value' => $benchVal,
            'invested'    => $invested,
            'mkt_value'   => $terminal,
            'start'       => $start,
            'span_days'   => $start !== null ? (int)round((strtotime($asOf) - strtotime($start)) / 86400.0) : 0,
            'excl_count'  => $exclCount,
            'excl_value'  => $exclValue,
        ];
    };

    $allSecIds = array_keys($heldQty + $lotsBySec);
    $portfolio = $aggregate($allSecIds, $lotsBySec, $heldQty, $mktVal);

    $accounts = [];
    foreach ($acctName as $aid => $nm) {
        $secIds = array_keys(($heldQtyA[$aid] ?? []) + ($lotsByAcctSec[$aid] ?? []));
        if (!$secIds) continue;
        $r = $aggregate($secIds, $lotsByAcctSec[$aid] ?? [], $heldQtyA[$aid] ?? [], $mktValA[$aid] ?? []);
        if ($r['irr'] === null) continue;   // only list accounts with a computable return
        $r['account_id'] = $aid;
        $r['account_name'] = $nm;
        $accounts[] = $r;
    }
    usort($accounts, fn($a, $b) => $b['mkt_value'] <=> $a['mkt_value']);

    return ['portfolio' => $portfolio, 'accounts' => $accounts, 'bench' => $bench, 'as_of' => $asOf];
}

/** Format an annual rate (0.082 → "+8.2%"); clamps absurd magnitudes for display. */
function ret_pct(?float $r, int $dp = 1): string
{
    if ($r === null) return '—';
    $p = $r * 100.0;
    if ($p > 9999) return '> +9999%';
    if ($p < -99.9) return '−100%';
    return ($p >= 0 ? '+' : '−') . number_format(abs($p), $dp) . '%';
}
