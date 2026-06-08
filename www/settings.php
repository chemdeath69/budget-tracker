<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$accounts = q_accounts($pdo, $uid);
$owned = array_filter($accounts, fn($a) => (int)$a['owner_id'] === $uid);

render_header('Settings', 'settings', ['narrow' => true]);
?>

<!-- Profile -->
<section class="card profile-card">
    <?= user_avatar_html(true) ?>
    <div>
        <div class="profile-name"><?= e($_SESSION['name'] ?? '') ?></div>
        <div class="muted"><?= e($_SESSION['user_email'] ?? '') ?></div>
    </div>
</section>

<!-- Link -->
<section class="block">
    <div class="block-head"><h2>Banks</h2></div>
    <a class="card action-card" href="/link.php">
        <span class="acct-icon"><?= nav_icon('bank') ?></span>
        <span class="acct-main">
            <span class="acct-name">Link a new bank</span>
            <span class="acct-sub muted">Connect another institution via Plaid</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
    <a class="card action-card" href="/manual_add.php">
        <span class="acct-icon"><?= nav_icon('invest') ?></span>
        <span class="acct-main">
            <span class="acct-name">Add a manual account</span>
            <span class="acct-sub muted">For institutions not on Plaid (e.g. Webull) — update by uploading documents</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
    <a class="card action-card" href="/retirement_add.php">
        <span class="acct-icon"><?= nav_icon('nest') ?></span>
        <span class="acct-main">
            <span class="acct-name">Add a 401(k)</span>
            <span class="acct-sub muted">Retirement plans that only mail statements — update by entering each one</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<!-- Manage owned accounts -->
<?php if ($owned): ?>
<section class="block">
    <div class="block-head"><h2>Your linked accounts</h2><span class="muted">Visibility &amp; connection</span></div>
    <div class="rows">
        <?php foreach ($owned as $a):
            $errored = ($a['item_status'] ?? '') === 'error' || !empty($a['error_code']); ?>
        <div class="row manage-row">
            <span class="row-main">
                <span class="row-title"><?= e($a['name'] ?: 'Account') ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?></span>
                <span class="row-sub"><?= e($a['institution_name'] ?: '') ?><?= $errored ? ' · <span class="mini-tag warn">needs attention</span>' : '' ?></span>
            </span>
            <span class="manage-controls">
                <select class="select vis-select" data-account="<?= e($a['account_id']) ?>" aria-label="Visibility">
                    <option value="shared"<?= $a['visibility'] === 'shared' ? ' selected' : '' ?>>Shared</option>
                    <option value="private"<?= $a['visibility'] === 'private' ? ' selected' : '' ?>>Private</option>
                </select>
                <?php if (($a['source'] ?? 'plaid') === 'manual'): ?>
                    <a class="btn-ghost sm" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>">Update</a>
                <?php else: ?>
                    <a class="btn-ghost sm" href="/link.php?item_id=<?= e(urlencode($a['item_id'])) ?>">Re-link</a>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="block">
    <a class="card action-card danger" href="/logout.php">
        <span class="acct-icon"><?= nav_icon('logout') ?></span>
        <span class="acct-main"><span class="acct-name">Sign out</span></span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<p class="muted load-note">Appearance follows your device's light/dark setting.</p>

<?php render_footer(); ?>
