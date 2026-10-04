<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Brings the database up to ACC_SCHEMA_VERSION. Called from bootstrap on
 * every request, but after the first run it costs nothing beyond the
 * settings lookup that already happens. Bump ACC_SCHEMA_VERSION whenever
 * db/schema.sql gains tables or seed data.
 */
const ACC_SCHEMA_VERSION = 12;

function acc_migrate(): void {
    if ((int)(settings()['schema_version'] ?? 0) >= ACC_SCHEMA_VERSION) return;

    $pdo = db();
    // Serialise concurrent first requests so two of them can't seed at once.
    if (!$pdo->query("SELECT GET_LOCK('acc_migrate', 15)")->fetchColumn()) {
        throw new RuntimeException('Timed out waiting for another database update to finish.');
    }
    try {
        $current = (int)$pdo->query("SELECT setting_value FROM acc_settings WHERE setting_key = 'schema_version'")->fetchColumn();
    } catch (PDOException) {
        $current = 0; // acc_settings doesn't exist yet — fresh install
    }

    try {
        if ($current < ACC_SCHEMA_VERSION) {
            $pdo->exec(file_get_contents(ACC_ROOT . '/db/schema.sql'));
            acc_upgrade_existing_tables($pdo);
            acc_seed_chart_of_accounts($pdo);
            acc_register_cash_accounts($pdo);
            $pdo->prepare("INSERT INTO acc_settings (setting_key, setting_value) VALUES ('schema_version', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([(string)ACC_SCHEMA_VERSION]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('acc_migrate')");
    }
    settings(true);
}

/**
 * Changes to tables that already existed before this version. schema.sql
 * only creates missing tables (IF NOT EXISTS), so column/constraint changes
 * to existing ones live here. Every step checks first, so re-running is safe.
 */
function acc_upgrade_existing_tables(PDO $pdo): void {
    $column = function (string $table, string $col) use ($pdo): ?string {
        $stmt = $pdo->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $col]);
        return $stmt->fetchColumn() ?: null;
    };
    $hasConstraint = function (string $table, string $name) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
        $stmt->execute([$table, $name]);
        return (bool)$stmt->fetchColumn();
    };

    // v3: project_id pointed at the site-ops projects table (VARCHAR). It now
    // references acc_projects. Any old tags can't be mapped, so they're cleared
    // (none existed in practice — the module had no project UI before v3).
    if ($column('acc_journal_lines', 'project_id') === 'varchar') {
        $pdo->exec('UPDATE acc_journal_lines SET project_id = NULL');
        $pdo->exec('ALTER TABLE acc_journal_lines MODIFY project_id INT UNSIGNED NULL');
    }
    if (!$column('acc_journal_lines', 'contact_id')) {
        $pdo->exec('ALTER TABLE acc_journal_lines ADD COLUMN contact_id INT UNSIGNED NULL AFTER project_id, ADD INDEX idx_contact (contact_id)');
    }
    if (!$hasConstraint('acc_journal_lines', 'fk_acc_jl_project')) {
        $pdo->exec('ALTER TABLE acc_journal_lines ADD CONSTRAINT fk_acc_jl_project FOREIGN KEY (project_id) REFERENCES acc_projects(id)');
    }
    if (!$hasConstraint('acc_journal_lines', 'fk_acc_jl_contact')) {
        $pdo->exec('ALTER TABLE acc_journal_lines ADD CONSTRAINT fk_acc_jl_contact FOREIGN KEY (contact_id) REFERENCES acc_contacts(id)');
    }

    // v5: SSF removed from payroll (the company does not contribute to SSF).
    foreach (['acc_employees' => ['ssf_number', 'ssf_enrolled'], 'acc_payslips' => ['ssf_employee', 'ssf_employer']] as $table => $cols) {
        foreach ($cols as $col) {
            if ($column($table, $col)) $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$col}");
        }
    }
    $pdo->exec("DELETE FROM acc_settings WHERE setting_key IN ('payroll_ssf_employee_rate', 'payroll_ssf_employer_rate', 'payroll_retirement_cap')");
    // v6: pay factor divides by standard days (26), not the B.S. month length.
    // Existing payslips keep the divisor they were calculated with.
    if ($column('acc_payslips', 'days_in_month')) {
        $pdo->exec('ALTER TABLE acc_payslips CHANGE days_in_month standard_days TINYINT UNSIGNED NOT NULL');
    }

    // v7: allowance is a flat rate per day present (setting payroll_allowance_per_day),
    // not a per-employee monthly amount. Posted payslips keep their own allowance_rate.
    if ($column('acc_employees', 'allowance')) {
        $pdo->exec('ALTER TABLE acc_employees DROP COLUMN allowance');
    }

    // v8: payslip email delivery tracking.
    if (!$column('acc_payslips', 'emailed_at')) {
        $pdo->exec('ALTER TABLE acc_payslips ADD COLUMN emailed_at DATETIME NULL AFTER note, ADD COLUMN email_error VARCHAR(255) NULL AFTER emailed_at');
    }

    // v10: invoice payments are allocations of an acc_payments row.
    if (!$column('acc_invoice_payments', 'payment_id')) {
        $pdo->exec('ALTER TABLE acc_invoice_payments ADD COLUMN payment_id INT UNSIGNED NULL AFTER invoice_id');
    }
    if (!$hasConstraint('acc_invoice_payments', 'fk_acc_ip_payment')) {
        $pdo->exec('ALTER TABLE acc_invoice_payments ADD CONSTRAINT fk_acc_ip_payment FOREIGN KEY (payment_id) REFERENCES acc_payments(id)');
    }


    // The two SSF accounts: delete if never used, otherwise keep them as ordinary
    // (non-system) accounts so their history stays and they can be deactivated.
    foreach (['ssf_payable', 'ssf_expense'] as $key) {
        $stmt = $pdo->prepare('SELECT id FROM acc_accounts WHERE system_key = ?');
        $stmt->execute([$key]);
        if (!$id = $stmt->fetchColumn()) continue;
        $used = $pdo->prepare('SELECT 1 FROM acc_journal_lines WHERE account_id = ? LIMIT 1');
        $used->execute([$id]);
        $used->fetchColumn()
            ? $pdo->prepare('UPDATE acc_accounts SET system_key = NULL WHERE id = ?')->execute([$id])
            : $pdo->prepare('DELETE FROM acc_accounts WHERE id = ?')->execute([$id]);
    }
}

