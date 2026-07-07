<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$admin = is_admin();          // admin-only sections: Users & access, Danger zone (migration 032)
// q_owned_accounts() (not q_accounts) so the owner can see + un-hide their HIDDEN
// accounts here — settings is the only surface that shows them (they're invisible
// everywhere else in the app, including their own account.php drill-down).
$owned  = q_owned_accounts($pdo, $uid);
// Accounts a bank has stopped reporting for ≥7 days (code review 5.9) → badge them below so
// the owner can drop a frozen balance from net worth via the Visibility control right there.
$staleMissing = [];
foreach (q_stale_missing_accounts($pdo, $uid) as $sm) $staleMissing[$sm['account_id']] = (int)$sm['missing_days'];
// How many accounts each Item owns — for the "Remove bank" confirm (removal is
// per-Item, so it deletes every account of that bank, not just the clicked row).
$itemAcctCount = [];
foreach ($owned as $a) { $itemAcctCount[$a['item_id']] = ($itemAcctCount[$a['item_id']] ?? 0) + 1; }
// Shared banks owned by OTHER members that an admin may re-link/refresh (Session 94).
$adminBanks = $admin ? q_household_relinkable_accounts($pdo, $uid) : [];
$alerts = q_alert_settings($pdo);   // household-shared notification prefs (TODO #14)
$theme  = user_prefs_theme(q_user_prefs($pdo, $uid));  // per-user Light/Dark/Auto (Phase 2)
$home   = home_config($pdo);        // UI-managed home/property setup (migration 031)
$homeStatus = $home['removed_on'] !== null
    ? 'Removed ' . (string)$home['removed_on']
    : ($home['address'] !== '' ? (string)$home['address'] : 'Not set — add your home to track its value');

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

<!-- Appearance (Phase 2 — per-user Light/Dark/Auto theme) -->
<section class="block">
    <div class="block-head"><h2>Appearance</h2></div>
    <div class="card">
        <div class="set-row">
            <span class="set-label">Theme
                <span class="set-sub">Light, dark, or follow your device</span></span>
            <span class="seg" id="theme-seg" role="group" aria-label="Theme">
                <?php foreach (['light' => 'Light', 'dark' => 'Dark', 'auto' => 'Auto'] as $val => $lbl):
                    $on = $theme === $val; ?>
                <button type="button" class="seg-btn<?= $on ? ' on' : '' ?>" data-theme="<?= e($val) ?>"
                        aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($lbl) ?></button>
                <?php endforeach; ?>
            </span>
        </div>
    </div>
</section>

<!-- Home (Phase 3 — the customizable dashboard) -->
<section class="block">
    <div class="block-head"><h2>Home</h2></div>
    <a class="card action-card" href="/customize_home.php">
        <span class="acct-icon"><?= nav_icon('home') ?></span>
        <span class="acct-main">
            <span class="acct-name">Customize home</span>
            <span class="acct-sub muted">Choose which cards show on your dashboard, their size &amp; order</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<!-- Home value & property (migration 031 — UI-managed home address + ownership %) -->
<section class="block">
    <div class="block-head"><h2>Home value &amp; property</h2></div>
    <a class="card action-card" href="/home_settings.php">
        <span class="acct-icon"><?= nav_icon('house') ?></span>
        <span class="acct-main">
            <span class="acct-name">Home value &amp; property</span>
            <span class="acct-sub muted"><?= e($homeStatus) ?></span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<!-- Banks & accounts -->
<section class="block">
    <div class="block-head"><h2>Banks &amp; accounts</h2></div>
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
    <a class="card action-card" href="/vehicle_add.php">
        <span class="acct-icon"><?= nav_icon('car') ?></span>
        <span class="acct-main">
            <span class="acct-name">Add a vehicle</span>
            <span class="acct-sub muted">A car, truck or motorcycle — decoded from its VIN, valued by depreciation, counts in net worth</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<!-- Manage owned accounts -->
