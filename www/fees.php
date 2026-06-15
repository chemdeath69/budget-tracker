<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/fees.php';
require __DIR__ . '/lib/layout.php';
require_login();

/**
 * Investment fee analyzer (TODO2 #39). The household's weighted-average portfolio expense
 * ratio + the projected annual $ drag, over the WHOLE portfolio (investments + retirement
 * holdings) — Empower's headline free feature, biggest win on the 401(k) funds.
 *
 * No reliably-free expense-ratio feed exists (verified Session 70), so ratios are entered by
 * hand. NOTHING is auto-classified — a holding is "covered" only once a value is entered (a
 * fund's ratio, or 0 for a single stock/coin): the bank labels every holding 'equity' (even
 * ETFs), so a type-based auto-0 would silently understate the fee. The ratio is a household-
 * shared per-security override (table security_expense_ratio, migration 027), edited by the
 * plain CSRF <form> below (like allocation.php's target editor / safe_to_spend.php — no app.js,
 * no API endpoint). Holdings are VIS-scoped via q_holdings.
 */

$pdo = db();
$uid = current_user_id();

/** "0.5"/"0.50%"/"" → ['set'=>bool, 'pct'=>float]; blank → set=false (= revert to auto). */
function fee_ratio_in($v): array
{
    $v = trim((string)$v);
    if ($v === '') return ['set' => false, 'pct' => 0.0];
    $v = str_replace([',', '%', ' '], '', $v);
    if (!is_numeric($v) || !is_finite((float)$v)) return ['set' => false, 'pct' => 0.0, 'skip' => true];
    return ['set' => true, 'pct' => max(0.0, min(100.0, (float)$v))];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /fees.php');
        exit;
    }
    // Only act on security_ids the viewer actually holds (VIS) — never trust arbitrary POST keys.
    $valid = [];
    foreach (q_holdings($pdo, $uid) as $h) {
        $sid = ($h['security_id'] ?? '') !== '' ? (string)$h['security_id'] : null;
        if ($sid !== null && (float)($h['institution_value'] ?? 0) > 0) $valid[$sid] = true;
    }
    $posted = is_array($_POST['ratio'] ?? null) ? $_POST['ratio'] : [];
    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM security_expense_ratio WHERE security_id = ?');
        $up  = $pdo->prepare(
            'INSERT INTO security_expense_ratio (security_id, expense_ratio, updated_by)
             VALUES (:sid, :pct, :by)
             ON DUPLICATE KEY UPDATE expense_ratio = VALUES(expense_ratio),
                                     updated_by    = VALUES(updated_by),
                                     updated_at    = CURRENT_TIMESTAMP'
        );
        foreach ($valid as $sid => $_) {
            if (!array_key_exists($sid, $posted)) continue;     // field not submitted → leave as-is
            $r = fee_ratio_in($posted[$sid]);
            if (!empty($r['skip'])) continue;                   // garbage typo → leave existing value
            if ($r['set']) $up->execute([':sid' => $sid, ':pct' => $r['pct'], ':by' => $uid]);
            else           $del->execute([$sid]);               // blank → revert to auto
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('fees save error: ' . $e->getMessage());
        flash_set('error', 'Could not save the expense ratios.');
        header('Location: /fees.php');
        exit;
    }
    flash_set('ok', 'Expense ratios saved.');
    header('Location: /fees.php');
    exit;
}

// Whole portfolio: q_holdings returns ALL visible holdings (investments + retirement) — like
// allocation.php, this page does NOT filter retirement out (the 401(k) funds matter most).
$holds  = q_holdings($pdo, $uid);
$ratios = q_security_expense_ratios($pdo);
$fv     = build_fees_view($holds, $ratios);

