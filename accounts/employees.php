<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$status = (string)($_GET['status'] ?? 'active');
$q = trim((string)($_GET['q'] ?? ''));
$where = ['1=1'];
$params = [];
if ($status === 'active')   $where[] = 'e.is_active = 1';
if ($status === 'inactive') $where[] = 'e.is_active = 0';
if ($q !== '') { $where[] = '(e.full_name LIKE ? OR e.employee_code LIKE ? OR e.designation LIKE ? OR e.pan_number LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"); }
$stmt = db()->prepare('SELECT e.* FROM acc_employees e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.is_active DESC, e.full_name');
$stmt->execute($params);
$employees = $stmt->fetchAll();

$cfg = payroll_config();
$totalMonthly = 0;
foreach ($employees as $emp) if ($emp['is_active']) {
    $totalMonthly += regular_monthly_pay($emp, $cfg);
}

$pageTitle   = 'Employees';
$activeNav   = 'payroll';
$breadcrumbs = [['label' => 'Payroll', 'href' => 'payroll.php'], ['label' => 'Employees']];
$pageActions = can_edit_books($user) ? '<a href="employee-edit.php" class="btn btn-primary"><i class="fa-solid fa-user-plus me-1"></i> New employee</a>' : '';
require __DIR__ . '/includes/layout-top.php';
$payrollTab = 'employees';
require __DIR__ . '/includes/payroll-nav.php';
?>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <div class="flex-grow-1" style="max-width:340px">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, code, designation or PAN">
        </div>
        <div>
            <label class="form-label" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $label): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?>
            </select>
        </div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
        <?php if ($totalMonthly): ?>
            <div class="ms-auto text-end">
                <div class="form-label mb-0">Monthly salary bill (gross)</div>
                <div class="fw-bold"><?= e(money_cents($totalMonthly, true)) ?></div>
            </div>
        <?php endif ?>
    </form>

    <?php if (!$employees): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid fa-id-badge"></i></div>
            <h3><?= $q || $status !== 'active' ? 'No employees match' : 'No employees yet' ?></h3>
            <p>Add office and site staff on monthly salary. Daily-wage site labour is handled by the site portal's attendance instead.</p>
            <?php if (can_edit_books($user) && !$q): ?><a href="employee-edit.php" class="btn btn-primary mt-3"><i class="fa-solid fa-user-plus me-1"></i> Add your first employee</a><?php endif ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Employee</th><th class="d-none d-md-table-cell">Designation</th><th class="d-none d-lg-table-cell">Project</th><th class="d-none d-md-table-cell">PAN</th><th class="num">Basic</th><th class="num" title="Basic + full-month allowance (26 days present)">Gross / month</th></tr></thead>
                <tbody>
                <?php foreach ($employees as $emp): $monthly = regular_monthly_pay($emp, $cfg); ?>
                    <tr class="<?= $emp['is_active'] ? '' : 'acc-inactive' ?>" style="cursor:pointer" onclick="location.href='employee.php?id=<?= (int)$emp['id'] ?>'">
                        <td>
                            <a href="employee.php?id=<?= (int)$emp['id'] ?>" class="fw-semibold text-decoration-none"><?= e($emp['full_name']) ?></a>
                            <?php if (!$emp['is_active']): ?> <span class="badge text-bg-secondary">Inactive</span><?php endif ?>
                            <div class="small text-body-secondary"><span class="acc-code"><?= e($emp['employee_code']) ?></span> · joined <?= e(bs_date($emp['join_date'])) ?></div>
                        </td>
                        <td class="d-none d-md-table-cell small"><?= e($emp['designation'] ?? '—') ?><?= $emp['department'] ? '<div class="text-body-secondary">' . e($emp['department']) . '</div>' : '' ?></td>
                        <td class="d-none d-lg-table-cell small"><?= e(project_label($emp['project_id'] ? (int)$emp['project_id'] : null) ?: '—') ?></td>
                        <td class="d-none d-md-table-cell"><?= $emp['pan_number'] ? '<span class="acc-code">' . e($emp['pan_number']) . '</span>' : '<span class="text-body-tertiary small">—</span>' ?></td>
                        <td class="num"><?= e(money($emp['basic_salary'], false)) ?></td>
                        <td class="num fw-semibold"><?= e(money_cents($monthly)) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
