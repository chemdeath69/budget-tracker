<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';
require __DIR__ . '/../lib/manual/ingest.php';
require_login();

/**
 * Upload + ingest a document for a manual account. This is a classic multipart
 * form POST (not JSON): on completion it sets a flash message and 303-redirects
 * back to the account page, so it works without JavaScript.
 */

$pdo = db();
$uid = current_user_id();
$accountId = (string)($_POST['account_id'] ?? '');
$back = '/account.php?account_id=' . urlencode($accountId);

function upload_done(string $type, string $msg, string $back): void
{
    flash_set($type, $msg);
    header('Location: ' . $back, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_done('error', 'Invalid request.', '/settings.php');
}
if (!csrf_check_request()) {
    upload_done('error', 'Your session expired — please try again.', $back);
}
access_log_action($pdo, (int)$uid, 'manual_upload', 'upload', $accountId !== '' ? $accountId : null);   // audit (best-effort)

$acct = $accountId !== '' ? q_account($pdo, $uid, $accountId) : null;
if (!$acct || !is_manual($acct)) {
    upload_done('error', 'Account not found.', '/settings.php');
}
if ((int)$acct['owner_id'] !== $uid) {
    upload_done('error', 'Only the owner can update this account.', $back);
}

$f = $_FILES['document'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE)
        ? 'That file is too large to upload.'
        : 'Please choose a PDF to upload.';
    upload_done('error', $msg, $back);
}
if ((int)$f['size'] > 25 * 1024 * 1024) {
    upload_done('error', 'That file is too large (max 25 MB).', $back);
}

try {
    $res = manual_ingest($pdo, $acct, $f['tmp_name'], (string)($f['name'] ?? 'document.pdf'), $uid);
    $detail = manual_upload_detail($res);
    upload_done($res['status'] === 'duplicate' ? 'info' : 'ok', $res['message'] . $detail, $back);
} catch (ManualIngestError $e) {
    upload_done('error', $e->getMessage(), $back);
} catch (Throwable $e) {
    error_log('manual_upload error: ' . $e->getMessage());
    upload_done('error', 'Something went wrong reading that document.', $back);
}

/** Build a short " (… )" detail string from an ingest result for the flash. */
function manual_upload_detail(array $res): string
{
    $s = $res['summary'] ?? [];
    if (($s['kind'] ?? '') === 'statement') {
        $bits = [];
        if (isset($s['total_value']) && $s['total_value'] !== null) $bits[] = 'value ' . usd($s['total_value']);
        $bits[] = (int)($s['positions'] ?? 0) . ' holdings';
        $bits[] = (int)($s['trades'] ?? 0) . ' trades';
        $bits[] = (int)($s['activity'] ?? 0) . ' cash entries';
        return $bits ? ' (' . implode(', ', $bits) . ')' : '';
    }
    if (($s['kind'] ?? '') === 'tax') {
        $od = $s['ordinary_dividends'] ?? null;
        return $od !== null ? ' (ordinary dividends ' . usd($od) . ')' : '';
    }
    return '';
}
