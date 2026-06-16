<?php
declare(strict_types=1);

/**
 * Shared page chrome: the mobile top banner (hamburger · title · avatar),
 * the slide-out navigation drawer, and the page <head>.
 *
 * Usage in a page:
 *     require __DIR__ . '/lib/layout.php';
 *     require_login();
 *     render_header('Dashboard', 'dashboard', ['chart' => true]);
 *     ... page body ...
 *     render_footer();
 */

require_once __DIR__ . '/activity.php';   // page-view logging + sync-error banner state

/**
 * The single ordered source of truth for navigation (UI redesign Phase 1).
 * Drives THREE renderings: the desktop grouped sidebar + the mobile bottom tab
 * bar (via the `tab` field) + the mobile "More" list (more.php). Each entry:
 *   key   highlight key (matches render_header's $active / body[data-page])
 *   href  destination
 *   label sidebar / More-list label
 *   icon  nav_icon() name
 *   group sidebar/More section (one of NAV_GROUPS, in render order)
 *   tab   which bottom tab highlights here: home|spend|worth|assistant|more
 *   desc  one-line description (shown in the More list)
 * "Link a bank" is intentionally NOT here — it lives under Settings (Phase 2).
 */
function nav_items(): array
{
    return [
        // EVERYDAY — the daily loop.
        ['key' => 'dashboard',    'href' => '/index.php',        'label' => 'Home',          'icon' => 'home',     'group' => 'everyday', 'tab' => 'home',      'desc' => 'Your overview & accounts'],
        ['key' => 'assistant',    'href' => '/assistant.php',    'label' => 'Assistant',     'icon' => 'chat',     'group' => 'everyday', 'tab' => 'assistant', 'desc' => 'Ask anything about your money'],
        ['key' => 'transactions', 'href' => '/transactions.php', 'label' => 'Transactions',  'icon' => 'list',     'group' => 'everyday', 'tab' => 'more',      'desc' => 'Search & browse every transaction'],
        ['key' => 'spending',     'href' => '/spending.php',     'label' => 'Spending & budgets', 'icon' => 'chart', 'group' => 'everyday', 'tab' => 'spend',  'desc' => 'Spending by category & budgets'],
        ['key' => 'bills',        'href' => '/bills.php',        'label' => 'Upcoming bills', 'icon' => 'calendar', 'group' => 'everyday', 'tab' => 'more',      'desc' => 'Bills due & payment calendar'],
        ['key' => 'safetospend',  'href' => '/safe_to_spend.php', 'label' => 'Safe to spend', 'icon' => 'wallet',  'group' => 'everyday', 'tab' => 'more',      'desc' => "What's safe to spend this month"],
        ['key' => 'refunds',      'href' => '/refunds.php',      'label' => 'Refunds',       'icon' => 'refund',   'group' => 'everyday', 'tab' => 'more',      'desc' => 'Track purchases awaiting a credit'],

        // WORTH — net worth & planning.
        ['key' => 'networth',     'href' => '/networth.php',     'label' => 'Net worth',     'icon' => 'trend',    'group' => 'worth',    'tab' => 'worth',     'desc' => 'Net worth & composition over time'],
        ['key' => 'cashflow',     'href' => '/cashflow.php',     'label' => 'Cash flow',     'icon' => 'flow',     'group' => 'worth',    'tab' => 'worth',     'desc' => 'Income vs expense by month'],
        ['key' => 'forecast',     'href' => '/forecast.php',     'label' => 'Cash forecast', 'icon' => 'forecast', 'group' => 'worth',    'tab' => 'worth',     'desc' => 'Projected balance, next 30–90 days'],
        ['key' => 'goals',        'href' => '/goals.php',        'label' => 'Savings goals', 'icon' => 'target',   'group' => 'worth',    'tab' => 'worth',     'desc' => 'Savings targets & progress'],
        ['key' => 'debt',         'href' => '/debt.php',         'label' => 'Debt payoff',   'icon' => 'debt',     'group' => 'worth',    'tab' => 'worth',     'desc' => 'Payoff plan — snowball vs avalanche'],

        // INVEST — the portfolio.
        ['key' => 'investments',  'href' => '/investments.php',  'label' => 'Investments',   'icon' => 'invest',   'group' => 'invest',   'tab' => 'more',      'desc' => 'Holdings, performance & returns'],
        ['key' => 'allocation',   'href' => '/allocation.php',   'label' => 'Allocation',    'icon' => 'pie',      'group' => 'invest',   'tab' => 'more',      'desc' => 'Asset mix vs target'],
        ['key' => 'fees',         'href' => '/fees.php',         'label' => 'Investment fees', 'icon' => 'percent','group' => 'invest',   'tab' => 'more',      'desc' => 'Portfolio expense ratios & drag'],
        ['key' => 'retirement',   'href' => '/retirement.php',   'label' => 'Retirement',    'icon' => 'nest',     'group' => 'invest',   'tab' => 'more',      'desc' => '401(k)s & retirement projection'],

        // INSIGHTS — analytics over spending.
        ['key' => 'trends',       'href' => '/trends.php',       'label' => 'Spending trends', 'icon' => 'bars',   'group' => 'insights', 'tab' => 'spend',     'desc' => 'Month-by-month by category'],
        ['key' => 'peers',        'href' => '/peers.php',        'label' => 'Spending vs typical', 'icon' => 'peers', 'group' => 'insights', 'tab' => 'spend', 'desc' => 'Compare to a typical household'],
        ['key' => 'merchants',    'href' => '/merchants.php',    'label' => 'Top merchants', 'icon' => 'store',    'group' => 'insights', 'tab' => 'spend',     'desc' => 'Where your money goes'],
        ['key' => 'moneyflow',    'href' => '/moneyflow.php',    'label' => 'Money flow',    'icon' => 'sankey',   'group' => 'insights', 'tab' => 'spend',     'desc' => 'Income → spending, one month'],
        ['key' => 'recurring',    'href' => '/recurring.php',    'label' => 'Recurring',     'icon' => 'repeat',   'group' => 'insights', 'tab' => 'spend',     'desc' => 'Subscriptions & recurring income'],

        // PROPERTY — home, credit, macro.
        ['key' => 'property',     'href' => '/property.php',     'label' => 'Property',      'icon' => 'house',    'group' => 'property', 'tab' => 'worth',     'desc' => 'Home value & mortgage'],
        ['key' => 'credit',       'href' => '/credit.php',       'label' => 'Credit',        'icon' => 'credit',   'group' => 'property', 'tab' => 'more',      'desc' => 'Imported credit reports'],
        ['key' => 'economic',     'href' => '/economic.php',     'label' => 'Economic',      'icon' => 'globe',    'group' => 'property', 'tab' => 'more',      'desc' => 'Rates, inflation & savings'],

        // SETUP — config.
        ['key' => 'rules',        'href' => '/rules.php',        'label' => 'Categories & rules', 'icon' => 'rules', 'group' => 'setup',  'tab' => 'spend',     'desc' => 'Custom categories & auto-rules'],
        ['key' => 'settings',     'href' => '/settings.php',     'label' => 'Settings',      'icon' => 'gear',     'group' => 'setup',    'tab' => 'more',      'desc' => 'Banks, alerts & appearance'],
    ];
}

