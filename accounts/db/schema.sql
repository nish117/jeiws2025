-- ============================================================
-- JEIWS Accounts — full schema
-- Lives in the same database as the site-ops tables; every table is
-- prefixed `acc_` so it can never collide with projects/workers/etc.
--
-- Applied automatically: includes/migrate.php runs this file whenever
-- ACC_SCHEMA_VERSION changes, so deploying = uploading the files. It can
-- also be imported by hand (phpMyAdmin → Import, or
--   mysql -u jeiws_app -p jeiws < accounts/db/schema.sql).
-- Safe to re-run: every statement is IF NOT EXISTS / INSERT IGNORE.
-- The default chart of accounts is seeded from PHP (includes/chart-template.php).
-- ============================================================

SET NAMES utf8mb4;

-- Staff who can sign in to the accounts system. Separate from the CMS
-- password and from site_users (site supervisors) on purpose — finance
-- access is granted individually.
CREATE TABLE IF NOT EXISTS acc_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','accountant','viewer') NOT NULL DEFAULT 'viewer',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at   DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed/successful sign-in log, used for brute-force throttling.
CREATE TABLE IF NOT EXISTS acc_login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(150) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at),
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value company settings (name, PAN, VAT no., fiscal year, …).
CREATE TABLE IF NOT EXISTS acc_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value TEXT         NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO acc_settings (setting_key, setting_value) VALUES
    ('company_name',       'JEIWS Engineering & Construction'),
    ('company_short_name', 'JEIWS'),
    ('company_address',    ''),
    ('company_phone',      ''),
    ('company_email',      ''),
    ('pan_number',         ''),
    ('vat_number',         ''),
    ('currency_code',      'NPR'),
    ('currency_symbol',    'Rs.'),
    ('vat_rate',           '13');

-- In-app notifications, one row per recipient (so read state is per user —
-- to notify everyone, insert a row for each active user).
CREATE TABLE IF NOT EXISTS acc_notifications (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL,
    message     VARCHAR(500) NULL,
    link        VARCHAR(255) NULL,
    icon        VARCHAR(40)  NOT NULL DEFAULT 'fa-bell',
    is_read     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    CONSTRAINT fk_acc_notif_user FOREIGN KEY (user_id) REFERENCES acc_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail — every create/update/delete in later modules writes here.
CREATE TABLE IF NOT EXISTS acc_audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(50)  NOT NULL,
    entity      VARCHAR(50)  NULL,
    entity_id   BIGINT UNSIGNED NULL,
    details     TEXT         NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity, entity_id),
    INDEX idx_user_time (user_id, created_at),
    CONSTRAINT fk_acc_audit_user FOREIGN KEY (user_id) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Phase 2 — double-entry general ledger
-- ============================================================

-- Chart of accounts. Group accounts (is_group = 1) only organise the tree;
-- transactions post to leaf accounts. `system_key` marks accounts other
-- modules post to automatically (receivables, VAT, TDS …) — those can be
-- renamed but not deleted or deactivated.
CREATE TABLE IF NOT EXISTS acc_accounts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id   INT UNSIGNED NULL,
    code        VARCHAR(20)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    type        ENUM('asset','liability','equity','income','expense') NOT NULL,
    is_group    TINYINT(1)   NOT NULL DEFAULT 0,
    system_key  VARCHAR(50)  NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parent (parent_id),
    CONSTRAINT fk_acc_account_parent FOREIGN KEY (parent_id) REFERENCES acc_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clients and suppliers (one table, `type` tells them apart). Journal
-- lines carry contact_id so each party's balance and statement come
-- straight from the ledger. tds_category drives TDS suggestions later
-- (contract 1.5%, service 1.5%/15%, rent 10% — see the TDS module).
CREATE TABLE IF NOT EXISTS acc_contacts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type            ENUM('client','supplier') NOT NULL,
    name            VARCHAR(150) NOT NULL,
    contact_person  VARCHAR(100) NULL,
    pan_number      VARCHAR(9)   NULL,
    vat_registered  TINYINT(1)   NOT NULL DEFAULT 0,
    phone           VARCHAR(40)  NULL,
    email           VARCHAR(150) NULL,
    address         VARCHAR(255) NULL,
    supplier_type   ENUM('material','subcontractor','service','equipment','other') NULL,
    tds_category    ENUM('none','contract','service','rent','other') NOT NULL DEFAULT 'none',
    notes           TEXT         NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_active (type, is_active),
    INDEX idx_name (name),
    INDEX idx_pan (pan_number),
    CONSTRAINT fk_acc_contact_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Construction projects as financial cost centres. site_project_id links
