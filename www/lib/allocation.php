<?php
declare(strict_types=1);
/**
 * Allocation vs target + drift — PURE derive assembler (Session 62, TODO2 #32).
 *
 * No DB of its own: the page hands in the already-VIS-scoped holdings (q_holdings — WHOLE
 * portfolio, investments + retirement), the household target mix (q_allocation_targets), and
 * the per-security override map (q_security_asset_classes). Like lib/bills.php / lib/forecast.php.
 *
 * Each holding is bucketed into one of six fixed ASSET CLASSES. The default class is derived
 * from Plaid's coarse `securities.type` (which lumps EVERY ETF/mutual fund together regardless
 * of whether it holds stocks or bonds), so the owner can OVERRIDE a security into the right
 * bucket (e.g. a bond ETF → Bonds, a REIT ETF → Real estate, a crypto ETF → Crypto). Allocation
 * is HOLDINGS-based (it inherits the existing allocation doughnut's scope) — uninvested cash in a
 * brokerage's total balance isn't counted; the page states that caveat.
 */

/** Ordered fixed taxonomy: key => display label. Keep the order — it drives the display + editor. */
const ALLOC_CLASSES = [
    'stocks'      => 'Stocks',
    'bonds'       => 'Bonds',
    'cash'        => 'Cash',
    'crypto'      => 'Crypto',
    'real_estate' => 'Real estate',
    'other'       => 'Other',
];

/** A $ drift smaller than this is treated as "on target" (no rebalance hint). */
const ALLOC_DRIFT_FLOOR = 100.0;

function alloc_valid_class(?string $key): bool
{
    return $key !== null && isset(ALLOC_CLASSES[$key]);
}

function alloc_class_label(?string $key): string
{
    return ALLOC_CLASSES[$key] ?? ALLOC_CLASSES['other'];
}

/**
 * Plaid `securities.type` → default asset-class key. ETF / mutual fund default to Stocks
 * (most are equity); the per-security override is exactly what handles the exceptions.
 */
function alloc_default_class(?string $securityType): string
{
    $t = strtolower(trim((string)$securityType));
    switch ($t) {
        case 'equity':
        case 'etf':
        case 'mutual fund':
        case 'etn':
            return 'stocks';
        case 'fixed income':
            return 'bonds';
        case 'cash':
        case 'money market':
            return 'cash';
        case 'cryptocurrency':
            return 'crypto';
        default: // derivative, loan, other, '', null → unclassifiable
            return 'other';
    }
}

/**
 * Resolve a single holding's effective class + whether it came from an override.
 * $overrides: [security_id => class]. $h: a q_holdings row (security_id, security_type).
 * @return array{class:string, source:'override'|'auto'}
 */
function alloc_resolve_class(array $h, array $overrides): array
{
    $sid = $h['security_id'] ?? null;
    if ($sid !== null && isset($overrides[$sid]) && alloc_valid_class($overrides[$sid])) {
        return ['class' => $overrides[$sid], 'source' => 'override'];
    }
    return ['class' => alloc_default_class($h['security_type'] ?? null), 'source' => 'auto'];
}

/**
 * Build the allocation-vs-target view.
 *
 *   $holds     — q_holdings() rows (whole portfolio; each has security_id/security_type/
 *                institution_value + a display label via ticker_symbol/security_name).
 *   $targets   — [class => target_pct] from q_allocation_targets() (a class absent → treated as
 *                0% when ANY target is set, i.e. "you want none of this").
 *   $overrides — [security_id => class] from q_security_asset_classes().
 *
 * Returns:
 *   total        — Σ holding market value (> 0 holdings)
 *   has_targets  — any target set
 *   target_sum   — Σ of the set target_pct (should be 100; a warning shows otherwise)
 *   classes      — per-class rows (only classes with actual value OR a target), ordered by
 *                  ALLOC_CLASSES, each: {key,label,actual_val,actual_pct,target_pct,target_val,
 *                                        drift_val(actual−target),drift_pct,has_target}
 *   sells/buys   — rebalance hints (overweight classes to trim / underweight to add), sorted by $
 *   max_drift_val— the single largest |drift_val| (0 when on target)
 *   holdings     — per-holding breakdown rows {security_id,label,value,pct,class,class_label,source}
 *                  sorted by value desc (for the override list)
 */
