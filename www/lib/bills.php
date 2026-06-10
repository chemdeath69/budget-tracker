<?php
/**
 * lib/bills.php — Upcoming-bills / payment-calendar assembler (TODO #4).
 *
 * Pure derive: NO database access of its own. The page fetches the two
 * VIS-scoped reads once — q_liabilities($pdo,$uid) and q_recurring($pdo,$uid)
 * (both in queries.php) — and hands the rows in. We merge:
 *
 *   • Liabilities — each visible liability with a next_payment_due_date is one
 *     bill on that date (mortgage / credit-card minimum / student-loan payment).
 *     These are Plaid's authoritative due dates.
 *
 *   • Recurring outflows — Plaid gives no "next date" for a recurring stream, so
 *     we PROJECT it forward from last_date + frequency. Approximate by design
 *     (SEMI_MONTHLY ≈ +15d); guarded against dead streams (stale guard) and
 *     against double-counting a liability already shown for the same account.
 *
 * Mirrors the assembler pattern of lib/property_view.php / lib/retirement.php.
 */

/**
 * Recurring-stream primary categories to EXCLUDE from bills — mirrors q_cashflow's
 * "true expense" philosophy. Internal transfers (e.g. savings→checking sweeps) and
 * income aren't bills; LOAN_PAYMENTS are represented authoritatively by the
 * liabilities source (with real due dates), so dropping them here avoids a
 * double-count. Plaid's recurring categories are noisy, but staying consistent with
 * the rest of the app is the right call (the alternative — showing a $2k internal
 * transfer as a "bill" — is far more misleading).
 */
const BILL_RECUR_EXCLUDE_CATS = ['TRANSFER_IN', 'TRANSFER_OUT', 'LOAN_PAYMENTS', 'INCOME'];

/** Approximate period length in days per Plaid frequency (for the stale guard). */
const BILL_PERIOD_DAYS = [
    'WEEKLY'       => 7,
    'BIWEEKLY'     => 14,
    'SEMI_MONTHLY' => 15,
    'MONTHLY'      => 31,
    'ANNUALLY'     => 366,
];

/** Is this Plaid frequency one we can project forward? */
function bill_freq_projectable(?string $freq): bool
{
    return isset(BILL_PERIOD_DAYS[strtoupper((string)$freq)]);
}

/**
 * The k-th projected occurrence (k >= 1) of a recurring stream after its
 * $last_date. Computed by ANCHORING to $last (not cumulative stepping), so
 * month/year math never drifts: for MONTHLY/ANNUALLY the day-of-month is clamped
 * to the target month's length (e.g. the 31st → Feb 28/29, then back to the 31st),
 * which avoids PHP's P1M month-overflow that would otherwise skip a short month
 * and permanently shift the day. WEEKLY/BIWEEKLY/SEMI_MONTHLY are exact day adds.
 */
function bill_occurrence_at(DateTimeImmutable $last, string $freq, int $k): DateTimeImmutable
{
    switch ($freq) {
        case 'WEEKLY':       return $last->add(new DateInterval('P' . (7 * $k) . 'D'));
        case 'BIWEEKLY':     return $last->add(new DateInterval('P' . (14 * $k) . 'D'));
        case 'SEMI_MONTHLY': return $last->add(new DateInterval('P' . (15 * $k) . 'D')); // ~twice a month (approx)
        case 'MONTHLY':
            $dom   = (int)$last->format('j');
            // Adding months to the 1st can never overflow; then clamp the day.
            $first = $last->modify('first day of this month')->add(new DateInterval('P' . $k . 'M'));
            $dim   = (int)$first->format('t');
            return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($dom, $dim));
        case 'ANNUALLY':
            $dom = (int)$last->format('j');
            $m   = (int)$last->format('n');
            $y   = (int)$last->format('Y') + $k;
            $dim = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
            return $last->setDate($y, $m, min($dom, $dim)); // Feb 29 → Feb 28 in a non-leap year
    }
    return $last; // unreachable: callers gate on bill_freq_projectable()
}

/**
 * Smallest occurrence index k (>= 1) whose date is at or just before $fromYmd —
 * computed arithmetically so a far-future window costs O(1) instead of looping
 * thousands of periods. Deliberately undershoots by one period; the caller's loop
 * walks the final step(s) forward to the exact first in-window occurrence.
 */
