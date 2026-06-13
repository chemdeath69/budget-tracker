<?php
declare(strict_types=1);

/**
 * Economic-data feed — fills `fred_series` (one observation per series per date)
 * from FRED (Federal Reserve Economic Data, St. Louis Fed) so the Economic page +
 * the inline insights can show:
 *   - real (inflation-adjusted) net worth      → CPIAUCSL  (CPI-U index, monthly)
 *   - mortgage rate vs the market / refi        → MORTGAGE30US (30-yr fixed avg, %, weekly)
 *   - savings-rate context                      → DGS10 (10-yr Treasury, %, daily)
 *                                                 FEDFUNDS (Fed Funds rate, %, monthly)
 *   - savings-rate benchmark (#38)              → SNDR (FDIC national savings rate, %, monthly)
 *
 * Provider: FRED (https://fred.stlouisfed.org/docs/api/fred/). FREE — generous rate
 * limit (120 req/min) and NO per-request billing, unlike RentCast. The HTTP layer is
 * isolated in fred_call() so a provider swap is a one-function change.
 *
 * SAFE WITHOUT A KEY: if config 'fred.api_key' is empty, every entry point returns
 * ['ok'=>false,'error'=>'no_key'] and touches nothing — so this can ship before the
 * key exists (the pages render an empty-state, the inline insights are omitted).
 *
 * Mirrors lib/prices.php's shape (api_key reader, single cURL point, upsert, daily
 * refresh + one-time backfill).
 */

const FRED_BASE = 'https://api.stlouisfed.org/fred';

// Refresh depth. The nightly cron normally pulls just the recent tail
// (FRED_REFRESH_LIMIT), but **self-heals** a sparse/empty series (fresh DB, a newly
// added series) by pulling a deep history (FRED_BACKFILL_LIMIT) until it has at least
// FRED_MIN_HISTORY rows — so the Economic charts + CPI reindex aren't stuck at ~8
// points. The upsert never deletes, so once backfilled the tail-fetch just maintains.
const FRED_REFRESH_LIMIT  = 8;
const FRED_BACKFILL_LIMIT = 130;   // ~10yr monthly · ~2.5yr weekly · ~6mo daily
const FRED_MIN_HISTORY    = 120;

/**
 * The series tracked, in display order. `unit` drives formatting on the page
 * ('index' = raw level, 'pct' = a percentage rate). `kind` groups them for the
 * Economic page's charts/insights.
 */
const FRED_SERIES = [
    'CPIAUCSL'     => ['label' => 'Consumer Price Index (CPI-U)', 'unit' => 'index', 'kind' => 'inflation'],
    'MORTGAGE30US' => ['label' => '30-Year Fixed Mortgage Rate', 'unit' => 'pct',   'kind' => 'mortgage'],
    'DGS10'        => ['label' => '10-Year Treasury Yield',      'unit' => 'pct',   'kind' => 'rates'],
    'FEDFUNDS'     => ['label' => 'Federal Funds Rate',          'unit' => 'pct',   'kind' => 'rates'],
    'SNDR'         => ['label' => 'National Savings Rate (FDIC)','unit' => 'pct',   'kind' => 'rates'],
];

/** Configured FRED API key, or '' if the economic feed is disabled. */
function fred_api_key(): string
{
    $cfg = $GLOBALS['CONFIG']['fred'] ?? null;
    return is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
}

/**
 * One FRED GET. Appends the api_key + file_type=json. Returns the decoded body, or
 * ['__error'=>reason] on a transport/API error (callers check for '__error').
 */
function fred_call(string $path, array $params): array
{
    $params['api_key']   = fred_api_key();
    $params['file_type'] = 'json';
    $url = FRED_BASE . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'budget-tracker/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false)  return ['__error' => 'curl: ' . $err];
    if ($code === 429)    return ['__error' => 'rate_limited'];
    $json = json_decode((string)$body, true);
    if (!is_array($json)) return ['__error' => 'bad_json (http ' . $code . ')'];
    // FRED signals errors with HTTP 400 + {"error_code":..,"error_message":..}.
    if (isset($json['error_code'])) {
        return ['__error' => 'api: ' . ($json['error_message'] ?? ('code ' . $json['error_code']))];
    }
    return $json;
}

/** Idempotent upsert of one observation. */
function fred_upsert(PDO $pdo, string $seriesId, string $date, float $value): void
{
    static $st = null;
    if ($st === null) {
        $st = $pdo->prepare(
            "INSERT INTO fred_series (series_id, obs_date, value)
             VALUES (:s, :d, :v)
             ON DUPLICATE KEY UPDATE value = VALUES(value), fetched_at = CURRENT_TIMESTAMP"
        );
    }
    $st->execute([':s' => $seriesId, ':d' => $date, ':v' => $value]);
}

/**
 * Fetch the newest $limit observations for one series and upsert each. FRED returns
 * missing observations as "." — those are skipped. Returns the count stored or
 * ['__error'=>..].
 */
function fred_fetch_series(PDO $pdo, string $seriesId, int $limit): array
{
    $res = fred_call('series/observations', [
        'series_id'  => $seriesId,
        'sort_order' => 'desc',     // newest first, so $limit trims to the recent tail
        'limit'      => $limit,
    ]);
    if (isset($res['__error'])) return $res;
    $obs = $res['observations'] ?? null;
    if (!is_array($obs)) return ['__error' => 'no_observations'];

    $n = 0;
    foreach ($obs as $o) {
        $date = substr((string)($o['date'] ?? ''), 0, 10);
        $val  = $o['value'] ?? null;
        if ($date === '' || $val === null || !is_numeric($val)) continue;  // "." = missing
        fred_upsert($pdo, $seriesId, $date, (float)$val);
        $n++;
    }
    return ['stored' => $n];
}

