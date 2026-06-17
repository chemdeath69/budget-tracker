<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Window selector (?days=). Small fixed set.
$days = (int)($_GET['days'] ?? 90);
if (!in_array($days, [30, 90, 365], true)) $days = 90;

$merchants = q_top_merchants($pdo, $uid, $days, 20);
$topTotal  = $merchants ? max(array_map(fn($m) => (float)$m['total'], $merchants)) : 0;

// Click-through window: from = today − $days (PHP app-TZ date, never MySQL CURDATE — S24 trap),
// so the destination ledger reconciles to the same window the leaderboard measured.
$from = date('Y-m-d', strtotime("-{$days} days"));

render_header('Top merchants', 'merchants', ['narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Insights</p>
    <h1>Top merchants</h1>
</div>

<form class="filter-bar" method="get" action="/merchants.php">
    <div class="filter-row">
        <select name="days" class="select" data-autosubmit aria-label="Period">
            <option value="30"<?=  $days === 30  ? ' selected' : '' ?>>Last 30 days</option>
            <option value="90"<?=  $days === 90  ? ' selected' : '' ?>>Last 90 days</option>
            <option value="365"<?= $days === 365 ? ' selected' : '' ?>>Last 12 months</option>
        </select>
        <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
    </div>
</form>

<?php if (!$merchants): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('store') ?></span>
        <h2>No merchant spending yet</h2>
        <p class="muted">Once your banks have synced some spending, your most-paid merchants appear here.</p>
    </div>
<?php else: ?>
    <section class="block">
        <div class="block-head"><h2>Where your money went</h2><span class="count-pill"><?= e($days === 365 ? '12 months' : $days . ' days') ?></span></div>
        <div class="rows card">
            <?php $rank = 0; foreach ($merchants as $m):
                $rank++;
                $name  = $m['merchant'];
                $total = (float)$m['total'];
                $pct   = $topTotal > 0 ? $total / $topTotal * 100 : 0;
                $href  = '/transactions.php?merchant=' . rawurlencode($name) . '&from=' . rawurlencode($from);
            ?>
            <a class="row merch-row" href="<?= e($href) ?>">
                <span class="merch-rank"><?= $rank ?></span>
                <?php if (!empty($m['logo_url'])): ?>
                    <img class="merchant-logo" src="<?= e($m['logo_url']) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="merchant-logo placeholder" aria-hidden="true"><?= e(strtoupper(mb_substr($name, 0, 1))) ?></span>
                <?php endif; ?>
                <span class="row-main">
                    <span class="row-title"><?= e(display_merchant($name)) ?></span>
                    <span class="row-sub muted"><?= (int)$m['n'] ?> transaction<?= (int)$m['n'] === 1 ? '' : 's' ?></span>
                    <span class="merch-bar"><span style="width:<?= round($pct) ?>%"></span></span>
                </span>
                <span class="row-amt"><?= e(usd($total)) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="muted load-note">True spending by payee — internal transfers and credit-card payments are
            excluded (same as Cash flow &amp; Trends). Tap a merchant to see its transactions.</p>
    </section>
<?php endif; ?>

<?php render_footer(); ?>
