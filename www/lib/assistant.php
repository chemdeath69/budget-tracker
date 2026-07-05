<?php
/**
 * assistant.php — natural-language AI assistant via Anthropic tool use (Session 57, TODO #27).
 *
 * The "Claude asks us for specific things, we look them up and provide them" model the owner
 * described — implemented as the standard Anthropic tool-use (function-calling) loop. Claude
 * NEVER touches the database: we expose a FIXED MENU of safe, read-only "tools" (each a thin
 * wrapper over an existing VIS-scoped q_*() helper). Claude decides which tool to call with
 * what arguments, WE execute it against our DB (scoped to the calling user's $uid), hand back
 * a compact JSON result, and Claude continues until it can answer in prose.
 *
 * Safety:
 *   - READ-ONLY. No tool mutates anything. The dispatcher is a HARD whitelist (match($name))
 *     — an unknown tool name returns an error result, never executes anything dynamic. No raw
 *     SQL is ever sent to, or accepted from, Claude.
 *   - VIS-scoped: every wrapped helper inherits the per-user visibility rule via $uid, so the
 *     assistant sees exactly what that user can see (shared + own private; never the other
 *     user's private/hidden).
 *   - Bounded: ASSISTANT_MAX_ROUNDS tool round-trips + ASSISTANT_TOKEN_CAP cumulative tokens
 *     per message, a per-call max_tokens, and a request timeout.
 *
 * Cost: pay-as-you-go prepaid Anthropic credits (NOT covered by a Claude Code / Claude.ai
 * plan); a few cents per question on claude-sonnet-4-6. Empty api_key disables the feature.
 *
 * Public API:
 *   assistant_enabled(array $cfg): bool
 *   assistant_respond(PDO $pdo, int $uid, array $messages, array $cfg): array
 *        → ['ok'=>bool, 'reply'=>?string, 'tools'=>string[], 'rounds'=>int,
 *           'error'=>?string, 'usage'=>['input'=>int,'output'=>int]]
 *
 * Requires (the caller includes them): queries.php (the q_*() helpers), retirement.php
 * (build_retirement_view), bills.php (bill_occurrences).
 */

const ASSISTANT_ENDPOINT     = 'https://api.anthropic.com/v1/messages';
const ASSISTANT_VERSION      = '2023-06-01';
const ASSISTANT_MAXTOKENS    = 1536;    // per API turn — answers are short; tool args are tiny
const ASSISTANT_MAX_ROUNDS   = 8;       // tool round-trips before we force a stop
const ASSISTANT_TIMEOUT      = 60;      // seconds per HTTP call
const ASSISTANT_TOKEN_CAP    = 200000;  // cumulative (input+output) budget per user message
const ASSISTANT_MAX_MESSAGES = 40;      // conversation turns accepted from the client
const ASSISTANT_MAX_MSG_LEN  = 6000;    // chars per message (a question, not a document)

/** Feature on only when a real Anthropic key is present (empty string = disabled). */
function assistant_enabled(array $cfg): bool
{
    return trim((string)($cfg['anthropic']['api_key'] ?? '')) !== '';
}

/**
 * The chat model. Reuses the existing `anthropic` config key; an optional
 * `anthropic.assistant_model` overrides the OCR model (which must stay a vision Sonnet).
 * Defaults to claude-sonnet-4-6 — strong tool use + good with finance reasoning.
 */
function assistant_model(array $cfg): string
{
    $am = trim((string)($cfg['anthropic']['assistant_model'] ?? ''));
    if ($am !== '') return $am;
    return trim((string)($cfg['anthropic']['model'] ?? '')) ?: 'claude-sonnet-4-6';
}

/**
 * Extended-thinking request block for the given model, or null to omit the field (#27 v2).
 * Sonnet-5 / Opus-4.7+ run *adaptive thinking by default* when `thinking` is unset — which for
 * snappy Q&A with a small ASSISTANT_MAXTOKENS could be eaten by thinking tokens before the answer.
 * So for those models we send `thinking: {type: disabled}` (valid on Sonnet 5 / Opus 4.7+). Older
 * models (claude-sonnet-4-6 and earlier) don't adaptive-think by default → omit the field entirely.
 * ⚠️ Claude Fable 5 REJECTS an explicit `{type:disabled}` (400) — never emit it there; it also isn't
 * a sensible assistant model, so it's simply excluded from the match.
 */
function assistant_thinking_param(string $model): ?array
{
    $m = strtolower($model);
    $adaptiveDefault = (bool)preg_match('/(sonnet-5|opus-4-(7|8|9)|opus-5)/', $m);
    return $adaptiveDefault ? ['type' => 'disabled'] : null;
}

/** Round a money-ish value to cents (null-safe) so tool results stay compact. */
function assistant_money($v): ?float
{
    if ($v === null || $v === '') return null;
    return round((float)$v, 2);
}

/**
 * The tool registry — one entry per wrapped read helper. Each is a thin JSON schema; the
 * actual work happens in assistant_dispatch(). Keep results COMPACT (top-N rows, rounded) so
 * we don't blow the token budget — Claude can always ask for more via another call.
 */
