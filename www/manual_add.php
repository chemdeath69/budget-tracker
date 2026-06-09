<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/manual/registry.php';
require_login();

$pdo   = db();
$uid   = current_user_id();
$types = manual_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /manual_add.php');
        exit;
    }
    $type = (string)($_POST['manual_type'] ?? '');
    $name = trim((string)($_POST['nickname'] ?? ''));
    $cfg  = manual_type($type);
    if (!$cfg) {
        flash_set('error', 'Unknown account type.');
        header('Location: /manual_add.php');
        exit;
    }
    $itemId = 'mnl_' . bin2hex(random_bytes(16));
    $acctId = 'mnl_' . bin2hex(random_bytes(16));
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO items (item_id, user_id, source, manual_type, institution_name, status)
             VALUES (?,?,"manual",?,?,"active")'
        )->execute([$itemId, $uid, $type, $cfg['institution']]);
        $pdo->prepare(
            'INSERT INTO accounts (account_id, item_id, name, type, subtype, iso_currency_code, visibility)
             VALUES (?,?,?,?,?,"USD","shared")'
        )->execute([
            $acctId, $itemId, ($name !== '' ? $name : $cfg['label']),
            $cfg['account_type'], $cfg['account_subtype'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('manual_add error: ' . $e->getMessage());
        flash_set('error', 'Could not create the account.');
        header('Location: /manual_add.php');
        exit;
    }
    flash_set('ok', $cfg['label'] . ' account created. Upload a statement or document to populate it.');
    header('Location: /account.php?account_id=' . urlencode($acctId));
    exit;
}

render_header('Add manual account', 'settings', ['back' => '/settings.php', 'narrow' => true]);
?>

<section class="card">
    <h2>Manually-updated account</h2>
    <p class="muted">For institutions that aren't connected through Plaid. You keep it current
        by uploading documents (e.g. statements or tax forms); we read them and update balances,
        holdings, and transactions.</p>

    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <label class="field">
            <span class="field-label">Type</span>
            <select class="select" name="manual_type" required>
                <?php foreach ($types as $key => $cfg): ?>
                    <option value="<?= e($key) ?>"><?= e($cfg['label']) ?> — <?= e($cfg['blurb']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Nickname <span class="muted">(optional)</span></span>
            <input class="input" type="text" name="nickname" maxlength="120" placeholder="e.g. Webull Brokerage">
        </label>
        <button class="btn" type="submit">Create account</button>
    </form>
</section>

<?php render_footer(); ?>