/** Sidebar / More-list groups, in render order. */
const NAV_GROUPS = [
    'everyday' => 'Everyday',
    'worth'    => 'Worth',
    'invest'   => 'Invest',
    'insights' => 'Insights',
    'property' => 'Property',
    'setup'    => 'Setup',
];

/**
 * The 5 primary bottom-tab-bar tabs (mobile) / sidebar order anchors. Each `tab`
 * matches the `tab` field on nav_items() so the active page lights the right tab.
 */
function nav_tabs(): array
{
    return [
        ['tab' => 'home',      'href' => '/index.php',      'label' => 'Home',  'icon' => 'home'],
        ['tab' => 'spend',     'href' => '/spending.php',   'label' => 'Spend', 'icon' => 'chart'],
        ['tab' => 'worth',     'href' => '/networth.php',   'label' => 'Worth', 'icon' => 'trend'],
        ['tab' => 'assistant', 'href' => '/assistant.php',  'label' => 'Ask',   'icon' => 'chat'],
        ['tab' => 'more',      'href' => '/more.php',       'label' => 'More',  'icon' => 'more'],
    ];
}

/** Which bottom tab is active for a given page key (defaults to 'more'). */
function nav_active_tab(string $activeKey): string
{
    if ($activeKey === 'more') return 'more';
    foreach (nav_items() as $it) {
        if ($it['key'] === $activeKey) return $it['tab'];
    }
    return '';   // login/link pages etc. — nothing highlighted
}

