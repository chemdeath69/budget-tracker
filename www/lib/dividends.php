<?php
declare(strict_types=1);

/**
 * Dividend feed — fills `security_dividends` (declared/historical cash dividends per
 * security: ex-date, per-share amount, payout frequency) so the Investments page can
 * show PROJECTED annual dividend income from current holdings and an UPCOMING
 * ex-dividend-date calendar.
 *
 * Provider: Polygon.io (free tier — 5 req/min, NO per-request billing). Its dividends
 * reference endpoint returns BOTH historical and already-declared FUTURE dividends with
 * a `frequency` field (payouts/yr), which makes the annual-income projection exact.
 * The HTTP layer is isolated in poly_call() so a different provider is a one-function swap.
 *
 * SAFE WITHOUT A KEY: if config 'polygon.api_key' is empty, every entry point returns
 * ['ok'=>false,'error'=>'no_key'] and touches nothing — so this can ship before the key
 * exists. Scope: US stocks & ETFs (bare ticker symbols), reusing the same held-ticker
 * list as the price feed (lib/prices.php → prices_tracked_securities, which already
 * skips CUR:%/cash placeholders).
 *
 * ⚠️ Polygon rebranded to "Massive" — the live host is now api.massive.com (the old
 * api.polygon.io 301-redirects there). The dividends path/auth live ONLY in poly_call()
 * (and the two consts below): REST path /v3/reference/dividends, key as the `apiKey`
 * query param. poly_call() also follows redirects, so either host works.
 */

require_once __DIR__ . '/prices.php';   // reuse prices_tracked_securities()

const POLY_BASE           = 'https://api.massive.com';
const POLY_DIVIDENDS_PATH = '/v3/reference/dividends';
const POLY_PAUSE          = 13;   // seconds between calls (free tier = 5 req/min)
const DIV_STALE_DAYS      = 7;    // re-pull a security's dividends at most ~weekly
const DIV_PER_SYMBOL      = 12;   // trailing dividends to keep per security (covers >2yr at quarterly)

/** Configured Polygon API key, or '' if the dividend feed is disabled. */
function dividends_api_key(): string
{
    $cfg = $GLOBALS['CONFIG']['polygon'] ?? null;
    return is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
}

/**
 * One Polygon GET. Returns the decoded body, or ['__error'=>reason] on a
 * transport/rate-limit/API error (callers check for '__error').
 */
function poly_call(string $path, array $params): array
{
    $params['apiKey'] = dividends_api_key();
    $url = POLY_BASE . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'budget-tracker/1.0',
        CURLOPT_FOLLOWLOCATION => true,   // api.polygon.io → api.massive.com 301
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false)       return ['__error' => 'curl: ' . $err];
    if ($code === 429)         return ['__error' => 'rate_limited'];
    $json = json_decode((string)$body, true);
    if (!is_array($json))      return ['__error' => 'bad_json (http ' . $code . ')'];
    // Polygon signals errors with {"status":"ERROR","error":"..."} (and HTTP 4xx).
    if (strtoupper((string)($json['status'] ?? '')) === 'ERROR' || $code >= 400) {
        return ['__error' => 'api: ' . ($json['error'] ?? ($json['message'] ?? ('http ' . $code)))];
    }
    return $json;
}

/** Idempotent upsert of one dividend record (keyed by security_id + ex_date). */
function dividends_upsert(PDO $pdo, string $securityId, array $row, string $source = 'polygon'): void
{
    static $st = null;
    if ($st === null) {
        $st = $pdo->prepare(
            "INSERT INTO security_dividends
               (security_id, ex_date, cash_amount, frequency, pay_date, record_date,
                declaration_date, currency, dividend_type, source)
             VALUES (:sid, :ex, :cash, :freq, :pay, :rec, :dec, :cur, :dtype, :src)
             ON DUPLICATE KEY UPDATE
               cash_amount      = VALUES(cash_amount),
               frequency        = VALUES(frequency),
               pay_date         = VALUES(pay_date),
               record_date      = VALUES(record_date),
               declaration_date = VALUES(declaration_date),
               currency         = VALUES(currency),
               dividend_type    = VALUES(dividend_type),
               source           = VALUES(source)"
        );
    }
    $st->execute([
        ':sid'   => $securityId,
        ':ex'    => $row['ex_date'],
        ':cash'  => $row['cash_amount'],
        ':freq'  => $row['frequency'],
        ':pay'   => $row['pay_date'] ?: null,
        ':rec'   => $row['record_date'] ?: null,
        ':dec'   => $row['declaration_date'] ?: null,
        ':cur'   => $row['currency'] ?: 'USD',
        ':dtype' => $row['dividend_type'] ?: null,
        ':src'   => $source,
    ]);
}

