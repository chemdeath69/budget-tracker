<?php
declare(strict_types=1);

require_once __DIR__ . '/ingest.php';   // ManualIngestError

/**
 * Webull handler — parses two document layouts (extracted with `pdftotext
 * -layout`) and writes them into the shared schema:
 *
 *   • Monthly Statement → OPEN POSITIONS become holdings/securities; SECURITIES
 *     TRADING ACTIVITY + ACCOUNT ACTIVITY become transactions (tagged
 *     ext_source='webull', ext_period='YYYY-MM'); Total(Combined Assets) becomes
 *     the account balance (only the newest statement sets balance/holdings).
 *   • Consolidated 1099 → yearly box totals into manual_tax_summaries (its own
 *     bucket — never summed into the monthly ledger).
 *
 * Sign convention (app-wide): + = money OUT, − = money IN. Webull reports the
 * cash effect (buys & withdrawals negative, sells/dividends/deposits positive),
 * so app_amount = −1 × cash_effect for every row.
 */

/* ---- small helpers -------------------------------------------------------- */

/** "54,291.43" / "-120.00" / "+3,086.51" → float. */
function wb_f($s): float
{
    return (float) str_replace([',', ' ', '$', '+'], '', (string) $s);
}

/** "05/31/2026" → "2026-05-31" (or '' if unparseable). */
function wb_date(string $mdy): string
{
    if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($mdy), $m)) return '';
    return "{$m[3]}-{$m[1]}-{$m[2]}";
}

/** First capture of $re in $text as float, or null. */
function wb_grab(string $re, string $text): ?float
{
    return preg_match($re, $text, $m) ? wb_f($m[1]) : null;
}

/** Substring between $startNeedle and the first of $endNeedles after it. */
function wb_region(string $text, string $startNeedle, array $endNeedles): string
{
    $start = stripos($text, $startNeedle);
    if ($start === false) return '';
    $start += strlen($startNeedle);
    $end = strlen($text);
    foreach ($endNeedles as $n) {
        $p = stripos($text, $n, $start);
        if ($p !== false && $p < $end) $end = $p;
    }
    return substr($text, $start, $end - $start);
}

/* ---- entry point ---------------------------------------------------------- */

/**
 * Parse a Webull PDF's text. Returns a normalized structure with doc_type,
 * period_key, a display summary, and the parsed payload.
 * @throws ManualIngestError if the document isn't a recognizable Webull doc.
 */
function webull_parse(string $text): array
{
    $isWebull = stripos($text, 'Webull') !== false || stripos($text, 'Apex Clearing') !== false
             || stripos($text, 'APEX CLEARING') !== false;
    // Statement markers are highly specific and never appear on a 1099, so check
    // them FIRST — a monthly statement's legal pages can mention "Form 1099".
    $looksStmt = stripos($text, 'Statement Period:') !== false || stripos($text, 'SUMMARY STATEMENT') !== false
              || stripos($text, 'Total(Combined Assets)') !== false
              || stripos($text, 'Total Equity Holdings') !== false || stripos($text, 'TOTAL PRICED PORTFOLIO') !== false;
    // Tax markers: the actual 1099 form headers (not an incidental "1099" mention).
    $looksTax = stripos($text, 'Form 1099 Composite') !== false || stripos($text, '1099 Composite') !== false
             || stripos($text, 'Form1099DIV') !== false || stripos($text, 'Tax Reporting Statement') !== false;

    if ($looksStmt) {
        return webull_parse_statement($text);
    }
    if ($looksTax) {
        return webull_parse_tax($text);
    }
    if ($isWebull) {
        throw new ManualIngestError('Recognized a Webull PDF but not its type — upload a monthly statement or a 1099.');
    }
    throw new ManualIngestError('This does not look like a Webull statement or 1099.');
}

/* ---- statement ------------------------------------------------------------ */

/**
 * Route to the correct monthly-statement parser. Webull issues two layouts:
 *   • "Summary Statement" (newer; has "Total(Combined Assets)") → _summary
 *   • legacy Apex Clearing "Account Statement" (has "Total Equity Holdings") → _apex
 * Both return the same normalized shape, so webull_ingest_statement() is shared.
 */
function webull_parse_statement(string $text): array
{
    if (stripos($text, 'Total(Combined Assets)') !== false
        || stripos($text, 'SUMMARY STATEMENT') !== false
        || stripos($text, 'Statement Period:') !== false) {
        return webull_parse_statement_summary($text);
    }
    return webull_parse_statement_apex($text);
}

