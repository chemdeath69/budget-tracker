<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/credit_ocr.php';   // credit_ocr_enabled()
require __DIR__ . '/lib/credit.php';       // build_credit_overview() / build_credit_view()
require_login();

/**
 * Credit page (TODO2 #28). Two modes:
 *   • Overview (no ?report_id): a grid of the latest pull per (user, bureau) with quick
 *     stats, plus an "Import a report" entry point.
 *   • Detail (?report_id=N): the full insight bundle for one report — score / health
 *     composite, utilization, account age, inquiries, credit mix, derogatory marks, the
 *     reconciliation-vs-tracked-accounts cross-feature, and a pull-over-pull diff.
 *
 * Reports are household-visible; the page labels whose report each is.
 */

$pdo = db();
$uid = current_user_id();
$reportId = (int)($_GET['report_id'] ?? 0);

/** Format a 0..1 fraction as a utilization percentage string. */
function cr_pct(?float $f): string
{
    if ($f === null) return '—';
    return number_format($f * 100, $f < 0.1 ? 1 : 0) . '%';
}
/** Utilization → state class (pos under 30%, cr-warn under 50%, neg above). */
function cr_util_state(?float $f): string
{
    if ($f === null) return 'muted';
    return $f < 0.30 ? 'pos' : ($f < 0.50 ? 'cr-warn' : 'neg');
}
$bureaus = ['equifax' => 'Equifax', 'experian' => 'Experian', 'transunion' => 'TransUnion', 'other' => 'Other'];
$atLabels = ['revolving' => 'Revolving', 'installment' => 'Installment', 'mortgage' => 'Mortgage',
             'auto' => 'Auto loan', 'student' => 'Student loan', 'personal' => 'Personal loan',
             'collection' => 'Collection', 'other' => 'Other'];

$view = $reportId > 0 ? build_credit_view($pdo, $uid, $reportId) : null;

if ($reportId > 0 && !$view) {
    render_header('Credit', 'credit', ['narrow' => true]);
    echo '<section class="card"><h2>Report not found</h2><p class="muted">That credit report doesn\'t exist.</p>'
       . '<a class="btn" href="/credit.php">Back to credit</a></section>';
    render_footer();
    exit;
}

if (!$view) {
    // ---- OVERVIEW --------------------------------------------------------
    $ov = build_credit_overview($pdo, $uid);
    render_header('Credit', 'credit');
    foreach (flash_take() as $fl) {
        echo '<div class="notice ' . ($fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '')) . '">' . e($fl['msg']) . '</div>';
    }
    ?>
    <div class="page-head">
        <p class="eyebrow">Credit</p>
        <h1>Credit reports</h1>
    </div>
    <section class="card">
        <div class="head-actions">
            <h2>Your reports</h2>
            <a class="btn" href="/credit_import.php">+ Import a report</a>
        </div>
        <?php if (!credit_ocr_enabled($GLOBALS['CONFIG'])): ?>
            <p class="muted">Import needs an Anthropic API key (<code>anthropic.api_key</code>).</p>
        <?php endif; ?>

        <?php if (!$ov['has_any']): ?>
            <p class="muted">No credit reports yet. Download your free report from
                <a href="https://www.annualcreditreport.com" target="_blank" rel="noopener">AnnualCreditReport.com</a>
                and import it to see utilization, account age, inquiries, credit mix, and how it lines up with
                your tracked accounts.</p>
        <?php else: ?>
            <div class="cr-grid">
                <?php foreach ($ov['cards'] as $c): ?>
                <a class="cr-card" href="/credit.php?report_id=<?= (int)$c['report_id'] ?>">
                    <div class="cr-card-top">
                        <span class="cr-bureau"><?= e($c['bureau_label']) ?></span>
                        <span class="cr-owner muted"><?= e($c['owner_name']) ?></span>
                    </div>
                    <div class="cr-card-score">
                        <?php if ($c['score'] !== null): ?>
                            <span class="cr-score-num"><?= (int)$c['score'] ?></span>
                            <span class="muted"><?= e($c['score_model'] ?: 'score') ?></span>
                        <?php else: ?>
                            <span class="cr-util <?= cr_util_state($c['utilization']) ?>"><?= cr_pct($c['utilization']) ?></span>
                            <span class="muted">utilization</span>
                        <?php endif; ?>
                    </div>
                    <div class="cr-card-meta muted">
                        <?= (int)$c['open_accounts'] ?> open · <?= (int)$c['derogatory'] ?> derog · <?= e($c['pulled_on']) ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
    exit;
}

