<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
// q_owned_accounts() (not q_accounts) so the owner can see + un-hide their HIDDEN
// accounts here — settings is the only surface that shows them (they're invisible
// everywhere else in the app, including their own account.php drill-down).
$owned  = q_owned_accounts($pdo, $uid);
$alerts = q_alert_settings($pdo);   // household-shared notification prefs (TODO #14)

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
                <span class="row-title"><?= e($a['name'] ?: 'Account') ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?><?php if ($a['visibility'] === 'hidden'): ?> <span class="mini-tag">hidden</span><?php endif; ?></span>
                <span class="row-sub"><?= e($a['institution_name'] ?: '') ?><?= $errored ? ' · <span class="mini-tag warn">needs attention</span>' : '' ?></span>
            </span>
            <span class="manage-controls">
                <select class="select vis-select" data-account="<?= e($a['account_id']) ?>" aria-label="Visibility">
                    <option value="shared"<?= $a['visibility'] === 'shared' ? ' selected' : '' ?>>Shared</option>
                    <option value="private"<?= $a['visibility'] === 'private' ? ' selected' : '' ?>>Private</option>
                    <option value="hidden"<?= $a['visibility'] === 'hidden' ? ' selected' : '' ?>>Hidden</option>
                </select>
                <?php if (($a['source'] ?? 'plaid') === 'manual'): ?>
                    <a class="btn-ghost sm" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>">Update</a>
                <?php else: ?>
                    <button type="button" class="btn-ghost sm" data-refresh data-item="<?= e($a['item_id']) ?>">Refresh</button>
                    <a class="btn-ghost sm" href="/link.php?item_id=<?= e(urlencode($a['item_id'])) ?>">Re-link</a>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Alerts & notifications (TODO #14 — household-shared; either user may change) -->
<?php
// NB: number_format's default thousands separator (',') would produce e.g. "1,500",
// which is invalid in <input type=number> (the browser blanks it per the HTML spec) →
// the field would render empty and a save would write NULL. Pass '' for no separator.
$thrVal = rtrim(rtrim(number_format((float)$alerts['large_tx_threshold'], 2, '.', ''), '0'), '.');
?>
<section class="block">
    <div class="block-head"><h2>Alerts &amp; notifications</h2><span class="muted">Shared by both of you</span></div>
    <div class="card alert-card" id="alert-settings">
        <label class="alert-row">
            <span class="alert-label">Email alerts
                <span class="muted alert-sub">Master switch — turn off to silence every alert email</span></span>
            <input type="checkbox" class="switch" data-alert="email_enabled"<?= $alerts['email_enabled'] ? ' checked' : '' ?>>
        </label>

        <label class="alert-row">
            <span class="alert-label">Large transactions
                <span class="muted alert-sub">Email when a charge exceeds the threshold below</span></span>
            <input type="checkbox" class="switch" data-alert="large_tx_enabled"<?= $alerts['large_tx_enabled'] ? ' checked' : '' ?>>
        </label>
        <div class="alert-row sub">
            <span class="alert-label">Threshold ($)</span>
            <input type="number" inputmode="decimal" class="input alert-num" data-alert="large_tx_threshold"
                   aria-label="Large-transaction alert threshold in dollars"
                   value="<?= e($thrVal) ?>" min="0" step="10" placeholder="200">
        </div>

        <label class="alert-row">
            <span class="alert-label">Bank connection problems
                <span class="muted alert-sub">Email when a bank needs re-authentication</span></span>
            <input type="checkbox" class="switch" data-alert="connection_alert_enabled"<?= $alerts['connection_alert_enabled'] ? ' checked' : '' ?>>
        </label>

        <label class="alert-row">
            <span class="alert-label">Weekly digest
                <span class="muted alert-sub">Sunday-night summary email — net worth, spending &amp; upcoming bills</span></span>
            <input type="checkbox" class="switch" data-alert="digest_enabled"<?= $alerts['digest_enabled'] ? ' checked' : '' ?>>
        </label>

        <label class="alert-row">
            <span class="alert-label">Budget exceeded
                <span class="muted alert-sub">When a category nears its budget · takes effect when budget alerts ship</span></span>
            <input type="checkbox" class="switch" data-alert="budget_alert_enabled"<?= $alerts['budget_alert_enabled'] ? ' checked' : '' ?>>
        </label>
        <div class="alert-row sub">
            <span class="alert-label">Alert at (% of budget)</span>
            <input type="number" inputmode="numeric" class="input alert-num" data-alert="budget_alert_pct"
                   aria-label="Budget-exceeded alert at percent of budget"
                   value="<?= e((string)$alerts['budget_alert_pct']) ?>" min="1" max="100" step="5">
        </div>

        <label class="alert-row">
            <span class="alert-label">Unusual spending
                <span class="muted alert-sub">When a category is 2× its 3-month average · takes effect when budget alerts ship</span></span>
            <input type="checkbox" class="switch" data-alert="unusual_spend_enabled"<?= $alerts['unusual_spend_enabled'] ? ' checked' : '' ?>>
        </label>

        <label class="alert-row">
            <span class="alert-label">Bill reminders
                <span class="muted alert-sub">Upcoming bills · takes effect when reminders ship</span></span>
            <input type="checkbox" class="switch" data-alert="bill_reminder_enabled"<?= $alerts['bill_reminder_enabled'] ? ' checked' : '' ?>>
        </label>
        <div class="alert-row sub">
            <span class="alert-label">Days ahead</span>
            <input type="number" inputmode="numeric" class="input alert-num" data-alert="bill_reminder_days"
                   aria-label="Bill reminder days ahead"
                   value="<?= e((string)$alerts['bill_reminder_days']) ?>" min="1" max="60" step="1">
        </div>
    </div>
</section>

<section class="block">
    <a class="card action-card danger" href="/logout.php">
        <span class="acct-icon"><?= nav_icon('logout') ?></span>
        <span class="acct-main"><span class="acct-name">Sign out</span></span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<p class="muted load-note">Appearance follows your device's light/dark setting.</p>

<?php render_footer(); ?>
