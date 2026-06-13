<?php
/**
 * lib/forecast.php — Forward-looking cash-flow forecast (TODO2 #30).
 *
 * Pure derive: NO database access of its own. The page fetches the VIS-scoped reads
 * (q_accounts / q_liabilities / q_recurring) + q_avg_daily_spend() and hands them in.
 * We project a day-by-day running CASH balance over the next 30–90 days so the page can
 * answer "you'll dip to $1,420 around the 28th before payday."
 *
 * Reuses lib/bills.php's occurrence machinery — bill_occurrences() for the scheduled
 * outflow events, and the same anchored / day-clamped / stale-guarded projector for the
 * recurring INCOME stream (forecast_income_occurrences()).
 *
 * The model — four populations, deliberately NON-OVERLAPPING so nothing double-counts:
 *   • Start balance = today's spendable cash (Checking + Savings accounts).
 *   • Recurring INCOME events  (+) — projected paydays/deposits from inflow streams.
 *   • Scheduled bill events    (−) — bill_occurrences(): liability due dates +
 *                                    projected recurring (subscription) outflows.
 *   • Flat discretionary spend (−) — average daily true-expense MINUS the part already
 *                                    represented by the recurring (subscription) bill
 *                                    events, spread evenly. So the everyday burn is
 *                                    smooth while the lumpy bills (rent on the 1st) keep
 *                                    their shape — which is what creates the dip the
 *                                    feature surfaces — and the total projected spend over
 *                                    the horizon still ≈ your historical average. (Exception:
 *                                    if the detected recurring bills alone already exceed that
 *                                    average, the discretionary baseline floors at $0 and the
 *                                    bills win, so total spend > average — conservative: it
 *                                    only deepens/earlier-dates the dip, never under-warns.)
 *
 * ⚠️ Why only the 'recurring' bill events feed the discretionary subtraction, not the
 * 'liability' ones: a recurring subscription is real card/checking spend and IS counted
 * in q_avg_daily_spend, so leaving it in BOTH the events and the average would double it.
 * A liability payment (mortgage/loan/CC minimum) is a debt payment that q_cashflow's
 * true-expense definition excludes (so it's NOT in the average) — it's a genuine cash
 * outflow added on top.
 *
 * Honest about its limits (the page states them): it only sees the RECURRING income Plaid
 * has detected — irregular income isn't projected (conservative: understates inflows) — and
 * a bill with an unknown amount can't move the projected balance.
 *
 * Mirrors the assembler pattern of lib/bills.php / lib/property_view.php.
 */

require_once __DIR__ . '/bills.php';

/**
 * account_group() buckets treated as spendable cash for the forecast. NB account_group()
 * returns the lowercase ACCOUNT_GROUPS *keys* ('checking'/'savings'), not the display labels.
 */
const FORECAST_CASH_GROUPS = ['checking', 'savings'];

/**
 * Recurring INFLOW categories to EXCLUDE from projected income — the income-side mirror of
 * bill_occurrences()'s BILL_RECUR_EXCLUDE_CATS. An internal transfer between the user's OWN
 * accounts (TRANSFER_IN/OUT) is already reflected in today's start balance, so projecting it
 * as fresh income would DOUBLE-count it; a loan disbursement/payment isn't recurring income
 * either. ⚠️ Unlike the outflow side, 'INCOME' is deliberately NOT excluded here — on the
 * inflow side an INCOME-tagged stream (a paycheck/deposit) is exactly what we want to project.
 */
const FORECAST_INCOME_EXCLUDE_CATS = ['TRANSFER_IN', 'TRANSFER_OUT', 'LOAN_PAYMENTS'];

/** Today's spendable cash = Σ balance_current over checking + savings accounts. */
function forecast_cash_balance(array $accounts): float
{
    $sum = 0.0;
    foreach ($accounts as $a) {
        if (in_array(account_group($a), FORECAST_CASH_GROUPS, true)) {
            $sum += (float)($a['balance_current'] ?? 0);
        }
    }
    return round($sum, 2);
}

