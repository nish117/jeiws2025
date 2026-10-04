<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Payroll: employees, monthly runs by B.S. month, Nepal salary TDS, and
 * posting to the ledger. The company does not contribute to SSF, so there
 * are no SSF deductions and the 1% Social Security Tax band applies.
 *
 * Allowance is a flat rate per day present (Rs 100 by default), the same
 * for every employee: allowance pay = rate × days present.
 *
 * Salary TDS uses the annual-projection method: this month's regular pay
 * (basic + a full month's allowance, i.e. rate × 26) × 12 is taxed on the annual slabs and
 * 1/12 is withheld, pro-rated by days paid ÷ standard days (26). One-off earnings (overtime,
 * bonus, festival allowance) are taxed at the marginal rate in the month
 * they're paid: tax(regular×12 + one-off) − tax(regular×12).
 *
 * All amounts are integer paisa; payroll figures are rounded to whole
 * rupees, as on a salary sheet. Rates and slabs live in settings
 * (payroll_* keys) because the Finance Act changes them — see
 * payroll-settings.php.
 */

const PAYROLL_DEFAULTS = [
    'standard_days'        => '26',     // a full month's pay = this many days; pay factor = days paid ÷ standard days
    'allowance_per_day'    => '100',    // Rs per day present, same for every employee
    'life_insurance_cap'   => '40000',  // max annual life insurance premium deduction (Rs)
    'health_insurance_cap' => '20000',  // max annual health insurance premium deduction (Rs)
    'female_rebate'        => '10',     // % rebate on tax for women with only salary income
    // Cumulative upper limits in Rs (null = no limit) and rate %. The first
    // band is the 1% Social Security Tax.
    'tax_slabs' => '{"single":[[500000,1],[700000,10],[1000000,20],[2000000,30],[5000000,36],[null,39]],'
                 . '"married":[[600000,1],[800000,10],[1100000,20],[2000000,30],[5000000,36],[null,39]]}',
];

function payroll_config(): array {
    $cfg = [];
    foreach (PAYROLL_DEFAULTS as $key => $default) $cfg[$key] = setting('payroll_' . $key, $default);
    $cfg['tax_slabs'] = json_decode($cfg['tax_slabs'], true) ?: json_decode(PAYROLL_DEFAULTS['tax_slabs'], true);
    return $cfg;
}

/** Days that make a full month's pay (26 by default), never less than 1. */
function standard_days(?array $cfg = null): int {
    return max(1, (int)($cfg ?? payroll_config())['standard_days']);
}

/**
 * Default days paid for someone employed from $from to $to within a month:
 * a full month is the standard days; a part month counts working days
 * (every day except Saturday, Nepal's weekly holiday), capped at the standard.
 */
function default_days_paid(string $from, string $to, string $monthStart, string $monthEnd, int $standardDays): int {
    if ($from <= $monthStart && $to >= $monthEnd) return $standardDays;
    $working = 0;
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
        if (date('w', $d) !== '6') $working++; // 6 = Saturday
    }
    return min($standardDays, $working);
}

/** Allowance for a full month (rate per day × standard days), in paisa. */
function full_month_allowance(?array $cfg = null): int {
    $cfg ??= payroll_config();
    return (int)round((float)$cfg['allowance_per_day'] * 100) * standard_days($cfg);
}

/** An employee's regular monthly pay for a full month: basic + full-month allowance, in paisa. */
function regular_monthly_pay(array $emp, ?array $cfg = null): int {
    return decimal_to_cents($emp['basic_salary']) + full_month_allowance($cfg);
}

function round_rupee(float|int $cents): int {
    return (int)round($cents / 100) * 100;
}

// ── Tax ──────────────────────────────────────────────────────────────
/**
 * Annual salary tax in paisa, with the working shown.
 * @return array{assessable:int, insurance:int, taxable:int, tax_before_rebate:int, rebate:int, tax:int}
 */