// ---- DETAIL --------------------------------------------------------------
$rep = $view['report'];
$util = $view['utilization'];
$age = $view['age'];
$inq = $view['inquiries'];
$mix = $view['mix'];
$health = $view['health'];
$recon = $view['recon'];
$diff = $view['diff'];

$healthState = $health['score'] >= 80 ? 'pos' : ($health['score'] >= 45 ? 'cr-warn' : 'neg');
$mixData = ['labels' => [], 'values' => []];
foreach (['revolving' => 'Revolving', 'installment' => 'Installment', 'mortgage' => 'Mortgage'] as $bk => $bl) {
    if ($mix['buckets'][$bk]['count'] > 0) { $mixData['labels'][] = $bl; $mixData['values'][] = $mix['buckets'][$bk]['count']; }
}

render_header('Credit — ' . $rep['bureau_label'], 'credit', ['back' => '/credit.php', 'chart' => true]);
foreach (flash_take() as $fl) {
    echo '<div class="notice ' . ($fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '')) . '">' . e($fl['msg']) . '</div>';
}
?>

<div class="page-head">
    <p class="eyebrow">Credit report</p>
    <h1><?= e($rep['bureau_label']) ?></h1>
</div>

<section class="hero card">
    <div class="hero-top">
        <span class="hero-label"><?= e($rep['owner_name']) ?> · <?= e($rep['bureau_label']) ?> · <?= e($rep['pulled_on']) ?></span>
    </div>
    <div class="hero-split">
        <div class="split-cell">
            <?php if ($rep['score'] !== null): ?>
                <span class="split-label">Credit score</span>
                <span class="cr-figure"><?= (int)$rep['score'] ?></span>
                <span class="muted cr-note"><?= e($rep['score_model'] ?: 'credit score') ?></span>
            <?php else: ?>
                <span class="split-label">Credit health</span>
                <span class="cr-figure <?= $healthState ?>"><?= (int)$health['score'] ?><span class="cr-of">/100</span></span>
                <span class="muted cr-note"><?= e($health['label']) ?> · no score on this free report</span>
            <?php endif; ?>
        </div>
        <div class="split-cell">
            <span class="split-label">Revolving utilization</span>
            <span class="cr-figure <?= cr_util_state($util['overall']) ?>"><?= cr_pct($util['overall']) ?></span>
            <span class="muted cr-note"><?= usd($util['balance']) ?> of <?= usd($util['limit']) ?></span>
        </div>
    </div>
</section>

