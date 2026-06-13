<?php
declare(strict_types=1);

/**
 * Retirement page data assembler + projection math.
 *
 * build_retirement_view($pdo,$uid) gathers the visible 401(k) accounts, their
 * hand-entered quarterly statements (retirement_statements) and the global
 * projection settings (retirement_settings), then derives:
 *   - the combined current total + per-account cards (with staleness),
 *   - a combined value-over-time series and a contributions series,
 *   - a growth rate DERIVED from balance history (time-weighted) — manual 401(k)
 *     statements AND/OR Plaid retirement accounts' (quarterly-resampled) daily
 *     balance history, pooled — with the settings override / default as fallback,
 *   - a year-by-year projection to the target retirement year.
 *
 * Pure read + derive (uses the q_* helpers in queries.php, which enforce
 * visibility). Used only by retirement.php + the dashboard card.
 */

/** 'YYYY-Qn' quarter bucket for a Y-m-d date. */
function ret_period_key(string $date): string
{
    $ts = strtotime($date);
    $y  = (int)date('Y', $ts);
    $q  = (int)ceil(((int)date('n', $ts)) / 3);
    return sprintf('%04d-Q%d', $y, $q);
}

/**
 * Resample a date→balance map down to ~quarterly observations. Plaid retirement
 * accounts carry a DAILY balance history; feeding day-spaced points to the growth
 * derivation would annualize single-day noise into absurd rates (`pow(1+r, 365)`),
 * so we coarsen to roughly the same quarterly cadence as the manual 401(k)
 * statements before deriving. We step ~a quarter at a time FROM the earliest
 * observation (rather than bucketing by calendar quarter) so the full available
 * span is used and every pair is ~a quarter long. The most recent observation
 * (today's balance) is always the final point — extending the last pair rather than
 * appending a tiny, noise-amplifying one. Returns a date-ascending list of
 * ['date'=>'Y-m-d','balance'=>float].
 */
function ret_resample_quarterly(array $map): array
{
    if (!$map) return [];
    ksort($map);
    $step  = 75 * 86400; // ~a quarter; keeps pairs near the manual statement cadence
    $picked = [];
    $lastTs = null;
    foreach ($map as $d => $bal) {
        $ts = strtotime((string)$d);
        if ($ts === false) continue;
        if ($lastTs === null || ($ts - $lastTs) >= $step) {
            $picked[] = ['date' => (string)$d, 'balance' => (float)$bal];
            $lastTs = $ts;
        }
    }
    // Anchor the series on the most recent observation. If the tail is shorter than a
    // step, replace the last pick (lengthening that final pair) instead of adding a
    // short one; otherwise append it.
    $dates    = array_keys($map);
    $lastDate = (string)$dates[count($dates) - 1];
    if ($picked && $picked[count($picked) - 1]['date'] !== $lastDate) {
        $tailTs = strtotime($lastDate);
        $prevTs = strtotime($picked[count($picked) - 1]['date']);
        $point  = ['date' => $lastDate, 'balance' => (float)$map[$lastDate]];
        if ($tailTs !== false && $prevTs !== false && ($tailTs - $prevTs) >= $step) {
            $picked[] = $point;
        } else {
            $picked[count($picked) - 1] = $point;
        }
    }
    return $picked;
}

/**
 * Time-weighted annualized growth rate derived from each account's balance
 * observations, pooled across accounts. Works for BOTH manual 401(k)s (quarterly
 * statements) AND Plaid retirement accounts (quarterly-resampled daily balance
 * history) — and any combination — since every account contributes its consecutive
 * pairs to the same pool. For each pair the contributions DEPOSITED in that interval
 * are removed first so deposits don't read as market growth.
 *
 * $accountSeries: list of [
 *   'points'   => [['date'=>'Y-m-d','balance'=>float], …]  (date-ascending, ≥2),
 *   'contribs' => [['date'=>'Y-m-d','amount'=>float], …]   (amount > 0 = deposit),
 * ].
 * Returns ['rate'=>?float,'pairs'=>int,'span'=>float] — rate is null until there's
 * at least one valid pair and the pooled span is ≥ ~0.5 years.
 */
