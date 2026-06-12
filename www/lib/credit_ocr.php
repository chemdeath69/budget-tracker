<?php
/**
 * credit_ocr.php — vision/document OCR for a downloaded consumer credit report
 * (Session 58, TODO2 #28). Mirrors lib/statement_ocr.php's shape (#25).
 *
 * The owner downloads their FREE report (AnnualCreditReport.com / FreeCreditReport.gov)
 * as a PDF (or photographs it) and uploads it. We send the file to the Anthropic
 * (Claude) Messages API with a forced tool_use JSON schema and get back a structured,
 * provider-tolerant array (the three bureaus format differently) that credit_import.php
 * pre-fills a review form from. NOTHING here writes to the DB or auto-saves.
 *
 * ⚠️ PII: the extractor PROMPT instructs the model to emit only the LAST 4 digits of any
 * account number and to NEVER output a full account number, SSN, or date of birth. The
 * raw uploaded file is discarded by the caller after this returns (never stored on disk).
 *
 * The free report usually carries NO score — that's expected; `score` is nullable and the
 * insight layer is built from the report factors (utilization, age, inquiries, mix).
 *
 * Cost: pay-as-you-go prepaid credits on config['anthropic']['model'] (claude-sonnet-4-6);
 * empty api_key disables the feature cleanly.
 *
 * Public API:
 *   credit_ocr_enabled(array $cfg): bool
 *   credit_ocr_extract(array $filePaths, array $cfg): array
 *        → ['ok'=>bool, 'data'=>array|null, 'error'=>?string, 'warnings'=>string[],
 *           'usage'=>array|null]
 */

const CREDIT_OCR_ENDPOINT  = 'https://api.anthropic.com/v1/messages';
const CREDIT_OCR_VERSION   = '2023-06-01';
const CREDIT_OCR_MAXTOKENS = 8192;     // a report can carry many tradelines + inquiries
const CREDIT_OCR_TIMEOUT   = 180;      // seconds — a multi-page PDF is slow to read
const CREDIT_OCR_MAX_FILES = 8;        // 1 PDF, or a handful of photographed pages
const CREDIT_OCR_PDF_BYTES = 32 * 1024 * 1024;  // Anthropic PDF limit
const CREDIT_OCR_IMG_BYTES = 5 * 1024 * 1024;   // Anthropic per-image limit

/** Feature on only when a real key is present (empty string = disabled). */
function credit_ocr_enabled(array $cfg): bool
{
    return trim((string)($cfg['anthropic']['api_key'] ?? '')) !== '';
}

