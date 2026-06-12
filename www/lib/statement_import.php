<?php
/**
 * statement_import.php — persist a reviewed manual-401(k) statement's HOLDINGS + ACTIVITY
 * (Session 55, TODO #25). The balance / contribution figures still go through the existing
 * retirement_statements path (retirement_statement.php); this file adds the per-fund
 * holdings and the dividend/capital-gain/fee activity lines, reusing the SAME tables the
 * Plaid feed uses so they light up the existing UI:
 *
 *   • securities                — one manual "fund" identity per holding (id = 'man_'+hash).
 *   • holdings                  — current per-fund snapshot (qty / price / value).
 *   • investment_transactions   — activity rows tagged ext_source='manual_ret'
 *                                 (q_investment_activity unions these alongside Plaid).
 *
 * Design notes / invariants:
 *   • ALL placeholders positional (?) → HY093-safe on this host (native prepares).
 *   • MUST run inside the caller's transaction (alongside the retirement_statements write),
 *     so a failure rolls the whole statement back as a unit.
 *   • Idempotent per (account, period): re-importing a period DELETEs then re-INSERTs that
 *     period's manual_ret activity. holdings (a current-snapshot table) are refreshed ONLY
 *     when the imported statement is the LATEST on file ($isLatest) — so re-importing an old
 *     quarter can't clobber the current holdings (mirrors retirement_statement.php's guard
 *     on balance_current).
 *   • Sign convention matches the rest of the app: stored amount + = money OUT, − = money IN.
 *     The extractor returns amounts "as printed" (income +, fee/withdrawal −), so we store
 *     the negation.
 *   • Only touches MANUAL securities/holdings (id prefix 'man_') — never a Plaid row.
 */

const STMT_IMPORT_SOURCE = 'manual_ret';   // investment_transactions.ext_source tag (≤16 chars)

/** Deterministic security_id for a manual fund, keyed on its (normalized) name. ≤64 chars. */
function statement_security_id(string $name): string
{
    return 'man_' . substr(sha1(strtolower(trim($name))), 0, 40);
}

/** Map an extractor activity type → investment_transactions (type, subtype). */
function statement_activity_classify(string $type): array
{
    switch ($type) {
        case 'dividend':     return ['cash', 'dividend'];
        case 'capital_gain': return ['cash', 'capital gain'];   // 'capital' → income predicate
        case 'interest':     return ['cash', 'interest'];
        case 'contribution': return ['cash', 'contribution'];
        case 'fee':          return ['fee',  'fee'];
        default:             return ['cash', 'other'];
    }
}

/** Validate a YYYY-MM-DD-ish string → 'Y-m-d', or null. */
function statement_norm_date($v): ?string
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/**
 * Persist the holdings + activity of one reviewed statement. Returns row counts.
 *
 * @param string $accountId      the manual 401(k) account
 * @param string $period         the quarter bucket key (ret_period_key(); ext_period + idempotency key)
 * @param string $statementDate  the statement date 'Y-m-d' (fallback trade_date for undated activity)
 * @param array  $data           the reviewed extractor result (holdings[], activity[])
 * @param bool   $isLatest       true ⇒ this is the newest statement for the account ⇒ refresh holdings
 * @return array{securities:int,holdings:int,activity:int}
 */