function assistant_tools(): array
{
    $int = fn($desc, $def, $min, $max) => [
        'type' => 'integer', 'description' => $desc . " (default {$def}, {$min}-{$max}).",
    ];
    // Every input_schema declares additionalProperties=false + a required array (empty where all
    // params are optional) — documents intent and keeps the model from inventing keys. NOTE: we do
    // NOT set strict=true. Anthropic strict tool use compiles a grammar and caps TOTAL optional
    // params across all tools at 24; this surface already has 33 (and Phase 3 adds more), so strict
    // is infeasible here — it returns "Schemas contains too many optional parameters" (#27 v2). No
    // numeric min/max in the schemas (the int helper puts the range in the description text only).
    // No-param tools share $noargs.
    $noargs = ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false, 'required' => []];
    // The filter properties shared by search_transactions + aggregate_transactions.
    $txnFilterProps = [
        'q'              => ['type' => 'string', 'description' => 'Free-text LITERAL substring search over merchant, raw name, category, and account-owner first name (no fuzzy matching — use merchant_fuzzy / find_merchants for approximate merchant names).'],
        'account'        => ['type' => 'string', 'description' => 'Scope to an account by an APPROXIMATE name, institution, or last-4 — e.g. "American Express", "amex", "chase checking", "1005". Matches ignoring punctuation/spacing/case; every word must appear. Use this for "on my <account>" questions instead of get_accounts + account_id.'],
        'account_id'     => ['type' => 'string', 'description' => 'EXACT account id (the `id` field from get_accounts) when you already have it and want a precise scope. Prefer `account` for a name.'],
        'category'       => ['type' => 'string', 'description' => 'Exact Plaid category code, e.g. FOOD_AND_DRINK, TRANSPORTATION, GENERAL_MERCHANDISE.'],
        'merchant'       => ['type' => 'string', 'description' => 'EXACT merchant/payee name as shown on the ledger (e.g. the value returned by find_merchants).'],
        'merchant_fuzzy' => ['type' => 'string', 'description' => 'APPROXIMATE merchant name — matches ignoring punctuation/spacing/case (e.g. "oaces" → "O\'Aces Bar & Grill"). Use this instead of `merchant`/`q` when you don\'t know the exact spelling.'],
        'from'           => ['type' => 'string', 'description' => 'Earliest date, YYYY-MM-DD.'],
        'to'             => ['type' => 'string', 'description' => 'Latest date, YYYY-MM-DD.'],
        'amin'           => ['type' => 'number', 'description' => 'Minimum dollar magnitude (ABS amount).'],
        'amax'           => ['type' => 'number', 'description' => 'Maximum dollar magnitude (ABS amount).'],
    ];
    return [
        [
            'name'        => 'get_accounts',
            'description' => 'List every account the user can see (checking, savings, credit cards, loans, brokerage, retirement, manual) with its current balance, type, and institution. Use this to answer "what accounts do we have", "what is the balance of X", or to find an account before another query.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_net_worth',
            'description' => 'Household net worth: the latest total, how it changed over the last 30/90/365 days, and the current breakdown (Cash, Investments, Retirement, Home, Debt). Folds in the home value and retirement accounts.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_spending_by_category',
            'description' => 'Total true spending by category over a period (excludes internal transfers and credit-card payments, so it is real outflow). Answers "how much did we spend on dining/groceries/etc". Default window is the last N `days`; pass `from` (and optionally `to`) as YYYY-MM-DD to scope to an explicit date range instead (e.g. a single month), which overrides `days`.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'days' => $int('Lookback window in days (ignored if `from` is given)', 30, 1, 366),
                'from' => ['type' => 'string', 'description' => 'Earliest date YYYY-MM-DD. Overrides `days` when set.'],
                'to'   => ['type' => 'string', 'description' => 'Latest date YYYY-MM-DD (defaults to today).'],
            ]],
        ],
        [
            'name'        => 'get_cash_flow',
            'description' => 'Monthly income vs. expense vs. net (and savings rate) over the last N months. Income counts only depository/investment inflows; expense excludes internal transfers and credit-card payments.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'months' => $int('Number of months', 6, 1, 36),
            ]],
        ],
        [
            'name'        => 'find_merchants',
            'description' => 'Find merchants/payees by an APPROXIMATE name, ignoring punctuation, spacing, and capitalization, and tolerating small typos — so "OAces" finds "O\'Aces Bar & Grill", "mcd" finds "McDonald\'s", "starbcks" finds "Starbucks". Returns the matching CANONICAL merchant names, ranked best-match first, each with how many transactions, total spent, total received, and the first/last date (optionally within a window). USE THIS FIRST whenever the user names a place/store/business/payee — to discover the real spelling, to count visits, or before search_transactions. Then answer from the count/totals, or pass the exact returned `merchant` to search_transactions to list the rows. The candidates may include a few near-misses — judge by the name (and any context the user gave) and use the one(s) that genuinely match; never claim "no transactions" until this returns nothing.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['q'], 'properties' => [
                'q'     => ['type' => 'string', 'description' => 'Approximate merchant name or the distinctive keyword(s), e.g. "oaces", "starbucks", "amazon". Keep it short — every word you give must appear in the name.'],
                'days'  => $int('Only count transactions within the last N days (omit for all history)', 365, 1, 1825),
                'from'  => ['type' => 'string', 'description' => 'Earliest date YYYY-MM-DD (overrides days).'],
                'to'    => ['type' => 'string', 'description' => 'Latest date YYYY-MM-DD.'],
                'limit' => $int('Max merchants to return', 25, 1, 50),
            ]],
        ],
        [
            'name'        => 'aggregate_transactions',
            'description' => 'Compute counts/totals/averages over transactions in ONE call — never page through search_transactions to count or sum. Same filters as search_transactions (q, account, account_id, category, merchant, merchant_fuzzy, from, to, amin, amax) plus `group_by`. Amounts are RAW-LEDGER magnitudes (matches search_transactions, NOT the true-expense spend pages): "+" is money OUT, "−" is money IN, so `total_out` is spending and `total_in` is money received. Each group returns: count, total_out, total_in, avg, min, max (avg/min/max are of the dollar magnitude), first_date, last_date. Answers "how many times / total / average at X", "spend by month", "top categories", etc. Up to 50 groups.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' =>
                $txnFilterProps + [
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'category', 'merchant', 'month', 'account'], 'description' => 'How to break down the results. "none" (default) = one overall total; "month" is chronological; the rest are ranked by total_out.'],
                ],
            ],
        ],
        [
            'name'        => 'search_transactions',
            'description' => 'Search/list individual transactions with optional filters. Amounts are MAGNITUDES; in this app "+" means money OUT (spending) and "−" means money IN. Use this for "show me transactions at X", "find charges over $200 last month", "what is the oldest/earliest charge on my <account>", etc. Returns at most 40 rows. For counts, totals, or averages, use aggregate_transactions instead — do NOT page through this. By default rows are NEWEST-first; set sort="oldest" to get the EARLIEST first (so the oldest transaction on an account is a single call: account="<name>", sort="oldest", limit=1). To scope to one account (e.g. "American Express", "amex", a last-4), pass `account` — no need to call get_accounts first. To match a merchant when you are unsure of the exact spelling, prefer find_merchants first (or use merchant_fuzzy here) — a plain `q` is a literal substring and will MISS names with punctuation (e.g. "OAces" will not match "O\'Aces Bar & Grill").',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' =>
                $txnFilterProps + [
                    'sort'   => ['type' => 'string', 'enum' => ['newest', 'oldest'], 'description' => 'Order of results by date. "newest" (default) = most recent first; "oldest" = earliest first (use for the oldest/first-ever transaction).'],
                    'offset' => $int('Skip this many rows (for paging past the first 40)', 0, 0, 100000),
                    'limit'  => $int('Max rows', 40, 1, 40),
                ],
            ],
        ],
        [
            'name'        => 'get_budgets',
            'description' => 'The household monthly budgets with this month\'s spend, the available amount (incl. any rollover carryover), and whether each is over budget.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_recurring',
            'description' => 'Recurring streams Plaid detected — subscriptions/bills (outflow) and recurring income (inflow): merchant, cadence, and typical amount.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_upcoming_bills',
            'description' => 'Bills and recurring outflows projected to come due within the next N days (merges liability due dates + projected recurring charges).',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'days' => $int('Horizon in days', 30, 1, 120),
            ]],
        ],
        [
            'name'        => 'get_liabilities',
            'description' => 'Debts: credit cards and loans with outstanding balance, APR, minimum payment, and next due date.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_investments',
            'description' => 'Investment holdings summary (non-retirement and retirement): total market value, total cost basis, unrealized gain/loss, and the largest holdings.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_retirement',
            'description' => 'Retirement summary: combined 401(k)/IRA total, contributed this year (employee/employer), trailing-12-month contributions, the derived growth rate, and the projection to the target year.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_goals',
            'description' => 'Savings goals: target, current progress, percent complete, and amount remaining.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_economic_context',
            'description' => 'Latest macro indicators from FRED: CPI (inflation), the 30-year mortgage rate, the 10-year Treasury, and the federal funds rate. Useful for refinance, savings-rate, and inflation context.',
            'input_schema' => $noargs,
        ],
    ];
}

