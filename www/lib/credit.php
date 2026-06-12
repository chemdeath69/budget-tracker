<?php
/**
 * credit.php — credit-report read assembler + insight layer (Session 58, TODO2 #28).
 *
 * Pure read + derive: decrypts the migration-021 rows and computes the dashboard the
 * `credit.php` page renders — utilization, account age, inquiry timeline, credit mix, a
 * "credit health" composite (used when the free report carries no score), the
 * reconciliation-vs-tracked-accounts cross-feature, and a pull-over-pull diff.
 *
 *   build_credit_overview($pdo,$uid)         → latest pull per (user,bureau) + quick stats
 *   build_credit_view($pdo,$uid,$reportId)   → full insight bundle for one report (or null)
 *
 * Reports are household-visible (either signed-in user views both), so the reads are NOT
 * VIS-scoped; but reconciliation matches against q_accounts($pdo,$uid) — the accounts the
 * VIEWER can see — so it never leaks the other user's private accounts into the match.
 *
 * Requires lib/credit_import.php (credit_dec()/crypto) and lib/queries.php (q_credit_*,
 * q_accounts, household_users/owner_first_name). The page loads both.
 */

require_once __DIR__ . '/credit_import.php';   // credit_dec() + crypto

/** Bureau key → display label. */
function credit_bureau_label(string $b): string
{
    return ['equifax' => 'Equifax', 'experian' => 'Experian', 'transunion' => 'TransUnion'][$b] ?? 'Other bureau';
}

/** account_type → mix bucket (revolving|installment|mortgage) or null (not in the mix). */
function credit_mix_bucket(?string $type): ?string
{
    return match ($type) {
        'revolving'  => 'revolving',
        'mortgage'   => 'mortgage',
        'installment', 'auto', 'student', 'personal' => 'installment',
        default      => null,   // collection / other / unknown — not part of credit mix
    };
}

/** Whole years between a Y-m-d date and today (app TZ), or null. */
function credit_years_since(?string $ymd): ?float
{
    if (!$ymd) return null;
    $ts = strtotime($ymd);
    if ($ts === false) return null;
    $days = (time() - $ts) / 86400;
    return $days < 0 ? 0.0 : round($days / 365.25, 1);
}

/** Whole months between $ymd and today (app TZ), or null. Negative clamped to 0. */
function credit_months_since(?string $ymd): ?int
{
    if (!$ymd) return null;
    try {
        $a = new DateTime($ymd);
        $b = new DateTime(date('Y-m-d'));
    } catch (Throwable $e) {
        return null;
    }
    if ($a > $b) return 0;
    $d = $a->diff($b);
    return $d->y * 12 + $d->m;
}

/** Normalize a name for fuzzy reconciliation matching (UPPER, alnum only). */
function credit_norm_name(string $s): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?: '');
}

/** Decrypt the sensitive columns on a fetched tradeline row, in place-ish (returns new). */
function credit_decrypt_tradeline(array $t): array
{
    $t['creditor']     = credit_dec($t['creditor_enc'] ?? null) ?? '(unreadable)';
    $t['account_mask'] = credit_dec($t['account_mask_enc'] ?? null);
    $t['is_open']      = isset($t['is_open']) && $t['is_open'] !== null ? (int)$t['is_open'] : null;
    foreach (['balance', 'credit_limit', 'high_balance', 'monthly_payment', 'past_due'] as $m) {
        $t[$m] = isset($t[$m]) && $t[$m] !== null ? (float)$t[$m] : null;
    }
    unset($t['creditor_enc'], $t['account_mask_enc']);
    return $t;
}

/** Whether a tradeline counts as "open" for utilization/coverage (closed_on or is_open=0 ⇒ no). */
function credit_tl_is_open(array $t): bool
{
    if (($t['is_open'] ?? null) === 0) return false;
    if (!empty($t['closed_on'])) return false;
    return true;
}

/**
 * Latest report per (user, bureau) + a quick stat line for each, for the overview grid.
 * Returns ['cards'=>[…], 'has_any'=>bool].
 */
