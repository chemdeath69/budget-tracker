<?php
declare(strict_types=1);

/**
 * Weekly email digest (TODO #15).
 *
 * A household Sunday-night summary — net worth + week-over-week change, true
 * spending this week with top categories, and bills due in the next 14 days.
 * Built from existing household reads (q_networth / q_networth_change /
 * q_digest_spending / q_digest_upcoming_bills) and sent via send_html_alert().
 *
 * Fired once a week from cron/sync.php (maybe_send_weekly_digest), gated on the
 * `alert_settings.digest_enabled` flag the owner sets on settings.php (TODO #14).
 *
 * ⚠️ Timezone: ALL day-of-week / "already sent today" logic uses PHP date() (the
 * app TZ, America/Los_Angeles) — NEVER MySQL NOW()/CURDATE(). The daily cron fires
 * ~22:13 PDT, which is already *Monday* in the server's EDT clock, so mixing the two
 * clocks would mis-gate the send (the documented S24 timezone trap).
 *
 * ⚠️ HTML email uses INLINE hex colours, not the app's CSS custom properties: email
 * clients strip <style>/external CSS and don't support CSS vars — inline styles are
 * the only portable option, so the "no hard-coded hex" app rule doesn't apply here.
 */

require_once __DIR__ . '/queries.php';   // q_networth*, q_digest_*, pretty_cat
require_once __DIR__ . '/mailer.php';    // alert_settings(), send_html_alert()

/**
 * Gather the household figures for one weekly digest. Pure read + derive.
 * Returns a flat view array consumed by the two renderers below.
 */
function build_digest_view(PDO $pdo): array
{
    // Net worth — household; q_networth() already folds in the home value.
    $series  = q_networth($pdo);
    $last    = $series ? end($series) : null;
    $current = $last ? (float)$last['net_worth'] : 0.0;
    $change  = q_networth_change($pdo, $current, 7);   // ['pct','abs','from'] (any may be null)

    // True spending this week (household; same exclusions as Cash-flow/Trends).
    // 6 = a 7-day INCLUSIVE window (`>= CURDATE() - INTERVAL 6 DAY` → today−6 … today),
    // so the headline ties exactly to the 7-day `week_label` printed below (which is
    // today − P6D … today). Passing 7 would span 8 calendar days and over-count the label.
    $spend = q_digest_spending($pdo, 6);

    // Top 5 categories + an "Other" rollup for the rest.
    $top = [];
    $other = 0.0;
    foreach ($spend['cats'] as $i => $c) {
        if ($i < 5) { $top[] = ['label' => pretty_cat($c['category']), 'amount' => (float)$c['total']]; }
        else        { $other += (float)$c['total']; }
    }
    if ($other > 0) { $top[] = ['label' => 'Other', 'amount' => round($other, 2)]; }

    // Bills due in the next 14 days.
    $bills = q_digest_upcoming_bills($pdo, 14);

    // Week label — the 7 days ending today (app TZ).
    $end   = new DateTimeImmutable('today');
    $start = $end->sub(new DateInterval('P6D'));

    return [
        'net_worth'   => $current,
        'change'      => $change,
        'spend_total' => $spend['total'],
        'top_cats'    => $top,
        'bills'       => $bills,
        'week_label'  => $start->format('M j') . '–' . $end->format('M j'),
        'app_url'     => 'https://budget.example.com',
    ];
}

