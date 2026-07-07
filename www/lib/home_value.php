<?php
declare(strict_types=1);

/**
 * Home valuation (AVM) feed — fills `home_values` so the dashboard can show the
 * home's estimated worth against the linked mortgage balance (→ equity).
 *
 * Provider: RentCast (free tier — 50 requests/month, billed per request with an
 * OVERAGE FEE above that). The whole point of this file is the HARD CAP: every
 * outbound call goes through rentcast_call(), which RESERVES a slot in the
 * `api_usage` monthly counter BEFORE sending and REFUSES once the cap is reached.
 * We therefore can never exceed the free quota and never incur an overage charge.
 *
 * SAFE WITHOUT A KEY: if config 'rentcast.api_key' is empty, every entry point
 * returns ['ok'=>false,'error'=>'no_key'] and touches nothing.
 *
 * The HTTP layer is isolated in rentcast_call() so a different AVM provider is a
 * one-function swap (mirrors lib/prices.php / td_call()).
 */

const RENTCAST_BASE        = 'https://api.rentcast.io/v1';
const RENTCAST_MONTHLY_CAP = 50;   // free-tier quota — NEVER send more than this/month

/** Configured RentCast API key, or '' if the feed is disabled. */
function hv_api_key(): string
{
    $cfg = $GLOBALS['CONFIG']['rentcast'] ?? null;
    return is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
}

/** Current usage period as 'YYYY-MM' (app timezone). */
function hv_period(): string
{
    return date('Y-m');
}

/**
 * Usage for the current month: ['period','used','cap','remaining'].
 * Read-only — does not reserve anything.
 */
function hv_usage(PDO $pdo, int $cap = RENTCAST_MONTHLY_CAP): array
{
    $st = $pdo->prepare("SELECT request_count FROM api_usage WHERE provider='rentcast' AND period=:p");
    $st->execute([':p' => hv_period()]);
    $used = (int)($st->fetchColumn() ?: 0);
    return ['period' => hv_period(), 'used' => $used, 'cap' => $cap, 'remaining' => max(0, $cap - $used)];
}

/**
 * Atomically reserve ONE request slot for the current month.
 *
 * Returns true if a slot was reserved (caller may send the HTTP request) or false
 * if the monthly cap is already reached (caller must NOT send). Reserve-BEFORE-send
 * is deliberate: a slot is consumed even if the call then fails, which is what
 * guarantees we can never exceed the cap. (rentcast_call() refunds the slot only
 * when the request never reached RentCast — a pure transport failure isn't billed.)
 *
 * Concurrency-safe: the conditional UPDATE locks the row, so two simultaneous
 * callers are serialized and the count can't overshoot the cap.
 */
function hv_reserve_slot(PDO $pdo, int $cap = RENTCAST_MONTHLY_CAP): bool
{
    $period = hv_period();
    // Ensure the month's row exists (no-op once it does).
    $pdo->prepare("INSERT IGNORE INTO api_usage (provider, period, request_count) VALUES ('rentcast', :p, 0)")
        ->execute([':p' => $period]);
    // Increment ONLY while still under the cap. rowCount()===1 ⇒ we got a slot.
    $st = $pdo->prepare(
        "UPDATE api_usage SET request_count = request_count + 1
         WHERE provider='rentcast' AND period=:p AND request_count < :cap"
    );
    $st->execute([':p' => $period, ':cap' => $cap]);
    return $st->rowCount() === 1;
}

/** Refund a previously reserved slot (only for requests that never hit RentCast). */
function hv_release_slot(PDO $pdo): void
{
    $pdo->prepare(
        "UPDATE api_usage SET request_count = GREATEST(CAST(request_count AS SIGNED) - 1, 0)
         WHERE provider='rentcast' AND period=:p"
    )->execute([':p' => hv_period()]);
}

/**
 * Auth-backoff marker (code review 5.28). A rejected key (401/403) means RentCast did no
 * billable work, so we refund the slot AND set a per-month marker so we STOP retrying with a
 * key that can't work — otherwise a placeholder/revoked key would reserve-and-refund (and log)
 * on every page load. Reuses api_usage as a tiny per-period kv (provider='rentcast_authfail',
 * separate from the real 'rentcast' counter so it never pollutes hv_usage()). Logs once/period.
 */