/**
 * One-time (or occasional) history backfill — a long tail per series so the Economic
 * page charts have depth and the CPI reindex resolves for any net-worth snapshot date.
 * Returns a per-series report.
 */
function fred_backfill(PDO $pdo, int $limit = 130): array
{
    if (fred_api_key() === '') return ['ok' => false, 'error' => 'no_key'];
    $report = [];
    foreach (array_keys(FRED_SERIES) as $sid) {
        $r = fred_fetch_series($pdo, $sid, $limit);
        $report[$sid] = $r['__error'] ?? ('stored ' . $r['stored']);
    }
    return ['ok' => true, 'series' => count(FRED_SERIES), 'report' => $report];
}

/**
 * Daily refresh — pull just the last few observations per series to capture the
 * newest value (cheap; called from the daily cron). FRED's rate limit is generous,
 * so there's no inter-call sleep (unlike the Twelve Data price feed).
 */
function fred_refresh_latest(PDO $pdo): array
{
    if (fred_api_key() === '') return ['ok' => false, 'error' => 'no_key'];
    $updated = 0; $backfilled = 0; $errors = [];
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM fred_series WHERE series_id = :s");
    foreach (array_keys(FRED_SERIES) as $sid) {
        $cnt->execute([':s' => $sid]);
        $have = (int)$cnt->fetchColumn();
        // Self-heal a sparse series with a deep pull; otherwise just the recent tail.
        $deep  = $have < FRED_MIN_HISTORY;
        $limit = $deep ? FRED_BACKFILL_LIMIT : FRED_REFRESH_LIMIT;
        if ($deep) $backfilled++;
        $r = fred_fetch_series($pdo, $sid, $limit);
        if (isset($r['__error'])) $errors[$sid] = $r['__error'];
        else $updated += (int)$r['stored'];
    }
    return ['ok' => true, 'series' => count(FRED_SERIES), 'updated' => $updated,
            'backfilled' => $backfilled, 'errors' => $errors];
}

/**
 * CPI index value applicable on/before $date (YYYY-MM-DD), or null if no CPI data.
 * CPIAUCSL is monthly and released with a lag, so the most-recent observation on or
 * before the date is the right basis for reindexing that date's dollars.
 */
function fred_cpi_at(PDO $pdo, string $date): ?float
{
    static $st = null;
    if ($st === null) {
        $st = $pdo->prepare(
            "SELECT value FROM fred_series
             WHERE series_id = 'CPIAUCSL' AND obs_date <= :d
             ORDER BY obs_date DESC LIMIT 1"
        );
    }
    $st->execute([':d' => $date]);
    $v = $st->fetchColumn();
    return $v === false ? null : (float)$v;
}

/**
 * Factor that converts a nominal value AS OF $date into today's dollars:
 *   real_today = nominal_then * (latestCPI / cpiAt($date))
 * Returns null when CPI data is missing (so callers fall back to nominal-only). The
 * latest CPI is cached per request.
 */
function fred_real_factor(PDO $pdo, string $date): ?float
{
    static $latest = false;  // false = not yet looked up (distinct from null = no data)
    if ($latest === false) {
        $row = $pdo->query(
            "SELECT value FROM fred_series WHERE series_id = 'CPIAUCSL'
             ORDER BY obs_date DESC LIMIT 1"
        )->fetchColumn();
        $latest = $row === false ? null : (float)$row;
    }
    if ($latest === null || $latest <= 0) return null;
    $then = fred_cpi_at($pdo, $date);
    if ($then === null || $then <= 0) return null;
    return $latest / $then;
}

/**
 * Re-express a net-worth snapshot series in today's dollars (CPI-adjusted). $snaps is
 * the q_networth() shape ([['snapshot_date'=>..,'net_worth'=>nominal], …]). Returns a
 * values array aligned to $snaps (a few entries may be null if CPI is missing for an
 * early date), or null entirely when there's no CPI data at all (caller then shows the
 * nominal line only).
 */
function fred_real_series(PDO $pdo, array $snaps): ?array
{
    if (fred_real_factor($pdo, date('Y-m-d')) === null) return null;  // no CPI data → bail
    $out = [];
    foreach ($snaps as $s) {
        $f = fred_real_factor($pdo, (string)($s['snapshot_date'] ?? ''));
        $out[] = $f === null ? null : round((float)($s['net_worth'] ?? 0) * $f, 2);
    }
    return $out;
}

/**
 * Inflation-adjusted change of net worth vs a prior snapshot, in today's dollars.
 * $current is already in today's dollars; ($prevNominal, $prevDate) are the prior
 * snapshot's nominal value and date. Returns ['pct'=>?,'abs'=>?] (nulls when CPI data
 * is unavailable). This is the purchasing-power change — distinct from the nominal one.
 */
function fred_real_change(PDO $pdo, float $current, float $prevNominal, string $prevDate): array
{
    $f = fred_real_factor($pdo, $prevDate);
    if ($f === null) return ['pct' => null, 'abs' => null];
    $prevReal = $prevNominal * $f;
    if ($prevReal == 0.0) return ['pct' => null, 'abs' => null];
    return [
        'pct' => round((($current - $prevReal) / abs($prevReal)) * 100, 1),
        'abs' => round($current - $prevReal, 2),
    ];
}