/** Webull "Summary Statement" layout (Apex-cleared; e.g. 2026-05.pdf). */
function webull_parse_statement_summary(string $text): array
{
    $lines = preg_split('/\r?\n/', $text) ?: [];

    // Header fields.
    $acctRef = preg_match('/Account\s*Number:\s*([0-9A-Z]+)/i', $text, $m) ? $m[1] : null;
    $acctType = preg_match('/Account\s*Type:\s*([A-Za-z]+)/i', $text, $m) ? strtoupper($m[1]) : null;
    $pStart = $pEnd = null;
    if (preg_match('#Statement Period:\s*(\d{2}/\d{2}/\d{4})\s*-\s*(\d{2}/\d{2}/\d{4})#', $text, $m)) {
        $pStart = wb_date($m[1]);
        $pEnd   = wb_date($m[2]);
    }
    if (!$pEnd) {
        throw new ManualIngestError('Could not find the statement period in that PDF.');
    }
    $periodKey = substr($pEnd, 0, 7);   // YYYY-MM

    // Total(Combined Assets): row = [prior, long, short, CURRENT, change]; take CURRENT.
    $totalValue = null;
    foreach ($lines as $ln) {
        if (stripos($ln, 'Total(Combined Assets)') !== false) {
            preg_match_all('/-?[\d,]+\.\d{2}/', $ln, $mm);
            $nums = array_map('wb_f', $mm[0]);
            if (count($nums) >= 4) { $totalValue = $nums[3]; }
            elseif ($nums)        { $totalValue = end($nums); }
            break;
        }
    }
    // Layout drift can leave total_value unmatched; refuse the ingest rather than
    // silently NULL the account's balance out of net worth (like a missing period).
    if ($totalValue === null) {
        throw new ManualIngestError('Statement layout not recognized — total value not found in that PDF.');
    }
    // Closing cash from CASH BALANCE DETAIL ("Closing  <sipc>  <fdic>  <total>").
    $cash = null;
    foreach ($lines as $ln) {
        if (preg_match('/^\s*Closing\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s*$/', $ln, $m)) {
            $cash = wb_f($m[3]);
            break;
        }
    }

    // --- Trades (SECURITIES TRADING ACTIVITY) ---
    $tradeRegion = wb_region($text, 'SECURITIES TRADING ACTIVITY', ['OPEN POSITIONS', 'ACCOUNT ACTIVITY', 'NOTES']);
    $tLines = preg_split('/\r?\n/', $tradeRegion) ?: [];
    $trades = [];
    $sym = $cusip = null;
    $dataRe = '#^\s*(\d{2}/\d{2}/\d{4})\s+(\d{2}/\d{2}/\d{4})\s+([BS])\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)\s+(-?[\d,]+\.\d+)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)\s+(-?[\d,]+\.\d+)#';
    $symRe = '#^\s*([A-Z][A-Z0-9./]{0,9})\s*-\s*([0-9A-Z]{9})\s*$#';
    for ($i = 0, $n = count($tLines); $i < $n; $i++) {
        $ln = $tLines[$i];
        if (preg_match($symRe, $ln, $m)) { $sym = $m[1]; $cusip = $m[2]; continue; }
        if (preg_match($dataRe, $ln, $m)) {
            $name = null;
            if (isset($tLines[$i + 1])) {
                $nxt = trim($tLines[$i + 1]);
                if ($nxt !== '' && !preg_match($symRe, $tLines[$i + 1]) && !preg_match($dataRe, $tLines[$i + 1])) {
                    $name = $nxt;
                }
            }
            $trades[] = [
                'symbol' => $sym, 'cusip' => $cusip, 'name' => $name,
                'trade_date' => wb_date($m[1]), 'settle_date' => wb_date($m[2]),
                'side' => $m[3], 'qty' => wb_f($m[4]), 'price' => wb_f($m[5]),
                'gross' => wb_f($m[6]), 'commission' => wb_f($m[7]), 'fee' => wb_f($m[8]),
                'net' => wb_f($m[9]),
            ];
        }
    }

    // --- Open positions (holdings) ---
    $posRegion = wb_region($text, 'OPEN POSITIONS', ['ACCOUNT ACTIVITY', 'NOTES', 'KEY DEFINITIONS']);
    $positions = [];
    foreach (preg_split('/\r?\n/', $posRegion) ?: [] as $ln) {
        if (preg_match('#^\s*([A-Z][A-Z0-9./]{0,9})\s+([0-9A-Z]{9})\s+([\d,]+\.\d+)\s+(\d+)\s+([\d,]+\.\d+)\s+([\d,]+\.\d+)(?:\s+[NY])?\s*$#', $ln, $m)) {
            $positions[] = [
                'symbol' => $m[1], 'cusip' => $m[2], 'qty' => wb_f($m[3]),
                'multiplier' => (int) $m[4], 'close_price' => wb_f($m[5]), 'amount' => wb_f($m[6]),
            ];
        }
    }

    // --- Account activity (dividends / deposits / withdrawals / interest / fees) ---
    $actRegion = wb_region($text, 'ACCOUNT ACTIVITY', ['NOTES', 'KEY DEFINITIONS', 'IMPORTANT INFORMATION']);
    $activity = [];
    foreach (preg_split('/\r?\n/', $actRegion) ?: [] as $ln) {
        // Amount is the first 2-decimal number after the account-type token; a
        // free-text description may follow it on the same line (deposits/withdrawals).
        if (preg_match('#^\s*(\d{2}/\d{2}/\d{4})\s+([A-Za-z]+)\s+([A-Z]{3})\s+([A-Za-z]+)\s+(.*?)(-?[\d,]+\.\d{2})(?:\s|$)#', $ln, $m)) {
            $mid = $m[5]; $aSym = null;
            if (preg_match('/([A-Z][A-Z0-9.]{0,9})\s+([0-9A-Z]{9})/', $mid, $mm)) $aSym = $mm[1];
            $activity[] = [
                'date' => wb_date($m[1]), 'kind' => $m[2], 'symbol' => $aSym, 'amount' => wb_f($m[6]),
            ];
        }
    }

    $summary = [
        'kind' => 'statement', 'period' => $periodKey, 'account_ref' => $acctRef,
        'account_type' => $acctType, 'total_value' => $totalValue, 'cash' => $cash,
        'positions' => count($positions), 'trades' => count($trades), 'activity' => count($activity),
    ];

    return [
        'doc_type'   => 'statement',
        'period_key' => $periodKey,
        'account_ref' => $acctRef,
        'summary'    => $summary,
        'statement'  => [
            'period_start' => $pStart, 'period_end' => $pEnd,
            'account_type' => $acctType, 'total_value' => $totalValue, 'cash' => $cash,
            'positions' => $positions, 'trades' => $trades, 'activity' => $activity,
        ],
    ];
}

