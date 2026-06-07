<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();
$accountId = (string)($_GET['account_id'] ?? '');
$acct = $accountId !== '' ? q_account($pdo, $uid, $accountId) : null;

if (!$acct) {
    render_header('Account', '', ['back' => '/index.php']);
    echo '<div class="empty-state card"><h2>Account not found</h2>'
       . '<p class="muted">It may have been removed, or you may not have access to it.</p>'
       . '<a class="btn" href="/index.php">Back to dashboard</a></div>';
    render_footer();
    exit;
}

$owner   = (int)$acct['owner_id'] === $uid;
$debt    = is_liability($acct);
$isInvest = ($acct['type'] ?? '') === 'investment';
$bal     = (float)($acct['balance_current'] ?? 0);
$txns    = q_transactions($pdo, $uid, ['account_id' => $accountId, 'limit' => 200]);
$liabs   = $debt ? q_liabilities($pdo, $uid, $accountId) : [];
$holds   = $isInvest ? q_holdings($pdo, $uid, $accountId) : [];
$errored = ($acct['item_status'] ?? '') === 'error' || !empty($acct['error_code']);

render_header($acct['name'] ?: 'Account', '', [
    'back'     => '/index.php',
    'subtitle' => $acct['institution_name'] ?: '',
]);
?>

<?php if ($errored): ?>
    <div class="notice warn">
        This connection needs attention<?= $acct['error_code'] ? ' (' . e($acct['error_code']) . ')' : '' ?>.
        <?php if ($owner): ?><a href="/link.php?item_id=<?= e(urlencode($acct['item_id'])) ?>">Re-link now ›</a><?php endif; ?>
    </div>
<?php endif; ?>

<!-- Balance summary -->
<section class="card balance-card">
    <div class="balance-main">
        <span class="balance-label"><?= $debt ? 'Balance owed' : 'Current balance' ?></span>
        <span class="balance-value <?= $debt ? 'neg' : '' ?>">
            <?= e(($debt && $bal > 0 ? '-' : '') . usd(abs($bal))) ?>
        </span>
        <span class="muted"><?= e(pretty_cat($acct['subtype'] ?: $acct['type'])) ?><?= $acct['mask'] ? ' · ••' . e($acct['mask']) : '' ?></span>
    </div>
    <div class="balance-grid">
        <?php if ($acct['balance_available'] !== null): ?>
        <div><span class="muted">Available</span><strong><?= e(usd($acct['balance_available'])) ?></strong></div>
        <?php endif; ?>
        <?php if ($acct['balance_limit'] !== null): ?>
        <div><span class="muted"><?= $debt ? 'Credit limit' : 'Limit' ?></span><strong><?= e(usd($acct['balance_limit'])) ?></strong></div>
        <?php endif; ?>
    </div>
</section>

<!-- Liability details -->
<?php foreach ($liabs as $l): ?>
<section class="card">
    <h2>Liability details</h2>
    <div class="kv-grid">
        <div><span class="muted">Type</span><strong><?= e(pretty_cat($l['liability_type'])) ?></strong></div>
        <?php if ($l['apr_percentage'] !== null): ?><div><span class="muted">APR</span><strong><?= e(number_format((float)$l['apr_percentage'], 2)) ?>%</strong></div><?php endif; ?>
        <?php if ($l['next_payment_due_date']): ?><div><span class="muted">Next due</span><strong><?= e($l['next_payment_due_date']) ?></strong></div><?php endif; ?>
        <?php if ($l['minimum_payment_amount'] !== null): ?><div><span class="muted">Min payment</span><strong><?= e(usd($l['minimum_payment_amount'])) ?></strong></div><?php endif; ?>
        <?php if ($l['last_payment_amount'] !== null): ?><div><span class="muted">Last payment</span><strong><?= e(usd($l['last_payment_amount'])) ?><?= $l['last_payment_date'] ? ' · ' . e($l['last_payment_date']) : '' ?></strong></div><?php endif; ?>
    </div>
</section>
<?php endforeach; ?>

<!-- Holdings -->
<?php if ($isInvest): ?>
<section class="card">
    <h2>Holdings</h2>
    <?php if (!$holds): ?>
        <p class="muted">No holdings reported for this account yet.</p>
    <?php else: ?>
        <div class="rows">
        <?php foreach ($holds as $h): $sec = ($h['ticker_symbol'] ? $h['ticker_symbol'] . ' — ' : '') . ($h['security_name'] ?: '—'); ?>
            <div class="row">
                <span class="row-main">
                    <span class="row-title"><?= e($sec) ?></span>
                    <span class="row-sub"><?= $h['quantity'] !== null ? e(number_format((float)$h['quantity'], 4)) . ' @ ' . e(usd($h['institution_price'])) : '' ?></span>
                </span>
                <span class="row-amt"><?= $h['institution_value'] !== null ? e(usd($h['institution_value'])) : '—' ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Owner controls -->
<?php if ($owner): ?>
<section class="card">
    <div class="control-row">
        <div>
            <strong>Visibility</strong>
            <div class="muted">Shared accounts are visible to both household members.</div>
        </div>
        <select class="select vis-select" data-account="<?= e($acct['account_id']) ?>">
            <option value="shared"<?= $acct['visibility'] === 'shared' ? ' selected' : '' ?>>Shared</option>
            <option value="private"<?= $acct['visibility'] === 'private' ? ' selected' : '' ?>>Private</option>
        </select>
    </div>
    <div class="control-row">
        <div>
            <strong>Connection</strong>
            <div class="muted">Re-link to fix errors or grant access to cards/investments.</div>
        </div>
        <a class="btn-ghost" href="/link.php?item_id=<?= e(urlencode($acct['item_id'])) ?>">Re-link</a>
    </div>
</section>
<?php endif; ?>

<!-- Transactions for this account -->
<section class="block">
    <div class="block-head">
        <h2>Transactions</h2>
        <a class="block-link" href="/api/export.php?account_id=<?= e(urlencode($accountId)) ?>">Export CSV ›</a>
    </div>
    <div class="search-bar">
        <input type="search" class="search-input" data-filter="#acct-tx" placeholder="Search merchant or category…">
    </div>
    <div class="rows tx-list" id="acct-tx">
        <?php if (!$txns): ?>
            <p class="muted" style="padding:1rem">No transactions yet.</p>
        <?php else: foreach ($txns as $t):
            $merchant = $t['merchant_name'] ?: ($t['name'] ?: '—');
            $amt = (float)$t['amount'];
            $hay = strtolower($merchant . ' ' . pretty_cat($t['category'])); ?>
        <div class="row tx-row" data-search="<?= e($hay) ?>">
            <span class="row-main">
                <span class="row-title"><?= e($merchant) ?><?= $t['pending'] ? ' <span class="mini-tag">pending</span>' : '' ?></span>
                <span class="row-sub">
                    <span class="tx-date"><?= e($t['date']) ?></span>
                    <button type="button" class="cat-chip" data-tx="<?= e($t['transaction_id']) ?>"><?= $t['category'] ? e(pretty_cat($t['category'])) : 'Set category' ?></button>
                </span>
            </span>
            <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>
</section>

<?php render_footer(); ?>