/**
 * Projected recurring INCOME occurrences within [$from,$to] (inclusive). Mirrors
 * bill_occurrences()'s recurring projection (anchored k-th occurrence, day-clamped,
 * stale-guarded) but for INFLOW streams — no true-expense category filter (income IS
 * the inflow). Same occurrence shape as bill_occurrences with source='income'.
 */
function forecast_income_occurrences(array $recurring, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $fromYmd = $from->format('Y-m-d');
    $toYmd   = $to->format('Y-m-d');
    $today   = new DateTimeImmutable('today');
    $out     = [];

    foreach ($recurring as $r) {
        if (($r['direction'] ?? '') !== 'inflow') continue;
        // Skip internal transfers / loan moves (the income-side mirror of bill_occurrences'
        // category filter) — an own-account sweep is already in the start balance, so counting
        // it as income would double it. Plaid's recurring categories are noisy, but consistency
        // with the outflow side is the right call.
        if (in_array(strtoupper((string)($r['category_primary'] ?? '')), FORECAST_INCOME_EXCLUDE_CATS, true)) continue;
        $last = $r['last_date'] ?? null;
        $freq = strtoupper((string)($r['frequency'] ?? ''));
        if (!$last || !bill_freq_projectable($freq)) continue;

        // Stale guard: an "active" stream not seen in 2+ periods is probably ended.
        $staleBefore = $today->sub(new DateInterval('P' . (2 * BILL_PERIOD_DAYS[$freq]) . 'D'));
        if ($last < $staleBefore->format('Y-m-d')) continue;

        $lastDt = new DateTimeImmutable($last);
        $k = bill_k_for_date($lastDt, $freq, $fromYmd);
        for ($emitted = 0, $steps = 0; $emitted < 200 && $steps < 400; $k++, $steps++) {
            $ymd = bill_occurrence_at($lastDt, $freq, $k)->format('Y-m-d');
            if ($ymd < $fromYmd) continue;
            if ($ymd > $toYmd) break;
            $emitted++;
            $amt  = isset($r['average_amount']) ? abs((float)$r['average_amount']) : 0.0;
            // Truthiness, not ??: an empty-string merchant_name must fall through.
            $name = trim((string)($r['merchant_name'] ?? ''));
            if ($name === '') $name = trim((string)($r['description'] ?? ''));
            if ($name === '') $name = 'Recurring income';
            $mask = ($r['mask'] ?? '') !== '' ? ' ••' . $r['mask'] : '';
            $out[] = [
                'date'       => $ymd,
                'source'     => 'income',
                'kind'       => 'Income',
                'label'      => $name,
                'sublabel'   => trim(($r['account_name'] ?? '') . $mask),
                'amount'     => $amt > 0 ? round($amt, 2) : null,
                'owner_id'   => $r['owner_id'] ?? null,
                'account_id' => $r['account_id'],
            ];
        }
    }
    return $out;
}

/**
 * Build the forecast over the next $horizonDays days (clamped 1..120).
 *
 * Returns:
 *   'start_balance'       float  today's spendable cash
 *   'end_balance'         float  projected cash on the last day
 *   'min_balance'         float  the projected low point
 *   'min_date'            Y-m-d  when the low occurs
 *   'goes_negative'       bool   does the projection dip below $0
 *   'horizon'             int    days
 *   'series'              ['labels'=>[Y-m-d…], 'values'=>[balance…]]  today → end
 *   'events'              [occurrence + 'signed'(?float: + income / − bill)] date asc
 *   'avg_daily_spend'     float  the historical baseline (from q_avg_daily_spend)
 *   'discretionary_daily' float  the flat per-day burn actually applied
 *   'has_income'          bool   any KNOWN-amount income projected into the balance
 *   'income_count'        int    inflow streams detected (incl. amountless — the page uses
 *                                this vs has_income to word the no-income caveat honestly)
 */