/** Detect the file kind from its bytes → 'pdf' | image media-type | null. */
function credit_ocr_kind(string $path): ?string
{
    $head = (string)@file_get_contents($path, false, null, 0, 5);
    if (strncmp($head, '%PDF-', 5) === 0) return 'pdf';

    $supported = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $info = @getimagesize($path);
    if ($info !== false && isset($info['mime']) && in_array($info['mime'], $supported, true)) {
        return $info['mime'];
    }
    return [
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'gif'  => 'image/gif',
        'webp' => 'image/webp', 'pdf'  => 'pdf',
    ][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
}

/** Allowed normalized account types (also the schema enum). */
function credit_account_types(): array
{
    return ['revolving', 'installment', 'mortgage', 'auto', 'student', 'personal', 'collection', 'other'];
}

/** Allowed flag kinds (also the schema enum). */
function credit_flag_kinds(): array
{
    return ['collection', 'public_record', 'late_payment', 'derogatory', 'bankruptcy', 'dispute', 'other'];
}

/**
 * The forced-output JSON schema. tool_choice on this single tool guarantees one tool_use
 * block whose `input` matches this shape. All fields nullable; the model is told to use
 * null rather than guess, and to mask account numbers to the last 4 digits only.
 */
function credit_ocr_schema(): array
{
    $money = ['type' => ['number', 'null']];
    return [
        'type' => 'object',
        'properties' => [
            'bureau'        => ['type' => ['string', 'null'], 'enum' => ['equifax', 'experian', 'transunion', 'other', null], 'description' => 'Which bureau issued this report.'],
            'pulled_on'     => ['type' => ['string', 'null'], 'description' => 'Report date / "as of" / "report generated on" date, YYYY-MM-DD.'],
            'consumer_name' => ['type' => ['string', 'null'], 'description' => 'The consumer name printed on the report (to confirm whose report it is). Do NOT include SSN or date of birth.'],
            'score'         => ['type' => ['integer', 'null'], 'description' => 'Credit score IF the report prints one (often it does not). Null if absent.'],
            'score_model'   => ['type' => ['string', 'null'], 'description' => 'Score model/version if shown, e.g. "VantageScore 3.0", "FICO 8".'],
            'tradelines' => [
                'type' => 'array',
                'description' => 'One row per account (credit card, loan, mortgage, collection) listed on the report.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'creditor'        => ['type' => 'string', 'description' => 'Creditor / lender name.'],
                        'account_mask'    => ['type' => ['string', 'null'], 'description' => 'LAST 4 DIGITS ONLY of the account number (e.g. "1006"). NEVER output the full account number.'],
                        'account_type'    => ['type' => ['string', 'null'], 'enum' => array_merge(credit_account_types(), [null]), 'description' => 'Best classification of the account.'],
                        'balance'         => array_merge($money, ['description' => 'Current balance owed.']),
                        'credit_limit'    => array_merge($money, ['description' => 'Credit limit (revolving accounts).']),
                        'high_balance'    => array_merge($money, ['description' => 'High balance / original loan amount.']),
                        'monthly_payment' => $money,
                        'past_due'        => $money,
                        'opened_on'       => ['type' => ['string', 'null'], 'description' => 'Date opened, YYYY-MM-DD.'],
                        'closed_on'       => ['type' => ['string', 'null'], 'description' => 'Date closed, YYYY-MM-DD, if closed.'],
                        'is_open'         => ['type' => ['boolean', 'null'], 'description' => 'True if the account is open/active, false if closed.'],
                        'responsibility'  => ['type' => ['string', 'null'], 'description' => 'individual / joint / authorized, if shown.'],
                        'status'          => ['type' => ['string', 'null'], 'description' => 'Payment status text, e.g. "Pays as agreed", "Closed", "Charged off".'],
                    ],
                    'required' => ['creditor'],
                ],
            ],
            'inquiries' => [
                'type' => 'array',
                'description' => 'Credit inquiries (hard pulls). Include the inquiring company + date.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'inquirer'     => ['type' => 'string'],
                        'inquiry_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD.'],
                        'inquiry_type' => ['type' => ['string', 'null'], 'enum' => ['hard', 'soft', null]],
                    ],
                    'required' => ['inquirer'],
                ],
            ],
            'flags' => [
                'type' => 'array',
                'description' => 'Derogatory marks: collections, public records, late payments, bankruptcies, disputes. Empty if the report is clean.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'kind'      => ['type' => 'string', 'enum' => credit_flag_kinds()],
                        'detail'    => ['type' => ['string', 'null'], 'description' => 'Short description, e.g. "Medical collection — XYZ Agency".'],
                        'amount'    => $money,
                        'flag_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD.'],
                    ],
                    'required' => ['kind'],
                ],
            ],
        ],
        'required' => ['tradelines'],
    ];
}

/** The extraction instruction sent alongside the file(s). */
function credit_ocr_prompt(): string
{
    return <<<TXT
You are reading a consumer credit report (Equifax, Experian, or TransUnion). Extract its
data into the extract_credit_report tool. Rules:
- PRIVACY: output only the LAST 4 DIGITS of any account number (e.g. "1006"). NEVER output a
  full account number, Social Security number, or date of birth. Omit those entirely.
- Use figures EXACTLY as printed; strip "$" and thousands commas.
- If a field is not shown, use null — never guess or compute a value the report doesn't state.
- Dates as YYYY-MM-DD.
- tradelines: ONE row per account (credit card, loan, mortgage, collection). Classify
  account_type as one of revolving (credit card / line of credit), installment, mortgage,
  auto, student, personal, collection, or other.
- credit_limit applies to revolving accounts; high_balance is the highest balance or the
  original loan amount.
- is_open: true if open/active, false if closed/paid.
- inquiries: list hard inquiries (the inquiring company + date).
- flags: collections, public records, charge-offs, late payments, bankruptcies, or disputes.
  Leave empty if there are none — do NOT fabricate.
- The free report often has NO score — leave score null if it isn't printed.
- One report, possibly many pages — merge them into one result, don't duplicate accounts.
TXT;
}

/**
 * Run the extraction. Returns a normalized result envelope. Never throws.
 *
 * @param string[] $filePaths absolute paths to the uploaded file(s) (PDF and/or images)
 */
