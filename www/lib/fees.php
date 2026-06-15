<?php
declare(strict_types=1);
/**
 * Investment fee analyzer — PURE derive assembler (Session 70, TODO2 #39).
 *
 * No DB of its own: the page hands in the already-VIS-scoped holdings (q_holdings — WHOLE
 * portfolio, investments + retirement) and the per-security expense-ratio map
 * (q_security_expense_ratios). Like lib/allocation.php / lib/peers.php.
 *
 * Empower's headline free feature: the household's weighted-average portfolio expense ratio
 * and the projected annual $ drag, with the biggest win usually on the 401(k) fund holdings.
 *
 * ⚠️ HONEST-NUMBER stance:
 *  - There is no reliably-free expense-ratio feed (verified Session 70), so ratios are entered
 *    by hand. A fund with no ratio entered is treated as UNKNOWN (excluded from the weighted
 *    average + the annual drag, flagged "needs a ratio") — NOT silently as 0, which would
 *    understate the fee. The annual drag is therefore a FLOOR until coverage is complete.
 *  - Plaid/Webull report a fund's `securities.type` UNRELIABLY (Webull labels EVERY holding —
 *    including ETFs like SPY/RIET/BTCO — as 'equity', identical to a real stock), so we do NOT
 *    auto-classify anything as no-fee from the type. A holding is "covered" ONLY once the owner
 *    enters a value — a fund's ratio, or 0 for a single stock/coin. Nothing is silently counted
 *    at 0 (a wrong auto-0 would understate the fee — the dangerous direction). The security NAME
 *    drives only a placeholder HINT (does it look like a fund?), never coverage.
 *  - A non-fund holding (a single stock / a directly-held coin) genuinely has no expense ratio —
 *    the owner enters 0 to confirm it; once entered it counts in the weighted-average denominator.
 *  - The weighted average is reported over the COVERED subset (the entered holdings), with the
 *    coverage % shown, so it's never silently diluted by holdings that have no value yet.
 *  - Holdings-based (inherits the existing allocation/investments scope): a brokerage's
 *    uninvested cash and an account with no per-holding breakdown contribute nothing.
 *
 * Ratios are stored + handled as a PERCENT (0.50 = 0.50%). annual_fee = value × ratio / 100.
 */

/**
 * Keywords in a security NAME that mark it as a fund (so its input gets a "0.50"-style
 * placeholder rather than a "0" one). A HINT ONLY — it never decides coverage, so a false
 * positive (a stock named "…Trust") or false negative just shows a different placeholder; the
 * owner still enters the real value. Used because Webull's `securities.type` is unreliable
 * (every holding is 'equity'), but fund names almost always say so.
 */
const FEE_FUND_NAME_HINTS = ['ETF', 'ETN', 'FUND', 'TRUST', 'INDEX', 'PORTFOLIO', 'TARGET', 'ISHARES', 'SPDR'];

/** Years used for the "for scale" cumulative-cost figure (at today's balances). */
const FEE_PROJECTION_YEARS = 10;

/** Does the security name look like a fund? (placeholder hint only — never coverage). */
function fee_name_looks_like_fund(?string $name): bool
{
    $n = strtoupper((string)$name);
    foreach (FEE_FUND_NAME_HINTS as $kw) {
        if (strpos($n, $kw) !== false) return true;
    }
    return false;
}

/**
 * Resolve one security's effective expense ratio.
 *   $ratios: [security_id => percent].  $name: securities.name (for the placeholder hint).
 * @return array{ratio: ?float, source: 'manual'|'unknown', covered: bool, fund_hint: bool}
 *   - 'manual'  : the owner entered a value (covered; ratio may be 0 = a confirmed "no fee").
 *   - 'unknown' : no entry yet → ratio null, NOT covered (needs a value — a fund's ratio, or 0).
 * Nothing is auto-covered from `securities.type` (Webull labels ETFs as 'equity'), so a wrong
 * auto-0 can never silently understate the headline fee.
 */
function fee_resolve(?string $securityId, ?string $name, array $ratios): array
{
    if ($securityId !== null && array_key_exists($securityId, $ratios)) {
        return ['ratio' => max(0.0, (float)$ratios[$securityId]), 'source' => 'manual',
                'covered' => true, 'fund_hint' => true];
    }
    return ['ratio' => null, 'source' => 'unknown', 'covered' => false,
            'fund_hint' => fee_name_looks_like_fund($name)];
}

