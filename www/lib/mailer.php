<?php
declare(strict_types=1);

/**
 * Build the `[Budget Tracker] …` mail Subject: CR/LF-stripped (header-injection
 * hardening) and RFC 2047 encoded so any non-ASCII (the digest's em/en-dashes, a
 * future merchant/account name in an alert) reaches the inbox as a proper
 * encoded-word instead of raw 8-bit mojibake. mbstring is present on this host.
 */
function mail_subject(string $subject): string
{
    $subject = '[Budget Tracker] ' . str_replace(["\r", "\n"], '', $subject);
    return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
}

/**
 * The From address for outgoing mail, CR/LF-stripped (header-injection hardening)
 * so a malformed `config['alerts']['from']` can never inject extra headers.
 * The display name is a fixed literal; only the address comes from config.
 */
function mail_from(): string
{
    global $CONFIG;
    $from = (string)($CONFIG['alerts']['from'] ?? 'budget@example.com');
    $from = str_replace(["\r", "\n"], '', $from);
    return "Budget Tracker <$from>";
}

/** Send a plain-text alert email to the configured recipients. Best-effort. */
function send_alert(string $subject, string $body): void
{
    global $CONFIG;
    $to   = implode(', ', $CONFIG['alerts']['recipients'] ?? []);
    if ($to === '') return;

    $headers = 'From: ' . mail_from() . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";   // bodies may carry UTF-8 (names, symbols)
    @mail($to, mail_subject($subject), $body, $headers);
}

/**
 * Connection-attention alert WITH DEDUP (code review 3.3). A broken bank
 * (ITEM_LOGIN_REQUIRED / PENDING_EXPIRATION / an ITEM ERROR webhook) otherwise emailed on
 * EVERY failed sync — the nightly cron AND every webhook retry — until re-linked, i.e. a
 * nightly (or worse) spam stream. Both sync.php's PlaidException branch and webhook.php's
 * ITEM branch route through here so they share one dedup.
 *
 * Dedup via the alert_log table (like spend_alerts): send at most once per
 * CONNECTION_ALERT_DEDUP_DAYS per item. A successful sync clears the item's key
 * (clear_connection_alert), so a genuinely fresh break re-alerts immediately rather than
 * being suppressed by an old crossing. Period/"today" are PHP app-TZ (the S24 trap).
 *
 * Caller passes the already-read $cfg (alert_settings) so we don't re-query it. Returns
 * true iff an email was actually sent. Never throws.
 */
const CONNECTION_ALERT_DEDUP_DAYS = 7;

function send_connection_alert(PDO $pdo, string $itemId, string $code, array $cfg): bool
{
    if (empty($cfg['email_enabled']) || empty($cfg['connection_alert_enabled'])) return false;

    try {
        // Already alerted for this item within the window? Then stay quiet.
        $since = date('Y-m-d', strtotime('-' . CONNECTION_ALERT_DEDUP_DAYS . ' days'));   // app TZ
        $chk = $pdo->prepare(
            "SELECT 1 FROM alert_log
             WHERE alert_type = 'connection' AND alert_key = ? AND sent_on >= ? LIMIT 1"
        );
        $chk->execute([$itemId, $since]);
        if ($chk->fetchColumn()) return false;

        // Claim this crossing (period = today's date, so sent_on advances each real send;
        // INSERT IGNORE dedups a same-day double-fire from cron + webhook).
        $today = date('Y-m-d');
        $pdo->prepare(
            "INSERT IGNORE INTO alert_log (alert_type, alert_key, period, sent_on)
             VALUES ('connection', ?, ?, ?)"
        )->execute([$itemId, $today, $today]);
    } catch (Throwable $e) {
        // If the dedup bookkeeping fails, fall through and still alert — a rare duplicate
        // email beats silently dropping a real "your bank needs re-linking" notice.
        error_log('connection alert dedup failed for ' . $itemId . ': ' . $e->getMessage());
    }

    send_alert('Bank connection needs attention',
        "Plaid reports that a linked bank needs attention (error: $code).\n" .
        "Item: $itemId\nYou may need to re-link this bank in Budget Tracker.");
    return true;
}

