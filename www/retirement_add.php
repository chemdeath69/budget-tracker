<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

/**
 * Create a manually-tracked 401(k). It's a manual account
 * (items.source='manual', manual_type='retirement_401k'; accounts.type='investment',
 * subtype='401k') so it counts toward net worth automatically. You then keep it
 * current by entering each statement on the Retirement page — there is no Plaid feed
 * and no document upload (these arrive as paper mailings from many providers).
 *
 * Session 110: this page is the last stop before a DUPLICATE account. A member who
 * couldn't record a statement on an existing 401(k) landed here and created a second
 * copy of it (double-counting its balance in net worth). Statement entry is now open to
 * anyone who can see the account, and this page additionally (a) lists the household's
 * existing 401(k)s with a direct "Add a statement" link, and (b) requires an explicit
 * confirmation before creating one whose provider matches an account already on file.
 */

$pdo = db();
$uid = current_user_id();

/** Manual 401(k)s already visible to this user — the duplicate-guard + "did you mean" list. */
$existing = array_values(array_filter(q_retirement_accounts($pdo, $uid), fn($a) => is_retirement($a)));

/** Loose name match so "Empower" ≈ "Empower 401(k)" ≈ "empower". */
function ret_add_norm(string $s): string
{
    $s = strtolower($s);
    $s = str_replace(['401(k)', '401k', 'plan'], ' ', $s);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $s));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /retirement_add.php');
        exit;
    }
    $provider   = trim((string)($_POST['provider'] ?? ''));
    $nickname   = trim((string)($_POST['nickname'] ?? ''));
    $visibility = ($_POST['visibility'] ?? 'shared') === 'private' ? 'private' : 'shared';
    $confirmed  = ($_POST['confirm_duplicate'] ?? '') === '1';

    if ($provider === '') {
        flash_set('error', 'Enter the plan provider (e.g. Fidelity, Vanguard, Empower).');
        header('Location: /retirement_add.php');
        exit;
    }

    // Duplicate guard: a 401(k) with this provider (or nickname) is already on file.
    if (!$confirmed) {
        $want = ret_add_norm($provider);
        $wantNick = $nickname !== '' ? ret_add_norm($nickname) : '';
        foreach ($existing as $a) {
            foreach ([(string)($a['institution_name'] ?? ''), (string)($a['name'] ?? '')] as $have) {
                $have = ret_add_norm($have);
                if ($have === '' || $want === '') continue;
                // Substring only for tokens long enough to be meaningful — a 1-2 char
                // provider would otherwise collide with almost any existing name.
                $sub = strlen($want) >= 3
                    && (str_contains($have, $want) || (strlen($have) >= 3 && str_contains($want, $have)));
                if ($have === $want || ($wantNick !== '' && $have === $wantNick) || $sub) {
                    flash_set('error',
                        '"' . $a['name'] . '" is already tracked. If that\'s the same plan, add your'
                        . ' statement to it instead of creating a second account — either of you can'
                        . ' update it. Otherwise tick "This really is a different plan" and save again.');
                    header('Location: /retirement_add.php?dup=' . urlencode($a['account_id'])
                        . '&provider=' . urlencode($provider) . '&nickname=' . urlencode($nickname));
                    exit;
                }
            }
        }
    }

    $itemId = 'mnl_' . bin2hex(random_bytes(16));
    $acctId = 'mnl_' . bin2hex(random_bytes(16));
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO items (item_id, user_id, source, manual_type, institution_name, status)
             VALUES (?,?,"manual","retirement_401k",?,"active")'
        )->execute([$itemId, $uid, $provider]);
        $pdo->prepare(
            'INSERT INTO accounts (account_id, item_id, name, type, subtype, iso_currency_code, visibility)
             VALUES (?,?,?,"investment","401k","USD",?)'
        )->execute([$acctId, $itemId, ($nickname !== '' ? $nickname : ($provider . ' 401(k)')), $visibility]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('retirement_add error: ' . $e->getMessage());
        flash_set('error', 'Could not create the account.');
        header('Location: /retirement_add.php');
        exit;
    }
    flash_set('ok', '401(k) added. Enter your latest statement to populate it.');
    header('Location: /retirement_statement.php?account_id=' . urlencode($acctId));
    exit;
}

// A blocked duplicate bounces back here with ?dup= (the account it collided with) so the
// form can re-offer that account, keep what was typed, and expose the override tick-box.
$dupId  = (string)($_GET['dup'] ?? '');
$dup    = null;
foreach ($existing as $a) { if ($a['account_id'] === $dupId) { $dup = $a; break; } }
$fProv  = (string)($_GET['provider'] ?? '');
$fNick  = (string)($_GET['nickname'] ?? '');

render_header('Add a 401(k)', 'retirement', ['back' => '/retirement.php', 'narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Invest</p>
    <h1>Add a 401(k)</h1>
</div>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if ($existing): ?>
<section class="card">
    <h2>Already tracked</h2>
    <p class="muted">If the plan you're holding is one of these, don't add it again — add your
        statement to it. Either household member can update any 401(k) they can see.</p>
    <div class="rows">
        <?php foreach ($existing as $a): ?>
        <div class="row">
            <span class="row-main">
                <span class="row-title"><a href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>"><?= e($a['name'] ?: '401(k)') ?></a></span>
                <span class="row-sub muted"><?= e($a['institution_name'] ?: 'Retirement') ?><?= owner_suffix($a['owner_id'] ?? null) ?></span>
            </span>
            <span class="row-side">
                <a class="btn-ghost sm" href="/retirement_statement.php?account_id=<?= e(urlencode($a['account_id'])) ?>">Add statement</a>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <h2>Manually-tracked 401(k)</h2>
    <p class="muted">For retirement plans that only send paper statements. You keep it current by
        entering each statement (balance + contributions) — we track the value, contributions and a
        combined retirement projection. It counts toward your net worth.</p>

    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <label class="field">
            <span class="field-label">Plan provider</span>
            <input class="input" type="text" name="provider" maxlength="120" required
                   value="<?= e($fProv) ?>" placeholder="e.g. Fidelity, Vanguard, Empower, Principal">
        </label>
        <label class="field">
            <span class="field-label">Nickname <span class="muted">(optional)</span></span>
            <input class="input" type="text" name="nickname" maxlength="120"
                   value="<?= e($fNick) ?>" placeholder="e.g. Acme Corp 401(k)">
        </label>
        <label class="field">
            <span class="field-label">Visibility</span>
            <select class="select" name="visibility">
                <option value="shared">Shared — both of you see it (counts in the combined total)</option>
                <option value="private">Private — only you</option>
            </select>
        </label>
        <?php if ($dup): ?>
        <label class="check-row">
            <input type="checkbox" class="switch" name="confirm_duplicate" value="1">
            <span class="field-label">This really is a different plan from
                &ldquo;<?= e($dup['name'] ?: '401(k)') ?>&rdquo; — create it anyway</span>
        </label>
        <?php endif; ?>
        <button class="btn" type="submit">Create 401(k)</button>
    </form>
</section>

<?php render_footer(); ?>
