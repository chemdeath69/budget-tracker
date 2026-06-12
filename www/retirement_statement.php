<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/retirement.php';        // ret_period_key()
require __DIR__ . '/lib/sync.php';              // write_networth_snapshot()
require __DIR__ . '/lib/statement_ocr.php';     // photo → structured figures (Session 55, #25)
require __DIR__ . '/lib/statement_import.php';  // save holdings + activity
require_login();

/**
 * Enter (or correct) a quarterly 401(k) statement. Owner-only — you record your own
 * mailings. A statement is one row in retirement_statements, bucketed by quarter
 * (account_id, period_key): re-entering a quarter UPDATES it. Saving also refreshes
 * the account's current balance, the per-account balance history and today's
 * net-worth snapshot, so the dashboard + net worth reflect it immediately.
 *
 * Session 55 (#25): an optional "Import from photo" step — upload the statement pages
 * (multiple photos, one statement), a vision model (lib/statement_ocr.php) extracts the
 * figures, and the normal form is PRE-FILLED for your review. On save, the reviewed
 * holdings + dividend/capital-gain/fee activity are written via statement_import_save()
 * in the SAME transaction as the statement row. Extraction never auto-saves.
 */

$pdo = db();
$uid = current_user_id();

/** Parse a money field ("$1,234.50", "1234.5", "") → float|null. */
function ret_num($v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([',', '$', ' '], '', $v);
    return is_numeric($v) ? (float)$v : null;
}

/** Format a numeric extractor value for a text input value (no thousands separators). */
function ret_inval($v): string
{
    return ($v === null || $v === '') ? '' : (is_numeric($v) ? number_format((float)$v, 2, '.', '') : '');
}

// Only the owner of a *manual* 401(k) may record its statements. Statement entry
// overwrites balance_current + account_balance_history, which must never touch a
// Plaid-synced retirement account (q_retirement_accounts() also returns those) —
// is_retirement() narrows the list to hand-tracked 401(k)s, so the <select> can't
// offer a Plaid account and a forged POST for one fails the $valid check below.
$owned = array_values(array_filter(
    q_retirement_accounts($pdo, $uid),
    fn($a) => (int)$a['owner_id'] === $uid && is_retirement($a)
));

