<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/fred.php';
require __DIR__ . '/lib/dashboard.php';     // widget catalog, per-user layout, the feed (Phase 3)
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$accounts = q_accounts($pdo, $uid);

// Per-user dashboard layout (UI redesign Phase 3). The bento, the Needs-Attention feed
// and the designer all read this; absent/invalid → the shipped default (dash_layout).
$layout   = dash_layout(q_user_prefs($pdo, $uid));
$on = [];                                   // widget => size, for the size!='off' cards (in order)
foreach ($layout['cards'] as $c) { if ($c['size'] !== 'off') $on[$c['widget']] = $c['size']; }
$active = fn(string $k): bool => isset($on[$k]);

render_header('Dashboard', 'dashboard', ['chart' => true]);

if (!$accounts):
?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('bank') ?></span>
        <h2>No accounts linked yet</h2>
        <p class="muted">Connect your first bank to start tracking balances, transactions, spending and net worth.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php
    render_footer();
    return;
endif;

/* ---- Data ---------------------------------------------------------------- */
// Core figures (cheap + reused by several widgets / the feed) are always computed.
$homeVal  = q_home_value($pdo);
$stats    = q_stats($accounts, $homeVal);
$snaps    = q_networth($pdo);
$change   = q_networth_change($pdo, $stats['net_worth'], 30);
$home     = q_home_equity($pdo, $accounts);
$ret      = q_retirement_summary($pdo, $uid);
$cf6      = q_cashflow($pdo, $uid, 6);
$cfRate   = $cf6['income'] > 0 ? ($cf6['net'] / $cf6['income']) * 100 : null;
$spend30  = q_spending_total($pdo, $uid, 30);

require_once __DIR__ . '/lib/bills.php';
$liab  = q_liabilities($pdo, $uid);
$recur = q_recurring($pdo, $uid);
$today = new DateTimeImmutable('today');
$billsSoon  = bill_occurrences($liab, $recur, $today, $today->add(new DateInterval('P14D')));
$billsTotal = 0.0;
foreach ($billsSoon as $b) $billsTotal += (float)($b['amount'] ?? 0);

require_once __DIR__ . '/lib/safe_to_spend.php';
$stsSpent = q_true_expense_total($pdo, $uid,
                                 $today->modify('first day of this month')->format('Y-m-d'),
                                 $today->add(new DateInterval('P1D'))->format('Y-m-d'));
$sts = safe_to_spend_build($recur, $liab, (float)q_spending_plan($pdo)['monthly_savings_target'], $stsSpent, $today);

require_once __DIR__ . '/lib/forecast.php';
$fcTeaser = forecast_build($accounts, $liab, $recur, q_avg_daily_spend($pdo, $uid, 90), 30, $today);
$fcHasCash = false;
foreach ($accounts as $a) { if (in_array(account_group($a), FORECAST_CASH_GROUPS, true)) { $fcHasCash = true; break; } }

require_once __DIR__ . '/lib/debt.php';
$debtPlan = build_debt_plan(q_debts($pdo, $uid), 0.0, false);

require_once __DIR__ . '/lib/refunds.php';
$rfView = build_refunds_view(q_refund_watches($pdo, $uid), [], date('Y-m-d'));

$goals    = q_goals($pdo, $uid);
$overdue  = q_manual_statement_status($pdo, $uid, true);
$overdueIds = array_column($overdue, 'account_id', 'account_id');
$lastSync = q_last_synced($pdo);
$hasPlaid = false;
foreach ($accounts as $a) { if (($a['source'] ?? 'plaid') === 'plaid') { $hasPlaid = true; break; } }

/* ---- Build the bento widget bundles (only for the active, data-bearing cards) ---- */
$widgets = [];   // widget key => render bundle (see dash_card_html)

