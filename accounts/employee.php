<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM acc_employees WHERE id = ?');
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) { flash('warning', 'Employee not found.'); redirect('employees.php'); }

$cfg = payroll_config();
$monthly = regular_monthly_pay($emp, $cfg);
$annual = $monthly * 12;
$tax = annual_salary_tax($annual, $emp, $cfg);
$tdsMonthly = round_rupee($tax['tax'] / 12);

$slips = db()->prepare(
    "SELECT s.*, r.bs_year, r.bs_month, r.fiscal_year, r.status, r.id AS run_id
     FROM acc_payslips s JOIN acc_payroll_runs r ON r.id = s.run_id
     WHERE s.employee_id = ? AND r.status IN ('posted','paid') ORDER BY r.bs_year DESC, r.bs_month DESC"
);
$slips->execute([$id]);
$history = $slips->fetchAll();
$fy = fiscal_year_for(date('Y-m-d'));
$ytd = array_fill_keys(['gross_pay', 'tds', 'net_pay'], 0);
foreach ($history as $h) if ($h['fiscal_year'] === $fy) foreach ($ytd as $k => $_) $ytd[$k] += decimal_to_cents($h[$k]);

$pageTitle   = $emp['full_name'];
$activeNav   = 'payroll';
$breadcrumbs = [['label' => 'Payroll', 'href' => 'payroll.php'], ['label' => 'Employees', 'href' => 'employees.php'], ['label' => $emp['full_name']]];
$pageActions = can_edit_books($user) ? '<a class="btn btn-primary" href="employee-edit.php?id=' . $id . '"><i class="fa-solid fa-pen me-1"></i> Edit</a>' : '';
require __DIR__ . '/includes/layout-top.php';
$row = fn(string $label, string $value) => '<dt class="col-5 text-body-secondary fw-normal">' . e($label) . '</dt><dd class="col-7">' . $value . '</dd>';
?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="acc-card h-100">
            <div class="acc-card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="acc-avatar" style="width:48px;height:48px;font-size:1rem"><?= e(mb_strtoupper(mb_substr($emp['full_name'], 0, 1))) ?></span>
                    <div>
                        <div class="fw-bold"><?= e($emp['designation'] ?? 'Employee') ?></div>
                        <div class="small text-body-secondary"><span class="acc-code"><?= e($emp['employee_code']) ?></span><?= $emp['department'] ? ' · ' . e($emp['department']) : '' ?></div>
                        <?php if (!$emp['is_active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif ?>
                    </div>
                </div>
                <dl class="row small mb-0">
                    <?= $row('Joined', e(bs_date($emp['join_date'])) . ' B.S.') ?>
                    <?php if ($emp['leave_date']): ?><?= $row('Left', e(bs_date($emp['leave_date'])) . ' B.S.') ?><?php endif ?>
                    <?= $row('Tax status', e(ucfirst($emp['marital_status'])) . ' · ' . e(ucfirst($emp['gender']))) ?>
                    <?= $row('PAN', $emp['pan_number'] ? '<span class="acc-code">' . e($emp['pan_number']) . '</span>' : '—') ?>
                    <?= $row('Project', e(project_label($emp['project_id'] ? (int)$emp['project_id'] : null) ?: 'Head office')) ?>
                    <?= $row('Bank', e(trim(($emp['bank_name'] ?? '') . ' ' . ($emp['bank_account'] ?? '')) ?: '—')) ?>
                    <?= $row('Phone', e($emp['phone'] ?? '—')) ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="row g-3">
            <div class="col-sm-4"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Gross / month</span><div class="acc-kpi-value"><?= e(money_cents($monthly)) ?></div><div class="acc-kpi-note">Basic <?= e(money($emp['basic_salary'], false)) ?></div></div></div>
            <div class="col-sm-4"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Est. take-home</span><div class="acc-kpi-value"><?= e(money_cents($monthly - $tdsMonthly)) ?></div><div class="acc-kpi-note">After TDS, full month</div></div></div>
            <div class="col-sm-4"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Paid FY <?= e($fy) ?></span><div class="acc-kpi-value"><?= e(money_cents($ytd['net_pay'])) ?></div><div class="acc-kpi-note">Gross <?= e(money_cents($ytd['gross_pay'])) ?> · TDS <?= e(money_cents($ytd['tds'])) ?></div></div></div>
            <div class="col-12">
                <div class="acc-card">
                    <div class="acc-card-head"><h2>Annual tax projection</h2><span class="small text-body-secondary">At current salary</span></div>
                    <table class="table table-sm mb-0 small">
                        <tbody>
                            <tr><td>Annual salary (<?= e(money_cents($monthly)) ?> × 12)</td><td class="num"><?= e(money_cents($annual)) ?></td></tr>
                            <?php if ($tax['insurance']): ?><tr><td>− Insurance premium deduction</td><td class="num"><?= e(money_cents($tax['insurance'])) ?></td></tr><?php endif ?>
                            <tr class="table-subtotal"><td>Taxable income (<?= e($emp['marital_status']) ?> slabs)</td><td class="num"><?= e(money_cents($tax['taxable'])) ?></td></tr>
                            <tr><td>Tax <span class="text-body-secondary">(incl. 1% social security tax on the first band)</span></td><td class="num"><?= e(money_cents($tax['tax_before_rebate'])) ?></td></tr>
                            <?php if ($tax['rebate']): ?><tr><td>− Female rebate (<?= e($cfg['female_rebate']) ?>%)</td><td class="num"><?= e(money_cents($tax['rebate'])) ?></td></tr><?php endif ?>
                            <tr class="table-total"><td>Annual tax · monthly TDS <?= e(money_cents($tdsMonthly)) ?></td><td class="num"><?= e(money_cents($tax['tax'])) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="acc-card">
    <div class="acc-card-head"><h2>Payslips</h2></div>
    <?php if (!$history): ?>
        <div class="acc-card-body small text-body-secondary">No posted payslips yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Month</th><th class="num">Days present</th><th class="num">Gross</th><th class="num">TDS</th><th class="num">Net pay</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= e(bs_month_name((int)$h['bs_month']) . ' ' . $h['bs_year']) ?> <?= $h['status'] === 'paid' ? '' : '<span class="badge text-bg-primary">Unpaid</span>' ?></td>
                        <td class="num"><?= e(rtrim(rtrim($h['days_paid'], '0'), '.')) ?>/<?= (int)$h['standard_days'] ?></td>
                        <td class="num"><?= e(money($h['gross_pay'], false)) ?></td>
                        <td class="num"><?= e(money($h['tds'], false)) ?></td>
                        <td class="num fw-semibold"><?= e(money($h['net_pay'], false)) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-link" href="payslip.php?id=<?= (int)$h['id'] ?>"><i class="fa-solid fa-file-invoice"></i> Payslip</a></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