function hv_mark_auth_backoff(PDO $pdo): void
{
    $st = $pdo->prepare("SELECT request_count FROM api_usage WHERE provider='rentcast_authfail' AND period=:p");
    $st->execute([':p' => hv_period()]);
    $already = (int)($st->fetchColumn() ?: 0) > 0;
    $pdo->prepare(
        "INSERT INTO api_usage (provider, period, request_count) VALUES ('rentcast_authfail', :p, 1)
         ON DUPLICATE KEY UPDATE request_count = 1"
    )->execute([':p' => hv_period()]);
    if (!$already) {
        error_log('RentCast rejected the API key (401/403) — backing off for ' . hv_period()
            . '. Fix rentcast.api_key to resume home valuations.');
    }
}

/** True if we've already seen a rejected key this month → skip further RentCast calls. */
function hv_auth_backoff_active(PDO $pdo): bool
{
    $st = $pdo->prepare("SELECT request_count FROM api_usage WHERE provider='rentcast_authfail' AND period=:p");
    $st->execute([':p' => hv_period()]);
    return (int)($st->fetchColumn() ?: 0) > 0;
}

/**
 * One RentCast GET, behind the monthly cap. Returns the decoded body, or
 * ['__error'=>reason]. Special error 'monthly_cap' means we refused to send.
 */
function rentcast_call(PDO $pdo, string $path, array $params): array
{
    if (hv_api_key() === '')          return ['__error' => 'no_key'];
    if (hv_auth_backoff_active($pdo)) return ['__error' => 'auth_backoff'];  // key rejected earlier this month
    if (!hv_reserve_slot($pdo))       return ['__error' => 'monthly_cap'];   // <-- the spend guard

    $url = RENTCAST_BASE . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'budget-tracker/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-Api-Key: ' . hv_api_key()],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {                 // never reached RentCast → not billed → refund the slot
        hv_release_slot($pdo);
        return ['__error' => 'curl: ' . $err];
    }
    if ($code === 429) {                   // rate-limited → not billed → refund the reserved slot
        hv_release_slot($pdo);
        return ['__error' => 'rate_limited (http 429)'];
    }
    if ($code === 401 || $code === 403) {  // key rejected → not billable work → refund + back off (5.28)
        hv_release_slot($pdo);
        hv_mark_auth_backoff($pdo);
        return ['__error' => 'auth http ' . $code . ' (key rejected — backing off this month)'];
    }
    if ($code >= 400)   return ['__error' => 'api http ' . $code . ': ' . substr((string)$body, 0, 200)];
    $json = json_decode((string)$body, true);
    if (!is_array($json)) return ['__error' => 'bad_json (http ' . $code . ')'];
    return $json;
}

/**
 * Fetch a value estimate for one address. $opts may carry propertyType, bedrooms,
 * bathrooms, squareFootage, compCount to sharpen the estimate. Returns a normalized
 * ['ok'=>true, value, value_low, value_high, comps, raw] or ['ok'=>false,'error'=>..].
 */
function home_value_estimate(PDO $pdo, string $address, array $opts = []): array
{
    $params = ['address' => $address];
    foreach (['propertyType', 'bedrooms', 'bathrooms', 'squareFootage', 'compCount'] as $k) {
        if (isset($opts[$k]) && $opts[$k] !== '') $params[$k] = $opts[$k];
    }
    $res = rentcast_call($pdo, 'avm/value', $params);
    if (isset($res['__error'])) return ['ok' => false, 'error' => $res['__error']];

    return [
        'ok'         => true,
        'value'      => isset($res['price'])          ? (float)$res['price']          : null,
        'value_low'  => isset($res['priceRangeLow'])  ? (float)$res['priceRangeLow']  : null,
        'value_high' => isset($res['priceRangeHigh']) ? (float)$res['priceRangeHigh'] : null,
        'comps'      => is_array($res['comparables'] ?? null) ? $res['comparables'] : [],
        'raw'        => $res,
    ];
}

