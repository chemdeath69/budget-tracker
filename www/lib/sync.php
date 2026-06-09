<?php
declare(strict_types=1);

require_once __DIR__ . '/plaid.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/mailer.php';

/**
 * Sync engine shared by api/exchange.php (initial sync), webhook.php and
 * cron/sync.php. All functions take an open PDO ($pdo).
 */

/** Sync everything for one Item row (assoc: item_id, user_id, access_token_enc, transactions_cursor). */
function sync_item(PDO $pdo, array $item, string $trigger): array
{
    // Per-item advisory lock: stop the exchange-sync, webhook and cron from
    // processing the same Item at once (avoids redundant work + duplicate
    // large-tx alerts). Non-blocking — if a sync is already running, skip.
    $lockName = 'bt_sync_' . substr(hash('sha256', $item['item_id']), 0, 40);
    $got = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 0)')->fetchColumn();
    if ($got !== 1) {
        log_sync($pdo, $item['item_id'], $trigger, 0, 0, 0, true, 'skipped: sync already running');
        return ['ok' => true, 'skipped' => true, 'added' => 0, 'modified' => 0, 'removed' => 0];
    }

    try {
    $token = decrypt_secret($item['access_token_enc']);
    if ($token === null) {
        log_sync($pdo, $item['item_id'], $trigger, 0, 0, 0, false, 'decrypt failed');
        return ['ok' => false, 'error' => 'decrypt failed'];
    }

    $counts = ['added' => 0, 'modified' => 0, 'removed' => 0];
    try {
        // Balances first — upserts accounts rows (transactions FK needs them).
        sync_balances($pdo, $item['item_id'], $token);
        $counts = sync_transactions($pdo, $item, $token);
        // Best-effort extras.
        try { sync_liabilities($pdo, $item['item_id'], $token); } catch (Throwable $e) { if (!plaid_benign($e)) error_log('liab: ' . $e->getMessage()); }
        try { sync_investments($pdo, $item['item_id'], $token); } catch (Throwable $e) { if (!plaid_benign($e)) error_log('invest: ' . $e->getMessage()); }
        try { sync_recurring($pdo, $item['item_id'], $token); } catch (Throwable $e) { if (!plaid_benign($e)) error_log('recurring: ' . $e->getMessage()); }

        $pdo->prepare('UPDATE items SET status="active", error_code=NULL, last_synced_at=NOW() WHERE item_id=?')
            ->execute([$item['item_id']]);
        log_sync($pdo, $item['item_id'], $trigger, $counts['added'], $counts['modified'], $counts['removed'], true, null);
        return ['ok' => true] + $counts;
    } catch (PlaidException $ex) {
        $code = $ex->plaidCode ?? '';
        $pdo->prepare('UPDATE items SET status="error", error_code=? WHERE item_id=?')
            ->execute([$code, $item['item_id']]);
        if (in_array($code, ['ITEM_LOGIN_REQUIRED', 'PENDING_EXPIRATION'], true)) {
            send_alert('Bank connection needs attention',
                "An institution connection requires re-authentication (error: $code).\n" .
                "Item: {$item['item_id']}\nPlease re-link it in Budget Tracker.");
        }
        log_sync($pdo, $item['item_id'], $trigger, 0, 0, 0, false, $ex->getMessage());
        return ['ok' => false, 'error' => $ex->getMessage()];
    }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
    }
}

/**
 * Strip the Unicode replacement char (U+FFFD) that some institutions send in
 * names (e.g. Wells Fargo "WAY2SAVE® SAVINGS" arrives as "WAY2SAVE�� SAVINGS"),
 * then collapse any doubled whitespace left behind.
 */
function clean_name(?string $s): ?string
{
    if ($s === null) return null;
    $s = preg_replace('/\x{FFFD}+/u', '', $s);
    $s = preg_replace('/\s{2,}/u', ' ', (string)$s);
    $s = trim((string)$s);
    return $s === '' ? null : $s;
}

