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

/** The navigation entries shown in the drawer, in order. */
function nav_items(): array
{
    return [
        // Ordered by how often a user is likely to visit: daily-use pages first,
        // periodic financial views next, analytics below, rare config pages last.
        ['key' => 'dashboard',    'href' => '/index.php',        'label' => 'Dashboard',     'icon' => 'home'],
        ['key' => 'transactions', 'href' => '/transactions.php', 'label' => 'Transactions',  'icon' => 'list'],
        ['key' => 'spending',     'href' => '/spending.php',     'label' => 'Spending & budgets', 'icon' => 'chart'],
        ['key' => 'bills',        'href' => '/bills.php',        'label' => 'Upcoming bills', 'icon' => 'calendar'],
        ['key' => 'cashflow',     'href' => '/cashflow.php',     'label' => 'Cash flow',     'icon' => 'flow'],
        ['key' => 'networth',     'href' => '/networth.php',     'label' => 'Net worth',     'icon' => 'trend'],
        ['key' => 'goals',        'href' => '/goals.php',        'label' => 'Savings goals', 'icon' => 'target'],
        ['key' => 'investments',  'href' => '/investments.php',  'label' => 'Investments',   'icon' => 'invest'],
        ['key' => 'retirement',   'href' => '/retirement.php',   'label' => 'Retirement',    'icon' => 'nest'],
        ['key' => 'recurring',    'href' => '/recurring.php',    'label' => 'Recurring',     'icon' => 'repeat'],
        ['key' => 'trends',       'href' => '/trends.php',       'label' => 'Spending trends', 'icon' => 'bars'],
        ['key' => 'merchants',    'href' => '/merchants.php',    'label' => 'Top merchants', 'icon' => 'store'],
        ['key' => 'moneyflow',    'href' => '/moneyflow.php',    'label' => 'Money flow',    'icon' => 'sankey'],
        ['key' => 'property',     'href' => '/property.php',     'label' => 'Property',      'icon' => 'house'],
        ['key' => 'economic',     'href' => '/economic.php',     'label' => 'Economic',      'icon' => 'globe'],
        ['key' => 'rules',        'href' => '/rules.php',        'label' => 'Category rules', 'icon' => 'rules'],
        ['key' => 'settings',     'href' => '/settings.php',     'label' => 'Settings',      'icon' => 'gear'],
    ];
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
        'sankey' => '<path d="M4 4v16"/><path d="M4 8h6a4 4 0 0 1 4 4 4 4 0 0 0 4 4h2"/><path d="M4 16h6a4 4 0 0 0 4-4 4 4 0 0 1 4-4h2"/>',
        'invest' => '<path d="M3 3v18h18"/><path d="M7 14l3-3 3 2 5-6"/>',
        'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 1.7V1a2 2 0 1 1 4 0v.1A1.7 1.7 0 0 0 17 4.6a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H23a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'bank'   => '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9 10v8M15 10v8M19 10v8"/><path d="M3 21h18"/>',
        'house'  => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
        'nest'   => '<ellipse cx="12" cy="10.5" rx="5" ry="6.5"/><path d="M4 15c1.8 2 4.7 3.2 8 3.2s6.2-1.2 8-3.2"/><path d="M12 7.5v3M10.5 9h3"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18"/><path d="M8 2v4"/><path d="M16 2v4"/>',
        'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.6 2.4 4 5.6 4 9s-1.4 6.6-4 9c-2.6-2.4-4-5.6-4-9s1.4-6.6 4-9z"/>',
        'rules'  => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
        'store'  => '<path d="M4 4h16l-1 5H5z"/><path d="M5 9v11h14V9"/><path d="M9 20v-6h6v6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
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
    $back    = $opts['back'] ?? null;
    $name    = $_SESSION['name'] ?? ($_SESSION['user_email'] ?? '');
    $email   = $_SESSION['user_email'] ?? '';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b0f17" media="(prefers-color-scheme: dark)">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · Budget Tracker</title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= $cssV ?>">
    <?php if ($needChart): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <?php if ($needSankey): ?>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-sankey@0.12.1"></script>
    <?php endif; ?>
    <?php endif; ?>
</head>
<body data-page="<?= e($active) ?>">
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="banner">
        <?php if ($back !== null): ?>
            <a class="banner-btn" href="<?= e($back) ?>" aria-label="Back">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
        <?php else: ?>
            <button class="banner-btn" id="menu-open" aria-label="Open menu" aria-controls="drawer" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            </button>
        <?php endif; ?>

        <h1 class="banner-title"><?= e($title) ?></h1>

        <a class="banner-avatar" href="/settings.php" aria-label="Account &amp; settings">
            <?= user_avatar_html() ?>
        </a>
    </header>

    <!-- Slide-out navigation drawer -->
    <div class="scrim" id="scrim"></div>
    <nav class="drawer" id="drawer" aria-label="Main navigation" aria-hidden="true">
        <div class="drawer-head">
            <?= user_avatar_html(true) ?>
            <div class="drawer-id">
                <div class="drawer-name"><?= e($name) ?></div>
                <div class="drawer-email"><?= e($email) ?></div>
            </div>
            <button class="drawer-close" id="menu-close" aria-label="Close menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <ul class="drawer-nav">
            <?php foreach (nav_items() as $it): ?>
            <li>
                <a href="<?= e($it['href']) ?>" class="<?= $active === $it['key'] ? 'active' : '' ?>"<?= $active === $it['key'] ? ' aria-current="page"' : '' ?>>
                    <span class="ic"><?= nav_icon($it['icon']) ?></span>
                    <span><?= e($it['label']) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="drawer-foot">
            <a href="/link.php" class="drawer-action"><span class="ic"><?= nav_icon('bank') ?></span><span>Link a bank</span></a>
            <a href="/logout.php" class="drawer-action"><span class="ic"><?= nav_icon('logout') ?></span><span>Sign out</span></a>
        </div>
    </nav>

    <main class="screen<?= !empty($opts['narrow']) ? ' narrow' : '' ?>" id="main">
    <?php if (!empty($opts['subtitle'])): ?>
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