function bill_k_for_date(DateTimeImmutable $last, string $freq, string $fromYmd): int
{
    $from = new DateTimeImmutable($fromYmd);
    if ($from <= $last) return 1;
    switch ($freq) {
        case 'MONTHLY':
            $months = ((int)$from->format('Y') - (int)$last->format('Y')) * 12
                    + ((int)$from->format('n') - (int)$last->format('n'));
            return max(1, $months - 1);
        case 'ANNUALLY':
            return max(1, (int)$from->format('Y') - (int)$last->format('Y') - 1);
        default: // day-based
            $days = (int)$last->diff($from)->days;
            return max(1, intdiv($days, BILL_PERIOD_DAYS[$freq]) - 1);
    }
}

/** Friendly "kind" tag for a liability_type. */
function bill_liability_kind(?string $type): string
{
    switch (strtolower((string)$type)) {
        case 'credit':   return 'Credit card';
        case 'mortgage': return 'Mortgage';
        case 'student':  return 'Student loan';
        default:         return 'Loan';
    }
}

/**
 * Build the list of bill occurrences within [$from, $to] (inclusive), sorted by
 * date ascending. Amount is a float, or null when unknown / non-positive (shown
 * as "—" by the page, matching the digest).
 *
 * Occurrence shape:
 *   ['date'=>'Y-m-d', 'source'=>'liability'|'recurring', 'kind'=>str,
 *    'label'=>str, 'sublabel'=>str, 'amount'=>?float,
 *    'owner_id'=>?int, 'account_id'=>str]
 */
function bill_occurrences(array $liabilities, array $recurring,
                          DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $fromYmd = $from->format('Y-m-d');
    $toYmd   = $to->format('Y-m-d');
    $out     = [];

    // --- Liabilities: one occurrence per liability whose due date is in range ---
    // Track (account_id => [due dates]) so we can dedup overlapping recurring streams.
    $liabDates = [];
    foreach ($liabilities as $l) {
        $due = $l['next_payment_due_date'] ?? null;
        if (!$due) continue;
        $liabDates[$l['account_id']][] = $due; // remember for dedup even if out of range
        if ($due < $fromYmd || $due > $toYmd) continue;

        $amt  = isset($l['minimum_payment_amount']) ? (float)$l['minimum_payment_amount'] : 0.0;
        $kind = bill_liability_kind($l['liability_type'] ?? null);
        $mask = ($l['mask'] ?? '') !== '' ? ' ••' . $l['mask'] : '';
        $out[] = [
            'date'       => $due,
            'source'     => 'liability',
            'kind'       => $kind,
            'label'      => $l['account_name'] ?? $kind,
            'sublabel'   => trim($kind . ' payment' . $mask),
            'amount'     => $amt > 0 ? round($amt, 2) : null,
            'owner_id'   => $l['owner_id'] ?? null,
            'account_id' => $l['account_id'],
        ];
    }

    // --- Recurring outflows: project forward from last_date + frequency ---
    $today = new DateTimeImmutable('today');
    foreach ($recurring as $r) {
        if (($r['direction'] ?? '') !== 'outflow') continue;
        // Skip internal transfers / income / loan-payments (covered by liabilities).
        $cat = strtoupper((string)($r['category_primary'] ?? ''));
        if (in_array($cat, BILL_RECUR_EXCLUDE_CATS, true)) continue;
        $last = $r['last_date'] ?? null;
        $freq = strtoupper((string)($r['frequency'] ?? ''));
        if (!$last || !bill_freq_projectable($freq)) continue;

        // Stale guard: a still-"active" stream we haven't seen in 2+ periods is
        // probably ended — don't ghost-project it.
        $staleBefore = $today->sub(new DateInterval('P' . (2 * BILL_PERIOD_DAYS[$freq]) . 'D'));
        if ($last < $staleBefore->format('Y-m-d')) continue;

        // Emit the projected occurrences (k >= 1 → strictly AFTER last_date, never
        // the already-made payment) that fall in [from, to]. bill_k_for_date jumps
        // near the window so a far-future month stays O(1); the loop walks the last
        // step(s) and caps emitted rows per stream.
        $lastDt = new DateTimeImmutable($last);
        $k = bill_k_for_date($lastDt, $freq, $fromYmd);
        for ($emitted = 0, $steps = 0; $emitted < 200 && $steps < 400; $k++, $steps++) {
            $ymd = bill_occurrence_at($lastDt, $freq, $k)->format('Y-m-d');
            if ($ymd < $fromYmd) continue;
            if ($ymd > $toYmd) break;
            $emitted++;
            // Dedup: skip if a liability for the same account is due within ±3 days
            // (e.g. a mortgage that also surfaces as a recurring outflow stream).
            if (bill_near_liability($liabDates[$r['account_id']] ?? [], $ymd, 3)) continue;
            $amt  = isset($r['average_amount']) ? abs((float)$r['average_amount']) : 0.0;
            // Truthiness, not ??: an empty-string merchant_name must fall through.
            $name = trim((string)($r['merchant_name'] ?? ''));
            if ($name === '') $name = trim((string)($r['description'] ?? ''));
            if ($name === '') $name = 'Recurring payment';
            $mask = ($r['mask'] ?? '') !== '' ? ' ••' . $r['mask'] : '';
            $out[] = [
                'date'       => $ymd,
                'source'     => 'recurring',
                'kind'       => 'Subscription',
                'label'      => $name,
                'sublabel'   => trim(($r['account_name'] ?? '') . $mask),
                'amount'     => $amt > 0 ? round($amt, 2) : null,
                'owner_id'   => $r['owner_id'] ?? null,
                'account_id' => $r['account_id'],
            ];
        }
    }

    // Sort by date, then liabilities before subscriptions, then by amount desc.
    usort($out, function ($a, $b) {
        if ($a['date'] !== $b['date']) return $a['date'] <=> $b['date'];
        if ($a['source'] !== $b['source']) return $a['source'] === 'liability' ? -1 : 1;
        return ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0);
    });
    return $out;
}