-- to the site-ops `projects` table (attendance/materials) when the same
-- project exists there; no FK because that table is outside this module.
CREATE TABLE IF NOT EXISTS acc_projects (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(20)  NOT NULL UNIQUE,
    name              VARCHAR(150) NOT NULL,
    client_id         INT UNSIGNED NULL,
    site_project_id   VARCHAR(64)  NULL UNIQUE,
    location          VARCHAR(150) NULL,
    status            ENUM('planned','ongoing','on_hold','completed','cancelled') NOT NULL DEFAULT 'ongoing',
    contract_value    DECIMAL(15,2) NOT NULL DEFAULT 0,
    budget            DECIMAL(15,2) NOT NULL DEFAULT 0,
    retention_percent DECIMAL(5,2)  NOT NULL DEFAULT 0,
    start_date        DATE NULL,
    end_date          DATE NULL,
    description       TEXT NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    CONSTRAINT fk_acc_project_client  FOREIGN KEY (client_id)  REFERENCES acc_contacts(id),
    CONSTRAINT fk_acc_project_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal vouchers. Every financial event in every module ends up here.
-- Drafts are editable and don't affect balances; posted entries are
-- immutable (corrected by voiding, which keeps the record for audit).
-- voucher_no is assigned at posting so posted vouchers are gap-free.
CREATE TABLE IF NOT EXISTS acc_journal_entries (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    voucher_no   VARCHAR(30)  NULL UNIQUE,
    entry_date   DATE         NOT NULL,
    fiscal_year  VARCHAR(9)   NOT NULL,
    narration    VARCHAR(500) NOT NULL,
    reference    VARCHAR(100) NULL,
    source       VARCHAR(30)  NOT NULL DEFAULT 'manual',
    source_id    BIGINT UNSIGNED NULL,
    status       ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_by   INT UNSIGNED NULL,
    posted_by    INT UNSIGNED NULL,
    posted_at    DATETIME     NULL,
    voided_by    INT UNSIGNED NULL,
    voided_at    DATETIME     NULL,
    void_reason  VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_date (status, entry_date),
    INDEX idx_fy (fiscal_year),
    INDEX idx_source (source, source_id),
    CONSTRAINT fk_acc_je_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acc_je_posted  FOREIGN KEY (posted_by)  REFERENCES acc_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acc_je_voided  FOREIGN KEY (voided_by)  REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Debit/credit lines. Exactly one of debit/credit is non-zero per line.
-- project_id tags a line to a project (cost centre); contact_id to the
-- client/supplier it concerns (drives their balance and statement).
-- Older installs are upgraded to this shape by includes/migrate.php.
CREATE TABLE IF NOT EXISTS acc_journal_lines (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id    BIGINT UNSIGNED NOT NULL,
    line_no     SMALLINT UNSIGNED NOT NULL,
    account_id  INT UNSIGNED  NOT NULL,
    debit       DECIMAL(15,2) NOT NULL DEFAULT 0,
    credit      DECIMAL(15,2) NOT NULL DEFAULT 0,
    description VARCHAR(255)  NULL,
    project_id  INT UNSIGNED  NULL,
    contact_id  INT UNSIGNED  NULL,
    INDEX idx_entry (entry_id),
    INDEX idx_account (account_id),
    INDEX idx_project (project_id),
    INDEX idx_contact (contact_id),
    CONSTRAINT fk_acc_jl_entry   FOREIGN KEY (entry_id)   REFERENCES acc_journal_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_acc_jl_account FOREIGN KEY (account_id) REFERENCES acc_accounts(id),
    CONSTRAINT fk_acc_jl_project FOREIGN KEY (project_id) REFERENCES acc_projects(id),
    CONSTRAINT fk_acc_jl_contact FOREIGN KEY (contact_id) REFERENCES acc_contacts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-fiscal-year counters for voucher / invoice numbering.
CREATE TABLE IF NOT EXISTS acc_sequences (
    name        VARCHAR(30) NOT NULL,
    fiscal_year VARCHAR(9)  NOT NULL,
    next_value  INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (name, fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Phase 4 — payroll
-- ============================================================

-- Salaried staff. Gender and marital status matter for salary tax
-- (married slabs; 10% rebate for women). project_id is the default cost
-- centre salary is charged to (e.g. a site engineer's project).
CREATE TABLE IF NOT EXISTS acc_employees (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_code       VARCHAR(20)  NOT NULL UNIQUE,
    full_name           VARCHAR(120) NOT NULL,
    designation         VARCHAR(100) NULL,
    department          VARCHAR(100) NULL,
    gender              ENUM('male','female','other') NOT NULL DEFAULT 'male',
    marital_status      ENUM('single','married') NOT NULL DEFAULT 'single',
    join_date           DATE NOT NULL,
    leave_date          DATE NULL,
    pan_number          VARCHAR(9)   NULL,
    basic_salary        DECIMAL(12,2) NOT NULL DEFAULT 0,
    life_insurance_premium   DECIMAL(12,2) NOT NULL DEFAULT 0,  -- annual
    health_insurance_premium DECIMAL(12,2) NOT NULL DEFAULT 0,  -- annual
    project_id          INT UNSIGNED NULL,
    bank_name           VARCHAR(100) NULL,
    bank_account        VARCHAR(40)  NULL,
    phone               VARCHAR(40)  NULL,
    email               VARCHAR(150) NULL,
    address             VARCHAR(255) NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    CONSTRAINT fk_acc_emp_project FOREIGN KEY (project_id) REFERENCES acc_projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_acc_emp_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One payroll run per B.S. month. draft → posted (accrual voucher) → paid
-- (payment voucher). Voiding a run voids its vouchers.
CREATE TABLE IF NOT EXISTS acc_payroll_runs (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bs_year            SMALLINT UNSIGNED NOT NULL,
    bs_month           TINYINT UNSIGNED  NOT NULL,
    fiscal_year        VARCHAR(9)  NOT NULL,
    period_start       DATE NOT NULL,
    period_end         DATE NOT NULL,
    status             ENUM('draft','posted','paid','void') NOT NULL DEFAULT 'draft',
    journal_entry_id   BIGINT UNSIGNED NULL,
    payment_entry_id   BIGINT UNSIGNED NULL,
    notes              VARCHAR(255) NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_period (bs_year, bs_month),
    CONSTRAINT fk_acc_pr_je      FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id),
    CONSTRAINT fk_acc_pr_pay     FOREIGN KEY (payment_entry_id) REFERENCES acc_journal_entries(id),
    CONSTRAINT fk_acc_pr_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One payslip per employee per run. Rates are snapshotted so posted
-- payslips never change when an employee's salary is later revised.
CREATE TABLE IF NOT EXISTS acc_payslips (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id             INT UNSIGNED NOT NULL,
    employee_id        INT UNSIGNED NOT NULL,
    project_id         INT UNSIGNED NULL,
    standard_days      TINYINT UNSIGNED NOT NULL,   -- divisor for the pay factor (26 by default)
    days_paid          DECIMAL(4,1)  NOT NULL,
    basic_rate         DECIMAL(12,2) NOT NULL DEFAULT 0,
    allowance_rate     DECIMAL(12,2) NOT NULL DEFAULT 0,   -- allowance per day present used for this payslip
    basic_pay          DECIMAL(12,2) NOT NULL DEFAULT 0,
    allowance_pay      DECIMAL(12,2) NOT NULL DEFAULT 0,
    overtime           DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonus              DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_earnings     DECIMAL(12,2) NOT NULL DEFAULT 0,
    gross_pay          DECIMAL(12,2) NOT NULL DEFAULT 0,
    tds                DECIMAL(12,2) NOT NULL DEFAULT 0,
    tds_override       TINYINT(1)    NOT NULL DEFAULT 0,
    advance_recovery   DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay            DECIMAL(12,2) NOT NULL DEFAULT 0,
    note               VARCHAR(255)  NULL,
    emailed_at         DATETIME      NULL,   -- when the payslip email was last sent successfully
    email_error        VARCHAR(255)  NULL,   -- last delivery problem, cleared on success
    UNIQUE KEY uq_run_employee (run_id, employee_id),
    INDEX idx_employee (employee_id),
    CONSTRAINT fk_acc_ps_run      FOREIGN KEY (run_id)      REFERENCES acc_payroll_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_acc_ps_employee FOREIGN KEY (employee_id) REFERENCES acc_employees(id),
    CONSTRAINT fk_acc_ps_project  FOREIGN KEY (project_id)  REFERENCES acc_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Phase 5 — sales invoices, purchase bills, attachments
-- ============================================================

-- One table for both directions. type = 'sales' (we bill a client) or
-- 'purchase' (a supplier bills us). Amounts are stored, not recomputed,
-- so a posted document never changes if rates change later.
CREATE TABLE IF NOT EXISTS acc_invoices (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type               ENUM('sales','purchase') NOT NULL,
    number             VARCHAR(40)  NULL,        -- our invoice no. (sales) / supplier's bill no. (purchase)
    number_is_manual   TINYINT(1)   NOT NULL DEFAULT 0, -- sales: typed from a printed bill book instead of auto-numbered
    contact_id         INT UNSIGNED NOT NULL,
    project_id         INT UNSIGNED NULL,
    invoice_date       DATE NOT NULL,
    due_date           DATE NULL,
    status             ENUM('draft','posted','paid','void') NOT NULL DEFAULT 'draft',
    vat_rate           DECIMAL(5,2)  NOT NULL DEFAULT 13,
    taxable_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,   -- lines subject to VAT
    exempt_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,   -- lines not subject to VAT
    vat_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- taxable + exempt + VAT
    retention_percent  DECIMAL(5,2)  NOT NULL DEFAULT 0,
    retention_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
    tds_percent        DECIMAL(5,2)  NOT NULL DEFAULT 0,
    tds_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
    net_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,   -- total − retention − TDS: what is actually received / paid
    amount_paid        DECIMAL(15,2) NOT NULL DEFAULT 0,
    notes              VARCHAR(500)  NULL,
    journal_entry_id   BIGINT UNSIGNED NULL,
    created_by         INT UNSIGNED NULL,
    posted_by          INT UNSIGNED NULL,
    posted_at          DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_status (type, status),
    INDEX idx_contact (contact_id),
    INDEX idx_project (project_id),
    INDEX idx_number (type, number),
    CONSTRAINT fk_acc_inv_contact FOREIGN KEY (contact_id) REFERENCES acc_contacts(id),
    CONSTRAINT fk_acc_inv_project FOREIGN KEY (project_id) REFERENCES acc_projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_acc_inv_je      FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id),
    CONSTRAINT fk_acc_inv_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acc_inv_posted  FOREIGN KEY (posted_by)  REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Line items. account_id = income account (sales) or expense/asset account (purchase).
CREATE TABLE IF NOT EXISTS acc_invoice_lines (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id   INT UNSIGNED NOT NULL,
    line_no      SMALLINT UNSIGNED NOT NULL,
    description  VARCHAR(255) NOT NULL,
    quantity     DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit         VARCHAR(20)   NULL,
    rate         DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
    vat_applicable TINYINT(1)  NOT NULL DEFAULT 1,
    account_id   INT UNSIGNED NOT NULL,
    INDEX idx_invoice (invoice_id),
    CONSTRAINT fk_acc_il_invoice FOREIGN KEY (invoice_id) REFERENCES acc_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_acc_il_account FOREIGN KEY (account_id) REFERENCES acc_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Money received against a sales invoice / paid against a purchase bill.
CREATE TABLE IF NOT EXISTS acc_invoice_payments (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id        INT UNSIGNED NOT NULL,
    payment_id        INT UNSIGNED NULL,          -- the acc_payments row this allocation belongs to (FK added by migrate.php)
    payment_date      DATE NOT NULL,
    amount            DECIMAL(15,2) NOT NULL,
    account_id        INT UNSIGNED NOT NULL,       -- cash / bank account
    reference         VARCHAR(100) NULL,
    status            ENUM('active','void') NOT NULL DEFAULT 'active',
    journal_entry_id  BIGINT UNSIGNED NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice (invoice_id),
    CONSTRAINT fk_acc_ip_invoice FOREIGN KEY (invoice_id) REFERENCES acc_invoices(id),
    CONSTRAINT fk_acc_ip_account FOREIGN KEY (account_id) REFERENCES acc_accounts(id),
    CONSTRAINT fk_acc_ip_je      FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id),
    CONSTRAINT fk_acc_ip_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uploaded files (invoice photos / PDFs). Stored under data/attachments/
-- (not web-accessible) with random names; served only via attachment.php.
CREATE TABLE IF NOT EXISTS acc_attachments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity         VARCHAR(30)  NOT NULL,
    entity_id      INT UNSIGNED NOT NULL,
    original_name  VARCHAR(255) NOT NULL,
    stored_name    VARCHAR(80)  NOT NULL UNIQUE,
    mime_type      VARCHAR(60)  NOT NULL,
    size_bytes     INT UNSIGNED NOT NULL,
    uploaded_by    INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity, entity_id),
    CONSTRAINT fk_acc_att_user FOREIGN KEY (uploaded_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Phase 6 — payments (money in / out), allocations and advances
-- ============================================================

-- One row per receipt or payment. A payment can settle several invoices
-- (rows in acc_invoice_payments with payment_id) and any remainder is an
-- advance (client advance liability / supplier advance asset). Applying an
-- advance to an invoice is also a row here, with method = 'advance'.
CREATE TABLE IF NOT EXISTS acc_payments (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction          ENUM('in','out') NOT NULL,
    contact_id         INT UNSIGNED NOT NULL,
    payment_date       DATE NOT NULL,
    amount             DECIMAL(15,2) NOT NULL,
    unallocated_amount DECIMAL(15,2) NOT NULL DEFAULT 0,   -- became an advance
    account_id         INT UNSIGNED NOT NULL,              -- cash/bank account, or the advance account for adjustments
    method             ENUM('cash','cheque','bank_transfer','wallet','advance') NOT NULL,
    reference          VARCHAR(100) NULL,
    notes              VARCHAR(255) NULL,
    status             ENUM('active','void') NOT NULL DEFAULT 'active',
    void_reason        VARCHAR(255) NULL,
    journal_entry_id   BIGINT UNSIGNED NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_direction_date (direction, payment_date),
    INDEX idx_contact (contact_id),
    CONSTRAINT fk_acc_pay_contact FOREIGN KEY (contact_id) REFERENCES acc_contacts(id),
    CONSTRAINT fk_acc_pay_account FOREIGN KEY (account_id) REFERENCES acc_accounts(id),
    CONSTRAINT fk_acc_pay_je      FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id),
    CONSTRAINT fk_acc_pay_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Phase 7 — cash & bank accounts and bank reconciliation
-- ============================================================

-- Details for every account money is held in. Each row points at its own
-- ledger account (created under 1130 Bank Accounts or 1100 Current Assets).
CREATE TABLE IF NOT EXISTS acc_bank_accounts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED NOT NULL UNIQUE,
    kind            ENUM('cash','bank','wallet') NOT NULL,
    bank_name       VARCHAR(100) NULL,
    branch          VARCHAR(100) NULL,
    account_number  VARCHAR(40)  NULL,
    account_type    ENUM('current','savings','overdraft','fixed_deposit','other') NULL,
    notes           VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_acc_ba_account FOREIGN KEY (account_id) REFERENCES acc_accounts(id),
    CONSTRAINT fk_acc_ba_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One reconciliation per bank statement. Lines ticked as "on the statement"
-- are recorded in acc_reconciliation_lines; a journal line can be cleared once.
CREATE TABLE IF NOT EXISTS acc_bank_reconciliations (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id         INT UNSIGNED NOT NULL,
    statement_date     DATE NOT NULL,
    statement_balance  DECIMAL(15,2) NOT NULL,
    status             ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
    completed_at       DATETIME NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account (account_id, status),
    CONSTRAINT fk_acc_br_account FOREIGN KEY (account_id) REFERENCES acc_accounts(id),
    CONSTRAINT fk_acc_br_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acc_reconciliation_lines (
    reconciliation_id  INT UNSIGNED NOT NULL,
    journal_line_id    BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (journal_line_id),
    INDEX idx_recon (reconciliation_id),
    CONSTRAINT fk_acc_rl_recon FOREIGN KEY (reconciliation_id) REFERENCES acc_bank_reconciliations(id) ON DELETE CASCADE,
    CONSTRAINT fk_acc_rl_line  FOREIGN KEY (journal_line_id) REFERENCES acc_journal_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Phase 8 — VAT, TDS and income-tax filings / payments
-- ============================================================

-- One row per filing or tax payment: a VAT return settled for a B.S. month,
-- TDS deposited for a month, an advance income-tax instalment, or the
-- year-end tax provision. period: 'YYYY-MM' (B.S.) for VAT/TDS,
-- 'instalment-1..3' or 'provision' for income tax.
CREATE TABLE IF NOT EXISTS acc_tax_filings (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tax               ENUM('vat','tds','income_tax') NOT NULL,
    fiscal_year       VARCHAR(9)  NOT NULL,
    period            VARCHAR(20) NOT NULL,
    amount            DECIMAL(15,2) NOT NULL DEFAULT 0,      -- cash paid to IRD (0 for a nil / credit VAT return)
    filing_date       DATE NOT NULL,
    account_id        INT UNSIGNED NULL,                     -- bank it was paid from
    reference         VARCHAR(100) NULL,                     -- IRD voucher / challan no.
    notes             VARCHAR(255) NULL,
    status            ENUM('active','void') NOT NULL DEFAULT 'active',
    journal_entry_id  BIGINT UNSIGNED NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tax_period (tax, period, status),
    CONSTRAINT fk_acc_tf_account FOREIGN KEY (account_id) REFERENCES acc_accounts(id),
    CONSTRAINT fk_acc_tf_je      FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id),
    CONSTRAINT fk_acc_tf_created FOREIGN KEY (created_by) REFERENCES acc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