/**
 * Fetch the most recent dividends for one symbol and upsert each. Polygon returns
 * historical AND already-declared future rows in one call. Returns the count stored
 * or ['__error'=>..].
 */
function dividends_fetch_symbol(PDO $pdo, string $securityId, string $symbol): array
{
    $res = poly_call(POLY_DIVIDENDS_PATH, [
        'ticker' => $symbol,
        'order'  => 'desc',
        'sort'   => 'ex_dividend_date',
        'limit'  => DIV_PER_SYMBOL,
    ]);
    if (isset($res['__error'])) return $res;
    $results = $res['results'] ?? null;
    if (!is_array($results)) return ['__error' => 'no_results'];

    $n = 0;
    foreach ($results as $r) {
        $ex   = substr((string)($r['ex_dividend_date'] ?? ''), 0, 10);
        $cash = $r['cash_amount'] ?? null;
        if ($ex === '' || $cash === null || !is_numeric($cash)) continue;
        dividends_upsert($pdo, $securityId, [
            'ex_date'          => $ex,
            'cash_amount'      => (float)$cash,
            'frequency'        => isset($r['frequency']) && $r['frequency'] !== null ? (int)$r['frequency'] : null,
            'pay_date'         => substr((string)($r['pay_date'] ?? ''), 0, 10),
            'record_date'      => substr((string)($r['record_date'] ?? ''), 0, 10),
            'declaration_date' => substr((string)($r['declaration_date'] ?? ''), 0, 10),
            'currency'         => strtoupper((string)($r['currency'] ?? 'USD')) ?: 'USD',
            'dividend_type'    => (string)($r['dividend_type'] ?? ''),
        ]);
        $n++;
    }
    return ['stored' => $n];
}

/**
 * Staleness-gated refresh (called from the daily cron): only re-pull a security whose
 * newest cached row is older than DIV_STALE_DAYS (dividends change slowly), so the
 * nightly cost stays near zero and the 5/min free limit is never approached. Throttled
 * to the free-tier rate between actual calls. Returns a report. No-op without a key.
 */
function dividends_refresh_if_stale(PDO $pdo): array
{
    if (dividends_api_key() === '') return ['ok' => false, 'error' => 'no_key'];
    $tracked = prices_tracked_securities($pdo);   // [security_id => SYMBOL], CUR:% already excluded

    // Securities whose newest cached row is still within the staleness window. Computed
    // ENTIRELY in SQL (HAVING MAX(updated_at) >= NOW() - INTERVAL N DAY) so both sides
    // share the MySQL server clock — never compare a MySQL CURRENT_TIMESTAMP value against
    // a PHP date() string (the S24 TZ trap: server clock is EDT, app TZ is PDT). N is a
    // trusted int constant, inlined (no user input → no bind needed; cast guards anyway).
    $fresh = [];
    foreach ($pdo->query(
        "SELECT security_id FROM security_dividends
         GROUP BY security_id
         HAVING MAX(updated_at) >= NOW() - INTERVAL " . (int)DIV_STALE_DAYS . " DAY"
    )->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $fresh[$sid] = true;
    }

    $refreshed = 0; $skipped = 0; $stored = 0; $errors = []; $first = true;
    foreach ($tracked as $sid => $sym) {
        if (isset($fresh[$sid])) { $skipped++; continue; }
        if (!$first) sleep(POLY_PAUSE);
        $first = false;
        $r = dividends_fetch_symbol($pdo, $sid, $sym);
        if (isset($r['__error'])) { $errors[$sym] = $r['__error']; }
        else { $refreshed++; $stored += (int)$r['stored']; }
    }
    return ['ok' => true, 'symbols' => count($tracked), 'refreshed' => $refreshed,
            'skipped' => $skipped, 'stored' => $stored, 'errors' => $errors];
}
