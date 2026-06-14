<?php
declare(strict_types=1);

/**
 * Refund tracking — PURE derive assembler (TODO2 #34).
 *
 * No DB of its own (like lib/apy.php / lib/peers.php / lib/bills.php): `refunds.php` passes
 * in the VIS-scoped reads — `q_refund_watches()` (the flagged purchases) and
 * `q_refund_credits()` (the candidate money-in pool) — so this stays independent of
 * queries.php load order.
 *
 * For each PENDING watch it finds candidate matching credits and ranks them (exact amount,
 * same account, same merchant, soonest after the purchase) as **suggestions** — never an
 * assertion (the owner confirms on the page; honest-number stance). For a RECEIVED watch it
 * looks up the confirmed credit's detail from the same VIS-scoped pool. Visibility-only:
 * it reports status + amounts, it does NOT change spend math (a refund credit is already its
 * own money-in transaction the spend aggregations exclude).
 *
 * Sign convention: a purchase is amount > 0 (money OUT); a credit is amount < 0, so its
 * magnitude is `-amount`.
 */

const REFUND_MIN_FRAC     = 0.5;   // a credit ≥ 50% of the purchase is a (partial-)refund candidate
const REFUND_AMOUNT_SLOP  = 0.01;  // …and ≤ purchase + 1¢ (rounding); a refund never exceeds the purchase
const REFUND_EXACT_SLOP   = 0.01;  // |credit − purchase| ≤ this ⇒ an "exact amount" match
const REFUND_MAX_SUGGEST  = 3;     // top-N candidates shown per pending watch

/** Normalise a merchant string for a same-merchant comparison (lowercase, collapse runs of
 *  non-alphanumerics to a single space, trim). '' if nothing usable. */
function refund_norm_merchant(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string)$s);
}

/** Display merchant for a row: enriched merchant_name, else the raw name, else '—'. */
function refund_merchant(array $row): string
{
    $m = trim((string)($row['merchant_name'] ?? ''));
    if ($m !== '') return $m;
    $n = trim((string)($row['name'] ?? ''));
    return $n !== '' ? $n : '—';
}

/**
 * Build the refunds view.
 *  $watches — q_refund_watches() rows (purchases, amount > 0, + status/matched_tx_id).
 *  $credits — q_refund_credits() rows (amount < 0 candidate pool, dated ≥ the earliest watch).
 *  $today   — 'Y-m-d' (PHP app-TZ).
 * Returns ['pending'=>[…], 'received'=>[…], 'pending_count','received_count','outstanding',
 *          'received_total'].
 */
function build_refunds_view(array $watches, array $credits, string $today): array
{
    // Index the credit pool by id (for matched-credit lookup) and keep the usable candidate list.
    $creditById = [];
    foreach ($credits as $c) {
        $creditById[(string)$c['transaction_id']] = $c;
    }

    // Credits already confirmed as some watch's match — never suggest them for another watch.
    $usedCredits = [];
    foreach ($watches as $w) {
        $mid = (string)($w['matched_tx_id'] ?? '');
        if ($mid !== '') $usedCredits[$mid] = true;
    }

    $pending = [];
    $received = [];
    $outstanding = 0.0;
    $receivedTotal = 0.0;

    foreach ($watches as $w) {
        $pamt   = (float)$w['amount'];          // purchase magnitude (> 0)
        $pdate  = (string)$w['date'];
        $pacct  = (string)($w['account_id'] ?? '');
        $pmerch = refund_norm_merchant((string)($w['merchant_name'] ?? '') ?: (string)($w['name'] ?? ''));

        $base = [
            'transaction_id' => (string)$w['transaction_id'],
            'merchant'       => refund_merchant($w),
            'date'           => $pdate,
            'amount'         => round($pamt, 2),
            'account_name'   => (string)($w['account_name'] ?? ''),
            'mask'           => (string)($w['mask'] ?? ''),
            'logo_url'       => (string)($w['logo_url'] ?? ''),
            'owner_id'       => $w['owner_id'] ?? null,
        ];

        if ((string)$w['status'] === 'received') {
            $mid = (string)($w['matched_tx_id'] ?? '');
            $matched = ($mid !== '' && isset($creditById[$mid])) ? $creditById[$mid] : null;
            $mAmt = $matched ? round(-(float)$matched['amount'], 2) : null;   // credit magnitude
            $base['matched']        = $matched ? [
                'transaction_id' => (string)$matched['transaction_id'],
                'merchant'       => refund_merchant($matched),
                'date'           => (string)$matched['date'],
                'amount'         => $mAmt,
            ] : null;                                                          // null ⇒ "(credit unavailable)"
            $receivedTotal += ($mAmt ?? $pamt);
            $received[] = $base;
            continue;
        }

        // Pending: rank candidate credits.
        $cands = [];
        foreach ($credits as $c) {
            $cid = (string)$c['transaction_id'];
            if (isset($usedCredits[$cid])) continue;          // spoken for by a confirmed match
            $cmag = -(float)$c['amount'];                     // credit magnitude (> 0)
            if ($cmag <= 0) continue;
            $cdate = (string)$c['date'];
            if ($cdate < $pdate) continue;                    // a refund lands on/after the purchase
            // Amount window: at least half the purchase, no more than the purchase (+rounding).
            if ($cmag < $pamt * REFUND_MIN_FRAC - REFUND_AMOUNT_SLOP) continue;
            if ($cmag > $pamt + REFUND_AMOUNT_SLOP) continue;

            $exact       = abs($cmag - $pamt) <= REFUND_EXACT_SLOP;
            $sameAccount = $pacct !== '' && (string)($c['account_id'] ?? '') === $pacct;
            $sameMerch   = $pmerch !== '' && refund_norm_merchant((string)($c['merchant_name'] ?? '') ?: (string)($c['name'] ?? '')) === $pmerch;
            $daysAfter   = (int)floor((strtotime($cdate) - strtotime($pdate)) / 86400);

            $cands[] = [
                'transaction_id' => $cid,
                'merchant'       => refund_merchant($c),
                'date'           => $cdate,
                'amount'         => round($cmag, 2),
                'account_name'   => (string)($c['account_name'] ?? ''),
                'mask'           => (string)($c['mask'] ?? ''),
                'exact'          => $exact,
                'same_account'   => $sameAccount,
                'same_merchant'  => $sameMerch,
                'days_after'     => $daysAfter,
                // "Likely" = an exact-amount credit that also matches the account or merchant.
                'likely'         => $exact && ($sameAccount || $sameMerch),
            ];
        }
        // Best first: exact amount, then same account, then same merchant, then soonest.
        usort($cands, function ($a, $b) {
            foreach (['exact', 'same_account', 'same_merchant'] as $k) {
                if ($a[$k] !== $b[$k]) return $a[$k] ? -1 : 1;
            }
            return $a['days_after'] <=> $b['days_after'];
        });
        $base['suggestions'] = array_slice($cands, 0, REFUND_MAX_SUGGEST);

        $outstanding += $pamt;
        $pending[] = $base;
    }

    return [
        'pending'        => $pending,
        'received'       => $received,
        'pending_count'  => count($pending),
        'received_count' => count($received),
        'outstanding'    => round($outstanding, 2),
        'received_total' => round($receivedTotal, 2),
    ];
}