/** /accounts/balance/get -> upsert accounts. */
function sync_balances(PDO $pdo, string $itemId, string $token): void
{
    $res = plaid_call('/accounts/balance/get', ['access_token' => $token]);
    $stmt = $pdo->prepare(
        'INSERT INTO accounts
            (account_id, item_id, name, official_name, mask, type, subtype,
             balance_available, balance_current, balance_limit, iso_currency_code, last_updated_datetime)
         VALUES (:id,:item,:name,:oname,:mask,:type,:subtype,:av,:cur,:lim,:iso,:upd)
         ON DUPLICATE KEY UPDATE
             name=VALUES(name), official_name=VALUES(official_name), mask=VALUES(mask),
             type=VALUES(type), subtype=VALUES(subtype),
             balance_available=VALUES(balance_available), balance_current=VALUES(balance_current),
             balance_limit=VALUES(balance_limit), iso_currency_code=VALUES(iso_currency_code),
             last_updated_datetime=VALUES(last_updated_datetime)'
        // NB: visibility, display_name and retirement_flag are intentionally NOT in
        // the UPDATE list (preserve the owner's choices). The Plaid `name` keeps
        // refreshing underneath the display_name override (migration 009).
    );
    foreach ($res['accounts'] ?? [] as $a) {
        $b = $a['balances'] ?? [];
        $upd = $b['last_updated_datetime'] ?? null;
        $stmt->execute([
            ':id' => $a['account_id'], ':item' => $itemId,
            ':name' => clean_name($a['name'] ?? null), ':oname' => clean_name($a['official_name'] ?? null),
            ':mask' => $a['mask'] ?? null, ':type' => $a['type'] ?? null, ':subtype' => $a['subtype'] ?? null,
            ':av' => $b['available'] ?? null, ':cur' => $b['current'] ?? null, ':lim' => $b['limit'] ?? null,
            ':iso' => $b['iso_currency_code'] ?? 'USD',
            ':upd' => $upd ? date('Y-m-d H:i:s', strtotime($upd)) : null,
        ]);
    }
}