<?php if ($owned): ?>
<section class="block">
    <div class="block-head"><h2>Your linked accounts</h2><span class="muted">Visibility &amp; connection</span></div>
    <?php if ($staleMissing): ?>
    <p class="muted load-note">Accounts flagged <em>no longer reported</em> have stopped appearing in your
        bank's data feed — their balance is frozen but still counts in your net worth. Set one to
        <strong>Hidden</strong> to drop it, or leave it if the bank is just temporarily quiet.</p>
    <?php endif; ?>
    <div class="rows">
        <?php foreach ($owned as $a):
            $errored = ($a['item_status'] ?? '') === 'error' || !empty($a['error_code']);
            $missDays = $staleMissing[$a['account_id']] ?? null; ?>
        <div class="row manage-row">
            <span class="row-main">
                <span class="row-title"><?= e($a['name'] ?: 'Account') ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?><?php if ($a['visibility'] === 'hidden'): ?> <span class="mini-tag">hidden</span><?php endif; ?></span>
                <span class="row-sub"><?= e($a['institution_name'] ?: '') ?><?= $errored ? ' · <span class="mini-tag warn">needs attention</span>' : '' ?><?php if ($missDays !== null): ?> · <span class="mini-tag warn">no longer reported (<?= $missDays ?>d)</span><?php endif; ?></span>
            </span>
            <span class="manage-controls">
                <select class="select vis-select" data-account="<?= e($a['account_id']) ?>" aria-label="Visibility">
                    <option value="shared"<?= $a['visibility'] === 'shared' ? ' selected' : '' ?>>Shared</option>
                    <option value="private"<?= $a['visibility'] === 'private' ? ' selected' : '' ?>>Private</option>
                    <option value="hidden"<?= $a['visibility'] === 'hidden' ? ' selected' : '' ?>>Hidden</option>
                </select>
                <?php if (($a['source'] ?? 'plaid') === 'manual'): ?>
                    <a class="btn-ghost sm" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>">Update</a>
                    <?php if ((int)$a['owner_id'] === (int)$uid): ?>
                    <button type="button" class="btn-ghost sm danger" data-unlink data-kind="manual"
                            data-item="<?= e($a['item_id']) ?>"
                            data-institution="<?= e($a['name'] ?: ($a['institution_name'] ?: 'this account')) ?>"
                            data-accounts="<?= (int)($itemAcctCount[$a['item_id']] ?? 1) ?>">Remove</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button type="button" class="btn-ghost sm" data-refresh data-item="<?= e($a['item_id']) ?>">Refresh</button>
                    <a class="btn-ghost sm" href="/link.php?item_id=<?= e(urlencode($a['item_id'])) ?>">Re-link</a>
                    <?php if ((int)$a['owner_id'] === (int)$uid): ?>
                    <button type="button" class="btn-ghost sm danger" data-unlink data-kind="plaid"
                            data-item="<?= e($a['item_id']) ?>"
                            data-institution="<?= e($a['institution_name'] ?: 'this bank') ?>"
                            data-accounts="<?= (int)($itemAcctCount[$a['item_id']] ?? 1) ?>">Remove</button>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Other members' banks (admin-only — re-link/refresh SHARED banks on their behalf, Session 94) -->
