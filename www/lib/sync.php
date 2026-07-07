<?php
declare(strict_types=1);

require_once __DIR__ . '/plaid.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/mailer.php';

/**
 * Sync engine shared by api/exchange.php (initial sync), webhook.php and
 * cron/sync.php. All functions take an open PDO ($pdo).
 */

/**
 * Sync everything for one Item row (assoc: item_id, user_id, access_token_enc, transactions_cursor).
 * $isReentry is internal (code review 3.4) — true on the single self-retry pass, to bound it.
 */
function sync_item(PDO $pdo, array $item, string $trigger, bool $isReentry = false): array
{
    // Per-item advisory lock: stop the exchange-sync, webhook and cron from
    // processing the same Item at once (avoids redundant work + duplicate
    // large-tx alerts). Non-blocking — if a sync is already running, skip.
    $lockName = 'bt_sync_' . substr(hash('sha256', $item['item_id']), 0, 40);
    $got = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 0)')->fetchColumn();
    if ($got !== 1) {
        // Another sync holds the lock. Flag that fresh work is pending (code review 3.4) so
        // the holder does one more pass after releasing — otherwise a webhook-announced
        // update arriving right now is acked and silently DROPPED for up to ~24h. The
        // nightly cron also sweeps any flag left set. Best-effort (a failed flag just falls
        // back to the cron sweep / next webhook).
        try { $pdo->prepare('UPDATE items SET resync_pending = 1 WHERE item_id = ?')->execute([$item['item_id']]); }
        catch (Throwable $e) { /* flag is best-effort */ }
        log_sync($pdo, $item['item_id'], $trigger, 0, 0, 0, true, 'skipped: sync already running (resync queued)');
        return ['ok' => true, 'skipped' => true, 'added' => 0, 'modified' => 0, 'removed' => 0];
    }

    // We hold the lock. Clear the pending flag: THIS pass satisfies anyone who set it before
    // we acquired the lock; anyone who sets it AFTER (while we run) trips the re-entry below.
    try { $pdo->prepare('UPDATE items SET resync_pending = 0 WHERE item_id = ?')->execute([$item['item_id']]); }
    catch (Throwable $e) { /* best-effort */ }

    $result = null;
    try {
        $token = decrypt_secret($item['access_token_enc']);
        if ($token === null) {
            log_sync($pdo, $item['item_id'], $trigger, 0, 0, 0, false, 'decrypt failed');
            return ['ok' => false, 'error' => 'decrypt failed'];   // finally still releases the lock
        }

        // Household alert prefs (TODO #14) — read once; used for the large-tx threshold
        // (passed into sync_transactions) and to gate the connection-broken alert below.
        $alertCfg = alert_settings($pdo);

        $counts = ['added' => 0, 'modified' => 0, 'removed' => 0];
        try {
            // Balances first — upserts accounts rows (transactions FK needs them).
            sync_balances($pdo, $item['item_id'], $token);
            // code review 3.7: a mid-pagination mutation is Plaid's EXPECTED race marker,
            // not a broken item — retry the cursor loop ONCE from the last saved cursor
            // (nothing is persisted until it completes) before letting it mark the item.
            try {
                $counts = sync_transactions($pdo, $item, $token, $alertCfg);
            } catch (PlaidException $pe) {
                if (($pe->plaidCode ?? '') === 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION') {
                    error_log('sync: mutation during pagination for ' . $item['item_id'] . ' — retrying once');
                    $counts = sync_transactions($pdo, $item, $token, $alertCfg);
                } else {
                    throw $pe;
                }
            }
            // Best-effort extras.
            try { sync_liabilities($pdo, $item['item_id'], $token); } catch (Throwable $e) { if (!plaid_benign($e)) error_log('liab: ' . $e->getMessage()); }
            try { sync_investments($pdo, $item['item_id'], $token); } catch (Throwable $e) { if (!plaid_benign($e)) error_log('invest: ' . $e->getMessage()); }
            try { sync_investment_transactions($pdo, $item, $token); } catch (Throwable $e) { if (!plaid_benign($e)) error_log('invest_tx: ' . $e->getMessage()); }
            try { sync_recurring($pdo, $item['item_id'], $token); } catch (Throwable $e) { if (!plaid_benign($e)) error_log('recurring: ' . $e->getMessage()); }

            $pdo->prepare('UPDATE items SET status="active", error_code=NULL, last_synced_at=NOW() WHERE item_id=?')
                ->execute([$item['item_id']]);
            // Healthy again — clear the connection-alert dedup key so a later break re-alerts
            // immediately instead of waiting out the window (code review 3.3).
            clear_connection_alert($pdo, (string)$item['item_id']);
            log_sync($pdo, $item['item_id'], $trigger, $counts['added'], $counts['modified'], $counts['removed'], true, null);
            $result = ['ok' => true] + $counts;
        } catch (PlaidException $ex) {
            $code = $ex->plaidCode ?? '';
            $pdo->prepare('UPDATE items SET status="error", error_code=? WHERE item_id=?')
                ->execute([$code, $item['item_id']]);
            // Route through the deduped helper (code review 3.3) so a broken bank doesn't
            // email on every nightly sync + every webhook retry until re-linked.
            if (in_array($code, ['ITEM_LOGIN_REQUIRED', 'PENDING_EXPIRATION'], true)) {
                send_connection_alert($pdo, (string)$item['item_id'], $code, $alertCfg);
            }
            log_sync($pdo, $item['item_id'], $trigger, 0, 0, 0, false, $ex->getMessage());
            $result = ['ok' => false, 'error' => $ex->getMessage()];
        } catch (Throwable $ex) {
            // Non-Plaid failure (e.g. a transient PDOException — deadlock, FK race,
            // "server has gone away"). Don't mark the Item broken (that status is for
            // re-link prompts); just log and bail THIS item so the cron loop and its
            // post-loop snapshot/price/home-value steps still run for everything else.
            log_sync($pdo, $item['item_id'], $trigger, 0, 0, 0, false, $ex->getMessage());
            $result = ['ok' => false, 'error' => $ex->getMessage()];
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
    }

    // code review 3.4 — single retry: if a concurrent caller announced fresh work while we
    // held the lock, do exactly ONE more pass (resuming from the freshly-advanced cursor).
    // $isReentry bounds it to one; the cron sweep is the backstop for anything set during
    // the re-entry itself.
    if (!$isReentry && !empty($result['ok']) && empty($result['skipped'])) {
        try {
            $pend = $pdo->prepare('SELECT transactions_cursor, resync_pending FROM items WHERE item_id = ?');
            $pend->execute([$item['item_id']]);
            $row = $pend->fetch();
            if ($row && (int)$row['resync_pending'] === 1) {
                $item['transactions_cursor'] = $row['transactions_cursor'];   // resume where this pass left off
                return sync_item($pdo, $item, $trigger, true);
            }
        } catch (Throwable $e) { /* the cron sweep will catch it */ }
    }
    return $result;
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
    $seen = [];
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
        if (isset($a['account_id'])) $seen[] = $a['account_id'];
    }

    // Reconcile which accounts this Item still reports (code review 5.9). An account that
    // DROPS OUT of /accounts/balance/get (closed at the bank, product access lost) keeps a
    // frozen balance in net worth forever unless we notice. Stamp `missing_since` the first
    // time it's absent; CLEAR it the moment it comes back. We only SURFACE stale-missing
    // accounts on settings.php (honest-number — never auto-hide), so the balance still counts
    // until the owner decides. Skip when the item returned NO accounts (likely a transient
    // product error — don't mass-stamp). Best-effort + column-guarded (migration 034).
    if ($seen) {
        try {
            $ph = implode(',', array_fill(0, count($seen), '?'));
            $pdo->prepare("UPDATE accounts SET missing_since = NULL
                           WHERE item_id = ? AND missing_since IS NOT NULL AND account_id IN ($ph)")
                ->execute(array_merge([$itemId], $seen));
            $pdo->prepare("UPDATE accounts SET missing_since = NOW()
                           WHERE item_id = ? AND missing_since IS NULL AND account_id NOT IN ($ph)")
                ->execute(array_merge([$itemId], $seen));
        } catch (Throwable $e) {
            error_log('sync_balances missing_since reconcile failed for ' . $itemId . ': ' . $e->getMessage());
        }
    }
}

/** /transactions/sync cursor loop -> apply added/modified/removed. */
function sync_transactions(PDO $pdo, array $item, string $token, ?array $alertCfg = null): array
{
    global $CONFIG;
    $alertCfg = $alertCfg ?? alert_settings($pdo);
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

    // Large-tx threshold from household alert prefs (TODO #14). 0 disables the alert
    // (the loop below only collects when $threshold > 0), so a flipped-off master or
    // large-tx switch silences it without any other code change.
    $threshold = ($alertCfg['email_enabled'] && $alertCfg['large_tx_enabled'])
        ? (float)$alertCfg['large_tx_threshold'] : 0.0;
    $largeAlerts = [];

    $guard = 0;
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

        $hasMore = (bool)($res['has_more'] ?? false);
        // code review 3.6: Plaid always returns next_cursor; its absence is anomalous.
        // The old `?? $cursor` re-sent the SAME cursor → an infinite spin. Stop instead and
        // keep the last good cursor (upserts are idempotent; the next sync resumes cleanly).
        if (!isset($res['next_cursor'])) {
            error_log('sync_transactions: missing next_cursor for ' . $item['item_id']
                      . ' (has_more=' . ($hasMore ? '1' : '0') . ') — stopping');
            break;
        }
        $cursor = $res['next_cursor'];
    } while ($hasMore && ++$guard < TRANSACTIONS_SYNC_MAX_PAGES);   // 3.6: hard page cap (200×500 = 100k tx)
    if ($hasMore && $guard >= TRANSACTIONS_SYNC_MAX_PAGES) {
        error_log('sync_transactions: hit ' . TRANSACTIONS_SYNC_MAX_PAGES . '-page cap for '
                  . $item['item_id'] . ' — cursor persisted, resumes next sync');
    }

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

/**
 * /investments/transactions/get -> upsert securities + investment_transactions rows
 * for a Plaid brokerage (best-effort; benign for items with no investments product).
 *
 * Unlike /transactions/sync there is NO cursor — the endpoint is a date-windowed,
 * OFFSET-paginated pull. We re-pull a bounded trailing window each sync and UPSERT
 * (PK = Plaid investment_transaction_id), so re-runs are idempotent. The window is
 * wide (INV_TX_BACKFILL_DAYS) the first time we see this item (no plaid rows yet),
 * then narrow (INV_TX_WINDOW_DAYS) thereafter. Plaid caps the range at 24 months.
 *
 * Storage maps onto the shared investment_transactions table (migration 018):
 *   side = buy/sell for trades, NULL otherwise; type/subtype/name carry the rest.
 * Cost basis is NOT derived here — Plaid holdings already carry it (sync_investments).
 */
function sync_investment_transactions(PDO $pdo, array $item, string $token): void
{
    $itemId = $item['item_id'];

    // First Plaid pull for this item? (No plaid invest-tx rows under its accounts yet.)
    $seen = $pdo->prepare(
        "SELECT COUNT(*) FROM investment_transactions it
         JOIN accounts a ON it.account_id = a.account_id
         WHERE a.item_id = ? AND it.ext_source = 'plaid'"
    );
    $seen->execute([$itemId]);
    $firstPull = ((int)$seen->fetchColumn() === 0);

    $days  = $firstPull ? INV_TX_BACKFILL_DAYS : INV_TX_WINDOW_DAYS;
    $end   = date('Y-m-d');                              // PHP app TZ (never CURDATE())
    $start = date('Y-m-d', strtotime("-{$days} days"));

    $secUp = $pdo->prepare(
        'INSERT INTO securities (security_id, ticker_symbol, name, type, close_price, close_price_date, iso_currency_code)
         VALUES (:id,:tic,:name,:type,:price,:pdate,:iso)
         ON DUPLICATE KEY UPDATE ticker_symbol=VALUES(ticker_symbol), name=VALUES(name), type=VALUES(type),
             close_price=VALUES(close_price), close_price_date=VALUES(close_price_date), iso_currency_code=VALUES(iso_currency_code)'
    );
    $txUp = $pdo->prepare(
        'INSERT INTO investment_transactions
            (inv_tx_id, account_id, security_id, side, type, subtype, name,
             quantity, price, fees, amount, trade_date, ext_source, ext_period)
         VALUES (:id,:acct,:sec,:side,:type,:subtype,:name,:qty,:price,:fees,:amt,:tdate,\'plaid\',NULL)
         ON DUPLICATE KEY UPDATE
             account_id=VALUES(account_id), security_id=VALUES(security_id), side=VALUES(side),
             type=VALUES(type), subtype=VALUES(subtype), name=VALUES(name), quantity=VALUES(quantity),
             price=VALUES(price), fees=VALUES(fees), amount=VALUES(amount), trade_date=VALUES(trade_date)'
    );

    $offset = 0;
    $total  = null;
    $guard  = 0;
    do {
        $res = plaid_call('/investments/transactions/get', [
            'access_token' => $token,
            'start_date'   => $start,
            'end_date'     => $end,
            'options'      => ['count' => 500, 'offset' => $offset],
        ]);

        foreach ($res['securities'] ?? [] as $s) {
            $secUp->execute([':id'=>$s['security_id'],':tic'=>$s['ticker_symbol']??null,':name'=>$s['name']??null,
                ':type'=>$s['type']??null,':price'=>$s['close_price']??null,':pdate'=>$s['close_price_as_of']??null,
                ':iso'=>$s['iso_currency_code']??'USD']);
        }

        $batch = $res['investment_transactions'] ?? [];
        foreach ($batch as $t) {
            $type  = $t['type'] ?? null;
            $side  = ($type === 'buy' || $type === 'sell') ? $type : null;
            $qty   = $t['quantity'] ?? null;
            $price = $t['price'] ?? null;
            // Skip rows missing a stable id / account / security (no usable row).
            if (empty($t['investment_transaction_id']) || empty($t['account_id']) || empty($t['security_id'])) continue;
            // A buy/sell MUST carry quantity + price — skip a malformed trade rather than
            // store a corrupt 0-lot (the columns are NOT NULL; 0 qty/price would lie about
            // the trade). Non-trade cash rows (dividend/interest/contribution/fee)
            // legitimately have qty/price 0, so they keep the `?? 0` fallback below.
            if ($side !== null && ($qty === null || $price === null)) continue;
            $txUp->execute([
                ':id'      => $t['investment_transaction_id'],
                ':acct'    => $t['account_id'],
                ':sec'     => $t['security_id'],
                ':side'    => $side,
                ':type'    => $type,
                ':subtype' => $t['subtype'] ?? null,
                ':name'    => clean_name($t['name'] ?? null),
                ':qty'     => $qty ?? 0,
                ':price'   => $price ?? 0,
                ':fees'    => $t['fees'] ?? 0,
                ':amt'     => $t['amount'] ?? null,
                ':tdate'   => $t['date'] ?? $end,
            ]);
        }

        $total   = $total ?? (int)($res['total_investment_transactions'] ?? 0);
        $offset += count($batch);
        // Stop when we've covered the reported total, an empty page, or a sanity cap.
    } while ($batch && $offset < $total && ++$guard < 200);
}

/** Hard page cap for the /transactions/sync cursor loop (code review 3.6) — mirrors the
 *  ++$guard<200 cap on the investment-tx loop. 200 pages × 500 = 100k tx per run; if hit,
 *  the cursor is persisted and the next sync resumes (no data loss, just bounded). */
const TRANSACTIONS_SYNC_MAX_PAGES = 200;

/** First Plaid invest-tx pull window (≤ Plaid's 24-month cap) and the incremental window. */
const INV_TX_BACKFILL_DAYS = 720;
const INV_TX_WINDOW_DAYS   = 120;

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
    $seen = [];
    $store = function (array $s, string $dir) use ($up, &$seen) {
        $seen[] = $s['stream_id'];
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

    // Reconcile: Plaid's response is the AUTHORITATIVE current set of streams for these
    // accounts. Plaid periodically RE-ISSUES a stream's id — the old id simply stops
    // appearing in the response (it is NOT returned again as is_active=false), so an
    // upsert-only sync would leave the superseded stream lingering at is_active=1 forever
    // and recurring.php / bills.php would show it as a duplicate of its replacement.
    // Deactivate any active stream of THESE accounts that was absent from this response.
    // (Positional placeholders — no named-placeholder reuse, so HY093-safe with native
    //  prepares; account_ids are scoped to this item so other items are untouched.)
    $acctPh = implode(',', array_fill(0, count($accountIds), '?'));
    if ($seen) {
        $seenPh = implode(',', array_fill(0, count($seen), '?'));
        $pdo->prepare(
            "UPDATE recurring_streams SET is_active = 0
              WHERE account_id IN ($acctPh) AND is_active = 1
                AND stream_id NOT IN ($seenPh)"
        )->execute(array_merge($accountIds, $seen));
    } else {
        // Plaid returned no streams at all for these accounts → none are current.
        $pdo->prepare(
            "UPDATE recurring_streams SET is_active = 0 WHERE account_id IN ($acctPh) AND is_active = 1"
        )->execute($accountIds);
    }
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

/**
 * Snapshot an Item + its accounts into `archived_items` (migration 033) BEFORE the Item
 * is deleted, so its Plaid item_id, the (still-encrypted) access_token, the institution
 * and the account metadata are retained FOREVER — for support/audit. Our destroy paths
 * (api/unlink.php, api/factory_reset.php) purge the live rows, and Plaid offers no way to
 * recover an item_id or token afterward (no list-items API, no token-from-item_id), so
 * without this the connection would be unrecoverable for a future Plaid support ticket.
 *
 * Best-effort: an archive failure must NEVER block the actual removal (the caller proceeds
 * regardless). The token is copied verbatim (still encrypted) and never decrypted here; it
 * is dead anyway once /item/remove has run. Returns true if a row was written.
 */
function archive_item(PDO $pdo, string $itemId, string $reason, ?int $archivedBy, bool $plaidRemoved): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT item_id, user_id, source, manual_type, institution_id, institution_name,
                    access_token_enc, status, error_code, consent_expiration, created_at, last_synced_at
             FROM items WHERE item_id = ?'
        );
        $st->execute([$itemId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it) return false;

        $ac = $pdo->prepare(
            'SELECT account_id, name, display_name, official_name, mask, type, subtype,
                    balance_current, balance_available, balance_limit, iso_currency_code, visibility
             FROM accounts WHERE item_id = ?'
        );
        $ac->execute([$itemId]);
        $accounts = $ac->fetchAll(PDO::FETCH_ASSOC);

        $ins = $pdo->prepare(
            'INSERT INTO archived_items
                (item_id, user_id, source, manual_type, institution_id, institution_name,
                 access_token_enc, status, error_code, consent_expiration, item_created_at,
                 last_synced_at, account_count, accounts_json, archive_reason, plaid_removed, archived_by)
             VALUES (:item,:uid,:src,:mtype,:iid,:iname,:tok,:status,:ecode,:consent,:icreated,
                     :lsync,:acount,:accounts,:reason,:premoved,:by)'
        );
        $ins->execute([
            ':item'     => $it['item_id'],
            ':uid'      => $it['user_id'],
            ':src'      => $it['source'],
            ':mtype'    => $it['manual_type'],
            ':iid'      => $it['institution_id'],
            ':iname'    => $it['institution_name'],
            ':tok'      => $it['access_token_enc'],
            ':status'   => $it['status'],
            ':ecode'    => $it['error_code'],
            ':consent'  => $it['consent_expiration'],
            ':icreated' => $it['created_at'],
            ':lsync'    => $it['last_synced_at'],
            ':acount'   => count($accounts),
            ':accounts' => json_encode($accounts),
            ':reason'   => $reason,
            ':premoved' => $plaidRemoved ? 1 : 0,
            ':by'       => $archivedBy,
        ]);
        return true;
    } catch (Throwable $e) {
        // Never block a removal because the archive write failed.
        error_log('archive_item failed for ' . $itemId . ': ' . $e->getMessage());
        return false;
    }
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
