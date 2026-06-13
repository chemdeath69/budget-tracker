<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Household-shared rules + the category picker (minus transfer/income targets — a rule
// must not silently drop spend from the true-expense reads; see RULE_CAT_BLOCKED).
$rules   = q_category_rules($pdo, $uid);
$custom  = q_custom_categories($pdo, $uid);
$catOpts = array_values(array_filter(
    transaction_category_options($pdo, $uid),
    fn($o) => !in_array($o['value'], RULE_CAT_BLOCKED, true)
));

render_header('Categories & rules', 'rules', ['narrow' => true]);
?>

<section class="card">
    <p class="muted intro-note">
        Create your own <strong>custom categories</strong> below, then assign them to transactions
        (click a transaction's category) or with a <strong>rule</strong> that recategorizes a merchant
        automatically. Rules apply everywhere — spending, cash flow, trends, budgets and the ledger —
        so a merchant only has to be fixed once. A category you set on a single transaction by hand
        still wins over a rule, and a split still wins over both.
    </p>
</section>

<!-- Custom categories -->
<section class="card" id="custom-categories">
    <div class="block-head"><h2>Custom categories</h2><span class="count-pill"><?= count($custom) ?></span></div>
    <p class="muted intro-note">
        Add categories beyond the built-in ones. Mark a category <em>“not spending”</em> to keep it out
        of your spending totals, budgets and trends (for reimbursements, internal moves, etc. — it works
        like a transfer).
    </p>
    <form id="add-category-form" class="cat-form" autocomplete="off">
        <div class="cat-form-row">
            <input id="cat-label" class="input" type="text" maxlength="96"
                   placeholder="e.g. Pet Care" aria-label="New category name">
            <button type="submit" class="btn">Add category</button>
        </div>
        <label class="cat-form-excl">
            <input id="cat-exclude" type="checkbox" class="switch">
            <span>Don’t count this as spending</span>
        </label>
    </form>

    <?php if ($custom): ?>
        <div class="catmgr-list">
            <?php foreach ($custom as $c):
                $uses = (int)$c['used_tx'] + (int)$c['used_rules'] + (int)$c['used_budgets'] + (int)$c['used_splits'];
                $excl = (int)$c['exclude_from_spending'] === 1; ?>
                <div class="catmgr-row">
                    <span class="catmgr-name">
                        <?= e($c['label']) ?>
                        <?php if ($excl): ?><span class="catmgr-tag">not spending</span><?php endif; ?>
                    </span>
                    <span class="catmgr-uses muted"><?= $uses ?> use<?= $uses === 1 ? '' : 's' ?></span>
                    <label class="catmgr-excl" title="Don’t count as spending">
                        <input type="checkbox" class="switch catmgr-exclude" data-id="<?= (int)$c['id'] ?>" <?= $excl ? 'checked' : '' ?>>
                    </label>
                    <button type="button" class="catmgr-rename" data-id="<?= (int)$c['id'] ?>"
                            data-label="<?= e($c['label']) ?>" aria-label="Rename this category">Rename</button>
                    <button type="button" class="catmgr-del" data-id="<?= (int)$c['id'] ?>"
                            data-label="<?= e($c['label']) ?>" data-uses="<?= $uses ?>" aria-label="Delete this category">×</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Add a rule -->
<section class="card" id="add-rule">
    <div class="block-head"><h2>Add a rule</h2></div>
    <form id="add-rule-form" class="rule-form" autocomplete="off">
        <div class="rule-form-row">
            <select id="rule-type" class="select" aria-label="Match type">
                <option value="merchant">Merchant is</option>
                <option value="contains">Description contains</option>
            </select>
            <input id="rule-value" class="input" type="text" maxlength="255"
                   placeholder="e.g. STARBUCKS" aria-label="Text to match">
        </div>
        <div class="rule-form-row">
            <span class="rule-arrow muted">categorize as</span>
            <select id="rule-cat" class="select" aria-label="Category">
                <?php foreach ($catOpts as $o): ?>
                    <option value="<?= e($o['value']) ?>"><?= e($o['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Add rule</button>
        </div>
    </form>
</section>

<!-- Existing rules -->
<section class="block">
    <div class="block-head"><h2>Your rules</h2><span class="count-pill"><?= count($rules) ?></span></div>
    <?php if (!$rules): ?>
        <div class="empty-state card">
            <p class="muted">No rules yet. Add one above, or use the “＋ rule” shortcut next to any transaction.</p>
        </div>
    <?php else: ?>
        <div class="rule-list card">
            <?php foreach ($rules as $r):
                $n = (int)$r['match_count']; ?>
                <div class="rule-row">
                    <span class="rule-cond">
                        <span class="rule-kind"><?= $r['match_type'] === 'contains' ? 'contains' : 'merchant' ?></span>
                        <span class="rule-val"><?= e($r['match_value']) ?></span>
                    </span>
                    <span class="rule-to">→ <?= e(pretty_cat($r['category'])) ?></span>
                    <span class="rule-count muted"><?= $n ?> match<?= $n === 1 ? '' : 'es' ?></span>
                    <button type="button" class="rule-del" data-id="<?= (int)$r['id'] ?>" aria-label="Delete this rule">×</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
