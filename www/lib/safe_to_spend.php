<?php
/**
 * lib/safe_to_spend.php — "Safe to spend" spending-plan number (TODO2 #31).
 *
 * PURE derive (like lib/forecast.php / lib/bills.php): NO database of its own. The page hands in the
 * VIS-scoped reads (q_recurring / q_liabilities), the household monthly savings target, and the
 * month-to-date true-expense total (q_true_expense_total). We compose the single Simplifi-style
 * "spending plan" figure for the CURRENT calendar month.
 *
 * The plan — distinct, NON-OVERLAPPING populations so nothing double-counts:
 *   Expected income       (+)  recurring INFLOW occurrences anywhere in this month — INCLUDING pay
 *                              already received (see sts_month_income() below).
 *   − Committed bills      (−)  this month's liability dues (all of them) + recurring (subscription)
 *                              outflows STILL UPCOMING this month (date > today). A subscription that
 *                              already charged this month is NOT counted here — its real charge is in
 *                              "spent" below — so it's counted exactly once.
 *   − Monthly savings      (−)  the owner-set household target.
 *   = Free to spend this month
 *   − Discretionary spent  (−)  q_true_expense_total() over month-to-date — your real everyday +
 *                              already-charged-subscription spending. No projected-vs-actual netting:
 *                              the only recurring outflows in "committed bills" are the UPCOMING ones,
 *                              which by definition aren't in the MTD actuals yet, so the two populations
 *                              never overlap. (Liabilities are never in true-expense, so they're never
 *                              in "spent" — that's why all of them belong in committed bills.)
 *   = Safe to spend (what's left for everyday spending this month)
 *
 * Honest-number stance (mirrors lib/forecast.php): only Plaid-detected RECURRING income is projected
 * (irregular income isn't → conservative, understates inflow); a bill/income with an unknown amount
 * doesn't move the figure (the page words the no-income caveat off income_count vs has_income).
 *
 * ⚠️ Why a dedicated sts_month_income() instead of reusing forecast.php's forecast_income_occurrences():
 * that projector is FORWARD-ONLY — bill_k_for_date()/bill_occurrence_at() never emit the last_date
 * occurrence (k>=1), which is correct for the cash-balance forecast (it projects from TOMORROW, and
 * already-received pay is already in the start balance) but WRONG for a calendar-month plan, which has
 * no start-balance term and must count a paycheck already received earlier this month. So we walk each
 * inflow stream from k=0 (its last_date) and keep every occurrence within [monthStart, monthEnd].
 *
 * Reuses lib/bills.php (via forecast.php): bill_occurrences() for the bill events, bill_occurrence_at()/
 * bill_freq_projectable()/BILL_PERIOD_DAYS for the income projection, and FORECAST_INCOME_EXCLUDE_CATS.
 */

require_once __DIR__ . '/forecast.php';

/**
 * Recurring INFLOW occurrences within [$monthStart, $monthEnd] (inclusive), INCLUDING each stream's
 * already-received last_date occurrence (k=0). Mirrors the income-side filters of
 * forecast_income_occurrences() (inflow direction, FORECAST_INCOME_EXCLUDE_CATS, projectable freq,
 * stale guard) but is NOT forward-only — see the file header for why. Occurrence shape matches
 * bill_occurrences()/forecast_income_occurrences() (source='income').
 */
