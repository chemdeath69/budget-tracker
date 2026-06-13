<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/safe_to_spend.php';
require_login();

/**
 * "Safe to spend" (#31) — the single spending-plan figure:
 *   income − committed bills − monthly savings target − discretionary spent (MTD) = safe to spend.
 * The savings target is one household-shared value (spending_plan, id=1), edited by the form below
 * (a plain CSRF-guarded POST + flash + redirect, like retirement_settings.php — no app.js needed).
 * Income/bills/spend are VIS-scoped to the viewer (q_recurring/q_liabilities/q_true_expense_total).
 */

$pdo = db();
$uid = current_user_id();

/** "$1,234.50"/"" → float (blank/garbage → 0). */
function sts_num($v): float
{
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    $v = str_replace([',', '$', ' '], '', $v);
    return is_numeric($v) ? max(0.0, (float)$v) : 0.0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /safe_to_spend.php');
        exit;
    }
    $target = sts_num($_POST['monthly_savings_target'] ?? '');
    try {
        $pdo->prepare(
            'INSERT INTO spending_plan (id, monthly_savings_target, updated_by)
             VALUES (1, :t, :by)
             ON DUPLICATE KEY UPDATE monthly_savings_target = VALUES(monthly_savings_target),
                                     updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
        )->execute([':t' => $target, ':by' => $uid]);
    } catch (Throwable $e) {
        error_log('safe_to_spend save error: ' . $e->getMessage());
        flash_set('error', 'Could not save the savings target.');
        header('Location: /safe_to_spend.php');
        exit;
    }
    flash_set('ok', 'Monthly savings target saved.');
    header('Location: /safe_to_spend.php');
    exit;
}

$recur    = q_recurring($pdo, $uid);
$liab     = q_liabilities($pdo, $uid);
$plan     = q_spending_plan($pdo);
$today    = new DateTimeImmutable('today');
$monthFirst = $today->modify('first day of this month')->format('Y-m-d');
$tomorrow   = $today->add(new DateInterval('P1D'))->format('Y-m-d');   // half-open [first, tomorrow)
$spentMtd = q_true_expense_total($pdo, $uid, $monthFirst, $tomorrow);

$sp = safe_to_spend_build($recur, $liab, (float)$plan['monthly_savings_target'], $spentMtd, $today);
$targetVal = number_format($sp['savings_target'], 2, '.', '');   // '' separator → valid in the input

