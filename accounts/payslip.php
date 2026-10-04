<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT s.*, r.bs_year, r.bs_month, r.fiscal_year, r.status AS run_status, r.period_start, r.period_end,
            e.employee_code, e.full_name, e.designation, e.department, e.pan_number, e.bank_name, e.bank_account
     FROM acc_payslips s JOIN acc_payroll_runs r ON r.id = s.run_id JOIN acc_employees e ON e.id = s.employee_id
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$s = $stmt->fetch();
if (!$s) { flash('warning', 'Payslip not found.'); redirect('payroll.php'); }
foreach (PAYSLIP_MONEY_FIELDS as $f) $s[$f] = decimal_to_cents($s[$f]);
$period = bs_month_name((int)$s['bs_month']) . ' ' . $s['bs_year'];

$earnings = array_filter([
    'Basic salary' => $s['basic_pay'], 'Allowance (' . rtrim(rtrim((string)$s['days_paid'], '0'), '.') . ' days × ' . money($s['allowance_rate']) . ')' => $s['allowance_pay'], 'Overtime' => $s['overtime'],
    'Bonus / festival allowance' => $s['bonus'], 'Other earnings' => $s['other_earnings'],
]);
$deductions = array_filter([
    'Income tax (TDS)' => $s['tds'], 'Advance recovery' => $s['advance_recovery'],
]);
$totalDeductions = array_sum($deductions);

$pageTitle   = "Payslip · {$s['full_name']} · {$period}";
$activeNav   = 'payroll';
$breadcrumbs = [['label' => 'Payroll', 'href' => 'payroll.php'], ['label' => $period, 'href' => 'payroll-run.php?id=' . (int)$s['run_id']], ['label' => $s['full_name']]];
$pageActions = '<button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print payslip</button>';
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($s['run_status'] === 'draft'): ?><div class="alert alert-warning">This payroll is still a draft — figures may change.</div><?php endif ?>
<?php if ($s['run_status'] === 'void'): ?><div class="alert alert-secondary">This payroll was voided.</div><?php endif ?>

<div class="acc-card" style="max-width:820px">
    <div class="acc-card-body">
        <div class="d-flex justify-content-between flex-wrap gap-3 border-bottom pb-3 mb-3">
            <div>
                <div class="fw-bold fs-5"><?= e(setting('company_name')) ?></div>
                <div class="small text-body-secondary"><?= e(setting('company_address')) ?><?= setting('pan_number') ? ' · PAN ' . e(setting('pan_number')) : '' ?></div>
            </div>
            <div class="text-end">
                <div class="fw-bold text-uppercase" style="letter-spacing:.08em">Payslip</div>
                <div><?= e($period) ?></div>
                <div class="small text-body-secondary"><?= e(bs_date($s['period_start'])) ?> – <?= e(bs_date($s['period_end'])) ?> B.S. · FY <?= e($s['fiscal_year']) ?></div>
            </div>
        </div>

        <div class="row small mb-3 g-2">
            <div class="col-sm-6"><span class="text-body-secondary">Employee:</span> <strong><?= e($s['full_name']) ?></strong> (<?= e($s['employee_code']) ?>)</div>
            <div class="col-sm-6"><span class="text-body-secondary">Designation:</span> <?= e(trim(($s['designation'] ?? '') . ($s['department'] ? ', ' . $s['department'] : '')) ?: '—') ?></div>
            <div class="col-sm-6"><span class="text-body-secondary">PAN:</span> <?= e($s['pan_number'] ?: '—') ?></div>
            <div class="col-sm-6"><span class="text-body-secondary">Days present:</span> <?= e(rtrim(rtrim((string)$s['days_paid'], '0'), '.')) ?> of <?= (int)$s['standard_days'] ?></div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Earnings</th><th class="num">Amount</th></tr></thead>
                    <tbody><?php foreach ($earnings as $label => $amt): ?><tr><td><?= e($label) ?></td><td class="num"><?= e(money_cents($amt)) ?></td></tr><?php endforeach ?></tbody>
                    <tfoot><tr class="table-subtotal"><td>Gross pay</td><td class="num"><?= e(money_cents($s['gross_pay'])) ?></td></tr></tfoot>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Deductions</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($deductions as $label => $amt): ?><tr><td><?= e($label) ?></td><td class="num"><?= e(money_cents($amt)) ?></td></tr><?php endforeach ?>
                        <?php if (!$deductions): ?><tr><td colspan="2" class="text-body-secondary">None</td></tr><?php endif ?>
                    </tbody>
                    <tfoot><tr class="table-subtotal"><td>Total deductions</td><td class="num"><?= e(money_cents($totalDeductions)) ?></td></tr></tfoot>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 p-3 rounded" style="background:var(--acc-blue-pale)">
            <span class="fw-bold">Net pay</span>
            <span class="fw-bold fs-4"><?= e(money_cents($s['net_pay'], true)) ?></span>
        </div>
        <div class="small text-body-secondary mt-2">
            <?php if ($s['bank_account']): ?>Paid to <?= e($s['bank_name'] ?? 'bank') ?> a/c <?= e($s['bank_account']) ?>. <?php endif ?>
            <?php if ($s['note']): ?><br>Note: <?= e($s['note']) ?><?php endif ?>
        </div>

        <div class="d-flex justify-content-between mt-5 pt-4 small text-body-secondary">
            <span style="border-top:1px solid var(--acc-border);padding-top:4px;min-width:180px">Prepared by</span>
            <span style="border-top:1px solid var(--acc-border);padding-top:4px;min-width:180px;text-align:right">Employee signature</span>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
