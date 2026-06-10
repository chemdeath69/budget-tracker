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

/** Send a plain-text alert email to the configured recipients. Best-effort. */
function send_alert(string $subject, string $body): void
{
    global $CONFIG;
    $to   = implode(', ', $CONFIG['alerts']['recipients'] ?? []);
    if ($to === '') return;
    $from = $CONFIG['alerts']['from'] ?? 'budget@example.com';

    $headers = "From: Budget Tracker <$from>\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";   // bodies may carry UTF-8 (names, symbols)
    @mail($to, mail_subject($subject), $body, $headers);
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
    $from = $CONFIG['alerts']['from'] ?? 'budget@example.com';

    $boundary = 'bt_' . bin2hex(random_bytes(8));

    $headers = "From: Budget Tracker <$from>\r\n"
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
