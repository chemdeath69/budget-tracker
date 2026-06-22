<?php
/**
 * FACTORY RESET — ADMIN-ONLY, DESTRUCTIVE, IRREVERSIBLE (migration 032 / Reset redesign).
 *
 * Returns the app to a fresh-install state for the financial data:
 *   1. Revoke every Plaid Item at Plaid (/item/remove) so banks stop syncing + billing.
 *      Benign "already gone" codes count as success; if any other revoke fails the reset
 *      ABORTS and reports them (409 + need_force) — unless the caller passes force:true,
 *      in which case we wipe locally anyway (the admin must then remove those banks in the
 *      Plaid dashboard themselves).
 *   2. Wipe ALL financial tables + financial settings (FK-checks-off DELETE).
 *   3. Clear the stored manual-document files on disk.
 *   4. Re-seed the default single-row settings + write a fresh $0 net-worth snapshot.
 *
 * KEEPS (never touched): users + roles, user_prefs (theme/dashboard), the audit/log
 * tables (access_log, sync_run/sync_run_step, sync_log, webhook_log, alert_log),
 * the notification toggles (alert_settings), and the macro cache (fred_series).
 *
 * Body (JSON): { confirm: "FACTORY RESET", force?: bool }
 * Guarded by: auth + CSRF + is_admin() + the exact confirm phrase.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/plaid.php';
require __DIR__ . '/../lib/crypto.php';
require __DIR__ . '/../lib/sync.php';   // write_networth_snapshot()

header('Content-Type: application/json');

if (!is_logged_in())                       { http_response_code(401); echo json_encode(['error' => 'not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit; }
if (!csrf_check_request())                 { http_response_code(403); echo json_encode(['error' => 'invalid csrf token']); exit; }
if (!is_admin())                           { http_response_code(403); echo json_encode(['error' => 'administrators only']); exit; }

$pdo   = db();
$uid   = (int)current_user_id();
$in    = json_decode(file_get_contents('php://input'), true) ?: [];
$force = !empty($in['force']);

if (trim((string)($in['confirm'] ?? '')) !== 'FACTORY RESET') {
    http_response_code(422);
    echo json_encode(['error' => 'Type FACTORY RESET exactly to confirm.']);
    exit;
}

access_log_action($pdo, $uid, 'factory_reset', $force ? 'start_force' : 'start', null);   // audit (best-effort)

// 1) Revoke every Plaid Item at Plaid. Benign codes = success; others collected.
$revoked = 0;
$failed  = [];
try {
    $items = $pdo->query("SELECT item_id, source, access_token_enc, institution_name FROM items")->fetchAll();
} catch (Throwable $e) {
    $items = [];
}
foreach ($items as $it) {
    if (($it['source'] ?? 'plaid') !== 'plaid') continue;   // manual items have no token
    $label = (string)($it['institution_name'] ?: $it['item_id']);
    $token = $it['access_token_enc'] !== null ? decrypt_secret($it['access_token_enc']) : null;
    if (!$token) { $revoked++; continue; }
    try {
        plaid_item_remove($token);
        $revoked++;
    } catch (PlaidException $e) {
        $code = $e->plaidCode ?? '';
        if (in_array($code, ['ITEM_NOT_FOUND', 'INVALID_ACCESS_TOKEN'], true)) {
            $revoked++;   // already gone at Plaid
        } else {
            error_log('factory_reset: plaid /item/remove failed for ' . $it['item_id'] . ' — ' . $e->getMessage());
            $failed[] = $label;
        }
    } catch (Throwable $e) {
        error_log('factory_reset: plaid /item/remove transport error for ' . $it['item_id'] . ' — ' . $e->getMessage());
        $failed[] = $label;
    }
}
if ($failed && !$force) {
    http_response_code(409);
    echo json_encode([
        'ok'         => false,
        'need_force' => true,
        'failed'     => $failed,
        'error'      => 'Some banks could not be revoked at Plaid.',
    ]);
    exit;
}

// 2) Wipe all financial data + financial settings. Audit/user/log/pref tables are KEPT.
$wipe = [
    'items', 'accounts', 'transactions', 'liabilities', 'securities', 'holdings',
    'investment_transactions', 'security_prices', 'security_dividends',
    'home_config', 'home_values', 'property_facts', 'market_stats', 'account_balance_history',
    'recurring_streams', 'budgets', 'balance_snapshots',
    'manual_documents', 'manual_tax_summaries', 'retirement_statements', 'retirement_settings',
    'spending_plan', 'allocation_targets', 'security_asset_class', 'security_expense_ratio',
    'vehicle_assets', 'tags', 'transaction_tags', 'transaction_splits', 'refund_watch',
    'category_rules', 'custom_categories', 'goals',
    'credit_reports', 'credit_tradelines', 'credit_inquiries', 'credit_flags',
    'api_usage',
];
$cleared = 0;
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($wipe as $t) {
        try { $pdo->exec("DELETE FROM `{$t}`"); $cleared++; }
        catch (Throwable $e) { /* table may not exist on an older DB — skip */ }
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// Re-seed the default single-row settings (matches the migration seeds → a clean baseline).
try { $pdo->exec('INSERT INTO retirement_settings (id) VALUES (1)'); } catch (Throwable $e) {}
try { $pdo->exec('INSERT INTO spending_plan (id) VALUES (1)'); }       catch (Throwable $e) {}

// 3) Clear stored manual-document files on disk (best-effort).
$filesDeleted = 0;
$dir = $CONFIG['storage']['manual_dir'] ?? '';
if ($dir !== '' && is_dir($dir)) {
    foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $f) {
        if (is_file($f) && @unlink($f)) $filesDeleted++;
    }
}

// 4) Fresh $0 net-worth snapshot.
try { write_networth_snapshot($pdo); } catch (Throwable $e) {
    error_log('factory_reset: snapshot rewrite failed — ' . $e->getMessage());
}

access_log_action($pdo, $uid, 'factory_reset', 'complete',
    "revoked={$revoked} cleared={$cleared} files={$filesDeleted}");   // audit (best-effort)

echo json_encode([
    'ok'             => true,
    'revoked'        => $revoked,
    'failed'         => $failed,
    'tables_cleared' => $cleared,
    'files_deleted'  => $filesDeleted,
]);