if ($active('net_worth')) {
    $sub = '';
    if ($change['pct'] !== null) {
        $up = $change['pct'] >= 0;
        $sub = '<span class="' . ($up ? 'pos' : 'neg') . '">' . ($up ? '▲' : '▼') . ' ' . number_format(abs($change['pct']), 1) . '%</span> · 30d';
    }
    $sub .= ($sub ? ' · ' : '') . 'assets ' . e(usd($stats['assets'])) . ' · liabilities ' . e(usd($stats['liabilities']));
    $widgets['net_worth'] = [
        'href' => '/networth.php', 'eyebrow' => 'Net worth',
        'value' => usd($stats['net_worth']), 'sub' => $sub,
        'spark' => count($snaps) > 1 ? ['id' => 'nw-spark-data',
            'labels' => array_column($snaps, 'snapshot_date'),
            'values' => array_map('floatval', array_column($snaps, 'net_worth'))] : null,
    ];
}
if ($active('cash_on_hand')) {
    // Liquid cash = the live checking + savings balances already loaded in $accounts
    // (no extra query for the current figure); the trend/30d-delta comes from ABH.
    $cashTotal = 0.0; $cashCount = 0;
    foreach ($accounts as $a) {
        if (in_array(account_group($a), ['checking', 'savings'], true)) {
            $cashTotal += (float)($a['balance_current'] ?? 0);
            $cashCount++;
        }
    }
    if ($cashCount > 0) {
        $cashHist = q_cash_history($pdo, $uid, 365);
        $sub = '';
        if (count($cashHist) > 1) {
            // 30-day delta: current vs the latest history point on/before ~30 days ago.
            // Only shown when a ~30-day-old baseline actually exists (no fallback to the
            // earliest point — labeling a 17-day-old baseline "30d" would mislead).
            $cut  = (new DateTimeImmutable('today'))->modify('-30 day')->format('Y-m-d');
            $prev = null;
            foreach ($cashHist as $h) { if ($h['snapshot_date'] <= $cut) $prev = (float)$h['balance']; else break; }
            if ($prev !== null && abs($prev) >= 1.0) {
                $pct = ($cashTotal - $prev) / abs($prev) * 100;
                $up  = $pct >= 0;
                $sub = '<span class="' . ($up ? 'pos' : 'neg') . '">' . ($up ? '▲' : '▼') . ' ' . number_format(abs($pct), 1) . '%</span> · 30d';
            }
        }
        $sub .= ($sub ? ' · ' : '') . $cashCount . ' account' . ($cashCount === 1 ? '' : 's');
        $widgets['cash_on_hand'] = [
            'href' => '/cash.php', 'eyebrow' => 'Cash on hand',
            'value' => usd($cashTotal), 'sub' => $sub,
            'spark' => count($cashHist) > 1 ? ['id' => 'cash-spark-data',
                'labels' => array_column($cashHist, 'snapshot_date'),
                'values' => array_map(fn($h) => (float)$h['balance'], $cashHist)] : null,
        ];
    }
}
if ($active('safe_to_spend')) {
    $widgets['safe_to_spend'] = [
        'href' => '/safe_to_spend.php', 'eyebrow' => 'Safe to spend',
        'value' => ($sts['safe'] < 0 ? '−' : '') . usd(abs($sts['safe'])),
        'tone'  => $sts['over'] ? 'neg' : 'pos',
        'sub'   => '≈ ' . e(usd(max(0, $sts['daily_left']))) . '/day left this month',
    ];
}
if ($active('cash_flow') && ($cf6['income'] > 0 || $cf6['expense'] > 0)) {
    $widgets['cash_flow'] = [
        'href' => '/cashflow.php', 'eyebrow' => 'Cash flow · 6mo',
        'value' => ($cf6['net'] < 0 ? '−' : '+') . usd(abs($cf6['net'])),
        'tone'  => $cf6['net'] < 0 ? 'neg' : 'pos',
        'sub'   => $cfRate !== null ? ($cfRate < 0 ? '−' : '') . number_format(abs($cfRate), 0) . '% saved' : 'net, 6 months',
        'spark' => count($cf6['months']) > 1 ? ['id' => 'cf-spark-data',
            'labels' => array_column($cf6['months'], 'label'),
            'values' => array_map(fn($m) => round($m['net'], 2), $cf6['months'])] : null,
    ];
}
if ($active('investments')) {
    $holds = array_values(array_filter(q_holdings($pdo, $uid), fn($h) => !is_retirement_account($h)));
    if ($holds) {
        $iVal = 0.0; $iCost = 0.0; $iValCost = 0.0;
        foreach ($holds as $h) {
            $iVal += (float)($h['institution_value'] ?? 0);
            if ($h['cost_basis'] !== null) { $iCost += (float)$h['cost_basis']; $iValCost += (float)($h['institution_value'] ?? 0); }
        }
        $gain = $iValCost - $iCost; $gpct = $iCost > 0 ? $gain / $iCost * 100 : null;
        $widgets['investments'] = [
            'href' => '/investments.php', 'eyebrow' => 'Investments',
            'value' => usd($iVal),
            'tone'  => $gpct === null ? '' : ($gain >= 0 ? 'pos' : 'neg'),
            'sub'   => $gpct === null ? count($holds) . ' holding' . (count($holds) === 1 ? '' : 's')
                                      : ($gain >= 0 ? '▲' : '▼') . ' ' . number_format(abs($gpct), 1) . '%',
        ];
    }
}
if ($active('retirement') && $ret['count'] > 0) {
    $widgets['retirement'] = [
        'href' => '/retirement.php', 'eyebrow' => 'Retirement',
        'value' => usd($ret['total']),
        'sub'   => $ret['count'] . ' account' . ($ret['count'] === 1 ? '' : 's'),
    ];
}
if ($active('bills') && $billsSoon) {
    $widgets['bills'] = [
        'href' => '/bills.php', 'eyebrow' => 'Bills · 14d',
        'value' => usd($billsTotal),
        'sub'   => count($billsSoon) . ' due in 14 days',
    ];
}
if ($active('spending')) {
    $widgets['spending'] = [
        'href' => '/spending.php', 'eyebrow' => 'Spending · 30d',
        'value' => usd($spend30), 'sub' => 'spent in the last 30 days',
    ];
}
if ($active('goals') && $goals) {
    $gCur = 0.0; $gTar = 0.0; $gReached = 0;
    foreach ($goals as $g) { $gCur += (float)($g['current'] ?? 0); $gTar += (float)($g['target'] ?? 0); if (!empty($g['reached'])) $gReached++; }
    $widgets['goals'] = [
        'href' => '/goals.php', 'eyebrow' => 'Savings goals',
        'value' => usd($gCur),
        'sub'   => 'of ' . e(usd($gTar)) . ' · ' . count($goals) . ' goal' . (count($goals) === 1 ? '' : 's')
                   . ($gReached ? ' · ' . $gReached . ' reached' : ''),
    ];
}
if ($active('forecast') && $fcHasCash) {
    $widgets['forecast'] = [
        'href' => '/forecast.php', 'eyebrow' => 'Cash forecast',
        'value' => ($fcTeaser['min_balance'] < 0 ? '−' : '') . usd(abs($fcTeaser['min_balance'])),
        'tone'  => $fcTeaser['goes_negative'] ? 'neg' : '',
        'sub'   => 'projected low · ' . e((new DateTimeImmutable($fcTeaser['min_date']))->format('M j')),
    ];
}
if ($active('debt') && $debtPlan['debts']) {
    $widgets['debt'] = [
        'href' => '/debt.php', 'eyebrow' => 'Debt payoff',
        'value' => usd($debtPlan['total']),
        'sub'   => count($debtPlan['debts']) . ' debt' . (count($debtPlan['debts']) === 1 ? '' : 's')
                   . ($debtPlan['has_mortgage'] ? ' · excl. mortgage' : ''),
    ];
}
if ($active('home_equity') && $home) {
    $widgets['home_equity'] = [
        'href' => '/property.php', 'eyebrow' => $home['equity'] !== null ? 'Home equity' : 'Home value',
        'value' => usd($home['equity'] !== null ? $home['equity'] : $home['value']),
        'sub'   => 'value ' . e(usd($home['value'])),
    ];
}
if ($active('refunds')) {
    $widgets['refunds'] = [
        'href' => '/refunds.php', 'eyebrow' => 'Refunds',
        'value' => usd($rfView['outstanding']),
        'sub'   => $rfView['pending_count'] . ' to confirm',
    ];
}
if ($active('allocation')) {
    require_once __DIR__ . '/lib/allocation.php';
    $allocHolds = q_holdings($pdo, $uid);
    if ($allocHolds) {
        $av = build_allocation_view($allocHolds, q_allocation_targets($pdo), q_security_asset_classes($pdo));
        $widgets['allocation'] = [
            'href' => '/allocation.php', 'eyebrow' => 'Allocation',
            'value' => usd($av['total']),
            'sub'   => $av['has_targets'] ? 'largest drift ' . e(usd(abs($av['max_drift_val']))) : 'set a target mix',
        ];
    }
}
if ($active('top_merchants')) {
    $tm = q_top_merchants($pdo, $uid, 90, 1);
    if ($tm) {
        $widgets['top_merchants'] = [
            'href' => '/merchants.php', 'eyebrow' => 'Top merchant · 90d',
            'value' => usd((float)$tm[0]['total']),
            'sub'   => e($tm[0]['merchant']),
        ];
    }
}
if ($active('recurring') && $recur) {
    $widgets['recurring'] = [
        'href' => '/recurring.php', 'eyebrow' => 'Recurring',
        'value' => (string)count($recur),
        'sub'   => 'subscriptions & recurring income',
    ];
}
if ($active('credit')) {
    require_once __DIR__ . '/lib/credit.php';
    $cov = build_credit_overview($pdo, $uid);
    // The overview cards carry a score (no health composite — that's in the detail view),
    // so the widget shows the first card that has a score.
    foreach (($cov['cards'] ?? []) as $r) {
        if (($r['score'] ?? null) === null) continue;
        $widgets['credit'] = [
            'href' => '/credit.php', 'eyebrow' => 'Credit score',
            'value' => (string)(int)$r['score'],
            'sub'   => e((string)($r['bureau_label'] ?? $r['bureau'] ?? '')),
        ];
        break;
    }
}
if ($active('economic')) {
    $mr = q_fred_latest($pdo, 'MORTGAGE30US');
    if ($mr && ($mr['value'] ?? null) !== null) {
        $widgets['economic'] = [
            'href' => '/economic.php', 'eyebrow' => 'Economic',
            'value' => number_format((float)$mr['value'], 2) . '%',
            'sub'   => '30-yr mortgage average',
        ];
    }
}