/* ---- legacy Apex Clearing statement --------------------------------------- */

/** "January 31, 2024" → "2024-01-31" (or '' if unparseable). */
function wb_long_date(string $s): string
{
    $d = DateTime::createFromFormat('F j, Y', trim($s));
    return $d ? $d->format('Y-m-d') : '';
}

/** "01/31/24" → "2024-01-31" (2-digit year). */
function wb_date2(string $mdy): string
{
    if (!preg_match('#^(\d{2})/(\d{2})/(\d{2})$#', trim($mdy), $m)) return '';
    return '20' . $m[3] . '-' . $m[1] . '-' . $m[2];
}

/** Normalize a security description to a match key (links trades/dividends to a holding's symbol). */
function wb_desc_key(string $d): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($d, 0, 18)));
}

/** Decide credit (money in) vs debit (money out) for an Apex funds row. */
function wb_apex_is_credit(string $type, string $desc, int $pos, ?int $debitCol, ?int $creditCol): bool
{
    if (stripos($desc, 'DEPOSIT') !== false || $type === 'DIVIDEND') return true;
    if (stripos($desc, 'WITHDRAW') !== false || $type === 'FEE') return false;
    if ($debitCol !== null && $creditCol !== null) {      // fall back to the column the amount sits in
        return $pos >= (($debitCol + $creditCol) / 2);
    }
    return $type === 'DIVIDEND';
}

/** Map an Apex funds row → [activity kind, symbol|null]. */
function wb_apex_fund_kind(string $type, string $desc, array $descMap): array
{
    if ($type === 'DIVIDEND') return ['dividend', $descMap[wb_desc_key($desc)] ?? null];
    if ($type === 'INTEREST') return ['interest', null];
    if ($type === 'FEE')      return ['fee', null];
    if (in_array($type, ['ACH', 'WIRE', 'CHECK', 'JOURNAL'], true)) {
        return [stripos($desc, 'WITHDRAW') !== false ? 'withdraw' : 'deposit', null];
    }
    return [strtolower($type), null];
}

/**
 * Legacy Apex Clearing "Account Statement" layout (e.g. 5JB16871.pdf). Emits the
 * SAME normalized shape as the summary parser. Pending-settlement trades (rows
 * with both a trade AND a settlement date) are intentionally skipped — they
 * re-appear as settled BUY/SELL rows in the following month's statement, so
 * including them here would double-count across statements.
 */
