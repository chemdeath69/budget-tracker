<?php
declare(strict_types=1);
/**
 * Debt payoff planner — PURE derive assembler (Session 63, TODO2 #33).
 *
 * No DB of its own: the page hands in the already-VIS-scoped debt accounts (q_debts — every
 * visible credit/loan account with its LEFT-JOINed Plaid `liabilities` detail). Like
 * lib/bills.php / lib/allocation.php.
 *
 * Models the two classic payoff strategies side by side:
 *   - SNOWBALL  — pay the smallest balance first (motivational quick wins).
 *   - AVALANCHE — pay the highest APR first (mathematically cheapest interest).
 * plus a MINIMUMS-ONLY baseline (the "do nothing extra" reference) so we can quote interest +
 * months saved. Each strategy with rollover applies a CONSTANT monthly pool (Σ all minimums +
 * extra): every active debt gets at least its minimum, the remainder sweeps onto the target debt
 * in strategy order, and a paid-off debt's minimum stays in the pool (the snowball effect).
 *
 * ⚠️ Honest-number stance: a debt with no reported APR is modeled at 0% (flagged — this UNDER-
 * states interest, so we never overstate the savings). A missing minimum falls back to the last
 * actual payment, else an estimate (flagged). Minimums are modeled as FIXED (a real card minimum
 * floats down with the balance); stated as a caveat on the page.
 */

const DEBT_MIN_EST_PCT   = 0.02;   // fallback minimum = 2% of balance …
const DEBT_MIN_EST_FLOOR = 25.0;   // … but at least $25
const DEBT_MAX_MONTHS    = 600;    // 50-year simulation cap (a runaway/negative-amortization guard)

/** A liability row is a mortgage (the toggle target) by its Plaid type or account subtype. */
function debt_is_mortgage(array $l): bool
{
    $lt  = strtolower(trim((string)($l['liability_type'] ?? '')));
    $sub = strtolower(trim((string)($l['subtype'] ?? '')));
    return $lt === 'mortgage' || $sub === 'mortgage';
}

/**
 * Normalize a q_debts() row into a debt the simulator understands, or null if it carries no
 * positive balance (a paid-off card shouldn't clutter the plan).
 */
function debt_normalize(array $l): ?array
{
    $type = strtolower(trim((string)($l['type'] ?? '')));

    // The LIVE account balance is what's owed now; the liabilities-product `outstanding_balance`
    // can be a stale last-statement figure (e.g. a card paid down since the statement), so prefer
    // balance_current and only fall back to outstanding_balance when it's missing.
    $bal = (float)($l['balance_current'] ?? 0);
    if ($bal <= 0) $bal = (float)($l['outstanding_balance'] ?? 0);
    $bal = abs($bal);
    if ($bal <= 0.005) return null;

    $aprRaw     = $l['apr_percentage'] ?? null;
    $aprUnknown = ($aprRaw === null || $aprRaw === '' || (float)$aprRaw < 0);
    $apr        = $aprUnknown ? 0.0 : (float)$aprRaw;

    // Minimum: the Plaid-reported minimum wins. Otherwise the fallback depends on the debt type:
    //  - installment LOAN / mortgage → the last actual payment (a real fixed monthly figure);
    //  - revolving CARD → a percentage-of-balance estimate (a card's last payment is often a
    //    full pay-off, which is a useless and wildly inflated "minimum").
    // Either fallback is flagged as not bank-reported.
    $min       = (float)($l['minimum_payment_amount'] ?? 0);
    $minSource = 'plaid';
    if ($min <= 0) {
        $lp = (float)($l['last_payment_amount'] ?? 0);
        if ($type === 'loan' && $lp > 0) {
            $min = $lp;
            $minSource = 'last_payment';
        } else {
            $min = max($bal * DEBT_MIN_EST_PCT, DEBT_MIN_EST_FLOOR);
            $minSource = 'estimated';
        }
    }

    return [
        'id'          => (string)($l['account_id'] ?? ''),
        'name'        => (string)($l['account_name'] ?? 'Debt'),
        'mask'        => (string)($l['mask'] ?? ''),
        'owner_id'    => $l['owner_id'] ?? null,
        'balance'     => $bal,
        'apr'         => $apr,
        'apr_unknown' => $aprUnknown,
        'min_payment' => $min,
        'min_source'  => $minSource,
        'is_mortgage' => debt_is_mortgage($l),
    ];
}