/**
 * Build the fee-analyzer view.
 *
 *   $holds  — q_holdings() rows (whole portfolio; each has security_id / security_type /
 *             ticker_symbol / security_name / institution_value).
 *   $ratios — [security_id => percent] from q_security_expense_ratios().
 *
 * Holdings are aggregated by security_id (the same ticker held across accounts → one row, one
 * ratio input). $0/placeholder holdings are suppressed (like the allocation doughnut).
 *
 * Returns:
 *   total            — Σ holding market value (> 0)
 *   covered_value    — Σ value of covered securities (the holdings with a ratio entered)
 *   uncovered_value  — Σ value of holdings with no ratio entered yet
 *   coverage_pct     — covered_value / total × 100
 *   uncovered_count  — # of securities still needing a value entered
 *   weighted_avg     — Σ(value×ratio)/covered_value over covered (percent); null if covered_value 0
 *   annual_fee       — Σ(value × ratio/100) over covered (a FLOOR while uncovered_count > 0)
 *   projection_total — annual_fee × FEE_PROJECTION_YEARS (at today's balances)
 *   projection_years — FEE_PROJECTION_YEARS
 *   has_any_ratio    — any manual ratio entered (drives the first-run guidance)
 *   biggest          — covered rows with a fee > 0, by annual_fee desc (top 3)
 *   rows             — per-security, by value desc: {security_id,label,name,value,pct,ratio,
 *                      source,covered,fund_hint,annual_fee}
 */
function build_fees_view(array $holds, array $ratios): array
{
    // Aggregate by security_id (combine the same ticker across accounts).
    $bySec = [];
    foreach ($holds as $h) {
        $val = (float)($h['institution_value'] ?? 0);
        if ($val <= 0) continue;
        $sid = ($h['security_id'] ?? '') !== '' ? (string)$h['security_id'] : null;
        // Defensive: holdings.security_id is NOT NULL (FK), so $sid is always present in practice;
        // but if one were ever missing it can't carry a ratio (no key to store), so bucket it under
        // a synthetic key — it still counts in the total + shows read-only (uncovered, input "—").
        $key = $sid ?? ('__noid_' . count($bySec));
        if (!isset($bySec[$key])) {
            $label = ($h['ticker_symbol'] ?? '') !== '' ? (string)$h['ticker_symbol']
                   : (($h['security_name'] ?? '') !== '' ? (string)$h['security_name'] : '—');
            $bySec[$key] = [
                'security_id' => $sid,
                'label'       => $label,
                'name'        => (string)($h['security_name'] ?? ''),
                'value'       => 0.0,
            ];
        }
        $bySec[$key]['value'] += $val;
    }

    $total         = 0.0;
    $coveredValue  = 0.0;
    $annualFee     = 0.0;
    $weightedNum   = 0.0;   // Σ value × ratio (covered)
    $uncoveredVal  = 0.0;
    $uncoveredCnt  = 0;
    $hasAnyRatio   = false;
    $rows          = [];

    foreach ($bySec as $s) {
        $val   = $s['value'];
        $total += $val;
        $res   = fee_resolve($s['security_id'], $s['name'], $ratios);
        if ($res['source'] === 'manual') $hasAnyRatio = true;

        $fee = null;
        if ($res['covered']) {
            $coveredValue += $val;
            $fee           = $val * ($res['ratio'] / 100.0);
            $annualFee    += $fee;
            $weightedNum  += $val * $res['ratio'];
        } else {
            $uncoveredVal += $val;
            $uncoveredCnt++;
        }

        $rows[] = [
            'security_id' => $s['security_id'],
            'label'       => $s['label'],
            'name'        => $s['name'],
            'value'       => $val,
            'ratio'       => $res['ratio'],
            'source'      => $res['source'],
            'covered'     => $res['covered'],
            'fund_hint'   => $res['fund_hint'],
            'annual_fee'  => $fee,
        ];
    }

    foreach ($rows as &$r) {
        $r['pct'] = $total > 0 ? $r['value'] / $total * 100 : 0.0;
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);

    $biggest = array_values(array_filter($rows, fn($r) => $r['annual_fee'] !== null && $r['annual_fee'] > 0));
    usort($biggest, fn($a, $b) => $b['annual_fee'] <=> $a['annual_fee']);
    $biggest = array_slice($biggest, 0, 3);

    return [
        'total'            => $total,
        'covered_value'    => $coveredValue,
        'uncovered_value'  => $uncoveredVal,
        'coverage_pct'     => $total > 0 ? $coveredValue / $total * 100 : 0.0,
        'uncovered_count'  => $uncoveredCnt,
        'weighted_avg'     => $coveredValue > 0 ? $weightedNum / $coveredValue : null,
        'annual_fee'       => $annualFee,
        'projection_total' => $annualFee * FEE_PROJECTION_YEARS,
        'projection_years' => FEE_PROJECTION_YEARS,
        'has_any_ratio'    => $hasAnyRatio,
        'biggest'          => $biggest,
        'rows'             => $rows,
    ];
}
