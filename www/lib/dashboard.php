<?php
declare(strict_types=1);

/**
 * Customizable dashboard — widget catalog, per-user layout, the "Needs Attention"
 * feed, and the bento card renderer (UI redesign Phase 3).
 *
 * The per-user layout lives in user_prefs.prefs.dashboard (migration 030, shared with
 * the Phase-2 theme — NO new migration). Shape on disk (what api/prefs.php stores after
 * dash_sanitize_layout):
 *     ['attention_on' => bool, 'cards' => [['widget' => key, 'size' => 'off|sm|wd|ft'], …]]
 * dash_layout() reads it back, validates against the catalog, drops unknown widgets and
 * appends any catalog widget the stored order is missing (as 'off'), so a fresh user / a
 * pre-migration DB / a later-added widget all degrade to a sensible Home.
 *
 * NOT VIS-scoped — it's the viewer's OWN prefs keyed by user_id. The widget DATA is still
 * computed by the page through the existing VIS-scoped q_*() helpers; this file only orders
 * + renders it (no new queries).
 */

/** Card sizes. 'off' = hidden; 'sm' = one cell; 'wd' = double-width compact; 'ft' = feature. */
const DASH_SIZES = ['off', 'sm', 'wd', 'ft'];

/** Human labels for the size segmented control (designer). */
const DASH_SIZE_LABELS = ['off' => 'Off', 'sm' => 'Small', 'wd' => 'Wide', 'ft' => 'Feature'];

/**
 * The widget catalog — the single ordered source of truth. Each entry:
 *   label  / desc  shown in the designer
 *   size           the DEFAULT size (used for a fresh user's layout)
 *   icon           nav_icon() name (designer row icon)
 * The order here is ALSO the default order on Home.
 */
function dash_widgets(): array
{
    return [
        'net_worth'     => ['label' => 'Net worth',     'desc' => 'Total · assets/liabilities · trend', 'size' => 'ft', 'icon' => 'trend'],
        'cash_on_hand'  => ['label' => 'Cash on hand',  'desc' => 'Checking + savings, over time',       'size' => 'wd', 'icon' => 'bank'],
        'safe_to_spend' => ['label' => 'Safe to spend', 'desc' => "What's left to spend this month",     'size' => 'ft', 'icon' => 'wallet'],
        'cash_flow'     => ['label' => 'Cash flow',     'desc' => 'Net + savings rate, 6 months',        'size' => 'sm', 'icon' => 'flow'],
        'investments'   => ['label' => 'Investments',   'desc' => 'Value + gain/loss',                   'size' => 'sm', 'icon' => 'invest'],
        'retirement'    => ['label' => 'Retirement',    'desc' => 'Combined 401(k) total',               'size' => 'sm', 'icon' => 'nest'],
        'bills'         => ['label' => 'Upcoming bills', 'desc' => 'Due in the next 14 days',            'size' => 'sm', 'icon' => 'calendar'],
        'spending'      => ['label' => 'Spending',      'desc' => 'Total spent, last 30 days',           'size' => 'off', 'icon' => 'chart'],
        'goals'         => ['label' => 'Savings goals', 'desc' => 'Progress toward your targets',        'size' => 'off', 'icon' => 'target'],
        'forecast'      => ['label' => 'Cash forecast', 'desc' => 'Projected low, next 30 days',         'size' => 'off', 'icon' => 'forecast'],
        'debt'          => ['label' => 'Debt payoff',   'desc' => 'Total owed + payoff plan',            'size' => 'off', 'icon' => 'debt'],
        'home_equity'   => ['label' => 'Home equity',   'desc' => 'Value vs mortgage',                   'size' => 'off', 'icon' => 'house'],
        'refunds'       => ['label' => 'Refunds',       'desc' => 'Outstanding refunds to confirm',      'size' => 'off', 'icon' => 'refund'],
        'allocation'    => ['label' => 'Allocation',    'desc' => 'Asset mix vs target',                 'size' => 'off', 'icon' => 'pie'],
        'top_merchants' => ['label' => 'Top merchants', 'desc' => 'Where your money goes, 90 days',      'size' => 'off', 'icon' => 'store'],
        'recurring'     => ['label' => 'Recurring',     'desc' => 'Subscriptions & recurring income',    'size' => 'off', 'icon' => 'repeat'],
        'credit'        => ['label' => 'Credit',        'desc' => 'Latest score / health',               'size' => 'off', 'icon' => 'credit'],
        'economic'      => ['label' => 'Economic',      'desc' => 'Rates & inflation',                   'size' => 'off', 'icon' => 'globe'],
    ];
}