/* ---- Needs Attention feed ------------------------------------------------ */
$feed = [];
if ($layout['attention_on']) {
    // Broken bank connections (per item, deduped by institution).
    $broken = [];
    foreach ($accounts as $a) {
        if ((($a['item_status'] ?? '') === 'error' || !empty($a['error_code'])) && !empty($a['item_id'])) {
            $broken[$a['item_id']] = $a['institution_name'] ?: 'A bank';
        }
    }
    // Bills due within 5 days, with a known amount (top 3).
    $billsDue = [];
    $soonCut = $today->add(new DateInterval('P5D'));
    foreach ($billsSoon as $b) {
        if (($b['amount'] ?? null) === null) continue;
        $d = new DateTimeImmutable($b['date']);
        if ($d > $soonCut) continue;
        $days = (int)$today->diff($d)->format('%r%a');
        $due  = $days <= 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in $days days");
        $billsDue[] = ['label' => $b['label'], 'due_label' => $due, 'amount' => (float)$b['amount'], 'account_id' => $b['account_id'] ?? null];
        if (count($billsDue) >= 3) break;
    }
    // Refunds with a suggested matching credit (needs the candidate pool — only if any pending).
    $rfCount = 0; $rfAmt = 0.0;
    if ($rfView['pending_count'] > 0) {
        $rf2 = build_refunds_view(q_refund_watches($pdo, $uid), q_refund_credits($pdo, $uid, date('Y-m-d', strtotime('-120 days'))), date('Y-m-d'));
        foreach ($rf2['pending'] as $p) { if (!empty($p['suggestions'])) { $rfCount++; $rfAmt += (float)($p['amount'] ?? 0); } }
    }
    // Overdue manual statements.
    $odStmts = [];
    foreach ($overdue as $o) {
        $odStmts[] = ['name' => $o['name'] ?: 'Account', 'account_id' => $o['account_id'],
                      'meta' => strtolower(statement_cadence_label($o['cadence'])) . ' · ' . statement_overdue_label($o)];
    }
    // Budgets exceeded this month.
    $budOver = [];
    foreach (q_budgets($pdo)['budgets'] as $bd) {
        $avail = (float)($bd['available'] ?? 0);
        $spent = (float)($bd['spent'] ?? 0);
        if ($spent > $avail && $spent > 0) {
            $budOver[] = ['label' => pretty_cat($bd['category']), 'pct' => $avail > 0 ? (int)round($spent / $avail * 100) : 100];
        }
    }
    $feed = build_attention_feed([
        'broken_banks'       => array_values($broken),
        'bills_due'          => $billsDue,
        'refunds_count'      => $rfCount,
        'refunds_amount'     => $rfAmt,
        'overdue_statements' => $odStmts,
        'budgets_over'       => $budOver,
    ]);
}

