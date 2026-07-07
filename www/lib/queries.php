<?php
declare(strict_types=1);

/**
 * Reusable, visibility-scoped read queries shared by all pages.
 *
 * Visibility rule (mostly-joint, some-private): a user sees an account when it
 * is shared OR they own the Item that holds it. Every query below applies this
 * via the VIS clause and a :uid bind.
 *
 * A third state, 'hidden' (migration 008), is excluded from EVERY read here for
 * BOTH users — a hidden account is registered nowhere in the app except its owner's
 * settings page (which uses q_owned_accounts(), the only read that ignores VIS).
 * The `<> "hidden"` test comes first so an owner can't see their own hidden accounts
 * via the `i.user_id = :uid` branch.
 */

const VIS = '(a.visibility <> "hidden" AND (a.visibility = "shared" OR i.user_id = :uid))';

/**
 * Effective account name SQL fragment: the owner's display-name override when set,
 * else the original Plaid/manual name (migration 009). SELECT it as `name` /
 * `account_name` so every page renders the rename transparently without touching
 * each call site. Requires the `accounts` table aliased `a`.
 */
const ACCT_NAME = "COALESCE(NULLIF(a.display_name, ''), a.name)";

/**
 * Split-explosion primitives (#8, migration 015). A transaction can be split
 * across categories (transaction_splits). To make splits DRIVE the spend math, the
 * six spend aggregations LEFT JOIN transaction_splits and use these two expressions
 * instead of the bare category-coalesce / `t.amount`:
 *
 *   FROM transactions t … SPLIT_JOIN
 *     - a tx with N splits explodes into N rows (each split's category + amount)
 *     - a tx with 0 splits stays 1 row (s.id IS NULL → EFF_CAT = the old coalesce,
 *       EFF_AMT = t.amount) — so behaviour is byte-for-byte unchanged when unsplit.
 *
 * The per-category transfer/CC-payment exclusions then apply PER SPLIT (correct).
 * Sign predicates (t.amount > 0 / < 0) stay on the PARENT. The split amounts are
 * enforced to sum to the parent amount at write time (api/account.php), so the
 * LEFT JOIN never drops a remainder. Requires `transactions` aliased `t`.
 *
 * ⚠️ SELF-RECONCILING (Session 30 review fix #1): the write-time sum-to-parent gate
 * runs ONCE, but `transactions.amount` is later UPSERT-updated in place by a Plaid
 * re-sync (lib/sync.php `amount=VALUES(amount)`) while the splits are never re-touched
 * — so a same-id amount revision would leave stale splits that no longer sum to the
 * parent, and the aggregations would silently total the OLD amount. The join therefore
 * only explodes a transaction whose splits STILL reconcile with its current amount (a
 * non-correlated subquery of reconciling tx ids, computed once). A tx whose splits have
 * gone stale falls back to its parent amount/category (s.id IS NULL → EFF_* = parent),
 * so the spend math always reflects the real `t.amount`. (render_tx_meta() badges the
 * stale split for the user.)
 */
const SPLIT_JOIN = 'LEFT JOIN transaction_splits s ON s.transaction_id = t.transaction_id '
    . 'AND s.transaction_id IN ('
    . '  SELECT sr.transaction_id FROM transaction_splits sr '
    . '  JOIN transactions tr ON tr.transaction_id = sr.transaction_id '
    . '  GROUP BY sr.transaction_id, tr.amount '
    . '  HAVING ABS(SUM(sr.amount) - tr.amount) < 0.005)';
/**
 * Rule-based auto-recategorization (#10, migration 016). Household-shared
 * `category_rules` ("always categorize merchant X as Y") are resolved entirely at
 * READ time — no transactions column, no backfill, instant retroactive + instant
 * revert when a rule is deleted.
 *
 * RULE_MATCH is the predicate that decides whether a rule row `cr` applies to a
 * transaction `t`:
 *   - 'merchant' : exact (case-insensitive) match on t.merchant_name
 *   - 'contains' : substring match on the raw descriptor (t.name, else t.merchant_name)
 * Stored match_value is UPPER-normalised with LIKE metachars stripped
 * (normalize_rule_value()), so the bare CONCAT('%',value,'%') needs no ESCAPE.
 *
 * RULE_CAT is a SCALAR correlated subquery (LIMIT 1 → always one value or NULL, so it
 * never explodes a row like a JOIN would) returning the winning rule's category for
 * the current `t`. Winner = exact-merchant over contains, then higher priority, then
 * newest. It is folded into EFF_CAT between the per-tx override and Plaid's pfc_primary
 * → precedence: split > category_override > RULE > pfc_primary > UNCATEGORIZED. Because
 * all six spend aggregations consume the EFF_CAT const, rules propagate to every one of
 * them with no per-query edit. Requires `transactions` aliased `t`.
 */
const RULE_MATCH =
    "((cr.match_type = 'merchant' AND cr.match_value <> '' AND UPPER(t.merchant_name) = cr.match_value)
      OR (cr.match_type = 'contains' AND cr.match_value <> ''
            AND UPPER(COALESCE(t.name, t.merchant_name, '')) LIKE CONCAT('%', cr.match_value, '%')))";
const RULE_CAT =
    "(SELECT cr.category FROM category_rules cr
        WHERE " . RULE_MATCH . "
        ORDER BY (cr.match_type = 'merchant') DESC, cr.priority DESC, cr.id DESC
        LIMIT 1)";

const EFF_CAT    = "COALESCE(s.category, t.category_override, " . RULE_CAT . ", t.pfc_primary, 'UNCATEGORIZED')";
const EFF_AMT    = "(CASE WHEN s.id IS NULL THEN t.amount ELSE s.amount END)";

/**
 * Categories a rule may NOT target (#10). RULE_CAT is folded into EFF_CAT, and the
 * true-expense aggregations exclude TRANSFER_IN/OUT per category — so a rule pointing a
 * merchant at a transfer/income category would silently drop its spend from cashflow/
 * trends/digest/unusual-spend/budget. Same set the split editor blocks (api/account.php).
 * Enforced on the write path (api/rules.php) and dropped from the pickers (rules.php / app.js).
 */
const RULE_CAT_BLOCKED = ['TRANSFER_IN', 'TRANSFER_OUT', 'INCOME'];

/* ---- Custom (user-defined) categories (migration 024) -------------------------
 * A custom category is a household-shared, first-class entity (`custom_categories`):
 * a stable `tag` (the code stored in category_override/category_rules/transaction_splits/
 * budgets — so EFF_CAT resolves it unchanged), a display `label`, and an
 * `exclude_from_spending` flag that makes it behave like TRANSFER_IN/OUT in the
 * true-expense reads. All helpers here are NOT VIS-scoped (one global vocabulary, like
 * category_rules / tags / budgets) and DEFENSIVE (missing table pre-migration → []).
 */

/** [TAG => ['label'=>string, 'exclude'=>bool]] for every custom category. Static-cached
 *  (one query per request); powers category_options(), pretty_cat() and the exclude list. */
function custom_category_map(PDO $pdo): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    try {
        foreach ($pdo->query("SELECT tag, label, exclude_from_spending FROM custom_categories") as $r) {
            $map[(string)$r['tag']] = [
                'label'   => (string)$r['label'],
                'exclude' => (int)$r['exclude_from_spending'] === 1,
            ];
        }
    } catch (Throwable $e) {
        $map = [];   // table not migrated yet, or transient — degrade to "no custom categories"
    }
    return $map;
}

/** Tags of custom categories flagged exclude_from_spending. Defensively re-filtered to
 *  [A-Z0-9_] so expense_exclude_clause() can inline them as quoted SQL literals safely. */
function category_excluded_tags(PDO $pdo): array
{
    $out = [];
    foreach (custom_category_map($pdo) as $tag => $meta) {
        if (!empty($meta['exclude']) && preg_match('/^[A-Z0-9_]+$/', $tag)) $out[] = $tag;
    }
    return $out;
}

/**
 * The "exclude these categories" predicate for the true-expense aggregations. Returns a
 * ready-to-INLINE SQL fragment `EFF_CAT NOT IN ('TRANSFER_IN','TRANSFER_OUT', …customs)`.
 *
 * The default $base reproduces the historical transfer exclusion, so the 12 spend
 * aggregations that already excluded transfers just swap their literal `EFF_CAT NOT IN
 * ('TRANSFER_IN','TRANSFER_OUT')` for `expense_exclude_clause($pdo)` — behaviour is
 * byte-identical when no custom category is flagged. Pass $base = [] for a read that
 * does NOT exclude transfers (q_spending / q_spending_total) so only the flagged customs
 * drop out; with an empty final list it returns `1=1` (an empty SQL `IN ()` is a syntax error).
 *
 * No placeholders: TRANSFER_IN/OUT are static literals and the custom tags are guaranteed
 * [A-Z0-9_] (normalize_category_tag() + category_excluded_tags() re-filter), so inlining
 * carries no injection surface and keeps every consumer HY093-safe (no per-query binds).
 * EFF_CAT itself binds nothing (it references joined columns), so repeating it here is free.
 *
 * NOTE: in q_cashflow / q_money_flow this clause sits in the shared WHERE that feeds BOTH the
 * income (amount<0) and expense (amount>0) branches, so a flagged exclude_from_spending category
 * is dropped from income too — intentional and consistent with how TRANSFER_IN/OUT already behave
 * there (a "Reimbursable"/internal bucket is neither spend nor income).
 */
function expense_exclude_clause(PDO $pdo, array $base = ['TRANSFER_IN', 'TRANSFER_OUT']): string
{
    $tags = array_merge($base, category_excluded_tags($pdo));
    if (!$tags) return '1=1';
    $list = implode(',', array_map(fn($t) => "'" . $t . "'", $tags));
    return EFF_CAT . " NOT IN (" . $list . ")";
}

/**
 * Derive a canonical category tag from a user-typed label: UPPER, every non-alphanumeric
 * run → a single `_`, trimmed — so it matches the PFC-code shape and the [A-Z0-9_] guarantee
 * expense_exclude_clause() relies on. e.g. "Pet Care" → PET_CARE, "AT&T Fees" → AT_T_FEES.
 * Returns '' for empty/garbage input (callers reject ''); capped at 96 (the column width).
 */
function normalize_category_tag(string $label): string
{
    $v = function_exists('mb_strtoupper') ? mb_strtoupper(trim($label)) : strtoupper(trim($label));
    $v = preg_replace('/[^A-Z0-9]+/', '_', $v);
    $v = trim((string)$v, '_');
    return function_exists('mb_substr') ? mb_substr($v, 0, 96) : substr($v, 0, 96);
}

/**
 * Plaid investment `subtype` values we treat as retirement accounts (lowercased).
 * Used by is_retirement_account() + q_retirement_accounts() so Plaid-linked IRAs /
 * 401(k)s / pensions land on the Retirement page (not the Investments page) without
 * the owner having to flag them. The per-account `retirement_flag` override wins
 * over this when set (Plaid's subtypes aren't always accurate).
 */
const RETIREMENT_SUBTYPES = [
    '401a', '401k', '403b', '457b', 'ira', 'roth', 'roth ira', 'roth 401k',
    'sep ira', 'simple ira', 'sarsep', 'pension', 'retirement', 'rollover',
    'keogh', 'tsp', 'thrift savings plan', 'profit sharing plan',
    'rrsp', 'rrif', 'lira', 'lrsp', 'lif', 'prif', 'rlif', 'sipp',
];

/**
 * Is this account a retirement account? Precedence:
 *   retirement_flag = 0 → never · = 1 → always · NULL → auto-classify:
 *   a manual 401(k) (manual_type='retirement_401k'), or a Plaid investment account
 *   whose subtype is in RETIREMENT_SUBTYPES. The row must carry retirement_flag,
 *   manual_type, type and subtype (q_accounts/q_account/q_holdings/q_retirement_* all do).
 */
function is_retirement_account(array $a): bool
{
    $flag = $a['retirement_flag'] ?? null;
    if ($flag !== null && $flag !== '') return (int)$flag === 1;
    if (($a['manual_type'] ?? '') === 'retirement_401k') return true;
    if (($a['type'] ?? '') === 'investment') {
        return in_array(strtolower(trim((string)($a['subtype'] ?? ''))), RETIREMENT_SUBTYPES, true);
    }
    return false;
}

/** Most-recent successful sync time across all live household Plaid items (for the
 *  dashboard "Updated X ago" label). Household-wide on purpose — it exposes only a
 *  timestamp, no account data — so no VIS clause is needed. NULL if never synced. */
function q_last_synced(PDO $pdo): ?string
{
    $v = $pdo->query("SELECT MAX(last_synced_at) FROM items
                      WHERE source = 'plaid' AND status <> 'removed'")->fetchColumn();
    return $v !== false && $v !== null ? (string)$v : null;
}

/** Seconds since the most recent Plaid sync (NULL if never synced). Age is computed
 *  SQL-side so it dodges the S24 EDT-server / PDT-app re-parse trap — feed the result
 *  to activity_ago(), NEVER time_ago() on the raw timestamp. */
function q_last_synced_age(PDO $pdo): ?int
{
    $v = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, MAX(last_synced_at), NOW()) FROM items
                      WHERE source = 'plaid' AND status <> 'removed'")->fetchColumn();
    return $v !== false && $v !== null ? (int)$v : null;
}