render_header('Safe to spend', 'safetospend', ['narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<!-- Safe-to-spend hero: the one number. -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Safe to spend</span>
        <span class="delta-sub muted"><?= e($sp['month_label']) ?></span>
    </div>
    <div class="hero-value <?= $sp['over'] ? 'neg' : '' ?>">
        <?= ($sp['safe'] < 0 ? '−' : '') . e(usd(abs($sp['safe']))) ?>
    </div>
    <?php if (!$sp['over'] && $sp['safe'] > 0): ?>
        <p class="muted" style="margin:2px 0 0">
            ≈ <strong><?= e(usd($sp['daily_left'])) ?>/day</strong> for the
            <?= (int)$sp['days_left'] ?> day<?= $sp['days_left'] === 1 ? '' : 's' ?> left this month
        </p>
    <?php endif; ?>

    <div class="hero-split tri">
        <div class="split-cell">
            <span class="split-label">Free to spend</span>
            <span class="split-value <?= $sp['plan'] < 0 ? 'neg' : '' ?>">
                <?= ($sp['plan'] < 0 ? '−' : '') . e(usd(abs($sp['plan']))) ?>
            </span>
        </div>
        <div class="split-cell">
            <span class="split-label">Spent so far</span>
            <span class="split-value"><?= e(usd($sp['spent'])) ?></span>
        </div>
        <div class="split-cell">
            <span class="split-label">Days left</span>
            <span class="split-value"><?= (int)$sp['days_left'] ?></span>
        </div>
    </div>

    <?php if ($sp['plan'] > 0): ?>
    <div class="budget-bar<?= $sp['over'] ? ' over' : '' ?>" style="margin-top:14px"
         role="img" aria-label="Spent <?= e(usd($sp['spent'])) ?> of <?= e(usd($sp['plan'])) ?> free to spend">
        <span style="width:<?= (int)$sp['spent_pct'] ?>%"></span>
    </div>
    <?php endif; ?>
</section>

<?php if ($sp['over']): ?>
    <div class="notice warn">
        ⚠️ You're <strong><?= e(usd(abs($sp['safe']))) ?> over</strong> your free-to-spend for
        <?= e($sp['month_label']) ?> — discretionary spending so far has passed what's left after
        income, bills and savings. Ease off or move money from savings to stay on plan.
    </div>
<?php elseif ($sp['income_count'] === 0): ?>
    <div class="notice warn">
        No recurring income was detected, so this plan can't estimate what's coming in this month —
        only committed bills, your savings target and what you've already spent are counted. Once a
        regular paycheck/deposit is recognized it'll be added automatically.
    </div>
<?php elseif (!$sp['has_income']): ?>
    <div class="notice warn">
        Recurring income was detected, but Plaid hasn't reported an amount for it yet — so it can't be
        added to this plan until it does.
    </div>
<?php endif; ?>

<!-- The plan, line by line. -->
<section class="card">
    <h2>This month's plan</h2>
    <div class="sts-breakdown">
        <div class="sts-line">
            <span class="sts-label"><a href="/recurring.php">Expected income</a></span>
            <span class="sts-amt pos">+<?= e(usd($sp['income'])) ?></span>
        </div>
        <div class="sts-line">
            <span class="sts-label"><a href="/bills.php">Committed bills</a></span>
            <span class="sts-amt neg">−<?= e(usd($sp['bills'])) ?></span>
        </div>
        <div class="sts-line">
            <span class="sts-label">Monthly savings target</span>
            <span class="sts-amt neg">−<?= e(usd($sp['savings_target'])) ?></span>
        </div>
        <div class="sts-line sts-total">
            <span class="sts-label">Free to spend this month</span>
            <span class="sts-amt <?= $sp['plan'] < 0 ? 'neg' : '' ?>">
                <?= ($sp['plan'] < 0 ? '−' : '') . e(usd(abs($sp['plan']))) ?>
            </span>
        </div>
        <div class="sts-line">
            <span class="sts-label">Discretionary spent so far</span>
            <span class="sts-amt neg">−<?= e(usd($sp['spent'])) ?></span>
        </div>
        <div class="sts-line sts-total">
            <span class="sts-label">Safe to spend</span>
            <span class="sts-amt <?= $sp['over'] ? 'neg' : 'pos' ?>">
                <?= ($sp['safe'] < 0 ? '−' : '') . e(usd(abs($sp['safe']))) ?>
            </span>
        </div>
    </div>
    <p class="muted load-note">
        "Spent so far" is your real everyday spending this month (same true-expense basis as Cash flow
        &amp; Spending). "Committed bills" counts this month's loan/card payments plus subscriptions still
        <em>upcoming</em> — a subscription already charged this month shows under spending, not bills, so
        nothing is double-counted. Only Plaid-detected recurring income is counted; irregular income isn't.
    </p>
</section>

<!-- Set the one number the owner controls. -->
<section class="card">
    <h2>Monthly savings target</h2>
    <p class="muted">How much you aim to set aside each month. It's subtracted before "free to spend",
        so the safe number is what's truly left for everyday spending. Shared across the household.</p>
    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <label class="field">
            <span class="field-label">Target per month <span class="muted">(0 = none)</span></span>
            <input class="input" type="text" inputmode="decimal" name="monthly_savings_target"
                   value="<?= e($targetVal === '0.00' ? '' : $targetVal) ?>" placeholder="e.g. 1,000">
        </label>
        <button class="btn" type="submit">Save target</button>
    </form>
</section>

<?php render_footer(); ?>
