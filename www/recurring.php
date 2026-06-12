<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();
$rows = q_recurring($pdo, $uid);
// recurring_streams has no logo_url — reuse the logos Plaid stored on transactions, matched
// best-effort by merchant name (#5). Keyed lowercase by merchant_logo_map().
$logos = merchant_logo_map($pdo);

$out = array_filter($rows, fn($r) => $r['direction'] !== 'inflow');
$in  = array_filter($rows, fn($r) => $r['direction'] === 'inflow');

render_header('Recurring', 'recurring');

function recurring_rows(array $rows, array $logos): void
{
    // Click-through window for the merchant link: today − 90 days (PHP app-TZ date,
    // never MySQL CURDATE — S24 trap), matching the transactions.php #5 idiom.
    $merchFrom = date('Y-m-d', strtotime('-90 days'));
    foreach ($rows as $r):
        $amt = abs((float)$r['average_amount']);
        $inflow = $r['direction'] === 'inflow';
        $acct = ($r['account_name'] ?: '') . ($r['mask'] ? ' ••' . $r['mask'] : '');
        $name = $r['merchant_name'] ?: ($r['description'] ?: '—');
        $hay  = strtolower(($r['merchant_name'] ?: ($r['description'] ?: '')) . ' ' . pretty_cat($r['category_primary'] ?: '') . ' ' . $acct);
        $logo = $r['merchant_name'] ? ($logos[strtolower($r['merchant_name'])] ?? '') : ''; ?>
        <div class="row" data-search="<?= e($hay) ?>">
            <span class="row-main">
                <span class="row-title"><?php if ($logo): ?><img class="merchant-logo" src="<?= e($logo) ?>" alt="" loading="lazy"><?php endif; ?><?php if ($name !== '—'): ?><a href="/transactions.php?merchant=<?= rawurlencode($name) ?>&amp;from=<?= e($merchFrom) ?>"><?= e($name) ?></a><?php else: ?><?= e($name) ?><?php endif; ?></span>
                <span class="row-sub">
                    <?= e(pretty_cat($r['frequency'] ?: '')) ?>
                    <?php if ($r['category_primary']): ?>· <?= e(pretty_cat($r['category_primary'])) ?><?php endif; ?>
                    · <?php if (!empty($r['account_id']) && $acct !== ''): ?><a href="/account.php?account_id=<?= rawurlencode($r['account_id']) ?>"><?= e($acct) ?></a><?php else: ?><?= e($acct) ?><?php endif; ?><?= owner_suffix($r['owner_id'] ?? null) ?>
                </span>
            </span>
            <span class="row-amt <?= $inflow ? 'pos' : '' ?>"><?= ($inflow ? '+' : '') . e(usd($amt)) ?></span>
        </div>
    <?php endforeach;
}
?>

<?php if (!$rows): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('repeat') ?></span>
        <h2>No recurring activity detected</h2>
        <p class="muted">Plaid identifies subscriptions and recurring bills automatically once enough transaction history is synced.</p>
    </div>
<?php else: ?>
    <?php if (count($rows) > 8): ?>
    <div class="search-bar">
        <input type="search" class="search-input" data-filter="#recurring-lists" placeholder="Filter by name, category or account…">
    </div>
    <?php endif; ?>
    <div class="cols" id="recurring-lists">
    <?php if ($out): ?>
    <section class="block" data-filter-group>
        <div class="block-head"><h2>Subscriptions &amp; bills</h2><span class="count-pill"><?= count($out) ?></span></div>
        <div class="rows card"><?php recurring_rows($out, $logos); ?></div>
    </section>
    <?php endif; ?>

    <?php if ($in): ?>
    <section class="block" data-filter-group>
        <div class="block-head"><h2>Recurring income</h2><span class="count-pill"><?= count($in) ?></span></div>
        <div class="rows card"><?php recurring_rows($in, $logos); ?></div>
    </section>
    <?php endif; ?>
    </div><!-- /.cols -->
<?php endif; ?>

<?php render_footer(); ?>