/** Persist one valuation run into home_values. Returns the new row id. */
function home_value_store(PDO $pdo, string $address, array $est, ?string $accountId = null, string $source = 'rentcast'): int
{
    $st = $pdo->prepare(
        "INSERT INTO home_values (account_id, address, value, value_low, value_high, as_of, source, raw_json)
         VALUES (:acct, :addr, :val, :lo, :hi, :asof, :src, :raw)"
    );
    $st->execute([
        ':acct' => $accountId,
        ':addr' => $address,
        ':val'  => $est['value'],
        ':lo'   => $est['value_low'],
        ':hi'   => $est['value_high'],
        ':asof' => date('Y-m-d'),
        ':src'  => $source,
        ':raw'  => isset($est['raw']) ? json_encode($est['raw']) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

/** Most recent stored valuation for an address, or null. */
function home_value_latest(PDO $pdo, string $address): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM home_values WHERE address=:addr ORDER BY as_of DESC, id DESC LIMIT 1"
    );
    $st->execute([':addr' => $address]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Refresh a home's value ONLY if the latest stored valuation is older than
 * $maxAgeDays (default 25 → at most ~one call per address per month). This is the
 * cron entry point: it keeps automatic usage to ~1/month per address, well inside
 * the 50/month cap. Returns:
 *   ['ok'=>true,'skipped'=>'fresh', ...]   — recent enough, no call made
 *   ['ok'=>true,'stored'=>id, value...]    — fetched + stored
 *   ['ok'=>false,'error'=>..]              — no_key / monthly_cap / api error
 */
function home_value_refresh_if_stale(PDO $pdo, string $address, array $opts = [], ?string $accountId = null, int $maxAgeDays = 25): array
{
    if (hv_api_key() === '') return ['ok' => false, 'error' => 'no_key'];

    $latest = home_value_latest($pdo, $address);
    if ($latest) {
        $ageDays = (int)floor((time() - strtotime((string)$latest['as_of'])) / 86400);
        if ($ageDays < $maxAgeDays) {
            return ['ok' => true, 'skipped' => 'fresh', 'as_of' => $latest['as_of'], 'value' => (float)$latest['value']];
        }
    }

    $est = home_value_estimate($pdo, $address, $opts);
    if (empty($est['ok'])) return ['ok' => false, 'error' => $est['error']];

    $id = home_value_store($pdo, $address, $est, $accountId);
    return ['ok' => true, 'stored' => $id, 'value' => $est['value'],
            'value_low' => $est['value_low'], 'value_high' => $est['value_high']];
}

/* ===========================================================================
 * Property record + market data — same hard-capped rentcast_call() path, so
 * these also count toward the 50/month quota and can never cause an overage.
 * Property records change rarely (refresh ~quarterly); market data ~monthly.
 * ======================================================================== */

/** Best-effort 5-digit ZIP parsed from an address string (last 5-digit run). */
function hv_zip_from_address(string $address): string
{
    if (preg_match_all('/\b(\d{5})\b/', $address, $m) && $m[1]) {
        return end($m[1]);
    }
    return '';
}

/** First element if RentCast returned a list, else the value itself. */
function hv_first(array $res): array
{
    if ($res && array_keys($res) === range(0, count($res) - 1)) {
        return is_array($res[0] ?? null) ? $res[0] : [];
    }
    return $res;
}

/** Fetch + persist the property record for an address (RentCast /properties). */
function property_record_refresh_if_stale(PDO $pdo, string $address, int $maxAgeDays = 90): array
{
    if (hv_api_key() === '') return ['ok' => false, 'error' => 'no_key'];

    // Age computed in SQL so both sides share the MySQL clock — fetched_at defaults to the
    // server-clock CURRENT_TIMESTAMP (EDT), so comparing it to PHP time() (PDT) is the S24 trap.
    $st = $pdo->prepare("SELECT TIMESTAMPDIFF(DAY, fetched_at, NOW()) FROM property_facts WHERE address=:a");
    $st->execute([':a' => $address]);
    $age = $st->fetchColumn();   // false = no row; NULL = no fetched_at (shouldn't happen)
    if ($age !== false && $age !== null && (int)$age < $maxAgeDays) {
        return ['ok' => true, 'skipped' => 'fresh'];
    }

    $res = rentcast_call($pdo, 'properties', ['address' => $address]);
    if (isset($res['__error'])) return ['ok' => false, 'error' => $res['__error']];
    $p = hv_first($res);
    if (!$p) return ['ok' => false, 'error' => 'no_record'];

    // Most recent prior sale = "what you paid" (history is keyed by date string).
    $purchasePrice = $p['lastSalePrice'] ?? null;
    $purchaseDate  = isset($p['lastSaleDate']) ? substr((string)$p['lastSaleDate'], 0, 10) : null;
    $hoa = $p['hoa']['fee'] ?? null;

    $st = $pdo->prepare(
        "INSERT INTO property_facts
            (address, property_type, bedrooms, bathrooms, square_footage, lot_size,
             year_built, hoa_fee, purchase_price, purchase_date, raw_json)
         VALUES (:addr,:pt,:bd,:ba,:sf,:lot,:yr,:hoa,:pp,:pd,:raw)
         ON DUPLICATE KEY UPDATE
            property_type=VALUES(property_type), bedrooms=VALUES(bedrooms),
            bathrooms=VALUES(bathrooms), square_footage=VALUES(square_footage),
            lot_size=VALUES(lot_size), year_built=VALUES(year_built),
            hoa_fee=VALUES(hoa_fee), purchase_price=VALUES(purchase_price),
            purchase_date=VALUES(purchase_date), raw_json=VALUES(raw_json)"
    );
    $st->execute([
        ':addr' => $address,
        ':pt'   => $p['propertyType']   ?? null,
        ':bd'   => $p['bedrooms']        ?? null,
        ':ba'   => $p['bathrooms']       ?? null,
        ':sf'   => $p['squareFootage']   ?? null,
        ':lot'  => $p['lotSize']         ?? null,
        ':yr'   => $p['yearBuilt']       ?? null,
        ':hoa'  => $hoa,
        ':pp'   => $purchasePrice,
        ':pd'   => $purchaseDate,
        ':raw'  => json_encode($p),
    ]);
    return ['ok' => true, 'stored' => $address];
}

/** Fetch + persist zip-level market stats (RentCast /markets). */
function market_refresh_if_stale(PDO $pdo, string $zip, int $maxAgeDays = 25): array
{
    if (hv_api_key() === '') return ['ok' => false, 'error' => 'no_key'];
    if ($zip === '')         return ['ok' => false, 'error' => 'no_zip'];

    // Age computed in SQL so both sides share the MySQL clock (see property_facts above — S24 trap).
    $st = $pdo->prepare("SELECT TIMESTAMPDIFF(DAY, fetched_at, NOW()) FROM market_stats WHERE zip=:z");
    $st->execute([':z' => $zip]);
    $age = $st->fetchColumn();   // false = no row; NULL = no fetched_at (shouldn't happen)
    if ($age !== false && $age !== null && (int)$age < $maxAgeDays) {
        return ['ok' => true, 'skipped' => 'fresh'];
    }

    $res = rentcast_call($pdo, 'markets', ['zipCode' => $zip, 'dataType' => 'Sale', 'historyRange' => 12]);
    if (isset($res['__error'])) return ['ok' => false, 'error' => $res['__error']];

    $sale = $res['saleData'] ?? [];
    $st = $pdo->prepare(
        "INSERT INTO market_stats
            (zip, median_sale_price, median_price_per_sqft, median_days_on_market, raw_json)
         VALUES (:z,:mp,:ppsf,:dom,:raw)
         ON DUPLICATE KEY UPDATE
            median_sale_price=VALUES(median_sale_price),
            median_price_per_sqft=VALUES(median_price_per_sqft),
            median_days_on_market=VALUES(median_days_on_market),
            raw_json=VALUES(raw_json)"
    );
    $st->execute([
        ':z'    => $zip,
        ':mp'   => $sale['medianPrice']           ?? null,
        ':ppsf' => $sale['medianPricePerSquareFoot'] ?? null,
        ':dom'  => $sale['medianDaysOnMarket']    ?? null,
        ':raw'  => json_encode($res),
    ]);
    return ['ok' => true, 'stored' => $zip];
}