/**
 * Simulate paying down $debts month by month.
 *
 *   $byId     — [id => normalized debt] (so the comparator can read apr/balance).
 *   $cmp      — usort comparator over two debts → the payoff ORDER (target first).
 *   $extra    — extra $/month on top of the minimums.
 *   $rollover — true: constant pool (Σ all minimums + extra), minimum on each debt then sweep the
 *               rest onto the target in order, freed minimums stay in the pool. false: each debt
 *               pays only its own minimum (+ any $extra to the first target) — the baseline.
 *
 * @return array{months:int,total_interest:float,payoff:array<string,?int>,series:float[],
 *               infeasible:bool,order:string[]}
 */
function debt_simulate(array $debts, array $byId, callable $cmp, float $extra, bool $rollover): array
{
    $EPS = 0.005;
    $ids = array_map(fn($d) => $d['id'], $debts);
    usort($ids, fn($a, $b) => $cmp($byId[$a], $byId[$b]));

    $bal = $min = $apr = [];
    foreach ($debts as $d) {
        $bal[$d['id']] = $d['balance'];
        $min[$d['id']] = $d['min_payment'];
        $apr[$d['id']] = $d['apr'];
    }
    $pool = $extra;
    foreach ($debts as $d) $pool += $d['min_payment']; // constant rollover pool

    $payoff        = array_fill_keys($ids, null);
    $totalInterest = 0.0;
    $series        = [round(array_sum($bal), 2)]; // month 0 = starting total
    $month         = 0;

    // NB: read array_sum($bal) inline each iteration — an arrow fn would capture $bal by VALUE
    // at creation (PHP semantics) and never see the paydown.
    while (array_sum($bal) > $EPS && $month < DEBT_MAX_MONTHS) {
        $month++;

        // 1. accrue one month of interest on every active debt.
        foreach ($ids as $id) {
            if ($bal[$id] <= 0) continue;
            $i = $bal[$id] * $apr[$id] / 1200.0;
            $bal[$id]      += $i;
            $totalInterest += $i;
        }

        if ($rollover) {
            $budget = $pool;
            // 2a. minimum on each active debt first (nobody goes delinquent).
            foreach ($ids as $id) {
                if ($bal[$id] <= 0) continue;
                $p = min($min[$id], $bal[$id], $budget);
                $bal[$id] -= $p; $budget -= $p;
                if ($bal[$id] <= $EPS && $payoff[$id] === null) { $bal[$id] = 0; $payoff[$id] = $month; }
            }
            // 2b. sweep the remainder onto the target(s) in strategy order.
            foreach ($ids as $id) {
                if ($budget <= $EPS) break;
                if ($bal[$id] <= 0) continue;
                $p = min($bal[$id], $budget);
                $bal[$id] -= $p; $budget -= $p;
                if ($bal[$id] <= $EPS && $payoff[$id] === null) { $bal[$id] = 0; $payoff[$id] = $month; }
            }
        } else {
            // Baseline: each debt pays only its own minimum.
            foreach ($ids as $id) {
                if ($bal[$id] <= 0) continue;
                $p = min($min[$id], $bal[$id]);
                $bal[$id] -= $p;
                if ($bal[$id] <= $EPS && $payoff[$id] === null) { $bal[$id] = 0; $payoff[$id] = $month; }
            }
            // …with any extra applied to the first active target (kept for completeness; the
            // page's baseline always passes extra=0).
            if ($extra > $EPS) {
                $rem = $extra;
                foreach ($ids as $id) {
                    if ($rem <= $EPS) break;
                    if ($bal[$id] <= 0) continue;
                    $p = min($bal[$id], $rem);
                    $bal[$id] -= $p; $rem -= $p;
                    if ($bal[$id] <= $EPS && $payoff[$id] === null) { $bal[$id] = 0; $payoff[$id] = $month; }
                }
            }
        }

        $series[] = round(array_sum($bal), 2);
    }

    return [
        'months'         => $month,
        'total_interest' => round($totalInterest, 2),
        'payoff'         => $payoff,
        'series'         => $series,
        'infeasible'     => array_sum($bal) > $EPS, // hit the cap → minimums can't retire the debt
        'order'          => $ids,
    ];
}