/** The system prompt: who the assistant is + the rules of engagement. */
function assistant_system_prompt(PDO $pdo, int $uid): string
{
    $names = array_values(household_users());
    $who   = $names ? implode(' and ', array_map(fn($n) => (string)$n, $names)) : 'the household';
    $today = date('l, F j, Y');   // app TZ (America/Los_Angeles)
    return <<<TXT
You are the built-in financial assistant for a private 2-person household budgeting app used by {$who}. Today is {$today}.

Your job: answer questions about THIS household's real finances by calling the provided tools, then reply in clear, concise prose.

Rules:
- NEVER invent or estimate a number. If you need a figure, CALL A TOOL to get it. If a tool returns no data, say so plainly rather than guessing.
- Amount sign convention in this app: "+" means money OUT (an expense/payment) and "−" means money IN (income/credit/refund). The spending and cash-flow tools already account for this — present spending as positive dollars and income as positive dollars; don't show raw signs to the user.
- Format money as US dollars (e.g. $1,234.56). Round sensibly. Use the user's own words for categories when natural (e.g. "dining" for FOOD_AND_DRINK).
- You can call several tools, in sequence, to build an answer (e.g. find an account, then its transactions). Be efficient — request only what you need, and prefer one focused query over many broad ones.
- For counts, totals, or averages over transactions ("how many times did we eat at X", "what did we spend at Y this year", "spend by month"), call aggregate_transactions — it answers in ONE call. NEVER page through search_transactions to count or add up rows.
- Merchant/payee names in the data often include punctuation, abbreviations, or extra words the user will NOT type exactly — the bar "O'Aces Bar & Grill" for "OAces", "AMZN MKTP" for Amazon, "SQ *COFFEE" for a coffee shop. So when the user names a place/store/business, do NOT assume a plain search matches: call find_merchants with a short approximate term (it matches ignoring punctuation/spacing/case and tolerates small typos) to discover the real name(s) and visit counts, then answer from those, or pass the exact returned merchant name to search_transactions. The candidate list may contain a few near-misses — examine the names (and any context the user gave, like "my favorite bar") and use only the one(s) that genuinely match; if two are plausible, briefly ask or present the options. Only say "no transactions / none" AFTER find_merchants also comes back empty.
- Keep answers short and skimmable. Lead with the direct answer, then a brief supporting detail or two. Use a short bulleted list for multiple figures. No preamble like "Sure!".
- This data is already scoped to what the asking user is allowed to see; you don't need to worry about permissions.
- You are READ-ONLY: you cannot add, change, or delete anything (no creating budgets, paying bills, moving money). If asked to act, explain that you can only look things up and suggest where in the app to do it.
- If a question is not about this household's finances, answer briefly and helpfully but stay focused on the budgeting app's scope.
TXT;
}