function statement_import_save(PDO $pdo, string $accountId, string $period, string $statementDate, array $data, bool $isLatest): array
{
    $counts = ['securities' => 0, 'holdings' => 0, 'activity' => 0];

    // --- securities (+ holdings when latest) -----------------------------------
    $holdings   = is_array($data['holdings'] ?? null) ? $data['holdings'] : [];
    $secByName  = [];   // fund name → security_id (to attribute activity lines)
    $currentIds = [];   // securities present in THIS statement

    $secStmt  = $pdo->prepare(
        "INSERT INTO securities (security_id, ticker_symbol, name, type)
         VALUES (?, ?, ?, 'mutual fund')
         ON DUPLICATE KEY UPDATE
            ticker_symbol = COALESCE(VALUES(ticker_symbol), ticker_symbol),
            name = VALUES(name)"
    );
    $holdStmt = $pdo->prepare(
        "INSERT INTO holdings
            (account_id, security_id, quantity, institution_price, institution_value, cost_basis)
         VALUES (?, ?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            institution_price = VALUES(institution_price),
            institution_value = VALUES(institution_value)"
    );

    foreach ($holdings as $h) {
        $name = trim((string)($h['name'] ?? ''));
        if ($name === '') continue;
        $sid = statement_security_id($name);
        $secByName[$name] = $sid;
        $currentIds[]     = $sid;

        $ticker = trim((string)($h['ticker'] ?? '')) ?: null;
        $secStmt->execute([$sid, $ticker, $name]);
        $counts['securities']++;

        if ($isLatest) {
            $units = is_numeric($h['units'] ?? null) ? (float)$h['units'] : null;
            $value = is_numeric($h['value'] ?? null) ? (float)$h['value'] : null;
            $price = is_numeric($h['price'] ?? null) ? (float)$h['price'] : null;
            // Reject a mis-read sign: a long-only 401(k) holding never has a negative
            // qty/value/price, so drop a negative figure rather than store nonsense (and
            // so a negative units can't flip the derived price below). The reviewer is
            // also warned about this in statement_ocr_validate() before the save.
            if ($units !== null && $units < 0) $units = null;
            if ($value !== null && $value < 0) $value = null;
            if ($price !== null && $price < 0) $price = null;
            // Statements don't always print a per-unit price — derive it from value/units.
            if ($price === null && $units !== null && $units > 1e-9 && $value !== null) {
                $price = $value / $units;
            }
            $holdStmt->execute([$accountId, $sid, $units, $price, $value]);
            $counts['holdings']++;
        }
    }

    // On a latest import, drop any manual holding for this account whose fund vanished from
    // the statement (e.g. fully sold). LEFT(.,4)='man_' guards Plaid holdings (none on a
    // manual account, but belt-and-suspenders); literal '_' in '=' is not a wildcard.
    if ($isLatest) {
        if ($currentIds) {
            $ph = implode(',', array_fill(0, count($currentIds), '?'));
            $pdo->prepare(
                "DELETE FROM holdings
                 WHERE account_id = ? AND LEFT(security_id,4) = 'man_'
                   AND security_id NOT IN ($ph)"
            )->execute(array_merge([$accountId], $currentIds));
        } else {
            $pdo->prepare(
                "DELETE FROM holdings WHERE account_id = ? AND LEFT(security_id,4) = 'man_'"
            )->execute([$accountId]);
        }
    }

    // --- activity (investment_transactions, ext_source='manual_ret') -----------
    // Idempotent per period: clear this period's rows, then re-insert the reviewed set.
    $pdo->prepare(
        "DELETE FROM investment_transactions
         WHERE account_id = ? AND ext_source = ? AND ext_period = ?"
    )->execute([$accountId, STMT_IMPORT_SOURCE, $period]);

    $activity   = is_array($data['activity'] ?? null) ? $data['activity'] : [];
    // Attribute an activity line to a fund: the sole holding when single-fund (the common
    // case), else a per-account "(plan)" placeholder security.
    $defaultSid = count($secByName) === 1 ? (string)reset($secByName) : statement_security_id($accountId . '|plan');

    $actStmt = $pdo->prepare(
        "INSERT INTO investment_transactions
            (inv_tx_id, account_id, security_id, side, type, subtype, name,
             quantity, price, fees, amount, trade_date, ext_source, ext_period)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)"
    );

    $i = 0;
    foreach ($activity as $a) {
        $amount = is_numeric($a['amount'] ?? null) ? (float)$a['amount'] : null;
        if ($amount === null) continue;   // drop null-amount noise (e.g. a split fee label line)

        $type = strtolower(trim((string)($a['type'] ?? 'other')));
        // Contributions are captured authoritatively by the statement form's
        // employee/employer fields (→ retirement_statements → the "Contributed YTD"
        // hero). Storing them ALSO as manual_ret activity would double-surface the same
        // deposit in retirement.php's "Recent contributions" section, so skip them here.
        if ($type === 'contribution') continue;
        [$itType, $itSub] = statement_activity_classify($type);

        $units  = is_numeric($a['units'] ?? null) ? (float)$a['units'] : 0.0;
        $price  = is_numeric($a['price'] ?? null) ? (float)$a['price'] : 0.0;
        $stored = -$amount;   // printed (income +, fee −) → stored (+ out / − in)
        $date   = statement_norm_date($a['date'] ?? null) ?? $statementDate;
        $name   = trim((string)($a['description'] ?? '')) ?: ucfirst($type);
        $txid   = STMT_IMPORT_SOURCE . '_' . substr(sha1($accountId), 0, 12) . '_' . $period . '_' . $i;

        $actStmt->execute([
            $txid, $accountId, $defaultSid, $itType, $itSub, $name,
            $units, $price, $stored, $date, STMT_IMPORT_SOURCE, $period,
        ]);
        $counts['activity']++;
        $i++;
    }

    return $counts;
}
