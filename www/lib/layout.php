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
        ['key' => 'dashboard',    'href' => '/index.php',        'label' => 'Dashboard',     'icon' => 'home'],
        ['key' => 'transactions', 'href' => '/transactions.php', 'label' => 'Transactions',  'icon' => 'list'],
        ['key' => 'spending',     'href' => '/spending.php',     'label' => 'Spending & budgets', 'icon' => 'chart'],
        ['key' => 'cashflow',     'href' => '/cashflow.php',     'label' => 'Cash flow',     'icon' => 'flow'],
        ['key' => 'trends',       'href' => '/trends.php',       'label' => 'Spending trends', 'icon' => 'bars'],
        ['key' => 'networth',     'href' => '/networth.php',     'label' => 'Net worth',     'icon' => 'trend'],
        ['key' => 'recurring',    'href' => '/recurring.php',    'label' => 'Recurring',     'icon' => 'repeat'],
        ['key' => 'investments',  'href' => '/investments.php',  'label' => 'Investments',   'icon' => 'invest'],
        ['key' => 'retirement',   'href' => '/retirement.php',   'label' => 'Retirement',    'icon' => 'nest'],
        ['key' => 'property',     'href' => '/property.php',     'label' => 'Property',      'icon' => 'house'],
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
        'invest' => '<path d="M3 3v18h18"/><path d="M7 14l3-3 3 2 5-6"/>',
        'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 1.7V1a2 2 0 1 1 4 0v.1A1.7 1.7 0 0 0 17 4.6a1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H23a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'bank'   => '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9 10v8M15 10v8M19 10v8"/><path d="M3 21h18"/>',
        'house'  => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
        'nest'   => '<ellipse cx="12" cy="10.5" rx="5" ry="6.5"/><path d="M4 15c1.8 2 4.7 3.2 8 3.2s6.2-1.2 8-3.2"/><path d="M12 7.5v3M10.5 9h3"/>',
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

/** Current 1-based page from ?$param= (min 1). A bad value falls back to 1. */
function page_num(string $param = 'page'): int
{
    $n = (int)($_GET[$param] ?? 1);
    return $n < 1 ? 1 : $n;
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
    $needChart = !empty($opts['chart']);
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