function forecast_build(array $accounts, array $liabilities, array $recurring,
                        float $avgDailySpend, int $horizonDays, DateTimeImmutable $today): array
{
    $horizonDays = max(1, min(120, $horizonDays));
    $end  = $today->add(new DateInterval('P' . $horizonDays . 'D'));
    $from = $today->add(new DateInterval('P1D')); // project from tomorrow — today's balance is already current

    $b0 = forecast_cash_balance($accounts);

    // Lumpy events: scheduled bills (−) and recurring income (+).
    $billEvents   = bill_occurrences($liabilities, $recurring, $from, $end);
    $incomeEvents = forecast_income_occurrences($recurring, $from, $end);

    // Discretionary daily baseline = average daily spend MINUS the per-day rate of the
    // recurring (subscription) bill events, so those events and the flat baseline don't
    // double-count the same dollars (liability events are excluded — they're not in the
    // spend average). Floored at $0.
    $recurKnownOut = 0.0;
    foreach ($billEvents as $e) {
        if ($e['source'] === 'recurring' && $e['amount'] !== null) $recurKnownOut += $e['amount'];
    }
    $recurDaily = $recurKnownOut / $horizonDays;
    $discDaily  = max(0.0, round($avgDailySpend - $recurDaily, 2));

    // Index known-amount events by date for the day walk.
    $inByDate = $outByDate = [];
    foreach ($incomeEvents as $e) if ($e['amount'] !== null) $inByDate[$e['date']]  = ($inByDate[$e['date']]  ?? 0.0) + $e['amount'];
    foreach ($billEvents   as $e) if ($e['amount'] !== null) $outByDate[$e['date']] = ($outByDate[$e['date']] ?? 0.0) + $e['amount'];

    // Walk day-by-day, building the running balance. Day 0 = today (current balance).
    $labels   = [$today->format('Y-m-d')];
    $values   = [round($b0, 2)];
    $bal      = $b0;
    $minBal   = $b0;
    $minDate  = $today->format('Y-m-d');
    $totalIn  = 0.0;   // known-amount income added — only used to derive has_income
    for ($d = 1; $d <= $horizonDays; $d++) {
        $ymd = $today->add(new DateInterval('P' . $d . 'D'))->format('Y-m-d');
        $in  = $inByDate[$ymd] ?? 0.0;
        $out = ($outByDate[$ymd] ?? 0.0) + $discDaily;
        $bal += $in - $out;
        $totalIn += $in;
        $labels[] = $ymd;
        $values[] = round($bal, 2);
        if ($bal < $minBal) { $minBal = $bal; $minDate = $ymd; }
    }

    // Merged event list for display: income first within a day, then bills.
    $events = [];
    foreach ($incomeEvents as $e) { $e['signed'] = $e['amount'] === null ? null :  $e['amount']; $events[] = $e; }
    foreach ($billEvents   as $e) { $e['signed'] = $e['amount'] === null ? null : -$e['amount']; $events[] = $e; }
    usort($events, function ($a, $b) {
        if ($a['date'] !== $b['date']) return $a['date'] <=> $b['date'];
        if ($a['source'] !== $b['source']) return $a['source'] === 'income' ? -1 : 1;
        return ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0);
    });

    return [
        'start_balance'       => round($b0, 2),
        'end_balance'         => round($bal, 2),
        'min_balance'         => round($minBal, 2),
        'min_date'            => $minDate,
        'goes_negative'       => $minBal < 0,
        'horizon'             => $horizonDays,
        'series'              => ['labels' => $labels, 'values' => $values],
        'events'              => $events,
        'avg_daily_spend'     => round($avgDailySpend, 2),
        'discretionary_daily' => $discDaily,
        'has_income'          => $totalIn > 0,        // any income with a KNOWN amount projected in
        'income_count'        => count($incomeEvents), // inflow streams detected (incl. amountless)
    ];
}