function build_credit_overview(PDO $pdo, int $uid): array
{
    $reports = q_credit_reports($pdo);
    $seen = [];
    $cards = [];
    foreach ($reports as $r) {                       // already DESC by pulled_on
        $key = $r['user_id'] . '|' . $r['bureau'];
        if (isset($seen[$key])) continue;            // keep only the most recent pull
        $seen[$key] = true;

        $tls = array_map('credit_decrypt_tradeline', q_credit_tradelines($pdo, (int)$r['id']));
        $flagCount = count(q_credit_flags($pdo, (int)$r['id']));

        $revBal = 0.0; $revLim = 0.0; $openCount = 0; $totalBal = 0.0;
        foreach ($tls as $t) {
            $open = credit_tl_is_open($t);
            if ($open) $openCount++;
            if ($t['balance'] !== null) $totalBal += $t['balance'];
            if ($t['account_type'] === 'revolving' && $open && ($t['credit_limit'] ?? 0) > 0) {
                $revBal += (float)$t['balance'];
                $revLim += (float)$t['credit_limit'];
            }
        }
        $util = $revLim > 0 ? $revBal / $revLim : null;

        $cards[] = [
            'report_id'   => (int)$r['id'],
            'user_id'     => (int)$r['user_id'],
            'owner_name'  => owner_first_name((int)$r['user_id']),
            'bureau'      => $r['bureau'],
            'bureau_label' => credit_bureau_label($r['bureau']),
            'pulled_on'   => $r['pulled_on'],
            'score'       => $r['score'] !== null ? (int)$r['score'] : null,
            'score_model' => $r['score_model'],
            'utilization' => $util,
            'open_accounts' => $openCount,
            'tradelines'  => count($tls),
            'derogatory'  => $flagCount,
            'total_balance' => $totalBal,
        ];
    }
    // Stable display order: by owner then bureau.
    usort($cards, fn($a, $b) => [$a['owner_name'], $a['bureau']] <=> [$b['owner_name'], $b['bureau']]);
    return ['cards' => $cards, 'has_any' => $reports !== []];
}

/** The "credit health" composite (0–100 + label) from the report factors. */
function credit_health_composite(?float $util, ?float $avgAge, int $inq12, int $derog, int $mixCount): array
{
    $u = $util === null ? 70
        : ($util < 0.10 ? 100 : ($util < 0.30 ? 80 : ($util < 0.50 ? 55 : ($util < 0.75 ? 30 : 12))));
    $a = $avgAge === null ? 60
        : ($avgAge >= 7 ? 100 : ($avgAge >= 5 ? 85 : ($avgAge >= 3 ? 65 : ($avgAge >= 1 ? 42 : 22))));
    $q = $inq12 <= 0 ? 100 : ($inq12 === 1 ? 85 : ($inq12 === 2 ? 65 : ($inq12 === 3 ? 45 : 25)));
    $d = $derog <= 0 ? 100 : ($derog === 1 ? 40 : ($derog === 2 ? 20 : 6));
    $m = $mixCount >= 3 ? 100 : ($mixCount === 2 ? 75 : ($mixCount === 1 ? 45 : 20));

    $score = $u * 0.30 + $d * 0.35 + $a * 0.15 + $q * 0.10 + $m * 0.10;
    $score = (int)round($score);
    $label = $score >= 80 ? 'Excellent' : ($score >= 65 ? 'Good' : ($score >= 45 ? 'Fair' : 'Needs work'));
    return ['score' => $score, 'label' => $label];
}

/** Compact tradeline summary signature for a pull-over-pull diff (creditor+mask key). */
function credit_tl_key(array $t): string
{
    return credit_norm_name((string)($t['creditor'] ?? '')) . '|' . ((string)($t['account_mask'] ?? ''));
}

/**
 * Full insight bundle for one report. Returns null if the report id is unknown.
 */