/**
 * Execute ONE whitelisted tool and return a compact, JSON-encodable result array.
 * A hard match() — an unknown name can never run anything. All reads are VIS-scoped via $uid.
 */
function assistant_dispatch(PDO $pdo, int $uid, string $name, array $in): array
{
    $clampInt = fn($v, $def, $min, $max) => max($min, min($max, (int)($v ?? $def)));

    switch ($name) {
        case 'get_accounts': {
            $out = [];
            foreach (q_accounts($pdo, $uid) as $a) {
                $out[] = [
                    'id'          => $a['account_id'],   // stable handle for search_transactions.account_id
                    'name'        => $a['name'],
                    'type'        => $a['type'],
                    'subtype'     => $a['subtype'],
                    'institution' => $a['institution_name'],
                    'mask'        => $a['mask'],
                    'balance'     => assistant_money($a['balance_current']),
                    'available'   => assistant_money($a['balance_available']),
                    'owner'       => owner_first_name($a['owner_id'] ?? null) ?: null,
                ];
            }
            return ['accounts' => $out, 'count' => count($out)];
        }

        case 'get_net_worth': {
            $hist = q_networth($pdo);
            $current = $hist ? (float)end($hist)['net_worth'] : 0.0;
            $changes = [];
            foreach ([30, 90, 365] as $d) {
                $c = q_networth_change($pdo, $current, $d);
                $changes[(string)$d . 'd'] = [
                    'change'  => assistant_money($c['abs']),
                    'percent' => $c['pct'],
                    'from'    => assistant_money($c['from']),
                    'as_of'   => $c['date'],
                ];
            }
            $comp = q_networth_composition($pdo, 365);
            return [
                'net_worth'   => assistant_money($current),
                'changes'     => $changes,
                'composition' => array_map('assistant_money', $comp['current'] ?? []),
            ];
        }

        case 'get_spending_by_category': {
            $from = (isset($in['from']) && trim((string)$in['from']) !== '') ? (string)$in['from'] : null;
            $to   = (isset($in['to'])   && trim((string)$in['to'])   !== '') ? (string)$in['to']   : null;
            $days = $clampInt($in['days'] ?? null, 30, 1, 366);
            $rows = [];
            $total = 0.0;
            foreach (q_spending($pdo, $uid, $days, $from, $to) as $r) {
                $amt = (float)$r['total'];
                $total += $amt;
                $rows[] = ['category' => pretty_cat($r['category']), 'code' => $r['category'], 'spent' => round($amt, 2)];
            }
            // Report the window that actually applied: an explicit `from` overrides the days window.
            return [
                'days'       => $from !== null ? null : $days,
                'from'       => $from,
                'to'         => $to,
                'total'      => round($total, 2),
                'categories' => array_slice($rows, 0, 30),
            ];
        }

        case 'aggregate_transactions': {
            $opts = [];
            foreach (['q', 'category', 'merchant', 'merchant_fuzzy', 'account', 'account_id', 'from', 'to'] as $k) {
                if (isset($in[$k]) && trim((string)$in[$k]) !== '') $opts[$k] = (string)$in[$k];
            }
            foreach (['amin', 'amax'] as $k) {
                if (isset($in[$k]) && is_numeric($in[$k])) $opts[$k] = (float)$in[$k];
            }
            $group = in_array($in['group_by'] ?? 'none', ['none', 'category', 'merchant', 'month', 'account'], true)
                ? (string)$in['group_by'] : 'none';
            $opts['group_by'] = $group;
            $res  = q_transactions_aggregate($pdo, $uid, $opts);
            $rows = [];
            foreach ($res['groups'] as $g) {
                if (!$g) continue;
                $row = [
                    'count'      => (int)$g['n'],
                    'total_out'  => assistant_money($g['total_out']),
                    'total_in'   => assistant_money($g['total_in']),
                    'avg'        => assistant_money($g['avg_abs']),
                    'min'        => assistant_money($g['min_abs']),
                    'max'        => assistant_money($g['max_abs']),
                    'first_date' => $g['first_date'],
                    'last_date'  => $g['last_date'],
                ];
                if ($group !== 'none') {
                    $label = $group === 'category' ? pretty_cat((string)$g['grp']) : $g['grp'];
                    $row = ['group' => $label] + $row;
                }
                $rows[] = $row;
            }
            return ['group_by' => $group, 'groups' => $rows, 'count' => count($rows)];
        }

        case 'get_cash_flow': {
            $months = $clampInt($in['months'] ?? null, 6, 1, 36);
            $cf = q_cashflow($pdo, $uid, $months);
            $savingsRate = $cf['income'] > 0 ? round(($cf['income'] - $cf['expense']) / $cf['income'] * 100, 1) : null;
            $byMonth = array_map(fn($m) => [
                'month'   => $m['label'],
                'income'  => round((float)$m['income'], 2),
                'expense' => round((float)$m['expense'], 2),
                'net'     => round((float)$m['net'], 2),
            ], $cf['months']);
            return [
                'months'       => $months,
                'income_total' => round((float)$cf['income'], 2),
                'expense_total'=> round((float)$cf['expense'], 2),
                'net_total'    => round((float)$cf['net'], 2),
                'savings_rate_pct' => $savingsRate,
                'by_month'     => $byMonth,
            ];
        }

        case 'find_merchants': {
            $term = trim((string)($in['q'] ?? ''));
            if ($term === '') return ['error' => 'provide an approximate merchant name to search for', 'merchants' => [], 'count' => 0];
            $opts = ['limit' => $clampInt($in['limit'] ?? null, 25, 1, 50)];
            if (isset($in['from']) && trim((string)$in['from']) !== '') $opts['from'] = (string)$in['from'];
            if (isset($in['to'])   && trim((string)$in['to'])   !== '') $opts['to']   = (string)$in['to'];
            // days → from (app-TZ), only when no explicit from was given.
            if (!isset($opts['from']) && isset($in['days']) && is_numeric($in['days'])) {
                $d = $clampInt($in['days'], 365, 1, 1825);
                $opts['from'] = date('Y-m-d', strtotime("-{$d} days"));
            }
            $rows = q_merchant_search($pdo, $uid, $term, $opts);
            $out  = array_map(fn($r) => [
                'merchant'     => $r['merchant'],
                'transactions' => (int)$r['txn_count'],
                'spent'        => assistant_money($r['spent']),
                'received'     => assistant_money($r['received']),
                'first_date'   => $r['first_date'],
                'last_date'    => $r['last_date'],
            ], $rows);
            return ['query' => $term, 'window_from' => $opts['from'] ?? null, 'merchants' => $out, 'count' => count($out)];
        }

        case 'search_transactions': {
            $opts = [];
            foreach (['q', 'category', 'merchant', 'merchant_fuzzy', 'account', 'account_id', 'from', 'to'] as $k) {
                if (isset($in[$k]) && trim((string)$in[$k]) !== '') $opts[$k] = (string)$in[$k];
            }
            foreach (['amin', 'amax'] as $k) {
                if (isset($in[$k]) && is_numeric($in[$k])) $opts[$k] = (float)$in[$k];
            }
            // Earliest-first only when explicitly asked; otherwise q_transactions stays newest-first.
            if (($in['sort'] ?? '') === 'oldest') $opts['sort'] = 'oldest';
            $opts['offset'] = $clampInt($in['offset'] ?? null, 0, 0, 100000);
            $opts['limit']  = $clampInt($in['limit'] ?? null, 40, 1, 40);
            $rows = [];
            foreach (q_transactions($pdo, $uid, $opts) as $t) {
                $amt = (float)$t['amount'];
                $rows[] = [
                    'date'      => $t['date'],
                    'merchant'  => ($t['merchant_name'] ?: $t['name']),
                    'category'  => pretty_cat($t['category']),
                    'direction' => $amt >= 0 ? 'out' : 'in',
                    'amount'    => round(abs($amt), 2),
                    'account'   => $t['account_name'],
                    'pending'   => (bool)$t['pending'],
                ];
            }
            return ['count' => count($rows), 'transactions' => $rows];
        }

        case 'get_budgets': {
            // q_budgets() returns a WRAPPER ['month'=>'YYYY-MM','budgets'=>[…rows…]] — iterate the
            // inner list, not the wrapper (else $b is the month string → TypeError on $b['available']).
            $bd  = q_budgets($pdo);
            $out = [];
            foreach (($bd['budgets'] ?? []) as $b) {
                $avail = (float)($b['available'] ?? $b['monthly_limit']);
                $spent = (float)$b['spent'];
                $out[] = [
                    'category'  => pretty_cat($b['category']),
                    'limit'     => round((float)$b['monthly_limit'], 2),
                    'available' => round($avail, 2),
                    'carryover' => round((float)($b['carryover'] ?? 0), 2),
                    'spent'     => round($spent, 2),
                    'remaining' => round($avail - $spent, 2),
                    'over'      => $spent > $avail,
                ];
            }
            return ['month' => date('F Y'), 'budgets' => $out, 'count' => count($out)];
        }

        case 'get_recurring': {
            $in_ = []; $out_ = [];
            foreach (q_recurring($pdo, $uid) as $r) {
                $row = [
                    'merchant'  => ($r['merchant_name'] ?: $r['description']),
                    'frequency' => $r['frequency'],
                    'typical'   => assistant_money($r['average_amount']),
                    'last_date' => $r['last_date'],
                    'account'   => $r['account_name'],
                ];
                if (($r['direction'] ?? '') === 'inflow') $in_[] = $row; else $out_[] = $row;
            }
            return ['outflows' => $out_, 'inflows' => $in_];
        }

        case 'get_upcoming_bills': {
            $days = $clampInt($in['days'] ?? null, 30, 1, 120);
            $from = new DateTimeImmutable('today');
            $to   = $from->add(new DateInterval('P' . $days . 'D'));
            $occ  = bill_occurrences(q_liabilities($pdo, $uid), q_recurring($pdo, $uid), $from, $to);
            $out  = array_map(fn($o) => [
                'date'   => $o['date'],
                'label'  => $o['label'],
                'detail' => $o['sublabel'],
                'amount' => $o['amount'],   // already rounded or null ("unknown")
            ], $occ);
            $known = array_sum(array_map(fn($o) => (float)($o['amount'] ?? 0), $occ));
            return ['horizon_days' => $days, 'count' => count($out), 'known_total' => round($known, 2), 'bills' => $out];
        }

        case 'get_liabilities': {
            $out = [];
            foreach (q_liabilities($pdo, $uid) as $l) {
                $out[] = [
                    'account'      => $l['account_name'],
                    'type'         => $l['liability_type'],
                    'balance'      => assistant_money($l['outstanding_balance'] ?? $l['balance_current']),
                    'apr_pct'      => $l['apr_percentage'] !== null ? round((float)$l['apr_percentage'], 2) : null,
                    'min_payment'  => assistant_money($l['minimum_payment_amount']),
                    'next_due'     => $l['next_payment_due_date'],
                ];
            }
            return ['liabilities' => $out, 'count' => count($out)];
        }

        case 'get_investments': {
            // Gain/loss must compare value vs cost over the SAME (cost-bearing) holdings — a lot
            // with no cost basis (common on manual/Plaid retirement lots) would otherwise add full
            // market value against $0 cost and read as ~100% gain. So accumulate $costVal (value of
            // cost-bearing holdings) alongside $totCost, and surface how many lots lack a basis so
            // the model can caveat. $totVal stays the full market value of all holdings.
            $totVal = 0.0; $totCost = 0.0; $costVal = 0.0; $missing = 0; $holds = [];
            foreach (q_holdings($pdo, $uid) as $h) {
                $val = (float)($h['institution_value'] ?? 0);
                if (abs($val) < 0.005) continue;   // skip cash placeholders
                $cost = $h['cost_basis'] !== null ? (float)$h['cost_basis'] : null;
                $totVal += $val;
                if ($cost !== null) { $totCost += $cost; $costVal += $val; } else { $missing++; }
                $holds[] = [
                    'ticker'     => $h['ticker_symbol'],
                    'name'       => $h['security_name'],
                    'value'      => round($val, 2),
                    'quantity'   => $h['quantity'] !== null ? round((float)$h['quantity'], 4) : null,
                    'cost_basis' => $cost !== null ? round($cost, 2) : null,
                    'account'    => $h['account_name'],
                    'retirement' => is_retirement_account($h),
                ];
            }
            usort($holds, fn($a, $b) => $b['value'] <=> $a['value']);
            return [
                'total_value'           => round($totVal, 2),
                'cost_basis_total'      => round($totCost, 2),
                'gain_loss'             => round($costVal - $totCost, 2),   // over cost-bearing holdings only
                'gain_loss_pct'         => $totCost > 0 ? round(($costVal - $totCost) / $totCost * 100, 1) : null,
                'holdings_missing_cost' => $missing,                        // excluded from gain/loss
                'holdings'              => array_slice($holds, 0, 25),
                'holding_count'         => count($holds),
            ];
        }

        case 'get_retirement': {
            $v = build_retirement_view($pdo, $uid);
            $proj = $v['projection'] ?? null;
            return [
                'total'              => $v['total'] ?? null,
                'contributed_ytd'    => $v['ytd'] ?? null,
                'contributed_ttm'    => $v['ttm_contrib'] ?? null,
                'growth_rate_pct'    => isset($v['rate']) ? round((float)$v['rate'] * 100, 2) : null,
                'growth_rate_basis'  => $v['rate_basis'] ?? null,
                'projection'         => $proj ? [
                    'target_year'   => $proj['target_year'],
                    'projected'     => $proj['projected'],
                    'annual_contrib'=> round((float)$proj['annual_contrib'], 2),
                    'target_amount' => $proj['target_amount'],
                    'progress_pct'  => $proj['progress'],
                ] : null,
            ];
        }

        case 'get_goals': {
            $out = [];
            foreach (q_goals($pdo, $uid) as $g) {
                $out[] = [
                    'name'      => $g['name'],
                    'target'    => $g['target'],
                    'current'   => $g['current'],
                    'percent'   => round((float)$g['pct'], 1),
                    'remaining' => $g['remaining'],
                    'reached'   => (bool)$g['reached'],
                    'tied_to'   => $g['tied'] ? $g['account_name'] : null,
                ];
            }
            return ['goals' => $out, 'count' => count($out)];
        }

        case 'get_economic_context': {
            $series = [
                'CPIAUCSL'     => 'CPI (consumer price index)',
                'MORTGAGE30US' => '30-year fixed mortgage rate (%)',
                'DGS10'        => '10-year Treasury yield (%)',
                'FEDFUNDS'     => 'Federal funds rate (%)',
            ];
            $out = [];
            foreach ($series as $id => $label) {
                $row = q_fred_latest($pdo, $id);
                $out[] = [
                    'indicator' => $label,
                    'value'     => $row ? round((float)$row['value'], 2) : null,
                    'as_of'     => $row['date'] ?? null,
                ];
            }
            return ['indicators' => $out];
        }
    }

    // Hard whitelist: anything else is rejected, never executed.
    return ['error' => 'unknown tool: ' . $name];
}