/** All accounts visible to $uid, ordered by institution then name. */
function q_accounts(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare(
        "SELECT a.account_id, " . ACCT_NAME . " AS name, a.official_name, a.mask, a.type, a.subtype,
                a.retirement_flag, a.balance_available, a.balance_current, a.balance_limit,
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
        "SELECT a.account_id, " . ACCT_NAME . " AS name, a.name AS original_name, a.display_name,
                a.official_name, a.mask, a.type, a.subtype,
                a.retirement_flag, a.statement_cadence, a.balance_available, a.balance_current, a.balance_limit,
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

/**
 * Every account the user OWNS, regardless of visibility — including 'hidden' ones.
 * This is the ONLY read that bypasses VIS, and it is scoped to the owner's own Items
 * (i.user_id = :uid), so it never exposes another user's accounts. Used solely by the
 * settings page so the owner can see and un-hide their hidden accounts (hidden accounts
 * are invisible everywhere else, including their own account.php drill-down).
 */
function q_owned_accounts(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare(
        "SELECT a.account_id, " . ACCT_NAME . " AS name, a.official_name, a.mask, a.type, a.subtype,
                a.retirement_flag, a.balance_available, a.balance_current, a.balance_limit,
                a.visibility, i.institution_name, i.institution_id,
                i.user_id AS owner_id, i.item_id, i.status AS item_status,
                i.error_code, i.source, i.manual_type
         FROM accounts a JOIN items i ON a.item_id = i.item_id
         WHERE i.user_id = :uid
         ORDER BY i.institution_name, a.name"
    );
    $st->execute([':uid' => $uid]);
    return $st->fetchAll();
}

/**
 * Shared Plaid accounts owned by OTHER household members — the set an ADMIN may
 * re-link / refresh on their behalf (Session 94). Privacy-preserving: ONLY
 * visibility='shared' accounts (never another user's private/hidden bank, which
 * an admin can't see anywhere else either) and ONLY Plaid sources (a manual
 * account has no re-link). The caller MUST gate this on is_admin() — this helper
 * does not check the role itself. One row per account (mirrors q_owned_accounts);
 * a multi-account bank shows each of its shared accounts.
 *
 * Each row also carries per-Item counts (`item_total_accounts` over ALL visibilities +
 * `item_shared_accounts`) so the caller can offer the DESTRUCTIVE admin Remove only when
 * every account on the Item is shared (total === shared) — never wiping a private/hidden
 * account the admin can't see — and show the true total in the confirm.
 */
function q_household_relinkable_accounts(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare(
        "SELECT a.account_id, " . ACCT_NAME . " AS name, a.official_name, a.mask, a.type, a.subtype,
                a.visibility, i.institution_name, i.institution_id,
                i.user_id AS owner_id, i.item_id, i.status AS item_status,
                i.error_code, i.source, i.manual_type,
                (SELECT COUNT(*) FROM accounts a2 WHERE a2.item_id = i.item_id) AS item_total_accounts,
                (SELECT COUNT(*) FROM accounts a3 WHERE a3.item_id = i.item_id AND a3.visibility = 'shared') AS item_shared_accounts
         FROM accounts a JOIN items i ON a.item_id = i.item_id
         WHERE i.user_id <> :uid
           AND a.visibility = 'shared'
           AND i.source = 'plaid'
         ORDER BY i.institution_name, a.name"
    );
    $st->execute([':uid' => $uid]);
    return $st->fetchAll();
}

/**
 * Net-worth / assets / liabilities / count from an accounts array.
 * $extraAssets folds in non-account assets (e.g. the estimated home value) so net
 * worth nets the mortgage against the house instead of only subtracting the debt.
 */
function q_stats(array $accounts, float $extraAssets = 0.0): array
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
    $assets += $extraAssets;
    return [
        'net_worth'   => round($assets - $liabilities, 2),
        'assets'      => round($assets, 2),
        'liabilities' => round($liabilities, 2),
        'accounts'    => count($accounts),
    ];
}

/**
 * The single household home-config row (migration 031) — the UI-managed home setup
 * (Settings → Home value). Static-cached per request (this is read inside loops over
 * net-worth dates, so it must be cheap). Returns address / value_factor / manual_value
 * (+date) / purchase_price (+date) / removed_on.
 *
 * DEFENSIVE: if the table is missing (pre-migration) or the row is absent, it falls
 * back to the legacy config['home'] keys for address/value_factor, so a deploy can't
 * 500 between code + migration. After migration the DB row is authoritative and the
 * config block is just the migration seed / fallback.
 *
 * `removed_on` set = the home was removed (sold) with history KEPT to that date: the
 * address stays so net-worth HISTORY still reads home_values, but every CURRENT read
 * (q_home_value / q_home_equity / property page) treats the home as gone. The "erase"
 * removal clears the row entirely instead, so address='' = no home anywhere.
 */
function home_config(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cfg = $GLOBALS['CONFIG']['home'] ?? [];
    $fallback = [
        'address'           => trim((string)($cfg['address'] ?? '')),
        'value_factor'      => $cfg['value_factor'] ?? null,
        'manual_value'      => null,
        'manual_value_date' => null,
        'purchase_price'    => null,
        'purchase_date'     => null,
        'removed_on'        => null,
        'removed_now'       => false,   // removed AND the removal date has arrived
    ];
    try {
        $row = $pdo->query("SELECT * FROM home_config WHERE id = 1")->fetch();
        if ($row) {
            $rem = $row['removed_on'];
            return $cache = [
                'address'           => trim((string)($row['address'] ?? '')),
                'value_factor'      => $row['value_factor'],
                'manual_value'      => $row['manual_value'],
                'manual_value_date' => $row['manual_value_date'],
                'purchase_price'    => $row['purchase_price'],
                'purchase_date'     => $row['purchase_date'],
                'removed_on'        => $rem,
                // CURRENT reads gate on removed_now (a future-dated removal still
                // counts until the date arrives); the net-worth HISTORY uses the
                // per-date cutoff in nw_home_at() instead.
                'removed_now'       => ($rem !== null && (string)$rem <= date('Y-m-d')),
            ];
        }
    } catch (Throwable $e) {
        // table missing (pre-migration 031) → fall back to config below
    }
    return $cache = $fallback;
}

/**
 * Optional ownership factor applied to the home value wherever it counts toward
 * NET WORTH — the dashboard / Net-Worth-page net-worth line + composition, and the
 * dashboard Home-equity card. Defaults to 1.0 (full value) when unset, which is the
 * normal case. The Property page is deliberately NOT scaled (it always shows the
 * home's full market value).
 *
 * Use case (rare): a co-owned home where only your share should count toward net
 * worth — e.g. a 50/50 home → set the ownership share to 50% (value_factor 0.5) in
 * Settings → Home value. Multiplies the VALUE only; the mortgage balance is left at
 * full (the documented decision). Clamped to [0,1]; a missing / non-numeric /
 * non-finite value → 1.0 (no scaling), so a typo can never inflate or zero net worth.
 */
function home_value_factor(): float
{
    $f = home_config(db())['value_factor'] ?? null;
    if ($f === null || $f === '' || !is_numeric($f)) return 1.0;
    $f = (float)$f;
    if (!is_finite($f)) return 1.0;
    return max(0.0, min(1.0, $f));
}

/**
 * Latest estimated home value applicable to NET WORTH for the configured address
 * (0 if none), already scaled by home_value_factor() so a co-owned home counts only
 * the owner's share. (The Property page reads q_value_history() for the full value.)
 */
function q_home_value(PDO $pdo): float
{
    $hc = home_config($pdo);
    $addr = $hc['address'];
    // No address, or removed (sold) → the home no longer has a CURRENT value.
    if ($addr === '' || $hc['removed_now']) return 0.0;
    $st = $pdo->prepare("SELECT value FROM home_values WHERE address = :a ORDER BY as_of DESC, id DESC LIMIT 1");
    $st->execute([':a' => $addr]);
    return (float)($st->fetchColumn() ?: 0) * home_value_factor();
}

/**
 * Home-value timeline for layering the house onto historical net-worth snapshots
 * (which store financial accounts only). Returns the valuation rows plus the
 * purchase price/date anchor (used for dates before the first valuation).
 */
function nw_home_timeline(PDO $pdo): array
{
    $hc = home_config($pdo);
    $addr = $hc['address'];
    if ($addr === '') return ['vals' => [], 'pp' => null, 'pd' => null, 'removed' => null];
    $st = $pdo->prepare("SELECT as_of, value FROM home_values WHERE address = :a ORDER BY as_of ASC");
    $st->execute([':a' => $addr]);
    $vals = $st->fetchAll();
    // Purchase price/date anchor (covers dates before any valuation): prefer the
    // owner-entered manual basis (home_config), else the RentCast property record.
    $pp = $hc['purchase_price'] ?? null;
    $pd = $hc['purchase_date'] ?? null;
    if ($pp === null || $pd === null) {
        $pf = $pdo->prepare("SELECT purchase_price, purchase_date FROM property_facts WHERE address = :a");
        $pf->execute([':a' => $addr]);
        $row = $pf->fetch() ?: [];
        if ($pp === null) $pp = $row['purchase_price'] ?? null;
        if ($pd === null) $pd = $row['purchase_date'] ?? null;
    }
    return ['vals' => $vals, 'pp' => $pp, 'pd' => $pd, 'removed' => $hc['removed_on']];
}

/**
 * Home value applicable at $date (YYYY-MM-DD) from a preloaded timeline, scaled by
 * home_value_factor() so the net-worth history line + composition count only the
 * owner's share of a co-owned home (default 1.0 = full value).
 */
function nw_home_at(array $tl, string $date): float
{
    // Removed (sold) with history kept: the home counts for dates BEFORE the removal
    // date and drops to 0 from the removal date onward — so the net-worth line/
    // composition on the removal day matches q_home_value()'s "removed → 0" current read.
    if (!empty($tl['removed']) && $date >= (string)$tl['removed']) return 0.0;
    $h = 0.0;
    // Baseline: purchase price once we owned it (covers dates before any valuation).
    if ($tl['pp'] !== null && $tl['pd'] && (string)$tl['pd'] <= $date) $h = (float)$tl['pp'];
    // Override with the most recent valuation on or before the date.
    foreach ($tl['vals'] as $v) {
        if ((string)$v['as_of'] <= $date) $h = (float)$v['value'];
        else break;
    }
    return $h * home_value_factor();
}

/**
 * Household net-worth snapshots (oldest first), with the estimated home value
 * layered on. balance_snapshots store financial accounts only (don't change that —
 * baking the home in there too would double-count); the house is added here at read.
 */
function q_networth(PDO $pdo): array
{
    // Take the NEWEST 730 daily snapshots (DESC + LIMIT), then re-order ascending for
    // plotting — same pattern as q_fred_history(). An ASC LIMIT froze the series at the
    // OLDEST 730 rows, so after ~2 years the line stopped advancing (code review 2.1).
    $rows = $pdo->query(
        "SELECT snapshot_date, net_worth FROM balance_snapshots
         ORDER BY snapshot_date DESC LIMIT 730"
    )->fetchAll();
    $rows = array_reverse($rows);
    $tl = nw_home_timeline($pdo);
    foreach ($rows as &$r) {
        $r['net_worth'] = round((float)$r['net_worth'] + nw_home_at($tl, (string)$r['snapshot_date']), 2);
    }
    return $rows;
}

/**
 * Percentage change in net worth vs the snapshot closest to $days ago.
 * Returns ['pct' => float|null, 'abs' => float|null, 'from' => ?float].
 */
function q_networth_change(PDO $pdo, float $current, int $days = 30): array
{
    $st = $pdo->prepare(
        "SELECT snapshot_date, net_worth FROM balance_snapshots
         WHERE snapshot_date <= :before
         ORDER BY snapshot_date DESC LIMIT 1"
    );
    // Cutoff anchored in PHP app-TZ (never CURDATE() — snapshot_date is written app-TZ but
    // MySQL's clock is EDT, so CURDATE() picks a baseline a day off in the late-PDT window; S24 trap).
    $st->bindValue(':before', (new DateTimeImmutable('today'))->modify("-{$days} day")->format('Y-m-d'));
    $st->execute();
    $row = $st->fetch();
    if (!$row) {
        // Fall back to the earliest snapshot we have.
        $row = $pdo->query(
            "SELECT snapshot_date, net_worth FROM balance_snapshots ORDER BY snapshot_date ASC LIMIT 1"
        )->fetch();
    }
    if (!$row) {
        return ['pct' => null, 'abs' => null, 'from' => null, 'date' => null];
    }
    // Layer the home value onto the comparison snapshot too (snapshots are
    // accounts-only), so the change isn't a fake one-time spike from adding the house.
    $tl = nw_home_timeline($pdo);
    $prev = (float)$row['net_worth'] + nw_home_at($tl, (string)$row['snapshot_date']);
    // A baseline at (or within $1 of) zero makes the comparison meaningless —
    // dividing by a few cents yields a wild percentage, and a near-zero baseline
    // is an inception/degenerate snapshot, not a real prior net worth. Suppress the
    // WHOLE comparison (all-null, matching the exact-zero case, just widened to a $1
    // floor) so neither the nominal nor the sibling real-NW chip — which gates on
    // 'date' — renders a garbage delta off it.
    if (abs($prev) < 1.0) {
        return ['pct' => null, 'abs' => null, 'from' => null, 'date' => null];
    }
    // 'date' = the baseline snapshot actually used, so callers (e.g. the #17 real-NW
    // chip) can reindex against the SAME snapshot instead of re-selecting one off a
    // different clock (the S24 PHP-PDT vs MySQL-EDT trap).
    return [
        'pct'  => round((($current - $prev) / abs($prev)) * 100, 1),
        'abs'  => round($current - $prev, 2),
        'from' => $prev,
        'date' => (string)$row['snapshot_date'],
    ];
}

/**
 * Net-worth COMPOSITION over time (#6) — how the asset/debt MIX shifted, not just the
 * single net-worth line. Derived at READ time from account_balance_history (one row per
 * account per day, written by the nightly cron for every non-hidden account), bucketed by
 * account_group() into 6 bands, with the estimated home value layered on per date.
 *
 * Deliberately HOUSEHOLD-WIDE (NOT VIS-scoped), excluding only 'hidden' — it decomposes the
 * household net-worth LINE (q_networth / balance_snapshots), which is itself household-wide,
 * so the bands sum (per date) to that line. Both ABH and balance_snapshots are written in the
 * SAME cron step from the same accounts.balance_current with the same `visibility <> 'hidden'`
 * filter, so they reconcile to the penny. Single :from bind → HY093-safe; the window is a PHP
 * app-TZ date, never MySQL CURDATE() (the S24 PDT-vs-EDT trap).
 *
 * ⚠️ RECONCILE GATE: a date is emitted ONLY if it has a balance_snapshots row AND its financial-
 * account net matches that snapshot within $1. Early ABH history is incomplete — manual 401(k)
 * statement-entry rows carry historical snapshot_dates with no peer accounts (no balance_snapshots
 * row), and the days before the cron began recording every account undercount net worth. The gate
 * drops exactly those, guaranteeing the composition's net ALWAYS equals the net-worth line above,
 * and never drops a good date (from the day ABH stabilised on, both come from the same cron source).
 * So the composition span can be SHORTER than the net-worth line — that's intended, not a bug.
 *
 * Bands (display/stack order): Cash (checking+savings+other-deposit) · Investments · Retirement ·
 * Vehicles (manual vehicle assets, #40) · Home (asset, layered from the valuation timeline) · Debt
 * (credit+loans, returned NEGATIVE so the stack nets to net worth). A band that's flat-zero across
 * the whole window is dropped (no home / no retirement / no vehicle ⇒ no empty legend entry).
 *
 * Returns ['labels'=>[date…], 'series'=>[['label'=>…,'values'=>[…]]…],
 *          'current'=>['Cash'=>…, …, 'net'=>…], 'net'=>[per-date sum]].
 */
function q_networth_composition(PDO $pdo, int $days = 365): array
{
    $from = date('Y-m-d', strtotime("-{$days} days"));   // app-TZ window; one bind → HY093-safe
    // account_group() needs type/subtype/retirement_flag/manual_type (the retirement classifier).
    $st = $pdo->prepare(
        "SELECT abh.snapshot_date, abh.balance,
                a.type, a.subtype, a.retirement_flag, i.manual_type
         FROM account_balance_history abh
         JOIN accounts a ON a.account_id = abh.account_id
         JOIN items i ON i.item_id = a.item_id
         WHERE a.visibility <> 'hidden' AND abh.snapshot_date >= :from
         ORDER BY abh.snapshot_date ASC"
    );
    $st->execute([':from' => $from]);
    $rows = $st->fetchAll();

    // ACCOUNT_GROUPS bucket → one of the 5 composition bands. 'other' (unknown type) reads as
    // an asset, exactly as write_networth_snapshot()'s else-branch treats it, so the sum still
    // reconciles to the net-worth line.
    $bandOf = static function (array $a): string {
        switch (account_group($a)) {
            case 'checking':
            case 'savings':     return 'Cash';
            case 'investments': return 'Investments';
            case 'retirement':  return 'Retirement';
            case 'vehicle':     return 'Vehicles';   // manual vehicle asset (#40)
            case 'credit':
            case 'loans':       return 'Debt';
            default:            return 'Cash';
        }
    };

    // Accumulate per (date, band). Debt is summed positive here, negated at emit.
    $byDate = [];   // 'YYYY-MM-DD' => [band => total]
    foreach ($rows as $r) {
        $d = (string)$r['snapshot_date'];
        $byDate[$d][$bandOf($r)] = ($byDate[$d][$bandOf($r)] ?? 0.0) + (float)$r['balance'];
    }

    // Household net-worth line per date (financial accounts only — no home), for the reconcile gate.
    $bs = [];
    foreach ($pdo->query("SELECT snapshot_date, net_worth FROM balance_snapshots")->fetchAll() as $r) {
        $bs[(string)$r['snapshot_date']] = (float)$r['net_worth'];
    }

    $tl     = nw_home_timeline($pdo);            // home value timeline (ABH stores accounts only)
    $bands  = ['Cash', 'Investments', 'Retirement', 'Vehicles', 'Home', 'Debt'];
    $series = array_fill_keys($bands, []);
    $labels = [];
    $net    = [];
    foreach (array_keys($byDate) as $d) {        // already date-ascending from the ORDER BY
        $cash = round((float)($byDate[$d]['Cash'] ?? 0.0), 2);
        $inv  = round((float)($byDate[$d]['Investments'] ?? 0.0), 2);
        $ret  = round((float)($byDate[$d]['Retirement'] ?? 0.0), 2);
        $veh  = round((float)($byDate[$d]['Vehicles'] ?? 0.0), 2);   // manual vehicle assets (#40)
        $debt = round((float)($byDate[$d]['Debt'] ?? 0.0), 2);   // positive here
        // Reconcile gate: skip a date with no household snapshot, or whose financial-account
        // net doesn't match it (incomplete ABH that day). $1 tolerance covers cent rounding.
        // Vehicles are assets in balance_snapshots' else-branch, so include them in the sum.
        if (!isset($bs[$d]) || abs(($cash + $inv + $ret + $veh - $debt) - $bs[$d]) > 1.0) continue;

        $vals = ['Cash' => $cash, 'Investments' => $inv, 'Retirement' => $ret, 'Vehicles' => $veh,
                 'Home' => round(nw_home_at($tl, $d), 2), 'Debt' => -$debt];  // debt below zero
        $labels[] = $d;
        $n = 0.0;
        foreach ($bands as $b) { $series[$b][] = $vals[$b]; $n += $vals[$b]; }
        $net[] = round($n, 2);
    }

    // Latest column (for the current-mix breakdown) + drop all-zero bands.
    $current = ['net' => $labels ? end($net) : 0.0];
    $out = [];
    foreach ($bands as $b) {
        $current[$b] = $labels ? end($series[$b]) : 0.0;
        foreach ($series[$b] as $v) {
            if (abs($v) > 0.005) { $out[] = ['label' => $b, 'values' => $series[$b]]; break; }
        }
    }

    return ['labels' => $labels, 'series' => $out, 'current' => $current, 'net' => $net];
}

/**
 * Liquid CASH on hand over time — the per-day sum of the viewer's checking + savings
 * balances. VIS-scoped (the viewer's own accounts), unlike q_networth_composition's Cash
 * band (which is household-wide). Read at READ time from account_balance_history (one row
 * per account per day, written by the nightly cron for every non-hidden account).
 *
 * "Cash" = accounts whose account_group() is 'checking' or 'savings' — the SAME definition
 * forecast.php uses (FORECAST_CASH_GROUPS), so this agrees with the cash forecast. The
 * checking-vs-savings split is by subtype, so we bucket in PHP (like q_networth_composition)
 * rather than in SQL. Plaid maps money-market / CD / cash-management into the 'savings'
 * bucket, so those count too (consistent with the rest of the app; surfaced as a caveat on
 * the page).
 *
 * Single :from + :uid bind (the VIS clause's :uid appears once) → HY093-safe; the window is a
 * PHP app-TZ date, never MySQL CURDATE() (the S24 PDT-vs-EDT trap).
 *
 * Returns [['snapshot_date' => 'YYYY-MM-DD', 'balance' => float], …] date-ascending.
 */
function q_cash_history(PDO $pdo, int $uid, int $days = 365): array
{
    $from = (new DateTimeImmutable('today'))->modify("-{$days} day")->format('Y-m-d');
    // account_group() needs type/subtype/retirement_flag/manual_type (the retirement classifier).
    $st = $pdo->prepare(
        "SELECT abh.snapshot_date, abh.balance,
                a.type, a.subtype, a.retirement_flag, i.manual_type
         FROM account_balance_history abh
         JOIN accounts a ON a.account_id = abh.account_id
         JOIN items i ON i.item_id = a.item_id
         WHERE " . VIS . " AND abh.snapshot_date >= :from
         ORDER BY abh.snapshot_date ASC"
    );
    $st->execute([':uid' => $uid, ':from' => $from]);

    $byDate = [];   // 'YYYY-MM-DD' => running cash total
    foreach ($st->fetchAll() as $r) {
        if (!in_array(account_group($r), ['checking', 'savings'], true)) continue;
        $d = (string)$r['snapshot_date'];
        $byDate[$d] = ($byDate[$d] ?? 0.0) + (float)$r['balance'];
    }

    $out = [];
    foreach ($byDate as $d => $bal) $out[] = ['snapshot_date' => $d, 'balance' => round($bal, 2)];
    return $out;   // already date-ascending (ORDER BY)
}

/**
 * Honest windowed CHANGE for the Cash-on-hand page (code review 5.14). The naïve
 * "live total − earliest in-window ABH total" OVER-reports whenever a cash account was
 * LINKED mid-window: its live balance is in the current figure but not in the baseline, so a
 * new $5k account looks like +$5k of "growth". We fix this by anchoring the delta to a STABLE
 * population — only the accounts that already had a snapshot on the window's earliest snapshot
 * date. baseline = Σ their balance THEN; current = Σ their LIVE balance NOW. Returns null when
 * there's no usable baseline (no history yet). The hero still shows the full live cash total;
 * only the "since {date}" delta uses this stable subset.
 */
function q_cash_change(PDO $pdo, int $uid, int $days): ?array
{
    $from = (new DateTimeImmutable('today'))->modify("-{$days} day")->format('Y-m-d');
    $st = $pdo->prepare(
        "SELECT abh.snapshot_date, abh.balance, a.balance_current AS live_balance,
                a.type, a.subtype, a.retirement_flag, i.manual_type
         FROM account_balance_history abh
         JOIN accounts a ON a.account_id = abh.account_id
         JOIN items i ON i.item_id = a.item_id
         WHERE " . VIS . " AND abh.snapshot_date >= :from
         ORDER BY abh.snapshot_date ASC"
    );
    $st->execute([':uid' => $uid, ':from' => $from]);
    // Cash = checking/savings only (the forecast.php definition), classified in PHP.
    $rows = array_values(array_filter(
        $st->fetchAll(),
        fn($r) => in_array(account_group($r), ['checking', 'savings'], true)
    ));
    if (!$rows) return null;

    $baseDate = (string)$rows[0]['snapshot_date'];   // earliest (ORDER BY ASC)
    $baseline = 0.0; $current = 0.0; $n = 0;
    foreach ($rows as $r) {
        if ((string)$r['snapshot_date'] !== $baseDate) continue;   // one row per account on that date
        $baseline += (float)$r['balance'];
        $current  += (float)($r['live_balance'] ?? 0);
        $n++;
    }
    return ['as_of' => $baseDate, 'baseline' => round($baseline, 2), 'current' => round($current, 2), 'accounts' => $n];
}

/** Spending by category over the last $days days (TRUE expense — outflows only).
 *  Session 86: applies the SAME true-expense exclusions as every other spend read
 *  (transfers via expense_exclude_clause + credit-card payments) so the by-category
 *  breakdown reconciles with trends/cash-flow/budgets instead of being dominated by
 *  internal transfers & CC payments (UI-review F7). Kept in lock-step with
 *  q_spending_total so the doughnut total == Σ category slices. */
function q_spending(PDO $pdo, int $uid, int $days = 30, ?string $from = null, ?string $to = null): array
{
    // #27 assistant v2: an explicit $from (Y-m-d) OVERRIDES the rolling $days window, and an
    // optional $to caps the far end — so the assistant can ask "dining in March" without being
    // pinned to a trailing-N-days window. UI callers pass only $days → byte-identical to before.
    $endClause = ($to !== null && $to !== '') ? ' AND t.date <= :end' : '';
    $st = $pdo->prepare(
        "SELECT " . EFF_CAT . " AS category,
                SUM(" . EFF_AMT . ") AS total
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND t.date >= :start" . $endClause . "
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
         GROUP BY " . EFF_CAT . "
         ORDER BY total DESC"
    );
    // Window anchored in PHP app-TZ (never CURDATE() — MySQL's clock is EDT, S24 trap).
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', ($from !== null && $from !== '')
        ? $from
        : (new DateTimeImmutable('today'))->modify("-{$days} day")->format('Y-m-d'));
    if ($endClause !== '') $st->bindValue(':end', $to);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Top merchants by true spend over the last $days days (#5 leaderboard). VIS-scoped per
 * viewing user (mirrors q_spending). Reuses the EXACT true-expense filter set + split
 * consts every spend aggregation uses (so a transfer/CC-payment/split-to-transfer can't
 * inflate a merchant): SUM(EFF_AMT) over the SPLIT_JOIN explosion. Groups by the explicit
 * merchant display expression (NOT a bare alias — the S30 group-by-alias trap), using the
 * raw `name` when Plaid gave no enriched merchant_name so no spend is lost. Returns
 * [{merchant,total,n,logo_url}] (n = distinct txns; logo_url = MAX, ~constant per payee).
 * Binds :uid (in VIS) once + :d once (RULE_CAT subquery has no binds) → HY093-safe.
 */
function q_top_merchants(PDO $pdo, int $uid, int $days = 90, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $st = $pdo->prepare(
        "SELECT COALESCE(NULLIF(t.merchant_name, ''), t.name) AS merchant,
                SUM(" . EFF_AMT . ") AS total,
                COUNT(DISTINCT t.transaction_id) AS n,
                MAX(t.logo_url) AS logo_url
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start
           AND COALESCE(NULLIF(t.merchant_name, ''), t.name) IS NOT NULL
           AND COALESCE(NULLIF(t.merchant_name, ''), t.name) <> ''
         GROUP BY COALESCE(NULLIF(t.merchant_name, ''), t.name)
         ORDER BY total DESC
         LIMIT " . $limit
    );
    // Window anchored in PHP app-TZ (never CURDATE() — MySQL's clock is EDT, S24 trap).
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', (new DateTimeImmutable('today'))->modify("-{$days} day")->format('Y-m-d'));
    $st->execute();
    return $st->fetchAll();
}

/**
 * Best-effort merchant_name → logo_url lookup, keyed by LOWERCASED merchant_name (#5).
 * recurring_streams has no logo_url column, so the Recurring page reuses the logos Plaid
 * stored on transactions. NOT VIS-scoped — a logo URL is non-sensitive (like all_tags()).
 */
function merchant_logo_map(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT merchant_name, MAX(logo_url) AS logo_url
         FROM transactions
         WHERE logo_url IS NOT NULL AND logo_url <> ''
           AND merchant_name IS NOT NULL AND merchant_name <> ''
         GROUP BY merchant_name"
    )->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[strtolower($r['merchant_name'])] = $r['logo_url'];
    }
    return $map;
}

/** Total TRUE-expense outflow over the last $days days (for headline figures). Split-aware
 *  (SUM(EFF_AMT) over SPLIT_JOIN — identical to the parent total when there are no splits).
 *  Session 86: now applies the full true-expense exclusion set (transfers via
 *  expense_exclude_clause + credit-card payments), kept in lock-step with q_spending so the
 *  headline total == Σ of the by-category slices (UI-review F7). */
function q_spending_total(PDO $pdo, int $uid, int $days = 30): float
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(" . EFF_AMT . "), 0)
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND t.date >= :start
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')"
    );
    // Window anchored in PHP app-TZ (never CURDATE() — MySQL's clock is EDT, S24 trap).
    // Kept in lock-step with q_spending so the headline total == Σ category slices.
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', (new DateTimeImmutable('today'))->modify("-{$days} day")->format('Y-m-d'));
    $st->execute();
    return (float)$st->fetchColumn();
}

/**
 * Household weekly-digest spending (TODO #15) — total true outflow + by-category,
 * over the last $days days. Deliberately HOUSEHOLD-WIDE: no VIS / :uid (the digest
 * is the joint household summary, like write_networth_snapshot()), so it aggregates
 * BOTH users' shared + private accounts; only `hidden` accounts are excluded
 * (registered nowhere). Uses q_cashflow's true-expense filters (outflows only;
 * excludes pending, ext_source, internal transfers and credit-card payments) so the
 * headline ties to the Cash-flow / Spending-trends figures, not the raw outflow sum.
 * No `items` join needed (no :uid) — `accounts.visibility` carries the only filter.
 *
 * Returns ['total'=>float, 'cats'=>[['category'=>CAT,'total'=>float],…] desc by total].
 */
function q_digest_spending(PDO $pdo, int $days = 7): array
{
    $st = $pdo->prepare(
        "SELECT " . EFF_CAT . " AS category,
                SUM(" . EFF_AMT . ") AS total
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         " . SPLIT_JOIN . "
         WHERE a.visibility <> 'hidden'
           AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start
         GROUP BY " . EFF_CAT . "
         ORDER BY total DESC"
    );
    // Window anchored in PHP app-TZ (never CURDATE() — the cron fires ~01:13 EDT, a day
    // ahead of the app's Pacific notion of "today"; S24 trap, like q_digest_upcoming_bills).
    $st->bindValue(':start', (new DateTimeImmutable('today'))->modify("-{$days} day")->format('Y-m-d'));
    $st->execute();
    $cats  = $st->fetchAll();
    $total = 0.0;
    foreach ($cats as $c) { $total += (float)$c['total']; }
    return ['total' => round($total, 2), 'cats' => $cats];
}

/**
 * Household upcoming bills (TODO #15 digest) — liabilities whose next payment is
 * due within the next $days days, soonest first. HOUSEHOLD-WIDE (no VIS), excludes
 * hidden. A lightweight read-only derive for the digest; the full bills / payment
 * calendar is the separate TODO #4.
 *
 * Returns [['account_id','account_name','mask','liability_type',
 *           'minimum_payment_amount','next_payment_due_date'], …] ordered by due date.
 * (account_id added Session 28 for the bill-reminder dedup key in lib/spend_alerts.php;
 * the digest renderer simply ignores the extra column.)
 */
