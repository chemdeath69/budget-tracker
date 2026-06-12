<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored and must NEVER be committed.
 *
 * DB: this host runs MySQL 5 AND MySQL 8. Our DB is on MySQL 8 — connect via the
 * unix socket /tmp/mysql8.sock (preferred) or TCP 127.0.0.1:3308. The default
 * localhost/3306 is the WRONG server. Note the MySQL username is the SHORT name
 * (e.g. "budget"), not the prefixed database name ("cpuser_budget").
 */
return [
    'db' => [
        'socket'  => '/tmp/mysql8.sock',   // preferred on this host (MySQL 8)
        'host'    => '127.0.0.1',          // fallback if socket unset
        'port'    => 3308,
        'name'    => 'cpuser_budget',
        'user'    => 'budget',             // SHORT name, not cpuser_budget
        'pass'    => 'CHANGE_ME_dbpass',
        'charset' => 'utf8mb4',
    ],

    'google' => [
        'client_id'     => 'CHANGE_ME.apps.googleusercontent.com',
        'client_secret' => 'CHANGE_ME',
        'redirect_uri'  => 'https://budget.example.com/oauth-callback.php',
    ],

    'plaid' => [
        'env'         => 'production',      // 'production' | 'sandbox'
        'client_id'   => 'CHANGE_ME',
        'secret'      => 'CHANGE_ME_production_secret',
        'webhook_url' => 'https://budget.example.com/webhook.php',
        // Initial history pull (days) on first sync of each Item.
        'days_requested' => 730,
    ],

    // Only these Google accounts may sign in. Everyone else is rejected.
    'allowed_emails' => [
        'you@example.com',
        'partner@example.com',
    ],

    // base64 of 32 random bytes — encrypts Plaid access_tokens at rest.
    // Generate with: php -r "echo base64_encode(random_bytes(32));"
    // LOSING THIS = must re-link all banks.
    'encryption_key' => 'CHANGE_ME_base64_32_bytes',

    // Random long string. Generate: php -r "echo bin2hex(random_bytes(32));"
    'session_secret' => 'CHANGE_ME_random_64_hex_chars',

    // Email alerts. NB (TODO #14): the live on/off flags + large-tx threshold now
    // live in the `alert_settings` DB table (one household-shared row, edited on
    // settings.php). The values below are TRANSPORT + FALLBACK only — `recipients`
    // and `from` are used as-is by send_alert(); `large_tx_threshold` is the default
    // used when alert_settings.large_tx_threshold is NULL.
    'alerts' => [
        'recipients'        => ['you@example.com'],
        'large_tx_threshold' => 200.0,    // USD fallback; live value is in alert_settings
        'from'              => 'budget@example.com',
    ],

    // Manual (non-Plaid) accounts. Uploaded source documents (e.g. Webull PDFs)
    // are kept on disk for audit/re-parse. Ideally OUTSIDE the web root — but on
    // this host the home dir is root-owned and the only user-writable area is the
    // docroot, so we keep them under it and hard-deny web access three ways:
    // storage/.htaccess (Require all denied) + a root .htaccess rule + a deny file
    // written at runtime by lib/manual/ingest.php.
    'storage' => [
        'manual_dir' => '/home/cpuser/www/budget/storage/manual',
    ],
    // poppler's pdftotext, used to read uploaded PDFs (host has it at /usr/bin).
    'pdftotext' => '/usr/bin/pdftotext',

    // Twelve Data (free tier) — daily security-price refresh + history backfill
    // that power the Investments change-icons and price charts. Create a free key
    // at https://twelvedata.com/account/api-keys. LEAVE EMPTY to disable the price
    // feed entirely (holdings still render; change-icons/charts just stay blank).
    'twelvedata' => [
        'api_key' => '',   // paste your Twelve Data key here (empty = disabled)
    ],

    // FRED — Federal Reserve Economic Data (St. Louis Fed). FREE, generous rate limit,
    // NO per-request billing. Powers the Economic page + inline insights: real
    // (inflation-adjusted) net worth (CPI), mortgage-rate-vs-market / refi (30-yr avg),
    // and savings-rate context (Treasury / Fed-funds). Get a free key at
    // https://fredaccount.stlouisfed.org/apikeys. LEAVE EMPTY to disable the feature
    // (the Economic page shows an empty-state; the inline insights are omitted).
    'fred' => [
        'api_key' => '',   // paste your FRED key here (empty = disabled)
    ],

    // Polygon.io (free tier — 5 requests/min, NO per-request billing) — declared/
    // upcoming cash-dividend feed (ex-date, per-share amount, payout frequency) for
    // the Investments "Dividend income & calendar" section (projected annual income +
    // upcoming ex-dividend dates from current holdings). Refreshed at most ~weekly per
    // security by cron/sync.php (staleness-gated in lib/dividends.php so the 5/min free
    // limit is never approached). Get a free key at https://polygon.io/dashboard/keys.
    // LEAVE EMPTY to disable (holdings still render; the dividend section shows an
    // empty-state). NB: empty string = disabled; a non-empty placeholder is treated as
    // a real key (the cron will call Polygon), so leave it '' until you have a real key.
    'polygon' => [
        'api_key' => '',   // paste your Polygon.io key here (empty = disabled)
    ],

    // RentCast (free tier — 50 requests/month, with a PER-REQUEST OVERAGE FEE above
    // that) — home-value (AVM) feed for the home-value-vs-mortgage card. The hard
    // monthly cap is enforced in lib/home_value.php (the api_usage counter reserves
    // a slot before every call and refuses past 50), so overage charges can't occur.
    // Get a free key at https://app.rentcast.io/. LEAVE EMPTY to disable the feed.
    'rentcast' => [
        'api_key' => '',   // paste your RentCast key here (empty = disabled)
    ],

    // Anthropic (Claude) API — powers two features:
    //   1. Vision OCR that reads a manual 401(k) statement photo and pre-fills the
    //      statement form (retirement_statement.php "Import from photo").
    //   2. The natural-language AI assistant (assistant.php) — a tool-use loop that lets
    //      Claude answer questions about your finances by calling our read-only query
    //      helpers (it never touches the DB directly).
    // Pay-as-you-go prepaid credits; NOT covered by a Claude Code / Claude.ai subscription.
    // Get a key at https://console.anthropic.com (API keys) and load $5 of credits
    // (Billing). LEAVE EMPTY to disable BOTH features (the importer + the assistant hide).
    'anthropic' => [
        'api_key'         => '',   // paste your Anthropic API key (sk-ant-…) here (empty = disabled)
        'model'           => 'claude-sonnet-4-6',  // vision model for the OCR (Sonnet — most accurate on dense statement tables)
        'assistant_model' => '',   // optional override for the chat assistant; empty = use `model` above
    ],

    // The property to value with RentCast (refreshed ~monthly by cron/sync.php) and
    // show against the linked mortgage balance on the dashboard. Use the format
    // "Street, City, State, Zip". LEAVE EMPTY to disable the home-value feature.
    'home' => [
        'address' => '',   // e.g. '123 Main St, Springfield, IL, 62704'
    ],
];