/** Plain-text rendering of the digest (the multipart fallback part). */
function render_digest_text(array $v): string
{
    $L = [];
    $L[] = 'Your week at Budget Tracker — ' . $v['week_label'];
    $L[] = str_repeat('=', 44);
    $L[] = '';
    $L[] = 'NET WORTH: ' . usd($v['net_worth']);
    $ch = $v['change'];
    if ($ch['abs'] !== null) {
        $dir = $ch['abs'] >= 0 ? 'up' : 'down';
        $pct = $ch['pct'] !== null ? ' (' . ($ch['pct'] >= 0 ? '+' : '') . $ch['pct'] . '%)' : '';
        $L[] = '  ' . $dir . ' ' . usd(abs($ch['abs'])) . $pct . ' vs last week';
    }
    $L[] = '';
    $L[] = 'SPENT THIS WEEK: ' . usd($v['spend_total']);
    foreach ($v['top_cats'] as $c) { $L[] = '  ' . $c['label'] . ': ' . usd($c['amount']); }
    if (!$v['top_cats']) { $L[] = '  (no spending recorded)'; }
    $L[] = '';
    $L[] = 'UPCOMING BILLS (next 14 days):';
    if ($v['bills']) {
        foreach ($v['bills'] as $b) {
            $due = date('M j', strtotime((string)$b['next_payment_due_date']));
            // Plaid reports $0 minimum for charge cards (no preset minimum) — show
            // "—" rather than "$0.00", which would read as "nothing due".
            $amt = ((float)$b['minimum_payment_amount'] > 0) ? usd($b['minimum_payment_amount']) : '—';
            $nm  = $b['account_name'] . ($b['mask'] ? " ••{$b['mask']}" : '');
            $L[] = "  $due — $nm: $amt";
        }
    } else {
        $L[] = '  (nothing due)';
    }
    $L[] = '';
    $L[] = $v['app_url'];
    return implode("\n", $L) . "\n";
}

/** Lightweight inline-styled HTML rendering of the digest. */
function render_digest_html(array $v): string
{
    $card  = 'background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 20px;margin:0 0 16px;';
    $muted = 'color:#64748b;font-size:13px;';
    $h2    = 'margin:0 0 6px;font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;';
    $big   = 'font-size:28px;font-weight:700;color:#0f172a;';

    // Net-worth week-over-week change line.
    $ch = $v['change'];
    $chHtml = '';
    if ($ch['abs'] !== null) {
        $up    = $ch['abs'] >= 0;
        $color = $up ? '#16a34a' : '#dc2626';
        $arrow = $up ? '▲' : '▼';
        $pct   = $ch['pct'] !== null ? ' (' . ($ch['pct'] >= 0 ? '+' : '') . e((string)$ch['pct']) . '%)' : '';
        $chHtml = '<div style="margin-top:4px;font-size:13px;color:' . $color . ';">'
                . $arrow . ' ' . e(usd(abs($ch['abs']))) . $pct . ' vs last week</div>';
    }

    // Top-category rows.
    $catRows = '';
    foreach ($v['top_cats'] as $c) {
        $catRows .= '<tr><td style="padding:4px 0;color:#334155;">' . e($c['label'])
                  . '</td><td style="padding:4px 0;text-align:right;font-weight:600;color:#0f172a;">'
                  . e(usd($c['amount'])) . '</td></tr>';
    }
    if ($catRows === '') {
        $catRows = '<tr><td style="padding:4px 0;color:#64748b;">No spending recorded</td><td></td></tr>';
    }

    // Bill rows.
    $billRows = '';
    foreach ($v['bills'] as $b) {
        $due = date('M j', strtotime((string)$b['next_payment_due_date']));
        $amt = ((float)$b['minimum_payment_amount'] > 0) ? usd($b['minimum_payment_amount']) : '—';
        $nm  = $b['account_name'] . ($b['mask'] ? " ••{$b['mask']}" : '');
        $billRows .= '<tr><td style="padding:4px 0;color:#64748b;white-space:nowrap;">' . e($due)
                   . '</td><td style="padding:4px 10px;color:#334155;">' . e($nm)
                   . '</td><td style="padding:4px 0;text-align:right;font-weight:600;color:#0f172a;">'
                   . e($amt) . '</td></tr>';
    }
    if ($billRows === '') {
        $billRows = '<tr><td colspan="3" style="padding:4px 0;color:#64748b;">Nothing due in the next 14 days</td></tr>';
    }

    $url = e($v['app_url']);

    return '<!DOCTYPE html><html><body style="margin:0;background:#f1f5f9;padding:24px 0;'
         . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
         . '<div style="max-width:560px;margin:0 auto;padding:0 16px;">'
         . '<div style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 2px;">Your week at Budget Tracker</div>'
         . '<div style="' . $muted . 'margin:0 0 18px;">' . e($v['week_label']) . '</div>'

         . '<div style="' . $card . '"><div style="' . $h2 . '">Net worth</div>'
         . '<div style="' . $big . '">' . e(usd($v['net_worth'])) . '</div>' . $chHtml . '</div>'

         . '<div style="' . $card . '"><div style="' . $h2 . '">Spent this week</div>'
         . '<div style="' . $big . '">' . e(usd($v['spend_total'])) . '</div>'
         . '<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:14px;">' . $catRows . '</table></div>'

         . '<div style="' . $card . '"><div style="' . $h2 . '">Upcoming bills · next 14 days</div>'
         . '<table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:14px;">' . $billRows . '</table></div>'

         . '<div style="text-align:center;margin:4px 0 0;"><a href="' . $url . '" '
         . 'style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;'
         . 'padding:10px 22px;border-radius:8px;font-weight:600;font-size:14px;">Open Budget Tracker</a></div>'
         . '<div style="' . $muted . 'text-align:center;margin-top:14px;">'
         . 'You\'re receiving this weekly summary because the digest is enabled in '
         . 'Settings → Alerts &amp; notifications.</div>'

         . '</div></body></html>';
}