/** /transactions/sync cursor loop -> apply added/modified/removed. */
function sync_transactions(PDO $pdo, array $item, string $token): array
{
    global $CONFIG;
    $cursor = $item['transactions_cursor'] ?: null;
    $firstInit = ($cursor === null);
    $added = 0; $modified = 0; $removed = 0;

    $ins = $pdo->prepare(
        'INSERT INTO transactions
            (transaction_id, account_id, amount, iso_currency_code, date, authorized_date, datetime,
             merchant_name, name, merchant_entity_id, logo_url, pending, pending_transaction_id,
             pfc_primary, pfc_detailed, payment_channel)
         VALUES (:tid,:acct,:amt,:iso,:date,:adate,:dt,:merch,:name,:meid,:logo,:pend,:ptid,:pfc1,:pfc2,:chan)
         ON DUPLICATE KEY UPDATE
             amount=VALUES(amount), iso_currency_code=VALUES(iso_currency_code),
             date=VALUES(date), authorized_date=VALUES(authorized_date), datetime=VALUES(datetime),
             merchant_name=VALUES(merchant_name), name=VALUES(name),
             merchant_entity_id=VALUES(merchant_entity_id), logo_url=VALUES(logo_url),
             pending=VALUES(pending), pending_transaction_id=VALUES(pending_transaction_id),
             pfc_primary=VALUES(pfc_primary), pfc_detailed=VALUES(pfc_detailed),
             payment_channel=VALUES(payment_channel)'
        // category_override and large_tx_alerted are preserved (not in UPDATE list).
    );
    $del = $pdo->prepare('DELETE FROM transactions WHERE transaction_id = ?');

    $threshold = (float)($CONFIG['alerts']['large_tx_threshold'] ?? 0);
    $largeAlerts = [];

    do {
        $body = ['access_token' => $token, 'count' => 500];
        if ($cursor !== null) $body['cursor'] = $cursor;
        if ($firstInit) $body['options'] = ['days_requested' => (int)($CONFIG['plaid']['days_requested'] ?? 730)];

        $res = plaid_call('/transactions/sync', $body);

        foreach ($res['added'] ?? [] as $t) {
            upsert_tx($ins, $t);
            $added++;
            if ($threshold > 0 && (float)$t['amount'] > $threshold && empty($t['pending'])) {
                $largeAlerts[] = $t;
            }
        }
        foreach ($res['modified'] ?? [] as $t) { upsert_tx($ins, $t); $modified++; }
        foreach ($res['removed'] ?? [] as $r) { $del->execute([$r['transaction_id']]); $removed++; }

        $cursor  = $res['next_cursor'] ?? $cursor;
        $hasMore = (bool)($res['has_more'] ?? false);
    } while ($hasMore);

    $pdo->prepare('UPDATE items SET transactions_cursor=? WHERE item_id=?')
        ->execute([$cursor, $item['item_id']]);

    // Large-transaction alerts (skip on first bulk import to avoid a flood).
    if (!$firstInit && $largeAlerts) {
        foreach ($largeAlerts as $t) {
            $chk = $pdo->prepare('SELECT large_tx_alerted FROM transactions WHERE transaction_id=?');
            $chk->execute([$t['transaction_id']]);
            if ((int)$chk->fetchColumn() === 1) continue;
            $merch = $t['merchant_name'] ?? $t['name'] ?? 'Unknown';
            send_alert('Large transaction: ' . $merch,
                sprintf("A transaction of $%.2f posted at %s on %s.", (float)$t['amount'], $merch, $t['date'] ?? ''));
            $pdo->prepare('UPDATE transactions SET large_tx_alerted=1 WHERE transaction_id=?')
                ->execute([$t['transaction_id']]);
        }
    } elseif ($firstInit) {
        // Mark imported large txns as already-alerted so we don't email historical ones later.
        $pdo->prepare('UPDATE transactions t JOIN accounts a ON t.account_id=a.account_id
                       SET t.large_tx_alerted=1 WHERE a.item_id=?')->execute([$item['item_id']]);
    }

    return ['added' => $added, 'modified' => $modified, 'removed' => $removed];
}

function upsert_tx(PDOStatement $ins, array $t): void
{
    $pfc = $t['personal_finance_category'] ?? [];
    $ins->execute([
        ':tid'   => $t['transaction_id'],
        ':acct'  => $t['account_id'],
        ':amt'   => $t['amount'],
        ':iso'   => $t['iso_currency_code'] ?? 'USD',
        ':date'  => $t['date'] ?? null,
        ':adate' => $t['authorized_date'] ?? null,
        ':dt'    => isset($t['datetime']) ? date('Y-m-d H:i:s', strtotime($t['datetime'])) : null,
        ':merch' => $t['merchant_name'] ?? null,
        ':name'  => $t['name'] ?? null,
        ':meid'  => $t['merchant_entity_id'] ?? null,
        ':logo'  => $t['logo_url'] ?? null,
        ':pend'  => !empty($t['pending']) ? 1 : 0,
        ':ptid'  => $t['pending_transaction_id'] ?? null,
        ':pfc1'  => $pfc['primary'] ?? null,
        ':pfc2'  => $pfc['detailed'] ?? null,
        ':chan'  => $t['payment_channel'] ?? null,
    ]);
}

/** /liabilities/get -> upsert liabilities (best-effort; not all items support it). */
function sync_liabilities(PDO $pdo, string $itemId, string $token): void
{
    $res = plaid_call('/liabilities/get', ['access_token' => $token]);
    $liab = $res['liabilities'] ?? [];
    $up = $pdo->prepare(
        'INSERT INTO liabilities
            (account_id, liability_type, apr_percentage, last_payment_amount, last_payment_date,
             next_payment_due_date, minimum_payment_amount, outstanding_balance, origination_principal, raw)
         VALUES (:acct,:type,:apr,:lpa,:lpd,:due,:min,:bal,:orig,:raw)
         ON DUPLICATE KEY UPDATE
             apr_percentage=VALUES(apr_percentage), last_payment_amount=VALUES(last_payment_amount),
             last_payment_date=VALUES(last_payment_date), next_payment_due_date=VALUES(next_payment_due_date),
             minimum_payment_amount=VALUES(minimum_payment_amount), outstanding_balance=VALUES(outstanding_balance),
             origination_principal=VALUES(origination_principal), raw=VALUES(raw)'
    );
    foreach ($liab['credit'] ?? [] as $c) {
        $apr = $c['aprs'][0]['apr_percentage'] ?? null;
        $up->execute([':acct'=>$c['account_id'],':type'=>'credit',':apr'=>$apr,
            ':lpa'=>$c['last_payment_amount']??null,':lpd'=>$c['last_payment_date']??null,
            ':due'=>$c['next_payment_due_date']??null,':min'=>$c['minimum_payment_amount']??null,
            ':bal'=>$c['last_statement_balance']??null,':orig'=>null,':raw'=>json_encode($c)]);
    }
    foreach ($liab['student'] ?? [] as $s) {
        $up->execute([':acct'=>$s['account_id'],':type'=>'student',':apr'=>$s['interest_rate_percentage']??null,
            ':lpa'=>$s['last_payment_amount']??null,':lpd'=>$s['last_payment_date']??null,
            ':due'=>$s['next_payment_due_date']??null,':min'=>$s['minimum_payment_amount']??null,
            ':bal'=>$s['outstanding_interest_amount']??null,':orig'=>$s['origination_principal_amount']??null,':raw'=>json_encode($s)]);
    }
    foreach ($liab['mortgage'] ?? [] as $m) {
        $up->execute([':acct'=>$m['account_id'],':type'=>'mortgage',':apr'=>$m['interest_rate']['percentage']??null,
            ':lpa'=>$m['last_payment_amount']??null,':lpd'=>$m['last_payment_date']??null,
            ':due'=>$m['next_payment_due_date']??null,':min'=>null,
            ':bal'=>$m['outstanding_principal_balance']??null,':orig'=>$m['origination_principal_amount']??null,':raw'=>json_encode($m)]);
    }
}

/** /investments/holdings/get -> upsert securities + holdings (best-effort). */
function sync_investments(PDO $pdo, string $itemId, string $token): void
{
    $res = plaid_call('/investments/holdings/get', ['access_token' => $token]);
    $sec = $pdo->prepare(
        'INSERT INTO securities (security_id, ticker_symbol, name, type, close_price, close_price_date, iso_currency_code)
         VALUES (:id,:tic,:name,:type,:price,:pdate,:iso)
         ON DUPLICATE KEY UPDATE ticker_symbol=VALUES(ticker_symbol), name=VALUES(name), type=VALUES(type),
             close_price=VALUES(close_price), close_price_date=VALUES(close_price_date), iso_currency_code=VALUES(iso_currency_code)'
    );
    foreach ($res['securities'] ?? [] as $s) {
        $sec->execute([':id'=>$s['security_id'],':tic'=>$s['ticker_symbol']??null,':name'=>$s['name']??null,
            ':type'=>$s['type']??null,':price'=>$s['close_price']??null,':pdate'=>$s['close_price_as_of']??null,
            ':iso'=>$s['iso_currency_code']??'USD']);
    }
    $hold = $pdo->prepare(
        'INSERT INTO holdings (account_id, security_id, quantity, cost_basis, institution_price, institution_value, iso_currency_code)
         VALUES (:acct,:sec,:qty,:cost,:price,:val,:iso)
         ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), cost_basis=VALUES(cost_basis),
             institution_price=VALUES(institution_price), institution_value=VALUES(institution_value), iso_currency_code=VALUES(iso_currency_code)'
    );
    foreach ($res['holdings'] ?? [] as $h) {
        $hold->execute([':acct'=>$h['account_id'],':sec'=>$h['security_id'],':qty'=>$h['quantity']??null,
            ':cost'=>$h['cost_basis']??null,':price'=>$h['institution_price']??null,
            ':val'=>$h['institution_value']??null,':iso'=>$h['iso_currency_code']??'USD']);
    }
}