render_header('Investment fees', 'fees', ['narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if ($fv['total'] <= 0): ?>
    <div class="empty-state card">
        <h2>No holdings to analyze yet</h2>
        <p class="muted">The fee analyzer estimates the expense ratios you're paying across your
            investment &amp; retirement <em>holdings</em>. Once a brokerage or retirement account
            reports holdings, they'll appear here.</p>
        <a class="btn" href="/investments.php">Open Investments ›</a>
    </div>
    <?php render_footer(); exit; ?>
<?php endif; ?>

<?php
$covered = $fv['coverage_pct'] >= 99.95;     // effectively 100%
$avgTxt  = $fv['weighted_avg'] !== null ? number_format($fv['weighted_avg'], 2) . '%' : '—';
?>

<!-- Hero: weighted-average expense ratio + projected annual $ drag. -->
<section class="hero card">
    <div class="hero-top">
        <span class="hero-label">Portfolio fees</span>
        <span class="delta-sub muted">whole portfolio · holdings</span>
    </div>
    <div class="hero-value"><?= e($avgTxt) ?></div>
    <p class="muted" style="margin:6px 0 0">
        weighted-average expense ratio · ≈ <strong><?= e(usd($fv['annual_fee'])) ?></strong>/year in fees<?= !$covered ? ' so far' : '' ?>
    </p>
</section>

<?php if ($fv['uncovered_count'] > 0): ?>
    <div class="notice warn">
        <strong><?= (int)$fv['uncovered_count'] ?></strong> holding<?= $fv['uncovered_count'] === 1 ? '' : 's' ?>
        worth <strong><?= e(usd($fv['uncovered_value'])) ?></strong>
        (<?= e(number_format(100 - $fv['coverage_pct'], 0)) ?>% of your portfolio)
        <?= $fv['uncovered_count'] === 1 ? "hasn't" : "haven't" ?> been entered yet — add each fund's
        ratio below (and <strong>0</strong> for a single stock or coin) for the full picture. Until then
        these figures cover only the part of your portfolio you've entered, so your real annual fee is
        <strong>higher</strong>.
    </div>
<?php endif; ?>

<?php if ($fv['biggest']): ?>
<!-- The biggest single fee drags. -->
<section class="card">
    <h2>Biggest fee drags</h2>
    <p class="muted">Where the dollars actually go each year (your share of each fund's expense ratio).</p>
    <div class="fee-drags">
        <?php foreach ($fv['biggest'] as $b): ?>
            <div class="fee-drag-row">
                <span class="fee-drag-name"><?= e($b['label']) ?></span>
                <span class="fee-drag-figs">
                    <strong><?= e(usd($b['annual_fee'])) ?></strong>/yr
                    <span class="muted">· <?= e(number_format((float)$b['ratio'], 2)) ?>% of <?= e(usd($b['value'])) ?></span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($fv['annual_fee'] > 0): ?>
        <p class="muted load-note" style="margin-top:10px">For scale: at today's balances that's about
            <strong><?= e(usd($fv['projection_total'])) ?></strong> over <?= (int)$fv['projection_years'] ?> years
            — and more as your balance grows, since fees are charged on the balance.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Per-holding expense-ratio entry (household-shared). -->
<section class="block">
    <div class="block-head"><h2>Holdings &amp; expense ratios</h2></div>
    <div class="card">
        <p class="muted">Enter each fund's annual expense ratio (as a percent — e.g. <code>0.03</code> for a
            3-basis-point index fund, <code>0.50</code> for a target-date fund; it's on the fund's fact sheet
            or your statement). A single stock or coin has no expense ratio — enter <code>0</code> to confirm
            it. (We can't auto-detect funds: the bank labels every holding the same, so nothing is assumed.)
            Ratios are shared across the household and survive bank syncs.</p>
        <form method="post" class="fee-form">
            <?= csrf_field() ?>
            <div class="rows">
                <?php foreach ($fv['rows'] as $r):
                    $editable = !empty($r['security_id']);
                    $prefill  = $r['source'] === 'manual' && $r['ratio'] !== null
                        ? rtrim(rtrim(number_format((float)$r['ratio'], 4, '.', ''), '0'), '.')
                        : '';
                    if ($prefill === '') $prefill = ($r['source'] === 'manual') ? '0' : '';
                    if ($r['source'] === 'manual') { $statusCls = 'ok';   $statusTxt = 'entered'; }
                    else                           { $statusCls = 'warn'; $statusTxt = $r['fund_hint'] ? 'needs a ratio' : 'stock? enter 0'; }
                ?>
                <div class="row fee-row">
                    <span class="row-main">
                        <span class="row-title">
                            <?php if ($editable): ?>
                                <a href="/security.php?security_id=<?= e(urlencode($r['security_id'])) ?>&amp;from=fees"><?= e($r['label']) ?></a>
                            <?php else: ?>
                                <?= e($r['label']) ?>
                            <?php endif; ?>
                        </span>
                        <span class="row-sub">
                            <?= e(usd($r['value'])) ?> · <?= e(number_format($r['pct'], 1)) ?>%
                            <?php if ($r['annual_fee'] !== null && $r['annual_fee'] > 0): ?>
                                · <span class="neg"><?= e(usd($r['annual_fee'])) ?>/yr</span>
                            <?php endif; ?>
                            · <span class="fee-status <?= $statusCls ?>"><?= e($statusTxt) ?></span>
                        </span>
                    </span>
                    <span class="fee-entry">
                        <?php if ($editable): ?>
                            <span class="fee-input-wrap">
                                <input class="input fee-ratio-input" type="text" inputmode="decimal"
                                       name="ratio[<?= e($r['security_id']) ?>]" value="<?= e($prefill) ?>"
                                       placeholder="<?= $r['fund_hint'] ? '0.50' : '0' ?>"
                                       aria-label="Expense ratio for <?= e($r['label']) ?> (percent)">
                                <span class="fee-pct">%</span>
                            </span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <button class="btn" type="submit">Save expense ratios</button>
        </form>
    </div>
</section>

<p class="muted load-note">Fees are an <strong>estimate</strong> from the expense ratios you enter — they're
    not pulled from a live feed (no free one exists), so keep them current if a fund changes. A weighted average
    weights each fund by how much you hold of it. Based on reported <strong>holdings</strong>: a brokerage's
    uninvested cash and an account with no per-holding breakdown aren't counted. Expense ratios are an ongoing
    drag but only part of total cost — they exclude loads, trading costs, advisory fees and taxes.</p>

<?php render_footer(); ?>