/**
 * v11: make sure the accounts money is kept in (cash, petty cash, anything
 * under 1130 Bank Accounts) have a cash/bank record. Runs after seeding so
 * it also covers fresh installs; INSERT IGNORE makes it safe to repeat.
 */
function acc_register_cash_accounts(PDO $pdo): void {
    $register = $pdo->prepare('INSERT IGNORE INTO acc_bank_accounts (account_id, kind) VALUES (?, ?)');
    foreach ($pdo->query("SELECT id, system_key FROM acc_accounts WHERE is_group = 0 AND (system_key IN ('cash_in_hand','petty_cash')
                          OR parent_id = (SELECT id FROM acc_accounts WHERE system_key = 'bank_accounts_group'))") as $a) {
        $register->execute([$a['id'], $a['system_key'] ? 'cash' : 'bank']);
    }
}

function acc_seed_chart_of_accounts(PDO $pdo): void {
    if ((int)$pdo->query('SELECT COUNT(*) FROM acc_accounts')->fetchColumn() > 0) return;

    $idsByCode = [];
    $insert = $pdo->prepare(
        'INSERT INTO acc_accounts (parent_id, code, name, type, is_group, system_key) VALUES (?,?,?,?,?,?)'
    );
    $pdo->beginTransaction();
    foreach (require __DIR__ . '/chart-template.php' as [$code, $name, $type, $isGroup, $parentCode, $systemKey]) {
        $insert->execute([$parentCode ? $idsByCode[$parentCode] : null, $code, $name, $type, $isGroup, $systemKey]);
        $idsByCode[$code] = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
}
