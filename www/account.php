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
$manual  = is_manual($acct);
$bal     = (float)($acct['balance_current'] ?? 0);
$txns    = q_transactions($pdo, $uid, ['account_id' => $accountId, 'limit' => 200]);
$catOptions = transaction_category_options($pdo, $uid);
$liabs   = $debt ? q_liabilities($pdo, $uid, $accountId) : [];
$holds   = $isInvest ? q_holdings($pdo, $uid, $accountId) : [];
$errored = ($acct['item_status'] ?? '') === 'error' || !empty($acct['error_code']);

$manualCfg = null; $mdocs = []; $taxes = [];
if ($manual) {
    require_once __DIR__ . '/lib/manual/registry.php';
    $manualCfg = manual_type($acct['manual_type'] ?? '');
    $mdocs = q_manual_documents($pdo, $accountId);
    $taxes = q_tax_summaries($pdo, $accountId);
}

render_header($acct['name'] ?: 'Account', '', [
    'back'     => '/index.php',
    'subtitle' => $acct['institution_name'] ?: '',
]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if ($errored): ?>
    <div class="notice warn">
        This connection needs attention<?= $acct['error_code'] ? ' (' . e($acct['error_code']) . ')' : '' ?>.
        <?php if ($owner): ?><a href="/link.php?item_id=<?= e(urlencode($acct['item_id'])) ?>">Re-link now ›</a><?php endif; ?>
    </div>
<?php endif; ?>

<div class="cols aside">
<div class="col"><!-- summary / details -->

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
    <?php if ($manual && $acct['last_updated_datetime']): ?>
    <div class="balance-asof muted">As of statement <?= e(substr((string)$acct['last_updated_datetime'], 0, 10)) ?></div>
    <?php endif; ?>
</section>

<!-- Manual update (owner only) -->
<?php if ($manual && $owner): ?>
<section class="card update-card">
    <h2>Update <?= e($manualCfg['label'] ?? 'account') ?></h2>
    <p class="muted">Upload a document to refresh balances, holdings and transactions. Re-uploading
        the same period replaces it — no duplicates. The document type is detected automatically.</p>
    <form method="post" action="/api/manual_upload.php" enctype="multipart/form-data" class="upload-form">
        <input type="hidden" name="account_id" value="<?= e($accountId) ?>">
        <input class="input file-input" type="file" name="document" accept="application/pdf,.pdf" required>
        <button class="btn" type="submit">Upload &amp; update</button>
    </form>
    <?php if ($manualCfg): ?>
    <p class="muted upload-hint">Accepted: <?= e(implode(' · ', array_values($manualCfg['doc_types']))) ?>.</p>
    <?php endif; ?>
</section>
<?php elseif ($manual && !$mdocs): ?>
<section class="card">
    <p class="muted">No documents uploaded yet. The owner can upload a statement to populate this account.</p>
</section>
<?php endif; ?>

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

<!-- Tax summary (manual 1099) -->
<?php if ($manual && $taxes): ?>
<section class="card">
    <h2>Tax summary (1099)</h2>
    <?php foreach ($taxes as $tx): ?>
    <div class="tax-year">
        <div class="tax-year-head"><strong><?= e($tx['tax_year']) ?></strong></div>
        <div class="kv-grid">
            <div><span class="muted">Ordinary dividends</span><strong><?= e(usd($tx['ordinary_dividends'])) ?></strong></div>
            <div><span class="muted">Qualified dividends</span><strong><?= e(usd($tx['qualified_dividends'])) ?></strong></div>
            <?php if ($tx['interest_income'] !== null): ?><div><span class="muted">Interest income</span><strong><?= e(usd($tx['interest_income'])) ?></strong></div><?php endif; ?>
            <?php if ($tx['capital_gain_distributions'] !== null): ?><div><span class="muted">Cap. gain distributions</span><strong><?= e(usd($tx['capital_gain_distributions'])) ?></strong></div><?php endif; ?>
            <?php if ($tx['proceeds'] !== null): ?><div><span class="muted">Sale proceeds</span><strong><?= e(usd($tx['proceeds'])) ?></strong></div><?php endif; ?>
            <?php if ($tx['net_gain_loss'] !== null): ?><div><span class="muted">Net gain/loss</span><strong><?= e(usd($tx['net_gain_loss'])) ?></strong></div><?php endif; ?>
            <?php if ($tx['federal_tax_withheld'] !== null): ?><div><span class="muted">Fed. tax withheld</span><strong><?= e(usd($tx['federal_tax_withheld'])) ?></strong></div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- Uploaded documents (manual) -->
<?php if ($manual && $mdocs): ?>
<section class="block">
    <div class="block-head"><h2>Uploaded documents</h2></div>
    <div class="rows">
        <?php foreach ($mdocs as $d):
            $label = $manualCfg['doc_types'][$d['doc_type']] ?? ucfirst($d['doc_type']); ?>
        <div class="row">
            <span class="row-main">
                <span class="row-title"><?= e($label) ?> · <?= e($d['period_key']) ?></span>
                <span class="row-sub muted"><?= e($d['original_name'] ?: 'document.pdf') ?> · uploaded <?= e(substr((string)$d['uploaded_at'], 0, 10)) ?></span>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
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
    <?php if (!$manual): ?>
    <div class="control-row">
        <div>
            <strong>Connection</strong>
            <div class="muted">Re-link to fix errors or grant access to cards/investments.</div>
        </div>
        <a class="btn-ghost" href="/link.php?item_id=<?= e(urlencode($acct['item_id'])) ?>">Re-link</a>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

</div><!-- /.col summary -->
<div class="col"><!-- transactions -->

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

</div><!-- /.col transactions -->
</div><!-- /.cols aside -->

<script type="application/json" id="cat-options"><?= json_encode($catOptions, JSON_UNESCAPED_SLASHES) ?></script>

<?php render_footer(); ?>