function build_credit_view(PDO $pdo, int $uid, int $reportId): ?array
{
    $r = q_credit_report($pdo, $reportId);
    if (!$r) return null;

    $tls   = array_map('credit_decrypt_tradeline', q_credit_tradelines($pdo, $reportId));
    $inqs  = array_map(function ($q) {
        $q['inquirer'] = credit_dec($q['inquirer_enc'] ?? null) ?? '(unreadable)';
        unset($q['inquirer_enc']);
        return $q;
    }, q_credit_inquiries($pdo, $reportId));
    $flags = array_map(function ($f) {
        $f['detail'] = credit_dec($f['detail_enc'] ?? null);
        $f['amount'] = isset($f['amount']) && $f['amount'] !== null ? (float)$f['amount'] : null;
        unset($f['detail_enc']);
        return $f;
    }, q_credit_flags($pdo, $reportId));

    // --- utilization (open revolving with a limit) --------------------------
    $revBal = 0.0; $revLim = 0.0; $cards = [];
    foreach ($tls as $t) {
        if ($t['account_type'] === 'revolving' && credit_tl_is_open($t) && ($t['credit_limit'] ?? 0) > 0) {
            $bal = (float)($t['balance'] ?? 0);
            $revBal += $bal; $revLim += (float)$t['credit_limit'];
            $cards[] = [
                'creditor' => $t['creditor'], 'mask' => $t['account_mask'],
                'balance' => $bal, 'limit' => (float)$t['credit_limit'],
                'util' => $bal / (float)$t['credit_limit'],
            ];
        }
    }
    usort($cards, fn($a, $b) => $b['util'] <=> $a['util']);
    $overallUtil = $revLim > 0 ? $revBal / $revLim : null;

    // --- account age --------------------------------------------------------
    $ages = [];
    $oldest = null; $newest = null;
    foreach ($tls as $t) {
        $yrs = credit_years_since($t['opened_on'] ?? null);
        if ($yrs === null) continue;
        $ages[] = $yrs;
        if ($oldest === null || $yrs > $oldest['years']) $oldest = ['creditor' => $t['creditor'], 'years' => $yrs, 'opened_on' => $t['opened_on']];
        if ($newest === null || $yrs < $newest['years']) $newest = ['creditor' => $t['creditor'], 'years' => $yrs, 'opened_on' => $t['opened_on']];
    }
    $avgAge = $ages ? round(array_sum($ages) / count($ages), 1) : null;

    // --- inquiry timeline ---------------------------------------------------
    $inqOut = []; $inq12 = 0; $inq24 = 0;
    foreach ($inqs as $q) {
        $mo = credit_months_since($q['inquiry_date'] ?? null);
        if ($mo !== null) {
            if ($mo < 12) $inq12++;
            if ($mo < 24) $inq24++;
        }
        $agesOff = null;
        if (!empty($q['inquiry_date'])) {
            $ts = strtotime($q['inquiry_date'] . ' +12 months');
            if ($ts !== false) $agesOff = date('Y-m-d', $ts);
        }
        $inqOut[] = ['inquirer' => $q['inquirer'], 'date' => $q['inquiry_date'], 'type' => $q['inquiry_type'],
                     'months_ago' => $mo, 'ages_off' => $agesOff];
    }
    usort($inqOut, fn($a, $b) => (string)$b['date'] <=> (string)$a['date']);

    // --- credit mix ---------------------------------------------------------
    $mix = ['revolving' => ['count' => 0, 'balance' => 0.0], 'installment' => ['count' => 0, 'balance' => 0.0], 'mortgage' => ['count' => 0, 'balance' => 0.0]];
    foreach ($tls as $t) {
        $b = credit_mix_bucket($t['account_type'] ?? null);
        if ($b === null) continue;
        $mix[$b]['count']++;
        if ($t['balance'] !== null) $mix[$b]['balance'] += (float)$t['balance'];
    }
    $mixCount = count(array_filter($mix, fn($m) => $m['count'] > 0));

    // --- derogatory (flags + any collection tradelines) ---------------------
    $collections = array_values(array_filter($tls, fn($t) => $t['account_type'] === 'collection'));
    $derogCount  = count($flags) + count($collections);

    // --- health composite (shown alongside the score, or instead of it) -----
    $health = credit_health_composite($overallUtil, $avgAge, $inq12, $derogCount, $mixCount);

    // --- reconciliation vs tracked accounts ---------------------------------
    $recon = credit_reconcile($pdo, $uid, $tls);

    // --- pull-over-pull diff ------------------------------------------------
    $diff = credit_pull_diff($pdo, $r, $tls, $inqs, $overallUtil);

    return [
        'report'      => [
            'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'],
            'owner_name' => owner_first_name((int)$r['user_id']),
            'bureau' => $r['bureau'], 'bureau_label' => credit_bureau_label($r['bureau']),
            'pulled_on' => $r['pulled_on'], 'score' => $r['score'] !== null ? (int)$r['score'] : null,
            'score_model' => $r['score_model'],
            'consumer_name' => credit_dec($r['consumer_name_enc'] ?? null),
            'created_at' => $r['created_at'] ?? null,
        ],
        'utilization' => ['overall' => $overallUtil, 'balance' => $revBal, 'limit' => $revLim, 'cards' => $cards],
        'age'         => ['average' => $avgAge, 'oldest' => $oldest, 'newest' => $newest, 'count' => count($ages)],
        'inquiries'   => ['list' => $inqOut, 'count12' => $inq12, 'count24' => $inq24, 'total' => count($inqOut)],
        'mix'         => ['buckets' => $mix, 'distinct' => $mixCount],
        'flags'       => $flags,
        'collections' => $collections,
        'derog_count' => $derogCount,
        'health'      => $health,
        'tradelines'  => $tls,
        'recon'       => $recon,
        'diff'        => $diff,
    ];
}

