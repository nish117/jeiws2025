<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * VAT, TDS and income tax. Everything is derived from posted vouchers:
 *
 *  VAT (monthly, due the 25th of the next B.S. month)
 *    Output VAT 2140 (credit) and Input VAT 1180 (debit). Settling a month
 *    posts: Dr Output VAT (its balance), Cr Input VAT (up to that amount),
 *    Cr Bank (the difference, if output > input). Unused input credit stays
 *    in 1180 and is carried forward automatically.
 *  TDS (monthly, due the 25th of the next B.S. month)
 *    TDS Payable 2150 is credited by supplier bills and payroll; depositing
 *    posts Dr TDS Payable, Cr Bank. TDS withheld by clients sits in 1185.
 *  Income tax (fiscal year)
 *    Estimated taxable profit × rate; advance tax by Poush end (40%),
 *    Chaitra end (70%) and Ashadh end (100%), less client TDS credit.
 *    Payments: Dr Advance Income Tax 1186, Cr Bank. Year-end provision:
 *    Dr Income Tax Expense 6960, Cr Income Tax Payable 2180.
 */

const INCOME_TAX_INSTALMENTS = [
    1 => ['label' => 'First instalment',  'month' => 9,  'next_year' => false, 'percent' => 40],   // Poush end
    2 => ['label' => 'Second instalment', 'month' => 12, 'next_year' => false, 'percent' => 70],   // Chaitra end
    3 => ['label' => 'Final instalment',  'month' => 3,  'next_year' => true,  'percent' => 100],  // Ashadh end
];

// ── B.S. periods ─────────────────────────────────────────────────────
/** [startAD, endAD] of a B.S. month. */
function bs_month_bounds(int $year, int $month): array {
    $days = NepaliDate::daysInMonth($year, $month);
    if (!$days) throw new DomainException('That month is outside the supported calendar.');
    return [NepaliDate::bsToAd($year, $month, 1), NepaliDate::bsToAd($year, $month, $days)];
}

/** The 12 B.S. months of a fiscal year label like "2083/84", Shrawan → Ashadh, as [year, month]. */
function fiscal_year_months(string $fy): array {
    $start = (int)substr($fy, 0, 4);
    $out = [];
    for ($m = 4; $m <= 12; $m++) $out[] = [$start, $m];
    for ($m = 1; $m <= 3; $m++) $out[] = [$start + 1, $m];
    return $out;
}

function period_key(int $y, int $m): string { return sprintf('%04d-%02d', $y, $m); }

function parse_period(string $key): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $key, $mm)) return null;
    [$y, $m] = [(int)$mm[1], (int)$mm[2]];
    return ($m >= 1 && $m <= 12 && NepaliDate::daysInMonth($y, $m)) ? [$y, $m] : null;
}

function period_label(int $y, int $m): string { return bs_month_name($m) . ' ' . $y; }

/** Monthly VAT and TDS are due on the 25th of the following B.S. month. */
function monthly_tax_due_date(int $y, int $m): string {
    [$ny, $nm] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
    return NepaliDate::bsToAd($ny, $nm, 25);
}

function current_bs_period(): array {
    [$y, $m] = array_map('intval', explode('-', bs_date(date('Y-m-d'))));
    return [$y, $m];
}

/** Most recent fully finished B.S. month — the one normally being filed. */
function last_finished_bs_period(): array {
    [$y, $m] = current_bs_period();
    return $m === 1 ? [$y - 1, 12] : [$y, $m - 1];
}

// ── Ledger helpers ───────────────────────────────────────────────────
/** Posted Dr and Cr (paisa) on an account between two dates, optionally ignoring some voucher sources. */
function account_movement(int $accountId, ?string $from, string $to, array $excludeSources = []): array {
    $sql = "SELECT COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
            WHERE e.status = 'posted' AND l.account_id = ? AND e.entry_date <= ?";
    $params = [$accountId, $to];
    if ($from) { $sql .= ' AND e.entry_date >= ?'; $params[] = $from; }
    if ($excludeSources) { $sql .= ' AND e.source NOT IN (' . implode(',', array_fill(0, count($excludeSources), '?')) . ')'; $params = [...$params, ...$excludeSources]; }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $r = $stmt->fetch();
    return ['dr' => decimal_to_cents($r['dr']), 'cr' => decimal_to_cents($r['cr'])];
}

