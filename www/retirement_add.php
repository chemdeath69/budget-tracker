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
 */

$pdo = db();
$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /retirement_add.php');
        exit;
    }
    $provider   = trim((string)($_POST['provider'] ?? ''));
    $nickname   = trim((string)($_POST['nickname'] ?? ''));
    $visibility = ($_POST['visibility'] ?? 'shared') === 'private' ? 'private' : 'shared';

    if ($provider === '') {
        flash_set('error', 'Enter the plan provider (e.g. Fidelity, Vanguard, Empower).');
        header('Location: /retirement_add.php');
        exit;
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

render_header('Add a 401(k)', 'retirement', ['back' => '/retirement.php', 'narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

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
                   placeholder="e.g. Fidelity, Vanguard, Empower, Principal">
        </label>
        <label class="field">
            <span class="field-label">Nickname <span class="muted">(optional)</span></span>
            <input class="input" type="text" name="nickname" maxlength="120" placeholder="e.g. Acme Corp 401(k)">
        </label>
        <label class="field">
            <span class="field-label">Visibility</span>
            <select class="select" name="visibility">
                <option value="shared">Shared — both of you see it (counts in the combined total)</option>
                <option value="private">Private — only you</option>
            </select>
        </label>
        <button class="btn" type="submit">Create 401(k)</button>
    </form>
</section>

<?php render_footer(); ?>