/**
 * POST one Messages request. Returns the decoded array, or ['__error'=>msg].
 */
function assistant_http(string $key, array $body): array
{
    $ch = curl_init(ASSISTANT_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ASSISTANT_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . ASSISTANT_VERSION,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $resp   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);

    if ($resp === false)  return ['__error' => 'Network error contacting Anthropic: ' . $cerr];
    $json = json_decode((string)$resp, true);
    if (!is_array($json)) return ['__error' => 'Unreadable response from Anthropic (HTTP ' . $status . ').'];
    if ($status !== 200) {
        return ['__error' => 'Anthropic error: ' . ($json['error']['message'] ?? ('HTTP ' . $status))];
    }
    return $json;
}

/**
 * Validate + normalise the conversation the client sent. Each message must be
 * {role: 'user'|'assistant', content: non-empty string}. Caps the count + length; ensures
 * it starts with a user turn and ends with a user turn (the new question). Returns the
 * cleaned messages array, or [] if it can't be made valid.
 */
function assistant_clean_messages(array $messages): array
{
    $out = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : (($m['role'] ?? '') === 'user' ? 'user' : null);
        $content = is_string($m['content'] ?? null) ? trim($m['content']) : '';
        if ($role === null || $content === '') continue;
        if (mb_strlen($content) > ASSISTANT_MAX_MSG_LEN) $content = mb_substr($content, 0, ASSISTANT_MAX_MSG_LEN);
        $out[] = ['role' => $role, 'content' => $content];
    }
    // Trim leading assistant turns (the API requires the first message to be from the user).
    while ($out && $out[0]['role'] === 'assistant') array_shift($out);
    // Keep only the most recent ASSISTANT_MAX_MESSAGES, then re-trim a leading assistant turn.
    if (count($out) > ASSISTANT_MAX_MESSAGES) $out = array_slice($out, -ASSISTANT_MAX_MESSAGES);
    while ($out && $out[0]['role'] === 'assistant') array_shift($out);
    // The conversation must end with the user's new question.
    if (!$out || end($out)['role'] !== 'user') return [];
    return $out;
}

