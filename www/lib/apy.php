<?php
declare(strict_types=1);

/**
 * Savings-rate & APY benchmarking — PURE derive assembler (TODO2 #38).
 *
 * No DB of its own — economic.php passes in the viewer's VIS-scoped cash accounts +
 * q_account_interest() rows + the two benchmark rates:
 *   - national average savings = FRED SNDR (the FDIC national savings rate, sourced
 *     from FDIC, pulled via the FRED feed we already run);
 *   - top high-yield ≈ FRED FEDFUNDS (a competitive online savings account tracks the
 *     Fed-funds rate — a proxy, honestly caveated on the page; we have no per-bank feed).
 *
 * Each cash account's effective APY is ESTIMATED from the interest its bank actually
 * credited over the trailing window ÷ its current balance, annualized over the observed
 * history. This is an approximation of the *realized* yield, NOT the bank's stated APY.
 * Honest-number stance (S34/S58): show '—' rather than fabricate when there's too little
 * history, and never assert "earns ~0%" without enough history to back it.
 */

const APY_WINDOW_DAYS       = 365;   // trailing window for interest (matches q_account_interest default)
const APY_LOW_CONF_DAYS     = 180;   // < ~6mo observed history → low-confidence ("est"): annualizing a few
                                     //   months over-amplifies any single interest credit or balance change,
                                     //   and the denominator is the *current* balance (ABH doesn't reach back
                                     //   a full year), so a balance that moved biases the rate. Flag it.
const APY_MIN_DAYS_FOR_ZERO = 180;   // need ≥ this history to assert "earns ~0%" when no interest was seen
const APY_SANITY_MAX_PCT    = 20.0;  // an annualized figure above this is almost certainly a one-off bonus → flag

/**
 * Estimate one cash account's effective APY (%). Returns
 *   ['rate'=>?float, 'confidence'=>'ok'|'low'|null, 'observed_days'=>int, 'interest'=>float]
 * rate is null when we can't honestly estimate (no positive balance, or no interest with
 * too little history to assert ~0%). confidence 'low' = annualized off < APY_LOW_CONF_DAYS
 * of history, or an implausibly high (likely-bonus) figure.
 */
function apy_estimate_account(float $balance, float $interest, ?string $firstTx, string $today): array
{
    $observed = 0;
    if ($firstTx !== null && $firstTx !== '') {
        $observed = (int)floor((strtotime($today) - strtotime($firstTx)) / 86400);
    }
    if ($observed < 0) $observed = 0;
    if ($observed > APY_WINDOW_DAYS) $observed = APY_WINDOW_DAYS;

    $base = ['rate' => null, 'confidence' => null, 'observed_days' => $observed, 'interest' => round($interest, 2)];
    if ($balance <= 0) return $base;

    if ($interest > 0) {
        if ($observed < 1) return $base;   // can't annualize without a span
        $rate = ($interest / $balance) * (APY_WINDOW_DAYS / $observed) * 100.0;
        $conf = ($observed >= APY_LOW_CONF_DAYS && $rate <= APY_SANITY_MAX_PCT) ? 'ok' : 'low';
        return ['rate' => round($rate, 2), 'confidence' => $conf, 'observed_days' => $observed, 'interest' => round($interest, 2)];
    }
    // interest == 0: only assert ~0% with enough history; otherwise unknown.
    if ($observed >= APY_MIN_DAYS_FOR_ZERO) {
        return ['rate' => 0.0, 'confidence' => 'ok', 'observed_days' => $observed, 'interest' => 0.0];
    }
    return $base;
}

/**
 * Build the savings-rate benchmark view.
 *   $cashAccounts  = the viewer's VIS-scoped checking+savings accounts (each row from
 *                    q_accounts(): account_id/name/owner_id/balance_current/source).
 *   $interestByAcct= q_account_interest() keyed by account_id.
 *   $nationalRate  = SNDR latest value (%) or null; $topRate = FEDFUNDS latest (%) or null.
 *   $today         = 'Y-m-d' (app TZ).
 * Returns per-account estimates + a balance-weighted blended rate + the annual
 * opportunity (Σ over rate-known accounts of max(0, (top − acct) × balance)). Manual
 * (non-Plaid) cash accounts have no per-tx interest feed, so their rate is left unknown.
 */
function build_apy_view(array $cashAccounts, array $interestByAcct, ?float $nationalRate, ?float $topRate, string $today): array
{
    $accounts = [];
    $totalCash = 0.0; $opportunity = 0.0;
    $okInterest = 0.0; $okCash = 0.0;   // blended is built ONLY from confident estimates
    $hasEstimate = false;

    foreach ($cashAccounts as $a) {
        $balance = (float)($a['balance_current'] ?? 0);
        if ($balance <= 0) continue;   // a $0/negative cash balance has nothing to benchmark
        $totalCash += $balance;
        $aid = (string)($a['account_id'] ?? '');

        // Manual (non-Plaid) accounts have no per-transaction interest feed → can't estimate.
        // (Kept inline rather than via is_manual() so this pure assembler stays independent of queries.php.)
        if (($a['source'] ?? 'plaid') === 'manual') {
            $est = ['rate' => null, 'confidence' => null, 'observed_days' => 0, 'interest' => 0.0];
        } else {
            $info = $interestByAcct[$aid] ?? ['interest' => 0.0, 'first_tx' => null];
            $est  = apy_estimate_account($balance, (float)$info['interest'], $info['first_tx'] ?? null, $today);
        }

        $couldEarn = null;
        if ($est['rate'] !== null) {
            $hasEstimate = true;
            // Opportunity is robust even for a 'low'-confidence rate: an account far below
            // the benchmark stays far below it whatever the exact figure (floored at 0).
            if ($topRate !== null) {
                $extra = ($topRate - $est['rate']) / 100.0 * $balance;
                $couldEarn = $extra > 0 ? round($extra, 2) : 0.0;
                $opportunity += max(0.0, $extra);
            }
            // The blended headline only uses CONFIDENT estimates — a noisy short-history /
            // balance-skewed rate must not drive the household figure (S34/S58: show nothing
            // rather than a wrong number).
            if ($est['confidence'] === 'ok') {
                $okCash     += $balance;
                $okInterest += $balance * $est['rate'] / 100.0;
            }
        }
        $accounts[] = [
            'account_id' => $aid,
            'name'       => (string)($a['name'] ?? ''),
            'owner_id'   => $a['owner_id'] ?? null,
            'balance'    => round($balance, 2),
            'rate'       => $est['rate'],
            'confidence' => $est['confidence'],
            'could_earn' => $couldEarn,
        ];
    }

    // Biggest balance first (the rows that matter most on top).
    usort($accounts, fn($x, $y) => $y['balance'] <=> $x['balance']);

    $blended = $okCash > 0 ? round($okInterest / $okCash * 100.0, 2) : null;

    return [
        'accounts'      => $accounts,
        'total_cash'    => round($totalCash, 2),
        'blended_rate'  => $blended,   // confident-only; null until ≥6mo of history accrues
        'national_rate' => $nationalRate,
        'top_rate'      => $topRate,
        'opportunity'   => ($hasEstimate && $topRate !== null) ? round($opportunity, 2) : null,
        'has_estimate'  => $hasEstimate,
        'has_accounts'  => $totalCash > 0,
    ];
}
