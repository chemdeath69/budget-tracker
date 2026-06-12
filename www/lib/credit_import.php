<?php
/**
 * credit_import.php — persist a reviewed credit report (Session 58, TODO2 #28).
 *
 * Writes the header + tradelines + inquiries + flags from a reviewed extractor result
 * (lib/credit_ocr.php) into the migration-021 tables. Sensitive free-text fields
 * (creditor, account last-4 mask, flag detail, the consumer's printed name) are ENCRYPTED
 * at rest with the app encryption_key (lib/crypto.php secretbox); structured fields stay
 * plain so the dashboard can aggregate them.
 *
 * Invariants:
 *   • ALL placeholders positional (?) → HY093-safe on this host (native prepares).
 *   • MUST run inside the caller's transaction (credit_import.php page wraps it).
 *   • Idempotent per (user_id, bureau, pulled_on): a re-import DELETEs the existing report
 *     (the FK cascade clears its children) then re-INSERTs — so re-uploading the same pull
 *     replaces it cleanly. Distinct pulls (different pulled_on) accumulate for trends.
 *   • Account numbers are stored as LAST 4 ONLY (the extractor masks them; we re-clamp).
 */

require_once __DIR__ . '/crypto.php';   // encrypt_secret() / decrypt_secret()

/** Encrypt a non-empty string for storage; null/'' → null. */
function credit_enc(?string $s): ?string
{
    $s = trim((string)($s ?? ''));
    return $s === '' ? null : encrypt_secret($s);
}

/** Decrypt a stored *_enc value; null/'' → null; tamper/invalid → null. */
function credit_dec(?string $s): ?string
{
    if ($s === null || $s === '') return null;
    return decrypt_secret($s);
}

/** Clamp any account reference to its last 4 digits (defense-in-depth before storage). */
function credit_mask4(?string $v): ?string
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $digits = preg_replace('/\D+/', '', $v);
    if ($digits !== '') return substr($digits, -4);
    return mb_substr($v, -4);
}

/** Validate a YYYY-MM-DD-ish value → 'Y-m-d', or null. */
function credit_norm_date($v): ?string
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/** Coerce a money-ish value → float|null. */
function credit_num($v): ?float
{
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float)$v;
    $clean = str_replace([',', '$', ' '], '', (string)$v);
    return is_numeric($clean) ? (float)$clean : null;
}

/**
 * Persist one reviewed credit report. Returns the new credit_reports.id.
 *
 * @param int   $reportUserId whose report this is (household user id)
 * @param int   $createdBy    the uploading user id
 * @param array $header       ['bureau','pulled_on','score','score_model','consumer_name']
 * @param array $data         ['tradelines'=>[], 'inquiries'=>[], 'flags'=>[]]
 */
function credit_import_save(PDO $pdo, int $reportUserId, int $createdBy, array $header, array $data): int
{
    $bureau = strtolower(trim((string)($header['bureau'] ?? '')));
    if (!in_array($bureau, ['equifax', 'experian', 'transunion', 'other'], true)) $bureau = 'other';
    $pulled = credit_norm_date($header['pulled_on'] ?? null) ?? date('Y-m-d');
    $score  = (isset($header['score']) && is_numeric($header['score'])) ? (int)$header['score'] : null;
    // A real credit score lives in 300–900 (FICO/VantageScore). Reject anything outside that
    // band (e.g. a forged direct POST) rather than storing a nonsense number.
    if ($score !== null && ($score < 300 || $score > 900)) $score = null;
    $model  = trim((string)($header['score_model'] ?? '')) ?: null;
    if ($model !== null) $model = mb_substr($model, 0, 32);

    // Replace any existing pull with the same natural key (cascade clears children).
    $del = $pdo->prepare('DELETE FROM credit_reports WHERE user_id = ? AND bureau = ? AND pulled_on = ?');
    $del->execute([$reportUserId, $bureau, $pulled]);

    $pdo->prepare(
        'INSERT INTO credit_reports
            (user_id, bureau, pulled_on, score, score_model, consumer_name_enc, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $reportUserId, $bureau, $pulled, $score, $model,
        credit_enc($header['consumer_name'] ?? null), $createdBy,
    ]);
    $reportId = (int)$pdo->lastInsertId();

    // --- tradelines ---------------------------------------------------------
    $tl = $pdo->prepare(
        'INSERT INTO credit_tradelines
            (credit_report_id, creditor_enc, account_mask_enc, account_type, balance,
             credit_limit, high_balance, monthly_payment, past_due, opened_on, closed_on,
             is_open, responsibility, status, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $types = function_exists('credit_account_types') ? credit_account_types() : [];
    $i = 0;
    foreach (($data['tradelines'] ?? []) as $t) {
        $creditor = trim((string)($t['creditor'] ?? ''));
        if ($creditor === '') continue;
        $at = strtolower(trim((string)($t['account_type'] ?? '')));
        if ($types && !in_array($at, $types, true)) $at = $at !== '' ? 'other' : null;
        elseif ($at === '') $at = null;
        $isOpen = array_key_exists('is_open', $t) && $t['is_open'] !== null ? (int)(bool)$t['is_open'] : null;
        $resp   = trim((string)($t['responsibility'] ?? '')) ?: null;
        $status = trim((string)($t['status'] ?? '')) ?: null;

        $tl->execute([
            $reportId,
            credit_enc($creditor),
            credit_enc(credit_mask4($t['account_mask'] ?? null)),
            $at,
            credit_num($t['balance'] ?? null),
            credit_num($t['credit_limit'] ?? null),
            credit_num($t['high_balance'] ?? null),
            credit_num($t['monthly_payment'] ?? null),
            credit_num($t['past_due'] ?? null),
            credit_norm_date($t['opened_on'] ?? null),
            credit_norm_date($t['closed_on'] ?? null),
            $isOpen,
            $resp !== null ? mb_substr($resp, 0, 24) : null,
            $status !== null ? mb_substr($status, 0, 48) : null,
            $i++,
        ]);
    }

    // --- inquiries ----------------------------------------------------------
    $iq = $pdo->prepare(
        'INSERT INTO credit_inquiries
            (credit_report_id, inquirer_enc, inquiry_date, inquiry_type, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $i = 0;
    foreach (($data['inquiries'] ?? []) as $q) {
        $inq = trim((string)($q['inquirer'] ?? ''));
        if ($inq === '') continue;
        $it = strtolower(trim((string)($q['inquiry_type'] ?? '')));
        if (!in_array($it, ['hard', 'soft'], true)) $it = null;
        $iq->execute([$reportId, credit_enc($inq), credit_norm_date($q['inquiry_date'] ?? null), $it, $i++]);
    }

    // --- flags --------------------------------------------------------------
    $fl = $pdo->prepare(
        'INSERT INTO credit_flags
            (credit_report_id, kind, detail_enc, amount, flag_date, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $kinds = function_exists('credit_flag_kinds') ? credit_flag_kinds() : [];
    $i = 0;
    foreach (($data['flags'] ?? []) as $f) {
        $kind = strtolower(trim((string)($f['kind'] ?? '')));
        if ($kinds && !in_array($kind, $kinds, true)) $kind = 'other';
        elseif ($kind === '') $kind = 'other';
        $fl->execute([
            $reportId, $kind, credit_enc($f['detail'] ?? null),
            credit_num($f['amount'] ?? null), credit_norm_date($f['flag_date'] ?? null), $i++,
        ]);
    }

    return $reportId;
}
