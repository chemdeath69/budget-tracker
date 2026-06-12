<?php
declare(strict_types=1);
/**
 * Migration 021 — credit-report import (TODO2 #28).
 *
 * Four tables holding a parsed consumer credit report (one per bureau pull):
 *
 *   credit_reports     — the pull header (user_id = whose report, bureau, pulled_on,
 *                        optional score). UNIQUE (user_id, bureau, pulled_on) so
 *                        re-importing the same pull REPLACES it (the import does a
 *                        DELETE-then-INSERT; the FK cascade clears the children).
 *   credit_tradelines  — one row per account on the report (creditor, type, balances,
 *                        limit, dates, status). Reconciled against our tracked accounts.
 *   credit_inquiries   — hard inquiries (inquirer + date).
 *   credit_flags       — derogatory marks (collections / public records / late / dispute).
 *
 * ⚠️ PII discipline (owner decisions, Session 58):
 *   • The RAW uploaded report is DISCARDED after parse — never stored on disk. So there
 *     is NO raw_path column (the file lives only as the PHP upload temp, auto-cleaned).
 *   • Sensitive free-text fields are ENCRYPTED at rest with the app encryption_key
 *     (lib/crypto.php secretbox) → stored as base64 ciphertext in the *_enc TEXT columns
 *     (creditor, account-number last-4 mask, flag detail, the report's printed name).
 *     Non-sensitive structured fields (account_type, balances, dates) stay plain so the
 *     dashboard can aggregate them.
 *   • Account numbers are captured as LAST-4 ONLY (enforced in the extractor prompt +
 *     credit_import_save). Full SSN / DOB are never extracted or stored.
 *   • Reports are household-visible (either signed-in user can view both) — but the
 *     rows are per-user (user_id), so the page can label whose report it is.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/021_credit_reports.php
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). CLI-only.
 *
 * ORDERING: run this FIRST (migration-first). credit.php / credit_import.php and the
 * q_credit_*() reads reference these tables, so deploying the code before they exist
 * would 500 those paths until this runs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$CONFIG = require __DIR__ . '/../config.php';
$d = $CONFIG['db'];
$dsn = !empty($d['socket'])
    ? "mysql:unix_socket={$d['socket']};dbname={$d['name']};charset=utf8mb4"
    : "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4";
$pdo = new PDO($dsn, $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$existed = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'credit_reports'"
)->fetchColumn();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS credit_reports (
       id                INT           NOT NULL AUTO_INCREMENT,
       user_id           INT           NOT NULL,        -- whose report this is
       bureau            ENUM('equifax','experian','transunion','other') NOT NULL,
       pulled_on         DATE          NOT NULL,         -- the report 'as of' date
       score             INT           NULL,             -- present only if the report carries one
       score_model       VARCHAR(32)   NULL,             -- e.g. 'VantageScore 3.0', 'FICO 8'
       consumer_name_enc TEXT          NULL,             -- ENCRYPTED printed name (confirm whose)
       created_by        INT           NOT NULL,         -- who uploaded it
       created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       UNIQUE KEY uq_credit_report (user_id, bureau, pulled_on),
       KEY idx_credit_user (user_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS credit_tradelines (
       id                INT           NOT NULL AUTO_INCREMENT,
       credit_report_id  INT           NOT NULL,
       creditor_enc      TEXT          NOT NULL,         -- ENCRYPTED creditor name
       account_mask_enc  TEXT          NULL,             -- ENCRYPTED last-4 (never the full number)
       account_type      VARCHAR(32)   NULL,             -- revolving/installment/mortgage/auto/student/personal/collection/other
       balance           DECIMAL(15,2) NULL,
       credit_limit      DECIMAL(15,2) NULL,
       high_balance      DECIMAL(15,2) NULL,
       monthly_payment   DECIMAL(15,2) NULL,
       past_due          DECIMAL(15,2) NULL,
       opened_on         DATE          NULL,
       closed_on         DATE          NULL,
       is_open           TINYINT(1)    NULL,             -- 1 open, 0 closed, NULL unknown
       responsibility    VARCHAR(24)   NULL,             -- individual / joint / authorized
       status            VARCHAR(48)   NULL,             -- 'Pays as agreed', 'Closed', etc.
       sort_order        INT           NOT NULL DEFAULT 0,
       PRIMARY KEY (id),
       KEY idx_tl_report (credit_report_id),
       CONSTRAINT fk_tl_report FOREIGN KEY (credit_report_id)
         REFERENCES credit_reports (id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS credit_inquiries (
       id                INT           NOT NULL AUTO_INCREMENT,
       credit_report_id  INT           NOT NULL,
       inquirer_enc      TEXT          NOT NULL,         -- ENCRYPTED inquiring company
       inquiry_date      DATE          NULL,
       inquiry_type      VARCHAR(16)   NULL,             -- 'hard' / 'soft'
       sort_order        INT           NOT NULL DEFAULT 0,
       PRIMARY KEY (id),
       KEY idx_inq_report (credit_report_id),
       CONSTRAINT fk_inq_report FOREIGN KEY (credit_report_id)
         REFERENCES credit_reports (id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS credit_flags (
       id                INT           NOT NULL AUTO_INCREMENT,
       credit_report_id  INT           NOT NULL,
       kind              VARCHAR(32)   NOT NULL,         -- collection/public_record/late_payment/derogatory/bankruptcy/dispute/other
       detail_enc        TEXT          NULL,             -- ENCRYPTED free-text detail
       amount            DECIMAL(15,2) NULL,
       flag_date         DATE          NULL,
       sort_order        INT           NOT NULL DEFAULT 0,
       PRIMARY KEY (id),
       KEY idx_flag_report (credit_report_id),
       CONSTRAINT fk_flag_report FOREIGN KEY (credit_report_id)
         REFERENCES credit_reports (id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo $existed === 0
    ? "Migration 021 applied: credit_reports + credit_tradelines + credit_inquiries + credit_flags created.\n"
    : "Migration 021 skipped: credit_reports already present.\n";