function webull_parse_statement_apex(string $text): array
{
    $lines = preg_split('/\r?\n/', $text) ?: [];

    // Period: "January 1, 2024 - January 31, 2024".
    $pStart = $pEnd = null;
    if (preg_match('/([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*([A-Z][a-z]+ \d{1,2}, \d{4})/', $text, $m)) {
        $pStart = wb_long_date($m[1]);
        $pEnd   = wb_long_date($m[2]);
    }
    if (!$pEnd) throw new ManualIngestError('Could not find the statement period in that Apex statement.');
    $periodKey = substr($pEnd, 0, 7);

    $acctRef = preg_match('/ACCOUNT NUMBER\s+([0-9A-Z][0-9A-Z\-]+)/', $text, $m) ? trim($m[1]) : null;
    $acctType = (stripos($text, 'Cash account') !== false && stripos($text, 'Margin account') === false)
        ? 'CASH' : 'MARGIN';

    // Total account value = closing "Total Equity Holdings".
    $totalValue = null;
    if (preg_match('/Total Equity Holdings\s+\$?([\d,]+\.\d{2})\s+\$?([\d,]+\.\d{2})/', $text, $m)) {
        $totalValue = wb_f($m[2]);
    } elseif (preg_match('/TOTAL PRICED PORTFOLIO\s+\$?([\d,]+\.\d{2})/', $text, $m)) {
        $totalValue = wb_f($m[1]);
    }
    // Refuse rather than silently NULL the balance out of net worth (see summary parser).
    if ($totalValue === null) {
        throw new ManualIngestError('Statement layout not recognized — total value not found in that Apex statement.');
    }
    // Cash = closing "NET ACCOUNT BALANCE" (fallback "Total Cash (Net Portfolio Balance)").
    $cash = null;
    if (preg_match('/NET ACCOUNT BALANCE\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})/', $text, $m)) {
        $cash = wb_f($m[2]);
    } elseif (preg_match('/Total Cash \(Net Portfolio Balance\)\s+\$?([\d,]+\.\d{2})/', $text, $m)) {
        $cash = wb_f($m[1]);
    }

    // --- Holdings (EQUITIES/OPTIONS table; symbol only, no inline CUSIP) ---
    $positions = []; $descMap = []; $inHold = false;
    $rowRe = '/^\s*(.+?)\s{2,}([A-Z][A-Z0-9.]{0,9})\s+([A-Z])\s+([\d,]+\.\d+)\s+\$?([\d,]+\.\d+)\s+\$?([\d,]+\.\d+)\b/';
    foreach ($lines as $ln) {
        if (stripos($ln, 'QUANTITY') !== false && stripos($ln, 'PORTFOLIO') !== false) { $inHold = true; continue; }
        if (stripos($ln, 'Total Equities') !== false) { $inHold = false; }
        if (!$inHold) continue;
        if (preg_match($rowRe, $ln, $m)) {
            $desc = trim($m[1]); $sym = $m[2];
            $positions[] = [
                'symbol' => $sym, 'cusip' => 'SYM' . $sym, 'name' => $desc,
                'qty' => wb_f($m[4]), 'close_price' => wb_f($m[5]), 'amount' => wb_f($m[6]),
            ];
            $descMap[wb_desc_key($desc)] = $sym;
        }
    }

    // --- Transactions (single-date rows only) ---
    $trades = []; $activity = [];
    $debitCol = $creditCol = null;
    $twoDateRe = '#^\s*(?:BOUGHT|SOLD)\s+\d{2}/\d{2}/\d{2}\s+\d{2}/\d{2}/\d{2}\s#';
    $tradeRe   = '#^\s*(BOUGHT|SOLD)\s+(\d{2}/\d{2}/\d{2})\s+([A-Z])\s+(.+?)\s+([\d,]+\.\d+)\s+\$?([\d,]+\.\d+)\s+\$?([\d,]+\.\d{2})\s*$#';
    $fundRe    = '#^\s*(ACH|DIVIDEND|INTEREST|FEE|JOURNAL|CHECK|WIRE)\s+(\d{2}/\d{2}/\d{2})\s+([A-Z])\s+(.+?)\s+\$?(-?[\d,]+\.\d{2})\s*$#';

    foreach ($lines as $ln) {
        if (stripos($ln, 'DEBIT') !== false && stripos($ln, 'CREDIT') !== false) {
            $debitCol = stripos($ln, 'DEBIT'); $creditCol = stripos($ln, 'CREDIT'); continue;
        }
        if (preg_match($twoDateRe, $ln)) continue;   // pending settlement → skip (avoids cross-month dup)

        if (preg_match($tradeRe, $ln, $m)) {
            $type = $m[1]; $desc = trim($m[4]); $val = wb_f($m[7]);
            $net = ($type === 'SOLD') ? $val : -$val;   // cash effect: buy out (−), sell in (+)
            $trades[] = [
                'symbol' => $descMap[wb_desc_key($desc)] ?? null, 'cusip' => null, 'name' => $desc,
                'trade_date' => wb_date2($m[2]), 'side' => ($type === 'BOUGHT' ? 'B' : 'S'),
                'qty' => wb_f($m[5]), 'price' => wb_f($m[6]), 'net' => $net,
            ];
            continue;
        }
        if (preg_match($fundRe, $ln, $m, PREG_OFFSET_CAPTURE)) {
            $type = strtoupper($m[1][0]); $desc = trim($m[4][0]);
            $val = wb_f($m[5][0]); $pos = (int) $m[5][1];
            $isCredit = wb_apex_is_credit($type, $desc, $pos, $debitCol, $creditCol);
            [$kind, $sym] = wb_apex_fund_kind($type, $desc, $descMap);
            $activity[] = [
                'date' => wb_date2($m[2][0]), 'kind' => $kind, 'symbol' => $sym,
                'amount' => $isCredit ? $val : -$val,    // cash effect (positive = in)
            ];
            continue;
        }
    }

    $summary = [
        'kind' => 'statement', 'period' => $periodKey, 'account_ref' => $acctRef,
        'account_type' => $acctType, 'total_value' => $totalValue, 'cash' => $cash,
        'positions' => count($positions), 'trades' => count($trades), 'activity' => count($activity),
    ];
    return [
        'doc_type'   => 'statement', 'period_key' => $periodKey, 'account_ref' => $acctRef,
        'summary'    => $summary,
        'statement'  => [
            'period_start' => $pStart, 'period_end' => $pEnd,
            'account_type' => $acctType, 'total_value' => $totalValue, 'cash' => $cash,
            'positions' => $positions, 'trades' => $trades, 'activity' => $activity,
        ],
    ];
}

/* ---- 1099 tax ------------------------------------------------------------- */