/**
 * Clear an item's connection-alert dedup key after a HEALTHY sync (code review 3.3) so a
 * later re-break alerts immediately instead of waiting out the dedup window. Best-effort.
 */
function clear_connection_alert(PDO $pdo, string $itemId): void
{
    try {
        $pdo->prepare("DELETE FROM alert_log WHERE alert_type = 'connection' AND alert_key = ?")
            ->execute([$itemId]);
    } catch (Throwable $e) {
        // best-effort — a stale key just means the next break waits out the window
    }
}

/**
 * Send a multipart (plain-text + HTML) email to the configured alert recipients.
 * Best-effort. Used by the weekly digest (TODO #15) — event alerts keep using the
 * plain-text send_alert() above. Same recipients/from transport from config['alerts'].
 * Subject is CR/LF-stripped + RFC 2047 encoded via mail_subject(); each MIME part
 * declares Content-Transfer-Encoding: 8bit since the bodies carry raw UTF-8.
 */
function send_html_alert(string $subject, string $html, string $text): void
{
    global $CONFIG;
    $to = implode(', ', $CONFIG['alerts']['recipients'] ?? []);
    if ($to === '') return;

    $boundary = 'bt_' . bin2hex(random_bytes(8));

    $headers = 'From: ' . mail_from() . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

    $body = "--$boundary\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $text . "\r\n\r\n"
          . "--$boundary\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $html . "\r\n\r\n"
          . "--$boundary--\r\n";

    @mail($to, mail_subject($subject), $body, $headers);
}

/**
 * Household alert preferences (TODO #14) — the single `alert_settings` row (id=1).
 * This is the ONE real reader; queries.php's q_alert_settings() is a thin wrapper.
 *
 * Defensive by design: any failure (table/row missing, e.g. run before migration
 * 011) returns the defaults, which reproduce the pre-#14 behaviour. NULL
 * large_tx_threshold falls back to config['alerts']['large_tx_threshold'].
 * Returns typed values (bools as bool, threshold as float).
 */
function alert_settings(PDO $pdo): array
{
    global $CONFIG;
    $fallback = (float)($CONFIG['alerts']['large_tx_threshold'] ?? 0);

    $row = false;
    try {
        $row = $pdo->query(
            "SELECT email_enabled, large_tx_enabled, large_tx_threshold,
                    connection_alert_enabled, budget_alert_enabled, budget_alert_pct,
                    unusual_spend_enabled, bill_reminder_enabled, bill_reminder_days,
                    digest_enabled
             FROM alert_settings WHERE id = 1"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // table missing (pre-migration) or transient — fall through to defaults.
    }
    if (!is_array($row)) {
        $row = [];
    }

    $thr = (isset($row['large_tx_threshold']) && $row['large_tx_threshold'] !== null)
        ? (float)$row['large_tx_threshold'] : $fallback;

    return [
        'email_enabled'            => (bool)($row['email_enabled'] ?? 1),
        'large_tx_enabled'         => (bool)($row['large_tx_enabled'] ?? 1),
        'large_tx_threshold'       => $thr,
        'connection_alert_enabled' => (bool)($row['connection_alert_enabled'] ?? 1),
        'budget_alert_enabled'     => (bool)($row['budget_alert_enabled'] ?? 0),
        'budget_alert_pct'         => (int)($row['budget_alert_pct'] ?? 90),
        'unusual_spend_enabled'    => (bool)($row['unusual_spend_enabled'] ?? 0),
        'bill_reminder_enabled'    => (bool)($row['bill_reminder_enabled'] ?? 0),
        'bill_reminder_days'       => (int)($row['bill_reminder_days'] ?? 5),
        'digest_enabled'           => (bool)($row['digest_enabled'] ?? 0),
    ];
}
