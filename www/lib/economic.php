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

require_once __DIR__ . '/queries.php';        // q_fred_*, q_networth, q_accounts, q_stats, account_group
require_once __DIR__ . '/fred.php';            // FRED_SERIES, fred_real_series, fred_real_factor
require_once __DIR__ . '/home_value.php';      // hv_zip_from_address (needed by build_property_view)
require_once __DIR__ . '/property_view.php';   // build_property_view (for the refi block)

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

    // --- Savings-rate context -------------------------------------------------
    $savings = null;
    $cash = 0.0;
    foreach ($accounts as $a) {
        if (in_array(account_group($a), ['checking', 'savings'], true)) {
            $cash += (float)($a['balance_current'] ?? 0);
        }
    }
    $t10 = q_fred_latest($pdo, 'DGS10');
    $ff  = q_fred_latest($pdo, 'FEDFUNDS');
    if ($cash > 0 && ($t10 || $ff)) {
        $bench = $ff ?: $t10;   // Fed-funds tracks short cash yields better than the 10-yr
        $savings = [
            'cash'            => round($cash, 2),
            't10'             => $t10,
            'fedfunds'        => $ff,
            'bench_label'     => $ff ? 'Fed Funds rate' : '10-yr Treasury',
            'bench_rate'      => $bench ? (float)$bench['value'] : null,
            'annual_at_bench' => $bench ? round($cash * (float)$bench['value'] / 100, 2) : null,
        ];
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
