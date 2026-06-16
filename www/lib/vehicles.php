<?php
declare(strict_types=1);

/**
 * Vehicle / other-asset valuation (TODO2 #40).
 *
 * A vehicle is a MANUAL account (items.manual_type='vehicle', accounts.type='vehicle')
 * so its accounts.balance_current counts in net worth automatically — like a manual
 * 401(k). This file owns:
 *   1. the depreciation MODEL (pure math) that turns the owner-set basis into today's
 *      value — `vehicle_depreciated_value()`,
 *   2. the free **NHTSA vPIC** VIN decode (year/make/model/trim — no key, no quota) —
 *      `vehicle_vin_decode()`,
 *   3. writing that modelled value into accounts.balance_current on save + nightly —
 *      `vehicle_save_balance()` / `vehicle_revalue_all()`.
 *
 * ⚠️ HONEST-NUMBER stance: there is NO reliably-free vehicle VALUATION feed (KBB / Black
 * Book are paid), so the value is a transparent owner-set depreciation estimate, NOT a
 * market quote. The owner may enter a `manual_value` (e.g. a real quote they looked up)
 * which WINS and stops the modelled depreciation. The page frames it as an estimate.
 *
 * Pure-ish (the math + decode have no DB dependency); the two write helpers take $pdo.
 * Self-contained — no require of queries.php (so the cron can include it cheaply).
 */

const VEHICLE_DEFAULT_RATE = 15.0;   // default annual depreciation, % per year (declining-balance)
const VEHICLE_MAX_RATE     = 60.0;   // clamp owner input to a sane ceiling
const NHTSA_VIN_URL        = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/%s?format=json';

/**
 * Today's modelled value for one vehicle_assets row (or null when there's not enough to
 * model — no manual override AND no purchase price/date). The result is what gets written
 * to accounts.balance_current and therefore counts in net worth.
 *
 *  · manual_value set → that value WINS (a point-in-time override; no further depreciation).
 *  · else: declining-balance  value = price · (1 − rate)^years
 *          straight-line       value = price · max(0, 1 − rate · years)
 * Floored at 0; `years` clamped ≥ 0 (a future purchase date reads as today = full price).
 */
function vehicle_depreciated_value(array $va, ?string $today = null): ?float
{
    // Manual override wins and freezes the value.
    $mv = $va['manual_value'] ?? null;
    if ($mv !== null && $mv !== '') {
        $mvf = (float)$mv;
        return $mvf > 0 ? round($mvf, 2) : 0.0;
    }

    $pp = $va['purchase_price'] ?? null;
    $pd = $va['purchase_date'] ?? null;
    if ($pp === null || $pp === '' || (float)$pp <= 0 || $pd === null || $pd === '') {
        return null;   // not enough basis to model a value
    }
    $price = (float)$pp;

    $today = $today ?? date('Y-m-d');
    $ts0 = strtotime((string)$pd);
    $ts1 = strtotime($today);
    if ($ts0 === false || $ts1 === false) return round($price, 2);
    $years = ($ts1 - $ts0) / (365.25 * 86400.0);
    if ($years < 0) $years = 0.0;   // future-dated purchase → no depreciation yet

    $rate = $va['annual_rate'] ?? VEHICLE_DEFAULT_RATE;
    $rate = is_numeric($rate) && is_finite((float)$rate) ? (float)$rate : VEHICLE_DEFAULT_RATE;
    $rate = max(0.0, min(VEHICLE_MAX_RATE, $rate)) / 100.0;   // → fraction/yr

    $method = ($va['depreciation_method'] ?? 'declining') === 'straight' ? 'straight' : 'declining';
    if ($method === 'straight') {
        $v = $price * max(0.0, 1.0 - $rate * $years);
    } else {
        $v = $price * pow(max(0.0, 1.0 - $rate), $years);
    }
    if (!is_finite($v) || $v < 0) $v = 0.0;
    return round($v, 2);
}

/** Normalise a VIN: uppercase, strip non-alphanumerics. */
function vehicle_clean_vin(string $vin): string
{
    return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $vin));
}

