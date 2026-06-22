<?php
/**
 * Guided first-run setup checklist (Reset/Bootstrap redesign). Shown right after the
 * very first (bootstrap) login — see oauth-callback.php — and reachable any time. A
 * plain list of "what to do next" cards linking to the relevant pages. require_login
 * only; admin-only steps (inviting users) are gated. Nothing here is mandatory — a
 * "Go to your dashboard" link finishes.
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo   = db();
$uid   = (int)current_user_id();
$admin = is_admin();

render_header('Get started', 'settings', ['narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Welcome</p>
    <h1>Let's get set up</h1>
</div>

<p class="muted" style="margin:-6px 0 18px">
    You're signed in<?= $admin ? ' as the administrator' : '' ?>. Here's how to get the most out of Budget Tracker —
    do any of these now or come back later from <strong>Settings</strong>.
</p>

<section class="block">
    <?php if ($admin): ?>
    <a class="card action-card" href="/users.php">
        <span class="acct-icon"><?= nav_icon('peers') ?></span>
        <span class="acct-main">
            <span class="acct-name">1 · Invite your household</span>
            <span class="acct-sub muted">Add the other people who should be able to sign in (and pick who's an admin)</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
    <?php endif; ?>

    <a class="card action-card" href="/link.php">
        <span class="acct-icon"><?= nav_icon('bank') ?></span>
        <span class="acct-main">
            <span class="acct-name"><?= $admin ? '2' : '1' ?> · Link your first bank</span>
            <span class="acct-sub muted">Connect a bank or card via Plaid — balances &amp; transactions sync automatically</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>

    <a class="card action-card" href="/manual_add.php">
        <span class="acct-icon"><?= nav_icon('invest') ?></span>
        <span class="acct-main">
            <span class="acct-name"><?= $admin ? '3' : '2' ?> · Add what Plaid can't see</span>
            <span class="acct-sub muted">Manual accounts (e.g. Webull), a 401(k), or a vehicle — they all count toward net worth</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>

    <a class="card action-card" href="/home_settings.php">
        <span class="acct-icon"><?= nav_icon('house') ?></span>
        <span class="acct-main">
            <span class="acct-name"><?= $admin ? '4' : '3' ?> · Add your home (optional)</span>
            <span class="acct-sub muted">Track home value &amp; equity against your mortgage</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<section class="block">
    <div class="block-head"><h2>Optional: extra data feeds</h2></div>
    <div class="card">
        <p class="muted" style="margin:0;line-height:1.6">
            Investment prices, dividends, economic indicators, home valuation, and the AI assistant each use a
            <strong>free (or low-cost) API key</strong>. Those keys live in the server's <code>lib/config.php</code>
            (a one-time edit by whoever installed the app) — each is optional and the related feature simply stays
            hidden until a key is added. Everything else works without them.
        </p>
    </div>
</section>

<section class="block">
    <a class="card action-card" href="/index.php">
        <span class="acct-icon"><?= nav_icon('home') ?></span>
        <span class="acct-main">
            <span class="acct-name">Go to your dashboard</span>
            <span class="acct-sub muted">You can always return here from Settings</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<?php render_footer(); ?>