function credit_ocr_extract(array $filePaths, array $cfg): array
{
    $fail = fn(string $e) => ['ok' => false, 'data' => null, 'error' => $e, 'warnings' => [], 'usage' => null];

    $key = trim((string)($cfg['anthropic']['api_key'] ?? ''));
    if ($key === '')                    return $fail('Anthropic API key not configured.');
    if (!$filePaths)                    return $fail('No file provided.');
    if (count($filePaths) > CREDIT_OCR_MAX_FILES) {
        return $fail('Too many files (max ' . CREDIT_OCR_MAX_FILES . ').');
    }
    $model = trim((string)($cfg['anthropic']['model'] ?? '')) ?: 'claude-sonnet-4-6';

    // Build the content: each file as a document (PDF) or image block, then the instruction.
    $content = [];
    foreach ($filePaths as $path) {
        if (!is_file($path) || !is_readable($path)) return $fail('Cannot read upload: ' . basename($path));
        $kind = credit_ocr_kind($path);
        if ($kind === null) return $fail('Unsupported file type (use a PDF or image): ' . basename($path));
        $bytes = filesize($path);
        $limit = $kind === 'pdf' ? CREDIT_OCR_PDF_BYTES : CREDIT_OCR_IMG_BYTES;
        if ($bytes === false || $bytes > $limit) {
            return $fail('File too large: ' . basename($path));
        }
        $raw = file_get_contents($path);
        if ($raw === false) return $fail('Cannot read upload: ' . basename($path));
        $b64 = base64_encode($raw);
        if ($kind === 'pdf') {
            $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]];
        } else {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $kind, 'data' => $b64]];
        }
    }
    $content[] = ['type' => 'text', 'text' => credit_ocr_prompt()];

    $body = [
        'model'       => $model,
        'max_tokens'  => CREDIT_OCR_MAXTOKENS,
        'tools'       => [[
            'name'         => 'extract_credit_report',
            'description'  => 'Return the structured data extracted from the credit report.',
            'input_schema' => credit_ocr_schema(),
        ]],
        'tool_choice' => ['type' => 'tool', 'name' => 'extract_credit_report'],
        'messages'    => [['role' => 'user', 'content' => $content]],
    ];

    $ch = curl_init(CREDIT_OCR_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CREDIT_OCR_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . CREDIT_OCR_VERSION,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $resp   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);

    if ($resp === false)  return $fail('Network error contacting Anthropic: ' . $cerr);
    $json = json_decode((string)$resp, true);
    if (!is_array($json)) return $fail('Unreadable response from Anthropic (HTTP ' . $status . ').');
    if ($status !== 200) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $status);
        return $fail('Anthropic error: ' . $msg);
    }

    $data = null;
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'extract_credit_report') {
            $data = $block['input'] ?? null;
            break;
        }
    }
    if (!is_array($data)) return $fail('Model did not return structured data.');

    $data     = credit_ocr_normalize($data);
    $warnings = credit_ocr_validate($data);

    // A max_tokens stop means the forced tool_use JSON was truncated — it can still decode
    // to a partial array (e.g. tradelines[] cut short), so warn loudly rather than silently
    // import an incomplete report.
    if (($json['stop_reason'] ?? '') === 'max_tokens') {
        array_unshift($warnings, 'The report was long and the read may be INCOMPLETE (the model hit its output limit) — review every account, or retry.');
    }

    return [
        'ok'       => true,
        'data'     => $data,
        'error'    => null,
        'warnings' => $warnings,
        'usage'    => $json['usage'] ?? null,
    ];
}

