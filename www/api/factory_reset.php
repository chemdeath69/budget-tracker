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
 * the notification toggles (alert_settings), the macro cache (fred_series), and the
 * permanent removed-Item archive (archived_items — every wiped Item is snapshotted into
 * it FIRST so its item_id / token / account metadata survive for support/audit).
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
$revoked    = 0;
$revokedIds = [];
$failed     = [];
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
        $revoked++; $revokedIds[] = $it['item_id'];
    } catch (PlaidException $e) {
        $code = $e->plaidCode ?? '';
        if (in_array($code, ['ITEM_NOT_FOUND', 'INVALID_ACCESS_TOKEN'], true)) {
            $revoked++; $revokedIds[] = $it['item_id'];   // already gone at Plaid
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

// 1b) Archive EVERY Item (plaid + manual) into archived_items (migration 033) BEFORE the
//     wipe, so the item_ids / (encrypted) tokens / institution + account metadata are
//     retained forever for support/audit. Best-effort per item (never blocks the reset).
$archived = 0;
foreach ($items as $it) {
    if (archive_item($pdo, $it['item_id'], 'factory_reset', $uid,
            in_array($it['item_id'], $revokedIds, true))) {
        $archived++;
    }
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
$cleared    = 0;
$wipeErrors = [];
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($wipe as $t) {
        try { $pdo->exec("DELETE FROM `{$t}`"); $cleared++; }
        catch (PDOException $e) {
            // Distinguish a genuinely-absent table (older DB — fine to skip) from a REAL
            // failure like a lock-wait timeout or permission error, which must NOT be
            // swallowed into a false ok:true partial wipe (code review 3.5). 1146 =
            // ER_NO_SUCH_TABLE (SQLSTATE 42S02).
            $errno = (int)($e->errorInfo[1] ?? 0);
            if ($errno === 1146 || $e->getCode() === '42S02') continue;
            $wipeErrors[] = $t . ': ' . $e->getMessage();
            error_log('factory_reset: wipe failed for ' . $t . ' — ' . $e->getMessage());
        }
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');   // always restore FK enforcement
}

// Re-seed the default single-row settings (matches the migration seeds → a clean baseline).
try { $pdo->exec('INSERT INTO retirement_settings (id) VALUES (1)'); } catch (Throwable $e) {}
try { $pdo->exec('INSERT INTO spending_plan (id) VALUES (1)'); }       catch (Throwable $e) {}

// 3) Clear stored manual-document files on disk (best-effort). RECURSIVE (code review
//    3.5 / 5.8) — the old top-level glob left files in per-account subdirectories behind.
$filesDeleted = 0;
$dir = $CONFIG['storage']['manual_dir'] ?? '';
if ($dir !== '' && is_dir($dir)) {
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) { @rmdir($f->getPathname()); }               // remove now-empty subdirs
            elseif (@unlink($f->getPathname())) { $filesDeleted++; }       // files + symlinks
        }
    } catch (Throwable $e) {
        error_log('factory_reset: storage cleanup error — ' . $e->getMessage());
    }
}

// 4) Fresh $0 net-worth snapshot.
try { write_networth_snapshot($pdo); } catch (Throwable $e) {
    error_log('factory_reset: snapshot rewrite failed — ' . $e->getMessage());
}

$ok = empty($wipeErrors);
access_log_action($pdo, $uid, 'factory_reset', $ok ? 'complete' : 'complete_with_errors',
    "revoked={$revoked} archived={$archived} cleared={$cleared} files={$filesDeleted} errors=" . count($wipeErrors));   // audit (best-effort)

if (!$ok) http_response_code(500);
echo json_encode([
    'ok'             => $ok,
    'revoked'        => $revoked,
    'archived'       => $archived,
    'failed'         => $failed,
    'tables_cleared' => $cleared,
    'files_deleted'  => $filesDeleted,
    'errors'         => $wipeErrors,   // real (non-"table missing") wipe failures — a partial reset (3.5)
    'error'          => $ok ? null : ('Reset incomplete — ' . count($wipeErrors) . ' table(s) failed to clear: ' . implode('; ', $wipeErrors)),
]);
