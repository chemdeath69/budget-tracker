<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/bills.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();

// Viewed month for the calendar (?month=YYYY-MM); default current month.
$ym = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
try {
    $monthFirst = new DateTimeImmutable($ym . '-01');
} catch (Throwable $e) {
    $monthFirst = new DateTimeImmutable('first day of this month');
    $ym = $monthFirst->format('Y-m');
}
// Clamp to a sane horizon (±36 months) so a far-future ?month can't render an
// empty/partial calendar or run up projection cost.
$thisMonth = new DateTimeImmutable('first day of this month');
$loBound   = $thisMonth->sub(new DateInterval('P36M'));
$hiBound   = $thisMonth->add(new DateInterval('P36M'));
if ($monthFirst < $loBound) $monthFirst = $loBound;
if ($monthFirst > $hiBound) $monthFirst = $hiBound;

$ym       = $monthFirst->format('Y-m');
$prevYm   = $monthFirst->sub(new DateInterval('P1M'))->format('Y-m');
$nextYm   = $monthFirst->add(new DateInterval('P1M'))->format('Y-m');
$thisYm   = date('Y-m');

$today    = new DateTimeImmutable('today');

// Fetch the two VIS-scoped sources once; derive both views in PHP.
$liabilities = q_liabilities($pdo, $uid);
$recurring   = q_recurring($pdo, $uid);

// Calendar: every bill in the viewed month.
$monthEnd  = $monthFirst->modify('last day of this month');
$monthOcc  = bill_occurrences($liabilities, $recurring, $monthFirst, $monthEnd);
$weeks     = bills_calendar_weeks(bills_by_date($monthOcc), $monthFirst, $today);

// Upcoming agenda + hero: the next 14 days.
$soonTo    = $today->add(new DateInterval('P14D'));
$upcoming  = bill_occurrences($liabilities, $recurring, $today, $soonTo);
$dueTotal  = 0.0;
foreach ($upcoming as $o) $dueTotal += (float)($o['amount'] ?? 0);

$hasAny = $liabilities || $recurring;

/** Friendly day header for the agenda. */
function bill_day_header(string $ymd, DateTimeImmutable $today): string {
    $t = $today->format('Y-m-d');
    if ($ymd === $t) return 'Today';
    if ($ymd === $today->add(new DateInterval('P1D'))->format('Y-m-d')) return 'Tomorrow';
    return (new DateTimeImmutable($ymd))->format('D, M j');
}

render_header('Upcoming bills', 'bills', ['narrow' => true]);
?>

<!-- Hero: total due in the next 14 days -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Due in the next 14 days</span>
        <span class="delta-sub muted"><?= count($upcoming) ?> bill<?= count($upcoming) === 1 ? '' : 's' ?></span>
    </div>
    <div class="hero-value"><?= e(usd($dueTotal)) ?></div>
    <p class="muted load-note">Bank-reported due dates (cards, loans, mortgage) plus projected recurring payments.
        The total covers bills with a known amount; a “—” means the bank doesn’t report one.</p>
</section>

<?php if (!$hasAny): ?>
    <div class="empty-state card">
        <h2>No bills to show yet</h2>
        <p class="muted">Once a bank is linked, your credit-card / loan due dates and recurring payments appear here.</p>
        <a class="btn" href="/link.php">Link a bank account</a>
    </div>
<?php else: ?>

    <!-- Month calendar -->
    <section class="card">
        <div class="cal-nav">
            <a class="btn-ghost" href="?month=<?= e($prevYm) ?>" aria-label="Previous month">‹</a>
            <h2><?= e($monthFirst->format('F Y')) ?></h2>
            <a class="btn-ghost" href="?month=<?= e($nextYm) ?>" aria-label="Next month">›</a>
        </div>
        <?php if ($ym !== $thisYm): ?>
            <p class="cal-today-link"><a href="?month=<?= e($thisYm) ?>">Back to this month</a></p>
        <?php endif; ?>

        <div class="cal">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                <div class="cal-head"><?= $d ?></div>
            <?php endforeach; ?>

            <?php foreach ($weeks as $week): ?>
                <?php foreach ($week as $cell): ?>
                    <?php if ($cell === null): ?>
                        <div class="cal-cell cal-blank"></div>
                    <?php else: ?>
                        <div class="cal-cell<?= $cell['today'] ? ' cal-today' : '' ?><?= $cell['past'] ? ' cal-past' : '' ?>">
                            <span class="cal-day"><?= $cell['day'] ?></span>
                            <?php if ($cell['bills']): ?>
                                <span class="cal-dots">
                                    <?php foreach ($cell['bills'] as $b):
                                        $dotLabel = $b['label'] . ' (' . $b['kind'] . ')'
                                                  . ($b['amount'] !== null ? ' · ' . usd($b['amount']) : ''); ?>
                                        <span class="cal-dot cal-dot-<?= $b['source'] ?>" role="img"
                                              title="<?= e($dotLabel) ?>" aria-label="<?= e($dotLabel) ?>"></span>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <p class="muted cal-legend">
            <span class="cal-dot cal-dot-liability" aria-hidden="true"></span> Bill / loan due
            &nbsp;·&nbsp;
            <span class="cal-dot cal-dot-recurring" aria-hidden="true"></span> Recurring payment
        </p>
    </section>

    <!-- Upcoming agenda (next 14 days) -->
    <section class="block">
        <div class="block-head"><h2>Next 14 days</h2><span class="count-pill"><?= count($upcoming) ?></span></div>
        <?php if (!$upcoming): ?>
            <div class="rows card"><div class="row"><span class="muted">Nothing due in the next 14 days.</span></div></div>
        <?php else: ?>
            <?php
            $curDate = null;
            foreach ($upcoming as $o):
                if ($o['date'] !== $curDate):
                    if ($curDate !== null) echo '</div></div>';
                    $curDate = $o['date'];
            ?>
            <div class="bill-day-group">
                <div class="bill-day-head"><?= e(bill_day_header($o['date'], $today)) ?></div>
                <div class="rows card">
            <?php endif; ?>
                <a class="row bill-row" href="/account.php?account_id=<?= e($o['account_id']) ?>">
                    <span class="row-main">
                        <span class="row-title"><?= e($o['label']) ?><?= owner_suffix($o['owner_id']) ?></span>
                        <span class="row-sub">
                            <span class="bill-kind bill-kind-<?= $o['source'] ?>"><?= e($o['kind']) ?></span>
                            <?= e($o['sublabel']) ?>
                        </span>
                    </span>
                    <span class="row-amt"><?= $o['amount'] === null ? '—' : e(usd($o['amount'])) ?></span>
                    <span class="chev" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php render_footer(); ?>
