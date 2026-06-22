<?php
/**
 * Users & access — the admin allowlist manager (migration 032). ADMIN-ONLY.
 *
 * Lists every allowlisted account (role · status · last login · pending/protected/you),
 * lets an admin invite an email, enable/disable access (data is kept on disable),
 * promote/demote, and delete a pending invite. Writes go through api/users.php; the
 * page re-renders from the returned roster. Reached from Settings → Users & access.
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_admin();

$pdo        = db();
$uid        = (int)current_user_id();
$users      = q_users($pdo);
$breakGlass = config_break_glass_emails();

render_header('Users & access', 'settings', ['narrow' => true, 'back' => '/settings.php']);
?>

<div class="page-head">
    <p class="eyebrow">Setup</p>
    <h1>Users &amp; access</h1>
</div>

<!-- Who can sign in -->
<section class="block">
    <div class="block-head"><h2>Invite a user</h2></div>
    <div class="card">
        <form id="user-add" class="user-add" autocomplete="off">
            <input type="email" class="input" id="user-add-email" placeholder="name@example.com" aria-label="Email to invite" required>
            <select class="select" id="user-add-role" aria-label="Role">
                <option value="member" selected>Member</option>
                <option value="admin">Admin</option>
            </select>
            <button type="submit" class="btn">Invite</button>
        </form>
        <p class="muted user-oauth-note">
            ⚠️ Inviting an email here lets them in <em>once Google lets them reach the sign-in</em>.
            If your Google OAuth consent screen is in <strong>Testing</strong> mode, also add their address as a
            <strong>Test user</strong> in Google Cloud Console (APIs &amp; Services → OAuth consent screen). The
            one-time fix is to set that screen to <strong>In production</strong> — our sign-in uses only basic
            profile/email scopes, so no Google verification is needed — after which adding a user here is all it takes.
        </p>
    </div>
</section>

<!-- Roster -->
<section class="block">
    <div class="block-head"><h2>People</h2><span class="muted"><?= count($users) ?> total</span></div>
    <div class="rows" id="user-rows">
        <?php foreach ($users as $u):
            $id        = (int)$u['id'];
            $email     = (string)$u['email'];
            $isSelf    = $id === $uid;
            $protected = in_array(strtolower($email), $breakGlass, true);
            $pending   = (int)$u['has_logged_in'] === 0;
            $disabled  = ($u['status'] ?? 'active') === 'disabled';
            $isAdmin   = ($u['role'] ?? 'member') === 'admin';
            $items     = (int)$u['item_count'];
            $last      = $u['last_login_at'] ? time_ago((string)$u['last_login_at']) : 'never';
            // Role control + Disable are locked for: yourself, and break-glass config accounts.
            $lockMgmt  = $isSelf || $protected;
        ?>
        <div class="row manage-row user-row<?= $disabled ? ' is-disabled' : '' ?>" data-uid="<?= $id ?>">
            <span class="row-main">
                <span class="row-title">
                    <?= e($u['name'] ?: $email) ?>
                    <?php if ($isSelf): ?> <span class="mini-tag">you</span><?php endif; ?>
                    <?php if ($protected): ?> <span class="mini-tag">config admin</span><?php endif; ?>
                    <?php if ($pending): ?> <span class="mini-tag warn">pending</span><?php endif; ?>
                    <?php if ($disabled): ?> <span class="mini-tag warn">disabled</span><?php endif; ?>
                </span>
                <span class="row-sub">
                    <?= e($email) ?> ·
                    <?= $isAdmin ? 'Admin' : 'Member' ?> ·
                    last login <?= e($last) ?><?= $items > 0 ? ' · owns ' . $items . ' linked account' . ($items === 1 ? '' : 's') : '' ?>
                </span>
            </span>
            <span class="manage-controls">
                <?php if ($lockMgmt): ?>
                    <select class="select role-select" data-uid="<?= $id ?>" aria-label="Role" disabled>
                        <option value="admin"<?= $isAdmin ? ' selected' : '' ?>>Admin</option>
                        <option value="member"<?= !$isAdmin ? ' selected' : '' ?>>Member</option>
                    </select>
                <?php else: ?>
                    <select class="select role-select" data-uid="<?= $id ?>" aria-label="Role">
                        <option value="admin"<?= $isAdmin ? ' selected' : '' ?>>Admin</option>
                        <option value="member"<?= !$isAdmin ? ' selected' : '' ?>>Member</option>
                    </select>
                    <?php if ($disabled): ?>
                        <button type="button" class="btn-ghost sm" data-user-status="active" data-uid="<?= $id ?>">Enable</button>
                    <?php else: ?>
                        <button type="button" class="btn-ghost sm danger" data-user-status="disabled" data-uid="<?= $id ?>">Remove access</button>
                    <?php endif; ?>
                    <?php if ($pending && $items === 0): ?>
                        <button type="button" class="btn-ghost sm danger" data-user-delete data-uid="<?= $id ?>"
                                data-email="<?= e($email) ?>">Delete</button>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="muted" style="margin-top:12px">
        <strong>Remove access</strong> stops a person signing in but keeps their accounts, transactions and history
        (re-enable any time). Config-allowlist admins and your own account are protected from changes here.
    </p>
</section>

<?php render_footer(); ?>
