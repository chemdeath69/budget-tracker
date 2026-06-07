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
$stats    = q_stats($accounts);
$snaps    = q_networth($pdo);
$change   = q_networth_change($pdo, $stats['net_worth'], 30);
$spend30  = q_spending_total($pdo, $uid, 30);
$topSpend = array_slice(q_spending($pdo, $uid, 30), 0, 4);

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

    <!-- Accounts (the hero of an account-centric dashboard) -->
    <?php
    $byInst = [];
    foreach ($accounts as $a) { $byInst[$a['institution_name'] ?: 'Other'][] = $a; }
    ?>
    <section class="block">
        <div class="block-head">
            <h2>Your accounts</h2>
            <span class="count-pill"><?= count($accounts) ?></span>
        </div>

        <?php foreach ($byInst as $inst => $rows): ?>
            <div class="inst-group">
                <div class="inst-name"><?= e($inst) ?></div>
                <div class="acct-list">
                    <?php foreach ($rows as $a):
                        $debt    = is_liability($a);
                        $bal     = (float)($a['balance_current'] ?? 0);
                        $errored = ($a['item_status'] ?? '') === 'error' || !empty($a['error_code']);
                    ?>
                    <a class="acct-card" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>">
                        <span class="acct-icon <?= $debt ? 'is-debt' : '' ?>"><?= nav_icon($debt ? 'invest' : 'bank') ?></span>
                        <span class="acct-main">
                            <span class="acct-name"><?= e($a['name'] ?: ($a['official_name'] ?: 'Account')) ?></span>
                            <span class="acct-sub">
                                <?= $a['mask'] ? '••' . e($a['mask']) . ' · ' : '' ?><?= e(pretty_cat($a['subtype'] ?: $a['type'])) ?>
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

<?php endif; ?>

<?php render_footer(); ?>