function webull_parse_tax(string $text): array
{
    $year = null;
    if (preg_match('/Form 1099 Composite\s+(\d{4})/', $text, $m)) $year = $m[1];
    elseif (preg_match('/(\d{4})\s+Form\s*1099DIV/i', $text, $m)) $year = $m[1];
    elseif (preg_match('/Tax\s+Summary\s+(\d{4})/i', $text, $m)) $year = $m[1];
    if (!$year) {
        throw new ManualIngestError('Could not determine the tax year from that 1099.');
    }
    $acctRef = preg_match('/Account\s+([0-9A-Z]{6,})/', $text, $m) ? $m[1] : null;

    // Sale-proceeds totals: sum the Short/Long/Undetermined "Total" rows.
    $proceeds = $cost = $netGain = null;
    foreach (preg_split('/\r?\n/', $text) ?: [] as $ln) {
        if (preg_match('/Total\s+(?:Short-term|Long-term|Undetermined-term)\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})/', $ln, $m)) {
            $proceeds = (float)($proceeds ?? 0) + wb_f($m[1]);
            $cost     = (float)($cost ?? 0) + wb_f($m[2]);
            $netGain  = (float)($netGain ?? 0) + wb_f($m[5]);
        }
    }

    // Federal tax withheld total = sum of the per-form summary lines if present.
    $fedW = null;
    foreach (['1099-DIV', '1099-INT', '1099-B', '1099-MISC', '1099-OID'] as $form) {
        if (preg_match('/' . preg_quote($form, '/') . '\s+Total[^:]*:\s*(-?[\d,]+\.\d{2})/', $text, $m)) {
            $fedW = (float)($fedW ?? 0) + wb_f($m[1]);
        }
    }
    if ($fedW === null) {
        $fedW = wb_grab('/4-\s*Federal Income Tax Withheld.*?(-?[\d,]+\.\d{2})/', $text);
    }

    $boxes = [
        'ordinary_dividends'         => wb_grab('/1a-\s*Total Ordinary Dividends.*?(-?[\d,]+\.\d{2})/', $text),
        'qualified_dividends'        => wb_grab('/1b-\s*Qualified Dividends.*?(-?[\d,]+\.\d{2})/', $text),
        'capital_gain_distributions' => wb_grab('/2a-\s*Total Capital Gain Distributions.*?(-?[\d,]+\.\d{2})/', $text),
        'nondividend_distributions'  => wb_grab('/(?<!\d)3-\s*Nondividend Distributions.*?(-?[\d,]+\.\d{2})/', $text),
        'section_199a_dividends'     => wb_grab('/5-\s*Section 199A Dividends.*?(-?[\d,]+\.\d{2})/', $text),
        'interest_income'            => wb_grab('/(?<![\da-z])1-\s*Interest Income.*?(-?[\d,]+\.\d{2})/', $text),
        'federal_tax_withheld'       => $fedW,
        'foreign_tax_paid'           => wb_grab('/7-\s*Foreign Tax Paid.*?(-?[\d,]+\.\d{2})/', $text),
        'proceeds'                   => $proceeds,
        'cost_basis'                 => $cost,
        'net_gain_loss'              => $netGain,
    ];

    $summary = [
        'kind' => 'tax', 'year' => $year, 'account_ref' => $acctRef,
        'ordinary_dividends' => $boxes['ordinary_dividends'],
        'qualified_dividends' => $boxes['qualified_dividends'],
        'interest_income' => $boxes['interest_income'],
        'proceeds' => $boxes['proceeds'], 'net_gain_loss' => $boxes['net_gain_loss'],
    ];

    return [
        'doc_type'   => 'tax',
        'period_key' => $year,
        'account_ref' => $acctRef,
        'summary'    => $summary,
        'tax'        => ['tax_year' => $year] + $boxes,
    ];
}

/* ---- ingest (write to DB) ------------------------------------------------- */

function webull_ingest(PDO $pdo, array $account, array $parsed, int $docId): array
{
    return $parsed['doc_type'] === 'tax'
        ? webull_ingest_tax($pdo, (string)$account['account_id'], $parsed, $docId)
        : webull_ingest_statement($pdo, (string)$account['account_id'], $parsed, $docId);
}

/** Map a Webull activity kind → [display name, pseudo-category]. */
function webull_activity_meta(string $kind, ?string $symbol): array
{
    $k = strtolower($kind);
    if ($k === 'dividend')  return [trim(($symbol ? $symbol . ' ' : '') . 'Dividend'), 'INCOME_DIVIDENDS'];
    if ($k === 'interest')  return ['Interest', 'INCOME_INTEREST'];
    if ($k === 'deposit')   return ['Deposit', 'TRANSFER_IN'];
    if ($k === 'withdraw' || $k === 'withdrawal') return ['Withdrawal', 'TRANSFER_OUT'];
    if ($k === 'transfer')  return ['Transfer', 'TRANSFER_OUT'];
    if ($k === 'fee')       return ['Fee', 'BANK_FEES'];
    if ($k === 'tax')       return ['Tax', 'BANK_FEES'];
    return [ucfirst($kind), 'INVESTMENT'];
}

