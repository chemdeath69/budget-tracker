<?php
declare(strict_types=1);

/**
 * Spending alerts (TODO #16) — the consumer side of three alert_settings flags that
 * Session 25 (#14) shipped as storage-only:
 *
 *   1. Budget exceeded  — a budgeted category has spent ≥ budget_alert_pct % of its
 *                         monthly_limit this month.
 *   2. Unusual spending — a category's month-to-date spend is ≥ SPEND_ANOMALY_MULT ×
 *                         its 3-prior-month average (and ≥ SPEND_ANOMALY_MIN, a noise floor).
 *   3. Bill reminder    — a liability is due within bill_reminder_days.
 *
 * Fired nightly from cron/sync.php (maybe_send_spend_alerts), gated on alert_settings.
 * One consolidated plain-text email per run (event-alert convention, like the large-tx
 * alert) — NOT the rich HTML digest. Dedup via the `alert_log` table (migration 013):
 * each alert is claimed atomically with INSERT IGNORE on UNIQUE (alert_type, alert_key,
 * period), so the daily cron emails each crossing AT MOST ONCE per occurrence.
 *
 * ⚠️ Timezone: every period / "today" value is PHP date() (app TZ, America/Los_Angeles),
 * NEVER MySQL NOW()/CURDATE() — the daily cron fires ~22:13 PDT = already Monday in the
 * server's EDT clock (the documented S24 timezone trap).
 */

require_once __DIR__ . '/queries.php';   // q_budgets, q_spend_anomalies, q_digest_upcoming_bills, pretty_cat
require_once __DIR__ . '/mailer.php';    // alert_settings(), send_alert()

const SPEND_ANOMALY_MULT = 2.0;    // "2× its 3-month average" (the TODO threshold)
const SPEND_ANOMALY_MIN  = 50.0;   // $ floor — suppress tiny-category noise early in the month

/**
 * Gather the alerts that currently apply, honouring the per-type flags in $cfg.
 * PURE read + derive — no writes, so it's safe for a read-only dry-run (pass a $cfg
 * with the flags forced on to preview everything). The caller decides what to send.
 *
 * Returns ['budget'=>[…], 'unusual'=>[…], 'bills'=>[…]].
 */
function build_spend_alerts(PDO $pdo, array $cfg): array
{
    $out = ['budget' => [], 'unusual' => [], 'bills' => []];

    // 1. Budget exceeded — reuse q_budgets() (household-wide, hidden excluded,
    //    current-month spent vs monthly_limit).
    if (!empty($cfg['budget_alert_enabled'])) {
        $pct = max(1, (int)($cfg['budget_alert_pct'] ?? 90));
        foreach (q_budgets($pdo)['budgets'] as $b) {
            $limit = (float)$b['monthly_limit'];
            $spent = (float)$b['spent'];
            if ($limit > 0 && $spent >= $limit * $pct / 100) {
                $out['budget'][] = [
                    'category' => $b['category'],
                    'spent'    => round($spent, 2),
                    'limit'    => round($limit, 2),
                    'pct'      => (int)round($spent / $limit * 100),
                ];
            }
        }
    }

    // 2. Unusual spending — month-to-date ≥ MULT × 3-mo avg, above the dollar floor.
    if (!empty($cfg['unusual_spend_enabled'])) {
        foreach (q_spend_anomalies($pdo) as $a) {
            if ($a['avg3'] > 0
                && $a['this'] >= SPEND_ANOMALY_MULT * $a['avg3']
                && $a['this'] >= SPEND_ANOMALY_MIN) {
                $out['unusual'][] = [
                    'category' => $a['category'],
                    'this'     => $a['this'],
                    'avg3'     => $a['avg3'],
                    'mult'     => round($a['this'] / $a['avg3'], 1),
                ];
            }
        }
    }

    // 3. Bill reminders — liabilities due within bill_reminder_days (household-wide).
    if (!empty($cfg['bill_reminder_enabled'])) {
        $days = max(1, (int)($cfg['bill_reminder_days'] ?? 5));
        foreach (q_digest_upcoming_bills($pdo, $days) as $b) {
            $out['bills'][] = [
                'account_id'            => (string)$b['account_id'],
                'account_name'          => (string)$b['account_name'],
                'mask'                  => $b['mask'],
                'minimum_payment_amount'=> $b['minimum_payment_amount'],
                'next_payment_due_date' => (string)$b['next_payment_due_date'],
            ];
        }
    }

    return $out;
}

