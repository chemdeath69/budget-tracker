<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo   = db();
$uid   = current_user_id();
$goals = q_goals($pdo, $uid);

// Account picker: visible asset accounts only (a savings goal tracks an asset balance, not a
// credit card / loan). q_accounts is VIS-scoped, so a user only ties to accounts they can see.
$acctOptions = array_values(array_filter(
    q_accounts($pdo, $uid),
    fn($a) => !in_array($a['type'], ['credit', 'loan'], true)
));

$totalTarget  = array_sum(array_map(fn($g) => (float)$g['target'], $goals));
$totalCurrent = array_sum(array_map(fn($g) => (float)$g['current'], $goals));
$overallPct   = $totalTarget > 0 ? min(100, $totalCurrent / $totalTarget * 100) : 0;

render_header('Savings goals', 'goals', ['narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Worth</p>
    <h1>Savings goals</h1>
</div>

<section class="block">
    <div class="block-head">
        <h2>Your goals</h2>
        <button class="btn-ghost" id="add-goal-btn" type="button">+ Add</button>
    </div>

    <?php if ($goals): ?>
    <div class="card goals-summary">
        <div class="b-head">
            <span class="muted">Across <?= count($goals) ?> goal<?= count($goals) === 1 ? '' : 's' ?></span>
            <span class="muted"><?= e(usd($totalCurrent)) ?> / <?= e(usd($totalTarget)) ?></span>
        </div>
        <div class="budget-bar"><span style="width:<?= round($overallPct) ?>%"></span></div>
    </div>
    <?php endif; ?>

    <form id="add-goal-form" class="card goal-form" hidden>
        <input type="hidden" id="goal-id" value="">
        <label class="field">
            <span>Goal name</span>
            <input id="goal-name" class="input" type="text" maxlength="96" placeholder="Emergency fund">
        </label>
        <label class="field">
            <span>Target amount ($)</span>
            <input id="goal-target" class="input" type="number" min="1" step="1" placeholder="20000">
        </label>
        <label class="field">
            <span>Track progress from</span>
            <select id="goal-source" class="input">
                <option value="manual">Manual amount</option>
                <?php foreach ($acctOptions as $a): ?>
                    <option value="acct:<?= e($a['account_id']) ?>">
                        <?= e($a['name']) ?><?= $a['mask'] ? ' ••' . e($a['mask']) : '' ?> — <?= e($a['institution_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field" id="goal-current-field">
            <span>Current amount ($)</span>
            <input id="goal-current" class="input" type="number" min="0" step="1" placeholder="15000">
        </label>
        <div class="form-actions">
            <button class="btn" type="submit">Save goal</button>
            <button class="btn-ghost" type="button" id="goal-cancel">Cancel</button>
        </div>
    </form>

    <div id="goals-list" class="budgets-list">
        <?php if (!$goals): ?>
            <p class="muted" id="goals-empty">No goals yet. Add one to track progress toward a savings target.</p>
        <?php else: foreach ($goals as $g):
            $tied   = $g['tied'];
            // A goal tied to an account this viewer can't see (another user's private/hidden):
            // mask everything about the account and don't leak its id into the edit form.
            $hidden = !empty($g['private_hidden']);
            $src    = ($tied && !$hidden) ? 'acct:' . $g['account_id'] : 'manual'; ?>
        <div class="budget-row card" data-id="<?= (int)$g['id'] ?>"
             data-name="<?= e($g['name']) ?>"
             data-target="<?= e((string)$g['target']) ?>"
             data-source="<?= e($src) ?>"
             data-current="<?= e($hidden ? '' : (string)$g['current']) ?>">
            <div class="b-head">
                <span>
                    <?= e($g['name']) ?>
                    <?php if ($tied): ?>
                        <span class="goal-tag muted"><?= e($g['account_name']) ?><?= owner_suffix($g['owner_id']) ?></span>
                    <?php else: ?>
                        <span class="goal-tag muted">Manual</span>
                    <?php endif; ?>
                </span>
                <span class="muted"><?= e(usd($g['current'])) ?> / <?= e(usd($g['target'])) ?>
                    <?php if (!$hidden): ?><button class="goal-edit" data-id="<?= (int)$g['id'] ?>" type="button" aria-label="Edit goal">✎</button><?php endif; ?>
                    <button class="goal-del" data-id="<?= (int)$g['id'] ?>" type="button" aria-label="Delete goal">✕</button></span>
            </div>
            <div class="budget-bar<?= $g['reached'] ? ' reached' : '' ?>"><span style="width:<?= round($g['pct']) ?>%"></span></div>
            <p class="muted goal-foot">
                <?php if ($g['reached']): ?>🎯 Reached<?php else: ?><?= e(usd($g['remaining'])) ?> to go<?php endif; ?>
                · <?= round($g['pct']) ?>%
            </p>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <p class="muted load-note">Goals are shared across the household. Account-tied goals update from the
        account's balance automatically; manual goals hold the amount you enter.</p>
</section>

<?php render_footer(); ?>
