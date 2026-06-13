<?php
/**
 * Peer / benchmark spending comparison — PURE derive (TODO2 #37, "you spent $X on dining
 * vs ~$Y typical for your income bracket").
 *
 * No DB of its own — the page hands in the viewer's VIS-scoped windowed spend
 * (q_peer_category_spend) and the chosen income bracket. Like lib/apy.php / lib/forecast.php,
 * this stays independent of queries.php load order.
 *
 * The benchmark is a STATIC bundled table from the **BLS Consumer Expenditure Survey** (free
 * U.S. gov data; BLS publishes tables/CSV, not a free live JSON API — so we bundle it and
 * refresh ~yearly). Source = the 2024 CEX "income before taxes" QUINTILE table, pulled from
 * the FRED CEX series (the authoritative mirror; see the [[bls-cex-peer-spend-data]] memory
 * for the exact series ids + how to refresh). All figures are AVERAGE ANNUAL expenditure per
 * "consumer unit" (≈ household), in dollars.
 *
 * ⚠️ HONEST-NUMBER stance (the S34/S38/S58 lesson):
 *  - The income brackets are income BEFORE taxes; Plaid only sees post-tax deposits, so the
 *    owner picks the bracket MANUALLY (no auto-inference, which would bracket them too low).
 *  - The household's spend is annualized as (windowed total × 12 / months_observed). A short
 *    linked history over-amplifies, so a `< PEER_MIN_CONF_MONTHS`-month basis is flagged "est".
 *  - Several PFC categories don't map 1:1 to a CEX line; we benchmark only the ones that do,
 *    and each carries the CEX scope caveat (peer_cat_map() `note`). We never fabricate a number.
 *  - CEX is per consumer unit (avg ~2.5 people), not per person — surfaced as a caveat.
 */

const PEER_CEX_YEAR        = 2024;
const PEER_CEX_SOURCE      = 'U.S. Bureau of Labor Statistics, Consumer Expenditure Survey, 2024';
const PEER_MIN_CONF_MONTHS = 6;     // fewer observed months → annualized figures flagged "est"
const PEER_DEFAULT_BRACKET = 'q3';  // neutral middle quintile (the owner sets their real one)

/**
 * Bundled BLS CEX table — income-before-taxes QUINTILES, 2024 (annual $ per consumer unit).
 * `low`/`high` = the quintile's income range (high=null = open-ended top); `income` = average
 * income before taxes; `people` = average people per consumer unit; `total` = total average
 * annual expenditures; `cats` = average annual expenditure for each benchmarked CEX line.
 * ⚠️ Refresh yearly when BLS releases the next year (see [[bls-cex-peer-spend-data]]).
 */
function peer_cex_brackets(): array
{
    return [
        'q1' => ['label' => 'Lowest 20%', 'low' => 0,      'high' => 29932,  'income' => 16658,  'people' => 1.6, 'total' => 35046,
                 'cats' => ['food' => 5498,  'transportation' => 5105,  'healthcare' => 3445, 'entertainment' => 1316, 'utilities' => 2934, 'personal_care' => 427]],
        'q2' => ['label' => 'Second 20%', 'low' => 29932,  'high' => 57452,  'income' => 42925,  'people' => 2.1, 'total' => 50054,
                 'cats' => ['food' => 7400,  'transportation' => 8430,  'healthcare' => 4826, 'entertainment' => 2156, 'utilities' => 4065, 'personal_care' => 659]],
        'q3' => ['label' => 'Middle 20%', 'low' => 57452,  'high' => 94511,  'income' => 74474,  'people' => 2.5, 'total' => 66900,
                 'cats' => ['food' => 9097,  'transportation' => 11657, 'healthcare' => 5676, 'entertainment' => 2764, 'utilities' => 4635, 'personal_care' => 852]],
        'q4' => ['label' => 'Fourth 20%', 'low' => 94511,  'high' => 155925, 'income' => 121548, 'people' => 2.9, 'total' => 89972,
                 'cats' => ['food' => 11845, 'transportation' => 15952, 'healthcare' => 7247, 'entertainment' => 4133, 'utilities' => 5431, 'personal_care' => 1145]],
        'q5' => ['label' => 'Highest 20%', 'low' => 155925, 'high' => null,  'income' => 264510, 'people' => 3.2, 'total' => 150342,
                 'cats' => ['food' => 16989, 'transportation' => 25378, 'healthcare' => 9771, 'entertainment' => 7660, 'utilities' => 6607, 'personal_care' => 1802]],
    ];
}

/**
 * PFC primary category → the CEX line we benchmark it against, with a display label and a
 * scope caveat (`note`) where the two definitions genuinely differ. Only categories with a
 * defensible mapping are listed — GENERAL_MERCHANDISE / HOME_IMPROVEMENT / GENERAL_SERVICES /
 * TRAVEL have no clean CEX 1:1, so they're intentionally not benchmarked.
 */