function annual_salary_tax(int $annualIncome, array $emp, array $cfg): array {
    $assessable = $annualIncome;
    $insurance  = min(decimal_to_cents($emp['life_insurance_premium']), (int)$cfg['life_insurance_cap'] * 100)
                + min(decimal_to_cents($emp['health_insurance_premium']), (int)$cfg['health_insurance_cap'] * 100);
    $taxable = max(0, $assessable - $insurance);

    $slabs = $cfg['tax_slabs'][$emp['marital_status'] === 'married' ? 'married' : 'single'];
    $tax = 0;
    $lower = 0;
    foreach ($slabs as [$limit, $rate]) {
        $upper = $limit === null ? PHP_INT_MAX : (int)$limit * 100;
        if ($taxable <= $lower) break;
        $portion = min($taxable, $upper) - $lower;
        $tax += $portion * (float)$rate / 100;
        $lower = $upper;
    }
    $tax = (int)round($tax);
    $rebate = $emp['gender'] === 'female' ? (int)round($tax * (float)$cfg['female_rebate'] / 100) : 0;

    return [
        'assessable' => $assessable, 'insurance' => $insurance,
        'taxable' => $taxable, 'tax_before_rebate' => $tax, 'rebate' => $rebate, 'tax' => $tax - $rebate,
    ];
}

/**
 * Computes one payslip. $in: days_paid, overtime, bonus, other_earnings,
 * advance_recovery (amount strings), tds_override (bool), tds (string).
 * @return array{errors: string[], slip: array}
 */
function calculate_payslip(array $emp, array $in, array $cfg): array {
    $errors = [];
    $name = $emp['full_name'];
    $standardDays = standard_days($cfg);
    $daysPaid = trim((string)($in['days_paid'] ?? $standardDays));
    if (!is_numeric($daysPaid) || $daysPaid < 0 || $daysPaid > $standardDays || fmod((float)$daysPaid * 2, 1) != 0) {
        $errors[] = "{$name}: days present must be between 0 and {$standardDays} (half days allowed). Pay extra days as overtime.";
        $daysPaid = $standardDays;
    }
    $daysPaid = (float)$daysPaid;
    $amounts = [];
    foreach (['overtime', 'bonus', 'other_earnings', 'advance_recovery'] as $k) {
        $amounts[$k] = to_cents($in[$k] ?? '');
        if ($amounts[$k] === null) { $errors[] = "{$name}: {$k} must be an amount."; $amounts[$k] = 0; }
    }

    $factor = $daysPaid / $standardDays;
    $basicRate = decimal_to_cents($emp['basic_salary']);
    $allowanceRate = (int)round((float)$cfg['allowance_per_day'] * 100); // per day present
    $basicPay = round_rupee($basicRate * $factor);
    $allowancePay = round_rupee($allowanceRate * $daysPaid);
    $oneOff = $amounts['overtime'] + $amounts['bonus'] + $amounts['other_earnings'];
    $gross = $basicPay + $allowancePay + $oneOff;

    $annualRegular = regular_monthly_pay($emp, $cfg) * 12;
    $taxRegular = annual_salary_tax($annualRegular, $emp, $cfg)['tax'];
    $taxWithOneOff = $oneOff ? annual_salary_tax($annualRegular + $oneOff, $emp, $cfg)['tax'] : $taxRegular;
    $tds = round_rupee($taxRegular / 12 * $factor + ($taxWithOneOff - $taxRegular));

    $override = !empty($in['tds_override']);
    if ($override) {
        $manual = to_cents($in['tds'] ?? '');
        if ($manual === null) $errors[] = "{$name}: TDS must be an amount.";
        else $tds = $manual;
    }

    $net = $gross - $tds - $amounts['advance_recovery'];
    if ($net < 0) $errors[] = "{$name}: deductions are more than the gross pay.";

    return ['errors' => $errors, 'slip' => [
        'standard_days' => $standardDays, 'days_paid' => $daysPaid,
        'basic_rate' => $basicRate, 'allowance_rate' => $allowanceRate,
        'basic_pay' => $basicPay, 'allowance_pay' => $allowancePay,
        'overtime' => $amounts['overtime'], 'bonus' => $amounts['bonus'], 'other_earnings' => $amounts['other_earnings'],
        'gross_pay' => $gross,
        'tds' => $tds, 'tds_override' => $override ? 1 : 0,
        'advance_recovery' => $amounts['advance_recovery'], 'net_pay' => $net,
        'project_id' => $emp['project_id'] ? (int)$emp['project_id'] : null,
    ]];
}

