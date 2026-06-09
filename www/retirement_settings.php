<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require_login();

/**
 * Global retirement-projection assumptions (one shared household row, id=1):
 * target retirement year, expected ongoing annual contribution, a growth-rate
 * override (blank = derive from statement history), the default rate used until
 * there's enough history, and an optional target amount.
 */

$pdo = db();
$uid = current_user_id();

/** "$1,234.50"/"" → float|null. */
function rs_num($v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([',', '$', ' ', '%'], '', $v);
    return is_numeric($v) ? (float)$v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /retirement_settings.php');
        exit;
    }
    $yearRaw = trim((string)($_POST['retirement_year'] ?? ''));
    $year = $yearRaw === '' ? null : (int)$yearRaw;
    if ($year !== null && ($year < 1990 || $year > 2100)) $year = null;

    $annual   = rs_num($_POST['annual_contribution'] ?? '');
    $target    = rs_num($_POST['target_amount'] ?? '');
    $ovrPct    = rs_num($_POST['growth_rate_override'] ?? '');   // percent
    $defPct    = rs_num($_POST['growth_default'] ?? '');         // percent
    $override  = $ovrPct !== null ? round($ovrPct / 100, 4) : null;
    $default   = $defPct !== null ? round($defPct / 100, 4) : 0.06;

    try {
        $pdo->prepare(
            'INSERT INTO retirement_settings
                (id, retirement_year, annual_contribution, growth_rate_override, growth_default, target_amount, updated_by)
             VALUES (1,:y,:a,:o,:d,:t,:by)
             ON DUPLICATE KEY UPDATE
                retirement_year=VALUES(retirement_year), annual_contribution=VALUES(annual_contribution),
                growth_rate_override=VALUES(growth_rate_override), growth_default=VALUES(growth_default),
                target_amount=VALUES(target_amount), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP'
        )->execute([
            ':y' => $year, ':a' => $annual, ':o' => $override, ':d' => $default, ':t' => $target, ':by' => $uid,
        ]);
    } catch (Throwable $e) {
        error_log('retirement_settings error: ' . $e->getMessage());
        flash_set('error', 'Could not save the assumptions.');
        header('Location: /retirement_settings.php');
        exit;
    }
    flash_set('ok', 'Projection assumptions saved.');
    header('Location: /retirement.php');
    exit;
}

$s = q_retirement_settings($pdo);
$ovrVal = $s['growth_rate_override'] !== null ? rtrim(rtrim(number_format($s['growth_rate_override'] * 100, 2), '0'), '.') : '';
$defVal = rtrim(rtrim(number_format($s['growth_default'] * 100, 2), '0'), '.');

render_header('Projection assumptions', 'retirement', ['back' => '/retirement.php', 'narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<section class="card">
    <h2>Retirement projection</h2>
    <p class="muted">These assumptions drive the combined projection on the Retirement page. Leave the
        growth override blank to use the rate <strong>derived from your statement history</strong>; the
        default below is used until there's enough history to derive one.</p>

    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <label class="field">
            <span class="field-label">Target retirement year</span>
            <input class="input" type="number" name="retirement_year" min="1990" max="2100" step="1"
                   value="<?= $s['retirement_year'] !== null ? e((string)$s['retirement_year']) : '' ?>"
                   placeholder="e.g. <?= e((string)((int)date('Y') + 20)) ?>">
        </label>
        <label class="field">
            <span class="field-label">Expected annual contribution <span class="muted">(blank = use your last 12 months)</span></span>
            <input class="input" type="text" inputmode="decimal" name="annual_contribution"
                   value="<?= $s['annual_contribution'] !== null ? e(number_format($s['annual_contribution'], 2)) : '' ?>"
                   placeholder="combined you + employer, e.g. 25,000">
        </label>
        <label class="field">
            <span class="field-label">Growth rate override % <span class="muted">(blank = derive from history)</span></span>
            <input class="input" type="text" inputmode="decimal" name="growth_rate_override"
                   value="<?= e($ovrVal) ?>" placeholder="e.g. 7">
        </label>
        <label class="field">
            <span class="field-label">Default growth rate % <span class="muted">(used until history is available)</span></span>
            <input class="input" type="text" inputmode="decimal" name="growth_default"
                   value="<?= e($defVal) ?>" placeholder="6">
        </label>
        <label class="field">
            <span class="field-label">Target amount <span class="muted">(optional retirement number)</span></span>
            <input class="input" type="text" inputmode="decimal" name="target_amount"
                   value="<?= $s['target_amount'] !== null ? e(number_format($s['target_amount'], 2)) : '' ?>"
                   placeholder="e.g. 1,500,000">
        </label>
        <button class="btn" type="submit">Save assumptions</button>
    </form>
</section>

<?php render_footer(); ?>
