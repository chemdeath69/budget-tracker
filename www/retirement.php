<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/retirement.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo  = db();
$uid  = current_user_id();
$view = build_retirement_view($pdo, $uid);

$accounts = $view['accounts'];
$total    = $view['total'];
$vs       = $view['value_series'];
$prev     = count($vs) >= 2 ? (float)$vs[count($vs) - 2]['value'] : null;
$chgAbs   = $prev !== null ? $total - $prev : null;
$chgPct   = ($prev !== null && $prev != 0.0) ? $chgAbs / abs($prev) * 100 : null;
$proj     = $view['projection'];

/* ---- Investment activity for the Plaid retirement brokerages shown here (#18) ----
 * Dividends/interest + trades come from q_investment_activity() scoped to THIS page's
 * (non-manual) retirement accounts — e.g. Betterment. Manual 401(k)s contribute
 * nothing (their statements live in retirement_statements, not the investment feeds). */
$invAcct     = trim((string)($_GET['iacct'] ?? ''));
$rdPage      = page_num('dpage');
$rtPage      = page_num('tpage');
$rcPage      = page_num('cpage');
$retAcctOpts = [];
foreach ($view['cards'] as $c) {
    if (empty($c['manual'])) $retAcctOpts[(string)$c['account']['account_id']] = $c['account']['name'] ?: 'Account';
}
$retScope = ($invAcct !== '' && isset($retAcctOpts[$invAcct])) ? [$invAcct] : array_keys($retAcctOpts);

$retIncomeRaw  = $retScope ? q_investment_activity($pdo, $uid, 'income', $retScope, PAGE_SIZE + 1, page_offset($rdPage)) : [];
$retIncomeNext = count($retIncomeRaw) > PAGE_SIZE;
$retIncome     = array_slice($retIncomeRaw, 0, PAGE_SIZE);
$retTradesRaw  = $retScope ? q_investment_activity($pdo, $uid, 'trades', $retScope, PAGE_SIZE + 1, page_offset($rtPage)) : [];
$retTradesNext = count($retTradesRaw) > PAGE_SIZE;
$retTrades     = array_slice($retTradesRaw, 0, PAGE_SIZE);
// Contributions (payroll 401(k) deposits, Plaid) — separate so they don't inflate the
// dividend/interest total; the account balance already reflects them.
$retContribRaw  = $retScope ? q_investment_activity($pdo, $uid, 'contributions', $retScope, PAGE_SIZE + 1, page_offset($rcPage)) : [];
$retContribNext = count($retContribRaw) > PAGE_SIZE;
$retContribs    = array_slice($retContribRaw, 0, PAGE_SIZE);
$retContribTotal = $retScope ? -q_investment_activity_total($pdo, $uid, 'contributions', $retScope) : 0.0;
$retIncomeTotal = $retScope ? -q_investment_activity_total($pdo, $uid, 'income', $retScope) : 0.0;

/** Friendly description of the growth-rate basis. */
function ret_rate_note(array $view): string
{
    $pct = number_format($view['rate'] * 100, 1) . '%';
    switch ($view['rate_basis']) {
        case 'override': return $pct . ' — your set rate';
        case 'derived':  return $pct . ' — derived from ' . $view['derived']['pairs']
            . ' period' . ($view['derived']['pairs'] === 1 ? '' : 's')
            . ' of history';
        default:         return $pct . ' — default (add more statements to derive your own)';
    }
}

