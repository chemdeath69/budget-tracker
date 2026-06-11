<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

$accounts   = q_accounts($pdo, $uid);
$catOptions = transaction_category_options($pdo, $uid);
$tagOptions = all_tags($pdo);   // tag vocabulary for the filter + add-tag autocomplete (#8)

// Server-side filters (each applied in q_transactions) + pagination.
$page  = page_num();
$fAcct = trim((string)($_GET['account_id'] ?? ''));
$fCat  = trim((string)($_GET['category'] ?? ''));
$fTag  = trim((string)($_GET['tag'] ?? ''));
$fFrom  = trim((string)($_GET['from'] ?? ''));
$fTo    = trim((string)($_GET['to'] ?? ''));
$fQ     = trim((string)($_GET['q'] ?? ''));
$fMerch = trim((string)($_GET['merchant'] ?? ''));   // exact-merchant filter (#5 leaderboard click-through)
$fMin   = trim((string)($_GET['amin'] ?? ''));        // amount-range filter (#12b) — dollar magnitude
$fMax   = trim((string)($_GET['amax'] ?? ''));
$filters = array_filter([
    'account_id' => $fAcct,
    'category'   => $fCat,
    'tag'        => $fTag,
    'merchant'   => $fMerch,
    'from'       => $fFrom,
    'to'         => $fTo,
    'amin'       => $fMin,
    'amax'       => $fMax,
    'q'          => $fQ,
], fn($v) => $v !== '');
$hasFilters = (bool)$filters;

// Fetch PAGE_SIZE + 1 so an extra row reveals there's an older page.
$rows    = q_transactions($pdo, $uid, $filters + ['limit' => PAGE_SIZE + 1, 'offset' => page_offset($page)]);
$hasNext = count($rows) > PAGE_SIZE;
$txns    = array_slice($rows, 0, PAGE_SIZE);
attach_tx_meta($pdo, $txns);   // notes/tags/splits for the page (#8)

// CSV export honours the active filters (same param names as api/export.php).
$exportHref = '/api/export.php' . ($filters ? '?' . http_build_query($filters) : '');

render_header('Transactions', 'transactions', ['narrow' => true]);
?>

<form class="filter-bar" method="get" action="/transactions.php">
    <div class="filter-search">
        <input type="search" name="q" class="search-input" value="<?= e($fQ) ?>" placeholder="Search merchant or category…">
        <a class="btn-ghost" href="<?= e($exportHref) ?>">CSV</a>
    </div>
    <div class="filter-row">
        <select name="account_id" class="select" data-autosubmit aria-label="Filter by account">
            <option value="">All accounts</option>
            <?php foreach ($accounts as $a): ?>
                <option value="<?= e($a['account_id']) ?>"<?= $fAcct === $a['account_id'] ? ' selected' : '' ?>><?= e(account_label($a)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="category" class="select" data-autosubmit aria-label="Filter by category">
            <option value="">All categories</option>
            <?php foreach ($catOptions as $o): ?>
                <option value="<?= e($o['value']) ?>"<?= $fCat === $o['value'] ? ' selected' : '' ?>><?= e($o['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($tagOptions): ?>
        <select name="tag" class="select" data-autosubmit aria-label="Filter by tag">
            <option value="">All tags</option>
            <?php foreach ($tagOptions as $tg): ?>
                <option value="<?= e($tg) ?>"<?= $fTag === $tg ? ' selected' : '' ?>>#<?= e($tg) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="date" name="from" class="input date-input" value="<?= e($fFrom) ?>" data-autosubmit aria-label="From date">
        <input type="date" name="to" class="input date-input" value="<?= e($fTo) ?>" data-autosubmit aria-label="To date">
        <input type="number" step="0.01" min="0" inputmode="decimal" name="amin" class="input amt-input" value="<?= e($fMin) ?>" data-autosubmit aria-label="Minimum amount" placeholder="Min $">
        <input type="number" step="0.01" min="0" inputmode="decimal" name="amax" class="input amt-input" value="<?= e($fMax) ?>" data-autosubmit aria-label="Maximum amount" placeholder="Max $">
        <button class="btn-ghost" type="submit">Filter</button>
        <?php if ($hasFilters): ?><a class="btn-ghost" href="/transactions.php">Clear</a><?php endif; ?>
    </div>
</form>

<?php if ($fMerch !== ''):
    // Merchant filter has no <select> in the bar (too many payees) — surface it as a removable
    // pill. The "remove" link keeps every OTHER active filter, dropping only `merchant`.
    $without = $filters; unset($without['merchant']);
    $clearHref = '/transactions.php' . ($without ? '?' . http_build_query($without) : ''); ?>
    <div class="active-filters">
        <span class="filter-pill">Merchant: <strong><?= e($fMerch) ?></strong>
            <a href="<?= e($clearHref) ?>" aria-label="Remove merchant filter">✕</a></span>
    </div>
<?php endif; ?>

<?php if (!$txns): ?>
    <?php if ($hasFilters): ?>
        <div class="empty-state card">
            <h2>No matching transactions</h2>
            <p class="muted">No transactions match these filters<?= $page > 1 ? ' on this page' : '' ?>.</p>
            <a class="btn" href="/transactions.php">Clear filters</a>
        </div>
    <?php else: ?>
        <div class="empty-state card">
            <h2>No transactions yet</h2>
            <p class="muted">Once a bank is linked and synced, your transactions appear here.</p>
            <a class="btn" href="/link.php">Link a bank account</a>
        </div>
    <?php endif; ?>
<?php else: ?>
    <section class="block">
        <div class="rows tx-list">
            <?php
            $lastDate = null;
            foreach ($txns as $t):
                $merchant = $t['merchant_name'] ?: ($t['name'] ?: '—');
                $amt = (float)$t['amount'];
                $acctLabel = ($t['account_name'] ?: '') . ($t['mask'] ? ' ••' . $t['mask'] : '');
            ?>
            <?php if ($t['date'] !== $lastDate): $lastDate = $t['date']; ?>
                <div class="tx-day"><?= e($t['date']) ?></div>
            <?php endif; ?>
            <div class="row tx-row">
                <span class="row-main">
                    <span class="row-title"><?php if (!empty($t['logo_url'])): ?><img class="merchant-logo" src="<?= e($t['logo_url']) ?>" alt="" loading="lazy"><?php endif; ?><?= e($merchant) ?><?= $t['pending'] ? ' <span class="mini-tag">pending</span>' : '' ?></span>
                    <span class="row-sub">
                        <button type="button" class="cat-chip" data-tx="<?= e($t['transaction_id']) ?>"><?= $t['category'] ? e(pretty_cat($t['category'])) : 'Set category' ?></button>
                        <span class="muted">· <?= e($acctLabel) ?><?= owner_suffix($t['owner_id'] ?? null) ?></span>
                    </span>
                    <?= render_tx_meta($t) ?>
                </span>
                <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php render_pager($page, $hasNext, $filters); ?>
    </section>
<?php endif; ?>

<script type="application/json" id="cat-options"><?= json_encode($catOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="tag-options"><?= json_encode($tagOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php render_footer(); ?>
