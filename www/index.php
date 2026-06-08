<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$accounts = q_accounts($pdo, $uid);
$homeVal  = q_home_value($pdo);            // estimated house value (0 if none)
$stats    = q_stats($accounts, $homeVal);  // net worth includes the home as an asset
$snaps    = q_networth($pdo);
$change   = q_networth_change($pdo, $stats['net_worth'], 30);
$spend30  = q_spending_total($pdo, $uid, 30);
$topSpend = array_slice(q_spending($pdo, $uid, 30), 0, 4);
$home     = q_home_equity($pdo, $accounts);
$ret      = q_retirement_summary($pdo, $uid); // combined 401(k) total (0 accounts = hidden)

render_header('Dashboard', 'dashboard', ['chart' => true]);
?>

<?php if (!$accounts): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('bank') ?></span>
        <h2>No accounts linked yet</h2>
        <p class="muted">Connect your first bank to start tracking balances, transactions, spending and net worth.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Net-worth hero -->
    <section class="hero card">
        <div class="hero-top">
            <span class="hero-label">Net worth</span>
            <?php if ($change['pct'] !== null): ?>
                <?php $up = $change['pct'] >= 0; ?>
                <span class="delta <?= $up ? 'up' : 'down' ?>">
                    <?= $up ? '▲' : '▼' ?> <?= number_format(abs($change['pct']), 1) ?>%
                    <span class="delta-sub">30d</span>
                </span>
            <?php endif; ?>
        </div>
        <div class="hero-value"><?= e(usd($stats['net_worth'])) ?></div>

        <?php if (count($snaps) > 1): ?>
            <div class="sparkline">
                <canvas id="nw-spark" data-chart="spark" data-src="nw-spark-data" height="64"></canvas>
            </div>
            <script type="application/json" id="nw-spark-data"><?= json_encode([
                'labels' => array_column($snaps, 'snapshot_date'),
                'values' => array_map('floatval', array_column($snaps, 'net_worth')),
            ], JSON_UNESCAPED_SLASHES) ?></script>
        <?php endif; ?>

        <div class="hero-split">
            <a class="split-cell" href="/networth.php">
                <span class="split-label">Assets</span>
                <span class="split-value pos"><?= e(usd($stats['assets'])) ?></span>
            </a>
            <a class="split-cell" href="/networth.php">
                <span class="split-label">Liabilities</span>
                <span class="split-value neg"><?= e(usd($stats['liabilities'])) ?></span>
            </a>
        </div>
    </section>

    <?php if ($home): ?>
    <!-- Home value vs. mortgage → equity (RentCast AVM, refreshed ~monthly) -->
    <section class="hero card">
        <div class="hero-top">
            <span class="hero-label"><?= $home['equity'] !== null ? 'Home equity' : 'Home value' ?></span>
            <span class="delta-sub muted">est. <?= e($home['as_of']) ?></span>
        </div>
        <div class="hero-value"><?= e(usd($home['equity'] !== null ? $home['equity'] : $home['value'])) ?></div>
        <div class="hero-split">
            <div class="split-cell">
                <span class="split-label">Home value<?php
                    if ($home['value_low'] !== null && $home['value_high'] !== null)
                        echo ' <span class="muted">(' . e(usd($home['value_low'])) . '–' . e(usd($home['value_high'])) . ')</span>';
                ?></span>
                <span class="split-value pos"><?= e(usd($home['value'])) ?></span>
            </div>
            <?php if ($home['mortgage_balance'] !== null): ?>
            <div class="split-cell">
                <span class="split-label"><?= e($home['mortgage_name']) ?></span>
                <span class="split-value neg">-<?= e(usd($home['mortgage_balance'])) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($ret['count'] > 0): ?>
    <!-- Retirement (manual 401(k)s) — combined total -->
    <section class="hero card">
        <div class="hero-top">
            <span class="hero-label">Retirement</span>
            <?php if ($ret['latest']): ?><span class="delta-sub muted">as of <?= e($ret['latest']) ?></span><?php endif; ?>
        </div>
        <div class="hero-value"><?= e(usd($ret['total'])) ?></div>
        <div class="hero-split">
            <a class="split-cell" href="/retirement.php">
                <span class="split-label">Accounts</span>
                <span class="split-value"><?= (int)$ret['count'] ?></span>
            </a>
            <a class="split-cell" href="/retirement.php">
                <span class="split-label">Projection</span>
                <span class="split-value">View ›</span>
            </a>
        </div>
    </section>
    <?php endif; ?>

    <div class="cols">
    <!-- Accounts (the hero of an account-centric dashboard), grouped by category -->
    <?php
    // Bucket by category, then keep ACCOUNT_GROUPS' canonical order. Within a group,
    // biggest balance first (debts compared by amount owed).
    $byCat = [];
    foreach ($accounts as $a) { $byCat[account_group($a)][] = $a; }
    foreach ($byCat as &$grp) {
        usort($grp, fn($x, $y) => abs((float)($y['balance_current'] ?? 0)) <=> abs((float)($x['balance_current'] ?? 0)));
    }
    unset($grp);
    ?>
    <section class="block">
        <div class="block-head">
            <h2>Your accounts</h2>
            <span class="count-pill"><?= count($accounts) ?></span>
        </div>

        <?php foreach (ACCOUNT_GROUPS as $cat => $label):
            if (empty($byCat[$cat])) continue;
            $rows = $byCat[$cat];
            // Group subtotal: assets add, debts subtract (so the figure reads as net for the group).
            $subtotal = 0.0;
            foreach ($rows as $a) {
                $b = (float)($a['balance_current'] ?? 0);
                $subtotal += is_liability($a) ? -abs($b) : $b;
            }
            $negTotal = $subtotal < 0;
        ?>
            <div class="inst-group">
                <div class="inst-name">
                    <span><?= e($label) ?></span>
                    <span class="inst-total <?= $negTotal ? 'neg' : '' ?>"><?= e(($negTotal ? '-' : '') . usd(abs($subtotal))) ?></span>
                </div>
                <div class="acct-list">
                    <?php foreach ($rows as $a):
                        $debt    = is_liability($a);
                        $bal     = (float)($a['balance_current'] ?? 0);
                        $errored = ($a['item_status'] ?? '') === 'error' || !empty($a['error_code']);
                        // Bank name now lives on the card (the group header is the category).
                        $sub = $a['institution_name'] ?: pretty_cat($a['subtype'] ?: $a['type']);
                    ?>
                    <a class="acct-card" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>">
                        <span class="acct-icon <?= $debt ? 'is-debt' : '' ?>"><?= nav_icon($debt ? 'invest' : 'bank') ?></span>
                        <span class="acct-main">
                            <span class="acct-name"><?= e($a['name'] ?: ($a['official_name'] ?: 'Account')) ?></span>
                            <span class="acct-sub">
                                <?= e($sub) ?><?= $a['mask'] ? ' · ••' . e($a['mask']) : '' ?><?= owner_suffix($a['owner_id'] ?? null) ?>
                                <?php if ($a['visibility'] === 'private'): ?><span class="mini-tag">private</span><?php endif; ?>
                                <?php if ($errored): ?><span class="mini-tag warn">needs attention</span><?php endif; ?>
                            </span>
                        </span>
                        <span class="acct-bal <?= $debt ? 'neg' : '' ?>">
                            <?= e(($debt && $bal > 0 ? '-' : '') . usd(abs($bal))) ?>
                        </span>
                        <span class="chev" aria-hidden="true">›</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- Spending snapshot -->
    <section class="block">
        <div class="block-head">
            <h2>Spending</h2>
            <a class="block-link" href="/spending.php">Last 30 days ›</a>
        </div>
        <a class="card spend-card" href="/spending.php">
            <div class="spend-total">
                <span class="spend-amt"><?= e(usd($spend30)) ?></span>
                <span class="muted">spent in the last 30 days</span>
            </div>
            <?php if ($topSpend): $maxCat = (float)$topSpend[0]['total']; ?>
            <div class="spend-bars">
                <?php foreach ($topSpend as $c): $w = $maxCat > 0 ? max(4, ($c['total'] / $maxCat) * 100) : 0; ?>
                <div class="spend-row">
                    <span class="spend-cat"><?= e(pretty_cat($c['category'])) ?></span>
                    <span class="spend-track"><span style="width:<?= round($w) ?>%"></span></span>
                    <span class="spend-val"><?= e(usd($c['total'])) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </a>
    </section>
    </div><!-- /.cols -->

<?php endif; ?>

<?php render_footer(); ?>
