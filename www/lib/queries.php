<?php
declare(strict_types=1);

/**
 * Reusable, visibility-scoped read queries shared by all pages.
 *
 * Visibility rule (mostly-joint, some-private): a user sees an account when it
 * is shared OR they own the Item that holds it. Every query below applies this
 * via the VIS clause and a :uid bind.
 */

const VIS = '(a.visibility = "shared" OR i.user_id = :uid)';

/** All accounts visible to $uid, ordered by institution then name. */
function q_accounts(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare(
        "SELECT a.account_id, a.name, a.official_name, a.mask, a.type, a.subtype,
                a.balance_available, a.balance_current, a.balance_limit,
                a.visibility, i.institution_name, i.institution_id,
                i.user_id AS owner_id, i.item_id, i.status AS item_status,
                i.error_code, i.source, i.manual_type
         FROM accounts a JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . "
         ORDER BY i.institution_name, a.name"
    );
    $st->execute([':uid' => $uid]);
    return $st->fetchAll();
}

/** A single visible account (or null). */
function q_account(PDO $pdo, int $uid, string $accountId): ?array
{
    $st = $pdo->prepare(
        "SELECT a.account_id, a.name, a.official_name, a.mask, a.type, a.subtype,
                a.balance_available, a.balance_current, a.balance_limit,
                a.iso_currency_code, a.visibility, a.last_updated_datetime,
                i.institution_name, i.institution_id, i.user_id AS owner_id,
                i.item_id, i.status AS item_status, i.error_code, i.last_synced_at,
                i.source, i.manual_type
         FROM accounts a JOIN items i ON a.item_id = i.item_id
         WHERE a.account_id = :acct AND " . VIS . "
         LIMIT 1"
    );
    $st->execute([':uid' => $uid, ':acct' => $accountId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Net-worth / assets / liabilities / count from an accounts array. */
function q_stats(array $accounts): array
{
    $assets = 0.0; $liabilities = 0.0;
    foreach ($accounts as $a) {
        $bal = (float)($a['balance_current'] ?? 0);
        if (in_array($a['type'], ['credit', 'loan'], true)) {
            $liabilities += $bal;
        } else {
            $assets += $bal;
        }
    }
    return [
        'net_worth'   => round($assets - $liabilities, 2),
        'assets'      => round($assets, 2),
        'liabilities' => round($liabilities, 2),
        'accounts'    => count($accounts),
    ];
}

/** Household net-worth snapshots (oldest first). */
function q_networth(PDO $pdo): array
{
    return $pdo->query(
        "SELECT snapshot_date, net_worth FROM balance_snapshots
         ORDER BY snapshot_date ASC LIMIT 730"
    )->fetchAll();
}

/**
 * Percentage change in net worth vs the snapshot closest to $days ago.
 * Returns ['pct' => float|null, 'abs' => float|null, 'from' => ?float].
 */
function q_networth_change(PDO $pdo, float $current, int $days = 30): array
{
    $st = $pdo->prepare(
        "SELECT net_worth FROM balance_snapshots
         WHERE snapshot_date <= (CURDATE() - INTERVAL :d DAY)
         ORDER BY snapshot_date DESC LIMIT 1"
    );
    $st->bindValue(':d', $days, PDO::PARAM_INT);
    $st->execute();
    $prev = $st->fetchColumn();
    if ($prev === false) {
        // Fall back to the earliest snapshot we have.
        $prev = $pdo->query(
            "SELECT net_worth FROM balance_snapshots ORDER BY snapshot_date ASC LIMIT 1"
        )->fetchColumn();
    }
    if ($prev === false || (float)$prev == 0.0) {
        return ['pct' => null, 'abs' => null, 'from' => null];
    }
    $prev = (float)$prev;
    return [
        'pct'  => round((($current - $prev) / abs($prev)) * 100, 1),
        'abs'  => round($current - $prev, 2),
        'from' => $prev,
    ];
}

/** Spending by category over the last $days days (outflows only). */
function q_spending(PDO $pdo, int $uid, int $days = 30): array
{
    $st = $pdo->prepare(
        "SELECT COALESCE(t.category_override, t.pfc_primary, 'UNCATEGORIZED') AS category,
                SUM(t.amount) AS total
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND t.date >= (CURDATE() - INTERVAL :d DAY)
         GROUP BY category
         ORDER BY total DESC"
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':d', $days, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Total outflow over the last $days days (for headline figures). */
function q_spending_total(PDO $pdo, int $uid, int $days = 30): float
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(t.amount), 0)
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND t.date >= (CURDATE() - INTERVAL :d DAY)"
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':d', $days, PDO::PARAM_INT);
    $st->execute();
    return (float)$st->fetchColumn();
}

/**
 * Visible transactions. Options:
 *   account_id (string), q (search text), limit (int, default 100).
 */
function q_transactions(PDO $pdo, int $uid, array $opts = []): array
{
    $where  = [VIS];
    $params = [':uid' => $uid];
    if (!empty($opts['account_id'])) {
        $where[] = 't.account_id = :acct';
        $params[':acct'] = (string)$opts['account_id'];
    }
    if (!empty($opts['q'])) {
        $where[] = '(t.merchant_name LIKE :q OR t.name LIKE :q
                     OR COALESCE(t.category_override, t.pfc_primary) LIKE :q)';
        $params[':q'] = '%' . $opts['q'] . '%';
    }
    $limit = (int)($opts['limit'] ?? 100);
    $sql = "SELECT t.transaction_id, t.date, t.merchant_name, t.name, t.amount, t.pending,
                   COALESCE(t.category_override, t.pfc_primary) AS category,
                   a.name AS account_name, a.mask, a.account_id
            FROM transactions t
            JOIN accounts a ON t.account_id = a.account_id
            JOIN items i ON a.item_id = i.item_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.date DESC, t.imported_at DESC
            LIMIT " . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Liabilities. Optionally scoped to one account. */
function q_liabilities(PDO $pdo, int $uid, ?string $accountId = null): array
{
    $where  = [VIS];
    $params = [':uid' => $uid];
    if ($accountId !== null) { $where[] = 'l.account_id = :acct'; $params[':acct'] = $accountId; }
    $st = $pdo->prepare(
        "SELECT l.liability_type, l.apr_percentage, l.outstanding_balance, l.last_payment_amount,
                l.last_payment_date, l.next_payment_due_date, l.minimum_payment_amount,
                a.name AS account_name, a.mask, a.balance_current, a.account_id
         FROM liabilities l
         JOIN accounts a ON l.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY a.name"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/** Investment holdings. Optionally scoped to one account. */
function q_holdings(PDO $pdo, int $uid, ?string $accountId = null): array
{
    $where  = [VIS];
    $params = [':uid' => $uid];
    if ($accountId !== null) { $where[] = 'h.account_id = :acct'; $params[':acct'] = $accountId; }
    $st = $pdo->prepare(
        "SELECT s.ticker_symbol, s.name AS security_name, h.quantity, h.institution_price,
                h.institution_value, a.name AS account_name, a.mask, a.account_id
         FROM holdings h
         JOIN accounts a ON h.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         LEFT JOIN securities s ON h.security_id = s.security_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY h.institution_value DESC"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/** Active recurring streams. Optionally scoped to one account. */
function q_recurring(PDO $pdo, int $uid, ?string $accountId = null): array
{
    $where  = [VIS, 'r.is_active = 1'];
    $params = [':uid' => $uid];
    if ($accountId !== null) { $where[] = 'r.account_id = :acct'; $params[':acct'] = $accountId; }
    $st = $pdo->prepare(
        "SELECT r.stream_id, r.direction, r.description, r.merchant_name, r.frequency,
                r.average_amount, r.last_amount, r.last_date, r.is_active, r.category_primary,
                a.name AS account_name, a.mask, a.account_id
         FROM recurring_streams r
         JOIN accounts a ON r.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY r.direction, r.average_amount DESC"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/** Shared budgets with current-month spend (household-wide). */
function q_budgets(PDO $pdo): array
{
    $month = date('Y-m');
    $rows = $pdo->prepare(
        "SELECT COALESCE(t.category_override, t.pfc_primary, 'UNCATEGORIZED') AS category,
                SUM(t.amount) AS spent
         FROM transactions t
         WHERE t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND DATE_FORMAT(t.date, '%Y-%m') = :m
         GROUP BY category"
    );
    $rows->execute([':m' => $month]);
    $spent = [];
    foreach ($rows->fetchAll() as $r) $spent[$r['category']] = (float)$r['spent'];

    $budgets = $pdo->query('SELECT id, category, monthly_limit FROM budgets ORDER BY category')->fetchAll();
    foreach ($budgets as &$b) {
        $b['monthly_limit'] = (float)$b['monthly_limit'];
        $b['spent'] = round($spent[$b['category']] ?? 0, 2);
    }
    return ['month' => $month, 'budgets' => $budgets];
}

/**
 * The canonical Plaid Personal-Finance **primary** categories — the "tag"
 * values stored in `transactions.pfc_primary`. Used to offer budget categories
 * even before any spend exists in them. (INCOME / TRANSFER_IN are inflows and
 * are intentionally excluded from the budget picker by budget_category_options.)
 */
function pfc_primary_categories(): array
{
    return [
        'INCOME', 'TRANSFER_IN', 'TRANSFER_OUT', 'LOAN_PAYMENTS', 'BANK_FEES',
        'ENTERTAINMENT', 'FOOD_AND_DRINK', 'GENERAL_MERCHANDISE', 'HOME_IMPROVEMENT',
        'MEDICAL', 'PERSONAL_CARE', 'GENERAL_SERVICES', 'GOVERNMENT_AND_NON_PROFIT',
        'TRANSPORTATION', 'TRAVEL', 'RENT_AND_UTILITIES',
    ];
}

/**
 * Options for the budget category picker: the canonical Plaid spending
 * categories unioned with any category that actually appears in the household's
 * outflow transactions (covers custom overrides set via recategorize, plus
 * UNCATEGORIZED) — i.e. exactly the labels the user sees on the spending list.
 * Returns [['value' => TAG, 'label' => 'Friendly Name'], ...] sorted by label.
 */
function budget_category_options(PDO $pdo, int $uid): array
{
    // Inflow-only categories make no sense as a spending budget — drop them.
    $cats = array_fill_keys(array_diff(pfc_primary_categories(), ['INCOME', 'TRANSFER_IN']), true);

    $st = $pdo->prepare(
        "SELECT DISTINCT COALESCE(t.category_override, t.pfc_primary, 'UNCATEGORIZED') AS category
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND t.amount > 0 AND t.ext_source IS NULL"
    );
    $st->execute([':uid' => $uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if ((string)$c !== '') $cats[$c] = true;
    }

    $opts = [];
    foreach (array_keys($cats) as $tag) {
        $opts[] = ['value' => $tag, 'label' => pretty_cat($tag)];
    }
    usort($opts, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $opts;
}

/* ---- small presentation helpers (server-side) ---------------------------- */

/** "FOOD_AND_DRINK" -> "Food And Drink". */
function pretty_cat(?string $c): string
{
    $c = (string)$c;
    if ($c === '') return '';
    return ucwords(strtolower(str_replace('_', ' ', $c)));
}

/** Account display label with mask, e.g. "Ally Savings ••7890". */
function account_label(array $a): string
{
    $name = $a['name'] ?: ($a['official_name'] ?: 'Account');
    return $name . ($a['mask'] ? " ••{$a['mask']}" : '');
}

/** Is this account a liability (debt)? */
function is_liability(array $a): bool
{
    return in_array($a['type'] ?? '', ['credit', 'loan'], true);
}

/** Is this a manual (document-updated, non-Plaid) account? */
function is_manual(array $a): bool
{
    return ($a['source'] ?? 'plaid') === 'manual';
}

/**
 * Ingested documents for a manual account (newest first). The caller must have
 * already fetched the account via q_account() (which enforces visibility).
 */
function q_manual_documents(PDO $pdo, string $accountId): array
{
    $st = $pdo->prepare(
        'SELECT id, doc_type, period_key, original_name, byte_size, summary, uploaded_at
         FROM manual_documents WHERE account_id = ?
         ORDER BY uploaded_at DESC'
    );
    $st->execute([$accountId]);
    return $st->fetchAll();
}

/** Yearly 1099 tax summaries for a manual account (newest year first). */
function q_tax_summaries(PDO $pdo, string $accountId): array
{
    $st = $pdo->prepare(
        'SELECT tax_year, ordinary_dividends, qualified_dividends, capital_gain_distributions,
                interest_income, federal_tax_withheld, foreign_tax_paid,
                proceeds, cost_basis, net_gain_loss
         FROM manual_tax_summaries WHERE account_id = ?
         ORDER BY tax_year DESC'
    );
    $st->execute([$accountId]);
    return $st->fetchAll();
}