function q_digest_upcoming_bills(PDO $pdo, int $days = 14): array
{
    // ⚠️ Bound the window with PHP app-TZ dates, NOT MySQL CURDATE() (the S24 TZ
    // trap): the daily cron fires ~22:13 PDT = ~01:13 EDT, so the server clock is
    // already a day ahead — CURDATE() would drop a bill due *today* (app TZ) and
    // alert tomorrow's a day early. The bill-reminder dedup key (lib/spend_alerts.php)
    // is this same app-TZ due-date, so both sides share one clock. (Session 28.)
    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime('+' . max(0, $days) . ' days'));
    $st = $pdo->prepare(
        "SELECT a.account_id, " . ACCT_NAME . " AS account_name, a.mask, l.liability_type,
                l.minimum_payment_amount, l.next_payment_due_date
         FROM liabilities l
         JOIN accounts a ON l.account_id = a.account_id
         WHERE a.visibility <> 'hidden'
           AND l.next_payment_due_date IS NOT NULL
           AND l.next_payment_due_date >= :from
           AND l.next_payment_due_date <= :to
         ORDER BY l.next_payment_due_date ASC"
    );
    $st->bindValue(':from', $from);
    $st->bindValue(':to', $to);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Household per-category spending this month vs. its 3-prior-month average (TODO #16
 * unusual-spend alert). Both sides are **month-to-date** — capped to today's
 * day-of-month via a `SUM(CASE WHEN DAY(t.date) <= :dom …)` aggregate, the same
 * technique as q_spending_trend's deltas — so a partial current month compares
 * like-for-like against full prior months (else it always looks low early in the month).
 *
 * HOUSEHOLD-WIDE (no VIS / :uid, excludes only `hidden`, like q_digest_spending) and
 * reuses q_cashflow's true-expense filters (outflows only; excludes pending, ext_source,
 * internal transfers and credit-card payments) so the figures tie to Cash-flow / Trends.
 * The 4-month window (current + 3 prior) is anchored in PHP and bound as a start date.
 *
 * Returns [['category'=>CAT,'this'=>float,'avg3'=>float], …] for every category with
 * any spend in the window (desc by `this`); the caller (lib/spend_alerts.php) applies
 * the 2× multiplier + minimum-dollar floor.
 */
function q_spend_anomalies(PDO $pdo): array
{
    $first = new DateTimeImmutable('first day of this month');
    $start = $first->sub(new DateInterval('P3M'))->format('Y-m-01');   // 3 prior months + current
    $dom   = (int)(new DateTimeImmutable('today'))->format('j');       // today's day-of-month

    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym,
                " . EFF_CAT . " AS category,
                SUM(CASE WHEN DAY(t.date) <= :dom THEN " . EFF_AMT . " ELSE 0 END) AS mtd
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         " . SPLIT_JOIN . "
         WHERE a.visibility <> 'hidden'
           AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start
         GROUP BY ym, " . EFF_CAT
    );
    $st->bindValue(':start', $start);
    $st->bindValue(':dom', $dom, PDO::PARAM_INT);
    $st->execute();

    // mtd[ym][CAT] = same-day-of-month total for that month.
    $mtd = [];
    foreach ($st->fetchAll() as $r) {
        $mtd[$r['ym']][$r['category']] = (float)$r['mtd'];
    }

    $cur  = $first->format('Y-m');
    $prev = [];
    for ($i = 1; $i <= 3; $i++) $prev[] = $first->sub(new DateInterval('P' . $i . 'M'))->format('Y-m');

    // Union of every category seen in the window.
    $cats = [];
    foreach ($mtd as $row) foreach ($row as $c => $_) $cats[$c] = true;

    $out = [];
    foreach (array_keys($cats) as $cat) {
        $this_ = (float)($mtd[$cur][$cat] ?? 0.0);
        $avg3  = 0.0;
        foreach ($prev as $pm) $avg3 += (float)($mtd[$pm][$cat] ?? 0.0);
        $avg3 /= 3;
        if ($this_ <= 0) continue;   // nothing spent this month → not a candidate
        $out[] = ['category' => $cat, 'this' => round($this_, 2), 'avg3' => round($avg3, 2)];
    }
    usort($out, fn($a, $b) => $b['this'] <=> $a['this']);
    return $out;
}

/**
 * Monthly cash flow over the last $months calendar months (oldest→newest),
 * gap-filled so every month renders even with no activity. Income = money in
 * (Plaid amount < 0), expense = money out (amount > 0), net = income − expense.
 *
 * Excludes pending rows, manual brokerage feeds (ext_source), internal transfers
 * (TRANSFER_IN/TRANSFER_OUT) and credit-card payments
 * (pfc_detailed = LOAN_PAYMENTS_CREDIT_CARD_PAYMENT) so moving money between your
 * own accounts doesn't inflate both sides, and a card payment doesn't double-count
 * spending already recorded on the card. Real outflows (mortgage/car/student-loan
 * payments, etc.) are kept. The month window is anchored in PHP and bound as a
 * start date so the SQL window and the gap-fill list always agree.
 *
 * Returns ['months'=>[['ym','label','income','expense','net'],...] oldest→newest,
 *          'income'=>float,'expense'=>float,'net'=>float] (period totals).
 */
function q_cashflow(PDO $pdo, int $uid, int $months = 12): array
{
    $months = max(1, min(36, $months));

    // Build the contiguous month list (oldest→newest), anchored to the 1st of
    // the current month, then derive the SQL start date from its first entry.
    $first = new DateTimeImmutable('first day of this month');
    $list  = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $list[] = $first->sub(new DateInterval('P' . $i . 'M'));
    }
    $start = $list[0]->format('Y-m-01');

    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym,
                SUM(CASE WHEN t.amount > 0 THEN " . EFF_AMT . " ELSE 0 END)  AS expense,
                -- Income = depository/investment inflows only. A loan/credit account's
                -- amount<0 is a debt payment or refund (inverted sign), not income —
                -- without this the mortgage payment + card refunds leaked into income.
                SUM(CASE WHEN t.amount < 0 AND a.type NOT IN ('loan','credit') THEN -" . EFF_AMT . " ELSE 0 END) AS income
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start
         GROUP BY ym"
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', $start);
    $st->execute();
    $byYm = [];
    foreach ($st->fetchAll() as $r) { $byYm[$r['ym']] = $r; }

    $out = [];
    $tot = ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
    foreach ($list as $dt) {
        $ym  = $dt->format('Y-m');
        $inc = isset($byYm[$ym]) ? (float)$byYm[$ym]['income']  : 0.0;
        $exp = isset($byYm[$ym]) ? (float)$byYm[$ym]['expense'] : 0.0;
        $net = $inc - $exp;
        $out[] = ['ym' => $ym, 'label' => $dt->format('M y'), 'income' => $inc, 'expense' => $exp, 'net' => $net];
        $tot['income']  += $inc;
        $tot['expense'] += $exp;
        $tot['net']     += $net;
    }
    return ['months' => $out] + $tot;
}

/**
 * Average daily TRUE-EXPENSE over the trailing $days days (VIS-scoped) — the
 * discretionary-spend baseline for the forward-looking cash-flow forecast (TODO2 #30).
 *
 * Reuses the EXACT true-expense filter set + SPLIT_JOIN/EFF_AMT/EFF_CAT that q_cashflow /
 * q_spending use (outflows only; excludes pending, ext_source, internal transfers and
 * credit-card payments) so the figure ties to those pages. Window start is PHP-app-TZ
 * anchored (never CURDATE() — S24 trap) and bound as :start; the only other placeholder
 * is VIS's single :uid → HY093-safe. SQL sums the spend; PHP divides by $days, so the
 * result is a flat $/day rate (0.0 with no spend in the window).
 */
function q_avg_daily_spend(PDO $pdo, int $uid, int $days = 90): float
{
    $days  = max(1, min(365, $days));
    $start = (new DateTimeImmutable('today'))->sub(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(" . EFF_AMT . "), 0) AS total
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start"
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', $start);
    $st->execute();
    return (float)$st->fetchColumn() / $days;
}

/**
 * Total TRUE-EXPENSE over a half-open date window [$start, $end) (Y-m-d), VIS-scoped.
 * Reuses the EXACT q_avg_daily_spend / q_cashflow true-expense filter set + SPLIT_JOIN/EFF_AMT/EFF_CAT
 * so it reconciles with those reads. Used by the "Safe to spend" plan (#31) for the month-to-date
 * discretionary spend. HY093-safe: VIS's single :uid + distinct :start/:end. Pass an app-TZ window
 * (never CURDATE() — the S24 TZ trap).
 */
function q_true_expense_total(PDO $pdo, int $uid, string $start, string $end): float
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(" . EFF_AMT . "), 0) AS total
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start AND t.date < :end"
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', $start);
    $st->bindValue(':end', $end);
    $st->execute();
    return (float)$st->fetchColumn();
}

/**
 * The household "Safe to spend" settings — the single shared spending_plan row (id=1).
 * Deliberately NOT VIS-scoped (one global household value, like q_alert_settings). Defensive:
 * a missing table/row (pre-migration-022) or transient error returns the 0 default. Returns
 * ['monthly_savings_target' => float]. Consumed by safe_to_spend.php + the index.php teaser.
 */
function q_spending_plan(PDO $pdo): array
{
    $target = 0.0;
    try {
        $v = $pdo->query("SELECT monthly_savings_target FROM spending_plan WHERE id = 1")->fetchColumn();
        if ($v !== false && $v !== null) $target = (float)$v;
    } catch (Throwable $e) {
        // table missing (pre-migration) or transient — fall through to the default.
    }
    return ['monthly_savings_target' => max(0.0, $target)];
}

/* ---- Per-user preferences (UI redesign Phase 2; migration 030) ----------- */

/** Allowed values of the per-user theme preference. */
const USER_PREF_THEMES = ['light', 'dark', 'auto'];

/**
 * The viewer's own preferences (NOT VIS-scoped — keyed by user_id). Defensive: a
 * missing table (pre-migration-030), a NULL/invalid JSON blob, or any transient
 * error → the empty array, so a fresh user and a pre-migration DB both fall back to
 * the defaults at the point of use (theme via user_prefs_theme()). Phase 3 will read
 * the dashboard layout from the same blob. Returns the decoded prefs as an assoc array.
 */
function q_user_prefs(PDO $pdo, int $uid): array
{
    try {
        $st = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = :uid");
        $st->bindValue(':uid', $uid, PDO::PARAM_INT);
        $st->execute();
        $raw = $st->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') return [];
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];   // table missing / transient — caller uses defaults.
    }
}

/** Validate a prefs theme to one of USER_PREF_THEMES (default 'auto'). */
function user_prefs_theme(array $prefs): string
{
    $t = $prefs['theme'] ?? 'auto';
    return in_array($t, USER_PREF_THEMES, true) ? $t : 'auto';
}

/**
 * Per-cash-account interest income over the trailing $days window (VIS-scoped) + the
 * earliest observed transaction date per account, for the savings-rate estimate (TODO2 #38).
 * Interest = Plaid-categorized INCOME_INTEREST_EARNED credits (amount < 0 = money in →
 * magnitude is -amount), summed only inside the window; first_tx is MIN(t.date) over ALL
 * the account's non-pending rows (any category) so the assembler can bound the
 * annualization span. Scoped to depository (checking/savings) accounts. Returns
 *   [account_id => ['interest' => float, 'first_tx' => 'Y-m-d'|null]].
 * HY093-safe: VIS's single :uid + a distinct :start (each used once). Pass an app-TZ
 * window (computed here via date(), never CURDATE() — the S24 TZ trap). The APY MATH
 * lives in lib/apy.php, not here.
 */
function q_account_interest(PDO $pdo, int $uid, int $days = 365): array
{
    $start = date('Y-m-d', strtotime('-' . max(1, $days) . ' days'));
    $st = $pdo->prepare(
        "SELECT a.account_id,
                SUM(CASE WHEN t.pfc_detailed = 'INCOME_INTEREST_EARNED'
                          AND t.amount < 0 AND t.date >= :start
                         THEN -t.amount ELSE 0 END) AS interest,
                MIN(t.date) AS first_tx
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND a.type = 'depository' AND t.pending = 0
         GROUP BY a.account_id"
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', $start);
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['account_id']] = [
            'interest' => (float)$r['interest'],
            'first_tx' => $r['first_tx'] !== null ? (string)$r['first_tx'] : null,
        ];
    }
    return $out;
}

/**
 * Per-category true-expense totals over a half-open [start,end) window — backs the
 * peer-spend comparison (TODO2 #37). Pass a COMPLETE-months window (e.g. the last 12
 * full calendar months, excluding the partial current month) so the per-category sums
 * annualize cleanly. Reuses the EXACT true-expense definition + SPLIT_JOIN/EFF_CAT/EFF_AMT
 * as every other spend read (so it ties to q_cashflow/q_spending_trend), VIS-scoped.
 *
 * Returns ['cats' => [EFF_CAT => total], 'months_observed' => int] where months_observed
 * = the count of DISTINCT calendar months in the window that actually carry true-expense
 * spend — the honest annualization divisor in lib/peers.php (so one big charge in a short
 * history can't read as a year-round habit, and a freshly-linked household isn't annualized
 * off a single month). HY093-safe: two prepared statements, each binds VIS's :uid once +
 * a distinct :start/:end. Compute the window in PHP app-TZ (never CURDATE() — the S24 trap).
 * The comparison MATH lives in lib/peers.php, not here.
 */
function q_peer_category_spend(PDO $pdo, int $uid, string $start, string $end): array
{
    $st = $pdo->prepare(
        "SELECT " . EFF_CAT . " AS category, SUM(" . EFF_AMT . ") AS total
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start AND t.date < :end
         GROUP BY " . EFF_CAT
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', $start);
    $st->bindValue(':end', $end);
    $st->execute();
    $cats = [];
    foreach ($st->fetchAll() as $r) {
        $cats[(string)$r['category']] = (float)$r['total'];
    }

    $cn = $pdo->prepare(
        "SELECT COUNT(DISTINCT DATE_FORMAT(t.date, '%Y-%m')) AS m
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start AND t.date < :end"
    );
    $cn->bindValue(':uid', $uid, PDO::PARAM_INT);
    $cn->bindValue(':start', $start);
    $cn->bindValue(':end', $end);
    $cn->execute();

    return ['cats' => $cats, 'months_observed' => (int)$cn->fetchColumn()];
}

/**
 * Allocation target mix (Session 62, TODO2 #32). Household-shared, **NOT VIS-scoped** — one set
 * of target percentages per asset class (like spending_plan / alert_settings). Returns
 * [asset_class => target_pct]; **defensive** (missing table pre-migration-023 / transient → []).
 */
function q_allocation_targets(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT asset_class, target_pct FROM allocation_targets") as $r) {
            $out[(string)$r['asset_class']] = (float)$r['target_pct'];
        }
    } catch (Throwable $e) {
        // table missing (pre-migration) or transient — empty = "no target set yet".
    }
    return $out;
}

/**
 * Per-security asset-class OVERRIDE map (Session 62, TODO2 #32). Household-shared, **NOT
 * VIS-scoped** — a security_id→class lookup (like merchant_logo_map / all_tags). No leak: the
 * allocation page only applies it to the viewer's own VIS-scoped q_holdings, so an override for
 * a security the viewer doesn't hold is never surfaced. Returns [security_id => asset_class];
 * **defensive** (missing table pre-migration-023 / transient → []).
 */
function q_security_asset_classes(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT security_id, asset_class FROM security_asset_class") as $r) {
            $out[(string)$r['security_id']] = (string)$r['asset_class'];
        }
    } catch (Throwable $e) {
        // table missing (pre-migration) or transient.
    }
    return $out;
}

/**
 * Per-security expense-ratio map (Session 70, TODO2 #39). Household-shared, **NOT VIS-scoped**
 * — a security_id→percent lookup (like q_security_asset_classes / merchant_logo_map). No leak:
 * the fee analyzer only applies it to the viewer's own VIS-scoped q_holdings, so a ratio for a
 * security the viewer doesn't hold is never surfaced. Returns [security_id => expense_ratio_pct]
 * (a PERCENT, e.g. 0.50 = 0.50%); **defensive** (missing table pre-migration-027 / transient → []).
 * The fee MATH lives in lib/fees.php, not here.
 */
function q_security_expense_ratios(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT security_id, expense_ratio FROM security_expense_ratio") as $r) {
            $out[(string)$r['security_id']] = (float)$r['expense_ratio'];
        }
    } catch (Throwable $e) {
        // table missing (pre-migration) or transient.
    }
    return $out;
}

/**
 * Spending by category across the last $months calendar months (oldest→newest),
 * gap-filled, plus current-month-vs-history deltas — backs the Spending-trends page.
 *
 * Uses the SAME true-expense definition as q_cashflow (outflows only; excludes
 * pending, ext_source, internal transfers and credit-card payments) so the trend
 * reflects real spending and ties to the cash-flow expense bars. Categories beyond
 * the top 7 (by total over the lookback) fold into an 'OTHER' bucket so the stacked
 * chart stays legible. Always queries at least 13 months so the "same month last
 * year" delta resolves even when the chart window ($months) is shorter.
 *
 * The chart's completed months use FULL monthly totals, but the current month is
 * only partial — so the deltas compare like-for-like MONTH-TO-DATE: this month so
 * far vs the prior months truncated to the same day-of-month (a conditional sum on
 * DAY(t.date) <= today), otherwise a 9th-of-the-month total always reads as "down".
 *
 * Returns:
 *   'months'    => [['ym','label','total','cats'=>[CAT=>amt,…]], …]  last $months, oldest→newest (full totals)
 *   'cat_order' => [CAT, …, 'OTHER']   stack + colour order (top-7, then OTHER if any)
 *   'deltas'    => [['category','this','avg3','lastyear'], …]  current month, 'this' desc (month-to-date)
 *   'this_total','avg3_total','lastyear_total' => float  (hero figures, month-to-date)
 *   'month_label' => 'M Y' label for the current (partial) month
 */
function q_spending_trend(PDO $pdo, int $uid, int $months = 12): array
{
    $months   = max(1, min(24, $months));
    $lookback = max($months, 13);   // ensure same-month-last-year is always covered

    // Contiguous month list (oldest→newest), anchored to the 1st of this month.
    $first = new DateTimeImmutable('first day of this month');
    $list  = [];
    for ($i = $lookback - 1; $i >= 0; $i--) {
        $list[] = $first->sub(new DateInterval('P' . $i . 'M'));
    }
    $start = $list[0]->format('Y-m-01');
    $dom   = (int)(new DateTimeImmutable('today'))->format('j');   // today's day-of-month

    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym,
                " . EFF_CAT . " AS category,
                SUM(" . EFF_AMT . ") AS total,
                SUM(CASE WHEN DAY(t.date) <= :dom THEN " . EFF_AMT . " ELSE 0 END) AS mtd
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start
         GROUP BY ym, " . EFF_CAT
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->bindValue(':start', $start);
    $st->bindValue(':dom', $dom, PDO::PARAM_INT);
    $st->execute();

    // matrix[ym][CAT] = full total (chart) ; mtd[ym][CAT] = same-day-of-month total
    // (deltas) ; catTotals[CAT] = total over the whole lookback (for ranking).
    $matrix = [];
    $mtd = [];
    $catTotals = [];
    foreach ($st->fetchAll() as $r) {
        $ym = $r['ym']; $cat = $r['category'];
        $matrix[$ym][$cat] = ($matrix[$ym][$cat] ?? 0.0) + (float)$r['total'];
        $mtd[$ym][$cat]    = ($mtd[$ym][$cat] ?? 0.0) + (float)$r['mtd'];
        $catTotals[$cat]   = ($catTotals[$cat] ?? 0.0) + (float)$r['total'];
    }
    arsort($catTotals);
    $topCats  = array_slice(array_keys($catTotals), 0, 7);
    $catOrder = $topCats;
    if (count($catTotals) > count($topCats)) $catOrder[] = 'OTHER';

    // Amount for one category in one month from a given matrix, treating 'OTHER'
    // as "everything not in the top-7".
    $pick = function (array $src, string $ym, string $cat) use ($topCats): float {
        $row = $src[$ym] ?? [];
        if ($cat === 'OTHER') {
            $o = 0.0;
            foreach ($row as $c => $a) if (!in_array($c, $topCats, true)) $o += $a;
            return $o;
        }
        return (float)($row[$cat] ?? 0.0);
    };

    // Chart window = the last $months entries of the lookback list, gap-filled (full totals).
    $monthsOut = [];
    foreach (array_slice($list, -$months) as $dt) {
        $ym   = $dt->format('Y-m');
        $cats = [];
        foreach ($catOrder as $cat) $cats[$cat] = $pick($matrix, $ym, $cat);
        $monthsOut[] = [
            'ym'    => $ym,
            'label' => $dt->format('M y'),
            'total' => array_sum($cats),
            'cats'  => $cats,
        ];
    }

    // Deltas (month-to-date): current month vs mean of the prior 3 months vs same
    // month last year — all capped to today's day-of-month via the `mtd` matrix.
    $cur   = $first->format('Y-m');
    $lastY = $first->sub(new DateInterval('P12M'))->format('Y-m');
    $prev  = [];
    for ($i = 1; $i <= 3; $i++) $prev[] = $first->sub(new DateInterval('P' . $i . 'M'))->format('Y-m');

    $deltas = [];
    foreach ($catOrder as $cat) {
        $this_ = $pick($mtd, $cur, $cat);
        $avg3  = 0.0;
        foreach ($prev as $pm) $avg3 += $pick($mtd, $pm, $cat);
        $avg3 /= 3;
        $ly = $pick($mtd, $lastY, $cat);
        if ($this_ <= 0 && $avg3 <= 0 && $ly <= 0) continue;   // drop all-empty rows
        $deltas[] = ['category' => $cat, 'this' => $this_, 'avg3' => $avg3, 'lastyear' => $ly];
    }
    usort($deltas, fn($a, $b) => $b['this'] <=> $a['this']);

    return [
        'months'         => $monthsOut,
        'cat_order'      => $catOrder,
        'deltas'         => $deltas,
        'this_total'     => array_sum(array_column($deltas, 'this')),
        'avg3_total'     => array_sum(array_column($deltas, 'avg3')),
        'lastyear_total' => array_sum(array_column($deltas, 'lastyear')),
        'month_label'    => $first->format('M Y'),
    ];
}

/**
 * Single-month money flow — income sources (by payer) → spending categories — for the
 * Money-flow Sankey (#7). VIS-scoped per viewing user. Reuses the EXACT true-expense
 * filter set + split consts every spend aggregation uses, so the two sides RECONCILE TO
 * q_cashflow's income/expense for the same month to the penny: income = SUM(-EFF_AMT)
 * over amount<0 grouped by the merchant display expression (the q_top_merchants idiom —
 * the raw `name` when Plaid gave no enriched merchant_name; grouped by the explicit
 * expression, never a bare alias — the S30 trap); expense = SUM(EFF_AMT) over amount>0
 * grouped by the explicit EFF_CAT (also the S30 trap). Both exclude pending, ext_source,
 * internal transfers (TRANSFER_IN/OUT) and credit-card payments. The month window is
 * anchored in PHP and bound as :start (>=) / :end (<) — never CURDATE() (the S24 TZ
 * trap). Each statement binds :uid (in VIS) once + :start + :end once → HY093-safe.
 *
 * Long tails are folded so the diagram stays legible: top 8 payers (rest → "Other
 * income") and top 12 expense categories (rest → "OTHER"). The page assembles the
 * Sankey nodes/links + the balancing Saved / Drawn-from-savings node from this.
 *
 * Returns:
 *   'ym','month_label'          the requested month + an 'F Y' label
 *   'income_total','expense_total','net'   period totals (net = income − expense)
 *   'income'  => [['payer'=>str,'amount'=>f,'other'=>bool], …]  desc, "Other income" last
 *   'expense' => [['category'=>RAWCAT|'OTHER','amount'=>f,'other'=>bool], …]  desc, "OTHER" last
 */