/**
 * Run the agentic loop and return the final answer.
 *
 * @param array $messages conversation from the client: [{role:'user'|'assistant', content:string}, …]
 *                        ending with the user's new question. Tool-call/result turns are NOT
 *                        sent by the client — we generate them internally each turn and only
 *                        the final text is returned (so they never need to round-trip).
 */
function assistant_respond(PDO $pdo, int $uid, array $messages, array $cfg): array
{
    $fail = fn(string $e) => ['ok' => false, 'reply' => null, 'tools' => [], 'rounds' => 0, 'error' => $e, 'usage' => ['input' => 0, 'output' => 0]];

    $key = trim((string)($cfg['anthropic']['api_key'] ?? ''));
    if ($key === '') return $fail('The assistant is not configured.');

    $msgs = assistant_clean_messages($messages);
    if (!$msgs) return $fail('Ask me a question to get started.');

    $model  = assistant_model($cfg);
    $system = assistant_system_prompt($pdo, $uid);
    $tools  = assistant_tools();

    // Prompt caching (#27 v2): send `system` as a block array with ONE ephemeral breakpoint.
    // The wire render order is tools → system → messages, so this single breakpoint caches the
    // whole tools + system prefix. Rounds 2+ of a question and follow-ups within ~5 min then read
    // that prefix at ~0.1× input price. The system prompt's date('l, F j, Y') is stable within a
    // day, so the prefix stays byte-identical across a session's turns. $thinking is the optional
    // extended-thinking control (disabled for adaptive-default models so it can't eat the answer).
    $systemBlocks = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];
    $thinking     = assistant_thinking_param($model);
    // One place that assembles a request body → every call shares the identical cached prefix.
    $buildBody = function (array $messages) use ($model, $systemBlocks, $tools, $thinking): array {
        $body = [
            'model'      => $model,
            'max_tokens' => ASSISTANT_MAXTOKENS,
            'system'     => $systemBlocks,
            'tools'      => $tools,
            'messages'   => $messages,
        ];
        if ($thinking !== null) $body['thinking'] = $thinking;
        return $body;
    };

    // Working message list, content-as-blocks once tools enter the picture.
    $work = array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $msgs);

    $usedTools = [];
    $tokIn = 0; $tokOut = 0;

    for ($round = 0; $round < ASSISTANT_MAX_ROUNDS; $round++) {
        $json = assistant_http($key, $buildBody($work));
        if (isset($json['__error'])) {
            error_log('assistant: ' . $json['__error']);
            return $fail('Sorry — I could not reach the assistant just now. Please try again.');
        }

        $tokIn  += (int)($json['usage']['input_tokens'] ?? 0);
        $tokOut += (int)($json['usage']['output_tokens'] ?? 0);

        $content = is_array($json['content'] ?? null) ? $json['content'] : [];
        $stop    = $json['stop_reason'] ?? '';

        if ($stop !== 'tool_use') {
            // Final answer: concatenate any text blocks.
            $text = '';
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'text') $text .= $b['text'];
            }
            $text = trim($text);
            return [
                'ok'     => true,
                'reply'  => $text !== '' ? $text : "I'm not sure how to answer that from the available data.",
                'tools'  => array_values(array_unique($usedTools)),
                'rounds' => $round + 1,
                'error'  => null,
                'usage'  => ['input' => $tokIn, 'output' => $tokOut],
            ];
        }

        // Append the assistant's tool-use turn, then run each tool and reply with results.
        // ⚠️ A no-argument tool call has input `{}` in the API JSON, but json_decode(…, true)
        // turns `{}` into an empty PHP array [], which re-encodes as a JSON array `[]` — and the
        // API rejects the resent turn ("tool_use.input: Input should be an object"). Coerce an
        // empty-array input back to an object so the round-trip preserves `{}`. (Non-empty inputs
        // are assoc arrays → already encode as objects; the dispatch loop below guards with
        // is_array, so a coerced stdClass simply yields the default [] args.)
        foreach ($content as &$blk) {
            if (($blk['type'] ?? '') === 'tool_use' && ($blk['input'] ?? null) === []) {
                $blk['input'] = new stdClass();
            }
        }
        unset($blk);
        $work[] = ['role' => 'assistant', 'content' => $content];
        $results = [];
        foreach ($content as $b) {
            if (($b['type'] ?? '') !== 'tool_use') continue;
            $tname = (string)($b['name'] ?? '');
            $tin   = is_array($b['input'] ?? null) ? $b['input'] : [];
            $usedTools[] = $tname;
            try {
                $res = assistant_dispatch($pdo, $uid, $tname, $tin);
            } catch (Throwable $e) {
                error_log('assistant tool ' . $tname . ': ' . $e->getMessage());
                $res = ['error' => 'that lookup failed'];
            }
            $results[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $b['id'] ?? '',
                'content'     => json_encode($res, JSON_UNESCAPED_SLASHES),
            ];
        }
        if (!$results) {
            // tool_use stop but no tool_use block — shouldn't happen; bail gracefully.
            return $fail('Sorry — I got confused. Please rephrase your question.');
        }
        $work[] = ['role' => 'user', 'content' => $results];

        // Budget guard: if we've burned the token cap, force a final no-tools turn.
        if ($tokIn + $tokOut > ASSISTANT_TOKEN_CAP) {
            // Nudge via a FINAL USER MESSAGE (not by editing $system) so the cached tools+system
            // prefix stays intact — appending to $system would invalidate the whole cache (#27 v2).
            $work[] = ['role' => 'user', 'content' => 'You have gathered enough data — answer now without calling more tools.'];
            $json = assistant_http($key, $buildBody($work));
            if (isset($json['__error'])) return $fail('Sorry — I could not finish that. Please try again.');
            $text = '';
            foreach (($json['content'] ?? []) as $b) {
                if (($b['type'] ?? '') === 'text') $text .= $b['text'];
            }
            return [
                'ok'     => true,
                'reply'  => trim($text) ?: 'That question needs more lookups than I can do at once — try narrowing it.',
                'tools'  => array_values(array_unique($usedTools)),
                'rounds' => $round + 1,
                'error'  => null,
                'usage'  => ['input' => $tokIn, 'output' => $tokOut],
            ];
        }
    }

    // Hit the round cap without a final text answer — ask one last time, no tools.
    // Same cache-preserving nudge as the token-cap path: append a user turn, keep $system unchanged.
    $work[] = ['role' => 'user', 'content' => 'Answer now with what you have; do not call any more tools.'];
    $json = assistant_http($key, $buildBody($work));
    $text = '';
    if (!isset($json['__error'])) {
        foreach (($json['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') $text .= $b['text'];
        }
        $tokIn  += (int)($json['usage']['input_tokens'] ?? 0);
        $tokOut += (int)($json['usage']['output_tokens'] ?? 0);
    }
    return [
        'ok'     => true,
        'reply'  => trim($text) ?: "That took more steps than I can handle in one go — try asking something more specific.",
        'tools'  => array_values(array_unique($usedTools)),
        'rounds' => ASSISTANT_MAX_ROUNDS,
        'error'  => null,
        'usage'  => ['input' => $tokIn, 'output' => $tokOut],
    ];
}