/** /transactions/recurring/get -> upsert recurring_streams (best-effort). */
function sync_recurring(PDO $pdo, string $itemId, string $token): void
{
    // Limit to this item's accounts (endpoint accepts account_ids).
    $ids = $pdo->prepare('SELECT account_id FROM accounts WHERE item_id = ?');
    $ids->execute([$itemId]);
    $accountIds = $ids->fetchAll(PDO::FETCH_COLUMN);
    if (!$accountIds) return;

    $res = plaid_call('/transactions/recurring/get', [
        'access_token' => $token,
        'account_ids'  => $accountIds,
    ]);

    $up = $pdo->prepare(
        'INSERT INTO recurring_streams
            (stream_id, account_id, direction, description, merchant_name, frequency,
             average_amount, last_amount, last_date, is_active, status, category_primary, raw)
         VALUES (:sid,:acct,:dir,:desc,:merch,:freq,:avg,:last,:ldate,:active,:status,:cat,:raw)
         ON DUPLICATE KEY UPDATE
             description=VALUES(description), merchant_name=VALUES(merchant_name),
             frequency=VALUES(frequency), average_amount=VALUES(average_amount),
             last_amount=VALUES(last_amount), last_date=VALUES(last_date),
             is_active=VALUES(is_active), status=VALUES(status),
             category_primary=VALUES(category_primary), raw=VALUES(raw)'
    );
    $store = function (array $s, string $dir) use ($up) {
        $up->execute([
            ':sid'   => $s['stream_id'],
            ':acct'  => $s['account_id'],
            ':dir'   => $dir,
            ':desc'  => $s['description'] ?? null,
            ':merch' => $s['merchant_name'] ?? null,
            ':freq'  => $s['frequency'] ?? null,
            ':avg'   => $s['average_amount']['amount'] ?? null,
            ':last'  => $s['last_amount']['amount'] ?? null,
            ':ldate' => $s['last_date'] ?? null,
            ':active'=> !empty($s['is_active']) ? 1 : 0,
            ':status'=> $s['status'] ?? null,
            ':cat'   => $s['personal_finance_category']['primary'] ?? null,
            ':raw'   => json_encode($s),
        ]);
    };
    foreach ($res['inflow_streams'] ?? [] as $s)  $store($s, 'inflow');
    foreach ($res['outflow_streams'] ?? [] as $s) $store($s, 'outflow');
}

