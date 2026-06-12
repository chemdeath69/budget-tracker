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
$stmtStatus = $manual ? manual_account_status($pdo, $acct) : null; // overdue-statement check (null = not monitored)
$bal     = (float)($acct['balance_current'] ?? 0);
$catOptions = transaction_category_options($pdo, $uid);

// Transactions: server-side filters (scoped to this account) + pagination.
$txPage = page_num('txpage');
$txCat  = trim((string)($_GET['category'] ?? ''));
$txFrom = trim((string)($_GET['from'] ?? ''));
$txTo   = trim((string)($_GET['to'] ?? ''));
$txQ    = trim((string)($_GET['q'] ?? ''));
$txMin  = trim((string)($_GET['amin'] ?? ''));   // amount-range filter (#12b) — dollar magnitude
$txMax  = trim((string)($_GET['amax'] ?? ''));
$txFilters = array_filter([
    'category' => $txCat, 'from' => $txFrom, 'to' => $txTo,
    'amin' => $txMin, 'amax' => $txMax, 'q' => $txQ,
], fn($v) => $v !== '');
$txHasFilters = (bool)$txFilters;
$txRowsRaw = q_transactions($pdo, $uid, $txFilters + [
    'account_id' => $accountId, 'limit' => PAGE_SIZE + 1, 'offset' => page_offset($txPage),
]);
$txHasNext = count($txRowsRaw) > PAGE_SIZE;
$txns      = array_slice($txRowsRaw, 0, PAGE_SIZE);
attach_tx_meta($pdo, $txns);   // notes/tags/splits for the page (#8)
$tagOptions = all_tags($pdo);  // add-tag autocomplete vocabulary (#8)
$txExport  = '/api/export.php?' . http_build_query(['account_id' => $accountId] + $txFilters);

$liabs   = $debt ? q_liabilities($pdo, $uid, $accountId) : [];
$holds   = $isInvest ? q_holdings($pdo, $uid, $accountId) : [];
$errored = ($acct['item_status'] ?? '') === 'error' || !empty($acct['error_code']);

// Investment activity for this account (#18). A Plaid brokerage / retirement account
// stores its activity in investment_transactions (NOT the transactions table, which is
// empty for it), so the regular Transactions list below would otherwise show nothing.
// Scope q_investment_activity() to THIS single account, mirroring retirement.php.
$invScope   = $isInvest ? [$accountId] : [];
$dPage      = page_num('dpage');   // dividends & interest
$itPage     = page_num('itpage');  // trades (distinct from the tx 'txpage')
$cPage      = page_num('cpage');   // contributions
$incomeRaw  = $invScope ? q_investment_activity($pdo, $uid, 'income', $invScope, PAGE_SIZE + 1, page_offset($dPage)) : [];
$incomeNext = count($incomeRaw) > PAGE_SIZE;
$income     = array_slice($incomeRaw, 0, PAGE_SIZE);
$tradesRaw  = $invScope ? q_investment_activity($pdo, $uid, 'trades', $invScope, PAGE_SIZE + 1, page_offset($itPage)) : [];
$tradesNext = count($tradesRaw) > PAGE_SIZE;
$trades     = array_slice($tradesRaw, 0, PAGE_SIZE);
$contribRaw = $invScope ? q_investment_activity($pdo, $uid, 'contributions', $invScope, PAGE_SIZE + 1, page_offset($cPage)) : [];
$contribNext = count($contribRaw) > PAGE_SIZE;
$contribs   = array_slice($contribRaw, 0, PAGE_SIZE);
$incomeTotal  = $invScope ? -q_investment_activity_total($pdo, $uid, 'income', $invScope) : 0.0;
$contribTotal = $invScope ? -q_investment_activity_total($pdo, $uid, 'contributions', $invScope) : 0.0;

$docPage = page_num('docpage');
$manualCfg = null; $mdocs = []; $taxes = []; $docHasNext = false;
if ($manual) {
    require_once __DIR__ . '/lib/manual/registry.php';
    $manualCfg = manual_type($acct['manual_type'] ?? '');
    $mdocsRaw = q_manual_documents($pdo, $accountId, PAGE_SIZE + 1, page_offset($docPage));
    $docHasNext = count($mdocsRaw) > PAGE_SIZE;
    $mdocs = array_slice($mdocsRaw, 0, PAGE_SIZE);
    $taxes = q_tax_summaries($pdo, $accountId);
}