/** The shipped default layout (a fresh user / a layout-less DB). */
function dash_default_layout(): array
{
    $cards = [];
    foreach (dash_widgets() as $key => $w) {
        $cards[] = ['widget' => $key, 'size' => $w['size']];
    }
    return ['attention_on' => true, 'cards' => $cards];
}

/**
 * Normalize the stored prefs into a full, valid layout covering EVERY catalog widget.
 * - No stored dashboard at all → the default layout.
 * - Stored: keep its order + sizes (validated); drop unknown widgets / bad sizes; append
 *   any catalog widget the stored order is missing as 'off' (a later-added widget never
 *   surprises an existing user by appearing on Home).
 * Always returns ['attention_on' => bool, 'cards' => [{widget,size}…]] (the designer
 * iterates this; the dashboard renders the size!='off' subset in order).
 */
function dash_layout(array $prefs): array
{
    $catalog = dash_widgets();
    $stored  = $prefs['dashboard'] ?? null;
    if (!is_array($stored) || !isset($stored['cards']) || !is_array($stored['cards'])) {
        return dash_default_layout();
    }

    $seen  = [];
    $cards = [];
    foreach ($stored['cards'] as $c) {
        if (!is_array($c)) continue;
        $w = $c['widget'] ?? null;
        $s = $c['size'] ?? null;
        if (!is_string($w) || !isset($catalog[$w]) || isset($seen[$w])) continue;
        if (!in_array($s, DASH_SIZES, true)) $s = 'off';
        $cards[]   = ['widget' => $w, 'size' => $s];
        $seen[$w]  = true;
    }
    // Append any catalog widget missing from the stored order, as 'off'.
    foreach ($catalog as $key => $_) {
        if (!isset($seen[$key])) $cards[] = ['widget' => $key, 'size' => 'off'];
    }

    return [
        'attention_on' => array_key_exists('attention_on', $stored) ? (bool)$stored['attention_on'] : true,
        'cards'        => $cards,
    ];
}

/**
 * Validate a posted layout into the on-disk shape (the security boundary for api/prefs.php).
 * Keeps only known widgets + valid sizes, in the posted order, deduped; coerces attention_on
 * to a bool. Returns null when the input isn't a usable layout object (the caller 422s).
 */
function dash_sanitize_layout($raw): ?array
{
    if (!is_array($raw) || !isset($raw['cards']) || !is_array($raw['cards'])) return null;
    $catalog = dash_widgets();
    $seen = [];
    $cards = [];
    foreach ($raw['cards'] as $c) {
        if (!is_array($c)) continue;
        $w = $c['widget'] ?? null;
        $s = $c['size'] ?? null;
        if (!is_string($w) || !isset($catalog[$w]) || isset($seen[$w])) continue;
        if (!in_array($s, DASH_SIZES, true)) continue;
        $cards[]  = ['widget' => $w, 'size' => $s];
        $seen[$w] = true;
    }
    if (!$cards) return null;
    return [
        'attention_on' => !empty($raw['attention_on']),
        'cards'        => $cards,
    ];
}

/**
 * Render one bento card from a uniform data bundle:
 *   ['href','eyebrow','value','tone'=>''|'pos'|'neg','sub'=>trusted-HTML,'spark'=>['id','labels','values']?]
 * `value`/`eyebrow` are e()-escaped; `sub` is TRUSTED HTML (the page builds it from
 * e()/usd() + fixed labels). The spark renders only at wide/feature size (a sparkline needs
 * width); the JSON blob mirrors the dashboard's other inline chart blobs (JSON_HEX_*).
 */
