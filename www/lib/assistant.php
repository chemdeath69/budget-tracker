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
 * A short human-facing status label for a tool name — used by the streaming endpoint
 * (api/assistant_stream.php) to show "Searching transactions…" per round while the tool-use
 * loop runs (the "status line" progress UX). PURE presentation; an unknown/new tool falls back
 * to a generic phrase, so it never needs updating in lock-step with the registry.
 */
function assistant_tool_label(string $name): string
{
    static $map = [
        'get_accounts'             => 'Looking up your accounts',
        'get_net_worth'            => 'Checking your net worth',
        'get_spending_by_category' => 'Adding up your spending',
        'get_cash_flow'            => 'Reviewing your cash flow',
        'get_spending_trend'       => 'Analyzing spending trends',
        'find_merchants'           => 'Finding merchants',
        'aggregate_transactions'   => 'Crunching transactions',
        'search_transactions'      => 'Searching transactions',
        'get_budgets'              => 'Checking your budgets',
        'get_recurring'            => 'Reviewing recurring charges',
        'get_upcoming_bills'       => 'Checking upcoming bills',
        'get_liabilities'          => 'Reviewing your debts',
        'get_investments'          => 'Reviewing your investments',
        'get_retirement'           => 'Checking your retirement',
        'get_goals'                => 'Reviewing your goals',
        'get_cash'                 => 'Checking your cash on hand',
        'get_cash_forecast'        => 'Projecting your cash flow',
        'get_safe_to_spend'        => 'Calculating safe-to-spend',
        'get_debt_plan'            => 'Modeling your debt payoff',
        'get_data_freshness'       => 'Checking data freshness',
        'get_economic_context'     => 'Pulling economic data',
        'get_property'             => 'Checking your home & mortgage',
        'get_allocation'           => 'Reviewing your asset allocation',
        'get_fees'                 => 'Analyzing investment fees',
        'get_dividends'            => 'Tallying dividend income',
        'get_security'             => 'Looking up that holding',
        'get_peer_comparison'      => 'Comparing to typical households',
        'get_budget_history'       => 'Reviewing budget history',
    ];
    return $map[$name] ?? 'Working on it';
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
            'name'        => 'get_spending_trend',
            'description' => 'Month-by-month true spending broken down by category over the last N months — the category × month matrix behind the Trends page. Answers "how did dining spending trend", "which categories are going up", "what did we spend by category each month". Returns the top categories (rest folded into "Other") with each month\'s total, plus a change block comparing this month-to-date vs the prior-3-month average and the same month last year. Same true-expense basis as get_spending_by_category / get_cash_flow (excludes transfers + credit-card payments).',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'months' => $int('Number of months to include', 6, 1, 24),
            ]],
        ],
        [
            'name'        => 'find_merchants',
            'description' => 'Find merchants/payees by an APPROXIMATE name, ignoring punctuation, spacing, and capitalization, and tolerating small typos — so "OAces" finds "O\'Aces Bar & Grill", "mcd" finds "McDonald\'s", "starbcks" finds "Starbucks". Returns the matching CANONICAL merchant names, ranked best-match first, each with how many transactions, total spent, total received, and the first/last date (optionally within a window). USE THIS FIRST whenever the user names a place/store/business/payee — to discover the real spelling, to count visits, or before search_transactions. Then answer from the count/totals, or pass the exact returned `merchant` to search_transactions to list the rows. The candidates may include a few near-misses — judge by the name (and any context the user gave) and use the one(s) that genuinely match; never claim "no transactions" until this returns nothing. If you OMIT `q` (leave it empty), this instead returns the TOP merchants by total spend over the window (true-expense basis, ranked by spend) — use that for "who do we spend the most on", "top merchants/stores", etc.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'q'     => ['type' => 'string', 'description' => 'Approximate merchant name or the distinctive keyword(s), e.g. "oaces", "starbucks", "amazon". Keep it short — every word you give must appear in the name. Omit it entirely to get the top merchants by spend instead.'],
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
            'name'        => 'get_cash',
            'description' => 'Liquid CASH ON HAND over time — the combined balance of checking + savings accounts only (not credit cards, investments, or retirement). Returns the current total and its history over the window, so you can answer "how much cash do we have", "how has our cash changed", "cash trend". For a specific past date use get_balance_history instead.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'window' => $int('Lookback window in days', 90, 7, 1825),
            ]],
        ],
        [
            'name'        => 'get_cash_forecast',
            'description' => 'Forward-looking cash-flow projection: walks today\'s checking+savings balance day-by-day over the next 30/60/90 days, adding projected recurring income, subtracting scheduled bills, and spreading average daily spending. Returns the projected low point (and when it hits), the projected end balance, and whether the balance is projected to go negative. Answers "will we run low on cash before payday", "what\'s our cash forecast". An estimate — only Plaid-detected recurring income is projected (irregular income is not).',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'days' => $int('Horizon in days (30, 60, or 90)', 30, 30, 90),
            ]],
        ],
        [
            'name'        => 'get_safe_to_spend',
            'description' => 'This calendar month\'s spending-plan figure: expected income − committed bills − monthly savings target − discretionary spent so far = "safe to spend". Also returns free-to-spend, spent-so-far, days left, and a suggested per-day pace. Answers "how much can we safely spend this month", "are we on budget". No parameters.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_debt_plan',
            'description' => 'Debt payoff planner / what-if. Simulates paying down all credit cards & loans and returns, for the chosen strategy, the debt-free month, total interest paid, and interest/months SAVED vs paying minimums only. Use `extra_monthly` to model throwing extra money at debt ("what if we pay an extra $200/month"). `strategy`: "avalanche" (highest APR first, cheapest), "snowball" (smallest balance first, quick wins), or "minimums" (baseline). Mortgage is excluded unless `include_mortgage` is true. Honest-number: a debt with no reported APR is modeled at 0% (understates interest).',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'strategy'         => ['type' => 'string', 'enum' => ['avalanche', 'snowball', 'minimums'], 'description' => 'Payoff order. "avalanche" (default) = highest APR first; "snowball" = smallest balance first; "minimums" = minimums-only baseline.'],
                'extra_monthly'    => ['type' => 'number', 'description' => 'Extra dollars per month applied on top of the minimum payments (default 0). Use this for "what if we pay an extra $X/month".'],
                'include_mortgage' => ['type' => 'boolean', 'description' => 'Fold the mortgage into the plan (default false — it dwarfs everything).'],
            ]],
        ],
        [
            'name'        => 'get_data_freshness',
            'description' => 'How current the linked-bank data is: per-bank last-sync age and connection status (healthy vs error, with the error code). Use this to caveat an answer when data may be stale, or to answer "when did our accounts last sync", "is any bank disconnected".',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_economic_context',
            'description' => 'Latest macro indicators from FRED: CPI (inflation), the 30-year mortgage rate, the 10-year Treasury, and the federal funds rate. Useful for refinance, savings-rate, and inflation context.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_property',
            'description' => 'The household home + mortgage: current estimated home value, mortgage balance, interest rate, monthly P&I payment, payoff date, equity, loan-to-value, appreciation since purchase, and a refinance comparison of the mortgage rate vs the current market 30-year rate (whether refinancing looks beneficial and the estimated monthly/annual savings). Answers "how much is the house worth", "how much equity do we have", "should we refinance", "when is the mortgage paid off". Returns nothing if no home/mortgage is configured.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_allocation',
            'description' => 'Investment asset allocation: the whole portfolio (taxable + retirement holdings) bucketed into six asset classes (stocks, bonds, cash, crypto, real estate, other) with each class\'s current value and percent, and — if a target mix is set — the target percent, the drift, and rebalance hints (what to trim / add to get back to target). Holdings-based (uninvested brokerage cash isn\'t counted). Answers "what\'s my asset allocation", "am I on target", "am I overweight stocks".',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_fees',
            'description' => 'Investment fee analysis: the portfolio\'s weighted-average expense ratio and the projected annual dollar fee drag, plus the biggest individual fee drags. Expense ratios are entered by hand (no live feed), so coverage may be partial — the result reports how much of the portfolio has a ratio entered and the annual fee is a FLOOR until coverage is complete. Answers "what am I paying in investment fees", "what\'s my expense ratio". Caveat any answer with the coverage figure.',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_dividends',
            'description' => 'Dividend & interest income across the whole portfolio (taxable + retirement): actual dividend/interest income received year-to-date and over the trailing 12 months, the projected forward annual dividend income from current holdings (latest declared rate × payout frequency), the top dividend-paying holdings, and upcoming ex-dividend dates. Answers "how much do we make in dividends", "what\'s our dividend income this year", "which stocks pay us the most". Projection is an estimate (assumes the latest rate/cadence continues).',
            'input_schema' => $noargs,
        ],
        [
            'name'        => 'get_security',
            'description' => 'Detail on ONE security/holding the household owns, looked up by ticker or name: latest price and recent price change, the household\'s position (shares, market value, cost basis, unrealized gain/loss), the money-weighted annualized return (IRR) vs an index benchmark when lot history allows, projected dividend income for the held shares, and upcoming ex-dividend dates. Answers "how is my Apple stock doing", "what\'s our return on VTI", "how many shares of X do we own". Returns "not found" if the household doesn\'t hold that security (never reveals whether it exists elsewhere).',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'ticker' => ['type' => 'string', 'description' => 'The ticker symbol (e.g. "AAPL", "VTI") — preferred. Matched case-insensitively.'],
                'name'   => ['type' => 'string', 'description' => 'A security name or fragment (e.g. "Apple", "Vanguard Total") when the ticker is unknown. Used only if `ticker` is omitted or doesn\'t match.'],
            ]],
        ],
        [
            'name'        => 'get_peer_comparison',
            'description' => 'Compares this household\'s annual spending to the typical U.S. household in a chosen income bracket, using the bundled BLS Consumer Expenditure Survey (2024). Returns, per benchmarked category (food, transportation, healthcare, entertainment, utilities, personal care), your annualized spend vs the typical figure and the difference, plus an overall total. Answers "how does our spending compare to others", "do we spend more than average on dining". Honest-number: only categories with a clean BLS match and tracked spend are compared; brackets are income BEFORE taxes (the user picks the bracket).',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'bracket' => ['type' => 'string', 'enum' => ['q1', 'q2', 'q3', 'q4', 'q5'], 'description' => 'Income quintile to compare against (before taxes): q1 = lowest 20% (under ~$30k), q2 (~$30–57k), q3 = middle (~$57–95k, default), q4 (~$95–156k), q5 = highest 20% (~$156k+). Ask the user their gross household income bracket if it matters; defaults to the middle quintile.'],
            ]],
        ],
        [
            'name'        => 'get_budget_history',
            'description' => 'Month-by-month spending history for each budgeted category over the last N months, alongside its monthly limit, this month-to-date spend, and the prior-3-month average — so you can say whether a category is trending over or under budget. Answers "how has our dining budget done over time", "are we consistently over on groceries". Only returns categories that have a budget set.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
                'months' => $int('Number of months of history', 6, 1, 12),
            ]],
        ],
    ];
}

