<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$txns = q_transactions($pdo, $uid, ['limit' => 300]);
$catOptions = transaction_category_options($pdo, $uid);

render_header('Transactions', 'transactions', ['narrow' => true]);
?>

<div class="search-bar sticky-search">
    <input type="search" class="search-input" data-filter="#all-tx" data-export="#export-link" placeholder="Search merchant or category…">
    <a class="btn-ghost" id="export-link" href="/api/export.php">CSV</a>
</div>

<?php if (!$txns): ?>
    <div class="empty-state card">
        <h2>No transactions yet</h2>
        <p class="muted">Once a bank is linked and synced, your transactions appear here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>
    <section class="block">
        <div class="rows tx-list" id="all-tx">
            <?php
            $lastDate = null;
            foreach ($txns as $t):
                $merchant = $t['merchant_name'] ?: ($t['name'] ?: '—');
                $amt = (float)$t['amount'];
                $acctLabel = ($t['account_name'] ?: '') . ($t['mask'] ? ' ••' . $t['mask'] : '');
                $hay = strtolower($merchant . ' ' . pretty_cat($t['category']) . ' ' . $acctLabel);
            ?>
            <?php if ($t['date'] !== $lastDate): $lastDate = $t['date']; ?>
                <div class="tx-day" data-daygroup><?= e($t['date']) ?></div>
            <?php endif; ?>
            <div class="row tx-row" data-search="<?= e($hay) ?>">
                <span class="row-main">
                    <span class="row-title"><?= e($merchant) ?><?= $t['pending'] ? ' <span class="mini-tag">pending</span>' : '' ?></span>
                    <span class="row-sub">
                        <button type="button" class="cat-chip" data-tx="<?= e($t['transaction_id']) ?>"><?= $t['category'] ? e(pretty_cat($t['category'])) : 'Set category' ?></button>
                        <span class="muted">· <?= e($acctLabel) ?></span>
                    </span>
                </span>
                <span class="row-amt <?= $amt < 0 ? 'pos' : '' ?>"><?= $amt < 0 ? '+' . e(usd(-$amt)) : e(usd($amt)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="muted load-note">Showing the most recent <?= count($txns) ?> transactions. Use search or export CSV for the full history.</p>
    </section>
<?php endif; ?>

<script type="application/json" id="cat-options"><?= json_encode($catOptions, JSON_UNESCAPED_SLASHES) ?></script>

<?php render_footer(); ?>
