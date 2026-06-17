<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/peers.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Complete-months window: the last 12 FULL calendar months (exclude the partial current
// month) so per-category totals annualize cleanly. PHP app-TZ — never CURDATE() (S24 trap).
$monthStart  = new DateTimeImmutable('first day of this month');
$windowStart = $monthStart->sub(new DateInterval('P12M'));

$bracketKey  = peer_valid_bracket($_GET['bracket'] ?? null);
$spend       = q_peer_category_spend($pdo, $uid, $windowStart->format('Y-m-d'), $monthStart->format('Y-m-d'));
$view        = build_peer_view($bracketKey, $spend);
$throughLbl  = $monthStart->sub(new DateInterval('P1D'))->format('M Y');   // last COMPLETE month

// Over typical = the cautionary direction (red ▲ more); under = green ▼ less.
$chip = function (?float $pct, bool $over): string {
    if ($pct === null) return '<span class="delta-chip muted">—</span>';
    $cls = $over ? 'neg' : 'pos';
    $arr = $over ? '▲' : '▼';
    $word = $over ? 'more' : 'less';
    return '<span class="delta-chip ' . $cls . '">' . $arr . ' ' . number_format(abs($pct), 0) . '% ' . $word . '</span>';
};

render_header('Spending vs typical', 'peers', ['narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Insights</p>
    <h1>Spending vs typical</h1>
</div>

<form class="filter-bar" method="get" action="/peers.php">
    <div class="filter-row">
        <label class="muted peer-sel-label" for="bracket">Compare me to households earning</label>
        <select name="bracket" id="bracket" class="select" data-autosubmit aria-label="Income bracket">
            <?php foreach ($view['brackets'] as $key => $b): ?>
            <option value="<?= e($key) ?>"<?= $key === $view['bracket_key'] ? ' selected' : '' ?>><?= e(peer_bracket_label($b)) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn-ghost" type="submit">Apply</button></noscript>
    </div>
</form>

<?php if (!$view['has_data']): ?>
    <div class="empty-state card">
        <h2>Spending vs typical</h2>
        <p class="muted">This compares your spending against the <strong>typical U.S. household</strong>
            in your income bracket, using the free <strong>Bureau of Labor Statistics Consumer
            Expenditure Survey</strong>. We need at least a month of categorized spending first — it
            populates as your transactions sync.</p>
    </div>
    <?php render_footer(); return; ?>
<?php endif; ?>

<?php $b = $view['bracket']; ?>

<!-- Headline: benchmarked-category total vs typical (only categories where you have tracked spend) -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Across your tracked categories, per year</span>
    </div>
    <?php if ($view['compared_count'] === 0): ?>
        <p class="muted hero-sub">No tracked spending yet in the benchmarked categories — they'll
            populate as your transactions sync.</p>
    <?php else: ?>
    <div class="hero-split">
        <div class="split-cell">
            <span class="split-label">You<?= $view['low_conf'] ? ' <span class="apy-est">est</span>' : '' ?></span>
            <span class="split-value"><?= e(usd($view['sum_you'])) ?></span>
        </div>
        <div class="split-cell">
            <span class="split-label">Typical · <?= e($b['label']) ?></span>
            <span class="split-value"><?= e(usd($view['sum_typical'])) ?></span>
        </div>
    </div>
    <p class="muted hero-sub">
        <?php if ($view['overall_pct'] === null): ?>
            Pick your income bracket above to compare.
        <?php else: ?>
            That's <strong class="<?= $view['overall_over'] ? 'neg' : 'pos' ?>"><?= ($view['overall_diff'] >= 0 ? '+' : '−') . e(usd(abs($view['overall_diff']))) ?>
            (<?= e(number_format(abs($view['overall_pct']), 0)) ?>% <?= $view['overall_over'] ? 'more' : 'less' ?>)</strong>
            than a typical <strong><?= e($b['label']) ?></strong> household
            (<?= e(peer_bracket_label($b)) ?>), across the
            <?= (int)$view['compared_count'] ?> of <?= (int)$view['total_count'] ?> categories
            you have tracked spending in.
        <?php endif; ?>
    </p>
    <?php endif; ?>
</section>

<!-- Per-category comparison -->
<section class="block">
    <div class="block-head">
        <h2>By category</h2>
        <span class="muted">you vs typical · per year</span>
    </div>
    <div class="card">
        <div class="peer-list">
            <?php foreach ($view['rows'] as $row):
                $scale = max($row['you_annual'], $row['typical_annual'], 1);
                $youW  = max(0.0, min(100.0, $row['you_annual'] / $scale * 100));
                $typW  = max(0.0, min(100.0, $row['typical_annual'] / $scale * 100));
            ?>
            <div class="peer-row">
                <?php if (!$row['has_spend']): ?>
                    <!-- No tracked spending: don't fabricate a "100% less" comparison (S37-review HIGH) -->
                    <div class="peer-row-head">
                        <span class="peer-cat"><?= e($row['label']) ?></span>
                        <span class="delta-chip muted">no tracked spending</span>
                    </div>
                    <div class="peer-figs muted">
                        <span>Not compared — nothing recorded here in your accounts.</span>
                        <span class="peer-typ">Typical <strong><?= e(usd($row['typical_annual'])) ?></strong>/yr</span>
                    </div>
                <?php else: ?>
                    <div class="peer-row-head">
                        <span class="peer-cat"><?= e($row['label']) ?></span>
                        <?= $chip($row['pct'], $row['over']) ?>
                    </div>
                    <div class="peer-bar" role="img"
                         aria-label="You <?= e(usd($row['you_annual'])) ?> per year vs typical <?= e(usd($row['typical_annual'])) ?>">
                        <div class="peer-bar-fill <?= $row['over'] ? 'over' : 'under' ?>" style="width: <?= e(number_format($youW, 2)) ?>%"></div>
                        <div class="peer-bar-mark" style="left: <?= e(number_format($typW, 2)) ?>%"></div>
                    </div>
                    <div class="peer-figs muted">
                        <span>You <strong><?= e(usd($row['you_annual'])) ?></strong>/yr
                            <span class="peer-mo">(<?= e(usd($row['you_month'])) ?>/mo)</span></span>
                        <span class="peer-typ">Typical <strong><?= e(usd($row['typical_annual'])) ?></strong>/yr</span>
                    </div>
                <?php endif; ?>
                <?php if ($row['note'] !== ''): ?>
                    <p class="peer-note muted">⚠ <?= e($row['note']) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Honest caveats -->
<section class="block">
    <div class="card peer-caveats">
        <ul class="muted">
            <li><strong>Based on ~<?= (int)$view['months_observed'] ?> month<?= $view['months_observed'] === 1 ? '' : 's' ?>
                of your history</strong> (through <?= e($throughLbl) ?>), scaled to a full year.
                <?php if ($view['low_conf']): ?>
                    With under <?= PEER_MIN_CONF_MONTHS ?> months linked these annual estimates can swing
                    (marked <em>est</em>) — they sharpen as more history accrues.
                <?php endif; ?>
                If your spending is seasonal, a partial-year sample can over- or under-state the annual figure.</li>
            <li>Only the <strong><?= (int)$view['compared_count'] ?></strong> categor<?= $view['compared_count'] === 1 ? 'y' : 'ies' ?>
                you have tracked spending in are summed in the headline; a category with no recorded
                spending shows "no tracked spending" and is left out (we can't tell "spent nothing" from
                "not tracked here").</li>
            <li>These categories are roughly <strong>half</strong> of a typical household's total
                spending — housing/shelter, insurance, education, apparel and more aren't benchmarked here.</li>
            <li>Brackets are <strong>income before taxes</strong>. Pick the one matching your household's
                gross income — your linked accounts only see post-tax deposits, so we don't guess it.</li>
            <li>A typical <strong><?= e($b['label']) ?></strong> household here is about
                <strong><?= e(number_format($b['people'], 1)) ?> people</strong>; yours may differ. If you
                <em>rent</em>, your "Utilities &amp; rent" line includes rent (high vs the BLS utilities
                figure); a mortgage's principal, interest and property tax sit elsewhere.</li>
            <li>Only categories with a clean match to a BLS line are shown; ⚠ marks where the BLS
                definition covers more (or less) than we track here.</li>
            <li>Source: <strong><?= e($view['source']) ?></strong> (average annual expenditures per
                household). A rough benchmark, not a budget rule.</li>
        </ul>
    </div>
</section>

<?php render_footer(); ?>