/** The system prompt: who the assistant is + the rules of engagement. */
function assistant_system_prompt(PDO $pdo, int $uid): string
{
    $names = array_values(household_users());
    $who   = $names ? implode(' and ', array_map(fn($n) => (string)$n, $names)) : 'the household';
    $today = date('l, F j, Y');   // app TZ (America/Los_Angeles)
    $system = <<<TXT
You are the built-in financial assistant for a private 2-person household budgeting app used by {$who}. Today is {$today}.

Your job: answer questions about THIS household's real finances by calling the provided tools, then reply in clear, concise prose.

Rules:
- NEVER invent or estimate a number. If you need a figure, CALL A TOOL to get it. If a tool returns no data, say so plainly rather than guessing.
- Amount sign convention in this app: "+" means money OUT (an expense/payment) and "−" means money IN (income/credit/refund). The spending and cash-flow tools already account for this — present spending as positive dollars and income as positive dollars; don't show raw signs to the user.
- Format money as US dollars (e.g. $1,234.56). Round sensibly. Use the user's own words for categories when natural (e.g. "dining" for FOOD_AND_DRINK).
- You can call several tools, in sequence, to build an answer (e.g. find an account, then its transactions). Be efficient — request only what you need, and prefer one focused query over many broad ones.
- For counts, totals, or averages over transactions ("how many times did we eat at X", "what did we spend at Y this year", "spend by month"), call aggregate_transactions — it answers in ONE call. NEVER page through search_transactions to count or add up rows.
- For how spending shifts over time by category (trends, "is dining going up"), call get_spending_trend. There are dedicated tools for planning questions — get_safe_to_spend (what's left to spend this month), get_cash / get_cash_forecast (cash on hand now / projected low), get_debt_plan (payoff what-ifs, incl. "what if we pay an extra \$X/month"), and get_budget_history (per-category budget vs actual over time) — prefer them over assembling the answer yourself.
- For investing and home questions, prefer the dedicated tools over raw holdings: get_property (home value, equity, mortgage, refinance), get_allocation (asset mix vs target), get_fees (expense ratios / fee drag), get_dividends (dividend income + projection), get_security (one holding by ticker/name — price, position, return, dividends), and get_peer_comparison (spending vs the typical US household). Several carry honest-number caveats (fee coverage, lot reconciliation, peer bracket) — surface those caveats rather than dropping them.
- The account, custom-category, and goal names for THIS household are listed at the end of this prompt — use them to scope queries directly (e.g. pass `account` to search/aggregate) instead of calling get_accounts just to discover a name. If an answer leans on current balances or recent activity and the data might be behind, you can call get_data_freshness and caveat accordingly.
- Merchant/payee names in the data often include punctuation, abbreviations, or extra words the user will NOT type exactly — the bar "O'Aces Bar & Grill" for "OAces", "AMZN MKTP" for Amazon, "SQ *COFFEE" for a coffee shop. So when the user names a place/store/business, do NOT assume a plain search matches: call find_merchants with a short approximate term (it matches ignoring punctuation/spacing/case and tolerates small typos) to discover the real name(s) and visit counts, then answer from those, or pass the exact returned merchant name to search_transactions. The candidate list may contain a few near-misses — examine the names (and any context the user gave, like "my favorite bar") and use only the one(s) that genuinely match; if two are plausible, briefly ask or present the options. Only say "no transactions / none" AFTER find_merchants also comes back empty.
- Keep answers short and skimmable. Lead with the direct answer, then a brief supporting detail or two. Use a short bulleted list for multiple figures. No preamble like "Sure!".
- This data is already scoped to what the asking user is allowed to see; you don't need to worry about permissions.
- You are READ-ONLY: you cannot add, change, or delete anything (no creating budgets, paying bills, moving money). If asked to act, explain that you can only look things up and suggest where in the app to do it.
- If a question is not about this household's finances, answer briefly and helpfully but stay focused on the budgeting app's scope.
TXT;

    // Roster (Phase 2 #5): a compact household inventory appended to the CACHED system prefix, so most
    // scoped questions skip a get_accounts discovery round. Names only (nothing beyond what get_accounts
    // already exposes); VIS-scoped via $uid so a private/hidden account never leaks into the prompt.
    $lines = [];
    foreach (q_accounts($pdo, $uid) as $a) {
        $typ  = trim((string)($a['type'] ?? '') . (($a['subtype'] ?? '') !== '' ? '/' . $a['subtype'] : ''));
        $bits = array_filter([
            trim((string)($a['name'] ?? '')),
            $typ,
            trim((string)($a['institution_name'] ?? '')),
            ($a['mask'] ?? '') !== '' ? '••' . $a['mask'] : '',
        ], fn($s) => $s !== '');
        if ($bits) $lines[] = '- ' . implode(' · ', $bits);
    }
    $roster = "\n\nThis household's roster (use these exact names to scope tool calls; don't call get_accounts just to look one up):\nAccounts:\n"
            . ($lines ? implode("\n", $lines) : '- (no linked accounts yet)');

    $cats = [];
    foreach (q_custom_categories($pdo, $uid) as $c) {
        $lbl = trim((string)($c['label'] ?? ''));
        if ($lbl !== '') $cats[] = $lbl;
    }
    if ($cats) $roster .= "\nCustom categories: " . implode(', ', $cats);

    $goalNames = [];
    foreach (q_goals($pdo, $uid) as $g) {
        $nm = trim((string)($g['name'] ?? ''));
        if ($nm !== '') $goalNames[] = $nm;
    }
    if ($goalNames) $roster .= "\nSavings goals: " . implode(', ', $goalNames);

    return $system . $roster;
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
            // Read once, then validate — never re-access $in['group_by'] in a ternary true branch (an
            // absent key would warn + yield "" instead of the 'none' default; see get_debt_plan).
            $group = (string)($in['group_by'] ?? 'none');
            if (!in_array($group, ['none', 'category', 'merchant', 'month', 'account'], true)) $group = 'none';
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

        case 'get_spending_trend': {
            $months = $clampInt($in['months'] ?? null, 6, 1, 24);
            $tr = q_spending_trend($pdo, $uid, $months);
            $prettyCats = function (array $cats): array {
                $o = [];
                foreach ($cats as $code => $v) $o[pretty_cat((string)$code)] = round((float)$v, 2);
                return $o;
            };
            $byMonth = array_map(fn($m) => [
                'month'       => $m['label'],
                'total'       => round((float)$m['total'], 2),
                'by_category' => $prettyCats($m['cats']),
            ], $tr['months']);
            $changes = array_map(fn($d) => [
                'category'      => pretty_cat((string)$d['category']),
                'this_mtd'      => round((float)$d['this'], 2),
                'prior_3mo_avg' => round((float)$d['avg3'], 2),
                'last_year_mtd' => round((float)$d['lastyear'], 2),
            ], $tr['deltas']);
            return [
                'months'       => $months,
                'categories'   => array_map(fn($c) => pretty_cat((string)$c), $tr['cat_order']),
                'by_month'     => $byMonth,
                'changes_mtd'  => $changes,
                'note'         => '"Other" folds everything outside the top categories. changes_mtd compares this month-to-date vs the prior-3-month average and the same month last year (all capped to today\'s day-of-month for a fair comparison).',
            ];
        }

        case 'find_merchants': {
            $term  = trim((string)($in['q'] ?? ''));
            $limit = $clampInt($in['limit'] ?? null, 25, 1, 50);
            if ($term === '') {
                // No search term → top merchants by true spend over the window (Phase 2 #4). q_top_merchants
                // is spend-only (true-expense), so `received`/first/last dates aren't available here.
                $days = $clampInt($in['days'] ?? null, 365, 1, 1825);
                if (isset($in['from']) && trim((string)$in['from']) !== '') {
                    $d = (int)round((strtotime('today') - strtotime((string)$in['from'])) / 86400);
                    if ($d >= 1) $days = min(1825, $d);
                }
                $out = array_map(fn($r) => [
                    'merchant'     => $r['merchant'],
                    'transactions' => (int)$r['n'],
                    'spent'        => assistant_money($r['total']),
                    'received'     => null,
                    'first_date'   => null,
                    'last_date'    => null,
                ], q_top_merchants($pdo, $uid, $days, $limit));
                return ['query' => null, 'top_by_spend' => true, 'window_from' => date('Y-m-d', strtotime("-{$days} days")), 'merchants' => $out, 'count' => count($out)];
            }
            $opts = ['limit' => $limit];
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

        case 'get_cash': {
            $window = $clampInt($in['window'] ?? null, 90, 7, 1825);
            $hist   = q_cash_history($pdo, $uid, $window);
            $last   = $hist ? $hist[count($hist) - 1] : null;
            $first  = $hist ? $hist[0] : null;
            $series = array_map(fn($r) => ['date' => $r['snapshot_date'], 'balance' => round((float)$r['balance'], 2)], $hist);
            return [
                'window_days'  => $window,
                'current_cash' => $last ? round((float)$last['balance'], 2) : 0.0,
                'as_of'        => $last['snapshot_date'] ?? null,
                'window_start' => $first['snapshot_date'] ?? null,
                'change'       => ($last && $first) ? round((float)$last['balance'] - (float)$first['balance'], 2) : null,
                'points'       => count($series),
                // Cap the daily series — Claude has the current value + change; full history rarely needed.
                'history'      => count($series) > 60 ? array_slice($series, -60) : $series,
            ];
        }

        case 'get_cash_forecast': {
            $horizon  = $clampInt($in['days'] ?? null, 30, 30, 90);
            $accounts = q_accounts($pdo, $uid);
            $liab     = q_liabilities($pdo, $uid);
            $recur    = q_recurring($pdo, $uid);
            $avgSpend = q_avg_daily_spend($pdo, $uid, 90);   // trailing-90-day discretionary baseline
            $fc = forecast_build($accounts, $liab, $recur, $avgSpend, $horizon, new DateTimeImmutable('today'));
            return [
                'horizon_days'            => $fc['horizon'],
                'cash_today'              => $fc['start_balance'],
                'projected_end'          => $fc['end_balance'],
                'projected_low'          => $fc['min_balance'],
                'low_date'               => $fc['min_date'],
                'goes_negative'          => $fc['goes_negative'],
                'everyday_spend_per_day' => $fc['discretionary_daily'],
                'has_projected_income'   => $fc['has_income'],
                'income_streams_detected'=> $fc['income_count'],
            ];
        }

        case 'get_safe_to_spend': {
            $recur = q_recurring($pdo, $uid);
            $liab  = q_liabilities($pdo, $uid);
            $plan  = q_spending_plan($pdo);
            $today = new DateTimeImmutable('today');
            $monthFirst = $today->modify('first day of this month')->format('Y-m-d');
            $tomorrow   = $today->add(new DateInterval('P1D'))->format('Y-m-d');   // half-open [first, tomorrow)
            $spentMtd = q_true_expense_total($pdo, $uid, $monthFirst, $tomorrow);
            $sp = safe_to_spend_build($recur, $liab, (float)$plan['monthly_savings_target'], $spentMtd, $today);
            return [
                'month'                => $sp['month_label'],
                'safe_to_spend'        => $sp['safe'],
                'free_to_spend'        => $sp['plan'],
                'expected_income'      => $sp['income'],
                'committed_bills'      => $sp['bills'],
                'savings_target'       => $sp['savings_target'],
                'spent_so_far'         => $sp['spent'],
                'days_left'            => $sp['days_left'],
                'per_day_left'         => $sp['daily_left'],
                'over_plan'            => $sp['over'],
                'has_projected_income' => $sp['has_income'],
            ];
        }

        case 'get_debt_plan': {
            // Resolve WITHOUT re-reading $in['strategy'] in the true branch: `$in[k] ?? d` returns the
            // default for an absent key, but `(string)$in[k]` then re-accesses it → "Undefined array key"
            // warning + "" (not the default). Read once into a var, then validate.
            $strategy = (string)($in['strategy'] ?? 'avalanche');
            if (!in_array($strategy, ['avalanche', 'snowball', 'minimums'], true)) $strategy = 'avalanche';
            $extra = (isset($in['extra_monthly']) && is_numeric($in['extra_monthly']))
                ? max(0.0, min(10000000.0, (float)$in['extra_monthly'])) : 0.0;
            $includeMortgage = !empty($in['include_mortgage']);
            $plan = build_debt_plan(q_debts($pdo, $uid), $extra, $includeMortgage);
            if (!$plan['debts']) {
                return [
                    'debts' => [], 'debt_count' => 0, 'total_debt' => 0.0,
                    'has_mortgage' => $plan['has_mortgage'], 'include_mortgage' => $includeMortgage,
                    'note' => ($plan['has_mortgage'] && !$includeMortgage)
                        ? 'The only debt is the mortgage, excluded by default — set include_mortgage=true to include it.'
                        : 'No credit-card or loan debt is showing.',
                ];
            }
            $sc  = $plan['scenarios'];
            $key = $strategy === 'minimums' ? 'baseline' : $strategy;
            $monthsToLabel = fn(int $m): string =>
                (new DateTimeImmutable('first day of this month'))->modify("+{$m} months")->format('M Y');
            $scenarioOut = fn(array $s): array => [
                'months_to_debt_free'        => $s['infeasible'] ? null : $s['months'],
                'debt_free_by'               => $s['infeasible'] ? null : $monthsToLabel($s['months']),
                'total_interest'             => $s['total_interest'],
                'interest_saved_vs_minimums' => $s['interest_saved'] ?? null,
                'months_saved_vs_minimums'   => $s['months_saved'] ?? null,
                'infeasible'                 => $s['infeasible'],
            ];
            $debts = array_map(fn($d) => [
                'name'        => $d['name'],
                'balance'     => round($d['balance'], 2),
                'apr_pct'     => $d['apr_unknown'] ? null : round($d['apr'], 2),
                'min_payment' => round($d['min_payment'], 2),
                'is_mortgage' => $d['is_mortgage'],
            ], $plan['debts']);
            return [
                'strategy'          => $strategy,
                'extra_monthly'     => $extra,
                'include_mortgage'  => $includeMortgage,
                'total_debt'        => round($plan['total'], 2),
                'debt_count'        => count($debts),
                'debts'             => $debts,
                'result'            => $scenarioOut($sc[$key]),
                'compare'           => [
                    'avalanche' => $scenarioOut($sc['avalanche']),
                    'snowball'  => $scenarioOut($sc['snowball']),
                    'minimums'  => $scenarioOut($sc['baseline']),
                ],
                'any_apr_unknown'   => $plan['any_apr_unknown'],
                'any_min_estimated' => $plan['any_min_estimated'],
            ];
        }

        case 'get_data_freshness': {
            $banks = array_map(function ($c) {
                $age = $c['age_s'] !== null ? (int)$c['age_s'] : null;   // TZ-safe: server-clock DIFF, not re-parsed
                return [
                    'institution'      => $c['institution_name'],
                    'status'           => $c['status'],
                    'error_code'       => ($c['error_code'] ?? '') !== '' ? $c['error_code'] : null,
                    'last_synced'      => $c['last_synced_at'],
                    'synced_age_hours' => $age !== null ? round($age / 3600, 1) : null,
                ];
            }, q_connection_status($pdo));
            return [
                'last_synced_any'      => q_last_synced($pdo),
                'any_connection_error' => (bool)array_filter($banks, fn($b) => $b['status'] === 'error'),
                'banks'                => $banks,
                'count'                => count($banks),
            ];
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

        case 'get_property': {
            $v = build_property_view($pdo, $uid);
            if (!$v) return ['has_property' => false, 'note' => 'No home value or mortgage is configured for this household.'];
            $m = $v['mortgage'] ?? null;
            $d = $v['derived'] ?? [];
            $rf = $v['refi'] ?? null;
            $p  = $v['property'] ?? null;
            return [
                'has_property' => true,
                'address'      => $v['address'] ?: null,
                'value'        => $v['value'] ? [
                    'current' => assistant_money($v['value']['current']),
                    'as_of'   => $v['value']['as_of'],
                ] : null,
                'equity'          => isset($d['equity']) ? assistant_money($d['equity']) : null,
                'ltv_pct'         => isset($d['ltv']) && $d['ltv'] !== null ? round((float)$d['ltv'] * 100, 1) : null,
                'appreciation'    => isset($d['appreciation']) ? assistant_money($d['appreciation']) : null,
                'appreciation_pct'=> isset($d['appreciation_pct']) && $d['appreciation_pct'] !== null ? round((float)$d['appreciation_pct'] * 100, 1) : null,
                'mortgage'     => $m ? [
                    'balance'          => assistant_money($m['balance']),
                    'rate_pct'         => $m['rate'],
                    'monthly_pi'       => assistant_money($m['payment_pi']),
                    'next_due'         => $m['next_due_date'],
                    'payoff_date'      => $m['payoff_date'],
                    'pct_paid_off'     => $m['pct_paid_off'] !== null ? round((float)$m['pct_paid_off'] * 100, 1) : null,
                    'ytd_interest'     => assistant_money($m['ytd_interest']),
                    'ytd_principal'    => assistant_money($m['ytd_principal']),
                    'escrow_balance'   => assistant_money($m['escrow']),
                    'interest_to_date' => assistant_money($m['interest_to_date']),
                ] : null,
                'refi'         => $rf ? [
                    'your_rate_pct'   => $rf['your_rate'],
                    'market_rate_pct' => $rf['market_rate'],
                    'market_as_of'    => $rf['as_of'],
                    'beneficial'      => $rf['beneficial'],
                    'monthly_savings' => assistant_money($rf['monthly_savings']),
                    'annual_savings'  => assistant_money($rf['annual_savings']),
                    'lifetime_interest_savings' => assistant_money($rf['lifetime_interest_savings']),
                ] : null,
                'facts'        => $p ? array_filter([
                    'purchase_price' => assistant_money($p['purchase_price']),
                    'purchase_date'  => $p['purchase_date'],
                    'beds'           => $p['beds'],
                    'baths'          => $p['baths'],
                    'sqft'           => $p['sqft'],
                    'year_built'     => $p['year_built'],
                ], fn($x) => $x !== null) : null,
            ];
        }

        case 'get_allocation': {
            $av = build_allocation_view(q_holdings($pdo, $uid), q_allocation_targets($pdo), q_security_asset_classes($pdo));
            if ($av['total'] <= 0) return ['has_holdings' => false, 'note' => 'No investment or retirement holdings to allocate yet.'];
            $classes = array_map(fn($c) => [
                'class'      => $c['label'],
                'value'      => assistant_money($c['actual_val']),
                'actual_pct' => round((float)$c['actual_pct'], 1),
                'target_pct' => $c['target_pct'] !== null ? round((float)$c['target_pct'], 1) : null,
                'drift'      => $c['drift_val'] !== null ? assistant_money($c['drift_val']) : null,
            ], $av['classes']);
            return [
                'has_holdings' => true,
                'total'        => assistant_money($av['total']),
                'has_targets'  => $av['has_targets'],
                'target_sum_pct' => $av['has_targets'] ? round((float)$av['target_sum'], 1) : null,
                'classes'      => $classes,
                'trim'         => array_map(fn($s) => ['class' => $s['label'], 'amount' => assistant_money($s['amount'])], $av['sells']),
                'add'          => array_map(fn($b) => ['class' => $b['label'], 'amount' => assistant_money($b['amount'])], $av['buys']),
                'note'         => 'Holdings-based: uninvested brokerage cash and accounts with no per-holding breakdown are not counted. Taxable + retirement combined.',
            ];
        }

        case 'get_fees': {
            $fv = build_fees_view(q_holdings($pdo, $uid), q_security_expense_ratios($pdo));
            if ($fv['total'] <= 0) return ['has_holdings' => false, 'note' => 'No investment or retirement holdings to analyze yet.'];
            return [
                'has_holdings'       => true,
                'total_value'        => assistant_money($fv['total']),
                'weighted_avg_pct'   => $fv['weighted_avg'] !== null ? round((float)$fv['weighted_avg'], 3) : null,
                'annual_fee'         => assistant_money($fv['annual_fee']),
                'coverage_pct'       => round((float)$fv['coverage_pct'], 0),
                'uncovered_count'    => (int)$fv['uncovered_count'],
                'uncovered_value'    => assistant_money($fv['uncovered_value']),
                'projection_total'   => assistant_money($fv['projection_total']),
                'projection_years'   => (int)$fv['projection_years'],
                'biggest_drags'      => array_map(fn($b) => [
                    'holding'    => $b['label'],
                    'annual_fee' => assistant_money($b['annual_fee']),
                    'ratio_pct'  => $b['ratio'] !== null ? round((float)$b['ratio'], 3) : null,
                    'value'      => assistant_money($b['value']),
                ], $fv['biggest']),
                'note'               => $fv['uncovered_count'] > 0
                    ? 'Coverage is partial — ' . (int)$fv['uncovered_count'] . ' holding(s) have no expense ratio entered, so the annual fee is a FLOOR (the real figure is higher). Ratios are entered by hand, not from a live feed.'
                    : 'Every holding has a ratio entered. Ratios are entered by hand, not from a live feed.',
            ];
        }

        case 'get_dividends': {
            // Whole-portfolio scope: every account the viewer holds a position in (taxable + retirement).
            $holds = q_holdings($pdo, $uid);
            $acctIds = array_values(array_unique(array_filter(array_map(fn($h) => $h['account_id'] ?? null, $holds))));
            // Projected forward annual income = Σ current qty × per-share annual dividend.
            $divs = q_security_dividends($pdo, array_map(fn($h) => $h['security_id'] ?? null, $holds));
            $projTotal = 0.0; $payers = []; $upcoming = [];
            $horizon = date('Y-m-d', strtotime('+90 days'));
            foreach ($holds as $h) {
                $sid = $h['security_id'] ?? null;
                $qty = $h['quantity'] !== null ? (float)$h['quantity'] : 0.0;
                if ($sid === null || $qty <= 0 || !isset($divs[$sid])) continue;
                $d = $divs[$sid];
                $ticker = ($h['ticker_symbol'] ?: ($h['security_name'] ?: '—'));
                if ($d['annual_ps'] !== null) {
                    $proj = $qty * $d['annual_ps'];
                    $projTotal += $proj;
                    $payers[$ticker] = ($payers[$ticker] ?? 0.0) + $proj;
                }
                foreach ($d['upcoming'] as $u) {
                    if ($u['ex_date'] > $horizon) continue;
                    $upcoming[] = ['ex_date' => $u['ex_date'], 'ticker' => $ticker,
                                   'per_share' => assistant_money($u['cash_amount']),
                                   'your_amount' => assistant_money($qty * $u['cash_amount'])];
                }
            }
            arsort($payers);
            $topPayers = [];
            foreach (array_slice($payers, 0, 10, true) as $tk => $amt) {
                $topPayers[] = ['holding' => $tk, 'projected_annual' => assistant_money($amt)];
            }
            usort($upcoming, fn($a, $b) => strcmp((string)$a['ex_date'], (string)$b['ex_date']));
            // Actual income received: dividends+interest activity (stored − = money in → flip to +).
            $ytdStart = date('Y') . '-01-01';
            $ttmStart = date('Y-m-d', strtotime('-1 year'));
            $ytd = 0.0; $ttm = 0.0;
            if ($acctIds) {
                foreach (q_investment_activity($pdo, $uid, 'income', $acctIds, 100000, 0) as $r) {
                    $inflow = -(float)$r['amount'];        // dividends/interest are money-in (negative stored)
                    if ($inflow <= 0) continue;
                    $td = (string)$r['tdate'];
                    if ($td >= $ytdStart) $ytd += $inflow;
                    if ($td >= $ttmStart) $ttm += $inflow;
                }
            }
            return [
                'income_ytd'             => assistant_money($ytd),
                'income_ttm'             => assistant_money($ttm),
                'projected_annual'       => assistant_money($projTotal),
                'top_payers'             => $topPayers,
                'upcoming_ex_dates'      => array_slice($upcoming, 0, 15),
                'note'                   => 'YTD/TTM are actual dividend + interest received (whole portfolio). Projected annual is an estimate from current holdings (latest declared rate × payout frequency) and assumes the rate/cadence continues.',
            ];
        }

        case 'get_security': {
            // Resolve the ticker/name to a security_id via the viewer's OWN holdings (VIS-scoped) —
            // so we never look up (or hint at the existence of) a security the household doesn't hold.
            $ticker = trim((string)($in['ticker'] ?? ''));
            $nameQ  = trim((string)($in['name'] ?? ''));
            $holds  = q_holdings($pdo, $uid);
            $sid = null;
            if ($ticker !== '') {
                foreach ($holds as $h) {
                    if (strcasecmp((string)($h['ticker_symbol'] ?? ''), $ticker) === 0) { $sid = $h['security_id']; break; }
                }
            }
            if ($sid === null && ($nameQ !== '' || $ticker !== '')) {
                $needle = strtolower($nameQ !== '' ? $nameQ : $ticker);
                foreach ($holds as $h) {
                    $nm = strtolower((string)($h['security_name'] ?? ''));
                    if ($nm !== '' && strpos($nm, $needle) !== false) { $sid = $h['security_id']; break; }
                }
            }
            if ($sid === null || !q_security_has_access($pdo, $uid, (string)$sid)) {
                return ['found' => false, 'note' => 'The household does not hold a security matching that ticker/name.'];
            }
            $sid = (string)$sid;
            $sec       = q_security($pdo, $sid);
            $holdings  = q_security_holdings($pdo, $uid, $sid);
            $c         = q_price_changes($pdo, [$sid])[$sid] ?? null;
            $divData   = q_security_dividends($pdo, [$sid])[$sid] ?? null;
            // Position aggregate (mirrors security.php).
            $totalQty = 0.0; $mvAll = 0.0; $costSum = 0.0; $valCost = 0.0; $haveCost = 0;
            foreach ($holdings as $h) {
                $qty = $h['quantity'] !== null ? (float)$h['quantity'] : 0.0;
                $totalQty += $qty;
                $mv = $h['institution_value'] !== null ? (float)$h['institution_value'] : 0.0;
                $mvAll += $mv;
                if ($h['cost_basis'] !== null) { $haveCost++; $costSum += (float)$h['cost_basis']; $valCost += $mv; }
            }
            $gain    = $haveCost ? $valCost - $costSum : null;
            $gainPct = ($haveCost && $costSum != 0.0) ? $gain / abs($costSum) * 100 : null;
            $curPrice  = $c['current'] ?? ($sec && $sec['close_price'] !== null ? (float)$sec['close_price'] : null);
            // Money-weighted IRR vs a default SPY benchmark (only when lots reconcile).
            $retLots = q_security_lots($pdo, $uid, $sid, 100000, 0);
            $bench = null;
            if ($retLots) {
                foreach (q_benchmark_candidates($pdo) as $bc) {
                    if (strtoupper((string)$bc['ticker_symbol']) === 'SPY') {
                        [$bAsOf, $bLatest] = ret_bench_lookup(q_security_prices($pdo, $bc['security_id'], 4000));
                        if ($bLatest > 0) $bench = ['asof' => $bAsOf, 'latest' => $bLatest, 'ticker' => 'SPY', 'name' => $bc['name']];
                        break;
                    }
                }
            }
            $secRet = ret_position($retLots, $totalQty, $mvAll, $bench, date('Y-m-d'));
            $divAnnual = ($divData && $divData['annual_ps'] !== null && $totalQty > 0) ? $totalQty * $divData['annual_ps'] : null;
            return [
                'found'         => true,
                'ticker'        => $sec['ticker_symbol'] ?? null,
                'name'          => $sec['name'] ?? null,
                'price'         => assistant_money($curPrice),
                'price_as_of'   => $c['date'] ?? ($sec['close_price_date'] ?? null),
                'change_1d_pct' => ($c && $c['current'] !== null && ($c['d1'] ?? null) !== null && (float)$c['d1'] != 0.0)
                                   ? round(($c['current'] - $c['d1']) / abs($c['d1']) * 100, 2) : null,
                'change_30d_pct'=> ($c && $c['current'] !== null && ($c['d30'] ?? null) !== null && (float)$c['d30'] != 0.0)
                                   ? round(($c['current'] - $c['d30']) / abs($c['d30']) * 100, 2) : null,
                'change_1y_pct' => ($c && $c['current'] !== null && ($c['d365'] ?? null) !== null && (float)$c['d365'] != 0.0)
                                   ? round(($c['current'] - $c['d365']) / abs($c['d365']) * 100, 2) : null,
                'shares'        => round($totalQty, 4),
                'market_value'  => assistant_money($mvAll),
                'cost_basis'    => $haveCost ? assistant_money($costSum) : null,
                'gain_loss'     => $gain !== null ? assistant_money($gain) : null,
                'gain_loss_pct' => $gainPct !== null ? round($gainPct, 1) : null,
                'annualized_return_pct' => $secRet['irr'] !== null ? round($secRet['irr'] * 100, 1) : null,
                'benchmark'     => ($secRet['irr'] !== null && $secRet['bench_irr'] !== null)
                                   ? ['ticker' => 'SPY', 'annualized_pct' => round($secRet['bench_irr'] * 100, 1)] : null,
                'projected_dividend_annual' => assistant_money($divAnnual),
                'accounts_held' => count($holdings),
                'note'          => $secRet['irr'] === null && $retLots
                    ? 'Annualized return omitted — recorded buy/sell lots do not reconcile with the current share count.'
                    : null,
            ];
        }

        case 'get_peer_comparison': {
            $bracket = peer_valid_bracket($in['bracket'] ?? null);
            // Last 12 FULL calendar months (exclude the partial current month), app-TZ (S24 trap).
            $monthStart  = new DateTimeImmutable('first day of this month');
            $windowStart = $monthStart->sub(new DateInterval('P12M'));
            $spend = q_peer_category_spend($pdo, $uid, $windowStart->format('Y-m-d'), $monthStart->format('Y-m-d'));
            $view  = build_peer_view($bracket, $spend);
            if (!$view['has_data']) return ['has_data' => false, 'note' => 'Not enough categorized spending history yet to compare.'];
            $b = $view['bracket'];
            $rows = array_map(fn($r) => [
                'category'       => $r['label'],
                'you_annual'     => $r['has_spend'] ? assistant_money($r['you_annual']) : null,
                'typical_annual' => assistant_money($r['typical_annual']),
                'diff'           => $r['has_spend'] ? assistant_money($r['diff']) : null,
                'pct_vs_typical' => ($r['has_spend'] && $r['pct'] !== null) ? round((float)$r['pct'], 0) : null,
                'has_tracked_spend' => $r['has_spend'],
            ], $view['rows']);
            return [
                'has_data'         => true,
                'bracket'          => $b['label'] . ' (income before taxes)',
                'months_observed'  => (int)$view['months_observed'],
                'low_confidence'   => (bool)$view['low_conf'],
                'you_total'        => assistant_money($view['sum_you']),
                'typical_total'    => assistant_money($view['sum_typical']),
                'overall_diff'     => assistant_money($view['overall_diff']),
                'overall_pct'      => $view['overall_pct'] !== null ? round((float)$view['overall_pct'], 0) : null,
                'compared_categories' => (int)$view['compared_count'],
                'categories'       => $rows,
                'source'           => $view['source'],
                'note'             => 'Only categories with a clean BLS match AND tracked spend are summed in the totals. Brackets are income before taxes. Figures cover ~half of a typical household budget (housing/insurance/education not benchmarked).',
            ];
        }

        case 'get_budget_history': {
            $months = $clampInt($in['months'] ?? null, 6, 1, 12);
            $hist = q_budget_history($pdo, $months);
            if (!$hist) return ['budgets' => [], 'count' => 0, 'note' => 'No budgets are set, so there is no budget history.'];
            $out = [];
            foreach ($hist as $cat => $h) {
                $out[] = [
                    'category'      => pretty_cat((string)$cat),
                    'limit'         => assistant_money($h['limit']),
                    'this_mtd'      => assistant_money($h['this']),
                    'prior_3mo_avg' => assistant_money($h['avg3']),
                    'by_month'      => array_map(fn($m) => ['month' => $m['label'], 'spent' => assistant_money($m['spent'])], $h['months']),
                ];
            }
            return ['months' => $months, 'budgets' => $out, 'count' => count($out),
                    'note' => 'this_mtd vs prior_3mo_avg is month-to-date (capped to today\'s day-of-month) for a fair comparison. Household-wide.'];
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
 * @param ?callable $onProgress optional per-round progress hook (the SSE status-line UX): called
 *                        once each time the model requests tools, as $onProgress(string[] $toolNames,
 *                        int $round), BEFORE the tools run — so the UI can show "Searching
 *                        transactions…". Best-effort: an exception from it is swallowed, and passing
 *                        null (the default, e.g. the non-streaming endpoint) is a total no-op.
 */
function assistant_respond(PDO $pdo, int $uid, array $messages, array $cfg, ?callable $onProgress = null): array
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

        // Progress hook (SSE status line): announce the tools this round BEFORE running them,
        // so the UI shows "Searching transactions…" while the dispatch + next round happen.
        if ($onProgress !== null) {
            $roundTools = [];
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'tool_use') $roundTools[] = (string)($b['name'] ?? '');
            }
            if ($roundTools) {
                try { $onProgress($roundTools, $round); } catch (Throwable $e) { /* progress is best-effort */ }
            }
        }

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