render_header('Retirement', 'retirement', ['chart' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if (!$accounts): ?>
    <div class="empty-state card">
        <span class="empty-ic"><?= nav_icon('nest') ?></span>
        <h2>No retirement accounts yet</h2>
        <p class="muted">Track 401(k)s that only send paper statements. Add one, then enter each
            statement (balance + contributions) to chart your growth and project your retirement.</p>
        <a class="btn" href="/retirement_add.php">Add a 401(k)</a>
    </div>
<?php else: ?>

    <!-- Combined retirement total -->
    <section class="hero card">
        <div class="hero-top">
            <span class="hero-label">Retirement total</span>
            <?php if ($chgPct !== null): $up = $chgPct >= 0; ?>
                <span class="delta <?= $up ? 'up' : 'down' ?>">
                    <?= $up ? '▲' : '▼' ?> <?= number_format(abs($chgPct), 1) ?>%
                    <span class="delta-sub">since last</span>
                </span>
            <?php endif; ?>
        </div>
        <div class="hero-value"><?= e(usd($total)) ?></div>

        <?php if (count($vs) > 1): ?>
            <div class="sparkline">
                <canvas id="ret-spark" data-chart="spark" data-src="ret-spark-data" height="64"></canvas>
            </div>
            <script type="application/json" id="ret-spark-data"><?= json_encode([
                'labels' => array_column($vs, 'date'),
                'values' => array_map('floatval', array_column($vs, 'value')),
            ], JSON_UNESCAPED_SLASHES) ?></script>
        <?php endif; ?>

        <div class="hero-split">
            <div class="split-cell">
                <span class="split-label">Accounts</span>
                <span class="split-value"><?= count($accounts) ?></span>
            </div>
            <div class="split-cell">
                <span class="split-label">Contributed YTD</span>
                <span class="split-value pos"><?= e(usd($view['ytd']['total'])) ?></span>
            </div>
        </div>
    </section>

    <div class="cols">
    <!-- Value over time -->
    <?php if (count($vs) > 1): ?>
    <section class="block">
        <div class="block-head"><h2>Value over time</h2><span class="muted">by statement</span></div>
        <section class="card">
            <div class="chart-wrap" style="height:240px">
                <canvas data-chart="line" data-src="ret-value-data"></canvas>
            </div>
            <script type="application/json" id="ret-value-data"><?= json_encode([
                'labels' => array_column($vs, 'date'),
                'values' => array_map('floatval', array_column($vs, 'value')),
            ], JSON_UNESCAPED_SLASHES) ?></script>
        </section>
    </section>
    <?php endif; ?>

    <!-- Contributions -->
    <section class="block">
        <div class="block-head"><h2>Contributions</h2><span class="muted">this year</span></div>
        <section class="card">
            <div class="kv-grid">
                <div><span class="muted">Your contribution YTD</span><strong><?= e(usd($view['ytd']['employee'])) ?></strong></div>
                <div><span class="muted">Employer match YTD</span><strong class="pos"><?= e(usd($view['ytd']['employer'])) ?></strong></div>
                <div><span class="muted">Total YTD</span><strong><?= e(usd($view['ytd']['total'])) ?></strong></div>
                <div><span class="muted">Last 12 months</span><strong><?= e(usd($view['ttm_contrib'])) ?></strong></div>
            </div>
            <?php if ($view['contrib_periods']): ?>
            <div class="chart-wrap" style="height:200px;margin-top:1rem">
                <canvas data-chart="bars" data-src="ret-contrib-data"></canvas>
            </div>
            <script type="application/json" id="ret-contrib-data"><?= json_encode([
                'labels' => array_keys($view['contrib_periods']),
                'values' => array_map(fn($p) => round($p['ee'] + $p['er'], 2), array_values($view['contrib_periods'])),
                'color'  => 'pos',
            ], JSON_UNESCAPED_SLASHES) ?></script>
            <?php endif; ?>
        </section>
    </section>
    </div><!-- /.cols -->

    <!-- Projection -->
    <section class="block">
        <div class="block-head">
            <h2>Projection</h2>
            <a class="block-link" href="/retirement_settings.php">Assumptions ›</a>
        </div>
        <?php if (!$proj): ?>
        <section class="card">
            <p class="muted">Set a target retirement year to project your combined balance forward.</p>
            <a class="btn" href="/retirement_settings.php">Set assumptions</a>
        </section>
        <?php else: ?>
        <section class="hero card">
            <div class="hero-top">
                <span class="hero-label">Projected at <?= e((string)$proj['target_year']) ?></span>
                <span class="delta-sub muted"><?= $proj['years'] ?> yr<?= $proj['years'] === 1 ? '' : 's' ?></span>
            </div>
            <div class="hero-value"><?= e(usd($proj['projected'])) ?></div>
            <div class="chart-wrap" style="height:240px">
                <canvas data-chart="multiline" data-src="ret-proj-data"></canvas>
            </div>
            <?php
            $series = [[
                'label'  => 'Projected balance',
                'values' => array_map(fn($p) => $p['value'], $proj['series']),
                'color'  => 'brand', 'fill' => true,
            ]];
            if ($proj['target_amount']) {
                $series[] = [
                    'label'  => 'Target',
                    'values' => array_fill(0, count($proj['series']), (float)$proj['target_amount']),
                    'color'  => 'muted', 'dashed' => true,
                ];
            }
            ?>
            <script type="application/json" id="ret-proj-data"><?= json_encode([
                'labels' => array_map(fn($p) => (string)$p['year'], $proj['series']),
                'series' => $series,
            ], JSON_UNESCAPED_SLASHES) ?></script>

            <div class="kv-grid" style="margin-top:1rem">
                <div><span class="muted">Growth rate</span><strong><?= e(ret_rate_note($view)) ?></strong></div>
                <div><span class="muted">Annual contribution</span><strong><?= e(usd($proj['annual_contrib'])) ?><?= $view['settings']['annual_contribution'] === null ? ' <span class="muted">(last 12 mo)</span>' : '' ?></strong></div>
                <?php if ($proj['progress'] !== null): ?>
                <div><span class="muted">Toward <?= e(usd($proj['target_amount'])) ?></span><strong><?= e(number_format($proj['progress'], 1)) ?>%</strong></div>
                <?php endif; ?>
            </div>
            <?php if ($proj['progress'] !== null): ?>
            <div class="spend-track" style="margin-top:.5rem"><span style="width:<?= round(min(100, max(2, $proj['progress']))) ?>%"></span></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </section>

    <!-- Accounts -->
    <section class="block">
        <div class="block-head"><h2>Your retirement accounts</h2><span class="count-pill"><?= count($accounts) ?></span></div>
        <?php foreach ($view['cards'] as $c):
            $a = $c['account'];
            $manual = $c['manual'];
            $stale = $manual && $c['stale_days'] !== null && $c['stale_days'] > 120;
            $owner = (int)$a['owner_id'] === $uid; ?>
        <section class="card" id="account-<?= e($a['account_id']) ?>">
            <div class="acct-card" style="padding:0">
                <span class="acct-icon"><?= nav_icon('nest') ?></span>
                <span class="acct-main">
                    <a class="acct-name" href="/account.php?account_id=<?= e(urlencode($a['account_id'])) ?>"><?= e($a['name'] ?: 'Retirement') ?></a>
                    <span class="acct-sub">
                        <?= e($a['institution_name'] ?: 'Retirement') ?><?= owner_suffix($a['owner_id'] ?? null) ?>
                        <?php if ($manual): ?>
                            <?php if ($c['last_date']): ?> · as of <?= e((string)$c['last_date']) ?><?php else: ?> · <span class="mini-tag">no statements</span><?php endif; ?>
                            <?php if ($stale): ?> <span class="mini-tag warn"><?= (int)floor($c['stale_days'] / 30) ?> mo old</span><?php endif; ?>
                        <?php else: ?>
                            · <span class="mini-tag">auto-synced</span><?php if ($c['last_date']): ?> · as of <?= e((string)$c['last_date']) ?><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($a['visibility'] === 'private'): ?> <span class="mini-tag">private</span><?php endif; ?>
                    </span>
                </span>
                <span class="row-side">
                    <span class="acct-bal"><?= e(usd($c['balance'])) ?></span>
                    <?php if ($manual && $owner): ?><a class="btn-ghost sm" href="/retirement_statement.php?account_id=<?= e(urlencode($a['account_id'])) ?>">Add statement</a><?php endif; ?>
                </span>
            </div>
            <?php if (!$manual && $c['holdings']): ?>
            <div class="rows" style="margin-top:.6rem;border-top:1px solid var(--line)">
                <?php foreach ($c['holdings'] as $h):
                    $sec  = ($h['ticker_symbol'] ? $h['ticker_symbol'] . ' — ' : '') . ($h['security_name'] ?: '—');
                    $val  = $h['institution_value'] !== null ? (float)$h['institution_value'] : null;
                    $cb   = $h['cost_basis'] !== null ? (float)$h['cost_basis'] : null;
                    $hgain = ($val !== null && $cb !== null) ? $val - $cb : null;
                    $hpct  = ($hgain !== null && $cb != 0.0) ? round($hgain / abs($cb) * 100, 1) : null; ?>
                <div class="row">
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
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
        <div style="margin-top:1rem">
            <a class="btn-ghost" href="/retirement_add.php">Add a manual 401(k)</a>
            <span class="muted" style="margin-left:.5rem">Plaid-linked retirement accounts (IRA / 401k) appear here automatically.</span>
        </div>
    </section>

    <?php if ($retScope): /* Plaid retirement brokerage(s) present → show their activity (#18) */ ?>
    <?php render_investment_activity('Dividends & interest', $retIncome, [
        'head_right'   => $retIncomeTotal > 0 ? '<span class="split-value pos">' . e(usd($retIncomeTotal)) . '</span>' : '',
        'page'         => $rdPage,
        'has_next'     => $retIncomeNext,
        'pager_key'    => 'dpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($rtPage > 1 ? ['tpage' => $rtPage] : []) + ($rcPage > 1 ? ['cpage' => $rcPage] : []),
        'empty'        => 'No dividend or interest activity in the synced window.',
        'filter'       => ['opts' => $retAcctOpts, 'current' => $invAcct, 'action' => '/retirement.php'],
    ]); ?>
    <?php if ($retTrades || $invAcct !== '' || $rtPage > 1): ?>
    <?php render_investment_activity('Recent trades', $retTrades, [
        'head_right'   => $retTrades ? '<span class="count-pill">' . count($retTrades) . '</span>' : '',
        'page'         => $rtPage,
        'has_next'     => $retTradesNext,
        'pager_key'    => 'tpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($rdPage > 1 ? ['dpage' => $rdPage] : []) + ($rcPage > 1 ? ['cpage' => $rcPage] : []),
        'empty'        => 'No trades for this filter.',
        'filter'       => ['opts' => $retAcctOpts, 'current' => $invAcct, 'action' => '/retirement.php'],
    ]); ?>
    <?php endif; ?>
    <?php if ($retContribs || $rcPage > 1): ?>
    <?php render_investment_activity('Recent contributions', $retContribs, [
        'head_right'   => $retContribTotal > 0 ? '<span class="split-value pos">' . e(usd($retContribTotal)) . '</span>' : '',
        'page'         => $rcPage,
        'has_next'     => $retContribNext,
        'pager_key'    => 'cpage',
        'pager_params' => array_filter(['iacct' => $invAcct]) + ($rdPage > 1 ? ['dpage' => $rdPage] : []) + ($rtPage > 1 ? ['tpage' => $rtPage] : []),
        'empty'        => 'No contributions in the synced window.',
        'filter'       => ['opts' => $retAcctOpts, 'current' => $invAcct, 'action' => '/retirement.php'],
    ]); ?>
    <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