/* ---- Greeting ------------------------------------------------------------ */
$hour  = (int)date('G');
$partOfDay = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
$first = trim(explode(' ', (string)($_SESSION['name'] ?? ''))[0] ?? '');
?>

<?php if ($hasPlaid): ?>
<div class="refresh-row">
    <button type="button" class="btn-ghost sm" data-refresh>Refresh</button>
    <?php if ($lastSync): ?><span class="muted refresh-stamp">Updated <?= e(time_ago($lastSync)) ?></span><?php endif; ?>
</div>
<?php endif; ?>

<div class="dash-greet">
    <p class="greet">Good <?= $partOfDay ?><?= $first ? ', <em>' . e($first) . '</em>' : '' ?>.</p>
    <a class="edit-link" href="/customize_home.php">✎ Customize</a>
</div>

<?php if ($layout['attention_on']): ?>
<section class="attention">
    <div class="block-head"><h2>Needs your attention</h2><?php if ($feed): ?><span class="count-pill"><?= count($feed) ?></span><?php endif; ?></div>
    <?php if ($feed): ?>
    <div class="feed">
        <?php foreach ($feed as $f): ?>
        <a class="feed-item<?= $f['tone'] ? ' ' . $f['tone'] : '' ?>" href="<?= e($f['href']) ?>">
            <span class="fi" aria-hidden="true"><?= e($f['icon']) ?></span>
            <span class="fm">
                <span class="ft"><?= e($f['title']) ?></span>
                <?php if (!empty($f['sub'])): ?><span class="fs"><?= e($f['sub']) ?></span><?php endif; ?>
            </span>
            <?php if (!empty($f['amount'])): ?>
                <span class="fx <?= e($f['amount_tone'] ?? '') ?>"><?= e($f['amount']) ?></span>
            <?php else: ?>
                <span class="chev" aria-hidden="true">›</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card feed-clear muted">You're all caught up ✓</div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php // The bento — the user's chosen cards, in order, at their sizes.