function peer_cat_map(): array
{
    return [
        'FOOD_AND_DRINK' => [
            'cex' => 'food', 'label' => 'Food & dining',
            'note' => 'Groceries and restaurants together (BLS combines food at home & away from home).',
        ],
        'TRANSPORTATION' => [
            'cex' => 'transportation', 'label' => 'Transportation',
            'note' => 'BLS transportation also includes vehicle purchases, auto loans and insurance — which this app tracks under loans/services — so your figure here (fuel, transit, rideshare, parking) reads much lower.',
        ],
        'MEDICAL' => [
            'cex' => 'healthcare', 'label' => 'Healthcare',
            'note' => '',
        ],
        'ENTERTAINMENT' => [
            'cex' => 'entertainment', 'label' => 'Entertainment',
            'note' => '',
        ],
        'RENT_AND_UTILITIES' => [
            'cex' => 'utilities', 'label' => 'Utilities & rent',
            'note' => 'BLS "utilities" excludes rent & mortgage; if you rent, your figure here includes rent and will read high.',
        ],
        'PERSONAL_CARE' => [
            'cex' => 'personal_care', 'label' => 'Personal care',
            'note' => '',
        ],
    ];
}

/** Coerce a requested bracket key to a valid one (default = the neutral middle quintile). */
function peer_valid_bracket(?string $k): string
{
    $k = (string)$k;
    return isset(peer_cex_brackets()[$k]) ? $k : PEER_DEFAULT_BRACKET;
}

/** "Middle 20% · $57k–$94k" — a selector/label for one bracket's income range. */
function peer_bracket_label(array $b): string
{
    $k = fn($n) => '$' . number_format($n / 1000) . 'k';
    if (($b['low'] ?? 0) <= 0)   return $b['label'] . ' · under ' . $k($b['high']);
    if (($b['high'] ?? null) === null) return $b['label'] . ' · ' . $k($b['low']) . '+';
    return $b['label'] . ' · ' . $k($b['low']) . '–' . $k($b['high']);
}

/**
 * Build the peer-spend view. $spend = ['cats'=>[EFF_CAT=>windowTotal], 'months_observed'=>int]
 * from q_peer_category_spend over a complete-months window. Annualizes the household's windowed
 * spend (× 12 / months_observed) and compares each mapped category to the chosen CEX quintile.
 */
function build_peer_view(string $bracketKey, array $spend): array
{
    $bracketKey = peer_valid_bracket($bracketKey);
    $brackets   = peer_cex_brackets();
    $b          = $brackets[$bracketKey];

    $months  = max(0, (int)($spend['months_observed'] ?? 0));
    $cats    = $spend['cats'] ?? [];
    $hasData = $months >= 1;
    $factor  = $hasData ? 12.0 / $months : 0.0;     // windowed total → annual
    $lowConf = $months < PEER_MIN_CONF_MONTHS;

    $rows = [];
    $sumYou = 0.0; $sumTypical = 0.0; $comparedCount = 0;
    foreach (peer_cat_map() as $pfc => $m) {
        $typical     = (float)($b['cats'][$m['cex']] ?? 0);
        $windowTotal = (float)($cats[$pfc] ?? 0);
        $youAnnual   = $windowTotal * $factor;
        $hasSpend    = $windowTotal > 0;
        $diff        = $youAnnual - $typical;
        $pct         = $typical > 0 ? ($diff / $typical) * 100 : null;
        $rows[] = [
            'pfc'            => $pfc,
            'label'          => $m['label'],
            'note'           => $m['note'],
            'you_annual'     => $youAnnual,
            'you_month'      => $youAnnual / 12,
            'typical_annual' => $typical,
            'typical_month'  => $typical / 12,
            'diff'           => $diff,
            'pct'            => $pct,
            'over'           => $diff > 0,
            'has_spend'      => $hasSpend,
        ];
        // ⚠ The headline compares ONLY categories where you actually have tracked spending. A $0
        // line means "no tracked spending" (possibly just untracked here), NOT "you spent nothing",
        // so folding it into the sum would falsely drag the comparison down (the S37-review HIGH).
        if ($hasSpend) { $sumYou += $youAnnual; $sumTypical += $typical; $comparedCount++; }
    }
    // Biggest benchmarked categories first (by the typical figure — a stable, data-independent order).
    usort($rows, fn($x, $y) => $y['typical_annual'] <=> $x['typical_annual']);

    $overallDiff = $sumYou - $sumTypical;
    $overallPct  = $sumTypical > 0 ? ($overallDiff / $sumTypical) * 100 : null;

    return [
        'has_data'        => $hasData,
        'months_observed' => $months,
        'low_conf'        => $lowConf,
        'bracket_key'     => $bracketKey,
        'bracket'         => $b,
        'brackets'        => $brackets,
        'rows'            => $rows,
        'compared_count'  => $comparedCount,   // categories with tracked spend (the headline's basis)
        'total_count'     => count($rows),
        'sum_you'         => $sumYou,
        'sum_typical'     => $sumTypical,
        'overall_diff'    => $overallDiff,
        'overall_pct'     => $overallPct,
        'overall_over'    => $overallDiff > 0,
        'source'          => PEER_CEX_SOURCE,
    ];
}