function q_money_flow(PDO $pdo, int $uid, string $ym): array
{
    // Anchor the calendar-month window in PHP (app TZ) and bind it as half-open
    // [start, end) so the SQL never touches CURDATE() (the S24 TZ trap).
    $first = new DateTimeImmutable($ym . '-01');
    $start = $first->format('Y-m-01');
    $end   = $first->add(new DateInterval('P1M'))->format('Y-m-01');

    // ---- Income (money in): amount < 0, grouped by payer ---------------------
    // Restrict to NON-liability accounts: on a depository account amount<0 is a
    // deposit (real income), but on a loan/credit account the sign is inverted —
    // amount<0 means a payment toward the debt or a refund, NOT household income
    // (e.g. the mortgage account's "Mortgage Payment" −$3,300, or a card refund).
    // Counting those as income overstated both income and the savings rate; they
    // belong nowhere in the income→spending flow (the mortgage already shows in
    // net worth / Property). The symmetric companion to the CC-payment exclusion.
    $si = $pdo->prepare(
        "SELECT COALESCE(NULLIF(t.merchant_name, ''), t.name) AS payer,
                SUM(-(" . EFF_AMT . ")) AS amount
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount < 0 AND t.ext_source IS NULL
           AND a.type NOT IN ('loan','credit')
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start AND t.date < :end
         GROUP BY COALESCE(NULLIF(t.merchant_name, ''), t.name)
         HAVING amount > 0
         ORDER BY amount DESC"
    );
    $si->bindValue(':uid', $uid, PDO::PARAM_INT);
    $si->bindValue(':start', $start);
    $si->bindValue(':end', $end);
    $si->execute();
    $incomeRaw = $si->fetchAll();

    // ---- Expense (money out): amount > 0, grouped by effective category ------
    $se = $pdo->prepare(
        "SELECT " . EFF_CAT . " AS category,
                SUM(" . EFF_AMT . ") AS amount
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         " . SPLIT_JOIN . "
         WHERE " . VIS . " AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND t.date >= :start AND t.date < :end
         GROUP BY " . EFF_CAT . "
         HAVING amount > 0
         ORDER BY amount DESC"
    );
    $se->bindValue(':uid', $uid, PDO::PARAM_INT);
    $se->bindValue(':start', $start);
    $se->bindValue(':end', $end);
    $se->execute();
    $expenseRaw = $se->fetchAll();

    // Fold the long tail so the Sankey stays legible (top N, remainder → "Other").
    $fold = function (array $rows, string $keyCol, int $keep, string $otherKey): array {
        $out = [];
        $otherSum = 0.0;
        $i = 0;
        foreach ($rows as $r) {
            $amt = (float)$r['amount'];
            $key = ($r[$keyCol] === null || $r[$keyCol] === '') ? null : (string)$r[$keyCol];
            if ($i < $keep && $key !== null) {
                $out[] = [$keyCol => $key, 'amount' => $amt, 'other' => false];
                $i++;
            } else {
                $otherSum += $amt;   // beyond the cap, or an unlabeled payer/category
            }
        }
        if ($otherSum > 0) $out[] = [$keyCol => $otherKey, 'amount' => $otherSum, 'other' => true];
        return $out;
    };

    $income  = $fold($incomeRaw,  'payer',    8,  'Other income');
    $expense = $fold($expenseRaw, 'category', 12, 'OTHER');

    $incomeTotal  = array_sum(array_column($income, 'amount'));
    $expenseTotal = array_sum(array_column($expense, 'amount'));

    return [
        'ym'            => $ym,
        'month_label'   => $first->format('F Y'),
        'income_total'  => $incomeTotal,
        'expense_total' => $expenseTotal,
        'net'           => $incomeTotal - $expenseTotal,
        'income'        => $income,
        'expense'       => $expense,
    ];
}

/**
 * Visible transactions. Options:
 *   account_id (string, exact), account (fuzzy name/institution/last-4 — assistant only),
 *   q (search text — matches merchant/name/category AND, #12a, the
 *     account owner's first name), category (exact tag),
 *   from / to (YYYY-MM-DD date bounds, inclusive),
 *   sort ('oldest' = earliest-first; anything else = newest-first, the default),
 *   limit (int, default 100), offset (int, default 0).
 * For pagination, request limit = PAGE_SIZE + 1 and treat an extra row as
 * "there's a next page" (see render_pager()).
 */
/**
 * Build the shared WHERE clause + bound params for a transactions read (#27 assistant v2).
 * Factored out of q_transactions() so q_transactions() and q_transactions_aggregate() apply
 * one identical filter set (same fuzzy/account/amount/date/category/tag semantics) and can't
 * drift. RAW-LEDGER: no true-expense exclusions, no split-explosion — this mirrors what the
 * ledger (transactions.php) and search_transactions surface, so an aggregate reconciles with
 * the row list. ⚠️ HY093: every placeholder name is emitted at most once per statement, and
 * this builder is called exactly once per query, so names never collide. Returns [$where, $params]
 * where $where is a non-empty array of clauses (VIS first) to AND together and $params is the
 * bind map (includes :uid).
 */
function q_transactions_where(int $uid, array $opts = []): array
{
    $where  = [VIS];
    $params = [':uid' => $uid];
    if (!empty($opts['account_id'])) {
        $where[] = 't.account_id = :acct';
        $params[':acct'] = (string)$opts['account_id'];
    }
    if (!empty($opts['category'])) {
        // Third fallback to 'UNCATEGORIZED' mirrors q_spending/q_budgets so a
        // category click-through on rows with no PFC at all still matches. Also
        // match a SPLIT category (#8) — a split drives the category in the spend
        // aggregations, so the drill-through from spending/trends must surface a
        // transaction whose split (not its parent) carries the clicked category.
        // NB: distinct placeholders (:cat / :cat_s) for the two occurrences — this host's
        // PDO runs with ATTR_EMULATE_PREPARES=false (db.php), and native MySQL prepares
        // reject a named placeholder reused more than once (HY093). Same reason the q
        // search below uses :q1/:q2/:q3.
        $where[] = "(COALESCE(t.category_override, " . RULE_CAT . ", t.pfc_primary, 'UNCATEGORIZED') = :cat
                     OR EXISTS (SELECT 1 FROM transaction_splits s
                                WHERE s.transaction_id = t.transaction_id AND s.category = :cat_s))";
        $params[':cat']   = (string)$opts['category'];
        $params[':cat_s'] = (string)$opts['category'];
    }
    if (!empty($opts['tag'])) {
        // Free-form tag filter (#8) — EXISTS keeps it one row per transaction (no
        // JOIN fan-out). Tag names are stored normalised (normalize_tag()).
        $where[] = "EXISTS (SELECT 1 FROM transaction_tags tt JOIN tags tg ON tg.id = tt.tag_id
                            WHERE tt.transaction_id = t.transaction_id AND tg.name = :tag)";
        $params[':tag'] = normalize_tag((string)$opts['tag']);
    }
    if (!empty($opts['merchant'])) {
        // Exact merchant match — the #5 "Top merchants" leaderboard click-through. Match
        // the SAME display expression the leaderboard groups by (merchant_name, else the
        // raw name) so the drill-through resolves a payee that has no enriched
        // merchant_name. Single placeholder → HY093-safe.
        $where[] = "COALESCE(NULLIF(t.merchant_name, ''), t.name) = :merch";
        $params[':merch'] = (string)$opts['merchant'];
    }
    if (!empty($opts['merchant_fuzzy'])) {
        // Fuzzy merchant match for the AI assistant (#27 fuzzy-search upgrade) — match the
        // merchant display name with punctuation/spacing/case stripped from BOTH sides, so
        // "OAces" finds "O'Aces Bar & Grill". Tokenises the term and requires EACH token to
        // appear as a substring of the normalised name (word order / extra words don't matter).
        // ADDITIVE opt — only the assistant sets it; the UI/CSV (transactions.php, account.php,
        // api/export.php) never do, so those stay byte-for-byte in lock-step. Distinct :mf*
        // placeholders → HY093-safe. Typo tolerance (Levenshtein) is NOT done here — that lives
        // in q_merchant_search()/find_merchants (discovery); the agent resolves the canonical
        // spelling there first, then drills in with the exact `merchant` filter.
        $mfTokens = merchant_search_tokens((string)$opts['merchant_fuzzy']);
        if (!$mfTokens) {
            // A non-empty term that tokenises to nothing (e.g. "&&&", "'''") must match NOTHING,
            // never silently fall through to the whole ledger — an empty filter returning every
            // row would let the assistant present unrelated transactions as "your X charges".
            $where[] = '1=0';
        } else {
            $mfDisp = "COALESCE(NULLIF(t.merchant_name, ''), t.name)";
            $mfNorm = "LOWER(REGEXP_REPLACE($mfDisp, '[^a-zA-Z0-9]+', ''))";
            foreach ($mfTokens as $k => $tok) {
                $where[] = "$mfNorm LIKE :mf$k";
                $params[":mf$k"] = '%' . $tok . '%';
            }
        }
    }
    if (!empty($opts['account'])) {
        // Fuzzy ACCOUNT scope for the AI assistant — resolve "American Express" / "amex checking" /
        // a last-4 to the matching account(s) without needing the opaque Plaid account_id. Matches
        // (punctuation/spacing/case-stripped) across the account's display name, official name, its
        // institution, and its mask; EACH token must appear (word order / extra words don't matter),
        // so "american express" and "express" both hit the Amex card. ADDITIVE opt — only the
        // assistant sets it (the UI passes the exact account_id); distinct :af* placeholders → HY093-safe.
        $acctTokens = merchant_search_tokens((string)$opts['account']);
        if (!$acctTokens) {
            $where[] = '1=0';   // garbage term (e.g. "&&&") matches nothing, never the whole ledger
        } else {
            $acctNorm = "LOWER(REGEXP_REPLACE(CONCAT_WS(' ', " . ACCT_NAME . ", COALESCE(a.official_name,''),"
                      . " COALESCE(i.institution_name,''), COALESCE(a.mask,'')), '[^a-zA-Z0-9]+', ''))";
            foreach ($acctTokens as $k => $tok) {
                $where[] = "$acctNorm LIKE :af$k";
                $params[":af$k"] = '%' . $tok . '%';
            }
        }
    }
    if (!empty($opts['from'])) { $where[] = 't.date >= :from'; $params[':from'] = (string)$opts['from']; }
    if (!empty($opts['to']))   { $where[] = 't.date <= :to';   $params[':to']   = (string)$opts['to']; }
    // Amount-range filter (spec §5.2) on the DOLLAR MAGNITUDE — `ABS(t.amount)` — because the
    // ledger shows both inflows and outflows as positive magnitudes (sign `+`=out/`−`=in is rendered
    // as a colour/`+`, not a minus), so "between $50 and $200" means magnitude regardless of
    // direction. is_numeric guards a crafted non-numeric URL (else it'd coerce to 0 and over-match);
    // is_finite additionally rejects an overflow like "1e400" → (float)INF, which MySQL coerces to 0
    // and would silently INVERT the filter (amin=INF → matches all; amax=INF → matches none).
    // Distinct placeholders :amin/:amax (each used once) → HY093-safe. Keep in lock-step with api/export.php.
    if (isset($opts['amin']) && is_numeric($opts['amin']) && is_finite((float)$opts['amin'])) { $where[] = 'ABS(t.amount) >= :amin'; $params[':amin'] = (float)$opts['amin']; }
    if (isset($opts['amax']) && is_numeric($opts['amax']) && is_finite((float)$opts['amax'])) { $where[] = 'ABS(t.amount) <= :amax'; $params[':amax'] = (float)$opts['amax']; }
    if (!empty($opts['q'])) {
        // Escape the user's own LIKE metacharacters (% _ \) so a literal '_' (common
        // in PFC tags like FOOD_AND_DRINK) or '%' isn't treated as a wildcard.
        $term = addcslashes((string)$opts['q'], '\\%_');
        // Distinct placeholders per occurrence — native prepares (emulation off) reject a
        // reused named placeholder (HY093). See the category clause above.
        $clauses = ["t.merchant_name LIKE :q1 ESCAPE '\\\\'",
                    "t.name LIKE :q2 ESCAPE '\\\\'",
                    "COALESCE(t.category_override, t.pfc_primary) LIKE :q3 ESCAPE '\\\\'"];
        $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $term . '%';
        // #12a (S13 follow-up): also match the account owner's first name, resolved in PHP
        // to the owning user id(s) (no extra JOIN). OR-only — this can only ADD matches, never
        // drop a merchant/category hit. Distinct :qo* placeholders → HY093-safe.
        foreach (owner_ids_matching((string)$opts['q']) as $k => $oid) {
            $clauses[] = "i.user_id = :qo$k";
            $params[":qo$k"] = $oid;
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
    return [$where, $params];
}

function q_transactions(PDO $pdo, int $uid, array $opts = []): array
{
    [$where, $params] = q_transactions_where($uid, $opts);
    $limit  = max(1, (int)($opts['limit'] ?? 100));
    $offset = max(0, (int)($opts['offset'] ?? 0));
    // Ordering: default (and everything the UI passes) stays newest-first, byte-identical. The AI
    // assistant may pass sort='oldest' to walk from the earliest transaction (e.g. "what's the
    // oldest charge on my Amex") in a single query instead of scanning the whole history.
    $order = (($opts['sort'] ?? '') === 'oldest')
        ? 'ORDER BY t.date ASC, t.imported_at ASC'
        : 'ORDER BY t.date DESC, t.imported_at DESC';
    $sql = "SELECT t.transaction_id, t.date, t.merchant_name, t.name, t.logo_url, t.amount, t.pending, t.note,
                   COALESCE(t.category_override, " . RULE_CAT . ", t.pfc_primary) AS category,
                   " . ACCT_NAME . " AS account_name, a.mask, a.account_id, i.user_id AS owner_id
            FROM transactions t
            JOIN accounts a ON t.account_id = a.account_id
            JOIN items i ON a.item_id = i.item_id
            WHERE " . implode(' AND ', $where) . "
            " . $order . "
            LIMIT " . $limit . " OFFSET " . $offset;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Aggregate transactions in ONE query (#27 assistant v2 — kills pagination). Same filter set as
 * q_transactions() (via the shared q_transactions_where() builder), plus a `group_by` ∈
 * {none, category, merchant, month, account}. RAW-LEDGER semantics — no true-expense exclusions,
 * no split-explosion — so counts/totals reconcile with search_transactions (NOT with the spend
 * pages). Sign convention: `+` = money OUT, `−` = money IN, so `total_out` sums positive amounts
 * and `total_in` sums |negatives|; avg/min/max are over the ABS magnitude the ledger displays.
 * Answers "how many times / total / average at X" without paging. Caps at 50 groups. Returns
 * ['group_by'=>$group, 'groups'=>[ ['grp'=>?, 'n','total_out','total_in','avg_abs','min_abs',
 * 'max_abs','first_date','last_date'] ... ]] ('grp' absent when group_by='none').
 * HY093-safe: q_transactions_where() emits each placeholder once; RULE_CAT has no binds.
 */
function q_transactions_aggregate(PDO $pdo, int $uid, array $opts = []): array
{
    [$where, $params] = q_transactions_where($uid, $opts);

    $groupExprs = [
        'category' => "COALESCE(t.category_override, " . RULE_CAT . ", t.pfc_primary, 'UNCATEGORIZED')",
        'merchant' => "COALESCE(NULLIF(t.merchant_name, ''), t.name)",
        'month'    => "DATE_FORMAT(t.date, '%Y-%m')",
        'account'  => ACCT_NAME,
    ];
    $group     = (string)($opts['group_by'] ?? 'none');
    $groupExpr = $groupExprs[$group] ?? null;

    $agg = "COUNT(*) AS n,
            SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END) AS total_out,
            SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END) AS total_in,
            AVG(ABS(t.amount)) AS avg_abs,
            MIN(ABS(t.amount)) AS min_abs,
            MAX(ABS(t.amount)) AS max_abs,
            MIN(t.date) AS first_date,
            MAX(t.date) AS last_date";
    $from = "FROM transactions t
             JOIN accounts a ON t.account_id = a.account_id
             JOIN items i ON a.item_id = i.item_id
             WHERE " . implode(' AND ', $where);

    if ($groupExpr === null) {
        $st = $pdo->prepare("SELECT $agg $from");
        $st->execute($params);
        $row = $st->fetch();
        return ['group_by' => 'none', 'groups' => $row ? [$row] : []];
    }

    // Month reads chronologically; every other grouping is ranked by outflow (most relevant first).
    $order = $group === 'month' ? 'ORDER BY grp ASC' : 'ORDER BY total_out DESC, n DESC';
    $st = $pdo->prepare("SELECT $groupExpr AS grp, $agg $from GROUP BY $groupExpr $order LIMIT 50");
    $st->execute($params);
    return ['group_by' => $group, 'groups' => $st->fetchAll()];
}

/**
 * Tokenise a free-text merchant-search term (#27 fuzzy-search upgrade): split on any
 * non-alphanumeric run, lowercase, dedupe, cap at 6 tokens. So "O'Aces Bar" → ['oaces','bar'],
 * "OAces" → ['oaces'] (no separator = one token), "  " → []. Shared by q_transactions'
 * merchant_fuzzy opt (SQL substring match) and q_merchant_search() (PHP match + typo pass) so
 * the two agree on what a "token" is. Returns [] for an empty/garbage term (callers no-op then).
 */