$visible = array_filter($layout['cards'], fn($c) => $c['size'] !== 'off' && isset($widgets[$c['widget']])); ?>
<section class="snapshot">
    <div class="block-head"><h2>Your snapshot</h2><a class="block-link" href="/customize_home.php">Edit cards ›</a></div>
    <?php if ($visible): ?>
    <div class="bento">
        <?php foreach ($visible as $c) echo dash_card_html($c['size'], $widgets[$c['widget']]); ?>
    </div>
    <?php else: ?>
    <div class="card feed-clear muted">No cards on Home yet — <a href="/customize_home.php">choose some</a>.</div>
    <?php endif; ?>
</section>

<div class="cols">
<?php
// Accounts (the account-centric anchor of the dashboard), grouped by category.
$byCat = [];
foreach ($accounts as $a) { $byCat[account_group($a)][] = $a; }
foreach ($byCat as &$grp) {
    usort($grp, fn($x, $y) => abs((float)($y['balance_current'] ?? 0)) <=> abs((float)($x['balance_current'] ?? 0)));
}
unset($grp);
?>
<section class="block" id="dash-accounts">
    <div class="block-head">
        <h2>Your accounts</h2>
        <span class="count-pill"><?= count($accounts) ?></span>
    </div>
    <?php if (count($accounts) > 8): ?>
    <div class="search-bar">
        <input type="search" class="search-input" data-filter="#dash-accounts" placeholder="Filter by account or bank…">
    </div>
    <?php endif; ?>

    <?php foreach (ACCOUNT_GROUPS as $cat => $label):
        if (empty($byCat[$cat])) continue;
        $rows = $byCat[$cat];
        $subtotal = 0.0;
        foreach ($rows as $a) {
            $bb = (float)($a['balance_current'] ?? 0);
            $subtotal += is_liability($a) ? -abs($bb) : $bb;
        }
        $negTotal = $subtotal < 0;
    ?>
        <div class="inst-group" data-filter-group>
            <div class="inst-name">
                <span><?= e($label) ?></span>
                <span class="inst-total <?= $negTotal ? 'neg' : '' ?>"><?= e(($negTotal ? '-' : '') . usd(abs($subtotal))) ?></span>
            </div>
            <div class="acct-list">
                <?php foreach ($rows as $a):
                    $debt    = is_liability($a);
                    $bal     = (float)($a['balance_current'] ?? 0);
                    $errored = ($a['item_status'] ?? '') === 'error' || !empty($a['error_code']);
                    $sub = $a['institution_name'] ?: pretty_cat($a['subtype'] ?: $a['type']);
                    $hay = strtolower(($a['name'] ?: '') . ' ' . ($a['official_name'] ?: '') . ' ' . ($a['institution_name'] ?: '') . ' ' . $sub);
                ?>
                <a class="acct-card" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>" data-search="<?= e($hay) ?>">
                    <span class="acct-icon <?= $debt ? 'is-debt' : '' ?>"><?= nav_icon($debt ? 'invest' : 'bank') ?></span>
                    <span class="acct-main">
                        <span class="acct-name"><?= e($a['name'] ?: ($a['official_name'] ?: 'Account')) ?></span>
                        <span class="acct-sub">
                            <?= e($sub) ?><?= $a['mask'] ? ' · ••' . e($a['mask']) : '' ?><?= owner_suffix($a['owner_id'] ?? null) ?>
                            <?php if ($a['visibility'] === 'private'): ?><span class="mini-tag">private</span><?php endif; ?>
                            <?php if ($errored): ?><span class="mini-tag warn">needs attention</span><?php endif; ?>
                            <?php if (isset($overdueIds[$a['account_id']])): ?><span class="mini-tag warn">needs update</span><?php endif; ?>
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
</div><!-- /.cols -->

<?php render_footer(); ?>