/**
 * The "Direct page" area chip rows (UI redesign §4) — sibling links shown at the
 * top of the area's landing page (spending.php / networth.php). Keys match
 * nav_items() so the current page's chip highlights.
 */
function nav_area_chips(): array
{
    return [
        'spend' => [
            ['key' => 'spending',  'href' => '/spending.php',  'label' => 'Overview'],
            ['key' => 'trends',    'href' => '/trends.php',    'label' => 'Trends'],
            ['key' => 'merchants', 'href' => '/merchants.php', 'label' => 'Top merchants'],
            ['key' => 'moneyflow', 'href' => '/moneyflow.php', 'label' => 'Money flow'],
            ['key' => 'recurring', 'href' => '/recurring.php', 'label' => 'Recurring'],
            ['key' => 'rules',     'href' => '/rules.php',     'label' => 'Categories & rules'],
        ],
        'worth' => [
            ['key' => 'networth',  'href' => '/networth.php',  'label' => 'Overview'],
            ['key' => 'cashflow',  'href' => '/cashflow.php',  'label' => 'Cash flow'],
            ['key' => 'forecast',  'href' => '/forecast.php',  'label' => 'Forecast'],
            ['key' => 'goals',     'href' => '/goals.php',     'label' => 'Goals'],
            ['key' => 'debt',      'href' => '/debt.php',      'label' => 'Debt payoff'],
            ['key' => 'property',  'href' => '/property.php',  'label' => 'Property'],
        ],
    ];
}

/** Render the area chip row (a horizontal scroll strip) for spending.php / networth.php. */
function render_nav_chips(string $area, string $activeKey): void
{
    $chips = nav_area_chips()[$area] ?? [];
    if (!$chips) return;
    echo '<nav class="tool-chips" aria-label="Section navigation">';
    foreach ($chips as $c) {
        $on = $c['key'] === $activeKey ? ' chip-on' : '';
        $cur = $c['key'] === $activeKey ? ' aria-current="page"' : '';
        echo '<a class="chip' . $on . '" href="' . e($c['href']) . '"' . $cur . '>' . e($c['label']) . '</a>';
    }
    echo '</nav>';
}

