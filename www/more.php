<?php
declare(strict_types=1);

/**
 * "More" — the mobile long-tail menu (UI redesign Phase 1).
 *
 * Renders the full navigation as a searchable, grouped list (the mobile
 * equivalent of the desktop sidebar; both are driven by nav_items()). The
 * search box reuses the client-side initFilters() machinery — no new JS.
 * Reached via the "More" bottom tab; on desktop the sidebar covers this, so
 * it's a plain narrow page.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

render_header('More', 'more', ['narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Navigate</p>
    <h1>More</h1>
</div>

<div class="menu-search">
    <span class="menu-search-ic" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
    </span>
    <input class="search-input" type="search" data-filter="#more-list"
           placeholder="Jump to anything — “refunds”, “net worth”…" aria-label="Search sections">
</div>

<div id="more-list">
    <?php
    $grp = null;
    foreach (nav_items() as $it):
        if ($it['group'] !== $grp):
            if ($grp !== null) echo "</div></div>\n";   // close prior list + section
            $grp = $it['group'];
            ?>
            <div class="menu-section" data-filter-group>
            <div class="menu-h"><?= e(NAV_GROUPS[$grp] ?? $grp) ?></div>
            <div class="menu-list">
        <?php endif; ?>
        <a class="menu-row" href="<?= e($it['href']) ?>" data-search="<?= e(strtolower($it['label'] . ' ' . $it['desc'])) ?>">
            <span class="mi"><?= nav_icon($it['icon']) ?></span>
            <span class="m">
                <span class="n"><?= e($it['label']) ?></span>
                <span class="s"><?= e($it['desc']) ?></span>
            </span>
            <span class="chev" aria-hidden="true">›</span>
        </a>
    <?php endforeach;
    if ($grp !== null) echo "</div></div>\n"; ?>

    <div class="menu-section" data-filter-group>
        <div class="menu-list">
            <a class="menu-row" href="/logout.php" data-search="sign out log out">
                <span class="mi"><?= nav_icon('logout') ?></span>
                <span class="m"><span class="n">Sign out</span></span>
                <span class="chev" aria-hidden="true">›</span>
            </a>
        </div>
    </div>
</div>

<?php render_footer(); ?>
