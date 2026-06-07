<?php
declare(strict_types=1);

/** Send a plain-text alert email to the configured recipients. Best-effort. */
function send_alert(string $subject, string $body): void
{
    global $CONFIG;
    $to   = implode(', ', $CONFIG['alerts']['recipients'] ?? []);
    if ($to === '') return;
    $from = $CONFIG['alerts']['from'] ?? 'budget@example.com';

    $headers = "From: Budget Tracker <$from>\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    @mail($to, '[Budget Tracker] ' . $subject, $body, $headers);
}