/**
 * Decode a VIN via the FREE NHTSA vPIC service (no key, no quota). Returns
 *   ['ok'=>true, 'vin','year','make','model','trim','body_class','note']  on success
 *   ['ok'=>false,'error'=>…]                                              on failure.
 * Never throws — a transport/parse failure is a soft error the form falls back from
 * (the owner can always type the details manually). Decode is best-effort: a VIN that
 * decodes only partially still returns whatever fields NHTSA gave.
 */
function vehicle_vin_decode(string $vin): array
{
    $vin = vehicle_clean_vin($vin);
    $len = strlen($vin);
    if ($len < 11 || $len > 17) {
        return ['ok' => false, 'error' => 'Enter a valid VIN (11–17 letters/numbers).'];
    }

    $ch = curl_init(sprintf(NHTSA_VIN_URL, rawurlencode($vin)));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'budget-tracker/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false)  return ['ok' => false, 'error' => 'Could not reach the VIN service: ' . $err];
    if ($code >= 400)     return ['ok' => false, 'error' => 'The VIN service returned HTTP ' . $code . '.'];
    $json = json_decode((string)$body, true);
    $r = (is_array($json) && !empty($json['Results'][0]) && is_array($json['Results'][0]))
        ? $json['Results'][0] : null;
    if ($r === null)      return ['ok' => false, 'error' => 'The VIN service returned no result.'];

    $year       = preg_replace('/\D/', '', (string)($r['ModelYear'] ?? ''));
    $make       = trim((string)($r['Make'] ?? ''));
    $model      = trim((string)($r['Model'] ?? ''));
    $trim       = trim((string)($r['Trim'] ?? ''));
    if ($trim === '') $trim = trim((string)($r['Series'] ?? ''));
    $bodyClass  = trim((string)($r['BodyClass'] ?? ''));
    $note       = trim((string)($r['ErrorText'] ?? ''));

    // Title-case an ALL-CAPS make (FORD → Ford); leave model/trim as returned (e.g. F-150).
    if ($make !== '' && $make === strtoupper($make)) $make = ucwords(strtolower($make));

    if ($make === '' && $model === '' && $year === '') {
        return ['ok' => false,
                'error' => 'Could not decode that VIN' . ($note !== '' ? ' (' . $note . ')' : '')
                         . '. Enter the details manually.'];
    }

    return [
        'ok'         => true,
        'vin'        => $vin,
        'year'       => $year !== '' ? (int)$year : null,
        'make'       => $make !== '' ? $make : null,
        'model'      => $model !== '' ? $model : null,
        'trim'       => $trim !== '' ? $trim : null,
        'body_class' => $bodyClass !== '' ? $bodyClass : null,
        'note'       => $note,
    ];
}

/**
 * Recompute one vehicle's modelled value and write it to accounts.balance_current.
 * Returns the value, or null if the row is missing / not enough basis to model
 * (in which case balance_current is left untouched). Called on save + by the cron.
 */
function vehicle_save_balance(PDO $pdo, string $accountId): ?float
{
    $st = $pdo->prepare('SELECT * FROM vehicle_assets WHERE account_id = ?');
    $st->execute([$accountId]);
    $va = $st->fetch(PDO::FETCH_ASSOC);
    if (!$va) return null;

    $val = vehicle_depreciated_value($va);
    if ($val === null) return null;

    $pdo->prepare('UPDATE accounts SET balance_current = ? WHERE account_id = ?')
        ->execute([$val, $accountId]);
    return $val;
}

/**
 * Re-age every vehicle's modelled value into accounts.balance_current (nightly cron),
 * so the depreciation curve advances day by day and the net-worth snapshot / balance
 * history pick it up. Idempotent — a manual_value row simply re-writes the same value.
 * Returns the number of accounts updated.
 */
function vehicle_revalue_all(PDO $pdo): int
{
    $rows = $pdo->query('SELECT * FROM vehicle_assets')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $upd = $pdo->prepare('UPDATE accounts SET balance_current = ? WHERE account_id = ?');
    $n = 0;
    foreach ($rows as $va) {
        $val = vehicle_depreciated_value($va);
        if ($val === null) continue;
        $upd->execute([$val, (string)$va['account_id']]);
        $n++;
    }
    return $n;
}
