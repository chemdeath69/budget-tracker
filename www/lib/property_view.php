<?php
declare(strict_types=1);

/**
 * Assembles everything the Property page (property.php) shows, combining the Plaid
 * mortgage detail (q_mortgage), the RentCast value/property/market data, and the
 * balance/value history — plus derived metrics (equity, LTV, appreciation) and an
 * amortization model computed from the loan's origination terms.
 *
 * Pure read+derive: it calls the q_*() helpers in queries.php (which apply the
 * visibility rule) and does no writes. Returns a single nested array, or null if
 * there's nothing to show (no mortgage and no valuation).
 */

/** Months between two 'YYYY-MM-DD' dates (b - a), or 0. */
function pv_months_between(?string $a, ?string $b): int
{
    if (!$a || !$b) return 0;
    $ta = strtotime($a); $tb = strtotime($b);
    if ($ta === false || $tb === false) return 0;
    $ya = (int)date('Y', $ta); $ma = (int)date('n', $ta);
    $yb = (int)date('Y', $tb); $mb = (int)date('n', $tb);
    return max(0, ($yb - $ya) * 12 + ($mb - $ma));
}

/**
 * Standard fixed-rate amortization. Returns the P&I payment, the month-by-month
 * remaining balance (index 0..n) and the total interest over the loan's life.
 */
function pv_amortize(float $P, float $annualPct, int $n): array
{
    if ($P <= 0 || $n <= 0) return ['payment' => 0.0, 'monthly' => [$P], 'total_interest' => 0.0];
    $r = ($annualPct / 100) / 12;
    $monthly = [$P]; $bal = $P; $totInt = 0.0;
    if ($r <= 0) {
        $M = $P / $n;
        for ($i = 1; $i <= $n; $i++) { $bal = max(0.0, $bal - $M); $monthly[] = $bal; }
        return ['payment' => $M, 'monthly' => $monthly, 'total_interest' => 0.0];
    }
    $M = $P * $r / (1 - pow(1 + $r, -$n));
    for ($i = 1; $i <= $n; $i++) {
        $int = $bal * $r; $prin = $M - $int;
        $bal = max(0.0, $bal - $prin); $totInt += $int;
        $monthly[] = $bal;
    }
    return ['payment' => $M, 'monthly' => $monthly, 'total_interest' => $totInt];
}

/** Year part of a 'YYYY-..' date string. */
function pv_year(?string $d): ?int
{
    if (!$d) return null;
    $t = strtotime($d);
    return $t ? (int)date('Y', $t) : null;
}