<?php if ($admin && $adminBanks): ?>
<section class="block">
    <div class="block-head"><h2>Other members&rsquo; banks</h2><span class="muted">Admin · re-link &amp; refresh</span></div>
    <div class="rows">
        <?php foreach ($adminBanks as $a):
            $errored = ($a['item_status'] ?? '') === 'error' || !empty($a['error_code']);
            $ownerNm = owner_first_name($a['owner_id'] ?? null);
            // DESTRUCTIVE Remove is offered only when EVERY account on this bank is shared,
            // so the admin can see everything that would be deleted (the server enforces it too).
            $itemTotal  = (int)($a['item_total_accounts'] ?? 0);
            $allShared  = $itemTotal > 0 && $itemTotal === (int)($a['item_shared_accounts'] ?? 0); ?>
        <div class="row manage-row">
            <span class="row-main">
                <span class="row-title"><?= e($a['name'] ?: 'Account') ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?></span>
                <span class="row-sub"><?= e($a['institution_name'] ?: '') ?><?= $ownerNm !== '' ? ' · ' . e($ownerNm) : '' ?><?= $errored ? ' · <span class="mini-tag warn">needs attention</span>' : '' ?></span>
            </span>
            <span class="manage-controls">
                <button type="button" class="btn-ghost sm" data-refresh data-item="<?= e($a['item_id']) ?>">Refresh</button>
                <a class="btn-ghost sm" href="/link.php?item_id=<?= e(urlencode($a['item_id'])) ?>">Re-link</a>
                <?php if ($allShared): ?>
                <button type="button" class="btn-ghost sm danger" data-unlink data-kind="plaid"
                        data-item="<?= e($a['item_id']) ?>"
                        data-institution="<?= e($a['institution_name'] ?: 'this bank') ?>"
                        data-accounts="<?= $itemTotal ?>">Remove</button>
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
                <span class="muted alert-sub">Email when a budgeted category passes the % below this month (once per category)</span></span>
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
                <span class="muted alert-sub">Email when a category's spend so far this month is 2× its 3-month average</span></span>
            <input type="checkbox" class="switch" data-alert="unusual_spend_enabled"<?= $alerts['unusual_spend_enabled'] ? ' checked' : '' ?>>
        </label>

        <label class="alert-row">
            <span class="alert-label">Bill reminders
                <span class="muted alert-sub">Email when a bill is due within the days below (once per due date)</span></span>
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

<?php if ($admin): ?>
<!-- Users & access (admin-only — DB-backed allowlist + roles, migration 032) -->
<section class="block">
    <div class="block-head"><h2>Users &amp; access</h2><span class="muted">Admin</span></div>
    <a class="card action-card" href="/users.php">
        <span class="acct-icon"><?= nav_icon('peers') ?></span>
        <span class="acct-main">
            <span class="acct-name">Manage users</span>
            <span class="acct-sub muted">Invite or remove people who can sign in, and set who's an admin</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>
<?php endif; ?>

<section class="block">
    <div class="block-head"><h2>Getting started</h2></div>
    <a class="card action-card" href="/setup.php">
        <span class="acct-icon"><?= nav_icon('home') ?></span>
        <span class="acct-main">
            <span class="acct-name">Setup checklist</span>
            <span class="acct-sub muted">Invite people, link a bank, add accounts &amp; your home</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<section class="block">
    <div class="block-head"><h2>Activity &amp; diagnostics</h2></div>
    <a class="card action-card" href="/activity.php">
        <span class="acct-icon"><?= nav_icon('activity') ?></span>
        <span class="acct-main">
            <span class="acct-name">Access logs &amp; sync status</span>
            <span class="acct-sub muted">Logins, page views, actions · nightly sync history</span>
        </span>
        <span class="chev" aria-hidden="true">›</span>
    </a>
</section>

<?php if ($admin): ?>
<!-- Danger zone (admin-only) — Factory reset -->
<section class="block">
    <div class="block-head"><h2>Danger zone</h2><span class="muted">Admin</span></div>
    <div class="card fr-card">
        <p class="fr-lead"><strong>Factory reset</strong> unlinks every bank and permanently deletes all
            financial data — accounts, transactions, holdings, budgets, goals, rules, the home setup, everything.
            Your sign-in accounts &amp; roles, audit logs, and personal display preferences are kept. This cannot be undone.</p>
        <form id="factory-reset-form" class="fr-form" autocomplete="off">
            <label class="fr-label" for="fr-confirm">Type <strong>FACTORY RESET</strong> to confirm</label>
            <div class="fr-row">
                <input type="text" id="fr-confirm" class="input fr-input" placeholder="FACTORY RESET" aria-label="Type FACTORY RESET to confirm">
                <button type="submit" id="fr-btn" class="btn fr-btn">Factory reset</button>
            </div>
        </form>
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

<?php render_footer(); ?>