/** True if any of $dates (Y-m-d strings) is within $within whole days of $ymd. */
function bill_near_liability(array $dates, string $ymd, int $within): bool
{
    // Whole-day diff (not epoch-seconds/86400) so a DST transition's 23h/25h day
    // can't push a pair exactly $within days apart over the threshold.
    $occ = new DateTimeImmutable($ymd);
    foreach ($dates as $d) {
        if ((int)$occ->diff(new DateTimeImmutable($d))->days <= $within) return true;
    }
    return false;
}

/**
 * Build a Monday-first weeks grid for the month containing $monthFirst (which
 * must be the 1st of the month). $occByDate is [ 'Y-m-d' => [occurrence,...] ].
 * Returns an array of weeks, each week an array of 7 cells; a cell is either
 * null (leading/trailing blank) or
 *   ['day'=>int,'date'=>'Y-m-d','today'=>bool,'past'=>bool,'bills'=>[...]].
 */
function bills_calendar_weeks(array $occByDate, DateTimeImmutable $monthFirst, DateTimeImmutable $today): array
{
    $daysIn   = (int)$monthFirst->format('t');
    $lead     = (int)$monthFirst->format('N') - 1; // Mon=0 … Sun=6
    $todayYmd = $today->format('Y-m-d');

    $cells = array_fill(0, $lead, null);
    for ($day = 1; $day <= $daysIn; $day++) {
        $ymd = $monthFirst->setDate((int)$monthFirst->format('Y'), (int)$monthFirst->format('m'), $day)->format('Y-m-d');
        $cells[] = [
            'day'   => $day,
            'date'  => $ymd,
            'today' => $ymd === $todayYmd,
            'past'  => $ymd < $todayYmd,
            'bills' => $occByDate[$ymd] ?? [],
        ];
    }
    while (count($cells) % 7 !== 0) $cells[] = null; // trailing blanks

    return array_chunk($cells, 7);
}

/** Index a flat occurrence list by date → [ 'Y-m-d' => [occurrence,...] ]. */
function bills_by_date(array $occurrences): array
{
    $by = [];
    foreach ($occurrences as $o) $by[$o['date']][] = $o;
    return $by;
}
