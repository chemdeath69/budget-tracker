<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$holds = q_holdings($pdo, $uid);
$total = array_sum(array_map(fn($h) => (float)($h['institution_value'] ?? 0), $holds));

render_header('Investments', 'investments');
?>

<?php if (!$holds): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('invest') ?></span>
        <h2>No holdings yet</h2>
        <p class="muted">Link a brokerage account (or re-link an existing one to grant investment access) to see your holdings here.</p>
        <a class="btn" href="/link.php">Link an account</a>
    </div>
<?php else: ?>
    <section class="card hero">
        <span class="hero-label">Total holdings value</span>
        <div class="hero-value"><?= e(usd($total)) ?></div>
    </section>

    <section class="block">
        <div class="block-head"><h2>Holdings</h2><span class="count-pill"><?= count($holds) ?></span></div>
        <div class="rows card">
            <?php foreach ($holds as $h): $sec = ($h['ticker_symbol'] ? $h['ticker_symbol'] . ' — ' : '') . ($h['security_name'] ?: '—'); ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title"><?= e($sec) ?></span>
                    <span class="row-sub">
                        <?= ($h['account_name'] ?: '') . ($h['mask'] ? ' ••' . e($h['mask']) : '') ?>
                        <?php if ($h['quantity'] !== null): ?>· <?= e(number_format((float)$h['quantity'], 4)) ?> @ <?= e(usd($h['institution_price'])) ?><?php endif; ?>
                    </span>
                </span>
                <span class="row-amt"><?= $h['institution_value'] !== null ? e(usd($h['institution_value'])) : '—' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php render_footer(); ?>