function merchant_search_tokens(string $term): array
{
    $parts = preg_split('/[^a-z0-9]+/i', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $seen = [];
    foreach ($parts as $p) {
        $p = strtolower($p);
        if ($p === '' || isset($seen[$p])) continue;
        $seen[$p] = true;
        if (count($seen) >= 6) break;
    }
    return array_keys($seen);
}

/**
 * Score how well a merchant display name matches a set of normalised query tokens (#27).
 * PURE — no DB — so it is unit-testable and shared by q_merchant_search().
 *
 * Each query token must match the merchant, either as a contiguous substring of the
 * punctuation-stripped name (cost 0 — the "OAces" → "O'Aces Bar & Grill" case) OR, for tokens
 * of length ≥ 4, within a small Levenshtein distance of one of the merchant's word-tokens
 * (typo tolerance — "starbcks" → "Starbucks"); a typo match costs its edit distance + 0.5 so a
 * clean match always ranks above a fuzzy one. If ANY token fails, the merchant does not match
 * (returns null). Otherwise returns the summed cost (0 = every token matched exactly), which
 * the caller uses as the primary sort key (lower = better).
 */
function merchant_match_score(array $tokens, string $merchant): ?float
{
    $low      = strtolower($merchant);
    $normFull = (string)preg_replace('/[^a-z0-9]+/', '', $low);
    if ($normFull === '') return null;
    $mTokens  = preg_split('/[^a-z0-9]+/', $low, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $total = 0.0;
    foreach ($tokens as $qt) {
        $len = strlen($qt);
        if ($len === 0) continue;
        if (strpos($normFull, $qt) !== false) continue;   // strong contiguous match → cost 0
        if ($len < 4) return null;                         // too short to fuzzy-match safely
        $thr  = $len <= 5 ? 1 : 2;                          // allow 1–2 typos by token length
        $best = PHP_INT_MAX;
        foreach ($mTokens as $mt) {
            if (abs(strlen($mt) - $len) > $thr) continue;   // length gap can't be within $thr edits
            $d = levenshtein($qt, $mt);
            if ($d < $best) $best = $d;
        }
        if ($best > $thr) return null;                      // this token didn't match the name
        $total += $best + 0.5;                              // a typo match ranks below an exact one
    }
    return $total;
}

/**
 * Fuzzy merchant discovery (#27 fuzzy-search upgrade) — find distinct payees whose name
 * LOOSELY matches a free-text term, ignoring punctuation / spacing / capitalization (and
 * tolerating small typos), so "OAces" finds "O'Aces Bar & Grill" and "starbcks" finds
 * "Starbucks". VIS-scoped. Aggregates per canonical merchant over an optional date window so
 * the assistant can both (a) answer "how many transactions at X" directly and (b) learn the
 * exact merchant spelling to drill in with q_transactions' exact `merchant` filter.
 *
 * Casts a wide RECALL net (the SQL just aggregates every visible merchant in the window — a
 * few hundred rows for this 2-person household, so a full scan is fine); PRECISION is done in
 * PHP via merchant_match_score() (where Levenshtein lives) and the result is RANKED best-first
 * so the model gets a clean, ordered candidate set it can reason over. Sign convention: in this
 * app `+` = money out, so `spent` sums positive amounts and `received` sums |negatives|.
 *
 * $opts: from / to (Y-m-d window, app-TZ — pass dates, never CURDATE()), limit (default 25, cap 50).
 * Returns rows: [merchant, txn_count, spent, received, first_date, last_date] best-match first.
 */
function q_merchant_search(PDO $pdo, int $uid, string $term, array $opts = []): array
{
    $tokens = merchant_search_tokens($term);
    if (!$tokens) return [];
    $limit = max(1, min(50, (int)($opts['limit'] ?? 25)));

    $disp   = "COALESCE(NULLIF(t.merchant_name, ''), t.name)";
    $where  = [VIS, "$disp <> ''"];
    $params = [':uid' => $uid];
    if (!empty($opts['from'])) { $where[] = 't.date >= :from'; $params[':from'] = (string)$opts['from']; }
    if (!empty($opts['to']))   { $where[] = 't.date <= :to';   $params[':to']   = (string)$opts['to']; }

    $sql = "SELECT $disp AS merchant,
                   COUNT(*) AS txn_count,
                   ROUND(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 2) AS spent,
                   ROUND(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 2) AS received,
                   MIN(t.date) AS first_date, MAX(t.date) AS last_date
            FROM transactions t
            JOIN accounts a ON t.account_id = a.account_id
            JOIN items i ON a.item_id = i.item_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY $disp";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $scored = [];
    foreach ($st->fetchAll() as $m) {
        $score = merchant_match_score($tokens, (string)$m['merchant']);
        if ($score === null) continue;
        $m['_score'] = $score;
        $scored[] = $m;
    }
    // Rank: best match first (lower score), then most transactions, then most recent.
    usort($scored, fn($a, $b) =>
        [$a['_score'], -(int)$a['txn_count'], $b['last_date']]
        <=> [$b['_score'], -(int)$b['txn_count'], $a['last_date']]);
    $scored = array_slice($scored, 0, $limit);
    foreach ($scored as &$s) unset($s['_score']);
    unset($s);
    return $scored;
}

/**
 * Normalise a free-form tag (#8): strip a leading '#', lowercase, collapse internal
 * whitespace to '-', drop anything but [a-z0-9-], trim stray dashes, cap at 32 chars.
 * Returns '' for an empty/garbage tag (callers reject ''). Shared by the write path
 * (api/account.php add_tag), the read filter (q_transactions 'tag' opt) and all_tags.
 */
function normalize_tag(string $raw): string
{
    $t = strtolower(trim($raw));
    $t = ltrim($t, '#');
    $t = preg_replace('/\s+/', '-', $t);
    $t = preg_replace('/[^a-z0-9-]/', '', (string)$t);
    $t = trim((string)$t, '-');
    return substr($t, 0, 32);
}

/**
 * The household tag vocabulary (#8) — every tag name, alphabetical. Used to populate
 * the add-tag autocomplete datalist + the transactions tag filter. NOT VIS-scoped:
 * `tags` is a shared household vocabulary (like categories), not per-account data.
 */
function all_tags(PDO $pdo): array
{
    return $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Bulk-attach notes/tags/splits metadata (#8) to a page of transaction rows fetched
 * by q_transactions (or any list carrying `transaction_id`). Loads tags + splits for
 * the whole page in two `IN (…)` queries (no per-row N+1) and sets, on each row:
 *   $row['tags']   => [['id'=>int,'name'=>str], …]            (alphabetical)
 *   $row['splits'] => [['category'=>str,'amount'=>float,'note'=>?str], …]
 * `note` is already selected by q_transactions. Mutates $rows in place. The rows are
 * already VIS-scoped by the feeding query, so this needs no visibility clause.
 */
function attach_tx_meta(PDO $pdo, array &$rows): void
{
    if (!$rows) return;
    $ids = [];
    foreach ($rows as $r) { if (!empty($r['transaction_id'])) $ids[] = $r['transaction_id']; }
    if (!$ids) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $tagsByTx = [];
    $ts = $pdo->prepare(
        "SELECT tt.transaction_id, tg.id, tg.name
         FROM transaction_tags tt JOIN tags tg ON tg.id = tt.tag_id
         WHERE tt.transaction_id IN ($ph)
         ORDER BY tg.name"
    );
    $ts->execute($ids);
    foreach ($ts->fetchAll() as $row) {
        $tagsByTx[$row['transaction_id']][] = ['id' => (int)$row['id'], 'name' => $row['name']];
    }

    $splitsByTx = [];
    $ss = $pdo->prepare(
        "SELECT transaction_id, category, amount, note
         FROM transaction_splits
         WHERE transaction_id IN ($ph)
         ORDER BY id"
    );
    $ss->execute($ids);
    foreach ($ss->fetchAll() as $row) {
        $splitsByTx[$row['transaction_id']][] = [
            'category' => $row['category'],
            'amount'   => (float)$row['amount'],
            'note'     => $row['note'],
        ];
    }

    // Refund watch state (#34) for the meta strip — pending/received per purchase.
    $refundByTx = [];
    $rs = $pdo->prepare(
        "SELECT transaction_id, status, matched_tx_id FROM refund_watch WHERE transaction_id IN ($ph)"
    );
    $rs->execute($ids);
    foreach ($rs->fetchAll() as $row) {
        $refundByTx[$row['transaction_id']] = [
            'status'        => $row['status'],
            'matched_tx_id' => $row['matched_tx_id'],
        ];
    }

    foreach ($rows as &$r) {
        $tid = $r['transaction_id'] ?? '';
        $r['tags']   = $tagsByTx[$tid]   ?? [];
        $r['splits'] = $splitsByTx[$tid] ?? [];
        $r['refund'] = $refundByTx[$tid] ?? null;
    }
    unset($r);
}

/**
 * Refund tracking (#34). The household-shared but VIS-SCOPED list of flagged purchases for
 * `refunds.php`: every refund_watch row whose underlying PURCHASE the viewer can see (VIS),
 * with the purchase's display fields. The matching credit (matched_tx_id) is NOT joined here
 * — `lib/refunds.php` looks it up from the VIS-scoped `q_refund_credits()` pool so the credit's
 * details are independently visibility-checked (and the pool covers it: a matched credit is
 * dated ≥ its purchase ≥ the pool's `$since`). Single `:uid` bind (VIS) → HY093-safe.
 * Order: pending first, then newest purchase first.
 */
function q_refund_watches(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare(
        "SELECT rw.transaction_id, rw.status, rw.matched_tx_id, rw.created_at, rw.resolved_at,
                t.amount, t.date, t.merchant_name, t.name, t.logo_url,
                t.account_id, " . ACCT_NAME . " AS account_name, a.mask, i.user_id AS owner_id
         FROM refund_watch rw
         JOIN transactions t ON t.transaction_id = rw.transaction_id
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . "
         ORDER BY (rw.status = 'pending') DESC, t.date DESC, rw.created_at DESC"
    );
    $st->execute([':uid' => $uid]);
    return $st->fetchAll();
}

/**
 * Refund tracking (#34). The VIS-scoped candidate pool of money-in (credit) transactions on or
 * after `$since` (Y-m-d, PHP app-TZ — never CURDATE()), used by `lib/refunds.php` to (a) suggest
 * matches for a pending watch and (b) resolve a confirmed `matched_tx_id` to its display detail.
 * SETTLED only (`pending = 0`) — a pending credit re-issues a new transaction_id when it settles,
 * so confirming a pending id would dangle. Excludes INCOME / TRANSFER_IN categories (a purchase
 * refund is neither a paycheck nor an internal transfer — a noise filter against coincidental
 * amount matches). Distinct `:uid` (VIS) + `:since` → HY093-safe.
 */
function q_refund_credits(PDO $pdo, int $uid, string $since): array
{
    $st = $pdo->prepare(
        "SELECT t.transaction_id, t.amount, t.date, t.merchant_name, t.name,
                t.account_id, " . ACCT_NAME . " AS account_name, a.mask, i.user_id AS owner_id
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . "
           AND t.amount < 0
           AND t.pending = 0
           AND t.date >= :since
           AND COALESCE(t.category_override, t.pfc_primary, 'UNCATEGORIZED') NOT IN ('INCOME', 'TRANSFER_IN')
         ORDER BY t.date ASC, t.transaction_id ASC"
    );
    $st->execute([':uid' => $uid, ':since' => $since]);
    return $st->fetchAll();
}

/**
 * Normalise a category-rule match value (#10): trim, strip the LIKE metacharacters
 * `% _ \` (a merchant fragment never needs them, and leaving them in would let a stray
 * `%` act as a wildcard in the RULE_MATCH `LIKE`), UPPER-case (the predicate compares
 * UPPER(...)), and cap at 255. Returns '' for empty/garbage input (callers reject '').
 * Shared by the write path (api/rules.php) so what's stored matches what RULE_MATCH compares.
 */
function normalize_rule_value(string $raw): string
{
    $v = trim($raw);
    $v = str_replace(['%', '_', '\\'], '', $v);
    $v = function_exists('mb_strtoupper') ? mb_strtoupper($v) : strtoupper($v);
    return function_exists('mb_substr') ? mb_substr($v, 0, 255) : substr($v, 0, 255);
}

/**
 * The household category-rule list (#10) for the management page. The rule ROWS are
 * household-shared (both users manage the same rules, like `tags`/`budgets`) — every rule is
 * returned to every viewer. But the per-rule **`match_count` is VIS-scoped to `$uid`** (Session
 * 64 follow-up): it counts only the transactions THIS viewer can see, so a rule whose matches
 * live on the other user's `private`/`hidden` account reads `0 matches` for them — a household-wide
 * count would otherwise leak how many of the other user's private transactions exist. The count
 * reuses the exact RULE_MATCH predicate (`t`=transactions, `cr`=the rule row) plus the VIS clause,
 * so it can't drift from what RULE_CAT actually applies to this viewer. VIS appears once → single
 * `:uid` bind → HY093-safe.
 */
function q_category_rules(PDO $pdo, int $uid): array
{
    $sql = "SELECT cr.id, cr.match_type, cr.match_value, cr.category, cr.priority, cr.created_by,
                   (SELECT COUNT(*) FROM transactions t
                      JOIN accounts a ON t.account_id = a.account_id
                      JOIN items i ON a.item_id = i.item_id
                      WHERE " . VIS . " AND " . RULE_MATCH . ") AS match_count
            FROM category_rules cr
            ORDER BY cr.match_type, cr.match_value";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $uid]);
    return $st->fetchAll();
}

/**
 * The household custom-category list (migration 024) for the management UI. The category ROWS are
 * household-shared (every viewer sees every category) and DEFENSIVE (no table yet → []). Each row
 * carries reference counts so the page can show a category's reach + the delete confirmation can
 * quote the impact. **The two transaction-derived counts (`used_tx`, `used_splits`) are VIS-scoped
 * to `$uid`** (Session 64 follow-up) — they count only what THIS viewer can see, so a category whose
 * transactions live on the other user's `private`/`hidden` account reads `0` for them (a household-wide
 * count would leak how many of the other user's private transactions exist). `used_rules` / `used_budgets`
 * stay household-wide on purpose — rules and budgets are themselves shared vocabulary shown to everyone,
 * so counting them exposes nothing per-account. ⚠️ VIS appears in BOTH transaction subqueries → bind
 * DISTINCT `:uid_t`/`:uid_s` (HY093 — a reused named placeholder 500s under emulation-off).
 */
function q_custom_categories(PDO $pdo, int $uid): array
{
    $visTx = str_replace(':uid', ':uid_t', VIS);
    $visSp = str_replace(':uid', ':uid_s', VIS);
    try {
        $st = $pdo->prepare(
            "SELECT cc.id, cc.tag, cc.label, cc.exclude_from_spending,
                    (SELECT COUNT(*) FROM transactions t
                       JOIN accounts a ON t.account_id = a.account_id
                       JOIN items i ON a.item_id = i.item_id
                       WHERE " . $visTx . " AND t.category_override = cc.tag)               AS used_tx,
                    (SELECT COUNT(*) FROM category_rules cr     WHERE cr.category = cc.tag) AS used_rules,
                    (SELECT COUNT(*) FROM budgets b             WHERE b.category = cc.tag)  AS used_budgets,
                    (SELECT COUNT(*) FROM transaction_splits s
                       JOIN transactions t ON t.transaction_id = s.transaction_id
                       JOIN accounts a ON t.account_id = a.account_id
                       JOIN items i ON a.item_id = i.item_id
                       WHERE " . $visSp . " AND s.category = cc.tag)                        AS used_splits
             FROM custom_categories cc
             ORDER BY cc.label"
        );
        $st->execute([':uid_t' => $uid, ':uid_s' => $uid]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Savings goals (#9). The goal SET is household-shared (both users manage the same goals, like
 * q_budgets / q_category_rules), but a goal TIED to an account exposes that account's name +
 * live balance as its progress — so the per-account detail is **VIS-scoped to $uid**: if the
 * viewer can't see the tied account (it's another user's `private`, or any `hidden` account),
 * the goal still shows (name + target are shared) but its **account name + balance are masked**
 * — `account_name='(private account)'`, owner suffix suppressed, and current/pct/remaining
 * nulled — so a non-owner can never read a private account's balance via a goal. A manual goal
 * uses its stored current_amount and is never masked. The reused VIS clause binds :uid once →
 * no repeated-placeholder (HY093) risk; keep it that way.
 */
function q_goals(PDO $pdo, int $uid): array
{
    $sql = "SELECT g.id, g.name, g.target_amount, g.account_id, g.current_amount, g.created_by,
                   " . ACCT_NAME . " AS account_name, a.balance_current AS account_balance,
                   a.account_id AS tied_acct_id, (" . VIS . ") AS acct_visible,
                   i.user_id AS owner_id
            FROM goals g
            LEFT JOIN accounts a ON g.account_id = a.account_id
            LEFT JOIN items i ON a.item_id = i.item_id
            ORDER BY g.created_at ASC, g.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $uid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$g) {
        $target      = (float)$g['target_amount'];
        $tied        = $g['account_id'] !== null && $g['account_id'] !== '';
        $acctExists  = $g['tied_acct_id'] !== null;          // the LEFT JOIN matched a row
        $acctVisible = (int)$g['acct_visible'] === 1;        // …and it's VIS-visible to $uid

        if ($tied && $acctExists && !$acctVisible) {
            // Tied to an account this viewer can't see (another user's private, or hidden):
            // keep the shared goal + target, but reveal NOTHING about the account — no name,
            // no owner, no balance-derived progress.
            $g['account_name']   = '(private account)';
            $g['owner_id']       = null;     // suppress the "· OwnerName" suffix
            $g['account_balance']= null;     // never serialise the private balance (api/goals.php echoes the row)
            $g['current']        = null;
            $g['target']         = round($target, 2);
            $g['tied']           = true;
            $g['pct']            = null;
            $g['remaining']      = null;
            $g['reached']        = false;
            $g['private_hidden'] = true;
        } else {
            $current = $tied ? (float)($g['account_balance'] ?? 0) : (float)($g['current_amount'] ?? 0);
            $g['current']   = round($current, 2);
            $g['target']    = round($target, 2);
            $g['tied']      = $tied;
            $g['pct']       = $target > 0 ? min(100, max(0, $current / $target * 100)) : 0;
            $g['remaining'] = round(max(0, $target - $current), 2);
            $g['reached']   = $current >= $target && $target > 0;
            $g['private_hidden'] = false;
            // A tied goal whose account vanished (re-link/removal) has a NULL account_name.
            if ($tied && ($g['account_name'] === null || $g['account_name'] === '')) {
                $g['account_name'] = '(account unavailable)';
            }
        }
        unset($g['tied_acct_id'], $g['acct_visible']);   // internal helper columns
    }
    unset($g);
    return $rows;
}

/**
 * Accounts a Plaid bank has STOPPED reporting for ≥ $minDays consecutive days (code review
 * 5.9 — `missing_since` is stamped by sync_balances). Their balance is frozen yet still counts
 * in net worth, so settings.php SURFACES them ("no longer reported by your bank — hide?"); we
 * never auto-hide (honest-number). VIS-scoped (VIS already excludes `hidden`). Age is computed
 * SQL-side (S24-safe). Column-guarded → [] if migration 034 hasn't run yet. Keyed lookups are
 * built by the caller from `account_id`.
 */
function q_stale_missing_accounts(PDO $pdo, int $uid, int $minDays = 7): array
{
    try {
        $st = $pdo->prepare(
            "SELECT a.account_id, " . ACCT_NAME . " AS name, a.mask, a.visibility,
                    a.balance_current, i.institution_name, i.user_id AS owner_id,
                    TIMESTAMPDIFF(DAY, a.missing_since, NOW()) AS missing_days
             FROM accounts a
             JOIN items i ON i.item_id = a.item_id
             WHERE " . VIS . " AND a.missing_since IS NOT NULL
               AND TIMESTAMPDIFF(DAY, a.missing_since, NOW()) >= :mind
             ORDER BY missing_days DESC"
        );
        $st->bindValue(':uid', $uid, PDO::PARAM_INT);
        $st->bindValue(':mind', $minDays, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];   // pre-migration-034 / transient — never break the page
    }
}

/**
 * Present a liability account's balance HONESTLY, distinguishing money OWED from an
 * OVERPAYMENT/credit (code review 5.10). Plaid liability convention: balance_current > 0 =
 * amount owed; a NEGATIVE balance = the account is overpaid (a credit in the holder's favour),
 * which REDUCES debt and must NOT render as red "debt". Returns:
 *   ['text'  => money string WITH sign ("-$500.00" owed / "+$50.00 credit" / "$0.00"),
 *    'class' => 'neg' (owed) | 'pos' (credit) | '' (zero),   // for the amount span
 *    'signed'=> float ]                                       // net-worth contribution: -bal
 * Callers must still e() the text at the render site.
 */
function liability_balance_display(float $bal): array
{
    if ($bal < 0)   return ['text' => '+' . usd(abs($bal)) . ' credit', 'class' => 'pos', 'signed' => -$bal];
    if ($bal == 0.0) return ['text' => usd(0),                          'class' => '',    'signed' => 0.0];
    return ['text' => '-' . usd($bal), 'class' => 'neg', 'signed' => -$bal];
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
                " . ACCT_NAME . " AS account_name, a.mask, a.balance_current, a.account_id,
                i.user_id AS owner_id
         FROM liabilities l
         JOIN accounts a ON l.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY a.name"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Debt payoff planner source (TODO2 #33). Every VIS-visible credit/loan account with its
 * LEFT-JOINed Plaid `liabilities` detail (APR / minimum / last payment / type) — so EVERY debt
 * shows, and the ones the bank doesn't report APR/payment detail for are flagged (not dropped),
 * unlike q_liabilities which only returns accounts that HAVE a liabilities row. VIS-scoped
 * (single `:uid` bind → HY093-safe). Selects a.subtype so the mortgage toggle can identify it.
 * lib/debt.php normalizes + simulates; balances are filtered to > 0 there.
 */
function q_debts(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare(
        "SELECT " . ACCT_NAME . " AS account_name, a.account_id, a.mask, a.type, a.subtype,
                a.balance_current, i.user_id AS owner_id,
                l.liability_type, l.apr_percentage, l.outstanding_balance,
                l.minimum_payment_amount, l.last_payment_amount
         FROM accounts a
         JOIN items i ON a.item_id = i.item_id
         LEFT JOIN liabilities l ON l.account_id = a.account_id
         WHERE " . VIS . " AND a.type IN ('credit', 'loan')
         ORDER BY a.name"
    );
    $st->execute([':uid' => $uid]);
    return $st->fetchAll();
}

/* ---- Credit reports (TODO2 #28) -----------------------------------------
 * Reports are HOUSEHOLD-VISIBLE (either signed-in user sees both people's pulls), so these
 * reads are deliberately NOT VIS-scoped — credit_reports carries its own user_id (whose
 * report it is) and the page labels it via owner_first_name(). The sensitive columns
 * (*_enc) are returned ENCRYPTED; lib/credit.php decrypts them. All placeholders are
 * distinct/positional → HY093-safe. The page gates on require_login() only.
 */

/** Every stored credit report header, newest pull first. */
function q_credit_reports(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, user_id, bureau, pulled_on, score, score_model, consumer_name_enc,
                created_by, created_at
         FROM credit_reports
         ORDER BY pulled_on DESC, id DESC"
    )->fetchAll();
}

/** One credit report header, or null. */
function q_credit_report(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        "SELECT id, user_id, bureau, pulled_on, score, score_model, consumer_name_enc,
                created_by, created_at
         FROM credit_reports WHERE id = ? LIMIT 1"
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** The most recent prior pull for the same (user, bureau) strictly before $before. */
function q_credit_prior_report(PDO $pdo, int $userId, string $bureau, string $before): ?array
{
    $st = $pdo->prepare(
        "SELECT id, pulled_on, score
         FROM credit_reports
         WHERE user_id = ? AND bureau = ? AND pulled_on < ?
         ORDER BY pulled_on DESC, id DESC LIMIT 1"
    );
    $st->execute([$userId, $bureau, $before]);
    return $st->fetch() ?: null;
}

/** Tradelines (accounts) for one report, in display order. Sensitive cols still encrypted. */
function q_credit_tradelines(PDO $pdo, int $reportId): array
{
    $st = $pdo->prepare(
        "SELECT id, creditor_enc, account_mask_enc, account_type, balance, credit_limit,
                high_balance, monthly_payment, past_due, opened_on, closed_on, is_open,
                responsibility, status
         FROM credit_tradelines WHERE credit_report_id = ?
         ORDER BY sort_order, id"
    );
    $st->execute([$reportId]);
    return $st->fetchAll();
}

/** Inquiries for one report. inquirer_enc still encrypted. */
function q_credit_inquiries(PDO $pdo, int $reportId): array
{
    $st = $pdo->prepare(
        "SELECT id, inquirer_enc, inquiry_date, inquiry_type
         FROM credit_inquiries WHERE credit_report_id = ?
         ORDER BY inquiry_date DESC, id"
    );
    $st->execute([$reportId]);
    return $st->fetchAll();
}

/** Derogatory flags for one report. detail_enc still encrypted. */
function q_credit_flags(PDO $pdo, int $reportId): array
{
    $st = $pdo->prepare(
        "SELECT id, kind, detail_enc, amount, flag_date
         FROM credit_flags WHERE credit_report_id = ?
         ORDER BY sort_order, id"
    );
    $st->execute([$reportId]);
    return $st->fetchAll();
}

/** Investment holdings. Optionally scoped to one account. */
function q_holdings(PDO $pdo, int $uid, ?string $accountId = null): array
{
    $where  = [VIS];
    $params = [':uid' => $uid];
    if ($accountId !== null) { $where[] = 'h.account_id = :acct'; $params[':acct'] = $accountId; }
    $st = $pdo->prepare(
        "SELECT s.ticker_symbol, s.name AS security_name, s.type AS security_type,
                h.security_id, h.quantity, h.cost_basis, h.institution_price, h.institution_value,
                " . ACCT_NAME . " AS account_name, a.mask, a.account_id, a.last_updated_datetime,
                a.type, a.subtype, a.retirement_flag,
                i.institution_name, i.source, i.manual_type, i.user_id AS owner_id
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

/**
 * Investment activity (dividends/interest OR trades), UNIONing the two feeds:
 *   - MANUAL (Webull): brokerage cash rows in `transactions` (ext_source IS NOT NULL),
 *     classified by `pfc_primary`.
 *   - PLAID (Session 34/#18) + MANUAL-401(k) imports (Session 55/#25): rows in
 *     `investment_transactions` (ext_source IN ('plaid','manual_ret')), classified by
 *     type/subtype/side. The manual-401(k) statement importer (lib/statement_import.php)
 *     writes dividend/capital-gain/fee lines tagged ext_source='manual_ret'; the income
 *     predicate matches '%capital%' so a fund's capital-gain distribution shows alongside
 *     dividends/interest.
 * VIS-scoped per viewing user. $kind ∈ 'income' | 'trades' | 'contributions'. Amount keeps the stored
 * sign (+ = money out, − = money in); the caller renders − as a green inflow.
 *
 * Scoped to an explicit $accountIds set — the accounts the CALLING PAGE renders — so
 * Betterment's activity lands on retirement.php and a non-retirement brokerage on
 * investments.php (the same account is never shown on both). Empty set ⇒ no rows.
 * Pass $limit = PAGE_SIZE + 1 and slice to detect the next page.
 *
 * ⚠️ HY093: the VIS clause contains :uid and appears in BOTH union arms — this host's
 * native prepares (emulation off) reject a :name reused across the statement — so each
 * arm binds a DISTINCT :uid_m / :uid_p, and the per-arm account-id IN-lists use
 * distinct placeholder names. The kind predicates are constant literals (no binds).
 */
function q_investment_activity(PDO $pdo, int $uid, string $kind, array $accountIds, int $limit = 50, int $offset = 0): array
{
    $accountIds = array_values(array_unique(array_filter($accountIds, fn($x) => $x !== null && $x !== '')));
    if (!$accountIds) return [];
    [$sql, $params] = inv_activity_union($uid, $kind, $accountIds);
    $st = $pdo->prepare(
        "SELECT * FROM ($sql) u
         ORDER BY u.tdate DESC, u.sortts DESC
         LIMIT " . max(1, (int)$limit) . " OFFSET " . max(0, (int)$offset)
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Signed total of investment-activity amounts (all rows, not one page) for $kind over
 * $accountIds — so the Dividends & interest header stays accurate while the list below
 * is paginated. Keeps the stored sign (+ = money out, − = money in).
 */
function q_investment_activity_total(PDO $pdo, int $uid, string $kind, array $accountIds): float
{
    $accountIds = array_values(array_unique(array_filter($accountIds, fn($x) => $x !== null && $x !== '')));
    if (!$accountIds) return 0.0;
    [$sql, $params] = inv_activity_union($uid, $kind, $accountIds);
    $st = $pdo->prepare("SELECT COALESCE(SUM(u.amount), 0) FROM ($sql) u");
    $st->execute($params);
    return (float)$st->fetchColumn();
}

/**
 * Builds the manual ∪ plaid investment-activity UNION for one $kind + $accountIds,
 * returning [$sql, $params]. Both arms emit the SAME normalized columns:
 *   tdate, title, amount, account_name, mask, account_id, owner_id, sortts.
 * Internal helper for q_investment_activity / _total only.
 */
function inv_activity_union(int $uid, string $kind, array $accountIds): array
{
    $params = [':uid_m' => $uid, ':uid_p' => $uid];
    $mIn = []; $pIn = [];
    foreach ($accountIds as $k => $aid) {
        $params[":am$k"] = $aid; $mIn[] = ":am$k";
        $params[":ap$k"] = $aid; $pIn[] = ":ap$k";
    }
    // Kind predicates are constant literals (no binds).
    if ($kind === 'trades') {
        $mKind = "t.pfc_primary IN ('INVESTMENT')";
        $pKind = "it.side IN ('buy','sell')";
    } elseif ($kind === 'contributions') {
        // Deposits into the account (Plaid 'contribution' subtype — e.g. payroll 401(k)
        // contributions). The manual Webull feed has no contribution concept (1=0 → the
        // arm contributes no rows but stays a valid, bound UNION half). Kept SEPARATE from
        // 'income' so a contribution never inflates the dividends/interest total.
        $mKind = "1 = 0";
        $pKind = "(it.type = 'cash' AND it.subtype LIKE '%contribution%')";
    } else { // 'income' — dividends + interest
        $mKind = "t.pfc_primary IN ('INCOME_DIVIDENDS','INCOME_INTEREST')";
        $pKind = "(it.type = 'cash' AND (it.subtype LIKE '%dividend%' OR it.subtype LIKE '%interest%' OR it.subtype LIKE '%capital%'))";
    }
    $visM = str_replace(':uid', ':uid_m', VIS);
    $visP = str_replace(':uid', ':uid_p', VIS);

    $manual =
        "SELECT t.date AS tdate,
                COALESCE(NULLIF(t.merchant_name,''), t.name, 'Activity') AS title,
                t.amount AS amount,
                " . ACCT_NAME . " AS account_name, a.mask, a.account_id,
                i.user_id AS owner_id, t.imported_at AS sortts
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE $visM AND t.ext_source IS NOT NULL
           AND t.account_id IN (" . implode(',', $mIn) . ")
           AND $mKind";

    $plaid =
        "SELECT it.trade_date AS tdate,
                COALESCE(NULLIF(it.name,''), s.name, s.ticker_symbol, it.type, 'Activity') AS title,
                it.amount AS amount,
                " . ACCT_NAME . " AS account_name, a.mask, a.account_id,
                i.user_id AS owner_id, it.updated_at AS sortts
         FROM investment_transactions it
         JOIN accounts a ON it.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         LEFT JOIN securities s ON it.security_id = s.security_id
         WHERE $visP AND it.ext_source IN ('plaid', 'manual_ret')
           AND it.account_id IN (" . implode(',', $pIn) . ")
           AND $pKind";

    return ["$manual UNION ALL $plaid", $params];
}

/**
 * Statement-coverage gaps for a manual account: which monthly statement buckets
 * we hold vs. the full sequence between the earliest and latest one. Lets the UI
 * warn that figures may be incomplete when a middle month is missing (e.g. Jan
 * and Mar uploaded, Feb absent). Returns
 *   ['have'=>['YYYY-MM',…], 'missing'=>['YYYY-MM',…], 'latest'=>?'YYYY-MM'].
 */
function q_statement_coverage(PDO $pdo, string $accountId): array
{
    $st = $pdo->prepare(
        "SELECT period_key FROM manual_documents
         WHERE account_id = ? AND doc_type = 'statement'
           AND period_key REGEXP '^[0-9]{4}-[0-9]{2}$'
         ORDER BY period_key"
    );
    $st->execute([$accountId]);
    $have = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$have) return ['have' => [], 'missing' => [], 'latest' => null];

    $first = $have[0];
    $last  = $have[count($have) - 1];
    $haveSet = array_fill_keys($have, true);

    // Walk every month from $first to $last; flag any not present.
    $missing = [];
    [$y, $m] = array_map('intval', explode('-', $first));
    while (($cur = sprintf('%04d-%02d', $y, $m)) <= $last) {
        if (!isset($haveSet[$cur])) $missing[] = $cur;
        if (++$m > 12) { $m = 1; $y++; }
    }
    return ['have' => $have, 'missing' => $missing, 'latest' => $last];
}

/**
 * Per-security price change anchors from `security_prices`, keyed by security_id.
 * For each id returns the latest close + the close as of ~1/7/30/365 days before
 * it (closest trading day at-or-before the target), so the page can render
 * day/week/month/year change icons as (current − anchor) × quantity.
 *   [security_id => ['date'=>'YYYY-MM-DD','current'=>f,'d1'=>?f,'d7'=>?f,'d30'=>?f,'d365'=>?f]]
 * The ids come from already-visibility-scoped holdings, so no VIS clause is needed
 * (these rows are market prices, not user data).
 */
function q_price_changes(PDO $pdo, array $securityIds): array
{
    $securityIds = array_values(array_unique(array_filter($securityIds)));
    if (!$securityIds) return [];
    $in = implode(',', array_fill(0, count($securityIds), '?'));
    $st = $pdo->prepare(
        "SELECT security_id, price_date, close FROM security_prices
         WHERE security_id IN ($in) ORDER BY security_id, price_date ASC"
    );
    $st->execute($securityIds);

    $series = [];
    foreach ($st->fetchAll() as $r) {
        $series[$r['security_id']][] = [$r['price_date'], (float)$r['close']];
    }

    $out = [];
    foreach ($series as $sid => $rows) {
        $n = count($rows);
        $latestDate = $rows[$n - 1][0];
        // Latest close on/at-or-before $latestDate minus $days (rows are ASC).
        $anchor = function (int $days) use ($rows, $latestDate): ?float {
            $target = date('Y-m-d', strtotime($latestDate . " -{$days} day"));
            $close = null;
            foreach ($rows as [$d, $c]) { if ($d <= $target) $close = $c; else break; }
            return $close;
        };
        $out[$sid] = [
            'date'    => $latestDate,
            'current' => $rows[$n - 1][1],
            'd1'      => $n > 1 ? $rows[$n - 2][1] : null,   // prior trading day = "today's" move
            'd7'      => $anchor(7),
            'd30'     => $anchor(30),
            'd365'    => $anchor(365),
        ];
    }
    return $out;
}

/**
 * Per-security dividend data from `security_dividends`, keyed by security_id, for the
 * Investments "Dividend income & calendar" section. For each id returns:
 *   'latest'   => the most recent dividend with a usable frequency (drives the annual
 *                 projection: qty × cash_amount × frequency), or the most recent of any
 *                 kind if none carries a frequency; null if the security has no rows.
 *   'upcoming' => declared dividends with ex_date >= today (ASC) for the calendar agenda.
 *   'annual_ps'=> projected annual dividend PER SHARE (cash_amount × frequency), or null.
 * NOT VIS-scoped (these are market facts, not user data) — the ids come from
 * already-visibility-scoped holdings, exactly like q_price_changes(). "today" is PHP
 * app-TZ (never MySQL CURDATE()).
 */
function q_security_dividends(PDO $pdo, array $securityIds): array
{
    $securityIds = array_values(array_unique(array_filter($securityIds)));
    if (!$securityIds) return [];
    $in = implode(',', array_fill(0, count($securityIds), '?'));
    $st = $pdo->prepare(
        "SELECT security_id, ex_date, cash_amount, frequency, pay_date
         FROM security_dividends
         WHERE security_id IN ($in)
         ORDER BY security_id, ex_date ASC"
    );
    $st->execute($securityIds);

    $today = date('Y-m-d');
    $byId = [];
    foreach ($st->fetchAll() as $r) {
        $byId[$r['security_id']][] = [
            'ex_date'     => $r['ex_date'],
            'cash_amount' => (float)$r['cash_amount'],
            'frequency'   => $r['frequency'] !== null ? (int)$r['frequency'] : null,
            'pay_date'    => $r['pay_date'],
        ];
    }

    $out = [];
    foreach ($byId as $sid => $rows) {
        // Latest row that carries a usable (>0) frequency drives the projection; fall
        // back to the most recent row of any kind so we can still show a per-share rate.
        $latest = null;
        foreach ($rows as $row) {
            if (($row['frequency'] ?? 0) > 0) $latest = $row;   // rows are ASC → ends on newest w/ freq
        }
        if ($latest === null) $latest = $rows[count($rows) - 1];

        $upcoming = array_values(array_filter($rows, fn($r) => $r['ex_date'] >= $today));

        $annualPs = (($latest['frequency'] ?? 0) > 0)
            ? $latest['cash_amount'] * $latest['frequency']
            : null;

        $out[$sid] = ['latest' => $latest, 'upcoming' => $upcoming, 'annual_ps' => $annualPs];
    }
    return $out;
}

/**
 * Portfolio value over time = Σ(current quantity × that-day's close) across the
 * given holdings, one point per trading day in `security_prices`. Closes are
 * carried forward per security so a day missing one security's bar still sums.
 * NB: uses *current* share counts (we don't keep historical lot history yet), so
 * it's "what today's holdings were worth then" — label it as such in the UI.
 * Returns [['date'=>'YYYY-MM-DD','value'=>float], …] oldest first.
 */
function q_portfolio_history(PDO $pdo, array $holds): array
{
    $qty = [];
    foreach ($holds as $h) {
        if (($h['security_id'] ?? null) === null || $h['quantity'] === null) continue;
        $qty[$h['security_id']] = ($qty[$h['security_id']] ?? 0.0) + (float)$h['quantity'];
    }
    if (!$qty) return [];
    $ids = array_keys($qty);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare(
        "SELECT security_id, price_date, close FROM security_prices
         WHERE security_id IN ($in) ORDER BY price_date ASC"
    );
    $st->execute($ids);

    $map = []; $dates = [];
    foreach ($st->fetchAll() as $r) {
        $map[$r['security_id']][$r['price_date']] = (float)$r['close'];
        $dates[$r['price_date']] = true;
    }
    if (!$dates) return [];
    ksort($dates);

    $need = count($qty);
    $last = []; $out = [];
    foreach (array_keys($dates) as $d) {
        foreach ($qty as $sid => $q) {
            if (isset($map[$sid][$d])) $last[$sid] = $map[$sid][$d];
        }
        // Don't plot until every security has a known price, else the line ramps
        // up over the first few days as each security's first bar arrives.
        if (count($last) < $need) continue;
        $val = 0.0;
        foreach ($qty as $sid => $q) $val += $last[$sid] * $q;
        $out[] = ['date' => $d, 'value' => round($val, 2)];
    }
    return $out;
}

/* ===========================================================================
 * Per-security detail page (security.php, #24)
 * ---------------------------------------------------------------------------
 * A focused drill-down for ONE security, reached from the holding rows on
 * investments.php / retirement.php. Market facts (securities/security_prices/
 * security_dividends) are non-sensitive, but the VIEWER's position and lot
 * ledger MUST be VIS-scoped — a security held only in the OTHER user's
 * private/hidden account must not leak. q_security_has_access() is the gate.
 * ======================================================================== */

/** Bare security metadata (market fact, not user data). NULL if unknown. */
function q_security(PDO $pdo, string $securityId): ?array
{
    $st = $pdo->prepare(
        "SELECT security_id, ticker_symbol, name, type, close_price, close_price_date
         FROM securities WHERE security_id = ?"
    );
    $st->execute([$securityId]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * Does the viewing user have ANY visible exposure to this security — a holding
 * OR a buy/sell lot in an account they can see? The access gate for security.php
 * (so a security held only in the other user's private/hidden account 404s
 * instead of leaking that it exists in the household). Two separate prepared
 * statements, so each may reuse :uid (HY093-safe).
 */
function q_security_has_access(PDO $pdo, int $uid, string $securityId): bool
{
    $h = $pdo->prepare(
        "SELECT 1 FROM holdings h
         JOIN accounts a ON h.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND h.security_id = :sid LIMIT 1"
    );
    $h->execute([':uid' => $uid, ':sid' => $securityId]);
    if ($h->fetchColumn()) return true;

    $l = $pdo->prepare(
        "SELECT 1 FROM investment_transactions it
         JOIN accounts a ON it.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND it.security_id = :sid AND it.side IN ('buy','sell') LIMIT 1"
    );
    $l->execute([':uid' => $uid, ':sid' => $securityId]);
    return (bool)$l->fetchColumn();
}

/**
 * The viewing user's holdings of one security, one row per visible account that
 * holds it (taxable AND retirement — this is a per-security view, not per-page).
 * VIS-scoped; selects type/subtype/retirement_flag so the page can tag a
 * retirement account, and owner_id for owner_suffix(). Distinct :uid/:sid → HY093-safe.
 */
function q_security_holdings(PDO $pdo, int $uid, string $securityId): array
{
    $st = $pdo->prepare(
        "SELECT h.quantity, h.cost_basis, h.institution_price, h.institution_value,
                " . ACCT_NAME . " AS account_name, a.mask, a.account_id,
                a.type, a.subtype, a.retirement_flag,
                i.institution_name, i.source, i.manual_type, i.user_id AS owner_id
         FROM holdings h
         JOIN accounts a ON h.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND h.security_id = :sid
         ORDER BY h.institution_value DESC"
    );
    $st->execute([':uid' => $uid, ':sid' => $securityId]);
    return $st->fetchAll();
}

/**
 * The viewing user's buy/sell lot ledger for one security (from
 * investment_transactions — Webull manual lots ∪ Plaid trades). VIS-scoped,
 * newest first, paginated (fetch PAGE_SIZE+1 and slice). Distinct :uid/:sid → HY093-safe.
 */
function q_security_lots(PDO $pdo, int $uid, string $securityId, int $limit = 50, int $offset = 0): array
{
    $st = $pdo->prepare(
        "SELECT it.trade_date, it.side, it.type, it.subtype, it.name,
                it.quantity, it.price, it.fees, it.amount, it.ext_source,
                " . ACCT_NAME . " AS account_name, a.mask, i.user_id AS owner_id
         FROM investment_transactions it
         JOIN accounts a ON it.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND it.security_id = :sid AND it.side IN ('buy','sell')
         ORDER BY it.trade_date DESC, it.updated_at DESC
         LIMIT " . max(1, (int)$limit) . " OFFSET " . max(0, (int)$offset)
    );
    $st->execute([':uid' => $uid, ':sid' => $securityId]);
    return $st->fetchAll();
}

/**
 * Daily close history for one security over the last $days, oldest first, for
 * the price chart. Market data (not user-scoped — the page already gated access
 * via q_security_has_access). Returns [['price_date'=>…,'close'=>f], …].
 */
function q_security_prices(PDO $pdo, string $securityId, int $days = 730): array
{
    $from = date('Y-m-d', strtotime("-{$days} days"));
    $st = $pdo->prepare(
        "SELECT price_date, close FROM security_prices
         WHERE security_id = :sid AND price_date >= :from
         ORDER BY price_date ASC"
    );
    $st->execute([':sid' => $securityId, ':from' => $from]);
    return $st->fetchAll();
}

/* ===========================================================================
 * Investment return rate + benchmark (returns.php, #29)
 * ---------------------------------------------------------------------------
 * Reads for the money-weighted return: the VIS-scoped buy/sell LOTS over a set
 * of accounts (the cash-flow stream), and the broad-market securities we already
 * price (the benchmark candidates). The math lives in lib/returns.php.
 * ======================================================================== */

/**
 * VIS-scoped buy/sell lots over an explicit account set (the accounts the calling
 * page renders), oldest first — the cash-flow stream for the return calc. Returns
 * security_id/trade_date/side/quantity/price/fees/amount + account_id/account_name.
 * Distinct :uid (VIS, used once) + :a* IN-list → HY093-safe. Empty set ⇒ [].
 */
function q_investment_lots(PDO $pdo, int $uid, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter($accountIds, fn($x) => $x !== null && $x !== '')));
    if (!$accountIds) return [];
    $in = []; $params = [':uid' => $uid];
    foreach ($accountIds as $k => $aid) { $params[":a$k"] = $aid; $in[] = ":a$k"; }
    $st = $pdo->prepare(
        "SELECT it.security_id, it.trade_date, it.side, it.quantity, it.price, it.fees, it.amount,
                it.account_id, " . ACCT_NAME . " AS account_name
         FROM investment_transactions it
         JOIN accounts a ON it.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND it.side IN ('buy','sell')
           AND it.account_id IN (" . implode(',', $in) . ")
         ORDER BY it.trade_date ASC, it.updated_at ASC"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Broad-market index securities we ALREADY have price history for — the return
 * benchmark candidates (no new feed: `security_prices` only tracks held tickers,
 * so in practice whichever of these the household actually holds, e.g. SPY).
 * Returns [['security_id','ticker_symbol','name','pts','first_date','last_date'], …]
 * most-history first. NOT VIS-scoped — market data, like q_price_changes.
 */
function q_benchmark_candidates(PDO $pdo): array
{
    $tickers = ['SPY','VOO','VTI','IVV','ITOT','SCHB','VT','QQQ','DIA','AGG','BND'];
    $in = implode(',', array_fill(0, count($tickers), '?'));
    $st = $pdo->prepare(
        "SELECT s.security_id, s.ticker_symbol, s.name,
                COUNT(*) AS pts, MIN(sp.price_date) AS first_date, MAX(sp.price_date) AS last_date
         FROM securities s
         JOIN security_prices sp ON sp.security_id = s.security_id
         WHERE UPPER(s.ticker_symbol) IN ($in)
         GROUP BY s.security_id, s.ticker_symbol, s.name
         HAVING pts >= 30
         ORDER BY pts DESC"
    );
    $st->execute($tickers);
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
                " . ACCT_NAME . " AS account_name, a.mask, a.account_id, i.user_id AS owner_id
         FROM recurring_streams r
         JOIN accounts a ON r.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY r.direction, r.average_amount DESC"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    // De-duplicate streams that represent the SAME real-world series. Two causes:
    //   (a) Plaid RE-ISSUES a stream's id — the superseded one is deactivated at sync
    //       time (sync_recurring's reconciliation pass), so it shouldn't reach here; and
    //   (b) Plaid can return TWO stream_ids for ONE series in a single response (e.g. two
    //       byte-identical "Interest Paid" streams, same last_date + amount) — those both
    //       stay active and (a) can't catch them. Collapse rows sharing display-name +
    //       account + direction + frequency + amount, keeping the most recent last_date,
    //       so a genuine Plaid double-emission can't surface as a duplicate row. Distinct
    //       series (e.g. Amazon $32.38/mo vs $14.77) differ on amount/freq and are kept.
    //       Preserves the SQL ordering (first occurrence holds its slot).
    $slot = [];
    $out  = [];
    foreach ($rows as $r) {
        $key = strtolower(($r['merchant_name'] ?: ($r['description'] ?: '')) . '|' . $r['account_id']
             . '|' . $r['direction'] . '|' . ($r['frequency'] ?? '') . '|' . $r['average_amount']);
        if (!isset($slot[$key])) {
            $slot[$key] = count($out);
            $out[] = $r;
        } elseif (($r['last_date'] ?? '') > ($out[$slot[$key]]['last_date'] ?? '')) {
            $out[$slot[$key]] = $r; // keep the live (later last_date) twin in the same slot
        }
    }
    return $out;
}

/**
 * Shared budgets with current-month spend (household-wide).
 *
 * Each budget row carries:
 *   monthly_limit  the base limit
 *   spent          current-month true-expense spend in the category
 *   rollover       bool — is "carry unspent forward" enabled (#11b)
 *   carryover      $ carried into this month (0 unless rollover); see below
 *   available      monthly_limit + carryover (= monthly_limit when not rollover)
 *
 * ROLLOVER (#11b) is a pure READ-TIME derive (no schema beyond the `rollover` flag):
 * for a rollover budget the carryover accumulates over COMPLETED months from the
 * budget's creation month (anchored on `budgets.created_at`) up to last month —
 * runningCarry = max(0, runningCarry + (limit − spent)) per month, so an overspent
 * month draws the bucket down but never below $0 ("overspend floors carryover at $0").
 * The current (partial) month is excluded from the accumulation; its spend shows
 * against `available`. A non-rollover budget is unchanged (carryover 0, available =
 * monthly_limit), so existing callers keep working.
 */
function q_budgets(PDO $pdo): array
{
    $month = date('Y-m');
    // Household-wide (no per-user VIS), but 'hidden' accounts must not feed budget
    // spend — join accounts to drop them. (Private accounts still count: the budget
    // is a shared household figure; only 'hidden' is registered nowhere.)
    $rows = $pdo->prepare(
        "SELECT " . EFF_CAT . " AS category,
                SUM(" . EFF_AMT . ") AS spent
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         " . SPLIT_JOIN . "
         WHERE t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND a.visibility <> 'hidden'
           -- Same true-expense exclusions as q_cashflow / q_spending_trend /
           -- q_digest_spending / q_spend_anomalies: internal transfers and
           -- credit-card payments must not inflate a category's budget spend
           -- (Session 28 — else a budget on a transfer/CC-payment category would
           -- over-count and fire a false budget-exceeded alert). 3-arg COALESCE so
           -- a NULL category isn't dropped by `NULL NOT IN (...)`. Splits (#8) drive
           -- the spend via EFF_CAT/EFF_AMT, so a budgeted split category counts here.
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND DATE_FORMAT(t.date, '%Y-%m') = :m
         GROUP BY " . EFF_CAT
    );
    $rows->execute([':m' => $month]);
    $spent = [];
    foreach ($rows->fetchAll() as $r) $spent[$r['category']] = (float)$r['spent'];

    $budgets = $pdo->query(
        'SELECT id, category, monthly_limit, effective_month, rollover, created_at
         FROM budgets ORDER BY category'
    )->fetchAll();

    // Per-category limit maps + the rollover anchor (creation month), needed by the
    // carryover accumulation below. The recurring (effective_month NULL) row is the
    // persistent budget the UI manages; any effective_month rows are per-month overrides.
    $recurLimit = [];   // CAT => recurring limit
    $monthLimit = [];   // CAT => [ 'YYYY-MM' => override limit ]
    $rollCats   = [];   // CAT => true  (category has rollover enabled on any of its rows)
    $createdM   = [];   // CAT => earliest 'YYYY-MM' the budget existed (carry start anchor)
    foreach ($budgets as $b) {
        $cat = $b['category'];
        if ($b['effective_month'] === null) $recurLimit[$cat] = (float)$b['monthly_limit'];
        else $monthLimit[$cat][$b['effective_month']] = (float)$b['monthly_limit'];
        if ((int)$b['rollover'] === 1) $rollCats[$cat] = true;
        $cm = substr((string)$b['created_at'], 0, 7);   // 'YYYY-MM'
        if ($cm !== '' && (!isset($createdM[$cat]) || $cm < $createdM[$cat])) $createdM[$cat] = $cm;
    }

    $carry = q_budget_carryover($pdo, array_keys($rollCats), $recurLimit, $monthLimit, $createdM);

    foreach ($budgets as &$b) {
        $cat = $b['category'];
        $b['monthly_limit'] = (float)$b['monthly_limit'];
        $b['rollover']      = (int)$b['rollover'] === 1;
        $b['spent']         = round($spent[$cat] ?? 0, 2);
        $b['carryover']     = $b['rollover'] ? round($carry[$cat] ?? 0.0, 2) : 0.0;
        $b['available']     = round($b['monthly_limit'] + $b['carryover'], 2);
        unset($b['effective_month'], $b['created_at']);   // internal; callers don't need them
    }
    unset($b);
    return ['month' => $month, 'budgets' => $budgets];
}

/**
 * Carryover (rollover #11b) per category — the running unspent bucket carried INTO the
 * current month. Accumulated over COMPLETED months (creation month .. last month;
 * the partial current month is excluded) as runningCarry = max(0, runningCarry +
 * (limit − spent)), so an overspent month never makes the bucket negative.
 *
 * HOUSEHOLD-WIDE, NOT VIS-scoped and reuses q_budgets' EXACT true-expense filters +
 * SPLIT_JOIN/EFF_CAT/EFF_AMT so the per-month spend ties to q_budgets. One aggregate over
 * [earliest-anchor, this-month) restricted to rollover categories; ALL placeholders
 * distinct (:start/:curstart/:rc0…:rcN → HY093-safe); window bounds are PHP-app-TZ
 * anchored (never CURDATE() — the S24 TZ trap). The lookback is clamped to 36 months as a
 * safety bound. Returns [CAT => carryover$]; [] when there are no rollover categories.
 */
function q_budget_carryover(PDO $pdo, array $rollCats, array $recurLimit, array $monthLimit, array $createdM): array
{
    if (!$rollCats) return [];

    $cur1     = new DateTimeImmutable('first day of this month');
    $curYm    = $cur1->format('Y-m');
    $floor36  = $cur1->sub(new DateInterval('P36M'))->format('Y-m');   // safety bound

    // Earliest creation month across the rollover categories (the window start).
    $earliest = $curYm;
    foreach ($rollCats as $c) {
        $cm = $createdM[$c] ?? $curYm;
        if ($cm < $earliest) $earliest = $cm;
    }
    if ($earliest < $floor36) $earliest = $floor36;

    // Monthly spend per rollover category over [start, current month) — completed months
    // only. Same filters/explosion as q_budgets so the figures reconcile to the penny.
    $inKeys = [];
    $params = [':start' => $earliest . '-01', ':curstart' => $cur1->format('Y-m-01')];
    foreach ($rollCats as $i => $c) { $k = ':rc' . $i; $inKeys[] = $k; $params[$k] = $c; }

    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym,
                " . EFF_CAT . " AS category,
                SUM(" . EFF_AMT . ") AS spent
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         " . SPLIT_JOIN . "
         WHERE a.visibility <> 'hidden'
           AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND " . EFF_CAT . " IN (" . implode(',', $inKeys) . ")
           AND t.date >= :start AND t.date < :curstart
         GROUP BY ym, " . EFF_CAT
    );
    $st->execute($params);
    $hist = [];   // hist[CAT][ym] = spent that month
    foreach ($st->fetchAll() as $r) $hist[$r['category']][$r['ym']] = (float)$r['spent'];

    $out = [];
    foreach ($rollCats as $cat) {
        $startCm = $createdM[$cat] ?? $earliest;
        if ($startCm < $earliest) $startCm = $earliest;
        $acc = 0.0;
        // Iterate completed months [startCm, curYm). Anchored on day 1 so P1M never
        // overflows a month-end. String 'YYYY-MM' compare bounds the loop.
        $m = new DateTimeImmutable($startCm . '-01');
        while ($m->format('Y-m') < $curYm) {
            $ym  = $m->format('Y-m');
            $lim = $monthLimit[$cat][$ym] ?? $recurLimit[$cat] ?? 0.0;
            $sp  = $hist[$cat][$ym] ?? 0.0;
            $acc = max(0.0, $acc + ($lim - $sp));
            $m   = $m->add(new DateInterval('P1M'));
        }
        $out[$cat] = $acc;
    }
    return $out;
}

/**
 * Per-budgeted-category monthly spend HISTORY for the spending.php trend (TODO #11).
 * For each category that has a budget, returns the last $months months of actual spend
 * plus that category's limit and a month-to-date delta (this month vs the mean of the
 * prior 3 months). Backs the per-row mini history + "vs 3-mo avg" chip — read-only
 * derive, no schema change (rollover is deferred).
 *
 * HOUSEHOLD-WIDE (NOT VIS-scoped, like q_budgets — a budget is a shared figure;
 * excludes only `hidden`) and reuses q_budgets/q_cashflow's EXACT true-expense filters
 * (pending=0, amount>0, ext_source NULL, transfers + CC-payments excluded) + the
 * SPLIT_JOIN/EFF_CAT/EFF_AMT split-explosion so the numbers tie to q_budgets to the penny.
 *
 * ⚠️ GROUP BY the explicit EFF_CAT expression, never the bare `category` alias — once
 * SPLIT_JOIN adds transaction_splits (which has its own `category` column) the alias
 * silently re-binds to s.category (the S30 trap). ⚠️ All placeholders are distinct
 * (:start, :dom, :c0…:cN) — this host runs emulation OFF and rejects a reused :name
 * (HY093 → 500). ⚠️ The window start is PHP app-TZ anchored (never CURDATE()) so the SQL
 * window stays in lock-step with the PHP gap-fill month list (the S24 TZ lesson).
 *
 * The per-month limit resolves COALESCE(effective_month override for that ym, recurring
 * NULL-month limit) — uses the `effective_month` column as #11 asks, though today the UI
 * only writes recurring (NULL) rows, so it's the recurring limit in practice.
 *
 * Returns keyed by category:
 *   ['<CAT>' => ['months'=>[{ym,label,spent}…] oldest→newest (gap-filled),
 *                'limit'=>float, 'this'=>MTD this month, 'avg3'=>mean prior-3 MTD]]
 * Empty array when no budgets exist.
 */
function q_budget_history(PDO $pdo, int $months = 6): array
{
    $months = max(1, min(24, $months));

    // Which categories are budgeted, and their limits (recurring NULL-month + any
    // month-specific override). No budgets → nothing to chart.
    $budRows = $pdo->query('SELECT category, monthly_limit, effective_month FROM budgets')->fetchAll();
    if (!$budRows) return [];
    $recurLimit = [];   // CAT => recurring (effective_month IS NULL) limit
    $monthLimit = [];   // CAT => [ 'YYYY-MM' => override limit ]
    foreach ($budRows as $b) {
        $cat = $b['category'];
        if ($b['effective_month'] === null) $recurLimit[$cat] = (float)$b['monthly_limit'];
        else $monthLimit[$cat][$b['effective_month']] = (float)$b['monthly_limit'];
    }
    $cats = array_values(array_unique(array_merge(array_keys($recurLimit), array_keys($monthLimit))));
    if (!$cats) return [];

    // Contiguous month list (oldest→newest), anchored to the 1st of this month in app TZ.
    $first = new DateTimeImmutable('first day of this month');
    $list  = [];
    for ($i = $months - 1; $i >= 0; $i--) $list[] = $first->sub(new DateInterval('P' . $i . 'M'));
    $start = $list[0]->format('Y-m-01');
    $dom   = (int)(new DateTimeImmutable('today'))->format('j');   // today's day-of-month

    // Distinct named placeholders for the IN list (HY093-safe; mixing positional + named
    // in one PDO statement isn't allowed, and :start/:dom are named, so name these too).
    $inKeys = [];
    $params = [':start' => $start, ':dom' => $dom];
    foreach ($cats as $idx => $c) { $k = ':c' . $idx; $inKeys[] = $k; $params[$k] = $c; }
    $inSql = implode(',', $inKeys);

    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym,
                " . EFF_CAT . " AS category,
                SUM(" . EFF_AMT . ") AS total,
                SUM(CASE WHEN DAY(t.date) <= :dom THEN " . EFF_AMT . " ELSE 0 END) AS mtd
         FROM transactions t
         JOIN accounts a ON t.account_id = a.account_id
         " . SPLIT_JOIN . "
         WHERE a.visibility <> 'hidden'
           AND t.pending = 0 AND t.amount > 0 AND t.ext_source IS NULL
           AND " . expense_exclude_clause($pdo) . "
           AND (t.pfc_detailed IS NULL OR t.pfc_detailed <> 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT')
           AND " . EFF_CAT . " IN ($inSql)
           AND t.date >= :start
         GROUP BY ym, " . EFF_CAT
    );
    $st->execute($params);

    // matrix[ym][CAT] = full total (the bars) ; mtd[ym][CAT] = same-day-of-month total (the delta).
    $matrix = []; $mtd = [];
    foreach ($st->fetchAll() as $r) {
        $matrix[$r['ym']][$r['category']] = (float)$r['total'];
        $mtd[$r['ym']][$r['category']]    = (float)$r['mtd'];
    }

    $cur  = $first->format('Y-m');
    $prev = [];
    for ($i = 1; $i <= 3; $i++) $prev[] = $first->sub(new DateInterval('P' . $i . 'M'))->format('Y-m');

    $out = [];
    foreach ($cats as $cat) {
        $monthsOut = [];
        foreach ($list as $dt) {
            $ym = $dt->format('Y-m');
            $monthsOut[] = [
                'ym'    => $ym,
                'label' => $dt->format('M y'),
                'spent' => round($matrix[$ym][$cat] ?? 0.0, 2),
            ];
        }
        // Limit reference = this month's resolved limit (override else recurring; 0 if neither).
        $limit = $monthLimit[$cat][$cur] ?? $recurLimit[$cat] ?? 0.0;
        $this_ = $mtd[$cur][$cat] ?? 0.0;
        $avg3  = 0.0;
        foreach ($prev as $pm) $avg3 += $mtd[$pm][$cat] ?? 0.0;
        $avg3 /= 3;
        $out[$cat] = [
            'months' => $monthsOut,
            'limit'  => round($limit, 2),
            'this'   => round($this_, 2),
            'avg3'   => round($avg3, 2),
        ];
    }
    return $out;
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
 * Build a category picker option list: the canonical Plaid primary categories
 * (minus $exclude) unioned with any category that actually appears in the
 * household's visible transactions (covers custom overrides set via
 * recategorize, plus UNCATEGORIZED) AND every first-class custom category from the
 * custom_categories table (migration 024 — so a freshly-created one shows in the picker
 * before any transaction uses it). When $outflowOnly the data scan is limited to spend
 * (amount > 0, non-manual) so it mirrors the spending list, AND every custom category
 * flagged exclude_from_spending is removed (a non-spending bucket like "Reimbursable"
 * has no place in the budget picker — like INCOME/TRANSFER_IN).
 * Returns [['value' => TAG, 'label' => 'Friendly Name'], ...] sorted by label.
 */
function category_options(PDO $pdo, int $uid, array $exclude = [], bool $outflowOnly = false): array
{
    $cats = array_fill_keys(array_diff(pfc_primary_categories(), $exclude), true);

    $sql = "SELECT DISTINCT COALESCE(t.category_override, t.pfc_primary, 'UNCATEGORIZED') AS category
            FROM transactions t
            JOIN accounts a ON t.account_id = a.account_id
            JOIN items i ON a.item_id = i.item_id
            WHERE " . VIS . ($outflowOnly ? " AND t.amount > 0 AND t.ext_source IS NULL" : "");
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if ((string)$c !== '') $cats[$c] = true;
    }

    // First-class custom categories (appear even before any transaction uses them).
    foreach (array_keys(custom_category_map($pdo)) as $tag) {
        if (!in_array($tag, $exclude, true)) $cats[$tag] = true;
    }
    // Budget/spending picker: a non-spending custom category must not be budgetable.
    if ($outflowOnly) {
        foreach (category_excluded_tags($pdo) as $t) unset($cats[$t]);
    }

    $opts = [];
    foreach (array_keys($cats) as $tag) {
        $opts[] = ['value' => $tag, 'label' => pretty_cat($tag)];
    }
    usort($opts, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $opts;
}

/** Categories for the budget picker (spending only — inflow categories dropped). */
function budget_category_options(PDO $pdo, int $uid): array
{
    return category_options($pdo, $uid, ['INCOME', 'TRANSFER_IN'], true);
}

/** Categories for the transaction recategorize picker (full set — a transaction
 *  can legitimately be income or a transfer). */
function transaction_category_options(PDO $pdo, int $uid): array
{
    return category_options($pdo, $uid);
}

/* ---- small presentation helpers (server-side) ---------------------------- */

/** "FOOD_AND_DRINK" -> "Food And Drink". A custom category (migration 024) renders with
 *  its stored label (preserves user fidelity that the underscore-titlecase would lose, e.g.
 *  "AT&T Fees"); the custom_category_map() lookup is static-cached (one query/request) and
 *  defensive (no table → fallback), so this stays cheap and safe everywhere it's called. */
function pretty_cat(?string $c): string
{
    $c = (string)$c;
    if ($c === '') return '';
    if (function_exists('db')) {
        $map = custom_category_map(db());
        if (isset($map[$c])) return $map[$c]['label'];
    }
    return ucwords(strtolower(str_replace('_', ' ', $c)));
}

/** Tidy a display merchant/payee string for the UI: fix dotted initialisms that a
 *  naive title-case lowercased — "U.s. Bank" → "U.S. Bank", "e.l.f." → "E.L.F." — by
 *  upper-casing a lone lowercase letter sitting between dots (a lookahead lets
 *  consecutive ones like "U.s.a." all match). Best-effort, ASCII, idempotent;
 *  operate on the RAW string BEFORE e() and only on DISPLAY text (never on a value
 *  used as a filter key / href param, which must stay byte-for-byte). UI-review F5. */
function display_merchant(?string $name): string
{
    $name = (string)$name;
    if ($name === '') return '';
    return preg_replace_callback('/\.([a-z])(?=\.)/', static fn($m) => '.' . strtoupper($m[1]), $name);
}

/** Account display label with mask, e.g. "Ally Savings ••7890". */
function account_label(array $a): string
{
    $name = $a['name'] ?: ($a['official_name'] ?: 'Account');
    return $name . ($a['mask'] ? " ••{$a['mask']}" : '');
}

/**
 * Household user display names, [user_id => name], fetched once per request.
 * The household is tiny (2 users), so this is a single cached query via the db() singleton.
 */
function household_users(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT id, name, email FROM users')->fetchAll() as $u) {
            $cache[(int)$u['id']] = (string)($u['name'] ?: $u['email']);
        }
    }
    return $cache;
}

/**
 * Full user/allowlist roster for the admin Users page (migration 032). NOT VIS-scoped
 * — this is account administration, not financial data; gated by require_admin() at the
 * page/endpoint. Returns each user with role/status, last-login, whether they've ever
 * signed in (google_sub set), and how many feed Items they own (so the UI can note
 * "data is kept" on disable). Defensive → [] before migration 032.
 */
function q_users(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT u.id, u.email, u.name, u.role, u.status, u.added_by,
                    u.created_at, u.last_login_at,
                    TIMESTAMPDIFF(SECOND, u.last_login_at, NOW()) AS last_login_age_s,
                    (u.google_sub IS NOT NULL) AS has_logged_in,
                    (SELECT COUNT(*) FROM items i WHERE i.user_id = u.id) AS item_count
             FROM users u
             ORDER BY (u.role = 'admin') DESC, u.email"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Owner's first name — a subtle "whose account is this" marker ('' if unknown). */
function owner_first_name($ownerId): string
{
    $name = household_users()[(int)$ownerId] ?? '';
    if ($name === '') return '';
    if (strpos($name, '@') !== false) $name = strstr($name, '@', true); // email → local part
    return preg_split('/\s+/', trim($name))[0] ?? '';
}

/** Subtle " · Name" owner suffix (HTML, pre-escaped) for an account sub-line; '' if unknown. */
function owner_suffix($ownerId): string
{
    $n = owner_first_name($ownerId);
    return $n === '' ? '' : ' · <span class="acct-owner">' . e($n) . '</span>';
}

/**
 * Household user IDs whose first name matches a free-text search term (#12a, S13 follow-up).
 * Folds the account owner's first name into the transactions free-text search so typing
 * "louis" surfaces Louis's transactions (not just merchants/categories named that) — the
 * S13 marker shows the owner first name, but it wasn't searchable until now. Resolved in PHP
 * off the tiny cached household_users() map (no extra JOIN); case-insensitive substring on
 * owner_first_name(). Empty/whitespace term → [] (no owner match). Shared by q_transactions
 * and api/export.php so the page and its CSV stay in lock-step.
 */
function owner_ids_matching(string $term): array
{
    $term = trim($term);
    if ($term === '') return [];
    $ids = [];
    foreach (array_keys(household_users()) as $uid) {
        $first = owner_first_name($uid);
        if ($first !== '' && stripos($first, $term) !== false) $ids[] = (int)$uid;
    }
    return $ids;
}

/** Is this account a liability (debt)? */
function is_liability(array $a): bool
{
    return in_array($a['type'] ?? '', ['credit', 'loan'], true);
}

/**
 * Dashboard account groups, in display order (assets first, then liabilities).
 * key => friendly heading. The 'other' catch-all only shows if something fails to
 * classify, so nothing ever silently disappears from the list.
 */
const ACCOUNT_GROUPS = [
    'checking'    => 'Checking',
    'savings'     => 'Savings',
    'investments' => 'Investments',
    'retirement'  => 'Retirement',
    'vehicle'     => 'Vehicles',
    'credit'      => 'Credit cards',
    'loans'       => 'Loans',
    'other'       => 'Other',
];

/**
 * Which ACCOUNT_GROUPS bucket an account belongs to. Retirement wins first (a 401(k)/IRA
 * is an `investment` type, so it must be pulled out before the investment branch). Then we
 * map by the reliable top-level `type`, splitting depository into checking vs everything-else-
 * cash (savings/CD/money-market/cash-management/unknown deposit) by `subtype`.
 */
function account_group(array $a): string
{
    if (is_retirement_account($a)) return 'retirement';
    $type = strtolower((string)($a['type'] ?? ''));
    $sub  = strtolower(trim((string)($a['subtype'] ?? '')));
    switch ($type) {
        case 'depository': return $sub === 'checking' ? 'checking' : 'savings';
        case 'credit':     return 'credit';
        case 'loan':       return 'loans';
        case 'investment': return 'investments';
        case 'vehicle':    return 'vehicle';   // manual vehicle asset (#40)
        default:           return 'other';
    }
}

/** Is this a manual (document-updated, non-Plaid) account? */
function is_manual(array $a): bool
{
    return ($a['source'] ?? 'plaid') === 'manual';
}

/**
 * Ingested documents for a manual account (newest first). The caller must have
 * already fetched the account via q_account() (which enforces visibility).
 * Pass $limit > 0 (and request PAGE_SIZE + 1) with $offset to paginate;
 * $limit = 0 (default) returns every document.
 */
function q_manual_documents(PDO $pdo, string $accountId, int $limit = 0, int $offset = 0): array
{
    $sql = 'SELECT id, doc_type, period_key, original_name, byte_size, summary, uploaded_at
            FROM manual_documents WHERE account_id = ?
            ORDER BY uploaded_at DESC';
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . max(0, (int)$offset);
    $st = $pdo->prepare($sql);
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

/**
 * The vehicle_assets basis row for a vehicle account (#40, migration 028), or null.
 * The caller must have already fetched the account via q_account() (which enforces
 * visibility) — this is keyed by account_id, mirroring q_manual_documents(). Defensive:
 * a missing table (pre-migration-028) or any error returns null, never fatals.
 */
function q_vehicle_asset(PDO $pdo, string $accountId): ?array
{
    try {
        $st = $pdo->prepare('SELECT * FROM vehicle_assets WHERE account_id = ? LIMIT 1');
        $st->execute([$accountId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ---- Manual-statement cadence / overdue warning (migration 010) ----------- */

/** Days of grace allowed after a period closes before its statement is "overdue". */
const STATEMENT_GRACE_DAYS = ['monthly' => 10, 'quarterly' => 21, 'annually' => 42];

/**
 * The effective expected-statement cadence for an account: the owner's explicit
 * `statement_cadence` when set (including 'off'), else an auto default by type —
 * a manual 401(k) → 'quarterly', any other manual (uploaded-statement) account →
 * 'monthly'. Non-manual (Plaid) accounts are never monitored → 'off'.
 * The row must carry `statement_cadence`, `source`/`manual_type` (q_account does).
 */
function statement_cadence_effective(array $a): string
{
    $c = $a['statement_cadence'] ?? null;
    if ($c !== null && $c !== '') return (string)$c;   // explicit override, incl. 'off'
    if (!is_manual($a)) return 'off';
    if (($a['manual_type'] ?? '') === 'vehicle') return 'off';   // a vehicle has no statements (#40)
    if (($a['manual_type'] ?? '') === 'retirement_401k') return 'quarterly';
    return 'monthly';
}

/** Last day (Y-m-d) of the cadence-period that contains $date. */
function statement_period_end(string $cadence, string $date): string
{
    $ts = strtotime($date);
    $y  = (int)date('Y', $ts);
    switch ($cadence) {
        case 'annually':  return sprintf('%04d-12-31', $y);
        case 'quarterly':
            $endMonth = (int)ceil(((int)date('n', $ts)) / 3) * 3;   // 3, 6, 9 or 12
            return date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $endMonth)));
        case 'monthly':
        default:          return date('Y-m-t', $ts);
    }
}

/** Last day (Y-m-d) of the cadence-period immediately AFTER the one ending $periodEnd. */
function statement_period_advance(string $cadence, string $periodEnd): string
{
    $nextStart = date('Y-m-d', strtotime($periodEnd . ' +1 day'));
    return statement_period_end($cadence, $nextStart);
}

/**
 * Overdue status for one monitored account given its cadence and the date its
 * latest statement covers ($latestDate, Y-m-d, or null if none ever).
 *
 * A statement for a period is expected by (period end + grace). Measuring from the
 * latest statement's covered period, the NEXT period's statement is due at
 * (nextPeriodEnd + grace); we're overdue once today passes that. With no statement
 * at all the account is overdue immediately. Returns:
 *   ['cadence','monitored'(bool),'overdue'(bool),'latest'(?Y-m-d),
 *    'due_date'(?Y-m-d),'days_overdue'(?int),'periods_behind'(?int)]
 */
function statement_status(string $cadence, ?string $latestDate, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $base  = ['cadence' => $cadence, 'monitored' => false, 'overdue' => false,
              'latest' => $latestDate, 'due_date' => null, 'days_overdue' => null,
              'periods_behind' => null];
    if (!isset(STATEMENT_GRACE_DAYS[$cadence])) return $base;   // 'off' / unknown
    $base['monitored'] = true;
    $grace = STATEMENT_GRACE_DAYS[$cadence];

    if ($latestDate === null) {                                 // never updated
        $base['overdue'] = true;
        return $base;
    }

    $nextEnd = statement_period_advance($cadence, statement_period_end($cadence, $latestDate));
    $dueDate = date('Y-m-d', strtotime($nextEnd . ' +' . $grace . ' day'));
    if ($today <= $dueDate) {                                   // up to date
        $base['due_date'] = $dueDate;
        return $base;
    }

    // Overdue. Count how many period statements are now past due (periods_behind).
    $periods = 0; $cur = $nextEnd;
    while (date('Y-m-d', strtotime($cur . ' +' . $grace . ' day')) <= $today) {
        $periods++;
        $cur = statement_period_advance($cadence, $cur);
    }
    $base['overdue']        = true;
    $base['due_date']       = $dueDate;
    $base['days_overdue']   = (int)floor((strtotime($today) - strtotime($dueDate)) / 86400);
    $base['periods_behind'] = $periods;
    return $base;
}

/** Friendly cadence name, e.g. 'quarterly' → 'Quarterly' ('annually' → 'Annual'). */
function statement_cadence_label(string $cadence): string
{
    return ['monthly' => 'Monthly', 'quarterly' => 'Quarterly',
            'annually' => 'Annual', 'off' => 'Off'][$cadence] ?? ucfirst($cadence);
}

/** Short human phrase for how overdue a status is (e.g. "3 statements behind"). */
function statement_overdue_label(array $s): string
{
    if (($s['latest'] ?? null) === null) return 'no statement uploaded yet';
    $pb = $s['periods_behind'] ?? 0;
    if ($pb !== null && $pb > 1) return $pb . ' statements behind';
    $d = (int)($s['days_overdue'] ?? 0);
    return $d > 0 ? ($d . ' day' . ($d === 1 ? '' : 's') . ' overdue') : 'due now';
}

/**
 * The latest statement-coverage date (Y-m-d) the app holds for a manual account:
 * MAX(statement_date) for a hand-entered 401(k), else the latest monthly
 * `manual_documents` statement bucket (period_key 'YYYY-MM' → first of that month).
 * null when nothing has been uploaded/entered yet.
 */
function manual_latest_statement_date(PDO $pdo, array $acct): ?string
{
    if (is_retirement($acct)) {
        $st = $pdo->prepare('SELECT MAX(statement_date) FROM retirement_statements WHERE account_id = ?');
        $st->execute([$acct['account_id']]);
        return $st->fetchColumn() ?: null;
    }
    $st = $pdo->prepare(
        "SELECT MAX(period_key) FROM manual_documents
         WHERE account_id = ? AND doc_type = 'statement'
           AND period_key REGEXP '^[0-9]{4}-[0-9]{2}$'"
    );
    $st->execute([$acct['account_id']]);
    $pk = $st->fetchColumn() ?: null;
    return $pk ? $pk . '-01' : null;
}

/**
 * Overdue status for a single manual account already fetched via q_account()
 * (VIS-scoped). Returns null when the account isn't manual or its effective
 * cadence is 'off' (not monitored); otherwise the statement_status() array with
 * 'account_id'/'name' attached. Used on the account page.
 */
function manual_account_status(PDO $pdo, array $acct): ?array
{
    if (!is_manual($acct)) return null;
    $cad = statement_cadence_effective($acct);
    if ($cad === 'off') return null;
    $s = statement_status($cad, manual_latest_statement_date($pdo, $acct));
    $s['account_id'] = $acct['account_id'];
    $s['name']       = $acct['name'];
    return $s;
}

/**
 * Statement-overdue status for every manual account the user OWNS (a non-hidden,
 * source='manual' Item), with an effective cadence other than 'off'. Owner-scoped
 * like q_owned_accounts() — only the owner can upload/enter statements, so the
 * warning is theirs to act on. Pass $overdueOnly to keep only overdue accounts
 * (the dashboard warning). Each row is a statement_status() array + account_id/name.
 */
function q_manual_statement_status(PDO $pdo, int $uid, bool $overdueOnly = false): array
{
    $accts = $pdo->prepare(
        "SELECT a.account_id, " . ACCT_NAME . " AS name, a.statement_cadence,
                i.source, i.manual_type
         FROM accounts a JOIN items i ON a.item_id = i.item_id
         WHERE i.user_id = :uid AND i.source = 'manual' AND a.visibility <> 'hidden'
         ORDER BY a.name"
    );
    $accts->execute([':uid' => $uid]);
    $rows = $accts->fetchAll();
    if (!$rows) return [];

    $out = [];
    foreach ($rows as $a) {
        $cad = statement_cadence_effective($a);
        if ($cad === 'off') continue;
        $s = statement_status($cad, manual_latest_statement_date($pdo, $a));
        if ($overdueOnly && !$s['overdue']) continue;
        $s['account_id']  = $a['account_id'];
        $s['name']        = $a['name'];
        $s['manual_type'] = $a['manual_type'];
        $out[] = $s;
    }
    return $out;
}

/**
 * Loan subtypes that plausibly ARE the home mortgage. Used to narrow the "which
 * account is the mortgage" fallback so a car/student/personal loan is never promoted
 * to "the mortgage" on a self-hosted install that has other loans but no linked home
 * loan (which would poison equity, the 360-mo amortization, and the FRED refi card).
 */
const MORTGAGE_SUBTYPES = ['mortgage', 'home equity', 'heloc', 'home'];

/**
 * Pick the account that is "the mortgage" from an already-fetched account set:
 * an explicit subtype='mortgage' first, else — only if none — the first type='loan'
 * whose subtype is mortgage-plausible (MORTGAGE_SUBTYPES). Returns null when nothing
 * qualifies (→ the honest no-mortgage empty state). Shared by q_home_equity() and
 * q_mortgage() so both agree on the same account.
 */
function pick_mortgage_account(array $accounts): ?array
{
    foreach ($accounts as $a) { if (($a['subtype'] ?? '') === 'mortgage') return $a; }
    foreach ($accounts as $a) {
        if (($a['type'] ?? '') === 'loan'
            && in_array(strtolower((string)($a['subtype'] ?? '')), MORTGAGE_SUBTYPES, true)) {
            return $a;
        }
    }
    return null;
}

/**
 * Home value vs. mortgage → equity, for the dashboard card. Returns null when no
 * home address is configured or no valuation has been stored yet.
 *
 * The home value is a shared fact about the property (home_values isn't tied to an
 * item/account, so the VIS clause doesn't apply to it). The MORTGAGE side, however,
 * is taken from $accounts — which the caller already fetched via q_accounts(), so it
 * is visibility-scoped: a user who can't see the mortgage just gets equity=null.
 * Mortgage = an account with subtype 'mortgage', else a mortgage-plausible loan
 * (pick_mortgage_account()).
 */
function q_home_equity(PDO $pdo, array $accounts): ?array
{
    $hc = home_config($pdo);
    $addr = $hc['address'];
    // No address, or removed (sold) → no current home-equity card.
    if ($addr === '' || $hc['removed_now']) return null;

    $st = $pdo->prepare(
        'SELECT value, value_low, value_high, as_of
         FROM home_values WHERE address = :a ORDER BY as_of DESC, id DESC LIMIT 1'
    );
    $st->execute([':a' => $addr]);
    $hv = $st->fetch();
    if (!$hv) return null;

    $mort = pick_mortgage_account($accounts);
    $bal = $mort ? (float)($mort['balance_current'] ?? 0) : null;

    // Net-worth scope → scale the VALUE by the ownership factor (default 1.0). The
    // mortgage stays full (the value-only decision), so equity = your share of the
    // house minus the full loan.
    $f = home_value_factor();
    $value = (float)$hv['value'] * $f;
    return [
        'address'          => $addr,
        'value'            => $value,
        'value_low'        => $hv['value_low']  !== null ? (float)$hv['value_low']  * $f : null,
        'value_high'       => $hv['value_high'] !== null ? (float)$hv['value_high'] * $f : null,
        'as_of'            => (string)$hv['as_of'],
        'mortgage_name'    => $mort ? ($mort['name'] ?: 'Mortgage') : null,
        'mortgage_balance' => $bal,
        'equity'           => $bal !== null ? round($value - $bal, 2) : null,
    ];
}

/**
 * The visible mortgage account + its Plaid liability detail, or null.
 * Mortgage = an account with subtype 'mortgage', else a mortgage-plausible loan
 * (pick_mortgage_account()).
 * Returns ['account'=>row, 'liab'=>row, 'raw'=>decoded Plaid mortgage, 'balance'=>current].
 */
function q_mortgage(PDO $pdo, int $uid): ?array
{
    $m = pick_mortgage_account(q_accounts($pdo, $uid));
    if (!$m) return null;

    $st = $pdo->prepare(
        "SELECT apr_percentage, outstanding_balance, origination_principal,
                last_payment_amount, last_payment_date, next_payment_due_date,
                minimum_payment_amount, raw
         FROM liabilities WHERE account_id = :a AND liability_type = 'mortgage' LIMIT 1"
    );
    $st->execute([':a' => $m['account_id']]);
    $liab = $st->fetch() ?: [];
    $raw  = isset($liab['raw']) ? (json_decode((string)$liab['raw'], true) ?: []) : [];

    return [
        'account' => $m,
        'liab'    => $liab,
        'raw'     => $raw,
        'balance' => abs((float)($m['balance_current'] ?? 0)),
    ];
}

/** Latest RentCast property record for an address (raw decoded), or null. */
function q_property_facts(PDO $pdo, string $address): ?array
{
    $st = $pdo->prepare("SELECT * FROM property_facts WHERE address = :a");
    $st->execute([':a' => $address]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['raw'] = isset($row['raw_json']) ? (json_decode((string)$row['raw_json'], true) ?: []) : [];
    return $row;
}

/** Latest market stats for a zip (raw decoded), or null. */
function q_market_stats(PDO $pdo, string $zip): ?array
{
    if ($zip === '') return null;
    $st = $pdo->prepare("SELECT * FROM market_stats WHERE zip = :z");
    $st->execute([':z' => $zip]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['raw'] = isset($row['raw_json']) ? (json_decode((string)$row['raw_json'], true) ?: []) : [];
    return $row;
}

/** Full value history for an address, oldest first. */
function q_value_history(PDO $pdo, string $address): array
{
    $st = $pdo->prepare(
        "SELECT as_of, value, value_low, value_high FROM home_values
         WHERE address = :a ORDER BY as_of ASC, id ASC"
    );
    $st->execute([':a' => $address]);
    return $st->fetchAll();
}

/** Balance history for one account, oldest first. */
function q_account_balance_history(PDO $pdo, string $accountId): array
{
    $st = $pdo->prepare(
        "SELECT snapshot_date, balance FROM account_balance_history
         WHERE account_id = :a ORDER BY snapshot_date ASC"
    );
    $st->execute([':a' => $accountId]);
    return $st->fetchAll();
}

/* ---- Retirement (manual 401(k)) ------------------------------------------- */

/** Is this account a manually-tracked 401(k)? */
function is_retirement(array $a): bool
{
    return ($a['manual_type'] ?? '') === 'retirement_401k';
}

/**
 * Visible retirement accounts, VIS-scoped: manual 401(k)s (manual_type=
 * 'retirement_401k') AND Plaid investment accounts classified as retirement
 * (subtype in RETIREMENT_SUBTYPES), honouring the per-account `retirement_flag`
 * override (1 = force in, 0 = force out). Mirrors is_retirement_account() in SQL.
 */
function q_retirement_accounts(PDO $pdo, int $uid): array
{
    $subs = []; $params = [':uid' => $uid];
    foreach (array_values(RETIREMENT_SUBTYPES) as $k => $s) { $subs[] = ":s$k"; $params[":s$k"] = $s; }
    $in = implode(',', $subs);
    $st = $pdo->prepare(
        "SELECT a.account_id, " . ACCT_NAME . " AS name, a.mask, a.type, a.subtype, a.retirement_flag,
                a.balance_current, a.visibility, a.last_updated_datetime, i.institution_name,
                i.user_id AS owner_id, i.item_id, i.source, i.manual_type
         FROM accounts a JOIN items i ON a.item_id = i.item_id
         WHERE " . VIS . " AND (
                a.retirement_flag = 1
                OR (a.retirement_flag IS NULL AND (
                     i.manual_type = 'retirement_401k'
                     OR (a.type = 'investment' AND LOWER(COALESCE(a.subtype, '')) IN ($in))
                ))
             )
         ORDER BY i.institution_name, a.name"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Statements for the visible 401(k) accounts (oldest first), VIS-scoped via the
 * account/item join. Optionally scoped to one account. Each row carries the
 * account name so callers can group without a second lookup.
 */
function q_retirement_statements(PDO $pdo, int $uid, ?string $accountId = null): array
{
    $where  = [VIS, "i.manual_type = 'retirement_401k'"];
    $params = [':uid' => $uid];
    if ($accountId !== null) { $where[] = 'rs.account_id = :acct'; $params[':acct'] = $accountId; }
    $st = $pdo->prepare(
        "SELECT rs.id, rs.account_id, rs.period_key, rs.statement_date, rs.balance,
                rs.employee_contrib, rs.employer_contrib, rs.employee_ytd, rs.employer_ytd,
                rs.note, " . ACCT_NAME . " AS account_name
         FROM retirement_statements rs
         JOIN accounts a ON rs.account_id = a.account_id
         JOIN items i ON a.item_id = i.item_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY rs.statement_date ASC, rs.id ASC"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Headline figures for the dashboard card: combined current balance across visible
 * 401(k) accounts, the account count, and the most recent statement date (or null).
 * Returns ['total'=>float, 'count'=>int, 'latest'=>?string].
 */
function q_retirement_summary(PDO $pdo, int $uid): array
{
    $accts = q_retirement_accounts($pdo, $uid);
    $total = 0.0;
    foreach ($accts as $a) $total += (float)($a['balance_current'] ?? 0);

    $latest = null;
    if ($accts) {
        $st = $pdo->prepare(
            "SELECT MAX(rs.statement_date)
             FROM retirement_statements rs
             JOIN accounts a ON rs.account_id = a.account_id
             JOIN items i ON a.item_id = i.item_id
             WHERE " . VIS . " AND i.manual_type = 'retirement_401k'"
        );
        $st->execute([':uid' => $uid]);
        $latest = $st->fetchColumn() ?: null;
    }
    return ['total' => round($total, 2), 'count' => count($accts), 'latest' => $latest];
}

/**
 * The single global projection-assumptions row (id=1), with safe defaults if the
 * row is missing. Not user-scoped — these are one shared household setting.
 * Returns ['retirement_year'=>?int, 'annual_contribution'=>?float,
 *          'growth_rate_override'=>?float, 'growth_default'=>float, 'target_amount'=>?float].
 */
function q_retirement_settings(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT retirement_year, annual_contribution, growth_rate_override,
                growth_default, return_volatility, target_amount
         FROM retirement_settings WHERE id = 1"
    )->fetch();
    return [
        'retirement_year'      => isset($row['retirement_year']) && $row['retirement_year'] !== null
                                    ? (int)$row['retirement_year'] : null,
        'annual_contribution'  => isset($row['annual_contribution']) && $row['annual_contribution'] !== null
                                    ? (float)$row['annual_contribution'] : null,
        'growth_rate_override' => isset($row['growth_rate_override']) && $row['growth_rate_override'] !== null
                                    ? (float)$row['growth_rate_override'] : null,
        'growth_default'       => isset($row['growth_default']) ? (float)$row['growth_default'] : 0.06,
        'return_volatility'    => isset($row['return_volatility']) && $row['return_volatility'] !== null
                                    ? (float)$row['return_volatility'] : null,
        'target_amount'        => isset($row['target_amount']) && $row['target_amount'] !== null
                                    ? (float)$row['target_amount'] : null,
    ];
}

/**
 * Household alert preferences (TODO #14). Thin wrapper so pages read via the q_*()
 * convention; the real implementation is alert_settings() in mailer.php (the alerts
 * module, also used by sync.php which doesn't load queries.php). NOT VIS-scoped —
 * there's no per-account data, just one global row shared by both household users.
 */
function q_alert_settings(PDO $pdo): array
{
    require_once __DIR__ . '/mailer.php';
    return alert_settings($pdo);
}

/**
 * Latest cached FRED observation for a series (#17), or null if none. Returns
 * ['date'=>'YYYY-MM-DD','value'=>float]. NOT VIS-scoped — `fred_series` is global
 * macro data shared by both household users (like q_alert_settings).
 */
function q_fred_latest(PDO $pdo, string $seriesId): ?array
{
    $st = $pdo->prepare(
        "SELECT obs_date, value FROM fred_series
         WHERE series_id = :s ORDER BY obs_date DESC LIMIT 1"
    );
    $st->execute([':s' => $seriesId]);
    $row = $st->fetch();
    return $row ? ['date' => (string)$row['obs_date'], 'value' => (float)$row['value']] : null;
}

/**
 * Cached FRED observations for a series, OLDEST first (for charts). $limit=0 = all.
 * Returns [['date'=>..,'value'=>..], ...]. NOT VIS-scoped (global macro data).
 */
function q_fred_history(PDO $pdo, string $seriesId, int $limit = 0): array
{
    // Take the newest $limit rows (DESC + LIMIT), then re-order ascending for plotting.
    $sql = "SELECT obs_date, value FROM fred_series WHERE series_id = :s ORDER BY obs_date DESC";
    if ($limit > 0) $sql .= " LIMIT " . (int)$limit;
    $st = $pdo->prepare($sql);
    $st->execute([':s' => $seriesId]);
    $rows = $st->fetchAll();
    $out = [];
    foreach (array_reverse($rows) as $r) {
        $out[] = ['date' => (string)$r['obs_date'], 'value' => (float)$r['value']];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Activity / diagnostics reads (Access Logs + Sync Status — migration 029).
 * HOUSEHOLD-WIDE, deliberately NOT VIS-scoped — these are diagnostic logs surfaced
 * on activity.php (reached from Settings), like alert_settings / credit reports. The
 * page resolves user_id → name via household_users() in PHP, so no JOIN is needed.
 * Defensive: a missing table (pre-migration-029) / transient error → [] so the page
 * degrades to an empty state rather than 500ing. The WRITERS + the banner-state read
 * live in lib/activity.php.
 * ------------------------------------------------------------------------- */

/** Valid access_log event types (the filter whitelist). */
function access_event_types(): array
{
    return ['login', 'logout', 'page', 'action'];
}

/**
 * Paginated access-log rows, newest first. $filters: ['event'=>?type, 'user_id'=>?int].
 * Distinct placeholders → HY093-safe; LIMIT/OFFSET inlined int-cast (codebase idiom).
 */
function q_access_log(PDO $pdo, int $limit = 50, int $offset = 0, array $filters = []): array
{
    try {
        $where = [];
        $args  = [];
        if (!empty($filters['event']) && in_array($filters['event'], access_event_types(), true)) {
            $where[] = 'event_type = :ev';
            $args[':ev'] = $filters['event'];
        }
        if (isset($filters['user_id']) && $filters['user_id'] !== '' && $filters['user_id'] !== null) {
            $where[] = 'user_id = :uid';
            $args[':uid'] = (int)$filters['user_id'];
        }
        $sql = 'SELECT id, user_id, event_type, label, detail, ip, user_agent, created_at,
                       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_s
                  FROM access_log'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY created_at DESC, id DESC'
             . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Recent sync runs, newest first (paginated). */
function q_sync_runs(PDO $pdo, int $limit = 30, int $offset = 0): array
{
    try {
        $st = $pdo->query(
            'SELECT id, trigger_type, started_at, finished_at, ok, step_count, error_count,
                    TIMESTAMPDIFF(SECOND, started_at, finished_at) AS duration_s,
                    TIMESTAMPDIFF(SECOND, started_at, NOW()) AS age_s
               FROM sync_run
              ORDER BY started_at DESC, id DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Steps for many runs at once → [run_id => [step rows]] (avoids N+1 on the history list). */
function q_sync_run_steps_map(PDO $pdo, array $runIds): array
{
    $ids = array_values(array_filter(array_map('intval', $runIds)));
    if (!$ids) return [];
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT run_id, step, label, ok, message, created_at
               FROM sync_run_step WHERE run_id IN ($ph) ORDER BY id ASC"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['run_id']][] = $r;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Per-Plaid-Item connection status for the Sync-status page (institution, status, last sync). */
function q_connection_status(PDO $pdo): array
{
    try {
        $st = $pdo->query(
            "SELECT item_id, institution_name, status, error_code, last_synced_at,
                    TIMESTAMPDIFF(SECOND, last_synced_at, NOW()) AS age_s
               FROM items
              WHERE source = 'plaid' AND status <> 'removed'
              ORDER BY (status = 'error') DESC, institution_name ASC"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Untracked Plaid "orphan" items (Session 96): item_ids Plaid has sent us a webhook
 * for that are NOT in our `items` table. These are the only Plaid-side Items we can
 * DISCOVER (Plaid has no list-items API) — typically abandoned Link sessions (created
 * at Plaid, never exchanged into our DB, so we never held an access_token → we cannot
 * /item/remove them). Household-wide diagnostic, surfaced admin-only on activity.php;
 * NOT VIS-scoped. Best-effort (returns [] if webhook_log/items is somehow unavailable).
 */
function q_orphan_webhook_items(PDO $pdo): array
{
    try {
        $st = $pdo->query(
            "SELECT w.item_id,
                    COUNT(*)                                              AS hits,
                    SUM(w.verified)                                       AS verified_hits,
                    MIN(w.created_at)                                     AS first_seen,
                    MAX(w.created_at)                                     AS last_seen,
                    TIMESTAMPDIFF(SECOND, MAX(w.created_at), NOW())       AS last_age_s,
                    GROUP_CONCAT(DISTINCT CONCAT(w.webhook_type, ':', w.webhook_code)
                        ORDER BY w.webhook_type SEPARATOR ', ')           AS kinds
               FROM webhook_log w
               LEFT JOIN items i ON i.item_id = w.item_id
              WHERE w.item_id IS NOT NULL AND i.item_id IS NULL
              GROUP BY w.item_id
              ORDER BY MAX(w.created_at) DESC"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