/**
 * Match report tradelines against the VIEWER's tracked credit/loan accounts.
 * Strong signal = last-4 mask equality; fallback = normalized-name overlap.
 */
function credit_reconcile(PDO $pdo, int $uid, array $tls): array
{
    $accts = array_values(array_filter(q_accounts($pdo, $uid), fn($a) => in_array($a['type'], ['credit', 'loan'], true)));

    $matched = []; $usedAcct = [];
    foreach ($tls as $t) {
        if (!credit_tl_is_open($t)) continue;                 // reconcile open accounts
        $mask = $t['account_mask'] ?? null;
        $nameN = credit_norm_name((string)$t['creditor']);
        $hit = null; $how = null;
        foreach ($accts as $idx => $a) {
            if (isset($usedAcct[$idx])) continue;
            $aMask = trim((string)($a['mask'] ?? ''));
            if ($mask !== null && $aMask !== '' && substr($aMask, -4) === $mask) { $hit = $idx; $how = 'mask'; break; }
        }
        if ($hit === null && $nameN !== '') {                 // name fallback
            foreach ($accts as $idx => $a) {
                if (isset($usedAcct[$idx])) continue;
                $an = credit_norm_name((string)($a['name'] ?? '') . ($a['institution_name'] ?? ''));
                $inN = credit_norm_name((string)($a['institution_name'] ?? ''));
                $cand = [$an, $inN, credit_norm_name((string)($a['name'] ?? ''))];
                foreach ($cand as $c) {
                    if ($c === '' || strlen($nameN) < 4) continue;
                    $short = substr($nameN, 0, max(5, min(strlen($nameN), 8)));
                    if (strpos($c, $short) !== false || strpos($nameN, substr($c, 0, 5)) !== false) {
                        $hit = $idx; $how = 'name'; break 2;
                    }
                }
            }
        }
        if ($hit !== null) {
            $usedAcct[$hit] = true;
            $a = $accts[$hit];
            $reportBal = $t['balance'];
            // Live balance: liabilities carry positive balance in balance_current.
            $liveBal = isset($a['balance_current']) ? abs((float)$a['balance_current']) : null;
            $matched[] = [
                'creditor' => $t['creditor'], 'mask' => $t['account_mask'], 'how' => $how,
                'account_name' => $a['name'], 'account_id' => $a['account_id'],
                'owner_id' => $a['owner_id'] ?? null,
                'report_balance' => $reportBal, 'live_balance' => $liveBal,
                'discrepancy' => ($reportBal !== null && $liveBal !== null) ? $liveBal - $reportBal : null,
            ];
        }
    }

    // Open report tradelines with a balance that we did NOT match → coverage gap.
    $matchedKeys = array_map(fn($m) => credit_norm_name((string)$m['creditor']) . '|' . ((string)$m['mask']), $matched);
    $unmatchedReport = [];
    foreach ($tls as $t) {
        if (!credit_tl_is_open($t)) continue;
        $k = credit_norm_name((string)$t['creditor']) . '|' . ((string)($t['account_mask'] ?? ''));
        if (in_array($k, $matchedKeys, true)) continue;
        // Only flag substantive accounts (a real card/loan), not noise.
        if (in_array($t['account_type'], ['revolving', 'installment', 'mortgage', 'auto', 'student', 'personal'], true)) {
            $unmatchedReport[] = ['creditor' => $t['creditor'], 'mask' => $t['account_mask'],
                                  'account_type' => $t['account_type'], 'balance' => $t['balance']];
        }
    }

    // Tracked credit/loan accounts with no matched tradeline → maybe not on this report.
    $unmatchedTracked = [];
    foreach ($accts as $idx => $a) {
        if (isset($usedAcct[$idx])) continue;
        $unmatchedTracked[] = ['account_name' => $a['name'], 'account_id' => $a['account_id'],
                               'mask' => $a['mask'] ?? null, 'type' => $a['type'],
                               'balance' => isset($a['balance_current']) ? abs((float)$a['balance_current']) : null];
    }

    return ['matched' => $matched, 'unmatched_report' => $unmatchedReport, 'unmatched_tracked' => $unmatchedTracked];
}