// ── Filings ──────────────────────────────────────────────────────────
function tax_filings(string $tax, string $fy): array {
    $stmt = db()->prepare(
        'SELECT f.*, j.voucher_no, a.name AS account_name, u.full_name AS created_by_name FROM acc_tax_filings f
         LEFT JOIN acc_journal_entries j ON j.id = f.journal_entry_id LEFT JOIN acc_accounts a ON a.id = f.account_id
         LEFT JOIN acc_users u ON u.id = f.created_by WHERE f.tax = ? AND f.fiscal_year = ? ORDER BY f.filing_date, f.id'
    );
    $stmt->execute([$tax, $fy]);
    return $stmt->fetchAll();
}

function active_filing(string $tax, string $period): ?array {
    $stmt = db()->prepare("SELECT f.*, j.voucher_no FROM acc_tax_filings f LEFT JOIN acc_journal_entries j ON j.id = f.journal_entry_id WHERE f.tax = ? AND f.period = ? AND f.status = 'active' ORDER BY f.id DESC LIMIT 1");
    $stmt->execute([$tax, $period]);
    return $stmt->fetch() ?: null;
}

/** Posts the voucher (if there are lines) and records the filing. Returns the filing id. */
function record_tax_filing(string $tax, string $fy, string $period, string $date, int $amount, ?int $accountId, string $reference, string $notes,
                           array $lines, string $source, string $narration, int $userId): int {
    return in_transaction(function (PDO $pdo) use ($tax, $fy, $period, $date, $amount, $accountId, $reference, $notes, $lines, $source, $narration, $userId) {
        $jeId = null;
        if ($lines) {
            $r = save_journal_entry(['entry_date' => $date, 'narration' => mb_substr($narration, 0, 500), 'reference' => mb_substr($reference, 0, 100), 'lines' => $lines],
                $userId, null, true, $source, null);
            if ($r['errors']) throw new DomainException(implode(' ', $r['errors']));
            $jeId = $r['id'];
        }
        $pdo->prepare('INSERT INTO acc_tax_filings (tax, fiscal_year, period, amount, filing_date, account_id, reference, notes, journal_entry_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tax, $fy, $period, cents_to_decimal($amount), $date, $accountId, $reference ?: null, $notes ?: null, $jeId, $userId]);
        $id = (int)$pdo->lastInsertId();
        if ($jeId) $pdo->prepare('UPDATE acc_journal_entries SET source_id = ? WHERE id = ?')->execute([$id, $jeId]);
        audit('tax_filing_' . $tax, 'tax_filing', $id, "{$period} " . money_cents($amount));
        return $id;
    });
}

function void_tax_filing(int $id, int $userId, string $reason): array {
    if (trim($reason) === '') throw new DomainException('Give a reason.');
    $stmt = db()->prepare("SELECT * FROM acc_tax_filings WHERE id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) throw new DomainException('Filing not found.');
    if ($f['tax'] === 'vat') {
        // Later VAT settlements were computed from balances that include this one.
        $later = db()->prepare("SELECT COUNT(*) FROM acc_tax_filings WHERE tax = 'vat' AND status = 'active' AND period > ?");
        $later->execute([$f['period']]);
        if ($later->fetchColumn()) throw new DomainException('Void the later months\' VAT settlements first (newest first).');
    }
    in_transaction(function (PDO $pdo) use ($f, $userId, $reason) {
        if ($f['journal_entry_id']) void_journal_entry((int)$f['journal_entry_id'], $userId, "Tax filing voided: {$reason}", true);
        $pdo->prepare("UPDATE acc_tax_filings SET status = 'void', notes = LEFT(CONCAT(COALESCE(notes, ''), ' [void: ', ?, ']'), 255) WHERE id = ?")->execute([$reason, $f['id']]);
        audit('void_tax_filing', 'tax_filing', (int)$f['id'], "{$f['tax']} {$f['period']}: {$reason}");
    });
    return $f;
}

// ── VAT ──────────────────────────────────────────────────────────────
/** Sales and purchase registers (posted, non-void invoices) for a B.S. month. */
function vat_registers(int $y, int $m): array {
    [$from, $to] = bs_month_bounds($y, $m);
    $stmt = db()->prepare(
        "SELECT i.id, i.type, i.number, i.invoice_date, i.taxable_amount, i.exempt_amount, i.vat_amount, i.total_amount,
                c.name AS party, c.pan_number AS pan
         FROM acc_invoices i JOIN acc_contacts c ON c.id = i.contact_id
         WHERE i.status IN ('posted','paid') AND i.invoice_date BETWEEN ? AND ? ORDER BY i.invoice_date, i.id"
    );
    $stmt->execute([$from, $to]);
    $out = ['sales' => [], 'purchase' => []];
    foreach ($stmt->fetchAll() as $r) $out[$r['type']][] = $r;
    return $out;
}

/**
 * VAT return figures for a B.S. month.
 *  output/input: this month's VAT from the ledger (settlement vouchers excluded)
 *  from_invoices / adjustments: how much came from invoices vs manual vouchers
 *  balances at month end → what settling now would set off, pay, or carry forward.
 */
function vat_summary(int $y, int $m): array {
    [$from, $to] = bs_month_bounds($y, $m);
    $outId = system_account_id('vat_output');
    $inId = system_account_id('vat_input');
    $o = account_movement($outId, $from, $to, ['vat_settlement']);
    $i = account_movement($inId, $from, $to, ['vat_settlement']);
    $output = $o['cr'] - $o['dr'];
    $input = $i['dr'] - $i['cr'];

    $reg = vat_registers($y, $m);
    $invOut = array_sum(array_map(fn($r) => decimal_to_cents($r['vat_amount']), $reg['sales']));
    $invIn = array_sum(array_map(fn($r) => decimal_to_cents($r['vat_amount']), $reg['purchase']));

    $ob = account_movement($outId, null, $to);
    $ib = account_movement($inId, null, $to);
    $outputBalance = $ob['cr'] - $ob['dr'];   // unpaid output VAT up to month end (incl. earlier months)
    $inputBalance = $ib['dr'] - $ib['cr'];    // unused input credit up to month end (incl. carried forward)
    $setoff = max(0, min($outputBalance, $inputBalance));

    return [
        'from' => $from, 'to' => $to, 'due' => monthly_tax_due_date($y, $m),
        'output' => $output, 'input' => $input, 'net' => $output - $input,
        'invoice_output' => $invOut, 'invoice_input' => $invIn,
        'output_adjustment' => $output - $invOut, 'input_adjustment' => $input - $invIn,
        'output_balance' => $outputBalance, 'input_balance' => $inputBalance,
        'setoff' => $setoff, 'payable' => max(0, $outputBalance - $inputBalance), 'carry_forward' => max(0, $inputBalance - $outputBalance),
        'registers' => $reg,
    ];
}

function settle_vat(int $y, int $m, string $date, int $accountId, string $reference, int $userId): int {
    $key = period_key($y, $m);
    if (active_filing('vat', $key)) throw new DomainException('VAT for ' . period_label($y, $m) . ' is already settled.');
    [$from, $to] = bs_month_bounds($y, $m);
    if (!valid_ad_date($date)) throw new DomainException('Enter the filing date.');
    if ($date <= $to) throw new DomainException('Settle after the month has ended (from ' . bs_date(date('Y-m-d', strtotime($to . ' +1 day'))) . ' B.S.).');
    // Months must be settled in order, or balances would be counted twice.
    $fy = fiscal_year_for($from);
    foreach (fiscal_year_months($fy) as [$py, $pm]) {
        if (period_key($py, $pm) >= $key) break;
        [$pf, $pt] = bs_month_bounds($py, $pm);
        $o = account_movement(system_account_id('vat_output'), $pf, $pt, ['vat_settlement']);
        $i = account_movement(system_account_id('vat_input'), $pf, $pt, ['vat_settlement']);
        $active = $o['dr'] || $o['cr'] || $i['dr'] || $i['cr'];
        if ($active && !active_filing('vat', period_key($py, $pm))) throw new DomainException('Settle ' . period_label($py, $pm) . ' first — months are settled in order.');
    }
    $s = vat_summary($y, $m);
    $lines = [];
    if ($s['output_balance'] > 0) {
        $lines[] = ['account_id' => system_account_id('vat_output'), 'debit' => cents_to_decimal($s['output_balance']), 'description' => 'Output VAT set off'];
        if ($s['setoff']) $lines[] = ['account_id' => system_account_id('vat_input'), 'credit' => cents_to_decimal($s['setoff']), 'description' => 'Input VAT set off'];
        if ($s['payable']) {
            if (!isset(payment_account_options()[$accountId])) throw new DomainException('Choose the bank account the VAT was paid from.');
            $lines[] = ['account_id' => $accountId, 'credit' => cents_to_decimal($s['payable']), 'description' => 'VAT paid to IRD'];
        }
    }
    return record_tax_filing('vat', $fy, $key, $date, $s['payable'], $s['payable'] ? $accountId : null, $reference,
        $s['payable'] ? '' : ($s['carry_forward'] ? 'Credit carried forward ' . money_cents($s['carry_forward']) : 'Nil return'),
        $lines, 'vat_settlement', 'VAT return ' . period_label($y, $m), $userId);
}

// ── TDS ──────────────────────────────────────────────────────────────
/**
 * TDS withheld by us in a B.S. month, one row per payee, from the TDS Payable ledger:
 * supplier bills (with bill no. and base), payroll (per employee) and any manual vouchers.
 */
function tds_withheld_rows(int $y, int $m): array {
    [$from, $to] = bs_month_bounds($y, $m);
    $stmt = db()->prepare(
        "SELECT l.credit, l.debit, l.contact_id, e.id AS entry_id, e.voucher_no, e.entry_date, e.source, e.source_id, e.narration
         FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
         WHERE e.status = 'posted' AND l.account_id = ? AND e.entry_date BETWEEN ? AND ? AND e.source <> 'tds_deposit'
         ORDER BY e.entry_date, e.id"
    );
    $stmt->execute([system_account_id('tds_payable'), $from, $to]);
    $rows = [];
    foreach ($stmt->fetchAll() as $l) {
        $amount = decimal_to_cents($l['credit']) - decimal_to_cents($l['debit']);
        if ($l['source'] === 'purchase_bill') {
            $inv = db()->prepare('SELECT i.number, i.taxable_amount, i.exempt_amount, i.tds_percent, c.name, c.pan_number, c.tds_category FROM acc_invoices i JOIN acc_contacts c ON c.id = i.contact_id WHERE i.id = ?');
            $inv->execute([$l['source_id']]);
            $b = $inv->fetch();
            $rows[] = ['date' => $l['entry_date'], 'payee' => $b['name'], 'pan' => $b['pan_number'], 'document' => 'Bill ' . $b['number'],
                'category' => $b['tds_category'] ?: 'other', 'base' => decimal_to_cents($b['taxable_amount']) + decimal_to_cents($b['exempt_amount']),
                'rate' => (float)$b['tds_percent'], 'tds' => $amount, 'entry_id' => $l['entry_id'], 'voucher' => $l['voucher_no']];
        } elseif ($l['source'] === 'payroll') {
            $ps = db()->prepare('SELECT e.full_name, e.pan_number, s.gross_pay, s.tds FROM acc_payslips s JOIN acc_employees e ON e.id = s.employee_id WHERE s.run_id = ? AND s.tds > 0 ORDER BY e.full_name');
            $ps->execute([$l['source_id']]);
            foreach ($ps->fetchAll() as $p) {
                $rows[] = ['date' => $l['entry_date'], 'payee' => $p['full_name'], 'pan' => $p['pan_number'], 'document' => 'Salary',
                    'category' => 'salary', 'base' => decimal_to_cents($p['gross_pay']), 'rate' => null, 'tds' => decimal_to_cents($p['tds']),
                    'entry_id' => $l['entry_id'], 'voucher' => $l['voucher_no']];
            }
        } else {
            $c = $l['contact_id'] ? (contact_options()[(int)$l['contact_id']] ?? null) : null;
            $pan = null;
            if ($c) { $p = db()->prepare('SELECT pan_number FROM acc_contacts WHERE id = ?'); $p->execute([$c['id']]); $pan = $p->fetchColumn() ?: null; }
            $rows[] = ['date' => $l['entry_date'], 'payee' => $c['name'] ?? $l['narration'], 'pan' => $pan, 'document' => 'Voucher',
                'category' => 'other', 'base' => null, 'rate' => null, 'tds' => $amount, 'entry_id' => $l['entry_id'], 'voucher' => $l['voucher_no']];
        }
    }
    return $rows;
}

function tds_summary(int $y, int $m): array {
    [$from, $to] = bs_month_bounds($y, $m);
    $rows = tds_withheld_rows($y, $m);
    $byCategory = [];
    foreach ($rows as $r) $byCategory[$r['category']] = ($byCategory[$r['category']] ?? 0) + $r['tds'];
    $bal = account_movement(system_account_id('tds_payable'), null, $to);
    return [
        'from' => $from, 'to' => $to, 'due' => monthly_tax_due_date($y, $m), 'rows' => $rows,
        'withheld' => array_sum(array_column($rows, 'tds')), 'by_category' => $byCategory,
        'balance_at_end' => $bal['cr'] - $bal['dr'],
    ];
}

function deposit_tds(int $y, int $m, string $date, string $amountRaw, int $accountId, string $reference, int $userId): int {
    $key = period_key($y, $m);
    if (active_filing('tds', $key)) throw new DomainException('TDS for ' . period_label($y, $m) . ' is already deposited.');
    if (!valid_ad_date($date)) throw new DomainException('Enter the deposit date.');
    if (!isset(payment_account_options()[$accountId])) throw new DomainException('Choose the bank account.');
    $amount = to_cents($amountRaw);
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the amount deposited.');
    $s = tds_summary($y, $m);
    if ($amount > $s['balance_at_end']) throw new DomainException('More than the ' . money_cents($s['balance_at_end'], true) . ' TDS payable at the end of ' . period_label($y, $m) . '.');
    $amt = cents_to_decimal($amount);
    return record_tax_filing('tds', fiscal_year_for($s['from']), $key, $date, $amount, $accountId, $reference, '',
        [['account_id' => system_account_id('tds_payable'), 'debit' => $amt, 'description' => 'TDS deposited'], ['account_id' => $accountId, 'credit' => $amt, 'description' => 'TDS deposited to IRD']],
        'tds_deposit', 'TDS deposit ' . period_label($y, $m), $userId);
}

/** TDS withheld from us by clients (sales invoices) in a fiscal year. */
function tds_receivable_rows(string $fy): array {
    [$from, $to] = fiscal_year_range($fy);
    $stmt = db()->prepare(
        "SELECT i.id, i.number, i.invoice_date, i.taxable_amount, i.exempt_amount, i.tds_percent, i.tds_amount, c.name, c.pan_number
         FROM acc_invoices i JOIN acc_contacts c ON c.id = i.contact_id
         WHERE i.type = 'sales' AND i.status IN ('posted','paid') AND i.tds_amount > 0 AND i.invoice_date BETWEEN ? AND ? ORDER BY i.invoice_date"
    );
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll();
}

// ── Income tax ───────────────────────────────────────────────────────
function income_tax_rate(): float { return (float)setting('income_tax_rate', '25'); }

/** Accounting profit for a fiscal year from the ledger (income − expenses, excluding income tax itself). */
function fiscal_year_profit(string $fy, ?string $upTo = null): array {
    [$from, $to] = fiscal_year_range($fy);
    if ($upTo && $upTo < $to) $to = $upTo;
    $taxExpense = system_account_id('income_tax_expense');
    $income = $expense = 0;
    foreach (account_totals($from, $to) as $id => $t) {
        $type = account_tree()[$id]['type'] ?? '';
        if ($type === 'income') $income += $t['credit'] - $t['debit'];
        if ($type === 'expense' && $id !== $taxExpense) $expense += $t['debit'] - $t['credit'];
    }
    return ['income' => $income, 'expense' => $expense, 'profit' => $income - $expense, 'from' => $from, 'to' => $to];
}

function income_tax_summary(string $fy): array {
    $actual = fiscal_year_profit($fy);
    $estimateSetting = setting('it_estimate_' . str_replace('/', '_', $fy));
    $estimate = $estimateSetting !== '' ? decimal_to_cents($estimateSetting) : max(0, $actual['profit']);
    $rate = income_tax_rate();
    $tax = round_rupee(max(0, $estimate) * $rate / 100);   // tax is assessed in whole rupees

    [$fyFrom, $fyTo] = fiscal_year_range($fy);
    $tdsCredit = account_movement(system_account_id('tds_receivable'), $fyFrom, $fyTo);
    $tdsCredit = $tdsCredit['dr'] - $tdsCredit['cr'];
    $filings = array_filter(tax_filings('income_tax', $fy), fn($f) => $f['status'] === 'active');
    $paid = 0;
    foreach ($filings as $f) if (str_starts_with($f['period'], 'instalment')) $paid += decimal_to_cents($f['amount']);
    $provision = null;
    foreach ($filings as $f) if ($f['period'] === 'provision') $provision = $f;

    $start = (int)substr($fy, 0, 4);
    $instalments = [];
    foreach (INCOME_TAX_INSTALMENTS as $n => $i) {
        $year = $i['next_year'] ? $start + 1 : $start;
        $due = NepaliDate::bsToAd($year, $i['month'], NepaliDate::daysInMonth($year, $i['month']));
        $cumulative = round_rupee($tax * $i['percent'] / 100);
        $instalments[$n] = $i + ['due' => $due, 'cumulative' => $cumulative, 'shortfall' => max(0, $cumulative - $paid - $tdsCredit)];
    }
    return [
        'actual' => $actual, 'estimate' => $estimate, 'estimate_is_custom' => $estimateSetting !== '', 'rate' => $rate, 'tax' => $tax,
        'tds_credit' => $tdsCredit, 'paid' => $paid, 'remaining' => max(0, $tax - $paid - $tdsCredit),
        'instalments' => $instalments, 'filings' => array_values($filings), 'provision' => $provision,
    ];
}

function pay_advance_tax(string $fy, int $instalment, string $date, string $amountRaw, int $accountId, string $reference, int $userId): int {
    if (!isset(INCOME_TAX_INSTALMENTS[$instalment])) throw new DomainException('Choose the instalment.');
    if (!valid_ad_date($date)) throw new DomainException('Enter the payment date.');
    if (!isset(payment_account_options()[$accountId])) throw new DomainException('Choose the bank account.');
    $amount = to_cents($amountRaw);
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the amount paid.');
    $amt = cents_to_decimal($amount);
    return record_tax_filing('income_tax', $fy, 'instalment-' . $instalment, $date, $amount, $accountId, $reference, '',
        [['account_id' => system_account_id('advance_income_tax'), 'debit' => $amt, 'description' => 'Advance tax ' . INCOME_TAX_INSTALMENTS[$instalment]['label']],
         ['account_id' => $accountId, 'credit' => $amt, 'description' => 'Advance income tax paid to IRD']],
        'income_tax', 'Advance income tax FY ' . $fy . ' — ' . INCOME_TAX_INSTALMENTS[$instalment]['label'], $userId);
}

function book_tax_provision(string $fy, string $date, string $amountRaw, int $userId): int {
    if (array_filter(tax_filings('income_tax', $fy), fn($f) => $f['period'] === 'provision' && $f['status'] === 'active')) {
        throw new DomainException('The provision for FY ' . $fy . ' is already booked. Void it first to rebook.');
    }
    if (!valid_ad_date($date)) throw new DomainException('Enter the date (usually the last day of the fiscal year).');
    $amount = to_cents($amountRaw);
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the provision amount.');
    $amt = cents_to_decimal($amount);
    return record_tax_filing('income_tax', $fy, 'provision', $date, $amount, null, '', '',
        [['account_id' => system_account_id('income_tax_expense'), 'debit' => $amt, 'description' => 'Income tax for FY ' . $fy],
         ['account_id' => system_account_id('income_tax_payable'), 'credit' => $amt, 'description' => 'Income tax payable FY ' . $fy]],
        'tax_provision', 'Income tax provision FY ' . $fy, $userId);
}

/** B.S. month picker options for a fiscal year (value = 'YYYY-MM'). */
function period_options(string $fy): array {
    $out = [];
    foreach (fiscal_year_months($fy) as [$y, $m]) $out[period_key($y, $m)] = period_label($y, $m);
    return $out;
}

/** A few fiscal years around today for selectors. */
function fiscal_year_options(): array {
    $cur = (int)substr(fiscal_year_for(date('Y-m-d')), 0, 4);
    $out = [];
    for ($y = $cur; $y >= $cur - 3; $y--) $out[] = sprintf('%d/%02d', $y, ($y + 1) % 100);
    return $out;
}

function filing_status_badge(?array $filing, string $due, bool $hasActivity): string {
    if ($filing) return '<span class="badge text-bg-success">Filed ' . e(bs_date($filing['filing_date'])) . '</span>';
    if (!$hasActivity) return '<span class="badge text-bg-light border">No activity</span>';
    if (date('Y-m-d') > $due) return '<span class="badge text-bg-danger">Overdue</span>';
    return '<span class="badge text-bg-warning">Due ' . e(bs_date($due)) . '</span>';
}