const PAYSLIP_MONEY_FIELDS = ['basic_rate', 'allowance_rate', 'basic_pay', 'allowance_pay', 'overtime', 'bonus', 'other_earnings',
                              'gross_pay', 'tds', 'advance_recovery', 'net_pay'];

// ── Employees ────────────────────────────────────────────────────────
function next_employee_code(): string {
    $max = (int)db()->query("SELECT MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) FROM acc_employees WHERE employee_code REGEXP '^EMP-[0-9]+$'")->fetchColumn();
    return sprintf('EMP-%03d', $max + 1);
}

/** @return array{errors: string[], values: array} */
function validate_employee(array $in, ?int $selfId = null): array {
    $t = fn(string $k) => trim((string)($in[$k] ?? ''));
    $v = [
        'employee_code' => strtoupper($t('employee_code')) ?: next_employee_code(),
        'full_name' => $t('full_name'), 'designation' => $t('designation') ?: null, 'department' => $t('department') ?: null,
        'gender' => $t('gender'), 'marital_status' => $t('marital_status'),
        'join_date' => $t('join_date'), 'leave_date' => $t('leave_date') ?: null,
        'pan_number' => preg_replace('/\s+/', '', $t('pan_number')) ?: null,
        'basic_salary' => to_cents($in['basic_salary'] ?? ''),
        'life_insurance_premium' => to_cents($in['life_insurance_premium'] ?? ''), 'health_insurance_premium' => to_cents($in['health_insurance_premium'] ?? ''),
        'project_id' => (int)($in['project_id'] ?? 0) ?: null,
        'bank_name' => $t('bank_name') ?: null, 'bank_account' => $t('bank_account') ?: null,
        'phone' => $t('phone') ?: null, 'email' => strtolower($t('email')) ?: null, 'address' => $t('address') ?: null,
        'is_active' => $selfId === null || !empty($in['is_active']) ? 1 : 0,
    ];
    $e = [];
    if ($v['full_name'] === '' || mb_strlen($v['full_name']) > 120) $e[] = 'Full name is required.';
    if (!preg_match('/^[A-Z0-9][A-Z0-9.\-\/]{0,19}$/', $v['employee_code'])) $e[] = 'Employee code must be up to 20 letters, digits or dashes.';
    if (!in_array($v['gender'], ['male', 'female', 'other'], true)) $e[] = 'Choose gender (it affects the tax rebate).';
    if (!in_array($v['marital_status'], ['single', 'married'], true)) $e[] = 'Choose marital status (it decides the tax slabs).';
    if (!valid_ad_date($v['join_date'])) $e[] = 'Enter a valid joining date.';
    if ($v['leave_date'] !== null && !valid_ad_date($v['leave_date'])) $e[] = 'Leaving date is not valid.';
    if (!$e && $v['leave_date'] && $v['leave_date'] < $v['join_date']) $e[] = 'Leaving date is before the joining date.';
    if ($v['pan_number'] !== null && !preg_match('/^\d{9}$/', $v['pan_number'])) $e[] = 'PAN must be 9 digits.';
    if ($v['email'] !== null && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $e[] = 'Email is not valid.';
    foreach (['basic_salary' => 'Basic salary', 'life_insurance_premium' => 'Life insurance premium', 'health_insurance_premium' => 'Health insurance premium'] as $k => $label) {
        if ($v[$k] === null) $e[] = "{$label} must be an amount.";
    }
    if (($v['basic_salary'] ?? 0) <= 0) $e[] = 'Basic salary must be more than zero.';
    if ($v['project_id'] && !isset(project_options()[$v['project_id']])) $e[] = 'Unknown project.';

    $dupe = db()->prepare('SELECT full_name FROM acc_employees WHERE employee_code = ? AND id <> ?');
    $dupe->execute([$v['employee_code'], $selfId ?? 0]);
    if ($other = $dupe->fetchColumn()) $e[] = "Code {$v['employee_code']} is already used by {$other}.";
    if ($v['pan_number']) {
        $dupe = db()->prepare('SELECT full_name FROM acc_employees WHERE pan_number = ? AND id <> ?');
        $dupe->execute([$v['pan_number'], $selfId ?? 0]);
        if ($other = $dupe->fetchColumn()) $e[] = "PAN {$v['pan_number']} is already used by {$other}.";
    }
    return ['errors' => $e, 'values' => $v];
}

function save_employee(array $v, int $userId, ?int $id = null): int {
    $money = ['basic_salary', 'life_insurance_premium', 'health_insurance_premium'];
    $cols = array_keys($v);
    $params = array_map(fn($c) => in_array($c, $money, true) ? cents_to_decimal($v[$c]) : $v[$c], $cols);
    if ($id) {
        db()->prepare('UPDATE acc_employees SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ' WHERE id = ?')->execute([...$params, $id]);
        audit('update_employee', 'employee', $id, $v['full_name']);
        return $id;
    }
    db()->prepare('INSERT INTO acc_employees (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)')
        ->execute([...$params, $userId]);
    $newId = (int)db()->lastInsertId();
    audit('create_employee', 'employee', $newId, $v['full_name']);
    return $newId;
}

// ── Runs ─────────────────────────────────────────────────────────────
function bs_month_name(int $month): string {
    return NepaliDate::MONTH_NAMES[$month - 1] ?? '?';
}

function payroll_period_label(array $run): string {
    return bs_month_name((int)$run['bs_month']) . ' ' . $run['bs_year'];
}

function load_payroll_run(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM acc_payroll_runs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Payslips of a run with employee details, money fields in paisa. */
function load_payslips(int $runId): array {
    $stmt = db()->prepare(
        'SELECT s.*, e.employee_code, e.full_name, e.designation, e.department, e.email, e.pan_number, e.bank_name, e.bank_account
         FROM acc_payslips s JOIN acc_employees e ON e.id = s.employee_id
         WHERE s.run_id = ? ORDER BY e.full_name'
    );
    $stmt->execute([$runId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) foreach (PAYSLIP_MONEY_FIELDS as $f) $r[$f] = decimal_to_cents($r[$f]);
    return $rows;
}

function payslip_totals(array $slips): array {
    $t = array_fill_keys(PAYSLIP_MONEY_FIELDS, 0);
    foreach ($slips as $s) foreach (PAYSLIP_MONEY_FIELDS as $f) $t[$f] += $s[$f];
    return $t;
}

/** Employees on the payroll for a period, with their default days paid in it. */
function eligible_employees(string $start, string $end): array {
    $stmt = db()->prepare(
        'SELECT * FROM acc_employees WHERE is_active = 1 AND join_date <= ? AND (leave_date IS NULL OR leave_date >= ?) ORDER BY full_name'
    );
    $stmt->execute([$end, $start]);
    $out = [];
    foreach ($stmt->fetchAll() as $emp) {
        $from = max($start, $emp['join_date']);
        $to = $emp['leave_date'] ? min($end, $emp['leave_date']) : $end;
        $emp['default_days'] = default_days_paid($from, $to, $start, $end, standard_days());
        $out[(int)$emp['id']] = $emp;
    }
    return $out;
}

function write_payslip(int $runId, int $employeeId, array $slip, ?string $note = null): void {
    $cols = ['project_id', 'standard_days', 'days_paid', ...PAYSLIP_MONEY_FIELDS, 'tds_override', 'note'];
    $vals = array_map(fn($c) => in_array($c, PAYSLIP_MONEY_FIELDS, true) ? cents_to_decimal($slip[$c]) : ($c === 'note' ? $note : $slip[$c]), $cols);
    db()->prepare(
        'INSERT INTO acc_payslips (run_id, employee_id, ' . implode(', ', $cols) . ') VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')
         ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols))
    )->execute([$runId, $employeeId, ...$vals]);
}

/** Creates a draft run for a B.S. month with a payslip for every eligible employee. */
function create_payroll_run(int $bsYear, int $bsMonth, int $userId): int {
    $days = NepaliDate::daysInMonth($bsYear, $bsMonth);
    if (!$days) throw new DomainException('That month is outside the supported calendar.');
    $start = NepaliDate::bsToAd($bsYear, $bsMonth, 1);
    $end = NepaliDate::bsToAd($bsYear, $bsMonth, $days);

    return in_transaction(function (PDO $pdo) use ($bsYear, $bsMonth, $start, $end, $userId) {
        $exists = $pdo->prepare("SELECT id FROM acc_payroll_runs WHERE bs_year = ? AND bs_month = ? AND status <> 'void' FOR UPDATE");
        $exists->execute([$bsYear, $bsMonth]);
        if ($exists->fetchColumn()) throw new DomainException('Payroll for ' . bs_month_name($bsMonth) . " {$bsYear} already exists.");

        $employees = eligible_employees($start, $end);
        if (!$employees) throw new DomainException('No active employees were employed in ' . bs_month_name($bsMonth) . " {$bsYear}. Add employees first.");

        $pdo->prepare('INSERT INTO acc_payroll_runs (bs_year, bs_month, fiscal_year, period_start, period_end, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$bsYear, $bsMonth, fiscal_year_for($start), $start, $end, $userId]);
        $runId = (int)$pdo->lastInsertId();

        $cfg = payroll_config();
        foreach ($employees as $emp) {
            $calc = calculate_payslip($emp, ['days_paid' => $emp['default_days']], $cfg);
            write_payslip($runId, (int)$emp['id'], $calc['slip']);
        }
        audit('create_payroll', 'payroll_run', $runId, bs_month_name($bsMonth) . " {$bsYear}");
        return $runId;
    });
}

/**
 * Recalculates a draft run from the edit grid. $inputs[employee_id] = [days_paid, overtime, …, remove].
 * Employees who became eligible since the run was created are added.
 * @return string[] errors (nothing is saved if any)
 */
function update_payroll_run(array $run, array $inputs): array {
    if ($run['status'] !== 'draft') return ['Only draft payroll can be edited.'];
    $cfg = payroll_config();
    $employees = eligible_employees($run['period_start'], $run['period_end']);
    $existing = array_column(load_payslips((int)$run['id']), null, 'employee_id');

    $errors = [];
    $results = [];
    foreach ($existing + $employees as $empId => $_) {
        $emp = $employees[$empId] ?? null;
        if (!$emp) { // no longer eligible (deactivated / dates changed) — use their record anyway so the draft stays editable
            $stmt = db()->prepare('SELECT * FROM acc_employees WHERE id = ?');
            $stmt->execute([$empId]);
            $emp = $stmt->fetch();
        }
        $in = $inputs[$empId] ?? null;
        if ($in !== null && !empty($in['remove'])) { $results[$empId] = null; continue; }
        if ($in === null) {
            $in = isset($existing[$empId])
                ? ['days_paid' => $existing[$empId]['days_paid']] + array_map(fn($c) => cents_to_decimal($c), array_intersect_key($existing[$empId], array_flip(['overtime', 'bonus', 'other_earnings', 'advance_recovery'])))
                : ['days_paid' => $emp['default_days'] ?? standard_days($cfg)];
        }
        $calc = calculate_payslip($emp, $in, $cfg);
        $errors = [...$errors, ...$calc['errors']];
        $results[$empId] = ['slip' => $calc['slip'], 'note' => trim((string)($in['note'] ?? ($existing[$empId]['note'] ?? ''))) ?: null];
    }
    if ($errors) return $errors;

    in_transaction(function (PDO $pdo) use ($run, $results) {
        foreach ($results as $empId => $r) {
            if ($r === null) $pdo->prepare('DELETE FROM acc_payslips WHERE run_id = ? AND employee_id = ?')->execute([$run['id'], $empId]);
            else write_payslip((int)$run['id'], $empId, $r['slip'], $r['note'] ? mb_substr($r['note'], 0, 255) : null);
        }
    });
    audit('update_payroll', 'payroll_run', (int)$run['id'], payroll_period_label($run));
    return [];
}

/** Posts the salary accrual voucher for a draft run. */
function post_payroll_run(array $run, int $userId, string $postDate): string {
    if ($run['status'] !== 'draft') throw new DomainException('Only draft payroll can be posted.');
    if (!valid_ad_date($postDate)) throw new DomainException('Enter a valid posting date.');
    $slips = load_payslips((int)$run['id']);
    if (!$slips) throw new DomainException('This payroll has no payslips.');
    $t = payslip_totals($slips);
    if ($t['gross_pay'] <= 0) throw new DomainException('Total gross pay is zero — nothing to post.');
    $period = payroll_period_label($run);

    // Expense lines split by project so salary shows up in project costing.
    $gross = [];
    foreach ($slips as $s) {
        $key = (int)($s['project_id'] ?? 0);
        $gross[$key] = ($gross[$key] ?? 0) + $s['gross_pay'];
    }
    $lines = [];
    foreach ($gross as $pid => $amt) if ($amt) $lines[] = ['account_id' => system_account_id('salary_expense'), 'debit' => cents_to_decimal($amt), 'project_id' => $pid ?: '', 'description' => "Salaries {$period}"];
    $credits = [
        ['tds_payable', $t['tds'], 'Salary TDS'],
        ['staff_advances', $t['advance_recovery'], 'Advances recovered'],
        ['salaries_payable', $t['net_pay'], 'Net salaries payable'],
    ];
    foreach ($credits as [$key, $amt, $desc]) if ($amt) $lines[] = ['account_id' => system_account_id($key), 'credit' => cents_to_decimal($amt), 'description' => $desc];

    return in_transaction(function () use ($run, $userId, $postDate, $period, $lines) {
        $result = save_journal_entry(['entry_date' => $postDate, 'narration' => "Payroll {$period}", 'reference' => "Payroll {$period}", 'lines' => $lines],
            $userId, null, true, 'payroll', (int)$run['id']);
        if ($result['errors']) throw new DomainException(implode(' ', $result['errors']));
        db()->prepare("UPDATE acc_payroll_runs SET status = 'posted', journal_entry_id = ? WHERE id = ? AND status = 'draft'")->execute([$result['id'], $run['id']]);
        audit('post_payroll', 'payroll_run', (int)$run['id'], $period);
        return (string)db()->query('SELECT voucher_no FROM acc_journal_entries WHERE id = ' . (int)$result['id'])->fetchColumn();
    });
}

// payment_account_options() lives in cashbank.php (every registered cash/bank/wallet account).


function pay_payroll_run(array $run, int $userId, string $payDate, int $accountId, string $reference = ''): string {
    if ($run['status'] !== 'posted') throw new DomainException('Only posted payroll can be marked as paid.');
    if (!valid_ad_date($payDate)) throw new DomainException('Enter a valid payment date.');
    if (!isset(payment_account_options()[$accountId])) throw new DomainException('Choose the cash or bank account the salaries were paid from.');
    $net = payslip_totals(load_payslips((int)$run['id']))['net_pay'];
    $period = payroll_period_label($run);

    return in_transaction(function () use ($run, $userId, $payDate, $accountId, $reference, $net, $period) {
        $result = save_journal_entry([
            'entry_date' => $payDate, 'narration' => "Salary payment {$period}", 'reference' => $reference ?: "Payroll {$period}",
            'lines' => [
                ['account_id' => system_account_id('salaries_payable'), 'debit' => cents_to_decimal($net), 'description' => "Net salaries {$period}"],
                ['account_id' => $accountId, 'credit' => cents_to_decimal($net), 'description' => "Net salaries {$period}"],
            ],
        ], $userId, null, true, 'payroll_payment', (int)$run['id']);
        if ($result['errors']) throw new DomainException(implode(' ', $result['errors']));
        db()->prepare("UPDATE acc_payroll_runs SET status = 'paid', payment_entry_id = ? WHERE id = ? AND status = 'posted'")->execute([$result['id'], $run['id']]);
        audit('pay_payroll', 'payroll_run', (int)$run['id'], $period);
        return (string)db()->query('SELECT voucher_no FROM acc_journal_entries WHERE id = ' . (int)$result['id'])->fetchColumn();
    });
}

/** Voids a posted/paid run and its vouchers so the month can be run again. */
function void_payroll_run(array $run, int $userId, string $reason): void {
    if (!in_array($run['status'], ['posted', 'paid'], true)) throw new DomainException('Only posted or paid payroll can be voided.');
    if (trim($reason) === '') throw new DomainException('Give a reason for voiding.');
    in_transaction(function () use ($run, $userId, $reason) {
        if ($run['payment_entry_id']) void_journal_entry((int)$run['payment_entry_id'], $userId, "Payroll voided: {$reason}", true);
        if ($run['journal_entry_id']) void_journal_entry((int)$run['journal_entry_id'], $userId, "Payroll voided: {$reason}", true);
        db()->prepare("UPDATE acc_payroll_runs SET status = 'void', notes = ? WHERE id = ?")->execute([mb_substr("Voided: {$reason}", 0, 255), $run['id']]);
        audit('void_payroll', 'payroll_run', (int)$run['id'], payroll_period_label($run) . ": {$reason}");
    });
}

// ── Payslip emails ───────────────────────────────────────────────────
/**
 * Subject, HTML and plain-text body of one employee's payslip email.
 * $s is a row from load_payslips() (money in paisa).
 * @return array{subject: string, html: string, text: string}
 */
function payslip_email_content(array $s, array $run): array {
    $company = setting('company_name', 'JEIWS');
    $period = payroll_period_label($run);
    $days = rtrim(rtrim((string)$s['days_paid'], '0'), '.');
    $rs = fn(int $c) => money_cents($c, true);

    $earnings = array_filter([
        'Basic salary (' . $days . ' of ' . (int)$s['standard_days'] . ' days)' => $s['basic_pay'],
        'Allowance (' . $days . ' days × ' . money_cents($s['allowance_rate'], true) . ')' => $s['allowance_pay'],
        'Overtime' => $s['overtime'], 'Bonus / festival allowance' => $s['bonus'], 'Other earnings' => $s['other_earnings'],
    ]);
    $deductions = array_filter(['Income tax (TDS)' => $s['tds'], 'Advance recovery' => $s['advance_recovery']]);

    $row = fn(string $label, string $amount, bool $bold = false) =>
        '<tr><td style="padding:6px 0;border-bottom:1px solid #eee;' . ($bold ? 'font-weight:700;' : '') . '">' . e($label) . '</td>'
        . '<td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;' . ($bold ? 'font-weight:700;' : '') . '">' . e($amount) . '</td></tr>';
    $table = function (string $title, array $items, string $totalLabel, int $total) use ($row, $rs) {
        $html = '<h3 style="font-size:14px;margin:20px 0 6px;color:#1B6799">' . e($title) . '</h3><table style="width:100%;border-collapse:collapse;font-size:14px">';
        foreach ($items as $label => $amount) $html .= $row($label, $rs($amount));
        if (!$items) $html .= $row('None', $rs(0));
        return $html . $row($totalLabel, $rs($total), true) . '</table>';
    };

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1A2433">'
        . '<div style="border-bottom:3px solid #1B6799;padding-bottom:10px;margin-bottom:16px">'
        . '<div style="font-size:18px;font-weight:700">' . e($company) . '</div>'
        . '<div style="font-size:13px;color:#6B7686">Payslip — ' . e($period) . ' (FY ' . e($run['fiscal_year']) . ')</div></div>'
        . '<p style="font-size:14px">Dear ' . e($s['full_name']) . ',</p>'
        . '<p style="font-size:14px">Your salary for <strong>' . e($period) . '</strong> has been paid. Your payslip is below.</p>'
        . '<table style="width:100%;font-size:13px;color:#6B7686"><tr><td>Employee code: ' . e($s['employee_code']) . '</td><td style="text-align:right">'
        . e(trim(($s['designation'] ?? '') . ($s['department'] ? ', ' . $s['department'] : ''))) . '</td></tr></table>'
        . $table('Earnings', $earnings, 'Gross pay', $s['gross_pay'])
        . $table('Deductions', $deductions, 'Total deductions', $s['tds'] + $s['advance_recovery'])
        // Tables, not flexbox — many email clients ignore modern CSS.
        . '<table style="width:100%;margin-top:18px;background:#E8F2FA;border-radius:8px;font-size:16px;font-weight:700"><tr>'
        . '<td style="padding:14px">Net pay</td><td style="padding:14px;text-align:right">' . e($rs($s['net_pay'])) . '</td></tr></table>'
        . ($s['bank_account'] ? '<p style="font-size:13px;color:#6B7686">Paid to ' . e($s['bank_name'] ?: 'your bank') . ' a/c ' . e($s['bank_account']) . '.</p>' : '')
        . '<p style="font-size:12px;color:#6B7686;margin-top:24px">This is a confidential, computer-generated payslip for you only. '
        . 'If anything looks wrong, reply to this email or contact the accounts department.</p></div>';

    $lines = ["Dear {$s['full_name']},", '', "Your salary for {$period} has been paid.", '', 'EARNINGS'];
    foreach ($earnings as $label => $amount) $lines[] = "  {$label}: " . $rs($amount);
    $lines[] = '  Gross pay: ' . $rs($s['gross_pay']);
    $lines[] = '';
    $lines[] = 'DEDUCTIONS';
    foreach ($deductions as $label => $amount) $lines[] = "  {$label}: " . $rs($amount);
    $lines[] = '';
    $lines[] = 'NET PAY: ' . $rs($s['net_pay']);
    $lines[] = '';
    $lines[] = "{$company} — confidential payslip.";

    return ['subject' => "Your payslip for {$period} — {$company}", 'html' => $html, 'text' => implode("\n", $lines)];
}

/**
 * Emails each employee their own payslip for a paid run.
 * $employeeIds limits it to those employees; $onlyUnsent skips payslips already sent.
 * @return array{sent: int, failed: int, no_email: int}
 */
function email_payslips(array $run, ?array $employeeIds = null, bool $onlyUnsent = false): array {
    if ($run['status'] !== 'paid') throw new DomainException('Payslips are emailed once the payroll is marked paid.');
    require_once __DIR__ . '/mailer.php';
    @set_time_limit(300);

    $result = ['sent' => 0, 'failed' => 0, 'no_email' => 0];
    $mark = db()->prepare('UPDATE acc_payslips SET emailed_at = ?, email_error = ? WHERE id = ?');
    foreach (load_payslips((int)$run['id']) as $s) {
        if ($employeeIds !== null && !in_array((int)$s['employee_id'], $employeeIds, true)) continue;
        if ($onlyUnsent && $s['emailed_at']) continue;
        if (!$s['email']) {
            $mark->execute([$s['emailed_at'], 'No email address on the employee record.', $s['id']]);
            $result['no_email']++;
            continue;
        }
        $content = payslip_email_content($s, $run);
        $sent = acc_send_mail($s['email'], $s['full_name'], $content['subject'], $content['html'], $content['text']);
        if ($sent['ok']) {
            $mark->execute([date('Y-m-d H:i:s'), null, $s['id']]);
            $result['sent']++;
        } else {
            $mark->execute([$s['emailed_at'], mb_substr($sent['error'], 0, 255), $s['id']]);
            $result['failed']++;
        }
    }
    audit('email_payslips', 'payroll_run', (int)$run['id'], "sent {$result['sent']}, failed {$result['failed']}, no email {$result['no_email']}");
    return $result;
}

function email_result_message(array $r): string {
    $parts = [];
    if ($r['sent'])     $parts[] = "{$r['sent']} payslip" . ($r['sent'] === 1 ? '' : 's') . ' emailed';
    if ($r['failed'])   $parts[] = "{$r['failed']} could not be sent";
    if ($r['no_email']) $parts[] = "{$r['no_email']} employee" . ($r['no_email'] === 1 ? ' has' : 's have') . ' no email address';
    return $parts ? ucfirst(implode(', ', $parts)) . '.' : 'No payslips needed sending.';
}

function payroll_status_badge(string $status): string {
    return match ($status) {
        'draft'  => '<span class="badge text-bg-warning">Draft</span>',
        'posted' => '<span class="badge text-bg-primary">Posted · unpaid</span>',
        'paid'   => '<span class="badge text-bg-success">Paid</span>',
        'void'   => '<span class="badge text-bg-secondary">Void</span>',
        default  => e($status),
    };
}