/** Plain-text body for the consolidated spending-alert email. */
function render_spend_alerts_text(array $fresh): string
{
    $L = [];
    if ($fresh['budget']) {
        $L[] = 'BUDGETS OVER THRESHOLD';
        foreach ($fresh['budget'] as $b) {
            $L[] = '  ' . pretty_cat($b['category']) . ': ' . usd($b['spent']) . ' of '
                 . usd($b['limit']) . ' (' . $b['pct'] . '%)';
        }
        $L[] = '';
    }
    if ($fresh['unusual']) {
        $L[] = 'UNUSUAL SPENDING (vs 3-month average)';
        foreach ($fresh['unusual'] as $u) {
            $L[] = '  ' . pretty_cat($u['category']) . ': ' . usd($u['this']) . ' so far — '
                 . $u['mult'] . '× the ' . usd($u['avg3']) . ' average';
        }
        $L[] = '';
    }
    if ($fresh['bills']) {
        $L[] = 'BILLS DUE SOON';
        foreach ($fresh['bills'] as $b) {
            $due = date('M j', strtotime($b['next_payment_due_date']));
            // Plaid reports $0 minimum for charge cards (no preset minimum) — show
            // "—" rather than "$0.00", which would read as "nothing due".
            $amt = ((float)$b['minimum_payment_amount'] > 0) ? usd($b['minimum_payment_amount']) : '—';
            $nm  = $b['account_name'] . ($b['mask'] ? " ••{$b['mask']}" : '');
            $L[] = "  $due — $nm: $amt";
        }
        $L[] = '';
    }
    $L[] = 'https://budget.example.com';
    return implode("\n", $L) . "\n";
}

/**
 * Cron entry point — send any newly-applicable spending alerts. Logs one line.
 *
 * Gating: the master email switch must be on, then at least one of the three per-type
 * flags. Each candidate is claimed in alert_log (INSERT IGNORE) so it emails at most
 * once per occurrence; only the freshly-claimed ones go into the (single) email.
 *
 * $force (verification) turns all three types on, bypasses the email switch, sends
 * EVERYTHING that currently applies, and skips writing alert_log — so a test send never
 * suppresses the real nightly run.
 */
function maybe_send_spend_alerts(PDO $pdo, bool $force = false): void
{
    $now = date('Y-m-d H:i:s T');
    $cfg = alert_settings($pdo);

    if ($force) {
        $cfg['email_enabled']         = true;
        $cfg['budget_alert_enabled']  = true;
        $cfg['unusual_spend_enabled'] = true;
        $cfg['bill_reminder_enabled'] = true;
    }

    if (!$cfg['email_enabled']) {
        echo "[$now] spend-alerts: skipped (email disabled)\n";
        return;
    }
    if (!$cfg['budget_alert_enabled'] && !$cfg['unusual_spend_enabled'] && !$cfg['bill_reminder_enabled']) {
        echo "[$now] spend-alerts: skipped (none enabled)\n";
        return;
    }

    $cand  = build_spend_alerts($pdo, $cfg);
    $month = date('Y-m');
    $today = date('Y-m-d');

    // Claim a (type,key,period) slot; true iff this is the first time (INSERT, not IGNORE).
    // $force sends without claiming so the real nightly send isn't pre-empted.
    $claimStmt = $pdo->prepare(
        "INSERT IGNORE INTO alert_log (alert_type, alert_key, period, sent_on)
         VALUES (:t, :k, :p, :s)"
    );
    $claim = function (string $type, string $key, string $period) use ($force, $claimStmt, $today): bool {
        if ($force) return true;
        $claimStmt->execute([':t' => $type, ':k' => $key, ':p' => $period, ':s' => $today]);
        return $claimStmt->rowCount() === 1;
    };

    $fresh = ['budget' => [], 'unusual' => [], 'bills' => []];
    foreach ($cand['budget'] as $c) {
        if ($claim('budget', $c['category'], $month)) $fresh['budget'][] = $c;
    }
    foreach ($cand['unusual'] as $c) {
        if ($claim('unusual', $c['category'], $month)) $fresh['unusual'][] = $c;
    }
    foreach ($cand['bills'] as $c) {
        if ($claim('bill', $c['account_id'], $c['next_payment_due_date'])) $fresh['bills'][] = $c;
    }

    $n = count($fresh['budget']) + count($fresh['unusual']) + count($fresh['bills']);
    if ($n === 0) {
        echo "[$now] spend-alerts: nothing new to send\n";
        return;
    }

    // ⚠️ The alert_log rows are written by $claim() BEFORE this send. @mail() is
    // best-effort, so a failed send won't re-fire — the same "err toward don't re-send"
    // idempotency choice the weekly digest makes (a missed alert is better than a nightly
    // re-spam). Acceptable for these non-critical informational alerts.
    $parts = [];
    if ($fresh['budget'])  $parts[] = count($fresh['budget'])  . ' budget';
    if ($fresh['unusual']) $parts[] = count($fresh['unusual']) . ' unusual';
    if ($fresh['bills'])   $parts[] = count($fresh['bills'])   . ' bill';
    $subject = 'Spending alerts — ' . implode(', ', $parts);

    send_alert($subject, render_spend_alerts_text($fresh));

    echo "[$now] spend-alerts: sent" . ($force ? ' (forced)' : '') . " — $n item(s) ("
       . implode(', ', $parts) . ")\n";
}