/** Coerce numerics → floats, dates → Y-m-d, clamp masks to digits, guarantee arrays. */
function credit_ocr_normalize(array $d): array
{
    $num = function ($v) {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        $clean = str_replace([',', '$', ' '], '', (string)$v);
        return is_numeric($clean) ? (float)$clean : null;
    };
    $date = function ($v): ?string {
        $v = trim((string)($v ?? ''));
        if ($v === '') return null;
        $ts = strtotime($v);
        return $ts === false ? null : date('Y-m-d', $ts);
    };
    // Belt-and-suspenders: if the model ever emits more than 4 digits, keep only the last 4.
    $mask = function ($v): ?string {
        $v = trim((string)($v ?? ''));
        if ($v === '') return null;
        $digits = preg_replace('/\D+/', '', $v);
        if ($digits === '') return mb_substr($v, -4);   // non-numeric mask, keep last 4 chars
        return substr($digits, -4);
    };

    $bureau = strtolower(trim((string)($d['bureau'] ?? '')));
    $d['bureau'] = in_array($bureau, ['equifax', 'experian', 'transunion'], true) ? $bureau : ($bureau !== '' ? 'other' : null);
    $d['pulled_on'] = $date($d['pulled_on'] ?? null);
    $d['consumer_name'] = (function ($v) { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, 120); })($d['consumer_name'] ?? null);
    $d['score'] = (isset($d['score']) && is_numeric($d['score'])) ? (int)$d['score'] : null;
    $d['score_model'] = (function ($v) { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, 32); })($d['score_model'] ?? null);

    foreach (['tradelines', 'inquiries', 'flags'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) $d[$k] = [];
    }

    $types = credit_account_types();
    foreach ($d['tradelines'] as &$t) {
        $t['creditor']     = trim((string)($t['creditor'] ?? ''));
        $t['account_mask'] = $mask($t['account_mask'] ?? null);
        $at = strtolower(trim((string)($t['account_type'] ?? '')));
        $t['account_type'] = in_array($at, $types, true) ? $at : ($at !== '' ? 'other' : null);
        foreach (['balance', 'credit_limit', 'high_balance', 'monthly_payment', 'past_due'] as $m) {
            $t[$m] = $num($t[$m] ?? null);
        }
        foreach (['opened_on', 'closed_on'] as $m) $t[$m] = $date($t[$m] ?? null);
        $t['is_open'] = isset($t['is_open']) ? (($t['is_open'] === null) ? null : (bool)$t['is_open']) : null;
        $t['responsibility'] = (function ($v) { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, 24); })($t['responsibility'] ?? null);
        $t['status'] = (function ($v) { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, 48); })($t['status'] ?? null);
    }
    unset($t);
    // Drop nameless tradelines.
    $d['tradelines'] = array_values(array_filter($d['tradelines'], fn($t) => ($t['creditor'] ?? '') !== ''));

    foreach ($d['inquiries'] as &$q) {
        $q['inquirer']     = trim((string)($q['inquirer'] ?? ''));
        $q['inquiry_date'] = $date($q['inquiry_date'] ?? null);
        $it = strtolower(trim((string)($q['inquiry_type'] ?? '')));
        $q['inquiry_type'] = in_array($it, ['hard', 'soft'], true) ? $it : null;
    }
    unset($q);
    $d['inquiries'] = array_values(array_filter($d['inquiries'], fn($q) => ($q['inquirer'] ?? '') !== ''));

    $kinds = credit_flag_kinds();
    foreach ($d['flags'] as &$f) {
        $kind = strtolower(trim((string)($f['kind'] ?? '')));
        $f['kind']      = in_array($kind, $kinds, true) ? $kind : 'other';
        $f['detail']    = (function ($v) { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, 300); })($f['detail'] ?? null);
        $f['amount']    = $num($f['amount'] ?? null);
        $f['flag_date'] = $date($f['flag_date'] ?? null);
    }
    unset($f);

    return $d;
}

/** Soft sanity checks surfaced to the reviewer; never block, just flag. */
function credit_ocr_validate(array $d): array
{
    $w = [];
    if (empty($d['bureau']))      $w[] = 'No bureau was identified — pick it manually below.';
    if (empty($d['pulled_on']))   $w[] = 'No report date was read — set it below.';
    if (empty($d['tradelines']))  $w[] = 'No accounts (tradelines) were read — check the file or retry.';

    foreach ($d['tradelines'] as $t) {
        $name = $t['creditor'] ?: 'an account';
        $bal = $t['balance'] ?? null; $lim = $t['credit_limit'] ?? null;
        if ($bal !== null && $bal < 0)  $w[] = sprintf('"%s" has a negative balance — check the report.', $name);
        if ($lim !== null && $lim < 0)  $w[] = sprintf('"%s" has a negative credit limit — check the report.', $name);
        if ($bal !== null && $lim !== null && $lim > 0 && $bal > $lim * 1.5) {
            $w[] = sprintf('"%s" balance (%.0f) far exceeds its limit (%.0f) — verify.', $name, $bal, $lim);
        }
    }
    return $w;
}
