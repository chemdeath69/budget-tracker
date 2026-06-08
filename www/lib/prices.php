<?php
declare(strict_types=1);

/**
 * Security price feed — fills `security_prices` (one close per security per day)
 * so the Investments page can show change-over-time and the day/week/month/year
 * change icons.
 *
 * Provider: Twelve Data (free tier — 800 credits/day, 8 req/min). The HTTP layer
 * is isolated in td_call() so a different provider is a one-function swap.
 *
 * SAFE WITHOUT A KEY: if config 'twelvedata.api_key' is empty, every entry point
 * returns ['ok'=>false,'error'=>'no_key'] and touches nothing — so this can ship
 * before the key exists. Scope: US stocks & ETFs (bare ticker symbols).
 */

const TD_BASE  = 'https://api.twelvedata.com';
const TD_PAUSE = 8;     // seconds between calls (free tier = 8 req/min)

/** Configured Twelve Data API key, or '' if the price feed is disabled. */
function prices_api_key(): string
{
    $cfg = $GLOBALS['CONFIG']['twelvedata'] ?? null;
    return is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
}

/**
 * One Twelve Data GET. Returns the decoded body, or ['__error'=>reason] on a
 * transport/rate-limit/API error (callers check for '__error'/status).
 */
function td_call(string $path, array $params): array
{
    $params['apikey'] = prices_api_key();
    $url = TD_BASE . '/' . ltrim($path, '/') . '?' . http_build_query($params);
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

    if ($body === false)       return ['__error' => 'curl: ' . $err];
    if ($code === 429)         return ['__error' => 'rate_limited'];
    $json = json_decode((string)$body, true);
    if (!is_array($json))      return ['__error' => 'bad_json (http ' . $code . ')'];
    // Twelve Data signals API errors with {"status":"error","code":..,"message":..}.
    if (($json['status'] ?? '') === 'error' || (isset($json['code']) && (int)$json['code'] >= 400)) {
        return ['__error' => 'api: ' . ($json['message'] ?? ('code ' . ($json['code'] ?? '?')))];
    }
    return $json;
}

/**
 * Held securities that have a usable ticker, as [security_id => SYMBOL].
 * Only securities currently in `holdings` are tracked (no point pricing the rest).
 */
function prices_tracked_securities(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT DISTINCT s.security_id, s.ticker_symbol
         FROM holdings h JOIN securities s ON h.security_id = s.security_id
         WHERE s.ticker_symbol IS NOT NULL AND s.ticker_symbol <> ''"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['security_id']] = strtoupper(trim((string)$r['ticker_symbol']));
    return $out;
}

/** Idempotent upsert of one daily close. */
function prices_upsert(PDO $pdo, string $securityId, string $date, float $close, string $source = 'twelvedata'): void
{
    static $st = null;
    if ($st === null) {
        $st = $pdo->prepare(
            "INSERT INTO security_prices (security_id, price_date, close, source)
             VALUES (:sid, :d, :c, :src)
             ON DUPLICATE KEY UPDATE close = VALUES(close), source = VALUES(source)"
        );
    }
    $st->execute([':sid' => $securityId, ':d' => $date, ':c' => $close, ':src' => $source]);
}

/**
 * Fetch a daily time series for one symbol and upsert every row. $outputsize is
 * the number of trailing daily bars (max 5000 on free). Returns the count stored
 * or ['__error'=>..].
 */
function prices_fetch_symbol(PDO $pdo, string $securityId, string $symbol, int $outputsize): array
{
    $res = td_call('time_series', [
        'symbol'     => $symbol,
        'interval'   => '1day',
        'outputsize' => $outputsize,
        'order'      => 'ASC',
        'timezone'   => 'America/New_York',
    ]);
    if (isset($res['__error'])) return $res;
    $values = $res['values'] ?? null;
    if (!is_array($values)) return ['__error' => 'no_values'];

    $n = 0;
    foreach ($values as $bar) {
        $date  = substr((string)($bar['datetime'] ?? ''), 0, 10);
        $close = $bar['close'] ?? null;
        if ($date === '' || $close === null || !is_numeric($close)) continue;
        prices_upsert($pdo, $securityId, $date, (float)$close);
        $n++;
    }
    return ['stored' => $n];
}

/**
 * One-time (or occasional) history backfill — ~2 years of daily closes for every
 * tracked security. Throttled to the free-tier rate. Returns a per-symbol report.
 */
function prices_backfill(PDO $pdo, int $outputsize = 520): array
{
    if (prices_api_key() === '') return ['ok' => false, 'error' => 'no_key'];
    $tracked = prices_tracked_securities($pdo);
    $report = []; $first = true;
    foreach ($tracked as $sid => $sym) {
        if (!$first) sleep(TD_PAUSE);
        $first = false;
        $r = prices_fetch_symbol($pdo, $sid, $sym, $outputsize);
        $report[$sym] = $r['__error'] ?? ('stored ' . $r['stored']);
    }
    return ['ok' => true, 'symbols' => count($tracked), 'report' => $report];
}

/**
 * Daily refresh — pull just the last few bars per security to capture the newest
 * close (cheap; called from the daily cron). Same upsert, small outputsize.
 */
function prices_refresh_latest(PDO $pdo): array
{
    if (prices_api_key() === '') return ['ok' => false, 'error' => 'no_key'];
    $tracked = prices_tracked_securities($pdo);
    $updated = 0; $errors = []; $first = true;
    foreach ($tracked as $sid => $sym) {
        if (!$first) sleep(TD_PAUSE);
        $first = false;
        $r = prices_fetch_symbol($pdo, $sid, $sym, 5);
        if (isset($r['__error'])) $errors[$sym] = $r['__error'];
        else $updated += (int)$r['stored'];
    }
    return ['ok' => true, 'symbols' => count($tracked), 'updated' => $updated, 'errors' => $errors];
}
