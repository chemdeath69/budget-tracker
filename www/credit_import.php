<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/credit_ocr.php';     // file → structured report (Session 58, #28)
require __DIR__ . '/lib/credit_import.php';  // encrypt + save
require_login();

/**
 * Import a consumer credit report (TODO2 #28). The owner downloads their FREE report
 * (AnnualCreditReport.com / FreeCreditReport.gov) as a PDF (or photographs it) and uploads
 * it here. A vision/document model (lib/credit_ocr.php) extracts the data, the owner
 * reviews it, and on save it's encrypted + written via credit_import_save().
 *
 * ⚠️ The raw uploaded file is DISCARDED after parse (never stored on disk). Between the
 * extract step and the save step the masked structured result lives ONLY in $_SESSION
 * (not echoed into the page HTML), and is cleared on save. Reports are household-visible,
 * so the importer may pick whose report it is.
 */

$pdo = db();
$uid = current_user_id();
$CFG = $GLOBALS['CONFIG'];

$enabled = credit_ocr_enabled($CFG);
$users   = household_users();   // [id => name]
$review  = null;                // set after a successful extract → review section

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // -- Extract: read the uploaded file(s), stash the result, re-render for review --
    if ($action === 'extract') {
        @set_time_limit(200);   // a multi-page PDF read can take a while
        if (!csrf_check_request()) {
            flash_set('error', 'Your session expired — please try again.');
            header('Location: /credit_import.php');
            exit;
        }
        $reportUser = (int)($_POST['report_user'] ?? 0);
        if (!$enabled) {
            flash_set('error', 'Credit-report import is not configured.');
        } elseif (!isset($users[$reportUser])) {
            flash_set('error', 'Choose whose report this is.');
        } else {
            $paths = [];
            $f = $_FILES['files'] ?? null;
            if ($f && is_array($f['tmp_name'])) {
                foreach ($f['tmp_name'] as $k => $tmp) {
                    if (($f['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    if (!is_uploaded_file($tmp)) continue;
                    if (credit_ocr_kind($tmp) === null) continue;   // not a PDF or supported image
                    $paths[] = $tmp;
                    if (count($paths) >= CREDIT_OCR_MAX_FILES) break;
                }
            }
            if (!$paths) {
                flash_set('error', 'Add the report file (a PDF, or photos of the pages).');
            } else {
                $res = credit_ocr_extract($paths, $CFG);
                if (!$res['ok']) {
                    // Keep provider/transport detail in the server log; show a generic message.
                    error_log('credit_ocr extract failed: ' . $res['error']);
                    flash_set('error', 'Could not read the report — please retry, or use a clearer file.');
                } else {
                    $d = $res['data'];
                    $_SESSION['credit_pending'] = [
                        'token'    => bin2hex(random_bytes(16)),
                        'user_id'  => $reportUser,
                        'header'   => [
                            'bureau'        => $d['bureau'] ?? null,
                            'pulled_on'     => $d['pulled_on'] ?? null,
                            'score'         => $d['score'] ?? null,
                            'score_model'   => $d['score_model'] ?? null,
                            'consumer_name' => $d['consumer_name'] ?? null,
                        ],
                        'data'     => [
                            'tradelines' => $d['tradelines'] ?? [],
                            'inquiries'  => $d['inquiries'] ?? [],
                            'flags'      => $d['flags'] ?? [],
                        ],
                        'warnings' => $res['warnings'] ?? [],
                    ];
                    $review = $_SESSION['credit_pending'];
                }
            }
        }
        // fall through to render

    } elseif ($action === 'save') {
        if (!csrf_check_request()) {
            flash_set('error', 'Your session expired — please try again.');
            header('Location: /credit_import.php');
            exit;
        }
        $pending = $_SESSION['credit_pending'] ?? null;
        $token   = (string)($_POST['token'] ?? '');
        if (!is_array($pending) || !hash_equals((string)($pending['token'] ?? ''), $token)) {
            flash_set('error', 'That review expired — please upload the report again.');
            header('Location: /credit_import.php');
            exit;
        }
        // Owner-editable header fields (the heavy arrays come from the session, untampered).
        $reportUser = (int)($_POST['report_user'] ?? $pending['user_id']);
        if (!isset($users[$reportUser])) $reportUser = (int)$pending['user_id'];

        $bureau = strtolower(trim((string)($_POST['bureau'] ?? '')));
        $pulled = trim((string)($_POST['pulled_on'] ?? ''));
        $scoreR = trim((string)($_POST['score'] ?? ''));
        $header = [
            'bureau'        => $bureau,
            'pulled_on'     => $pulled,
            'score'         => $scoreR === '' ? null : (int)$scoreR,
            'score_model'   => trim((string)($_POST['score_model'] ?? '')) ?: ($pending['header']['score_model'] ?? null),
            'consumer_name' => $pending['header']['consumer_name'] ?? null,
        ];
        if ($pulled === '' || strtotime($pulled) === false) {
            flash_set('error', 'Enter the report date.');
            header('Location: /credit_import.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $reportId = credit_import_save($pdo, $reportUser, $uid, $header, $pending['data']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('credit_import save error: ' . $e->getMessage());
            flash_set('error', 'Could not save the report.');
            header('Location: /credit_import.php');
            exit;
        }

        unset($_SESSION['credit_pending']);
        flash_set('ok', 'Credit report saved.');
        header('Location: /credit.php?report_id=' . $reportId);
        exit;
    }
}

render_header('Import a credit report', 'credit', ['back' => '/credit.php', 'narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if (!$enabled): ?>
<section class="card">
    <h2>Importer not configured</h2>
    <p class="muted">Credit-report import needs an Anthropic API key. Add <code>anthropic.api_key</code>
        to the server config to enable it.</p>
</section>

<?php else: ?>

<?php if (!$review): ?>
<section class="card import-card">
    <h2>📄 Upload a credit report</h2>
    <p class="muted">Download your free report from
        <a href="https://www.annualcreditreport.com" target="_blank" rel="noopener">AnnualCreditReport.com</a>
        (free weekly from each bureau) and upload the PDF — or take clear photos of the pages. We read the
        accounts, inquiries and any score, then let you review before saving. The uploaded file is
        <strong>not stored</strong> — only the parsed summary is kept (encrypted).</p>
    <form method="post" enctype="multipart/form-data" class="stack-form" id="credit-import-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="extract">
        <label class="field">
            <span class="field-label">Whose report is this?</span>
            <select class="select" name="report_user" required>
                <?php foreach ($users as $id => $name): ?>
                    <option value="<?= (int)$id ?>"<?= (int)$id === $uid ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Report file <span class="muted">(PDF, or up to <?= CREDIT_OCR_MAX_FILES ?> images)</span></span>
            <input class="input file-input" type="file" name="files[]" id="credit-files"
                   accept=".pdf,application/pdf,image/*" multiple required>
        </label>
        <div class="file-names" id="credit-file-names"></div>
        <button class="btn" type="submit" id="credit-import-submit">Read report</button>
    </form>
</section>

<?php else:
    $h = $review['header'];
    $tls = $review['data']['tradelines']; $inqs = $review['data']['inquiries']; $flags = $review['data']['flags'];
    $bureaus = ['equifax' => 'Equifax', 'experian' => 'Experian', 'transunion' => 'TransUnion', 'other' => 'Other'];
    $atLabels = ['revolving' => 'Revolving', 'installment' => 'Installment', 'mortgage' => 'Mortgage',
                 'auto' => 'Auto loan', 'student' => 'Student loan', 'personal' => 'Personal loan',
                 'collection' => 'Collection', 'other' => 'Other'];
?>
<section class="card review-card">
    <h2>Review the report</h2>
    <p class="muted">Read
        <?= !empty($h['consumer_name']) ? 'for <strong>' . e($h['consumer_name']) . '</strong>' : '' ?>.
        Check the figures, fix the header below if needed, then <strong>Save</strong>. The uploaded file
        is discarded — re-upload to read it again.</p>
    <?php foreach (($review['warnings'] ?? []) as $w): ?>
        <div class="notice warn"><?= e($w) ?></div>
    <?php endforeach; ?>

    <h3 class="review-head">Accounts (<?= count($tls) ?>)</h3>
    <?php if ($tls): ?>
    <div class="rows">
        <?php foreach ($tls as $t):
            $mask = $t['account_mask'] ?? null;
            $bal = $t['balance'] ?? null; $lim = $t['credit_limit'] ?? null; ?>
        <div class="row">
            <div class="row-main">
                <div class="row-title"><?= e($t['creditor'] ?: 'Account') ?><?= $mask ? ' <span class="muted">••' . e($mask) . '</span>' : '' ?></div>
                <div class="row-sub muted"><?= e($atLabels[$t['account_type']] ?? 'Account') ?><?php
                    if ($lim) echo ' · limit ' . usd((float)$lim);
                    if (!empty($t['status'])) echo ' · ' . e($t['status']); ?></div>
            </div>
            <div class="row-amt"><?= $bal !== null ? usd((float)$bal) : '—' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?><p class="muted">No accounts were read.</p><?php endif; ?>

    <?php if ($inqs): ?>
    <h3 class="review-head">Inquiries (<?= count($inqs) ?>)</h3>
    <div class="rows">
        <?php foreach ($inqs as $q): ?>
        <div class="row">
            <div class="row-main"><div class="row-title"><?= e($q['inquirer'] ?: 'Inquiry') ?></div>
                <div class="row-sub muted"><?= e(ucfirst((string)($q['inquiry_type'] ?? 'inquiry'))) ?></div></div>
            <div class="row-amt muted"><?= e($q['inquiry_date'] ?? '—') ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($flags): ?>
    <h3 class="review-head">Derogatory marks (<?= count($flags) ?>)</h3>
    <div class="rows">
        <?php foreach ($flags as $f): ?>
        <div class="row">
            <div class="row-main"><div class="row-title"><?= e($f['detail'] ?: ucwords(str_replace('_', ' ', (string)$f['kind']))) ?></div>
                <div class="row-sub muted"><?= e(ucwords(str_replace('_', ' ', (string)$f['kind']))) ?><?php
                    if (!empty($f['flag_date'])) echo ' · ' . e($f['flag_date']); ?></div></div>
            <div class="row-amt"><?= isset($f['amount']) && $f['amount'] !== null ? usd((float)$f['amount']) : '' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Confirm &amp; save</h2>
    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="token" value="<?= e($review['token']) ?>">
        <label class="field">
            <span class="field-label">Whose report</span>
            <select class="select" name="report_user" required>
                <?php foreach ($users as $id => $name): ?>
                    <option value="<?= (int)$id ?>"<?= (int)$id === (int)$review['user_id'] ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="cols">
            <label class="field">
                <span class="field-label">Bureau</span>
                <select class="select" name="bureau" required>
                    <?php foreach ($bureaus as $bk => $bl): ?>
                        <option value="<?= e($bk) ?>"<?= ($h['bureau'] ?? '') === $bk ? ' selected' : '' ?>><?= e($bl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Report date</span>
                <input class="input" type="date" name="pulled_on" max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($h['pulled_on'] ?? '') ?>" required>
            </label>
        </div>
        <div class="cols">
            <label class="field">
                <span class="field-label">Score <span class="muted">(if shown)</span></span>
                <input class="input" type="number" name="score" min="300" max="900" inputmode="numeric"
                       value="<?= $h['score'] !== null ? (int)$h['score'] : '' ?>" placeholder="often blank">
            </label>
            <label class="field">
                <span class="field-label">Score model <span class="muted">(optional)</span></span>
                <input class="input" type="text" name="score_model" maxlength="32"
                       value="<?= e($h['score_model'] ?? '') ?>" placeholder="e.g. VantageScore 3.0">
            </label>
        </div>
        <button class="btn" type="submit">Save report</button>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
