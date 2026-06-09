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

// Server-side filters (each applied in q_transactions) + pagination.
$page  = page_num();
$fAcct = trim((string)($_GET['account_id'] ?? ''));
$fCat  = trim((string)($_GET['category'] ?? ''));
$fFrom = trim((string)($_GET['from'] ?? ''));
$fTo   = trim((string)($_GET['to'] ?? ''));
$fQ    = trim((string)($_GET['q'] ?? ''));
$filters = array_filter([
    'account_id' => $fAcct,
    'category'   => $fCat,
    'from'       => $fFrom,
    'to'         => $fTo,
    'q'          => $fQ,
], fn($v) => $v !== '');
$hasFilters = (bool)$filters;

// Fetch PAGE_SIZE + 1 so an extra row reveals there's an older page.
$rows    = q_transactions($pdo, $uid, $filters + ['limit' => PAGE_SIZE + 1, 'offset' => page_offset($page)]);
$hasNext = count($rows) > PAGE_SIZE;
$txns    = array_slice($rows, 0, PAGE_SIZE);

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
        <input type="date" name="from" class="input date-input" value="<?= e($fFrom) ?>" data-autosubmit aria-label="From date">
        <input type="date" name="to" class="input date-input" value="<?= e($fTo) ?>" data-autosubmit aria-label="To date">
        <button class="btn-ghost" type="submit">Filter</button>
        <?php if ($hasFilters): ?><a class="btn-ghost" href="/transactions.php">Clear</a><?php endif; ?>
    </div>
</form>

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
                    <span class="row-title"><?= e($merchant) ?><?= $t['pending'] ? ' <span class="mini-tag">pending</span>' : '' ?></span>
                    <span class="row-sub">
                        <button type="button" class="cat-chip" data-tx="<?= e($t['transaction_id']) ?>"><?= $t['category'] ? e(pretty_cat($t['category'])) : 'Set category' ?></button>
                        <span class="muted">· <?= e($acctLabel) ?><?= owner_suffix($t['owner_id'] ?? null) ?></span>
                    </span>
                </span>
                <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php render_pager($page, $hasNext, $filters); ?>
    </section>
<?php endif; ?>

<script type="application/json" id="cat-options"><?= json_encode($catOptions, JSON_UNESCAPED_SLASHES) ?></script>

<?php render_footer(); ?>
