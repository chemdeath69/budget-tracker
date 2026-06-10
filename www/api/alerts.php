<?php
// On-demand save of household alert preferences (TODO #14). POST JSON, auth+CSRF.
// Household-wide — NO owner check; alert settings are shared by both users (like
// budgets / refresh). Upserts the single alert_settings row (id=1). The settings
// UI lives on settings.php; reads go through q_alert_settings()/alert_settings().
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';

header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid csrf token']);
    exit;
}

try {
    $pdo = db();
    $uid = current_user_id();
    $in  = json_decode(file_get_contents('php://input'), true) ?: [];

    $bool = static fn($v) => !empty($v) ? 1 : 0;
    $clamp = static fn($v, $lo, $hi) => max($lo, min($hi, (int)$v));

    // Threshold: blank/missing/negative → NULL (fall back to config default).
    $thrRaw = $in['large_tx_threshold'] ?? null;
    $threshold = ($thrRaw === null || $thrRaw === '' || (float)$thrRaw < 0)
        ? null : round((float)$thrRaw, 2);

    $pdo->prepare(
        'INSERT INTO alert_settings
            (id, email_enabled, large_tx_enabled, large_tx_threshold, connection_alert_enabled,
             budget_alert_enabled, budget_alert_pct, unusual_spend_enabled,
             bill_reminder_enabled, bill_reminder_days, digest_enabled, updated_by)
         VALUES (1,:em,:lte,:thr,:con,:ba,:bap,:us,:br,:brd,:dig,:by)
         ON DUPLICATE KEY UPDATE
            email_enabled=VALUES(email_enabled), large_tx_enabled=VALUES(large_tx_enabled),
            large_tx_threshold=VALUES(large_tx_threshold),
            connection_alert_enabled=VALUES(connection_alert_enabled),
            budget_alert_enabled=VALUES(budget_alert_enabled), budget_alert_pct=VALUES(budget_alert_pct),
            unusual_spend_enabled=VALUES(unusual_spend_enabled),
            bill_reminder_enabled=VALUES(bill_reminder_enabled), bill_reminder_days=VALUES(bill_reminder_days),
            digest_enabled=VALUES(digest_enabled), updated_by=VALUES(updated_by),
            updated_at=CURRENT_TIMESTAMP'
    )->execute([
        ':em'  => $bool($in['email_enabled'] ?? 0),
        ':lte' => $bool($in['large_tx_enabled'] ?? 0),
        ':thr' => $threshold,
        ':con' => $bool($in['connection_alert_enabled'] ?? 0),
        ':ba'  => $bool($in['budget_alert_enabled'] ?? 0),
        ':bap' => $clamp($in['budget_alert_pct'] ?? 90, 1, 100),
        ':us'  => $bool($in['unusual_spend_enabled'] ?? 0),
        ':br'  => $bool($in['bill_reminder_enabled'] ?? 0),
        ':brd' => $clamp($in['bill_reminder_days'] ?? 5, 1, 60),
        ':dig' => $bool($in['digest_enabled'] ?? 0),
        ':by'  => $uid,
    ]);

    echo json_encode(['ok' => true, 'settings' => q_alert_settings($pdo)]);
} catch (Throwable $e) {
    error_log('api/alerts.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'could not save settings']);
}