$acctSubtitle = trim($acct['institution_name'] ?: '');
$acctOwner = owner_first_name($acct['owner_id'] ?? null);
if ($acctOwner !== '') $acctSubtitle = ($acctSubtitle !== '' ? $acctSubtitle . ' · ' : '') . $acctOwner;
render_header($acct['name'] ?: 'Account', '', [
    'back'     => '/index.php',
    'subtitle' => $acctSubtitle,
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

<?php if ($stmtStatus && $stmtStatus['overdue']): ?>
    <div class="notice warn">
        This account needs a new statement — <?= e(statement_overdue_label($stmtStatus)) ?>
        (expected <?= e(strtolower(statement_cadence_label($stmtStatus['cadence']))) ?>).
        <?php if ($owner): ?>
            <?php if (is_retirement($acct)): ?>
                <a href="/retirement_statement.php?account_id=<?= e(urlencode($accountId)) ?>">Add a statement ›</a>
            <?php else: ?>
                Upload the latest statement below.
            <?php endif; ?>
        <?php endif; ?>
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
<?php if ($manual && is_retirement($acct)): ?>
<section class="card">
    <h2>Retirement account</h2>
    <p class="muted">This 401(k) is kept current by entering each statement (balance + contributions)
        on the Retirement page, where it rolls into your combined total and projection.</p>
    <?php if ($owner): ?><a class="btn" href="/retirement_statement.php?account_id=<?= e(urlencode($accountId)) ?>">Add a statement</a><?php endif; ?>
    <a class="btn-ghost" href="/retirement.php">Open Retirement ›</a>
</section>
<?php elseif ($manual && $owner): ?>
<section class="card update-card">
    <h2>Update <?= e($manualCfg['label'] ?? 'account') ?></h2>
    <p class="muted">Upload a document to refresh balances, holdings and transactions. Re-uploading
        the same period replaces it — no duplicates. The document type is detected automatically.</p>
    <form method="post" action="/api/manual_upload.php" enctype="multipart/form-data" class="upload-form">
        <?= csrf_field() ?>
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
        <?php if (count($holds) > 8): ?>
        <div class="search-bar">
            <input type="search" class="search-input" data-filter="#acct-holdings" placeholder="Filter holdings…">
        </div>
        <?php endif; ?>
        <div class="rows" id="acct-holdings">
        <?php foreach ($holds as $h):
            $sec   = ($h['ticker_symbol'] ? $h['ticker_symbol'] . ' — ' : '') . ($h['security_name'] ?: '—');
            $val   = $h['institution_value'] !== null ? (float)$h['institution_value'] : null;
            $cb    = $h['cost_basis'] !== null ? (float)$h['cost_basis'] : null;
            $hgain = ($val !== null && $cb !== null) ? $val - $cb : null;
            $hpct  = ($hgain !== null && $cb != 0.0) ? round($hgain / abs($cb) * 100, 1) : null; ?>
            <div class="row" data-search="<?= e(strtolower($sec)) ?>">
                <span class="row-main">
                    <span class="row-title"><?= e($sec) ?></span>
                    <span class="row-sub">
                        <?php if ($h['quantity'] !== null): ?><?= e(number_format((float)$h['quantity'], 4)) ?> @ <?= e(usd($h['institution_price'])) ?><?php endif; ?>
                        <?php if ($cb !== null): ?> · cost <?= e(usd($cb)) ?><?php endif; ?>
                    </span>
                </span>
                <span class="row-side">
                    <span class="row-amt"><?= $val !== null ? e(usd($val)) : '—' ?></span>
                    <?php if ($hgain !== null): ?>
                        <span class="delta <?= $hgain >= 0 ? 'up' : 'down' ?>"><?= $hgain >= 0 ? '▲' : '▼' ?> <?= ($hgain >= 0 ? '+' : '−') . e(usd(abs($hgain))) ?><?php if ($hpct !== null): ?> (<?= e(number_format(abs($hpct), 1)) ?>%)<?php endif; ?></span>
                    <?php elseif ($val !== null): ?>
                        <span class="muted mini-tag">cost basis pending</span>
                    <?php endif; ?>
                </span>
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
    <?php render_pager($docPage, $docHasNext, ['account_id' => $accountId] + ($txPage > 1 ? ['txpage' => $txPage] : []) + $txFilters, 'docpage'); ?>
</section>
<?php endif; ?>

<!-- Owner controls -->
<?php if ($owner): ?>
<section class="card">
    <div class="control-row">
        <div>
            <strong>Display name</strong>
            <div class="muted">Rename how this account shows throughout the app. Leave blank to use the <?= $manual ? 'name you entered' : 'name from your bank' ?>: <?= e($acct['original_name'] ?: 'Account') ?>.</div>
        </div>
        <form class="name-form" data-account="<?= e($acct['account_id']) ?>">
            <input type="text" class="input name-input" maxlength="255"
                   value="<?= e($acct['display_name'] ?? '') ?>"
                   placeholder="<?= e($acct['original_name'] ?: 'Account') ?>"
                   aria-label="Account display name">
            <button type="submit" class="btn-ghost name-save">Save</button>
        </form>
    </div>
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
    <?php if ($manual):
        $cadRaw = $acct['statement_cadence'] ?? null;
        $cadVal = ($cadRaw === null || $cadRaw === '') ? 'auto' : (string)$cadRaw;
        $cadAuto = is_retirement($acct) ? 'Quarterly' : 'Monthly'; ?>
    <div class="control-row">
        <div>
            <strong>Statement cadence</strong>
            <div class="muted">How often this account expects a new statement. We warn on the dashboard when one is overdue. Auto = <?= e(strtolower($cadAuto)) ?> for this account; Off disables the warning.</div>
        </div>
        <select class="select cadence-select" data-account="<?= e($acct['account_id']) ?>">
            <option value="auto"<?= $cadVal === 'auto' ? ' selected' : '' ?>>Auto (<?= e($cadAuto) ?>)</option>
            <option value="monthly"<?= $cadVal === 'monthly' ? ' selected' : '' ?>>Monthly</option>
            <option value="quarterly"<?= $cadVal === 'quarterly' ? ' selected' : '' ?>>Quarterly</option>
            <option value="annually"<?= $cadVal === 'annually' ? ' selected' : '' ?>>Annually</option>
            <option value="off"<?= $cadVal === 'off' ? ' selected' : '' ?>>Off</option>
        </select>
    </div>
    <?php endif; ?>
    <?php if ($isInvest && !is_retirement($acct)):
        $rf = $acct['retirement_flag'] ?? null;
        $rfVal = $rf === null ? 'auto' : ((int)$rf === 1 ? 'yes' : 'no'); ?>
    <div class="control-row">
        <div>
            <strong>Retirement account</strong>
            <div class="muted">Show this on the Retirement page (with your 401(k)s) instead of Investments. Auto detects IRAs/401(k)s by type.</div>
        </div>
        <select class="select ret-select" data-account="<?= e($acct['account_id']) ?>">
            <option value="auto"<?= $rfVal === 'auto' ? ' selected' : '' ?>>Auto</option>
            <option value="yes"<?= $rfVal === 'yes' ? ' selected' : '' ?>>Yes</option>
            <option value="no"<?= $rfVal === 'no' ? ' selected' : '' ?>>No</option>
        </select>
    </div>
    <?php endif; ?>
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
<div class="col"><!-- transactions / investment activity -->

<!-- Investment activity (brokerage / retirement accounts store activity in
     investment_transactions, not the transactions table) -->
<?php if ($isInvest): ?>
    <?php render_investment_activity('Dividends & interest', $income, [
        'head_right'   => $incomeTotal > 0 ? '<span class="split-value pos">' . e(usd($incomeTotal)) . '</span>' : '',
        'page'         => $dPage,
        'has_next'     => $incomeNext,
        'pager_key'    => 'dpage',
        'pager_params' => ['account_id' => $accountId] + ($itPage > 1 ? ['itpage' => $itPage] : []) + ($cPage > 1 ? ['cpage' => $cPage] : []),
        'empty'        => 'No dividend or interest activity in the synced window.',
    ]); ?>
    <?php if ($trades || $itPage > 1): ?>
    <?php render_investment_activity('Recent trades', $trades, [
        'head_right'   => $trades ? '<span class="count-pill">' . count($trades) . '</span>' : '',
        'page'         => $itPage,
        'has_next'     => $tradesNext,
        'pager_key'    => 'itpage',
        'pager_params' => ['account_id' => $accountId] + ($dPage > 1 ? ['dpage' => $dPage] : []) + ($cPage > 1 ? ['cpage' => $cPage] : []),
        'empty'        => 'No trades in the synced window.',
    ]); ?>
    <?php endif; ?>
    <?php if ($contribs || $cPage > 1): ?>
    <?php render_investment_activity('Recent contributions', $contribs, [
        'head_right'   => $contribTotal > 0 ? '<span class="split-value pos">' . e(usd($contribTotal)) . '</span>' : '',
        'page'         => $cPage,
        'has_next'     => $contribNext,
        'pager_key'    => 'cpage',
        'pager_params' => ['account_id' => $accountId] + ($dPage > 1 ? ['dpage' => $dPage] : []) + ($itPage > 1 ? ['itpage' => $itPage] : []),
        'empty'        => 'No contributions in the synced window.',
    ]); ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Transactions for this account (hidden for brokerages with no cash transactions) -->
<?php if (!$isInvest || $txns || $txHasFilters): ?>
<section class="block">
    <div class="block-head">
        <h2>Transactions</h2>
        <a class="block-link" href="<?= e($txExport) ?>">Export CSV ›</a>
    </div>
    <form class="filter-bar" method="get" action="/account.php">
        <input type="hidden" name="account_id" value="<?= e($accountId) ?>">
        <div class="filter-search">
            <input type="search" name="q" class="search-input" value="<?= e($txQ) ?>" placeholder="Search merchant or category…">
        </div>
        <div class="filter-row">
            <select name="category" class="select" data-autosubmit aria-label="Filter by category">
                <option value="">All categories</option>
                <?php foreach ($catOptions as $o): ?>
                    <option value="<?= e($o['value']) ?>"<?= $txCat === $o['value'] ? ' selected' : '' ?>><?= e($o['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" class="input date-input" value="<?= e($txFrom) ?>" data-autosubmit aria-label="From date">
            <input type="date" name="to" class="input date-input" value="<?= e($txTo) ?>" data-autosubmit aria-label="To date">
            <input type="number" step="0.01" min="0" inputmode="decimal" name="amin" class="input amt-input" value="<?= e($txMin) ?>" data-autosubmit aria-label="Minimum amount" placeholder="Min $">
            <input type="number" step="0.01" min="0" inputmode="decimal" name="amax" class="input amt-input" value="<?= e($txMax) ?>" data-autosubmit aria-label="Maximum amount" placeholder="Max $">
            <button class="btn-ghost" type="submit">Filter</button>
            <?php if ($txHasFilters): ?><a class="btn-ghost" href="/account.php?account_id=<?= e(urlencode($accountId)) ?>">Clear</a><?php endif; ?>
        </div>
    </form>
    <div class="rows tx-list">
        <?php if (!$txns): ?>
            <p class="muted" style="padding:1rem"><?= $txHasFilters ? 'No transactions match these filters.' : 'No transactions yet.' ?></p>
        <?php else: foreach ($txns as $t):
            $merchant = $t['merchant_name'] ?: ($t['name'] ?: '—');
            $amt = (float)$t['amount']; ?>
        <div class="row tx-row">
            <span class="row-main">
                <span class="row-title"><?php if (!empty($t['logo_url'])): ?><img class="merchant-logo" src="<?= e($t['logo_url']) ?>" alt="" loading="lazy"><?php endif; ?><?= e($merchant) ?><?= $t['pending'] ? ' <span class="mini-tag">pending</span>' : '' ?></span>
                <span class="row-sub">
                    <span class="tx-date"><?= e($t['date']) ?></span>
                    <button type="button" class="cat-chip" data-tx="<?= e($t['transaction_id']) ?>"><?= $t['category'] ? e(pretty_cat($t['category'])) : 'Set category' ?></button>
                </span>
                <?= render_tx_meta($t) ?>
            </span>
            <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php render_pager($txPage, $txHasNext, ['account_id' => $accountId] + $txFilters + ($docPage > 1 ? ['docpage' => $docPage] : []), 'txpage'); ?>
</section>
<?php endif; ?>

</div><!-- /.col transactions / investment activity -->
</div><!-- /.cols aside -->

<script type="application/json" id="cat-options"><?= json_encode($catOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="tag-options"><?= json_encode($tagOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php render_footer(); ?>
