<?php
declare(strict_types=1);

/**
 * Economic-data page assembler (TODO #17).
 *
 * Turns the cached FRED series (lib/fred.php → fred_series) into the three insights
 * the Economic page shows, plus per-series trend data:
 *   - real (inflation-adjusted) net worth — q_networth() reindexed by CPI
 *   - mortgage rate vs the current market 30-yr — reuses build_property_view()['refi']
 *   - savings-rate context — idle cash measured against the Fed-funds / 10-yr benchmark
 *
 * Pure read+derive (no writes, no external HTTP — the nightly cron does the fetching).
 * Safe with no FRED data: returns has_data=false and the page renders an empty-state.
 */

require_once __DIR__ . '/queries.php';        // q_fred_*, q_networth, q_accounts, q_stats, account_group, q_account_interest
require_once __DIR__ . '/fred.php';            // FRED_SERIES, fred_real_series, fred_real_factor
require_once __DIR__ . '/home_value.php';      // hv_zip_from_address (needed by build_property_view)
require_once __DIR__ . '/property_view.php';   // build_property_view (for the refi block)
require_once __DIR__ . '/apy.php';             // build_apy_view (savings-rate benchmark, #38)

/**
 * Latest year-over-year CPI inflation from the cached series, or null.
 * Returns ['yoy'=>float, 'as_of'=>'YYYY-MM-DD'].
 */
function econ_cpi_inflation(array $cpiHistory): ?array
{
    if (count($cpiHistory) < 2) return null;
    $latest = end($cpiHistory);
    $yearAgo = date('Y-m-d', strtotime($latest['date'] . ' -12 months'));
    $base = null;
    foreach ($cpiHistory as $o) {
        if ($o['date'] <= $yearAgo) $base = $o; else break;
    }
    if (!$base || $base['value'] <= 0) return null;
    return ['yoy' => round(($latest['value'] / $base['value'] - 1) * 100, 1), 'as_of' => $latest['date']];
}

function build_economic_view(PDO $pdo, int $uid): array
{
    // --- Per-series trend data + latest value ---------------------------------
    $series = [];
    $hasData = false;
    foreach (FRED_SERIES as $sid => $meta) {
        $hist   = q_fred_history($pdo, $sid, 0);     // all cached obs, oldest first
        $latest = $hist ? end($hist) : null;
        if ($latest) $hasData = true;
        $series[$sid] = [
            'label'   => $meta['label'],
            'unit'    => $meta['unit'],     // 'index' | 'pct'
            'kind'    => $meta['kind'],
            'history' => $hist,
            'latest'  => $latest,           // ['date','value'] or null
        ];
    }

    $accounts = q_accounts($pdo, $uid);

    // --- Real (inflation-adjusted) net worth ----------------------------------
    $real  = null;
    $snaps = q_networth($pdo);
    if ($snaps && count($snaps) > 1) {
        $realVals = fred_real_series($pdo, $snaps);   // null without CPI
        if ($realVals !== null) {
            $stats   = q_stats($accounts, q_home_value($pdo));
            $curr    = (float)$stats['net_worth'];
            // Anchor growth on the FIRST snapshot that has a CPI factor — early dates
            // may be null until the CPI backfill reaches that far, and using index 0
            // there would coerce null→0 and silently drop real_growth.
            $bi = 0;
            foreach ($realVals as $k => $rv) { if ($rv !== null) { $bi = $k; break; } }
            $firstNom  = (float)$snaps[$bi]['net_worth'];        // nominal at the anchor
            $firstReal = (float)($realVals[$bi] ?? 0);           // anchor, in today's dollars
            $real = [
                'labels'         => array_column($snaps, 'snapshot_date'),
                'nominal'        => array_map('floatval', array_column($snaps, 'net_worth')),
                'real'           => $realVals,
                'current'        => $curr,
                'from_date'      => (string)$snaps[$bi]['snapshot_date'],
                'nominal_growth' => $firstNom  != 0 ? round(($curr - $firstNom) / abs($firstNom) * 100, 1) : null,
                'real_growth'    => $firstReal != 0 ? round(($curr - $firstReal) / abs($firstReal) * 100, 1) : null,
            ];
        }
    }
    $inflation = build_economic_inflation($series);

    // --- Refi: reuse the single source of truth in build_property_view() ------
    $refi = null;
    $pv = build_property_view($pdo, $uid);
    if ($pv) $refi = $pv['refi'] ?? null;

    // --- Savings-rate benchmark (#38) -----------------------------------------
    // Each cash account's effective APY estimated from the interest its bank credited,
    // vs the FDIC national savings rate (SNDR) + a top-high-yield proxy (Fed funds).
    $savings = null;
    $cashAccounts = array_values(array_filter(
        $accounts,
        fn($a) => in_array(account_group($a), ['checking', 'savings'], true)
    ));
    if ($cashAccounts) {
        $sndr     = q_fred_latest($pdo, 'SNDR');       // FDIC national savings rate
        $ff       = q_fred_latest($pdo, 'FEDFUNDS');   // top-high-yield proxy
        $interest = q_account_interest($pdo, $uid, APY_WINDOW_DAYS);
        $savings  = build_apy_view(
            $cashAccounts,
            $interest,
            $sndr ? (float)$sndr['value'] : null,
            $ff   ? (float)$ff['value']   : null,
            date('Y-m-d')
        );
        $savings['national_as_of'] = $sndr['date'] ?? null;
        $savings['top_as_of']      = $ff['date'] ?? null;
    }

    return [
        'has_data'  => $hasData,
        'series'    => $series,
        'real'      => $real,
        'inflation' => $inflation,
        'refi'      => $refi,
        'savings'   => $savings,
    ];
}

/** Helper kept separate so the CPI YoY can be computed from the assembled $series. */
function build_economic_inflation(array $series): ?array
{
    $cpi = $series['CPIAUCSL']['history'] ?? [];
    return econ_cpi_inflation($cpi);
}