/** Compare this report to the prior pull for the same (user, bureau). */
function credit_pull_diff(PDO $pdo, array $report, array $tls, array $inqs, ?float $util): ?array
{
    $prior = q_credit_prior_report($pdo, (int)$report['user_id'], (string)$report['bureau'], (string)$report['pulled_on']);
    if (!$prior) return null;

    $pTls = array_map('credit_decrypt_tradeline', q_credit_tradelines($pdo, (int)$prior['id']));
    $pInqCount = count(q_credit_inquiries($pdo, (int)$prior['id']));
    $pFlagCount = count(q_credit_flags($pdo, (int)$prior['id']));
    $curFlagCount = count(q_credit_flags($pdo, (int)$report['id']));

    // Prior utilization.
    $pRevBal = 0.0; $pRevLim = 0.0;
    foreach ($pTls as $t) {
        if ($t['account_type'] === 'revolving' && credit_tl_is_open($t) && ($t['credit_limit'] ?? 0) > 0) {
            $pRevBal += (float)$t['balance']; $pRevLim += (float)$t['credit_limit'];
        }
    }
    $pUtil = $pRevLim > 0 ? $pRevBal / $pRevLim : null;

    // New / closed accounts by key.
    $curKeys = array_map('credit_tl_key', $tls);
    $priorKeys = array_map('credit_tl_key', $pTls);
    $newAccts = count(array_diff($curKeys, $priorKeys));
    $goneAccts = count(array_diff($priorKeys, $curKeys));

    return [
        'prior_id'      => (int)$prior['id'],
        'prior_pulled'  => $prior['pulled_on'],
        'score_prev'    => $prior['score'] !== null ? (int)$prior['score'] : null,
        'score_now'     => $report['score'] !== null ? (int)$report['score'] : null,
        'util_prev'     => $pUtil,
        'util_now'      => $util,
        'inq_prev'      => $pInqCount,
        'inq_now'       => count($inqs),
        'derog_prev'    => $pFlagCount,
        'derog_now'     => $curFlagCount,
        'new_accounts'  => $newAccts,
        'closed_accounts' => $goneAccts,
    ];
}
