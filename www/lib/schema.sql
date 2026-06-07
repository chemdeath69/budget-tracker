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
-- items — one Plaid Item = one bank login. access_token encrypted at rest.
-- ---------------------------------------------------------------------------
CREATE TABLE items (
  item_id              VARCHAR(64)  NOT NULL,      -- Plaid item_id
  user_id              INT UNSIGNED NOT NULL,      -- owner (who linked it)
  institution_id       VARCHAR(64)  NULL,
  institution_name     VARCHAR(255) NULL,
  access_token_enc     VARBINARY(512) NOT NULL,    -- sodium secretbox (nonce||ciphertext)
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
  official_name         VARCHAR(255) NULL,
  mask                  VARCHAR(16)  NULL,
  type                  VARCHAR(32)  NULL,          -- depository|credit|loan|investment
  subtype               VARCHAR(48)  NULL,
  balance_available     DECIMAL(15,2) NULL,
  balance_current       DECIMAL(15,2) NULL,
  balance_limit         DECIMAL(15,2) NULL,
  iso_currency_code     VARCHAR(8)  NULL DEFAULT 'USD',
  visibility            ENUM('shared','private') NOT NULL DEFAULT 'shared',
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
  payment_channel         VARCHAR(32) NULL,
  large_tx_alerted        TINYINT(1) NOT NULL DEFAULT 0, -- de-dupe large-tx email
  raw                     JSON NULL,               -- optional full payload
  imported_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_id),
  KEY idx_tx_account (account_id),
  KEY idx_tx_date (date),
  KEY idx_tx_pfc (pfc_primary),
  CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Upsert from sync: INSERT ... ON DUPLICATE KEY UPDATE everything EXCEPT
-- category_override and large_tx_alerted (preserve those). 'removed' => DELETE by id.

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

-- Optional: investment_transactions (buys/sells/dividends). Add later if needed.

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