function ret_derive_growth(array $accountSeries): array
{
    $sumW = 0.0; $sumWR = 0.0; $pairs = 0; $span = 0.0;
    foreach ($accountSeries as $acct) {
        $pts      = $acct['points'] ?? [];
        $contribs = $acct['contribs'] ?? [];
        $n = count($pts);
        for ($k = 1; $k < $n; $k++) {
            $start = (float)$pts[$k - 1]['balance'];
            if ($start <= 0) continue;
            $d0 = strtotime((string)$pts[$k - 1]['date']);
            $d1 = strtotime((string)$pts[$k]['date']);
            if ($d0 === false || $d1 === false) continue;
            // Contributions deposited in (d0, d1] — strictly after the start point, up
            // to and including the end point — so each deposit is counted in exactly one
            // pair (and the manual path stays identical: the end statement's contribution).
            $contrib = 0.0;
            foreach ($contribs as $c) {
                $cd = strtotime((string)$c['date']);
                if ($cd !== false && $cd > $d0 && $cd <= $d1) $contrib += (float)$c['amount'];
            }
            $growth = (float)$pts[$k]['balance'] - $start - $contrib;
            $ret    = $growth / $start;
            if ((1 + $ret) <= 0) continue; // avoid fractional power of a negative base
            $dt = ($d1 - $d0) / 86400 / 365.25;
            if ($dt <= 0) continue;
            $annual = pow(1 + $ret, 1 / $dt) - 1;
            $sumW  += $dt;
            $sumWR += $annual * $dt;
            $span  += $dt;
            $pairs++;
        }
    }
    $rate = ($pairs >= 1 && $span >= 0.5 && $sumW > 0) ? $sumWR / $sumW : null;
    return ['rate' => $rate, 'pairs' => $pairs, 'span' => round($span, 2)];
}

/**
 * Year-by-year projection. Compounds P forward at rate $r adding $c each year for
 * $n years. Returns [['year'=>int,'value'=>float], …] including the start year.
 */
function ret_project(float $p, float $r, float $c, int $startYear, int $n): array
{
    $out = [['year' => $startYear, 'value' => round($p, 2)]];
    $v = $p;
    for ($i = 1; $i <= $n; $i++) {
        $v = $v * (1 + $r) + $c;
        $out[] = ['year' => $startYear + $i, 'value' => round($v, 2)];
    }
    return $out;
}

// --- Monte Carlo (TODO2 #36) -------------------------------------------------
// Upgrades the single-rate deterministic projection (ret_project) to a probabilistic
// simulation: each year's return is a random draw from Normal(mean, volatility), run
// many times, so the page can say "78% chance of reaching your target" + show a
// percentile fan of outcomes. mean = the same growth rate the deterministic line uses
// (override/derived/default); volatility is an owner-set assumption (the ~quarter of
// account history is far too short to derive one) defaulting to RET_DEFAULT_VOLATILITY.

const RET_MC_RUNS          = 2000;    // simulation paths — plenty for stable percentiles, ~ms in PHP
const RET_DEFAULT_VOLATILITY = 0.13;  // annual std-dev of returns (equity-heavy long-horizon default)
const RET_DEFAULT_INFLATION  = 0.025; // fallback CPI inflation when FRED has no data (today's-$ caveat)
const RET_MC_RETURN_FLOOR  = -0.95;   // a single year can't lose >95% (guards a fat-tailed draw)
const RET_MC_SEED          = 0x9E3779B9; // fixed seed → the chart is reproducible across page loads

/**
 * Deterministic [0,1) generator (xorshift32) so the simulation renders identically on
 * every page load and is reproducible under test — and, by using its OWN state, it never
 * touches PHP's global mt_rand() seed. Returns a closure; call it for each draw.
 */
function ret_mc_prng(int $seed): callable
{
    $state = $seed & 0xFFFFFFFF;
    if ($state === 0) $state = 0x9E3779B9;
    return function () use (&$state): float {
        $x = $state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5)  & 0xFFFFFFFF;
        $x &= 0xFFFFFFFF;
        $state = $x;
        return $x / 4294967296.0; // 2^32
    };
}