/**
 * Compute household net worth across ALL accounts and upsert today's snapshot (Pacific date).
 * NB: snapshots intentionally cover FINANCIAL ACCOUNTS ONLY — the estimated home value is
 * layered on at read time (q_networth / q_stats / q_networth_change). Do NOT add the home here
 * too, or it would be double-counted.
 */
function write_networth_snapshot(PDO $pdo): void
{
    // 'hidden' accounts are registered nowhere — exclude them from the household total
    // (private accounts still count: the snapshot is the joint household net worth).
    $rows = $pdo->query("SELECT type, balance_current FROM accounts WHERE visibility <> 'hidden'")->fetchAll();
    $assets = 0.0; $liabilities = 0.0;
    foreach ($rows as $r) {
        $bal = (float)($r['balance_current'] ?? 0);
        if (in_array($r['type'], ['credit', 'loan'], true)) $liabilities += $bal;
        else $assets += $bal;
    }
    $net = $assets - $liabilities;
    $pdo->prepare(
        'INSERT INTO balance_snapshots (snapshot_date, total_assets, total_liabilities, net_worth)
         VALUES (:d,:a,:l,:n)
         ON DUPLICATE KEY UPDATE total_assets=VALUES(total_assets),
             total_liabilities=VALUES(total_liabilities), net_worth=VALUES(net_worth)'
    )->execute([':d' => date('Y-m-d'), ':a' => $assets, ':l' => $liabilities, ':n' => $net]);
}

/** True for Plaid errors that are expected/benign for optional products (no consent,
 *  product not supported, no relevant accounts, data not ready) — not worth logging. */
function plaid_benign(Throwable $e): bool
{
    $code = ($e instanceof PlaidException) ? (string)$e->plaidCode : '';
    return in_array($code, [
        'ADDITIONAL_CONSENT_REQUIRED', 'PRODUCTS_NOT_SUPPORTED', 'PRODUCT_NOT_READY',
        'NO_LIABILITY_ACCOUNTS', 'NO_INVESTMENT_ACCOUNTS', 'NO_ACCOUNTS',
    ], true);
}

function log_sync(PDO $pdo, ?string $itemId, string $trigger, int $a, int $m, int $r, bool $ok, ?string $msg): void
{
    $pdo->prepare(
        'INSERT INTO sync_log (item_id, trigger_type, added, modified, removed, ok, message)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$itemId, $trigger, $a, $m, $r, $ok ? 1 : 0, $msg]);
}