function webull_ingest_statement(PDO $pdo, string $acctId, array $parsed, int $docId): array
{
    $period = (string)$parsed['period_key'];
    $st     = $parsed['statement'];
    $endDt  = ($st['period_end'] ?? '') !== '' ? $st['period_end'] . ' 23:59:59' : null;

    // Insert reused for both trades and activity. Preserves a manual
    // category_override across re-uploads (not in the UPDATE list).
    $ins = $pdo->prepare(
        'INSERT INTO transactions
            (transaction_id, account_id, amount, iso_currency_code, date,
             merchant_name, name, pfc_primary, payment_channel, ext_source, ext_period)
         VALUES (:tid,:acct,:amt,"USD",:date,:merch,:name,:pfc,"other","webull",:period)
         ON DUPLICATE KEY UPDATE
            amount=VALUES(amount), date=VALUES(date), merchant_name=VALUES(merchant_name),
            name=VALUES(name), pfc_primary=VALUES(pfc_primary),
            ext_source=VALUES(ext_source), ext_period=VALUES(ext_period)'
    );

    $seen = [];
    foreach ($st['trades'] as $ti => $t) {
        if (($t['trade_date'] ?? '') === '') continue;
        $tid = 'wb' . substr(sha1($acctId . '|t|' . $t['trade_date'] . '|' . $t['symbol'] . '|' . $t['side'] . '|' . $t['qty'] . '|' . $t['net'] . '|' . $ti), 0, 40);
        $label = ($t['side'] === 'B' ? 'Buy ' : 'Sell ') . ($t['symbol'] ?? '');
        $merch = trim((string)($t['name'] ?: $t['symbol'] ?? ''));
        $ins->execute([
            ':tid' => $tid, ':acct' => $acctId, ':amt' => -1 * (float)$t['net'],
            ':date' => $t['trade_date'], ':merch' => ($merch !== '' ? $merch : null),
            ':name' => $label, ':pfc' => 'INVESTMENT', ':period' => $period,
        ]);
        $seen[] = $tid;
    }
    foreach ($st['activity'] as $idx => $a) {
        if (($a['date'] ?? '') === '') continue;
        [$label, $pfc] = webull_activity_meta((string)$a['kind'], $a['symbol'] ?? null);
        $tid = 'wb' . substr(sha1($acctId . '|a|' . $a['date'] . '|' . $a['kind'] . '|' . ($a['symbol'] ?? '') . '|' . $a['amount'] . '|' . $idx), 0, 40);
        $ins->execute([
            ':tid' => $tid, ':acct' => $acctId, ':amt' => -1 * (float)$a['amount'],
            ':date' => $a['date'], ':merch' => $label, ':name' => $label, ':pfc' => $pfc,
            ':period' => $period,
        ]);
        $seen[] = $tid;
    }

    // Drop any rows previously ingested for THIS bucket that are gone now
    // (handles a corrected statement that removed a row).
    if ($seen) {
        $ph = implode(',', array_fill(0, count($seen), '?'));
        $del = $pdo->prepare("DELETE FROM transactions
            WHERE account_id = ? AND ext_source = 'webull' AND ext_period = ?
              AND transaction_id NOT IN ($ph)");
        $del->execute(array_merge([$acctId, $period], $seen));
    } else {
        $pdo->prepare("DELETE FROM transactions
            WHERE account_id = ? AND ext_source = 'webull' AND ext_period = ?")
            ->execute([$acctId, $period]);
    }

    // Persist this statement's buy/sell lots (qty + price) for cost-basis derivation.
    webull_write_invtx($pdo, $acctId, $period, $st['trades']);

    // Holdings + balance: only the NEWEST statement defines current positions.
    $maxPeriod = $pdo->prepare("SELECT MAX(period_key) FROM manual_documents
                                WHERE account_id = ? AND doc_type = 'statement'");
    $maxPeriod->execute([$acctId]);
    $max = $maxPeriod->fetchColumn();
    $isNewest = ($max === false || $max === null || $period >= (string)$max);

    if ($isNewest) {
        $secUp = $pdo->prepare(
            'INSERT INTO securities (security_id, ticker_symbol, name, type, close_price, close_price_date, iso_currency_code)
             VALUES (:id,:tic,:name,:type,:price,:pdate,"USD")
             ON DUPLICATE KEY UPDATE ticker_symbol=VALUES(ticker_symbol),
                name=COALESCE(VALUES(name), name), type=VALUES(type),
                close_price=VALUES(close_price), close_price_date=VALUES(close_price_date)'
        );
        $holdUp = $pdo->prepare(
            'INSERT INTO holdings (account_id, security_id, quantity, institution_price, institution_value, iso_currency_code)
             VALUES (:acct,:sec,:qty,:price,:val,"USD")
             ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),
                institution_price=VALUES(institution_price), institution_value=VALUES(institution_value)'
        );
        // Names seen in this statement's trades (positions carry no name).
        $names = [];
        foreach ($st['trades'] as $t) {
            if (!empty($t['cusip']) && !empty($t['name'])) $names[$t['cusip']] = $t['name'];
        }
        $keepSec = [];
        foreach ($st['positions'] as $p) {
            $secId = 'wb_' . $p['cusip'];
            // Prefer a name carried on the position (Apex statements include it),
            // else a name learned from this statement's trades (Summary format).
            $secName = ($p['name'] ?? null) ?: ($names[$p['cusip']] ?? null);
            $secUp->execute([
                ':id' => $secId, ':tic' => $p['symbol'], ':name' => $secName,
                ':type' => 'equity', ':price' => $p['close_price'],
                ':pdate' => $st['period_end'] ?: null,
            ]);
            $holdUp->execute([
                ':acct' => $acctId, ':sec' => $secId, ':qty' => $p['qty'],
                ':price' => $p['close_price'], ':val' => $p['amount'],
            ]);
            $keepSec[] = $secId;
        }
        // Remove holdings no longer present (sold-out positions).
        if ($keepSec) {
            $ph = implode(',', array_fill(0, count($keepSec), '?'));
            $pdo->prepare("DELETE FROM holdings WHERE account_id = ? AND security_id NOT IN ($ph)")
                ->execute(array_merge([$acctId], $keepSec));
        } else {
            $pdo->prepare('DELETE FROM holdings WHERE account_id = ?')->execute([$acctId]);
        }

        // Derive per-position cost basis (average cost) from all lots → holdings.cost_basis.
        webull_derive_cost_basis($pdo, $acctId);

        // Account balance/value from the statement.
        $mask = $parsed['account_ref'] ? substr((string)$parsed['account_ref'], -4) : null;
        $official = trim('Webull ' . ((string)($st['account_type'] ?? '')) . ' ' . (string)($parsed['account_ref'] ?? ''));
        $pdo->prepare(
            'UPDATE accounts SET
                balance_current = :cur, balance_available = :avail,
                official_name = :oname, mask = COALESCE(:mask, mask),
                last_updated_datetime = :upd
             WHERE account_id = :acct'
        )->execute([
            ':cur' => $st['total_value'], ':avail' => $st['cash'],
            ':oname' => $official, ':mask' => $mask, ':upd' => $endDt, ':acct' => $acctId,
        ]);
    }

    return [
        'kind' => 'statement', 'period' => $period,
        'total_value' => $st['total_value'], 'cash' => $st['cash'],
        'positions' => count($st['positions']), 'trades' => count($st['trades']),
        'activity' => count($st['activity']), 'applied_balance' => $isNewest,
    ];
}

/**
 * Persist a statement's trades as buy/sell lots (qty + price + fees) in
 * investment_transactions, scoped to the doc's monthly bucket so a re-uploaded
 * statement replaces exactly its own rows (mirrors the transactions dedup).
 * security_id = 'wb_' . cusip; trades without a cusip (legacy Apex layout) are
 * skipped — those positions just keep "cost basis pending".
 */
function webull_write_invtx(PDO $pdo, string $acctId, string $period, array $trades): void
{
    $ins = $pdo->prepare(
        'INSERT INTO investment_transactions
            (inv_tx_id, account_id, security_id, side, quantity, price, fees, amount, trade_date, ext_source, ext_period)
         VALUES (:id,:acct,:sec,:side,:qty,:price,:fees,:amt,:date,"webull",:period)
         ON DUPLICATE KEY UPDATE
            quantity=VALUES(quantity), price=VALUES(price), fees=VALUES(fees),
            amount=VALUES(amount), trade_date=VALUES(trade_date), ext_period=VALUES(ext_period)'
    );
    $seen = [];
    foreach ($trades as $ti => $t) {
        if (($t['trade_date'] ?? '') === '' || empty($t['cusip'])) continue;
        $fees = (float)($t['commission'] ?? 0) + (float)($t['fee'] ?? 0);
        $id = 'wbi' . substr(sha1($acctId . '|' . $t['cusip'] . '|' . $t['trade_date'] . '|' . $t['side'] . '|' . $t['qty'] . '|' . $t['price'] . '|' . $ti), 0, 40);
        $ins->execute([
            ':id' => $id, ':acct' => $acctId, ':sec' => 'wb_' . $t['cusip'],
            ':side' => ($t['side'] === 'B') ? 'buy' : 'sell',
            ':qty' => (float)$t['qty'], ':price' => (float)$t['price'], ':fees' => $fees,
            ':amt' => -1 * (float)$t['net'], ':date' => $t['trade_date'], ':period' => $period,
        ]);
        $seen[] = $id;
    }
    if ($seen) {
        $ph = implode(',', array_fill(0, count($seen), '?'));
        $pdo->prepare("DELETE FROM investment_transactions
            WHERE account_id = ? AND ext_source = 'webull' AND ext_period = ?
              AND inv_tx_id NOT IN ($ph)")->execute(array_merge([$acctId, $period], $seen));
    } else {
        $pdo->prepare("DELETE FROM investment_transactions
            WHERE account_id = ? AND ext_source = 'webull' AND ext_period = ?")
            ->execute([$acctId, $period]);
    }
}

/**
 * Derive per-position cost basis (average cost) from all stored lots and write it
 * to holdings.cost_basis for the account's CURRENT positions. Buys add qty×price
 * + fees; sells reduce the running cost at the current average; a held security
 * with no lot history is left NULL ("cost basis pending"). The average is scaled
 * to the shares actually held now, so missing statements degrade gracefully
 * rather than producing a wildly wrong figure. Returns # holdings priced.
 */
function webull_derive_cost_basis(PDO $pdo, string $acctId): int
{
    $h = $pdo->prepare('SELECT security_id, quantity FROM holdings WHERE account_id = ?');
    $h->execute([$acctId]);
    $held = [];
    foreach ($h->fetchAll() as $r) {
        if ($r['quantity'] !== null) $held[$r['security_id']] = (float)$r['quantity'];
    }
    if (!$held) return 0;

    $in = implode(',', array_fill(0, count($held), '?'));
    $st = $pdo->prepare(
        "SELECT security_id, side, quantity, price, fees FROM investment_transactions
         WHERE account_id = ? AND security_id IN ($in)
         ORDER BY trade_date ASC, inv_tx_id ASC"
    );
    $st->execute(array_merge([$acctId], array_keys($held)));

    $shares = []; $cost = [];
    foreach ($st->fetchAll() as $r) {
        $sid = $r['security_id'];
        $q = (float)$r['quantity']; $p = (float)$r['price']; $f = (float)$r['fees'];
        $shares[$sid] = $shares[$sid] ?? 0.0; $cost[$sid] = $cost[$sid] ?? 0.0;
        if ($r['side'] === 'buy') {
            $shares[$sid] += $q; $cost[$sid] += $q * $p + $f;
        } else {
            $avg = $shares[$sid] > 0 ? $cost[$sid] / $shares[$sid] : 0.0;
            $shares[$sid] -= $q; $cost[$sid] -= $avg * $q;
            if ($shares[$sid] < 1e-9) { $shares[$sid] = 0.0; $cost[$sid] = 0.0; }
        }
    }

    $upd = $pdo->prepare('UPDATE holdings SET cost_basis = :cb WHERE account_id = :a AND security_id = :s');
    $n = 0;
    foreach ($held as $sid => $curQty) {
        if (($shares[$sid] ?? 0) <= 0) continue;          // no usable lot history
        $avg = $cost[$sid] / $shares[$sid];
        $upd->execute([':cb' => round($avg * $curQty, 4), ':a' => $acctId, ':s' => $sid]);
        $n++;
    }
    return $n;
}

/**
 * One-off backfill: rebuild investment_transactions for an account by re-parsing
 * its stored statement PDFs, then derive cost basis. Idempotent (per-bucket
 * upsert + dedup). Returns a report.
 */
function webull_backfill_account(PDO $pdo, string $acctId): array
{
    require_once __DIR__ . '/pdftext.php';
    $docs = $pdo->prepare(
        "SELECT period_key, stored_path FROM manual_documents
         WHERE account_id = ? AND doc_type = 'statement' AND stored_path IS NOT NULL
         ORDER BY period_key ASC"
    );
    $docs->execute([$acctId]);
    $report = ['statements' => 0, 'lots' => 0, 'errors' => []];
    foreach ($docs->fetchAll() as $doc) {
        try {
            if (!is_file($doc['stored_path'])) { $report['errors'][$doc['period_key']] = 'file missing'; continue; }
            $parsed = webull_parse(pdf_extract_text($doc['stored_path']));
            if (($parsed['doc_type'] ?? '') !== 'statement') continue;
            $trades = $parsed['statement']['trades'] ?? [];
            webull_write_invtx($pdo, $acctId, (string)$parsed['period_key'], $trades);
            $report['statements']++; $report['lots'] += count($trades);
        } catch (\Throwable $e) {
            $report['errors'][$doc['period_key']] = $e->getMessage();
        }
    }
    $report['holdings_priced'] = webull_derive_cost_basis($pdo, $acctId);
    return $report;
}

function webull_ingest_tax(PDO $pdo, string $acctId, array $parsed, int $docId): array
{
    $t = $parsed['tax'];
    $pdo->prepare(
        'INSERT INTO manual_tax_summaries
            (account_id, tax_year, ordinary_dividends, qualified_dividends,
             capital_gain_distributions, nondividend_distributions, section_199a_dividends,
             interest_income, federal_tax_withheld, foreign_tax_paid,
             proceeds, cost_basis, net_gain_loss, document_id, raw)
         VALUES (:acct,:yr,:od,:qd,:cg,:nd,:s199,:int,:fed,:ftp,:proc,:cost,:ng,:doc,:raw)
         ON DUPLICATE KEY UPDATE
            ordinary_dividends=VALUES(ordinary_dividends), qualified_dividends=VALUES(qualified_dividends),
            capital_gain_distributions=VALUES(capital_gain_distributions),
            nondividend_distributions=VALUES(nondividend_distributions),
            section_199a_dividends=VALUES(section_199a_dividends),
            interest_income=VALUES(interest_income), federal_tax_withheld=VALUES(federal_tax_withheld),
            foreign_tax_paid=VALUES(foreign_tax_paid), proceeds=VALUES(proceeds),
            cost_basis=VALUES(cost_basis), net_gain_loss=VALUES(net_gain_loss),
            document_id=VALUES(document_id), raw=VALUES(raw)'
    )->execute([
        ':acct' => $acctId, ':yr' => $t['tax_year'],
        ':od' => $t['ordinary_dividends'], ':qd' => $t['qualified_dividends'],
        ':cg' => $t['capital_gain_distributions'], ':nd' => $t['nondividend_distributions'],
        ':s199' => $t['section_199a_dividends'], ':int' => $t['interest_income'],
        ':fed' => $t['federal_tax_withheld'], ':ftp' => $t['foreign_tax_paid'],
        ':proc' => $t['proceeds'], ':cost' => $t['cost_basis'], ':ng' => $t['net_gain_loss'],
        ':doc' => $docId, ':raw' => json_encode($t),
    ]);

    return [
        'kind' => 'tax', 'year' => $t['tax_year'],
        'ordinary_dividends' => $t['ordinary_dividends'],
        'qualified_dividends' => $t['qualified_dividends'],
        'interest_income' => $t['interest_income'],
        'proceeds' => $t['proceeds'], 'net_gain_loss' => $t['net_gain_loss'],
    ];
}