function build_allocation_view(array $holds, array $targets, array $overrides): array
{
    // Sanitize targets to the known classes only.
    $clean = [];
    foreach ($targets as $k => $v) {
        if (alloc_valid_class((string)$k)) $clean[(string)$k] = max(0.0, (float)$v);
    }
    $targets    = $clean;
    $hasTargets = !empty($targets);
    $targetSum  = array_sum($targets);

    $total    = 0.0;
    $byClass  = array_fill_keys(array_keys(ALLOC_CLASSES), 0.0);
    $holdRows = [];

    foreach ($holds as $h) {
        $val = (float)($h['institution_value'] ?? 0);
        if ($val <= 0) continue; // suppress $0/placeholder holdings, like the existing doughnut
        $r     = alloc_resolve_class($h, $overrides);
        $class = $r['class'];
        $total += $val;
        $byClass[$class] += $val;
        $defaultClass = alloc_default_class($h['security_type'] ?? null);
        $holdRows[] = [
            'security_id'   => $h['security_id'] ?? null,
            'label'         => ($h['ticker_symbol'] ?? '') !== '' ? $h['ticker_symbol']
                                : (($h['security_name'] ?? '') !== '' ? $h['security_name'] : '—'),
            'value'         => $val,
            'class'         => $class,
            'class_label'   => alloc_class_label($class),
            'default_label' => alloc_class_label($defaultClass), // the "Auto · X" option label
            'source'        => $r['source'],
        ];
    }

    // Aggregate per-security label rows that share a security_id (same ticker across accounts).
    usort($holdRows, fn($a, $b) => $b['value'] <=> $a['value']);
    foreach ($holdRows as &$hr) {
        $hr['pct'] = $total > 0 ? $hr['value'] / $total * 100 : 0.0;
    }
    unset($hr);

    // Per-class comparison rows. Include a class if it has actual value OR a target was set for it.
    $classes = [];
    foreach (ALLOC_CLASSES as $key => $label) {
        $actualVal  = $byClass[$key];
        $hasTarget  = array_key_exists($key, $targets);
        if ($actualVal <= 0 && !$hasTarget) continue;
        // When targets exist, a class with none set means "want 0%".
        $targetPct  = $hasTargets ? ($targets[$key] ?? 0.0) : null;
        $actualPct  = $total > 0 ? $actualVal / $total * 100 : 0.0;
        $targetVal  = $targetPct !== null ? $total * $targetPct / 100 : null;
        $classes[]  = [
            'key'        => $key,
            'label'      => $label,
            'actual_val' => $actualVal,
            'actual_pct' => $actualPct,
            'target_pct' => $targetPct,
            'target_val' => $targetVal,
            'drift_pct'  => $targetPct !== null ? $actualPct - $targetPct : null,
            'drift_val'  => $targetVal !== null ? $actualVal - $targetVal : null,
            'has_target' => $hasTarget,
        ];
    }

    // Rebalance hints: overweight (sell) vs underweight (buy), only when targets exist.
    $sells = [];
    $buys  = [];
    $maxDrift = 0.0;
    if ($hasTargets) {
        foreach ($classes as $c) {
            if ($c['drift_val'] === null) continue;
            $d = $c['drift_val'];
            if (abs($d) > $maxDrift) $maxDrift = abs($d);
            if ($d > ALLOC_DRIFT_FLOOR)      $sells[] = ['label' => $c['label'], 'amount' => $d];
            elseif ($d < -ALLOC_DRIFT_FLOOR) $buys[]  = ['label' => $c['label'], 'amount' => -$d];
        }
        usort($sells, fn($a, $b) => $b['amount'] <=> $a['amount']);
        usort($buys,  fn($a, $b) => $b['amount'] <=> $a['amount']);
    }

    return [
        'total'         => $total,
        'has_targets'   => $hasTargets,
        'target_sum'    => $targetSum,
        'classes'       => $classes,
        'sells'         => $sells,
        'buys'          => $buys,
        'max_drift_val' => $maxDrift,
        'holdings'      => $holdRows,
    ];
}
