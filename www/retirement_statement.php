<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/retirement.php';   // ret_period_key()
require __DIR__ . '/lib/sync.php';          // write_networth_snapshot()
require_login();

/**
 * Enter (or correct) a quarterly 401(k) statement. Owner-only — you record your own
 * mailings. A statement is one row in retirement_statements, bucketed by quarter
 * (account_id, period_key): re-entering a quarter UPDATES it. Saving also refreshes
 * the account's current balance, the per-account balance history and today's
 * net-worth snapshot, so the dashboard + net worth reflect it immediately.
 */

$pdo = db();
$uid = current_user_id();

/** Parse a money field ("$1,234.50", "1234.5", "") → float|null. */
function ret_num($v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([',', '$', ' '], '', $v);
    return is_numeric($v) ? (float)$v : null;
}

// Only the owner of a *manual* 401(k) may record its statements. Statement entry
// overwrites balance_current + account_balance_history, which must never touch a
// Plaid-synced retirement account (q_retirement_accounts() also returns those) —
// is_retirement() narrows the list to hand-tracked 401(k)s, so the <select> can't
// offer a Plaid account and a forged POST for one fails the $valid check below.
$owned = array_values(array_filter(
    q_retirement_accounts($pdo, $uid),
    fn($a) => (int)$a['owner_id'] === $uid && is_retirement($a)
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /retirement_statement.php');
        exit;
    }
    $acctId = (string)($_POST['account_id'] ?? '');
    $valid  = null;
    foreach ($owned as $a) { if ($a['account_id'] === $acctId) { $valid = $a; break; } }

    $date = trim((string)($_POST['statement_date'] ?? ''));
    $bal  = ret_num($_POST['balance'] ?? '');
    $ts   = $date !== '' ? strtotime($date) : false;

    $err = null;
    if (!$valid)                              $err = 'Choose one of your 401(k) accounts.';
    elseif ($ts === false)                    $err = 'Enter a valid statement date.';
    elseif ($ts > strtotime(date('Y-m-d')))   $err = 'The statement date is in the future.';
    elseif ($bal === null || $bal < 0)        $err = 'Enter the statement balance.';

    if ($err) {
        flash_set('error', $err);
        header('Location: /retirement_statement.php' . ($acctId !== '' ? '?account_id=' . urlencode($acctId) : ''));
        exit;
    }

    $dateYmd = date('Y-m-d', $ts);
    $period  = ret_period_key($dateYmd);
    $ee  = ret_num($_POST['employee_contrib'] ?? '');
    $er  = ret_num($_POST['employer_contrib'] ?? '');
    $eey = ret_num($_POST['employee_ytd'] ?? '');
    $ery = ret_num($_POST['employer_ytd'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if ($note === '') $note = null;

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO retirement_statements
                (account_id, period_key, statement_date, balance, employee_contrib,
                 employer_contrib, employee_ytd, employer_ytd, note, created_by)
             VALUES (:a,:p,:d,:b,:ee,:er,:eey,:ery,:n,:by)
             ON DUPLICATE KEY UPDATE
                statement_date=VALUES(statement_date), balance=VALUES(balance),
                employee_contrib=VALUES(employee_contrib), employer_contrib=VALUES(employer_contrib),
                employee_ytd=VALUES(employee_ytd), employer_ytd=VALUES(employer_ytd),
                note=VALUES(note), updated_at=CURRENT_TIMESTAMP'
        )->execute([
            ':a' => $acctId, ':p' => $period, ':d' => $dateYmd, ':b' => $bal,
            ':ee' => $ee, ':er' => $er, ':eey' => $eey, ':ery' => $ery, ':n' => $note, ':by' => $uid,
        ]);

        // Current balance follows the most recent statement on file for this account.
        $latest = $pdo->prepare(
            'SELECT balance, statement_date FROM retirement_statements
             WHERE account_id = ? ORDER BY statement_date DESC, id DESC LIMIT 1'
        );
        $latest->execute([$acctId]);
        $lr = $latest->fetch();
        if ($lr) {
            $pdo->prepare(
                'UPDATE accounts SET balance_current = ?, last_updated_datetime = ? WHERE account_id = ?'
            )->execute([$lr['balance'], $lr['statement_date'] . ' 00:00:00', $acctId]);
        }

        // Real history point at the statement date (charts + account detail).
        $pdo->prepare(
            'INSERT INTO account_balance_history (account_id, snapshot_date, balance)
             VALUES (?,?,?) ON DUPLICATE KEY UPDATE balance = VALUES(balance)'
        )->execute([$acctId, $dateYmd, $bal]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('retirement_statement error: ' . $e->getMessage());
        flash_set('error', 'Could not save the statement.');
        header('Location: /retirement_statement.php?account_id=' . urlencode($acctId));
        exit;
    }

    // Fold the new balance into today's net-worth snapshot right away.
    write_networth_snapshot($pdo);

    flash_set('ok', 'Statement saved for ' . $period . '.');
    header('Location: /retirement.php');
    exit;
}

// --- GET: render the form ---------------------------------------------------
$preAcct = (string)($_GET['account_id'] ?? '');

render_header('Add a statement', 'retirement', ['back' => '/retirement.php', 'narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if (!$owned): ?>
<section class="card">
    <h2>No 401(k) to update</h2>
    <p class="muted">You don't own a manually-tracked 401(k) yet. Add one first, then enter its statements.</p>
    <a class="btn" href="/retirement_add.php">Add a 401(k)</a>
</section>
<?php else: ?>
<section class="card">
    <h2>Enter a statement</h2>
    <p class="muted">Copy the figures off your latest 401(k) statement. Contributions are for this
        statement's period (your contribution and the employer match) — they let us separate market
        growth from deposits and derive your return. Re-entering the same quarter replaces it.</p>

    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <label class="field">
            <span class="field-label">Account</span>
            <select class="select" name="account_id" required>
                <?php foreach ($owned as $a): ?>
                    <option value="<?= e($a['account_id']) ?>"<?= $a['account_id'] === $preAcct ? ' selected' : '' ?>>
                        <?= e($a['name'] ?: '401(k)') ?><?= $a['institution_name'] ? ' — ' . e($a['institution_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Statement date</span>
            <input class="input" type="date" name="statement_date" max="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <label class="field">
            <span class="field-label">Total balance</span>
            <input class="input" type="text" inputmode="decimal" name="balance" required placeholder="e.g. 128,450.00">
        </label>

        <div class="cols">
            <label class="field">
                <span class="field-label">Your contribution <span class="muted">(this period)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employee_contrib" placeholder="optional">
            </label>
            <label class="field">
                <span class="field-label">Employer match <span class="muted">(this period)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employer_contrib" placeholder="optional">
            </label>
        </div>
        <div class="cols">
            <label class="field">
                <span class="field-label">Your contribution YTD <span class="muted">(optional)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employee_ytd" placeholder="optional">
            </label>
            <label class="field">
                <span class="field-label">Employer match YTD <span class="muted">(optional)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employer_ytd" placeholder="optional">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Note <span class="muted">(optional)</span></span>
            <input class="input" type="text" name="note" maxlength="200" placeholder="e.g. Q2 statement">
        </label>

        <button class="btn" type="submit">Save statement</button>
    </form>
</section>
<?php endif; ?>

<?php render_footer(); ?>