/** The `alert_settings.digest_sent_on` marker (PHP-date string) or null if never
 *  sent / column missing. Its own guarded read — alert_settings() doesn't expose it. */
function digest_last_sent(PDO $pdo): ?string
{
    try {
        $v = $pdo->query("SELECT digest_sent_on FROM alert_settings WHERE id = 1")->fetchColumn();
        return ($v === false || $v === null) ? null : (string)$v;
    } catch (Throwable $e) {
        return null;   // pre-migration column missing — treat as never sent
    }
}

/**
 * Cron entry point — send the weekly digest if it's due. Logs one line either way.
 *
 * Gating: master email switch + digest_enabled must be on; then (unless $force) it
 * must be Sunday in the app TZ — OR a catch-up run ≥7 days after the last send (so a
 * single missed Sunday cron still goes out once) — and not sent in the last 6 days.
 * $force (for verification) bypasses the gate + idempotency AND skips writing the
 * marker, so a test send never suppresses the real Sunday run.
 */
function maybe_send_weekly_digest(PDO $pdo, bool $force = false): void
{
    $now = date('Y-m-d H:i:s T');
    $cfg = alert_settings($pdo);
    if (!$cfg['email_enabled'] || !$cfg['digest_enabled']) {
        echo "[$now] digest: skipped (disabled)\n";
        return;
    }

    if (!$force) {
        // Fire on Sunday (app TZ), OR catch up on the first run after a MISSED Sunday —
        // the cron is daily but fires once a night, so a single skipped Sunday would
        // otherwise lose the week's digest with no make-up. The min-6-day spacing (plus
        // the null-marker guard) prevents a double-send and re-anchors to Sunday. All
        // date math is PHP app-TZ (the marker is written as date('Y-m-d')); NEVER the
        // MySQL clock — the cron fires after midnight EDT (the S24 TZ trap).
        $sent      = digest_last_sent($pdo);                       // 'Y-m-d' or null
        $daysSince = $sent === null ? null
                   : (int) floor((strtotime(date('Y-m-d')) - strtotime($sent)) / 86400);
        if ($daysSince !== null && $daysSince < 6) {
            echo "[$now] digest: skipped (sent $daysSince day(s) ago)\n";
            return;
        }
        $catchUp = $daysSince !== null && $daysSince >= 7;
        if (date('w') !== '0' && !$catchUp) {
            echo "[$now] digest: skipped (not Sunday)\n";
            return;
        }
    }

    $view = build_digest_view($pdo);

    // Record the send BEFORE dispatching: @mail() is best-effort, so if the marker
    // UPDATE were to throw AFTER sending, a same-day cron re-run could send a second
    // digest. Marking first errs toward "don't re-send" — the safer idempotency choice.
    if (!$force) {
        $pdo->prepare('UPDATE alert_settings SET digest_sent_on = :d WHERE id = 1')
            ->execute([':d' => date('Y-m-d')]);
    }

    $subject = 'Your weekly summary — ' . $view['week_label'];
    send_html_alert($subject, render_digest_html($view), render_digest_text($view));

    echo "[$now] digest: sent" . ($force ? ' (forced)' : '') . ' — spent ' . usd($view['spend_total'])
       . ', net ' . usd($view['net_worth']) . ', ' . count($view['bills']) . " bill(s)\n";
}