<div class="cols">
<div>
    <section class="card">
        <h2>Utilization by card</h2>
        <p class="muted">Aim for under 30% overall — under 10% is best.</p>
        <?php if ($util['cards']): ?>
            <div class="cr-bars">
            <?php foreach ($util['cards'] as $c): ?>
                <div class="cr-bar-row">
                    <div class="cr-bar-head">
                        <span><?= e($c['creditor']) ?><?= $c['mask'] ? ' <span class="muted">••' . e($c['mask']) . '</span>' : '' ?></span>
                        <span class="<?= cr_util_state($c['util']) ?>"><?= cr_pct($c['util']) ?></span>
                    </div>
                    <div class="cr-bar"><span class="cr-bar-fill <?= cr_util_state($c['util']) ?>" style="width:<?= min(100, round($c['util'] * 100)) ?>%"></span></div>
                    <div class="cr-bar-sub muted"><?= usd($c['balance']) ?> of <?= usd($c['limit']) ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No open revolving accounts with a credit limit were found.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Account age</h2>
        <div class="cr-kv">
            <div><span class="muted">Average age</span><strong><?= $age['average'] !== null ? e($age['average']) . ' yrs' : '—' ?></strong></div>
            <div><span class="muted">Oldest</span><strong><?= $age['oldest'] ? e($age['oldest']['creditor']) . ' · ' . e($age['oldest']['years']) . ' yrs' : '—' ?></strong></div>
            <div><span class="muted">Newest</span><strong><?= $age['newest'] ? e($age['newest']['creditor']) . ' · ' . e($age['newest']['years']) . ' yrs' : '—' ?></strong></div>
            <div><span class="muted">Accounts dated</span><strong><?= (int)$age['count'] ?></strong></div>
        </div>
        <p class="muted cr-note">Opening new accounts lowers your average age — a factor in scoring.</p>
    </section>

    <section class="card">
        <h2>Credit mix</h2>
        <?php if ($mixData['values']): ?>
            <div class="chart-wrap">
                <canvas data-chart="doughnut" data-src="mix-data" aria-label="Credit mix by account type"></canvas>
            </div>
            <script type="application/json" id="mix-data"><?= json_encode($mixData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <div class="cat-list cr-mix-list">
            <?php $i = 0; foreach (['revolving' => 'Revolving', 'installment' => 'Installment', 'mortgage' => 'Mortgage'] as $bk => $bl):
                $m = $mix['buckets'][$bk]; if ($m['count'] === 0) continue; ?>
                <div class="cat-row"><span class="cat-swatch" style="--i:<?= $i ?>"></span><span class="cat-name"><?= e($bl) ?> <span class="muted">(<?= (int)$m['count'] ?>)</span></span><span class="cat-amt"><?= usd($m['balance']) ?></span></div>
            <?php $i++; endforeach; ?>
            </div>
            <p class="muted cr-note"><?= (int)$mix['distinct'] ?> of 3 account types — a varied mix helps.</p>
        <?php else: ?>
            <p class="muted">No classifiable accounts to chart.</p>
        <?php endif; ?>
    </section>
</div>

<div>
    <section class="card">
        <h2>🔗 Vs. your tracked accounts</h2>
        <p class="muted">How this report lines up with the accounts in Budget Tracker.</p>
        <?php if ($recon['matched']): ?>
            <h3 class="review-head">Matched</h3>
            <div class="rows">
            <?php foreach ($recon['matched'] as $m):
                $disc = $m['discrepancy']; ?>
                <div class="row">
                    <div class="row-main">
                        <div class="row-title">✓ <a href="/account.php?account_id=<?= e(rawurlencode($m['account_id'])) ?>"><?= e($m['account_name']) ?></a></div>
                        <div class="row-sub muted"><?= e($m['creditor']) ?><?= $m['mask'] ? ' ••' . e($m['mask']) : '' ?> · matched by <?= e($m['how']) ?></div>
                    </div>
                    <div class="row-amt">
                        <?= $m['report_balance'] !== null ? usd((float)$m['report_balance']) : '—' ?>
                        <?php // Only trust a live-vs-report balance comparison on a strong (last-4 mask)
                              // match — a name match is issuer-level and may pair different cards.
                              if ($m['how'] === 'mask' && $disc !== null && abs($disc) >= 1): ?>
                            <div class="cr-disc muted">live <?= usd((float)$m['live_balance']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($recon['unmatched_report']): ?>
            <h3 class="review-head">On the report, not tracked here</h3>
            <p class="muted cr-note">Possible coverage gaps — accounts you might want to add.</p>
            <div class="rows">
            <?php foreach ($recon['unmatched_report'] as $u): ?>
                <div class="row">
                    <div class="row-main"><div class="row-title"><?= e($u['creditor']) ?><?= $u['mask'] ? ' <span class="muted">••' . e($u['mask']) . '</span>' : '' ?></div>
                        <div class="row-sub muted"><?= e($atLabels[$u['account_type']] ?? 'Account') ?></div></div>
                    <div class="row-amt"><?= $u['balance'] !== null ? usd((float)$u['balance']) : '—' ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($recon['unmatched_tracked']): ?>
            <h3 class="review-head">Tracked here, not on this report</h3>
            <div class="rows">
            <?php foreach ($recon['unmatched_tracked'] as $u): ?>
                <div class="row">
                    <div class="row-main"><div class="row-title"><?= e($u['account_name']) ?><?= $u['mask'] ? ' <span class="muted">••' . e($u['mask']) . '</span>' : '' ?></div>
                        <div class="row-sub muted"><?= e(ucfirst((string)$u['type'])) ?></div></div>
                    <div class="row-amt muted"><?= $u['balance'] !== null ? usd((float)$u['balance']) : '' ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!$recon['matched'] && !$recon['unmatched_report'] && !$recon['unmatched_tracked']): ?>
            <p class="muted">No credit/loan accounts to reconcile.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Hard inquiries</h2>
        <p class="muted"><?= (int)$inq['count12'] ?> in the last 12 months · <?= (int)$inq['count24'] ?> in 24 months. Inquiries stop counting after 12 months.</p>
        <?php if ($inq['list']): ?>
            <div class="rows">
            <?php foreach ($inq['list'] as $q): ?>
                <div class="row">
                    <div class="row-main"><div class="row-title"><?= e($q['inquirer']) ?></div>
                        <div class="row-sub muted"><?= e($q['date'] ?? 'date unknown') ?><?php
                            if ($q['ages_off']) echo ' · ages off ' . e($q['ages_off']); ?></div></div>
                    <div class="row-amt muted"><?= $q['months_ago'] !== null ? (int)$q['months_ago'] . ' mo' : '' ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No inquiries on file. 👍</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Derogatory marks</h2>
        <?php if ($view['flags'] || $view['collections']): ?>
            <div class="rows">
            <?php foreach ($view['flags'] as $f): ?>
                <div class="row">
                    <div class="row-main"><div class="row-title"><?= e($f['detail'] ?: ucwords(str_replace('_', ' ', (string)$f['kind']))) ?></div>
                        <div class="row-sub muted"><?= e(ucwords(str_replace('_', ' ', (string)$f['kind']))) ?><?php
                            if (!empty($f['flag_date'])) echo ' · ' . e($f['flag_date']); ?></div></div>
                    <div class="row-amt neg"><?= isset($f['amount']) && $f['amount'] !== null ? usd((float)$f['amount']) : '' ?></div>
                </div>
            <?php endforeach; ?>
            <?php foreach ($view['collections'] as $t): ?>
                <div class="row">
                    <div class="row-main"><div class="row-title"><?= e($t['creditor']) ?><?= $t['account_mask'] ? ' <span class="muted">••' . e($t['account_mask']) . '</span>' : '' ?></div>
                        <div class="row-sub muted">Collection</div></div>
                    <div class="row-amt neg"><?= $t['balance'] !== null ? usd((float)$t['balance']) : '' ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No collections, public records, or late marks. 👍</p>
        <?php endif; ?>
    </section>

    <?php if ($diff): ?>
    <section class="card">
        <h2>Since your last <?= e($rep['bureau_label']) ?> pull</h2>
        <p class="muted">Compared with <?= e($diff['prior_pulled']) ?>.</p>
        <div class="cr-kv">
            <?php if ($diff['score_now'] !== null && $diff['score_prev'] !== null): ?>
            <div><span class="muted">Score</span><strong><?= (int)$diff['score_prev'] ?> → <?= (int)$diff['score_now'] ?></strong></div>
            <?php endif; ?>
            <div><span class="muted">Utilization</span><strong><?= cr_pct($diff['util_prev']) ?> → <?= cr_pct($diff['util_now']) ?></strong></div>
            <div><span class="muted">Inquiries</span><strong><?= (int)$diff['inq_prev'] ?> → <?= (int)$diff['inq_now'] ?></strong></div>
            <div><span class="muted">Derogatory</span><strong><?= (int)$diff['derog_prev'] ?> → <?= (int)$diff['derog_now'] ?></strong></div>
            <div><span class="muted">New accounts</span><strong><?= (int)$diff['new_accounts'] ?></strong></div>
            <div><span class="muted">Closed/dropped</span><strong><?= (int)$diff['closed_accounts'] ?></strong></div>
        </div>
    </section>
    <?php endif; ?>
</div>
</div>

<?php render_footer(); ?>