$importEnabled = statement_ocr_enabled($GLOBALS['CONFIG']) && $owned;
$prefill = null;   // set by the 'extract' action → pre-fills + review section below
$preAcct = (string)($_GET['account_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    // -- Photo import: extract figures, then re-render the form pre-filled (no save) --
    if ($action === 'extract') {
        @set_time_limit(120);   // a vision call can take ~10-30s
        if (!csrf_check_request()) {
            flash_set('error', 'Your session expired — please try again.');
            header('Location: /retirement_statement.php');
            exit;
        }
        $acctId = (string)($_POST['account_id'] ?? '');
        $valid  = null;
        foreach ($owned as $a) { if ($a['account_id'] === $acctId) { $valid = $a; break; } }
        $preAcct = $acctId;

        if (!$importEnabled) {
            flash_set('error', 'Photo import is not configured.');
        } elseif (!$valid) {
            flash_set('error', 'Choose one of your 401(k) accounts.');
        } else {
            // Collect the uploaded pages (one statement = several photos).
            $paths = [];
            $f = $_FILES['pages'] ?? null;
            if ($f && is_array($f['tmp_name'])) {
                foreach ($f['tmp_name'] as $k => $tmp) {
                    if (($f['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    if (!is_uploaded_file($tmp) || @getimagesize($tmp) === false) continue;
                    $paths[] = $tmp;
                    if (count($paths) >= STATEMENT_OCR_MAX_IMAGES) break;
                }
            }
            if (!$paths) {
                flash_set('error', 'Add at least one photo of the statement (image files only).');
            } else {
                $res = statement_ocr_extract($paths, $GLOBALS['CONFIG']);
                if (!$res['ok']) {
                    // Keep the raw provider/cURL detail in the server log; show the owner a
                    // generic message (don't reflect Anthropic/transport internals into the UI).
                    error_log('statement_ocr extract failed: ' . $res['error']);
                    flash_set('error', 'Could not read the statement — please retry, or use clearer photos.');
                } else {
                    $prefill = ['data' => $res['data'], 'warnings' => $res['warnings'], 'account_id' => $acctId];
                }
            }
        }
        // fall through to render (pre-filled when $prefill is set)

    } else {
        // -- Save the reviewed statement (+ holdings/activity when imported) ----------
        if (!csrf_check_request()) {
            flash_set('error', 'Your session expired — please try again.');
            header('Location: /retirement_statement.php');
            exit;
        }
        $acctId = (string)($_POST['account_id'] ?? '');
        $valid  = null;
        foreach ($owned as $a) { if ($a['account_id'] === $acctId) { $valid = $a; break; } }

        $date = trim((string)($_POST['statement_date'] ?? ''));
        $bal  = ret_num($_POST['balance'] ?? '');
        $ts   = $date !== '' ? strtotime($date) : false;

        $err = null;
        if (!$valid)                              $err = 'Choose one of your 401(k) accounts.';
        elseif ($ts === false)                    $err = 'Enter a valid statement date.';
        elseif ($ts > strtotime(date('Y-m-d')))   $err = 'The statement date is in the future.';
        elseif ($bal === null || $bal < 0)        $err = 'Enter the statement balance.';

        if ($err) {
            flash_set('error', $err);
            header('Location: /retirement_statement.php' . ($acctId !== '' ? '?account_id=' . urlencode($acctId) : ''));
            exit;
        }

        $dateYmd = date('Y-m-d', $ts);
        $period  = ret_period_key($dateYmd);
        $ee  = ret_num($_POST['employee_contrib'] ?? '');
        $er  = ret_num($_POST['employer_contrib'] ?? '');
        $eey = ret_num($_POST['employee_ytd'] ?? '');
        $ery = ret_num($_POST['employer_ytd'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note === '') $note = null;

        // Optional holdings/activity carried over from a photo-import review.
        $import = null;
        $rawImport = (string)($_POST['import_data'] ?? '');
        if ($rawImport !== '' && strlen($rawImport) <= 100000) {
            $decoded = json_decode($rawImport, true);
            if (is_array($decoded)) $import = $decoded;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO retirement_statements
                    (account_id, period_key, statement_date, balance, employee_contrib,
                     employer_contrib, employee_ytd, employer_ytd, note, created_by)
                 VALUES (:a,:p,:d,:b,:ee,:er,:eey,:ery,:n,:by)
                 ON DUPLICATE KEY UPDATE
                    statement_date=VALUES(statement_date), balance=VALUES(balance),
                    employee_contrib=VALUES(employee_contrib), employer_contrib=VALUES(employer_contrib),
                    employee_ytd=VALUES(employee_ytd), employer_ytd=VALUES(employer_ytd),
                    note=VALUES(note), updated_at=CURRENT_TIMESTAMP'
            )->execute([
                ':a' => $acctId, ':p' => $period, ':d' => $dateYmd, ':b' => $bal,
                ':ee' => $ee, ':er' => $er, ':eey' => $eey, ':ery' => $ery, ':n' => $note, ':by' => $uid,
            ]);

            // Current balance follows the most recent statement on file for this account.
            $latest = $pdo->prepare(
                'SELECT balance, statement_date FROM retirement_statements
                 WHERE account_id = ? ORDER BY statement_date DESC, id DESC LIMIT 1'
            );
            $latest->execute([$acctId]);
            $lr = $latest->fetch();
            if ($lr) {
                $pdo->prepare(
                    'UPDATE accounts SET balance_current = ?, last_updated_datetime = ? WHERE account_id = ?'
                )->execute([$lr['balance'], $lr['statement_date'] . ' 00:00:00', $acctId]);
            }

            // Real history point at the statement date (charts + account detail).
            $pdo->prepare(
                'INSERT INTO account_balance_history (account_id, snapshot_date, balance)
                 VALUES (?,?,?) ON DUPLICATE KEY UPDATE balance = VALUES(balance)'
            )->execute([$acctId, $dateYmd, $bal]);

            // Imported holdings + activity (same transaction). Refresh the current-snapshot
            // holdings only when this statement is the newest on file for the account.
            if ($import) {
                $isLatest = $lr && $lr['statement_date'] === $dateYmd;
                statement_import_save($pdo, $acctId, $period, $dateYmd, $import, $isLatest);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('retirement_statement error: ' . $e->getMessage());
            flash_set('error', 'Could not save the statement.');
            header('Location: /retirement_statement.php?account_id=' . urlencode($acctId));
            exit;
        }

        // Fold the new balance into today's net-worth snapshot right away.
        write_networth_snapshot($pdo);

        flash_set('ok', 'Statement saved for ' . $period . ($import ? ' (with holdings & activity).' : '.'));
        header('Location: /retirement.php');
        exit;
    }
}

// --- GET / post-extract render ----------------------------------------------
$pf = $prefill['data'] ?? null;
if ($pf && !empty($prefill['account_id'])) $preAcct = (string)$prefill['account_id'];

render_header('Add a statement', 'retirement', ['back' => '/retirement.php', 'narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>

<?php if (!$owned): ?>
<section class="card">
    <h2>No 401(k) to update</h2>
    <p class="muted">You don't own a manually-tracked 401(k) yet. Add one first, then enter its statements.</p>
    <a class="btn" href="/retirement_add.php">Add a 401(k)</a>
</section>
<?php else: ?>

<?php if ($importEnabled && !$pf): ?>
<section class="card import-card">
    <h2>📷 Import from photo</h2>
    <p class="muted">Snap or upload the statement pages — several photos for one statement is fine.
        We'll read the balance, holdings and activity and pre-fill the form for you to check before saving.</p>
    <form method="post" enctype="multipart/form-data" class="stack-form" id="import-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="extract">
        <label class="field">
            <span class="field-label">Account</span>
            <select class="select" name="account_id" required>
                <?php foreach ($owned as $a): ?>
                    <option value="<?= e($a['account_id']) ?>"<?= $a['account_id'] === $preAcct ? ' selected' : '' ?>>
                        <?= e($a['name'] ?: '401(k)') ?><?= $a['institution_name'] ? ' — ' . e($a['institution_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Statement pages <span class="muted">(up to <?= STATEMENT_OCR_MAX_IMAGES ?> images)</span></span>
            <input class="input file-input" type="file" name="pages[]" id="import-files"
                   accept="image/*" capture="environment" multiple required>
        </label>
        <div class="file-previews" id="import-previews"></div>
        <button class="btn" type="submit" id="import-submit">Read statement</button>
    </form>
</section>
<?php endif; ?>

<?php if ($pf): ?>
<section class="card review-card">
    <h2>Review imported figures</h2>
    <p class="muted">Read from
        <?= e($pf['provider'] ?: 'your statement') ?><?= !empty($pf['plan_name']) ? ' — ' . e($pf['plan_name']) : '' ?><?php
        if (!empty($pf['period_start']) && !empty($pf['period_end'])) echo ' · ' . e($pf['period_start']) . ' → ' . e($pf['period_end']); ?>.
        Check the figures below, then <strong>Save</strong>. If anything is wrong, retake the photo and import again.</p>
    <?php foreach (($prefill['warnings'] ?? []) as $w): ?>
        <div class="notice warn"><?= e($w) ?></div>
    <?php endforeach; ?>

    <?php if (!empty($pf['holdings'])): ?>
    <h3 class="review-head">Holdings</h3>
    <div class="rows">
        <?php foreach ($pf['holdings'] as $h): ?>
        <div class="row">
            <div class="row-main"><div class="row-title"><?= e($h['name'] ?? 'Fund') ?></div>
                <div class="row-sub muted">
                    <?php if (isset($h['units']) && $h['units'] !== null) echo rtrim(rtrim(number_format((float)$h['units'], 3, '.', ''), '0'), '.') . ' units'; ?>
                </div>
            </div>
            <div class="row-amt"><?= isset($h['value']) && $h['value'] !== null ? usd((float)$h['value']) : '—' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($pf['activity'])): ?>
    <h3 class="review-head">Activity</h3>
    <div class="rows">
        <?php foreach ($pf['activity'] as $a):
            $amt = isset($a['amount']) && $a['amount'] !== null ? (float)$a['amount'] : null;
            if ($amt === null) continue; ?>
        <div class="row">
            <div class="row-main">
                <div class="row-title"><?= e($a['description'] ?: ucfirst((string)($a['type'] ?? 'Activity'))) ?></div>
                <div class="row-sub muted"><?= e(ucwords(str_replace('_', ' ', (string)($a['type'] ?? '')))) ?><?php
                    if (!empty($a['date'])) echo ' · ' . e($a['date']); ?></div>
            </div>
            <div class="row-amt <?= $amt >= 0 ? 'pos' : 'neg' ?>"><?= ($amt >= 0 ? '+' : '−') . usd(abs($amt)) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= $pf ? 'Confirm &amp; save' : 'Enter a statement' ?></h2>
    <p class="muted"><?= $pf
        ? 'These were read from your photos — edit anything that looks off, then save.'
        : 'Copy the figures off your latest 401(k) statement. Contributions are for this statement\'s period (your contribution and the employer match) — they let us separate market growth from deposits and derive your return. Re-entering the same quarter replaces it.' ?></p>

    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($pf): ?>
        <input type="hidden" name="import_data" value="<?= e(json_encode(['holdings' => $pf['holdings'] ?? [], 'activity' => $pf['activity'] ?? []], JSON_UNESCAPED_SLASHES)) ?>">
        <?php endif; ?>
        <label class="field">
            <span class="field-label">Account</span>
            <select class="select" name="account_id" required>
                <?php foreach ($owned as $a): ?>
                    <option value="<?= e($a['account_id']) ?>"<?= $a['account_id'] === $preAcct ? ' selected' : '' ?>>
                        <?= e($a['name'] ?: '401(k)') ?><?= $a['institution_name'] ? ' — ' . e($a['institution_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Statement date</span>
            <input class="input" type="date" name="statement_date" max="<?= e(date('Y-m-d')) ?>"
                   value="<?= e($pf['period_end'] ?? '') ?>" required>
        </label>
        <label class="field">
            <span class="field-label">Total balance</span>
            <input class="input" type="text" inputmode="decimal" name="balance" required
                   value="<?= e(ret_inval($pf['balance'] ?? null)) ?>" placeholder="e.g. 128,450.00">
        </label>

        <div class="cols">
            <label class="field">
                <span class="field-label">Your contribution <span class="muted">(this period)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employee_contrib"
                       value="<?= e(ret_inval($pf['contributions_period']['employee'] ?? null)) ?>" placeholder="optional">
            </label>
            <label class="field">
                <span class="field-label">Employer match <span class="muted">(this period)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employer_contrib"
                       value="<?= e(ret_inval($pf['contributions_period']['employer'] ?? null)) ?>" placeholder="optional">
            </label>
        </div>
        <div class="cols">
            <label class="field">
                <span class="field-label">Your contribution YTD <span class="muted">(optional)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employee_ytd"
                       value="<?= e(ret_inval($pf['contributions_ytd']['employee'] ?? null)) ?>" placeholder="optional">
            </label>
            <label class="field">
                <span class="field-label">Employer match YTD <span class="muted">(optional)</span></span>
                <input class="input" type="text" inputmode="decimal" name="employer_ytd"
                       value="<?= e(ret_inval($pf['contributions_ytd']['employer'] ?? null)) ?>" placeholder="optional">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Note <span class="muted">(optional)</span></span>
            <input class="input" type="text" name="note" maxlength="200"
                   value="<?= e($pf ? trim(($pf['provider'] ?? '') . ' statement (imported)') : '') ?>"
                   placeholder="e.g. Q2 statement">
        </label>

        <button class="btn" type="submit"><?= $pf ? 'Save statement' : 'Save statement' ?></button>
    </form>
</section>
<?php endif; ?>

<?php render_footer(); ?>