function build_property_view(PDO $pdo, int $uid): ?array
{
    $address = trim((string)($GLOBALS['CONFIG']['home']['address'] ?? ''));
    $mort    = q_mortgage($pdo, $uid);
    $valRows = $address !== '' ? q_value_history($pdo, $address) : [];
    $facts   = $address !== '' ? q_property_facts($pdo, $address) : null;
    $zip     = function_exists('hv_zip_from_address') ? hv_zip_from_address($address) : '';
    $market  = $zip !== '' ? q_market_stats($pdo, $zip) : null;

    $latestVal = $valRows ? (float)end($valRows)['value'] : null;
    $valRowLast = $valRows ? end($valRows) : null;
    if (!$mort && $latestVal === null) return null;

    $now = time();

    // ---- Mortgage + amortization --------------------------------------------
    $M = null;
    if ($mort) {
        $raw  = $mort['raw'];
        $rate = $raw['interest_rate']['percentage'] ?? ($mort['liab']['apr_percentage'] ?? null);
        $origDate = isset($raw['origination_date']) ? substr((string)$raw['origination_date'], 0, 10) : null;
        $matDate  = isset($raw['maturity_date'])    ? substr((string)$raw['maturity_date'], 0, 10)    : null;
        $origPrin = (float)($raw['origination_principal_amount'] ?? $mort['liab']['origination_principal'] ?? 0);

        // Loan term in months: prefer origination→maturity span, else parse "30 year".
        $n = pv_months_between($origDate, $matDate);
        if ($n <= 0 && !empty($raw['loan_term']) && preg_match('/(\d+)/', (string)$raw['loan_term'], $mm)) {
            $n = (int)$mm[1] * 12;
        }
        if ($n <= 0) $n = 360;

        $amort = pv_amortize($origPrin, (float)($rate ?? 0), $n);
        $monthsElapsed = $origDate ? min($n, pv_months_between($origDate, date('Y-m-d', $now))) : 0;
        $interestToDate = 0.0;
        if ($origPrin > 0 && ($rate ?? 0) > 0) {
            // cumulative interest through monthsElapsed
            $r = ((float)$rate / 100) / 12; $bal = $origPrin;
            for ($i = 0; $i < $monthsElapsed; $i++) { $int = $bal * $r; $interestToDate += $int; $bal = max(0.0, $bal - ($amort['payment'] - $int)); }
        }
        $payoffDate = $matDate ?: ($origDate ? date('Y-m-d', strtotime("$origDate +$n months")) : null);
        $pctPaid = $origPrin > 0 ? max(0.0, min(1.0, ($origPrin - $mort['balance']) / $origPrin)) : null;

        $M = [
            'name'            => $mort['account']['name'] ?: 'Mortgage',
            'owner_id'        => $mort['account']['owner_id'] ?? null,
            'balance'         => $mort['balance'],
            'rate'            => $rate !== null ? (float)$rate : null,
            'rate_type'       => $raw['interest_rate']['type'] ?? null,
            'loan_type'       => $raw['loan_type_description'] ?? null,
            'loan_term'       => $raw['loan_term'] ?? null,
            'origination_date'=> $origDate,
            'origination_principal' => $origPrin ?: null,
            'maturity_date'   => $matDate,
            'payoff_date'     => $payoffDate,
            'next_due_date'   => $raw['next_payment_due_date'] ?? ($mort['liab']['next_payment_due_date'] ?? null),
            'next_payment'    => $raw['next_monthly_payment'] ?? null,
            'last_payment_amount' => $raw['last_payment_amount'] ?? ($mort['liab']['last_payment_amount'] ?? null),
            'last_payment_date'   => $raw['last_payment_date'] ?? ($mort['liab']['last_payment_date'] ?? null),
            'ytd_interest'    => $raw['ytd_interest_paid']  ?? null,
            'ytd_principal'   => $raw['ytd_principal_paid'] ?? null,
            'escrow'          => $raw['escrow_balance']     ?? null,
            'has_pmi'         => $raw['has_pmi']            ?? null,
            'has_prepay'      => $raw['has_prepayment_penalty'] ?? null,
            'past_due'        => $raw['past_due_amount']    ?? null,
            'payment_pi'      => $amort['payment'] ?: null,
            'total_interest_life' => $amort['total_interest'] ?: null,
            'interest_to_date'=> $interestToDate ?: null,
            'pct_paid_off'    => $pctPaid,
            '_amort'          => $amort,   // internal: schedule for charts
            '_n'              => $n,
            '_orig_date'      => $origDate,
            '_orig_prin'      => $origPrin,
        ];
    }

    // ---- Derived metrics -----------------------------------------------------
    $derived = [];
    if ($latestVal !== null) {
        $bal = $M['balance'] ?? null;
        $derived['equity']     = $bal !== null ? round($latestVal - $bal, 2) : null;
        $derived['equity_pct'] = $bal !== null && $latestVal > 0 ? ($latestVal - $bal) / $latestVal : null;
        $derived['ltv']        = $bal !== null && $latestVal > 0 ? $bal / $latestVal : null;
        $pp = $facts['purchase_price'] ?? null;
        $pd = $facts['purchase_date'] ?? null;
        if ($pp) {
            $derived['appreciation']     = round($latestVal - (float)$pp, 2);
            $derived['appreciation_pct'] = (float)$pp > 0 ? ($latestVal - (float)$pp) / (float)$pp : null;
            $yrs = $pd ? max(0.1, ($now - strtotime((string)$pd)) / (365.25 * 86400)) : null;
            $derived['years_owned'] = $yrs;
            $derived['appreciation_annual_pct'] = ($yrs && (float)$pp > 0) ? pow($latestVal / (float)$pp, 1 / $yrs) - 1 : null;
        }
    }

    // ---- Chart series --------------------------------------------------------
    $charts = [];

    // Value over time (+ purchase anchor + low/high band).
    if ($valRows || ($facts['purchase_price'] ?? null)) {
        $labels = []; $est = []; $low = []; $high = [];
        if (($facts['purchase_price'] ?? null) && ($facts['purchase_date'] ?? null)) {
            $labels[] = substr((string)$facts['purchase_date'], 0, 7);
            $est[] = (float)$facts['purchase_price']; $low[] = null; $high[] = null;
        }
        foreach ($valRows as $v) {
            $labels[] = substr((string)$v['as_of'], 0, 7);
            $est[]  = (float)$v['value'];
            $low[]  = $v['value_low']  !== null ? (float)$v['value_low']  : null;
            $high[] = $v['value_high'] !== null ? (float)$v['value_high'] : null;
        }
        $charts['value'] = ['labels' => $labels, 'est' => $est, 'low' => $low, 'high' => $high];
    }

    // Mortgage payoff: projected lifetime curve (yearly) + actual anchors.
    if ($M && $M['_orig_date'] && $M['_orig_prin'] > 0) {
        $oy = pv_year($M['_orig_date']); $py = pv_year($M['payoff_date']) ?: ($oy + (int)ceil($M['_n'] / 12));
        $labels = []; $proj = []; $actual = [];
        $hist = q_account_balance_history($pdo, $mort['account']['account_id']);
        $actualByYear = [(string)$oy => $M['_orig_prin']];                 // origination anchor
        foreach ($hist as $h) { $actualByYear[(string)pv_year((string)$h['snapshot_date'])] = (float)$h['balance']; }
        $actualByYear[(string)(int)date('Y', $now)] = $M['balance'];        // today
        for ($y = $oy; $y <= $py; $y++) {
            $labels[] = (string)$y;
            $idx = min($M['_n'], ($y - $oy) * 12);
            $proj[] = round($M['_amort']['monthly'][$idx] ?? 0, 2);
            $actual[] = $actualByYear[(string)$y] ?? null;
        }
        $charts['payoff'] = ['labels' => $labels, 'projected' => $proj, 'actual' => $actual];

        // Amortization: cumulative principal vs interest (yearly).
        $labels = []; $cumP = []; $cumI = [];
        $r = (($M['rate'] ?? 0) / 100) / 12; $bal = $M['_orig_prin']; $ci = 0.0;
        for ($mo = 0; $mo <= $M['_n']; $mo++) {
            if ($mo % 12 === 0) {
                $labels[] = (string)($oy + intdiv($mo, 12));
                $cumP[] = round($M['_orig_prin'] - $bal, 2);
                $cumI[] = round($ci, 2);
            }
            if ($mo < $M['_n'] && $r > 0) { $int = $bal * $r; $ci += $int; $bal = max(0.0, $bal - ($M['payment_pi'] - $int)); }
        }
        $charts['amort'] = ['labels' => $labels, 'principal' => $cumP, 'interest' => $cumI];
    }

    // Equity over time: aligned to value dates; debt = projected (or current today).
    if (!empty($charts['value']) && $M) {
        $labels = $charts['value']['labels']; $vals = $charts['value']['est'];
        $valueLine = []; $debtLine = []; $equityLine = [];
        $lastIdx = count($labels) - 1;
        foreach ($labels as $i => $lab) {
            $d = $lab . '-01';
            if ($i === $lastIdx) {
                $debt = $M['balance'];
            } elseif ($M['_orig_date'] && $M['_orig_prin'] > 0) {
                $idx = min($M['_n'], pv_months_between($M['_orig_date'], $d));
                $debt = $M['_amort']['monthly'][$idx] ?? $M['balance'];
            } else {
                $debt = $M['balance'];
            }
            $valueLine[] = $vals[$i];
            $debtLine[]  = round($debt, 2);
            $equityLine[] = round($vals[$i] - $debt, 2);
        }
        $charts['equity'] = ['labels' => $labels, 'value' => $valueLine, 'debt' => $debtLine, 'equity' => $equityLine];
    }

    // Assessed value + property tax history (from the property record raw).
    $assessments = []; $taxes = [];
    if ($facts && is_array($facts['raw'] ?? null)) {
        foreach (($facts['raw']['taxAssessments'] ?? []) as $yr => $a) {
            if (isset($a['value'])) $assessments[(string)$yr] = (float)$a['value'];
        }
        foreach (($facts['raw']['propertyTaxes'] ?? []) as $yr => $t) {
            if (isset($t['total'])) $taxes[(string)$yr] = (float)$t['total'];
        }
        ksort($assessments); ksort($taxes);
    }
    if ($assessments) $charts['assessed'] = ['labels' => array_keys($assessments), 'values' => array_values($assessments)];
    if ($taxes)       $charts['tax']      = ['labels' => array_keys($taxes), 'values' => array_values($taxes)];

    // Market median-price trend (from market raw saleData.history).
    if ($market && is_array($market['raw'] ?? null)) {
        $hist = $market['raw']['saleData']['history'] ?? [];
        if (is_array($hist) && $hist) {
            ksort($hist);
            $labels = []; $values = [];
            foreach ($hist as $k => $h) {
                $mp = $h['medianPrice'] ?? null;
                if ($mp !== null) { $labels[] = substr((string)$k, 0, 7); $values[] = (float)$mp; }
            }
            if ($values) $charts['market'] = ['labels' => $labels, 'values' => $values];
        }
    }

    // Features list (from property record raw).
    $features = [];
    if ($facts && is_array($facts['raw']['features'] ?? null)) {
        $f = $facts['raw']['features'];
        $flag = fn($k, $label) => !empty($f[$k]) ? $label : null;
        $features = array_values(array_filter([
            $flag('garage', 'Garage' . (isset($f['garageSpaces']) ? " ({$f['garageSpaces']})" : '')),
            $flag('pool', 'Pool'),
            !empty($f['heating']) ? ('Heating' . (isset($f['heatingType']) ? ": {$f['heatingType']}" : '')) : null,
            !empty($f['cooling']) ? ('Cooling' . (isset($f['coolingType']) ? ": {$f['coolingType']}" : '')) : null,
            isset($f['floorCount']) ? "{$f['floorCount']} floor(s)" : null,
            isset($f['roofType']) ? "Roof: {$f['roofType']}" : null,
            isset($f['exteriorType']) ? "Exterior: {$f['exteriorType']}" : null,
            isset($f['architectureType']) ? "Style: {$f['architectureType']}" : null,
        ]));
    }

    return [
        'address'  => $address,
        'value'    => $latestVal !== null ? [
            'current' => $latestVal,
            'low'     => $valRowLast && $valRowLast['value_low']  !== null ? (float)$valRowLast['value_low']  : null,
            'high'    => $valRowLast && $valRowLast['value_high'] !== null ? (float)$valRowLast['value_high'] : null,
            'as_of'   => $valRowLast ? (string)$valRowLast['as_of'] : null,
        ] : null,
        'mortgage' => $M,
        'property' => $facts ? [
            'purchase_price' => $facts['purchase_price'] !== null ? (float)$facts['purchase_price'] : null,
            'purchase_date'  => $facts['purchase_date'],
            'hoa_fee'        => $facts['hoa_fee'] !== null ? (float)$facts['hoa_fee'] : null,
            'beds'           => $facts['bedrooms'],
            'baths'          => $facts['bathrooms'],
            'sqft'           => $facts['square_footage'],
            'lot'            => $facts['lot_size'],
            'year_built'     => $facts['year_built'],
            'type'           => $facts['property_type'],
            'owner_occupied' => $facts['raw']['ownerOccupied'] ?? null,
            'features'       => $features,
        ] : null,
        'market'   => $market ? [
            'zip'          => $market['zip'],
            'median_price' => $market['median_sale_price'] !== null ? (float)$market['median_sale_price'] : null,
            'ppsf'         => $market['median_price_per_sqft'] !== null ? (float)$market['median_price_per_sqft'] : null,
            'dom'          => $market['median_days_on_market'] !== null ? (float)$market['median_days_on_market'] : null,
        ] : null,
        'derived'  => $derived,
        'charts'   => $charts,
    ];
}
