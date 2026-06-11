-- budget-tracker — MySQL schema (first cut)
-- Derived from docs/spec.md + docs/requirements.md. Apply via phpMyAdmin or
-- `mysql -u <user> -p <dbname> < schema.sql`. MySQL 8 / InnoDB / utf8mb4.
-- Money stored as DECIMAL (USD only). Plaid amount sign: + = money OUT, - = money IN.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- users — the 2 allowlisted Google accounts
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(255) NOT NULL,
  name          VARCHAR(255) NULL,
  google_sub    VARCHAR(255) NULL,                 -- Google subject id from id_token
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- items — one feed = one Plaid Item (bank login) OR one manual account source.
-- For source='plaid' the access_token is encrypted at rest; for source='manual'
-- there is no token (data arrives via uploaded documents, e.g. Webull PDFs).
-- ---------------------------------------------------------------------------
CREATE TABLE items (
  item_id              VARCHAR(64)  NOT NULL,      -- Plaid item_id, or synthetic mnl_… for manual
  user_id              INT UNSIGNED NOT NULL,      -- owner (who linked/created it)
  source               ENUM('plaid','manual') NOT NULL DEFAULT 'plaid', -- feed type
  manual_type          VARCHAR(32)  NULL,          -- when source='manual': e.g. 'webull'
  institution_id       VARCHAR(64)  NULL,
  institution_name     VARCHAR(255) NULL,
  access_token_enc     VARBINARY(512) NULL,        -- sodium secretbox; NULL for manual items
  transactions_cursor  TEXT NULL,                  -- /transactions/sync next_cursor
  status               VARCHAR(32) NOT NULL DEFAULT 'active', -- active | error | removed
  error_code           VARCHAR(64) NULL,           -- e.g. ITEM_LOGIN_REQUIRED
  consent_expiration   DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_synced_at       DATETIME NULL,
  PRIMARY KEY (item_id),
  KEY idx_items_user (user_id),
  CONSTRAINT fk_items_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- accounts — bank/credit/loan/investment accounts within an Item
-- ---------------------------------------------------------------------------
CREATE TABLE accounts (
  account_id            VARCHAR(64) NOT NULL,       -- Plaid account_id
  item_id               VARCHAR(64) NOT NULL,
  name                  VARCHAR(255) NULL,
  display_name          VARCHAR(255) NULL,          -- owner-set rename override; shown everywhere when set, never touched by Plaid sync (migration 009)
  official_name         VARCHAR(255) NULL,
  mask                  VARCHAR(16)  NULL,
  type                  VARCHAR(32)  NULL,          -- depository|credit|loan|investment
  subtype               VARCHAR(48)  NULL,
  retirement_flag       TINYINT NULL,              -- NULL=auto-classify by subtype/manual_type; 1=force retirement; 0=force not. See migration 007 + is_retirement_account().
  statement_cadence     ENUM('monthly','quarterly','annually','off') NULL,  -- manual accts: expected statement frequency for the dashboard overdue warning; NULL=auto (401k→quarterly, other manual→monthly). Migration 010 + statement_cadence_effective().
  balance_available     DECIMAL(15,2) NULL,
  balance_current       DECIMAL(15,2) NULL,
  balance_limit         DECIMAL(15,2) NULL,
  iso_currency_code     VARCHAR(8)  NULL DEFAULT 'USD',
  visibility            ENUM('shared','private','hidden') NOT NULL DEFAULT 'shared',  -- hidden = registered nowhere but the owner's settings page (migration 008)
  last_updated_datetime DATETIME NULL,             -- Plaid balances.last_updated_datetime
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (account_id),
  KEY idx_accounts_item (item_id),
  CONSTRAINT fk_accounts_item FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- transactions — from /transactions/sync (idempotent upsert on transaction_id)
-- ---------------------------------------------------------------------------
CREATE TABLE transactions (
  transaction_id          VARCHAR(64) NOT NULL,    -- Plaid transaction_id (stable)
  account_id              VARCHAR(64) NOT NULL,
  amount                  DECIMAL(15,2) NOT NULL,  -- + out / - in
  iso_currency_code       VARCHAR(8)  NULL DEFAULT 'USD',
  date                    DATE NOT NULL,
  authorized_date         DATE NULL,
  datetime                DATETIME NULL,
  merchant_name           VARCHAR(255) NULL,       -- enriched; prefer over name
  name                    VARCHAR(512) NULL,       -- deprecated by Plaid but kept
  merchant_entity_id      VARCHAR(64) NULL,
  logo_url                VARCHAR(512) NULL,
  pending                 TINYINT(1) NOT NULL DEFAULT 0,
  pending_transaction_id  VARCHAR(64) NULL,
  pfc_primary             VARCHAR(64) NULL,        -- personal_finance_category.primary
  pfc_detailed            VARCHAR(96) NULL,        -- personal_finance_category.detailed
  category_override       VARCHAR(96) NULL,        -- manual; survives re-sync
  note                    VARCHAR(500) NULL,       -- free-text user note (#8); survives re-sync (not in sync UPSERT)
  payment_channel         VARCHAR(32) NULL,
  ext_source              VARCHAR(16) NULL,        -- manual feed origin (e.g. 'webull'); NULL = Plaid
  ext_period              VARCHAR(16) NULL,        -- doc bucket key (e.g. '2026-05'); scopes re-ingest
  large_tx_alerted        TINYINT(1) NOT NULL DEFAULT 0, -- de-dupe large-tx email
  raw                     JSON NULL,               -- optional full payload
  imported_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_id),
  KEY idx_tx_account (account_id),
  KEY idx_tx_date (date),
  KEY idx_tx_pfc (pfc_primary),
  KEY idx_tx_ext (account_id, ext_source, ext_period),
  CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Upsert from sync: INSERT ... ON DUPLICATE KEY UPDATE everything EXCEPT
-- category_override, note and large_tx_alerted (preserve those). 'removed' => DELETE by id.
-- Manual rows carry ext_source/ext_period so a re-uploaded document can replace
-- exactly its own bucket. Spending/budget queries exclude rows where ext_source
-- IS NOT NULL (brokerage trades/cash moves are not household "spending").

-- ---------------------------------------------------------------------------
-- liabilities — credit/loan/mortgage detail (Plaid /liabilities/get)
-- ---------------------------------------------------------------------------
CREATE TABLE liabilities (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id               VARCHAR(64) NOT NULL,
  liability_type           VARCHAR(16) NOT NULL,   -- credit | student | mortgage
  apr_percentage           DECIMAL(7,3) NULL,
  last_payment_amount      DECIMAL(15,2) NULL,
  last_payment_date        DATE NULL,
  next_payment_due_date    DATE NULL,
  minimum_payment_amount   DECIMAL(15,2) NULL,
  outstanding_balance      DECIMAL(15,2) NULL,
  origination_principal    DECIMAL(15,2) NULL,
  raw                      JSON NULL,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_liab_account_type (account_id, liability_type),
  CONSTRAINT fk_liab_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- holdings + securities — investments (/investments/holdings/get)
-- ---------------------------------------------------------------------------
CREATE TABLE securities (
  security_id      VARCHAR(64) NOT NULL,
  ticker_symbol    VARCHAR(32) NULL,
  name             VARCHAR(255) NULL,
  type             VARCHAR(48) NULL,
  close_price      DECIMAL(18,4) NULL,
  close_price_date DATE NULL,
  iso_currency_code VARCHAR(8) NULL DEFAULT 'USD',
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (security_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE holdings (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id          VARCHAR(64) NOT NULL,
  security_id         VARCHAR(64) NOT NULL,
  quantity            DECIMAL(20,6) NULL,
  cost_basis          DECIMAL(18,4) NULL,
  institution_price   DECIMAL(18,4) NULL,
  institution_value   DECIMAL(18,4) NULL,
  iso_currency_code   VARCHAR(8) NULL DEFAULT 'USD',
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_holding (account_id, security_id),
  KEY idx_holding_security (security_id),
  CONSTRAINT fk_holding_account FOREIGN KEY (account_id) REFERENCES accounts(account_id),
  CONSTRAINT fk_holding_security FOREIGN KEY (security_id) REFERENCES securities(security_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- investment_transactions — investment activity with qty + price.
--   * Webull (manual): buy/sell lots — the transactions table only keeps net cash,
--     so these DERIVE per-position cost basis (average cost) → holdings.cost_basis.
--   * Plaid (ext_source='plaid', migration 018): the FULL /investments/transactions
--     feed — buys/sells PLUS dividends/interest/fees/cash (side=NULL for non-trades).
--     Plaid holdings already carry cost_basis, so the derive is NOT run for Plaid;
--     these rows back the Dividends/Trades activity on investments.php + retirement.php.
-- See migrations 003 + 018, webull.php, and sync_investment_transactions() in sync.php.
CREATE TABLE investment_transactions (
  inv_tx_id   VARCHAR(64)   NOT NULL,           -- Webull lot id, or Plaid investment_transaction_id
  account_id  VARCHAR(64)   NOT NULL,
  security_id VARCHAR(64)   NOT NULL,           -- 'wb_'+cusip / Plaid security_id; no FK (sold-out secs)
  side        ENUM('buy','sell') NULL,          -- NULL for Plaid non-trade rows (dividend/interest/fee/cash)
  type        VARCHAR(32)   NULL,               -- Plaid type: buy|sell|cash|fee|transfer|cancel
  subtype     VARCHAR(48)   NULL,               -- Plaid subtype: dividend|interest|contribution|…
  name        VARCHAR(255)  NULL,               -- Plaid description (Webull → NULL, falls back to security name)
  quantity    DECIMAL(20,6) NOT NULL,
  price       DECIMAL(18,4) NOT NULL,           -- per share
  fees        DECIMAL(18,4) NOT NULL DEFAULT 0, -- commission + fee
  amount      DECIMAL(18,4) NULL,               -- net cash effect (+ out / - in)
  trade_date  DATE          NOT NULL,
  ext_source  VARCHAR(16)   NOT NULL DEFAULT 'webull', -- 'webull' | 'plaid'
  ext_period  VARCHAR(16)   NULL,               -- doc bucket (YYYY-MM) for re-ingest (Webull only)
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (inv_tx_id),
  KEY idx_itx_acct_sec (account_id, security_id, trade_date),
  KEY idx_itx_bucket (account_id, ext_source, ext_period),
  CONSTRAINT fk_itx_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- security_prices — daily close per security (history for charts + the
-- Investments change-icons). Filled by lib/prices.php (Twelve Data). One row
-- per (security_id, day); upserted. See migration 002_security_prices.php.
-- ---------------------------------------------------------------------------
CREATE TABLE security_prices (
  security_id  VARCHAR(64)   NOT NULL,
  price_date   DATE          NOT NULL,
  close        DECIMAL(18,4) NOT NULL,
  source       VARCHAR(16)   NOT NULL DEFAULT 'twelvedata',
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (security_id, price_date),
  KEY idx_sp_date (price_date),
  CONSTRAINT fk_sp_security FOREIGN KEY (security_id) REFERENCES securities(security_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- security_dividends — declared/historical cash dividends per security (ex-date,
-- per-share amount, payout frequency). Filled by lib/dividends.php (Polygon.io
-- free feed); read by q_security_dividends() for the Investments "Dividend income
-- & calendar" section (projected annual income + upcoming ex-dates). One row per
-- (security_id, ex_date); upserted. No FK (mirrors investment_transactions — a
-- security may not yet be in `securities`). See migration 019_security_dividends.php.
-- ---------------------------------------------------------------------------
CREATE TABLE security_dividends (
  security_id      VARCHAR(64)   NOT NULL,
  ex_date          DATE          NOT NULL,
  cash_amount      DECIMAL(18,6) NOT NULL,
  frequency        SMALLINT      NULL,            -- payouts/yr: 1,2,4,12,24,52 (0/NULL=unknown)
  pay_date         DATE          NULL,
  record_date      DATE          NULL,
  declaration_date DATE          NULL,
  currency         VARCHAR(8)    NOT NULL DEFAULT 'USD',
  dividend_type    VARCHAR(16)   NULL,
  source           VARCHAR(16)   NOT NULL DEFAULT 'polygon',
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (security_id, ex_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- api_usage — per-provider, per-month outbound-request meter. The home-value
-- feed (lib/home_value.php) reserves a slot here before every RentCast call and
-- refuses past the monthly cap, so we can't exceed the free quota / be billed an
-- overage. See migration 004_home_values.php.
-- ---------------------------------------------------------------------------
CREATE TABLE api_usage (
  provider      VARCHAR(32)  NOT NULL,
  period        CHAR(7)      NOT NULL,            -- 'YYYY-MM'
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (provider, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- home_values — one row per AVM valuation run (RentCast), keyed by address, so
-- the dashboard can show home value (+ low/high range) vs. the mortgage balance
-- and a value-over-time history. See migration 004_home_values.php.
-- ---------------------------------------------------------------------------
CREATE TABLE home_values (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id  VARCHAR(64)     NULL,               -- linked mortgage account, if mapped
  address     VARCHAR(255)    NOT NULL,
  value       DECIMAL(18,2)   NOT NULL,
  value_low   DECIMAL(18,2)   NULL,
  value_high  DECIMAL(18,2)   NULL,
  as_of       DATE            NOT NULL,
  source      VARCHAR(32)     NOT NULL DEFAULT 'rentcast',
  raw_json    MEDIUMTEXT      NULL,
  fetched_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hv_addr_date (address, as_of),
  KEY idx_hv_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- property_facts / market_stats / account_balance_history — Property page data
-- (RentCast property record + zip market stats; per-account daily balances for
-- the mortgage-over-time chart). See migration 005_property_detail.php.
-- ---------------------------------------------------------------------------
CREATE TABLE property_facts (
  address        VARCHAR(255)  NOT NULL,
  property_type  VARCHAR(48)   NULL,
  bedrooms       DECIMAL(5,1)  NULL,
  bathrooms      DECIMAL(5,1)  NULL,
  square_footage INT UNSIGNED  NULL,
  lot_size       INT UNSIGNED  NULL,
  year_built     SMALLINT      NULL,
  hoa_fee        DECIMAL(12,2) NULL,
  purchase_price DECIMAL(18,2) NULL,
  purchase_date  DATE          NULL,
  raw_json       MEDIUMTEXT    NULL,
  fetched_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE market_stats (
  zip                   VARCHAR(12)   NOT NULL,
  median_sale_price     DECIMAL(18,2) NULL,
  median_price_per_sqft DECIMAL(12,2) NULL,
  median_days_on_market DECIMAL(8,1)  NULL,
  raw_json              MEDIUMTEXT     NULL,
  fetched_at            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (zip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE account_balance_history (
  account_id    VARCHAR(64)   NOT NULL,
  snapshot_date DATE          NOT NULL,
  balance       DECIMAL(18,2) NOT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (account_id, snapshot_date),
  KEY idx_abh_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- recurring_streams — /transactions/recurring/get (subscriptions view)
-- ---------------------------------------------------------------------------
CREATE TABLE recurring_streams (
  stream_id          VARCHAR(64) NOT NULL,
  account_id         VARCHAR(64) NOT NULL,
  direction          ENUM('inflow','outflow') NOT NULL,
  description        VARCHAR(255) NULL,
  merchant_name      VARCHAR(255) NULL,
  frequency          VARCHAR(32) NULL,             -- WEEKLY/MONTHLY/...
  average_amount     DECIMAL(15,2) NULL,
  last_amount        DECIMAL(15,2) NULL,
  last_date          DATE NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  status             VARCHAR(32) NULL,             -- MATURE/EARLY_DETECTION
  category_primary   VARCHAR(64) NULL,
  raw                JSON NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (stream_id),
  KEY idx_stream_account (account_id),
  CONSTRAINT fk_stream_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- budgets — shared household monthly limit per category
-- ---------------------------------------------------------------------------
CREATE TABLE budgets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category        VARCHAR(96) NOT NULL,            -- matches pfc_primary (or override scheme)
  monthly_limit   DECIMAL(15,2) NOT NULL,
  effective_month CHAR(7) NULL,                    -- 'YYYY-MM'; NULL = applies every month
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_cat_month (category, effective_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- balance_snapshots — daily net-worth history (written by cron)
-- ---------------------------------------------------------------------------
CREATE TABLE balance_snapshots (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_date    DATE NOT NULL,                  -- Pacific date
  total_assets     DECIMAL(15,2) NOT NULL,         -- depository + investment values
  total_liabilities DECIMAL(15,2) NOT NULL,        -- credit/loan balances
  net_worth        DECIMAL(15,2) NOT NULL,         -- assets - liabilities
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_snapshot_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Optional companion: per-account snapshot rows if we want per-account net-worth trends.

-- ---------------------------------------------------------------------------
-- audit logs
-- ---------------------------------------------------------------------------
CREATE TABLE sync_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id       VARCHAR(64) NULL,
  trigger_type  VARCHAR(16) NOT NULL,              -- cron | webhook | manual
  added         INT NULL,
  modified      INT NULL,
  removed       INT NULL,
  ok            TINYINT(1) NOT NULL DEFAULT 1,
  message       TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_synclog_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE webhook_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_type  VARCHAR(64) NULL,
  webhook_code  VARCHAR(64) NULL,
  item_id       VARCHAR(64) NULL,
  verified      TINYINT(1) NOT NULL DEFAULT 0,
  payload       JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_webhooklog_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- manual_documents — one row per ingested document for a manual account.
-- The (account_id, doc_type, period_key) UNIQUE is the dedup "bucket": one slot
-- per monthly statement ('2026-05') or tax year ('2025'). Re-uploading the same
-- file (same file_sha256) is a no-op; a different file in the same bucket is a
-- correction that REPLACES that bucket's derived rows. Also points at the raw
-- PDF kept on the server (outside the web root).
-- ---------------------------------------------------------------------------
CREATE TABLE manual_documents (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id     VARCHAR(64) NOT NULL,
  manual_type    VARCHAR(32) NOT NULL,             -- 'webull'
  doc_type       VARCHAR(32) NOT NULL,             -- 'statement' | 'tax'
  period_key     VARCHAR(16) NOT NULL,             -- 'YYYY-MM' (statement) | 'YYYY' (tax)
  file_sha256    CHAR(64) NOT NULL,                -- exact-duplicate detection
  stored_path    VARCHAR(255) NULL,                -- absolute path to kept PDF (outside web root)
  original_name  VARCHAR(255) NULL,
  byte_size      INT UNSIGNED NULL,
  summary        JSON NULL,                        -- parsed headline figures (for the UI)
  uploaded_by    INT UNSIGNED NULL,
  uploaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_bucket (account_id, doc_type, period_key),
  KEY idx_doc_hash (file_sha256),
  KEY idx_doc_account (account_id),
  CONSTRAINT fk_doc_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- manual_tax_summaries — yearly 1099 totals, kept in their own bucket so they
-- are NEVER summed into the monthly transaction ledger (separate-buckets dedup).
-- One row per (account_id, tax_year); re-uploading a corrected 1099 replaces it.
-- ---------------------------------------------------------------------------
CREATE TABLE manual_tax_summaries (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id                  VARCHAR(64) NOT NULL,
  tax_year                    CHAR(4) NOT NULL,
  ordinary_dividends          DECIMAL(15,2) NULL,   -- 1099-DIV 1a
  qualified_dividends         DECIMAL(15,2) NULL,   -- 1099-DIV 1b
  capital_gain_distributions  DECIMAL(15,2) NULL,   -- 1099-DIV 2a
  nondividend_distributions   DECIMAL(15,2) NULL,   -- 1099-DIV 3
  section_199a_dividends      DECIMAL(15,2) NULL,   -- 1099-DIV 5
  interest_income             DECIMAL(15,2) NULL,   -- 1099-INT 1
  federal_tax_withheld        DECIMAL(15,2) NULL,   -- 1099-DIV/INT box 4
  foreign_tax_paid            DECIMAL(15,2) NULL,
  proceeds                    DECIMAL(15,2) NULL,   -- Summary of Sale Proceeds: Total Proceeds
  cost_basis                  DECIMAL(15,2) NULL,   -- Summary of Sale Proceeds: Total Cost Basis
  net_gain_loss               DECIMAL(15,2) NULL,   -- Summary of Sale Proceeds: Net Gain or Loss
  document_id                 INT UNSIGNED NULL,
  raw                         JSON NULL,            -- all parsed boxes for reference
  updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tax_account_year (account_id, tax_year),
  CONSTRAINT fk_tax_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- retirement_statements — hand-entered 401(k) statements (manual accounts with
-- items.manual_type='retirement_401k'; accounts.type='investment', subtype='401k').
-- One row per account per quarter; (account_id, period_key) is the dedup bucket so
-- re-entering a quarter UPDATES it (a correction). Source of truth for the Retirement
-- page's value-over-time + contributions; the latest row drives accounts.balance_current
-- (which is what folds the 401(k) into net worth). See migration 006_retirement.php.
-- ---------------------------------------------------------------------------
CREATE TABLE retirement_statements (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id        VARCHAR(64)   NOT NULL,
  period_key        VARCHAR(8)    NOT NULL,           -- 'YYYY-Qn'
  statement_date    DATE          NOT NULL,
  balance           DECIMAL(18,2) NOT NULL,
  employee_contrib  DECIMAL(15,2) NULL,               -- this period's contribution
  employer_contrib  DECIMAL(15,2) NULL,               -- this period's employer match
  employee_ytd      DECIMAL(15,2) NULL,
  employer_ytd      DECIMAL(15,2) NULL,
  note              VARCHAR(255)  NULL,
  created_by        INT UNSIGNED  NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ret_bucket (account_id, period_key),
  KEY idx_ret_acct_date (account_id, statement_date),
  CONSTRAINT fk_ret_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- retirement_settings — single global row (id=1) holding the combined-projection
-- assumptions: target retirement year, expected ongoing annual contribution, a growth
-- override (NULL = derive the rate from statement history) and the default rate used
-- until there's enough history, plus an optional target amount. See migration 006.
-- ---------------------------------------------------------------------------
CREATE TABLE retirement_settings (
  id                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  retirement_year     SMALLINT      NULL,
  annual_contribution DECIMAL(15,2) NULL,             -- expected ongoing; NULL = derive
  growth_rate_override DECIMAL(6,4)  NULL,            -- e.g. 0.0700; NULL = derive from history
  growth_default      DECIMAL(6,4)  NOT NULL DEFAULT 0.0600,
  target_amount       DECIMAL(18,2) NULL,
  updated_by          INT UNSIGNED  NULL,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- alert_settings — single global (household-shared) row of notification prefs
-- (migration 011). id is always 1. Defaults reproduce pre-#14 behaviour
-- (large-tx + connection alerts ON, everything new OFF). Read via
-- alert_settings() (lib/mailer.php) / q_alert_settings() (lib/queries.php);
-- large_tx_threshold NULL falls back to config['alerts']['large_tx_threshold'].
-- The digest / budget / unusual-spend / bill-reminder flags are stored now and
-- consumed by TODO #15/#16/#4.
-- ---------------------------------------------------------------------------
CREATE TABLE alert_settings (
  id                       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  email_enabled            TINYINT(1)    NOT NULL DEFAULT 1,   -- master kill-switch for ALL alert email
  large_tx_enabled         TINYINT(1)    NOT NULL DEFAULT 1,
  large_tx_threshold       DECIMAL(15,2) NULL,                 -- NULL = use config fallback
  connection_alert_enabled TINYINT(1)    NOT NULL DEFAULT 1,   -- existing bank-connection-broken alert
  budget_alert_enabled     TINYINT(1)    NOT NULL DEFAULT 0,   -- #16 budget-exceeded alert
  budget_alert_pct         TINYINT UNSIGNED NOT NULL DEFAULT 90,
  unusual_spend_enabled    TINYINT(1)    NOT NULL DEFAULT 0,   -- #16 unusual-spend alert (2× 3-mo avg)
  bill_reminder_enabled    TINYINT(1)    NOT NULL DEFAULT 0,   -- #16 bill-due reminder
  bill_reminder_days       TINYINT UNSIGNED NOT NULL DEFAULT 5,
  digest_enabled           TINYINT(1)    NOT NULL DEFAULT 0,   -- #15 weekly digest
  digest_sent_on           DATE          NULL,                 -- #15 last digest emailed on (PHP app-TZ date; double-send guard)
  updated_by               INT UNSIGNED  NULL,
  updated_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Spending-alert dedup ledger (#16, migration 013): one row per fired alert so the
-- daily cron emails each crossing AT MOST ONCE per occurrence. Written by
-- lib/spend_alerts.php via INSERT IGNORE on the UNIQUE key. `period` is a PHP app-TZ
-- string: 'YYYY-MM' for budget/unusual (once per category per month), 'YYYY-MM-DD'
-- (the due date) for bill reminders (re-arms each cycle). `alert_key` = category tag,
-- or account_id for bills.
CREATE TABLE alert_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_type  VARCHAR(24)  NOT NULL,                 -- 'budget' | 'unusual' | 'bill'
  alert_key   VARCHAR(96)  NOT NULL,                 -- category tag, or account_id for bills
  period      VARCHAR(10)  NOT NULL,                 -- 'YYYY-MM' or 'YYYY-MM-DD' (PHP app-TZ)
  sent_on     DATE         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alert (alert_type, alert_key, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FRED economic-data cache (#17, migration 014): one observation per (series_id,
-- obs_date) for the macro series the Economic page + inline insights use (CPI →
-- real net worth, 30-yr mortgage rate → refi compare, Treasury/Fed-funds → savings
-- context). Filled nightly by lib/fred.php → fred_refresh_latest(); read (NOT
-- VIS-scoped — global data) via q_fred_latest()/q_fred_history(). The natural
-- composite PK makes the upsert idempotent.
CREATE TABLE fred_series (
  series_id   VARCHAR(32)   NOT NULL,                 -- e.g. 'CPIAUCSL', 'MORTGAGE30US'
  obs_date    DATE          NOT NULL,                 -- observation date (FRED, stored as-is)
  value       DECIMAL(14,4) NOT NULL,                 -- index level or percent, per series
  fetched_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (series_id, obs_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- transaction annotations (#8, migration 015) — notes/tags/splits. The `note`
-- column lives on `transactions` (above); these are the tag vocabulary + the two
-- child tables. All FK to transactions(transaction_id) ON DELETE CASCADE so a Plaid
-- 'removed' tx cleans up its annotations. Splits "explode" a parent in the spend
-- aggregations (LEFT JOIN transaction_splits in queries.php) — the split amounts MUST
-- sum to the parent amount (enforced at write in api/account.php; the LEFT JOIN drops
-- no remainder). tags are a household-shared free-form vocabulary.
CREATE TABLE tags (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(48)  NOT NULL,                  -- normalised: lowercase, [a-z0-9-], no leading '#'
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tag_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transaction_tags (
  transaction_id VARCHAR(64)  NOT NULL,
  tag_id         INT UNSIGNED NOT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_id, tag_id),
  KEY idx_txtag_tag (tag_id),
  CONSTRAINT fk_txtag_tx  FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
  CONSTRAINT fk_txtag_tag FOREIGN KEY (tag_id)         REFERENCES tags(id)                     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transaction_splits (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  transaction_id VARCHAR(64)   NOT NULL,
  category       VARCHAR(96)   NOT NULL,              -- PFC-style tag, like category_override
  amount         DECIMAL(15,2) NOT NULL,              -- positive portion; splits sum to the parent amount
  note           VARCHAR(255)  NULL,
  created_by     INT UNSIGNED  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_split_tx (transaction_id),
  CONSTRAINT fk_split_tx FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rule-based auto-recategorization (#10, migration 016). Household-shared "always
-- categorize merchant X as Y" rules, resolved at READ time by the RULE_CAT subquery
-- in queries.php (precedence: split > category_override > RULE > pfc_primary).
CREATE TABLE category_rules (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_type  ENUM('merchant','contains') NOT NULL DEFAULT 'merchant',
  match_value VARCHAR(255) NOT NULL,                  -- stored UPPER-normalised (no LIKE metachars)
  category    VARCHAR(96)  NOT NULL,                  -- target PFC tag, UPPER (like category_override)
  priority    INT NOT NULL DEFAULT 0,                 -- higher wins; UI sets 0 for v1
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rule (match_type, match_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Savings goals (#9, migration 017). Household-shared. A goal is either tied to an account
-- (account_id SET → progress = that account's live balance_current) or manual (account_id NULL
-- → progress = current_amount). Progress is derived at READ time in q_goals() (queries.php).
CREATE TABLE goals (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name           VARCHAR(96)   NOT NULL,
  target_amount  DECIMAL(15,2) NOT NULL,
  account_id     VARCHAR(64)   NULL,                  -- tied account: progress = its balance; NULL = manual
  current_amount DECIMAL(15,2) NULL,                  -- manual goals only (account_id IS NULL)
  created_by     INT UNSIGNED  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_goals_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