/** Minimal inline icon set (stroke-based, inherit currentColor). */
function nav_icon(string $name): string
{
    $p = [
        'home'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        'list'   => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><circle cx="3.5" cy="6" r="1.2"/><circle cx="3.5" cy="12" r="1.2"/><circle cx="3.5" cy="18" r="1.2"/>',
        'chart'  => '<path d="M4 20V4"/><path d="M4 20h16"/><rect x="7" y="11" width="3" height="6"/><rect x="13" y="7" width="3" height="10"/>',
        'trend'  => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'bars'   => '<rect x="4" y="13" width="4" height="7" rx="1"/><rect x="10" y="9" width="4" height="11" rx="1"/><rect x="16" y="5" width="4" height="15" rx="1"/>',
        'repeat' => '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'flow'   => '<path d="M7 21V5"/><path d="M3 9l4-4 4 4"/><path d="M17 3v16"/><path d="M13 15l4 4 4-4"/>',
        'forecast' => '<path d="M3 14l4-3 3 2 3-4"/><path d="M13 9l4 5 4-7" stroke-dasharray="3 3"/>',
        'sankey' => '<path d="M4 4v16"/><path d="M4 8h6a4 4 0 0 1 4 4 4 4 0 0 0 4 4h2"/><path d="M4 16h6a4 4 0 0 0 4-4 4 4 0 0 1 4-4h2"/>',
        'chat'   => '<path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 9.5 9.5 0 0 1-4-.9L3 21l1.9-5.5A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z"/>',
        'invest' => '<path d="M3 3v18h18"/><path d="M7 14l3-3 3 2 5-6"/>',
        'pie'    => '<path d="M12 3a9 9 0 1 0 9 9h-9z"/><path d="M12 3v9"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
        'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 1.7V1a2 2 0 1 1 4 0v.1A1.7 1.7 0 0 0 17 4.6a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H23a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'bank'   => '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9 10v8M15 10v8M19 10v8"/><path d="M3 21h18"/>',
        'house'  => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
        'nest'   => '<ellipse cx="12" cy="10.5" rx="5" ry="6.5"/><path d="M4 15c1.8 2 4.7 3.2 8 3.2s6.2-1.2 8-3.2"/><path d="M12 7.5v3M10.5 9h3"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18"/><path d="M8 2v4"/><path d="M16 2v4"/>',
        'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.6 2.4 4 5.6 4 9s-1.4 6.6-4 9c-2.6-2.4-4-5.6-4-9s1.4-6.6 4-9z"/>',
        'rules'  => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
        'store'  => '<path d="M4 4h16l-1 5H5z"/><path d="M5 9v11h14V9"/><path d="M9 20v-6h6v6"/>',
        'peers'  => '<circle cx="9" cy="8" r="3"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16 5.5a3 3 0 0 1 0 5.6"/><path d="M17.5 13.6A5.5 5.5 0 0 1 20.5 18"/>',
        'credit' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h12v4"/><rect x="3" y="7" width="18" height="12" rx="2"/><path d="M21 11h-4a2 2 0 0 0 0 4h4"/>',
        'debt'   => '<rect x="4" y="5" width="4" height="15" rx="1"/><rect x="10" y="9" width="4" height="11" rx="1"/><rect x="16" y="14" width="4" height="6" rx="1"/>',
        'refund' => '<path d="M3 9h11a6 6 0 0 1 0 12H9"/><path d="M7 5 3 9l4 4"/>',
        'car'    => '<path d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13"/><path d="M3 17v-2a2 2 0 0 1 1-1.7L5 13h14l1 .3A2 2 0 0 1 21 15v2"/><path d="M3 17h18v2h-2v-1H5v1H3z"/><circle cx="7.5" cy="16.5" r="1.2"/><circle cx="16.5" cy="16.5" r="1.2"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'activity' => '<path d="M3 12h4l2 6 4-14 2 8h6"/>',
        'more'   => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
    ];
    $inner = $p[$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

/** First-letter initials from a name or email. */
function user_initials(string $nameOrEmail): string
{
    $s = trim($nameOrEmail);
    if ($s === '') return '?';
    if (strpos($s, '@') !== false && strpos($s, ' ') === false) {
        return strtoupper(substr($s, 0, 1));
    }
    $parts = preg_split('/\s+/', $s);
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return strtoupper($first . $last);
}

/** Deterministic accent colour for the initials avatar, from the email. */
function avatar_hue(string $seed): int
{
    return (int)(hexdec(substr(md5($seed), 0, 6)) % 360);
}

/** The avatar element (Google photo if available, else initials circle). */
function user_avatar_html(bool $large = false): string
{
    $name    = $_SESSION['name'] ?? ($_SESSION['user_email'] ?? '');
    $email   = $_SESSION['user_email'] ?? '';
    $picture = $_SESSION['picture'] ?? '';
    $cls     = 'avatar' . ($large ? ' avatar-lg' : '');
    if ($picture !== '') {
        return '<img class="' . $cls . '" src="' . e($picture) . '" alt="' . e($name) . '" '
             . 'referrerpolicy="no-referrer" loading="lazy">';
    }
    $hue = avatar_hue($email ?: $name);
    return '<span class="' . $cls . ' avatar-initials" style="--hue:' . $hue . '" '
         . 'aria-label="' . e($name) . '">' . e(user_initials($name)) . '</span>';
}

/* ---- Pagination --------------------------------------------------------- */

/** Default rows shown per page in a paginated list. */
const PAGE_SIZE = 50;

/**
 * Largest page we'll honour. At PAGE_SIZE=50 this caps the SQL OFFSET at ~500k
 * rows — far beyond any real list here — so an absurd ?page=99999999 can't push
 * a giant OFFSET into a query (a needless full scan).
 */
const PAGE_NUM_MAX = 10000;

/** Current 1-based page from ?$param= (clamped to [1, PAGE_NUM_MAX]). Bad value → 1. */
function page_num(string $param = 'page'): int
{
    $n = (int)($_GET[$param] ?? 1);
    if ($n < 1) return 1;
    return $n > PAGE_NUM_MAX ? PAGE_NUM_MAX : $n;
}

/** Zero-based SQL offset for $page at PAGE_SIZE rows each. */
function page_offset(int $page): int
{
    return ($page - 1) * PAGE_SIZE;
}

/**
 * Prev/Next pager for a list. Detect $hasNext by fetching PAGE_SIZE + 1 rows
 * and testing `count($rows) > PAGE_SIZE` (then slice to PAGE_SIZE for display) —
 * cheaper than a COUNT(*).
 *  $page     current 1-based page
 *  $hasNext  whether an older page exists
 *  $params   active filters to carry through the links ([key=>value]); empties
 *            are dropped. Do NOT include the page key — it's set per-link.
 *  $param    the page query-string key (use distinct keys when one page hosts
 *            two independent lists, e.g. 'txpage' / 'docpage').
 * Emits nothing for a lone first page (page 1, no next).
 */
function render_pager(int $page, bool $hasNext, array $params = [], string $param = 'page'): void
{
    if ($page <= 1 && !$hasNext) return;
    $keep = array_filter($params, fn($v) => $v !== '' && $v !== null);
    $href = fn(int $p): string => '?' . http_build_query([$param => $p] + $keep);
    ?>
    <nav class="pager" aria-label="Pagination">
        <?php if ($page > 1): ?>
            <a class="pager-btn" rel="prev" href="<?= e($href($page - 1)) ?>">← Newer</a>
        <?php else: ?>
            <span class="pager-btn is-disabled" aria-disabled="true">← Newer</span>
        <?php endif; ?>
        <span class="pager-pos">Page <?= (int)$page ?></span>
        <?php if ($hasNext): ?>
            <a class="pager-btn" rel="next" href="<?= e($href($page + 1)) ?>">Older →</a>
        <?php else: ?>
            <span class="pager-btn is-disabled" aria-disabled="true">Older →</span>
        <?php endif; ?>
    </nav>
    <?php
}

/**
 * Investment-activity list section (Dividends & interest / Recent trades), shared by
 * investments.php + retirement.php (Session 34/#18). $rows come from
 * q_investment_activity() (normalized: tdate, title, amount, account_name, owner_id);
 * amount keeps the stored sign (+ = out, − = in) and is rendered as a green inflow
 * when negative. $o options:
 *   head_right    => HTML for the block-head right side (a total or a count pill)
 *   page,has_next,pager_key,pager_params  => render_pager() args
 *   empty         => message when $rows is empty
 *   filter        => ['opts'=>[id=>name], 'current'=>id, 'action'=>'/page.php'] | null
 *                    (an account picker; only render when there's >1 account)
 */
function render_investment_activity(string $title, array $rows, array $o): void
{
    $f = $o['filter'] ?? null; ?>
    <section class="block">
        <div class="block-head"><h2><?= e($title) ?></h2><?= $o['head_right'] ?? '' ?></div>
        <?php if ($f && count($f['opts']) > 1): ?>
        <form class="filter-bar" method="get" action="<?= e($f['action']) ?>">
            <div class="filter-row">
                <select name="iacct" class="select" data-autosubmit aria-label="Filter activity by account">
                    <option value="">All accounts</option>
                    <?php foreach ($f['opts'] as $aid => $nm): ?>
                        <option value="<?= e($aid) ?>"<?= (string)$f['current'] === (string)$aid ? ' selected' : '' ?>><?= e($nm) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <?php endif; ?>
        <div class="rows card">
            <?php if (!$rows): ?>
                <p class="muted" style="padding:1rem"><?= e($o['empty'] ?? 'No activity yet.') ?></p>
            <?php else: foreach ($rows as $r): $amt = (float)$r['amount']; $in = $amt < 0; ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title"><?= e($r['title'] ?: 'Activity') ?></span>
                    <span class="row-sub"><span class="tx-date"><?= e($r['tdate']) ?></span> · <?= e($r['account_name'] ?: '') ?><?= owner_suffix($r['owner_id'] ?? null) ?></span>
                </span>
                <span class="row-amt <?= $in ? 'pos' : '' ?>"><?= $in ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <?php render_pager((int)($o['page'] ?? 1), (bool)($o['has_next'] ?? false), $o['pager_params'] ?? [], $o['pager_key'] ?? 'page'); ?>
    </section>
<?php }

/**
 * Shared per-transaction metadata strip (#8) — tags, note + split affordances — shown
 * under a transaction row on transactions.php + account.php so both stay identical.
 * Expects a row already passed through attach_tx_meta() (queries.php), i.e. $t['tags']
 * (= [['id','name'],…]), $t['splits'] (= [['category','amount','note'],…]) and the
 * selected $t['note'] are present. Returns an HTML string the caller echoes inside
 * .row-main. The interactive parts are wired by app.js (initTxTags / initTxNotes /
 * initTxSplits) against api/account.php — the data-* attributes are the contract:
 *   .tx-meta[data-tx][data-amount][data-expense]  · .tag-chip[data-tag-id] + .tag-x
 *   · .tag-add-btn · .note-btn · .split-btn. Splits are offered only on an expense
 *   (amount > 0 = money OUT); data-amount is the positive parent total the split
 *   editor must reconcile to. pretty_cat()/usd() come from queries.php (loaded first).
 */
function render_tx_meta(array $t): string
{
    $tid     = (string)($t['transaction_id'] ?? '');
    $amt     = (float)($t['amount'] ?? 0);
    $expense = $amt > 0;                       // Plaid sign: + = money OUT
    $tags    = $t['tags']   ?? [];
    $splits  = $t['splits'] ?? [];
    $note    = (string)($t['note'] ?? '');
    // Quick-rule (#10): the inline "+ rule" shortcut prefills from the row's merchant/
    // description + its currently-shown category (the rule editor is wired by app.js
    // initTxRules against api/rules.php). Offered only when there's something to match on.
    $merch   = trim((string)($t['merchant_name'] ?? ''));
    $rawName = trim((string)($t['name'] ?? ''));
    $curCat  = (string)($t['category'] ?? '');
    $canRule = ($merch !== '' || $rawName !== '');
    ob_start();
    ?>
    <span class="tx-meta" data-tx="<?= e($tid) ?>" data-amount="<?= e(number_format($amt, 2, '.', '')) ?>" data-expense="<?= $expense ? '1' : '0' ?>" data-merchant="<?= e($merch) ?>" data-name="<?= e($rawName) ?>" data-cat="<?= e($curCat) ?>">
        <span class="tx-tags">
            <?php foreach ($tags as $tg): ?>
                <span class="tag-chip" data-tag-id="<?= (int)$tg['id'] ?>">#<?= e($tg['name']) ?><button type="button" class="tag-x" data-tx="<?= e($tid) ?>" data-tag-id="<?= (int)$tg['id'] ?>" aria-label="Remove tag <?= e($tg['name']) ?>">×</button></span>
            <?php endforeach; ?>
            <button type="button" class="meta-btn tag-add-btn" data-tx="<?= e($tid) ?>">+ tag</button>
        </span>
        <button type="button" class="meta-btn note-btn<?= $note !== '' ? ' has-note' : '' ?>" data-tx="<?= e($tid) ?>"><?= $note !== '' ? e($note) : 'note' ?></button>
        <?php if ($canRule): ?>
            <button type="button" class="meta-btn rule-add-btn" data-tx="<?= e($tid) ?>" title="Always categorize this merchant — create a rule">+ rule</button>
        <?php endif; ?>
        <?php if ($expense): ?>
            <?php $splitData = array_map(fn($s) => ['category' => $s['category'], 'amount' => (float)$s['amount']], $splits); ?>
            <button type="button" class="meta-btn split-btn<?= $splits ? ' is-split' : '' ?>" data-tx="<?= e($tid) ?>" data-splits="<?= e(json_encode($splitData, JSON_UNESCAPED_SLASHES)) ?>">Split<?= $splits ? ' (' . count($splits) . ')' : '' ?></button>
        <?php endif; ?>
        <?php if ($splits): ?>
            <?php
            // Splits drive spend ONLY while they still reconcile with the parent amount
            // (queries.php SPLIT_JOIN). If a Plaid re-sync changed the amount under the
            // splits, they've gone stale → the spend math falls back to the parent, so
            // flag it here rather than silently showing a split that no longer counts.
            $splitSum = 0.0;
            foreach ($splits as $sp) { $splitSum += (float)$sp['amount']; }
            $stale = abs($splitSum - abs($amt)) >= 0.005;
            ?>
            <span class="split-summary<?= $stale ? ' is-stale' : '' ?>"><?php
                $parts = [];
                foreach ($splits as $sp) { $parts[] = e(pretty_cat($sp['category'])) . ' ' . e(usd((float)$sp['amount'])); }
                echo implode(' · ', $parts);
                if ($stale) echo ' <span class="split-stale" title="The transaction amount changed after this split was set — the split no longer matches, so it isn\'t counted. Edit it to re-balance.">⚠ amount changed — review</span>';
            ?></span>
        <?php endif; ?>
        <?php if ($expense): ?>
            <?php
            // Refund tracking (#34): flag a purchase "expecting a refund". The button toggles
            // the flag on/off (app.js initRefundFlag); confirming the matching credit / marking
            // received happens on refunds.php. A received watch shows a static chip here.
            $refundStatus = (isset($t['refund']) && $t['refund']) ? (string)$t['refund']['status'] : 'none';
            ?>
            <?php if ($refundStatus === 'received'): ?>
                <a class="refund-chip is-received" href="/refunds.php" title="Refund received — manage on the Refunds page">✓ refunded</a>
            <?php else: ?>
                <button type="button" class="meta-btn refund-btn<?= $refundStatus === 'pending' ? ' is-pending' : '' ?>" data-tx="<?= e($tid) ?>" data-status="<?= e($refundStatus) ?>"><?= $refundStatus === 'pending' ? '⟳ refund pending' : '⟳ expect refund' ?></button>
            <?php endif; ?>
        <?php endif; ?>
    </span>
    <?php
    return (string)ob_get_clean();
}

/**
 * Emit the document head + banner + drawer and open <main>.
 *  $active = nav key to highlight.
 *  $opts: ['chart'=>bool, 'back'=>?string url, 'subtitle'=>string, 'narrow'=>bool]
 *  narrow => true caps the desktop content column tighter (~820px) for linear,
 *  single-column pages (lists, forms) instead of the default wide (~1180px).
 */
function render_header(string $title, string $active = '', array $opts = []): void
{
    $assets  = __DIR__ . '/../assets';
    $cssV    = @filemtime($assets . '/style.css') ?: time();
    $jsV     = @filemtime($assets . '/app.js') ?: time();
    $needSankey = !empty($opts['sankey']);
    $needChart  = !empty($opts['chart']) || $needSankey;
    $back      = $opts['back'] ?? null;
    $activeTab = nav_active_tab($active);   // which bottom tab highlights
    $name    = $_SESSION['name'] ?? ($_SESSION['user_email'] ?? '');
    $email   = $_SESSION['user_email'] ?? '';

    // --- Activity logging + persistent sync-error banner (migration 029) ---------
    // render_header() runs on every authenticated page, so it's the single choke point
    // for "what pages they viewed" + the household sync-error banner. All best-effort —
    // a logging/query failure must never break the render.
    $actUid = function_exists('current_user_id') ? current_user_id() : null;
    if ($actUid !== null) access_log_page(db(), (int)$actUid);
    if (isset($_GET['dismiss_sync_alert'])) {                        // per-session dismiss
        $_SESSION['sync_alert_dismissed'] = (string)$_GET['dismiss_sync_alert'];
    }
    if ($active !== 'activity') {
        $syncAlert = sync_alert_state(db());
        // Once the error clears, forget the dismissal — so a later RECURRENCE of the same
        // content-stable signature (e.g. the same bank re-breaking in one session) re-surfaces
        // the banner instead of staying hidden (review fix).
        if (empty($syncAlert['error'])) unset($_SESSION['sync_alert_dismissed']);
    } else {
        $syncAlert = ['error' => false, 'signature' => '', 'reasons' => []];
    }
    $showSyncAlert = !empty($syncAlert['error'])
        && ($_SESSION['sync_alert_dismissed'] ?? '') !== ($syncAlert['signature'] ?? '');
    $dismissHref = '?' . http_build_query(array_merge($_GET, ['dismiss_sync_alert' => $syncAlert['signature'] ?? '']));
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#F7F4EE" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#14130F" media="(prefers-color-scheme: dark)">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · Budget Tracker</title>
    <link rel="preload" href="/assets/fonts/fraunces-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/ibmplexsans-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/style.css?v=<?= $cssV ?>">
    <?php if ($needChart): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <?php if ($needSankey): ?>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-sankey@0.12.1"></script>
    <?php endif; ?>
    <?php endif; ?>
</head>
<body data-page="<?= e($active) ?>" data-tab="<?= e($activeTab) ?>">
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="banner">
        <?php if ($back !== null): ?>
            <a class="banner-btn" href="<?= e($back) ?>" aria-label="Back">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
        <?php else: ?>
            <span class="banner-btn banner-spacer" aria-hidden="true"></span>
        <?php endif; ?>

        <h1 class="banner-title"><?= e($title) ?></h1>

        <a class="banner-avatar" href="/settings.php" aria-label="Account &amp; settings">
            <?= user_avatar_html() ?>
        </a>
    </header>

    <!-- Desktop grouped sidebar (≥1024px). Hidden on mobile — the bottom tab bar
         + the More tab cover navigation there (UI redesign Phase 1). -->
    <nav class="drawer" id="drawer" aria-label="Main navigation">
        <div class="drawer-head">
            <?= user_avatar_html(true) ?>
            <div class="drawer-id">
                <div class="drawer-name"><?= e($name) ?></div>
                <div class="drawer-email"><?= e($email) ?></div>
            </div>
        </div>

        <div class="drawer-nav">
            <?php $grp = null; foreach (nav_items() as $it):
                if ($it['group'] !== $grp): $grp = $it['group']; ?>
                <h4 class="nav-group"><?= e(NAV_GROUPS[$grp] ?? $grp) ?></h4>
                <?php endif; ?>
                <a href="<?= e($it['href']) ?>" class="nav-link<?= $active === $it['key'] ? ' active' : '' ?>"<?= $active === $it['key'] ? ' aria-current="page"' : '' ?>>
                    <span class="ic"><?= nav_icon($it['icon']) ?></span>
                    <span><?= e($it['label']) ?></span>
                </a>
            <?php endforeach; ?>
            <a href="/logout.php" class="nav-link drawer-action drawer-sep"><span class="ic"><?= nav_icon('logout') ?></span><span>Sign out</span></a>
        </div>
    </nav>

    <!-- Mobile bottom tab bar (hidden on desktop). -->
    <nav class="tabbar" aria-label="Primary">
        <?php foreach (nav_tabs() as $t): ?>
        <a href="<?= e($t['href']) ?>" class="<?= $activeTab === $t['tab'] ? 'active' : '' ?>"<?= $activeTab === $t['tab'] ? ' aria-current="page"' : '' ?>>
            <span class="ic"><?= nav_icon($t['icon']) ?></span>
            <span class="tabbar-label"><?= e($t['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <main class="screen<?= !empty($opts['narrow']) ? ' narrow' : '' ?>" id="main">
    <?php if ($showSyncAlert): ?>
        <div class="notice warn sync-alert" role="alert">
            <span class="sync-alert-msg"><?= e(implode(' · ', $syncAlert['reasons'])) ?> — <a href="/activity.php?view=sync">View sync status</a></span>
            <a class="sync-alert-x" href="<?= e($dismissHref) ?>" aria-label="Dismiss this warning">&times;</a>
        </div>
    <?php endif;
    if (!empty($opts['subtitle'])): ?>
        <p class="screen-subtitle"><?= e($opts['subtitle']) ?></p>
    <?php endif;
}

/** Close <main>, load the script, close the document. */
function render_footer(): void
{
    $jsV = @filemtime(__DIR__ . '/../assets/app.js') ?: time();
    ?>
    </main>
    <script src="/assets/app.js?v=<?= $jsV ?>"></script>
</body>
</html>
    <?php
}