/** One Normal(mean, sd) draw via Box–Muller from the uniform generator $next. */
function ret_mc_normal(callable $next, float $mean, float $sd): float
{
    $u1 = $next();
    $u2 = $next();
    if ($u1 < 1e-12) $u1 = 1e-12;            // avoid log(0)
    $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    return $mean + $sd * $z;
}

/** Linear-interpolated percentile of an ASCENDING-sorted numeric array. */
function ret_percentile(array $sortedAsc, float $p): float
{
    $n = count($sortedAsc);
    if ($n === 0) return 0.0;
    if ($n === 1) return (float)$sortedAsc[0];
    $rank = ($p / 100) * ($n - 1);
    $lo = (int)floor($rank);
    $hi = (int)ceil($rank);
    if ($lo === $hi) return (float)$sortedAsc[$lo];
    return (float)$sortedAsc[$lo] + ((float)$sortedAsc[$hi] - (float)$sortedAsc[$lo]) * ($rank - $lo);
}

/**
 * Run the simulation. Compounds $p0 forward $n years; each year applies a random return
 * ~Normal($mean,$vol) then adds $contrib (matching ret_project's year-end contribution
 * order). Returns per-year percentile bands (for the fan chart), the probability of
 * finishing ≥ $target (null if no target), and the 10th/50th/90th end values.
 */
function ret_monte_carlo(float $p0, float $mean, float $vol, float $contrib, int $startYear, int $n, ?float $target, int $runs = RET_MC_RUNS): array
{
    $n    = max(0, $n);
    $runs = max(1, $runs);
    $byYear = [];
    for ($y = 0; $y <= $n; $y++) $byYear[$y] = [];

    $next = ret_mc_prng(RET_MC_SEED);
    for ($r = 0; $r < $runs; $r++) {
        $v = $p0;
        $byYear[0][] = $v;
        for ($y = 1; $y <= $n; $y++) {
            $ret = ret_mc_normal($next, $mean, $vol);
            if ($ret < RET_MC_RETURN_FLOOR) $ret = RET_MC_RETURN_FLOOR;
            $v = $v * (1 + $ret) + $contrib;
            if ($v < 0) $v = 0.0;
            $byYear[$y][] = $v;
        }
    }

    $bands = [];
    $finalsSorted = [];
    foreach ($byYear as $y => $vals) {
        sort($vals);
        if ($y === $n) $finalsSorted = $vals;
        $bands[] = [
            'year' => $startYear + $y,
            'p10'  => round(ret_percentile($vals, 10), 2),
            'p25'  => round(ret_percentile($vals, 25), 2),
            'p50'  => round(ret_percentile($vals, 50), 2),
            'p75'  => round(ret_percentile($vals, 75), 2),
            'p90'  => round(ret_percentile($vals, 90), 2),
        ];
    }

    $success = null;
    if ($target !== null && $target > 0 && $finalsSorted) {
        $cnt = 0;
        foreach ($finalsSorted as $fv) if ($fv >= $target) $cnt++;
        $success = round($cnt / count($finalsSorted) * 100, 1);
    }
    $last = $bands[$n];
    return [
        'runs'        => $runs,
        'years'       => $n,
        'mean'        => $mean,
        'bands'       => $bands,
        'success_pct' => $success,
        'end_low'     => $last['p10'],
        'end_median'  => $last['p50'],
        'end_high'    => $last['p90'],
    ];
}

/**
 * Annual CPI inflation assumption for the today's-dollars caveat: the latest year-over-year
 * CPI-U change from the FRED feed we already pull, clamped to a sane band; falls back to
 * RET_DEFAULT_INFLATION when FRED data is unavailable.
 */
function ret_inflation_assumption(PDO $pdo): float
{
    if (function_exists('q_fred_history')) {
        $hist = q_fred_history($pdo, 'CPIAUCSL', 0); // ascending, monthly
        $n = count($hist);
        if ($n >= 13) {
            $latest  = (float)$hist[$n - 1]['value'];
            $yearAgo = (float)$hist[$n - 13]['value'];
            if ($yearAgo > 0) {
                $yoy = ($latest - $yearAgo) / $yearAgo;
                if ($yoy >= 0 && $yoy <= 0.15) return round($yoy, 4);
            }
        }
    }
    return RET_DEFAULT_INFLATION;
}