function sts_month_income(array $recurring, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): array
{
    $startYmd = $monthStart->format('Y-m-d');
    $endYmd   = $monthEnd->format('Y-m-d');
    $today    = new DateTimeImmutable('today');
    $out      = [];

    foreach ($recurring as $r) {
        if (($r['direction'] ?? '') !== 'inflow') continue;
        if (in_array(strtoupper((string)($r['category_primary'] ?? '')), FORECAST_INCOME_EXCLUDE_CATS, true)) continue;
        $last = $r['last_date'] ?? null;
        $freq = strtoupper((string)($r['frequency'] ?? ''));
        if (!$last || !bill_freq_projectable($freq)) continue;

        // Stale guard: an "active" stream not seen in 2+ periods is probably ended (same as forecast).
        $staleBefore = $today->sub(new DateInterval('P' . (2 * BILL_PERIOD_DAYS[$freq]) . 'D'));
        if ($last < $staleBefore->format('Y-m-d')) continue;

        // Walk from k=0 (the last_date occurrence itself) forward; emit those within this month.
        // last_date is recent (stale guard), so the small bounded loop reaches the window cheaply.
        $lastDt = new DateTimeImmutable($last);
        for ($k = 0, $steps = 0; $steps < 400; $k++, $steps++) {
            $ymd = bill_occurrence_at($lastDt, $freq, $k)->format('Y-m-d');
            if ($ymd < $startYmd) continue;   // earlier than this month — keep stepping forward
            if ($ymd > $endYmd)   break;      // past month end — done
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
 * Build the spending plan for the calendar month containing $today.
 *
 * Returns:
 *   'month_label'    string  e.g. "June 2026"
 *   'income'         float   expected recurring income this month (known amounts, incl. already received)
 *   'bills'          float   committed bills this month (all liabilities + UPCOMING subscriptions)
 *   'savings_target' float   the household monthly savings target (>=0)
 *   'plan'           float   free to spend this month = income − bills − savings
 *   'spent'          float   discretionary true-expense MTD (>=0)
 *   'safe'           float   safe to spend = plan − spent
 *   'days_left'      int     days remaining in the month, incl. today
 *   'daily_left'     float   safe ÷ days_left — a suggested daily pace (rest of month)
 *   'spent_pct'      int     spent ÷ plan, clamped 0..100 (the progress bar)
 *   'over'           bool    safe < 0 (already over plan)
 *   'has_income'     bool    any KNOWN-amount income this month
 *   'income_count'   int     recurring inflow OCCURRENCES this month (incl. amountless) — for the caveat
 */
function safe_to_spend_build(array $recurring, array $liabilities, float $savingsTarget,
                             float $trueExpenseMtd, DateTimeImmutable $today): array
{
    $monthStart = $today->modify('first day of this month');
    $monthEnd   = $today->modify('last day of this month');
    $todayYmd   = $today->format('Y-m-d');

    // Expected income — whole month, including pay already received this month.
    $incomeEvents = sts_month_income($recurring, $monthStart, $monthEnd);
    $income = 0.0;
    foreach ($incomeEvents as $e) {
        if ($e['amount'] !== null) $income += $e['amount'];
    }

    // Committed bills — all of this month's liability dues + recurring outflows STILL UPCOMING
    // (date > today). An already-charged subscription this month is real spend already in
    // $trueExpenseMtd, so excluding it here keeps it counted exactly once (see the file header).
    $bills = 0.0;
    foreach (bill_occurrences($liabilities, $recurring, $monthStart, $monthEnd) as $e) {
        if ($e['source'] === 'recurring' && $e['date'] <= $todayYmd) continue;
        if ($e['amount'] !== null) $bills += $e['amount'];
    }

    $savings = max(0.0, $savingsTarget);
    $plan    = $income - $bills - $savings;            // free to spend this month
    $spent   = max(0.0, round($trueExpenseMtd, 2));    // discretionary + already-charged-subscription spend
    $safe    = $plan - $spent;                         // safe to spend

    // Days left in the month, incl. today → a suggested daily pace for the rest of the month.
    $daysLeft  = (int)$monthEnd->diff($today)->days + 1;
    $dailyLeft = $daysLeft > 0 ? $safe / $daysLeft : $safe;

    return [
        'month_label'    => $today->format('F Y'),
        'income'         => round($income, 2),
        'bills'          => round($bills, 2),
        'savings_target' => round($savings, 2),
        'plan'           => round($plan, 2),
        'spent'          => $spent,
        'safe'           => round($safe, 2),
        'days_left'      => $daysLeft,
        'daily_left'     => round($dailyLeft, 2),
        'spent_pct'      => $plan > 0 ? (int)min(100, max(0, round($spent / $plan * 100))) : ($spent > 0 ? 100 : 0),
        'over'           => $safe < 0,
        'has_income'     => $income > 0,
        'income_count'   => count($incomeEvents),
    ];
}