/**
 * Build the whole debt-payoff view.
 *
 *   $debtRows        — q_debts() rows (every visible credit/loan account + its liabilities detail).
 *   $extra           — extra $/month the user wants to throw at debt (≥ 0).
 *   $includeMortgage — fold the mortgage into the plan (default off — it dwarfs everything).
 *
 * Returns: debts[] (in scope), total, has_mortgage, include_mortgage, extra, any_apr_unknown,
 *   any_min_estimated, and scenarios = {baseline, snowball, avalanche} each with
 *   months/total_interest/payoff/series/infeasible/order + (for the two strategies)
 *   interest_saved / months_saved vs the baseline.
 */
function build_debt_plan(array $debtRows, float $extra, bool $includeMortgage): array
{
    $extra = max(0.0, $extra);

    // Normalize + dedupe by account_id (a LEFT JOIN could double a row if an account ever carried
    // two liability_types; keep the first).
    $seen = [];
    $all  = [];
    foreach ($debtRows as $r) {
        $d = debt_normalize($r);
        if ($d === null || $d['id'] === '') continue;
        if (isset($seen[$d['id']])) continue;
        $seen[$d['id']] = true;
        $all[] = $d;
    }

    $hasMortgage = (bool)array_filter($all, fn($d) => $d['is_mortgage']);
    $debts = $includeMortgage
        ? $all
        : array_values(array_filter($all, fn($d) => !$d['is_mortgage']));

    $total = 0.0;
    foreach ($debts as $d) $total += $d['balance'];

    $out = [
        'debts'             => $debts,
        'total'             => $total,
        'has_mortgage'      => $hasMortgage,
        'include_mortgage'  => $includeMortgage,
        'extra'             => $extra,
        'any_apr_unknown'   => (bool)array_filter($debts, fn($d) => $d['apr_unknown']),
        'any_min_estimated' => (bool)array_filter($debts, fn($d) => $d['min_source'] !== 'plaid'),
        'scenarios'         => [],
    ];
    if (!$debts) return $out;

    $byId = [];
    foreach ($debts as $d) $byId[$d['id']] = $d;

    // Comparators (PHP array spaceship compares element-wise). Snowball: smallest balance first
    // (APR desc as a tiebreak). Avalanche: highest APR first (smallest balance as a tiebreak).
    $snowCmp = fn($a, $b) => [$a['balance'], -$a['apr']] <=> [$b['balance'], -$b['apr']];
    $avalCmp = fn($a, $b) => [-$a['apr'], $a['balance']] <=> [-$b['apr'], $b['balance']];

    $baseline  = debt_simulate($debts, $byId, $snowCmp, 0.0,    false); // order irrelevant (no sweep)
    $snowball  = debt_simulate($debts, $byId, $snowCmp, $extra, true);
    $avalanche = debt_simulate($debts, $byId, $avalCmp, $extra, true);

    // Savings vs the baseline (only meaningful when the baseline actually retires the debt).
    $withSaved = function (array $s) use ($baseline): array {
        $s['interest_saved'] = $baseline['infeasible'] ? null
            : max(0.0, round($baseline['total_interest'] - $s['total_interest'], 2));
        $s['months_saved']   = $baseline['infeasible'] ? null
            : max(0, $baseline['months'] - $s['months']);
        return $s;
    };
    $snowball  = $withSaved($snowball);
    $avalanche = $withSaved($avalanche);

    $out['scenarios'] = [
        'baseline'  => $baseline,
        'snowball'  => $snowball,
        'avalanche' => $avalanche,
    ];
    return $out;
}