/** Assemble everything the Retirement page needs. */
function build_retirement_view(PDO $pdo, int $uid): array
{
    $accounts  = q_retirement_accounts($pdo, $uid);
    $statements = q_retirement_statements($pdo, $uid);   // oldest first, all accounts
    $settings  = q_retirement_settings($pdo);

    // Group statements by account (already ASC by date) — manual 401(k)s only.
    $byAccount = [];
    foreach ($statements as $s) $byAccount[$s['account_id']][] = $s;

    // Holdings for the retirement accounts, grouped by account — Plaid brokerages AND
    // (Session 55, #25) manual 401(k)s whose statement import wrote per-fund holdings.
    // Holdings that round to $0 (e.g. Plaid's cash placeholder security) are suppressed.
    $holdByAccount = [];
    foreach (q_holdings($pdo, $uid) as $h) {
        if (!is_retirement_account($h)) continue;
        if ($h['institution_value'] === null || abs((float)$h['institution_value']) < 0.005) continue;
        $holdByAccount[$h['account_id']][] = $h;
    }

    $todayYmd = date('Y-m-d');
    $today    = strtotime($todayYmd);

    // --- per-account series + cards ------------------------------------------
    // Each retirement account gets a date→balance series from its richest source:
    // manual 401(k)s from their hand-entered statements, Plaid accounts from
    // account_balance_history (daily, written by cron). A "today = current balance"
    // anchor is appended so a just-linked Plaid account with no history yet still
    // shows its value and the chart's last point equals the combined total.
    $total = 0.0;
    $cards = [];
    $acctMap = [];   // account_id => [YYYY-MM-DD => balance]
    foreach ($accounts as $a) {
        $aid    = $a['account_id'];
        $bal    = (float)($a['balance_current'] ?? 0);
        $total += $bal;
        $manual = ($a['manual_type'] ?? '') === 'retirement_401k';

        $map = [];
        if ($manual) {
            foreach ($byAccount[$aid] ?? [] as $s) $map[(string)$s['statement_date']] = (float)$s['balance'];
        } else {
            foreach (q_account_balance_history($pdo, $aid) as $r) $map[(string)$r['snapshot_date']] = (float)$r['balance'];
        }
        if ($a['balance_current'] !== null) $map[$todayYmd] = $bal; // current-value anchor
        ksort($map);
        $acctMap[$aid] = $map;

        // Staleness: manual = since last statement; Plaid = since last sync.
        $lastReal = $manual
            ? (($byAccount[$aid] ?? []) ? (string)$byAccount[$aid][count($byAccount[$aid]) - 1]['statement_date'] : null)
            : ($a['last_updated_datetime'] ? substr((string)$a['last_updated_datetime'], 0, 10) : null);
        $staleDays = $lastReal ? (int)floor(($today - strtotime($lastReal)) / 86400) : null;

        $cards[] = [
            'account'    => $a,
            'manual'     => $manual,
            'balance'    => $bal,
            'last_date'  => $lastReal,
            'stale_days' => $staleDays,
            'count'      => count($byAccount[$aid] ?? []),
            'holdings'   => $holdByAccount[$aid] ?? [],
        ];
    }

    // --- combined value over time (union of dates, carry forward, sum) --------
    $allDates = [];
    foreach ($acctMap as $map) foreach ($map as $d => $_) $allDates[$d] = true;
    ksort($allDates);
    $valueSeries = [];
    $lastBal = [];
    foreach (array_keys($allDates) as $d) {
        foreach ($acctMap as $aid => $map) { if (isset($map[$d])) $lastBal[$aid] = $map[$d]; }
        $valueSeries[] = ['date' => $d, 'value' => round(array_sum($lastBal), 2)];
    }

    // --- contributions by quarter (+ employee/employer split) -----------------
    $contribByPeriod = []; // period_key => ['ee'=>, 'er'=>]
    foreach ($statements as $s) {
        $p = (string)$s['period_key'];
        if (!isset($contribByPeriod[$p])) $contribByPeriod[$p] = ['ee' => 0.0, 'er' => 0.0];
        $contribByPeriod[$p]['ee'] += (float)($s['employee_contrib'] ?? 0);
        $contribByPeriod[$p]['er'] += (float)($s['employer_contrib'] ?? 0);
    }
    ksort($contribByPeriod);

    // Year-to-date + trailing-12-month contributions.
    $curYear = (int)date('Y');
    $cutoff  = strtotime('-365 days', $today);
    $ytdEe = 0.0; $ytdEr = 0.0; $ttmContrib = 0.0;
    foreach ($statements as $s) {
        $ee = (float)($s['employee_contrib'] ?? 0);
        $er = (float)($s['employer_contrib'] ?? 0);
        if ((int)date('Y', strtotime((string)$s['statement_date'])) === $curYear) { $ytdEe += $ee; $ytdEr += $er; }
        if (strtotime((string)$s['statement_date']) >= $cutoff) $ttmContrib += $ee + $er;
    }

    // Fold in Plaid-synced contributions (e.g. Betterment payroll/IRA deposits) so the
    // YTD / quarterly / trailing-12-month figures reflect them too. Manual 401(k)s carry
    // contributions in retirement_statements, but Plaid retirement accounts have none — so
    // without this the "Contributed YTD" hero, the contributions-by-quarter chart and the
    // projection's annual-contribution input would all under-count (showing $0 for a
    // Plaid-only account). Plaid tags every deposit with subtype 'contribution' (no
    // structured employee/employer split), but its free-text name distinguishes an
    // "Employer Contribution" from a payroll one — so we route a deposit whose name
    // mentions "employer" to the employer-match bucket, else to the employee bucket.
    // Balances already include these deposits, so we touch only the contribution
    // accumulators, never $total / the value series.
    $plaidAcctIds = [];
    foreach ($cards as $c) {
        if (empty($c['manual'])) $plaidAcctIds[] = (string)$c['account']['account_id'];
    }
    $plaidContribByAcct = []; // account_id => [['date'=>,'amount'=>], …] — feeds the growth derivation
    if ($plaidAcctIds) {
        // All contribution rows (not one page); stored amount − = money in → positive deposit.
        foreach (q_investment_activity($pdo, $uid, 'contributions', $plaidAcctIds, 100000, 0) as $r) {
            $amt = -(float)$r['amount'];
            if ($amt <= 0) continue;
            $ts = strtotime((string)$r['tdate']);
            if ($ts === false) continue;
            $plaidContribByAcct[(string)$r['account_id']][] = ['date' => (string)$r['tdate'], 'amount' => $amt];
            $bucket = stripos((string)$r['title'], 'employer') !== false ? 'er' : 'ee';
            $pk = ret_period_key((string)$r['tdate']);
            if (!isset($contribByPeriod[$pk])) $contribByPeriod[$pk] = ['ee' => 0.0, 'er' => 0.0];
            $contribByPeriod[$pk][$bucket] += $amt;
            if ((int)date('Y', $ts) === $curYear) {
                if ($bucket === 'er') $ytdEr += $amt; else $ytdEe += $amt;
            }
            if ($ts >= $cutoff) $ttmContrib += $amt;
        }
        ksort($contribByPeriod);
    }

    // --- growth rate: override ?? derived ?? default --------------------------
    // Derive from each account's balance observations + the contributions deposited
    // between them — manual 401(k)s from their quarterly statements, Plaid retirement
    // accounts from their (quarterly-resampled) daily balance history; pooled, so any
    // mix of the two contributes. Each account needs ≥2 observations spanning real time.
    $growthSeries = [];
    foreach ($accounts as $a) {
        $aid    = $a['account_id'];
        $manual = ($a['manual_type'] ?? '') === 'retirement_401k';
        if ($manual) {
            $pts = []; $contribs = [];
            foreach ($byAccount[$aid] ?? [] as $s) {
                $pts[]      = ['date' => (string)$s['statement_date'], 'balance' => (float)$s['balance']];
                $contribs[] = ['date'   => (string)$s['statement_date'],
                               'amount' => (float)($s['employee_contrib'] ?? 0) + (float)($s['employer_contrib'] ?? 0)];
            }
        } else {
            $pts      = ret_resample_quarterly($acctMap[$aid] ?? []);
            $contribs = $plaidContribByAcct[(string)$aid] ?? [];
        }
        if (count($pts) >= 2) $growthSeries[] = ['points' => $pts, 'contribs' => $contribs];
    }
    $derived = ret_derive_growth($growthSeries);
    if ($settings['growth_rate_override'] !== null) {
        $rate = $settings['growth_rate_override']; $rateBasis = 'override';
    } elseif ($derived['rate'] !== null) {
        $rate = $derived['rate']; $rateBasis = 'derived';
    } else {
        $rate = $settings['growth_default']; $rateBasis = 'default';
    }

    // Effective annual contribution: explicit setting ?? trailing-12-month actual.
    $annualContribUsed = $settings['annual_contribution'] !== null
        ? $settings['annual_contribution']
        : round($ttmContrib, 2);

    // --- projection -----------------------------------------------------------
    $projection = null;
    if ($settings['retirement_year'] !== null && $settings['retirement_year'] >= $curYear) {
        $n = $settings['retirement_year'] - $curYear;
        $series = ret_project($total, $rate, $annualContribUsed, $curYear, $n);
        $projected = $series[count($series) - 1]['value'];
        $projection = [
            'years'        => $n,
            'target_year'  => $settings['retirement_year'],
            'rate'         => $rate,
            'rate_basis'   => $rateBasis,
            'annual_contrib' => $annualContribUsed,
            'series'       => $series,
            'projected'    => $projected,
            'target_amount'=> $settings['target_amount'],
            'progress'     => ($settings['target_amount'] && $settings['target_amount'] > 0)
                                ? round($total / $settings['target_amount'] * 100, 1) : null,
        ];
    }

    // --- Monte Carlo (probability of success + outcome fan) (#36) -------------
    // Built only when there's a projection (a target year set). mean = the deterministic
    // rate; volatility = the owner's setting or the bundled default. The simulation runs
    // in NOMINAL dollars (so the success % is vs the target the owner actually entered);
    // the median is also restated in today's dollars using FRED CPI inflation as context.
    $monteCarlo = null;
    if ($projection !== null) {
        $volSet = $settings['return_volatility'];
        $volIsDefault = ($volSet === null || $volSet <= 0);
        $vol  = $volIsDefault ? RET_DEFAULT_VOLATILITY : $volSet;
        $infl = ret_inflation_assumption($pdo);
        $n    = $projection['years'];
        $sim  = ret_monte_carlo($total, $rate, $vol, $annualContribUsed, $curYear, $n, $settings['target_amount'], RET_MC_RUNS);
        $realFactor = pow(1 + $infl, -$n); // nominal → today's dollars over the horizon
        $monteCarlo = $sim + [
            'volatility'     => $vol,
            'vol_is_default' => $volIsDefault,
            'inflation'      => $infl,
            'median_real'    => round($sim['end_median'] * $realFactor, 2),
            'target'         => $settings['target_amount'],
            'target_real'    => $settings['target_amount'] !== null ? round($settings['target_amount'] * $realFactor, 2) : null,
        ];
    }

    return [
        'accounts'      => $accounts,
        'cards'         => $cards,
        'total'         => round($total, 2),
        'value_series'  => $valueSeries,
        'contrib_periods' => $contribByPeriod,
        'ytd'           => ['employee' => round($ytdEe, 2), 'employer' => round($ytdEr, 2), 'total' => round($ytdEe + $ytdEr, 2)],
        'ttm_contrib'   => round($ttmContrib, 2),
        'derived'       => $derived,
        'rate'          => $rate,
        'rate_basis'    => $rateBasis,
        'settings'      => $settings,
        'projection'    => $projection,
        'monte_carlo'   => $monteCarlo,
        'cur_year'      => $curYear,
    ];
}