function dash_card_html(string $size, array $w): string
{
    $cls = 'b-card';
    if ($size === 'ft')      $cls .= ' span2 feature';
    elseif ($size === 'wd')  $cls .= ' span2';
    $tone = in_array(($w['tone'] ?? ''), ['pos', 'neg'], true) ? ' ' . $w['tone'] : '';

    $h  = '<a class="' . $cls . '" href="' . e($w['href']) . '">';
    $h .= '<p class="eyebrow">' . e($w['eyebrow']) . '</p>';
    $h .= '<div class="b-val tnum' . $tone . '">' . e($w['value']) . '</div>';
    if (!empty($w['spark']) && ($size === 'ft' || $size === 'wd')) {
        $sp = $w['spark'];
        $h .= '<div class="b-spark"><canvas data-chart="spark" data-src="' . e($sp['id']) . '" height="30"></canvas></div>';
        $h .= '<script type="application/json" id="' . e($sp['id']) . '">'
            . json_encode(['labels' => $sp['labels'], 'values' => $sp['values']],
                          JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
    }
    // ⚠️ $w['sub'] is emitted as RAW HTML — callers MUST build it from e()/usd() + fixed labels,
    // NEVER from a raw external string (a merchant/account/OCR name would be stored XSS here).
    if (!empty($w['sub'])) $h .= '<div class="b-sub">' . $w['sub'] . '</div>';
    $h .= '</a>';
    return $h;
}

/**
 * Build the ordered "Needs Attention" feed from already-derived signals (PURE — no DB).
 * Order (§5.4): broken connection → due-soon money → confirmables → overdue → informational.
 * Each input is optional; an empty result means "all caught up".
 *
 * $in keys (all optional):
 *   broken_banks       [names…]                         → warn
 *   bills_due          [['label','due_label','amount'(?float),'account_id'], …] (already ≤N days)
 *   refunds_count      int  + refunds_amount float      → go (teal)
 *   overdue_statements [['name','account_id','meta'], …]
 *   budgets_over       [['label','pct'(int)], …]
 *
 * Returns [['tone'=>''|'warn'|'go','icon','title','sub','href','amount'(?str),'amount_tone'], …].
 */
function build_attention_feed(array $in): array
{
    $feed = [];

    foreach (($in['broken_banks'] ?? []) as $name) {
        $feed[] = [
            'tone' => 'warn', 'icon' => '⤬',
            'title' => 'A bank needs reconnecting',
            'sub'   => (string)$name . ' · sign-in expired',
            'href'  => '/settings.php',
        ];
    }

    foreach (($in['bills_due'] ?? []) as $b) {
        $feed[] = [
            'tone' => 'warn', 'icon' => '!',
            'title' => (string)$b['label'] . ' due ' . (string)$b['due_label'],
            'sub'   => 'Upcoming bill',
            'href'  => !empty($b['account_id']) ? '/account.php?account_id=' . urlencode((string)$b['account_id']) : '/bills.php',
            'amount'      => isset($b['amount']) && $b['amount'] !== null ? usd((float)$b['amount']) : null,
            'amount_tone' => 'neg',
        ];
    }

    if (!empty($in['refunds_count'])) {
        $n = (int)$in['refunds_count'];
        $feed[] = [
            'tone' => 'go', 'icon' => '↩',
            'title' => $n === 1 ? 'A refund landed — confirm it' : $n . ' refunds to confirm',
            'sub'   => 'A matching credit was found',
            'href'  => '/refunds.php',
            'amount'      => isset($in['refunds_amount']) ? '+' . usd((float)$in['refunds_amount']) : null,
            'amount_tone' => 'pos',
        ];
    }

    foreach (($in['overdue_statements'] ?? []) as $o) {
        $feed[] = [
            'tone' => '', 'icon' => '◷',
            'title' => (string)$o['name'] . ' statement overdue',
            'sub'   => (string)($o['meta'] ?? ''),
            'href'  => !empty($o['account_id']) ? '/account.php?account_id=' . urlencode((string)$o['account_id']) : '/settings.php',
        ];
    }

    foreach (($in['budgets_over'] ?? []) as $b) {
        $feed[] = [
            'tone' => '', 'icon' => '!',
            'title' => (string)$b['label'] . ' budget exceeded',
            'sub'   => isset($b['pct']) ? (int)$b['pct'] . '% of budget spent' : 'Over this month',
            'href'  => '/spending.php',
        ];
    }

    return $feed;
}
