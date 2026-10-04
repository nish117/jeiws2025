<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

[$todayBsYear, $todayBsMonth] = array_map('intval', explode('-', bs_date(date('Y-m-d'))));
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    try {
        $runId = create_payroll_run((int)($_POST['bs_year'] ?? 0), (int)($_POST['bs_month'] ?? 0), (int)$user['id']);
        flash('success', 'Draft payroll created. Check days, overtime and deductions, then post it.');
        redirect('payroll-run.php?id=' . $runId);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$runs = db()->query(
    "SELECT r.*, COUNT(s.id) AS employees, COALESCE(SUM(s.gross_pay),0) AS gross, COALESCE(SUM(s.net_pay),0) AS net,
            COALESCE(SUM(s.tds),0) AS tds
     FROM acc_payroll_runs r LEFT JOIN acc_payslips s ON s.run_id = r.id
     GROUP BY r.id ORDER BY r.bs_year DESC, r.bs_month DESC, r.id DESC"
)->fetchAll();
$activeEmployees = (int)db()->query('SELECT COUNT(*) FROM acc_employees WHERE is_active = 1')->fetchColumn();
$unpaid = array_filter($runs, fn($r) => $r['status'] === 'posted');

// Suggest the first month that has no live run yet, starting from the current one.
$taken = [];
foreach ($runs as $r) if ($r['status'] !== 'void') $taken[$r['bs_year'] . '-' . $r['bs_month']] = true;
[$suggestYear, $suggestMonth] = [$todayBsYear, $todayBsMonth];
if (isset($taken["{$suggestYear}-{$suggestMonth}"])) {
    $suggestMonth = $suggestMonth % 12 + 1;
    if ($suggestMonth === 1) $suggestYear++;
}

$pageTitle   = 'Payroll';
$activeNav   = 'payroll';
$breadcrumbs = [['label' => 'Payroll']];
require __DIR__ . '/includes/layout-top.php';
$payrollTab = 'runs';
require __DIR__ . '/includes/payroll-nav.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>
<?php if ($unpaid): ?>
    <div class="alert alert-warning"><i class="fa-solid fa-hourglass-half me-1"></i>
        Salaries posted but not yet marked paid: <?= implode(', ', array_map(fn($r) => '<a href="payroll-run.php?id=' . (int)$r['id'] . '">' . e(payroll_period_label($r)) . '</a>', $unpaid)) ?>.
    </div>
<?php endif ?>

<div class="row g-3">
    <?php if ($canEdit): ?>
    <div class="col-xl-4 order-xl-2">
        <form method="post" class="acc-card">
            <?= csrf_field() ?>
            <div class="acc-card-head"><h2>Run payroll</h2></div>
            <div class="acc-card-body">
                <?php if (!$activeEmployees): ?>
                    <p class="small text-body-secondary mb-3">Add your employees first — each run creates a payslip for every active employee.</p>
                    <a href="employee-edit.php" class="btn btn-primary w-100"><i class="fa-solid fa-user-plus me-1"></i> Add employee</a>
                <?php else: ?>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label fw-semibold" for="bs_month">Month (B.S.)</label>
                            <select class="form-select" id="bs_month" name="bs_month">
                                <?php foreach (NepaliDate::MONTH_NAMES as $i => $name): ?><option value="<?= $i + 1 ?>" <?= $i + 1 === $suggestMonth ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-semibold" for="bs_year">Year</label>
                            <select class="form-select" id="bs_year" name="bs_year">
                                <?php for ($y = $todayBsYear - 1; $y <= $todayBsYear + 1; $y++): ?><option value="<?= $y ?>" <?= $y === $suggestYear ? 'selected' : '' ?>><?= $y ?></option><?php endfor ?>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary w-100"><i class="fa-solid fa-play me-1"></i> Create draft payroll</button>
                    <p class="small text-body-secondary mt-3 mb-0"><?= $activeEmployees ?> active employee<?= $activeEmployees === 1 ? '' : 's' ?>. Nothing touches the books until you post the draft.</p>
                <?php endif ?>
            </div>
        </form>
    </div>
    <?php endif ?>

    <div class="<?= $canEdit ? 'col-xl-8 order-xl-1' : 'col-12' ?>">
        <div class="acc-card">
            <div class="acc-card-head"><h2>Payroll runs</h2></div>
            <?php if (!$runs): ?>
                <div class="acc-empty">
                    <div class="acc-empty-icon"><i class="fa-solid fa-money-check-dollar"></i></div>
                    <h3>No payroll yet</h3>
                    <p>Each month: create a draft, adjust days and overtime, post it to the books, then record the salary payment.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Month</th><th>Status</th><th class="num d-none d-md-table-cell">Staff</th><th class="num">Gross</th><th class="num d-none d-lg-table-cell">TDS</th><th class="num">Net pay</th></tr></thead>
                        <tbody>
                        <?php foreach ($runs as $r): ?>
                            <tr class="<?= $r['status'] === 'void' ? 'acc-inactive' : '' ?>" style="cursor:pointer" onclick="location.href='payroll-run.php?id=<?= (int)$r['id'] ?>'">
                                <td><a class="fw-semibold text-decoration-none" href="payroll-run.php?id=<?= (int)$r['id'] ?>"><?= e(payroll_period_label($r)) ?></a><div class="small text-body-secondary">FY <?= e($r['fiscal_year']) ?></div></td>
                                <td><?= payroll_status_badge($r['status']) ?></td>
                                <td class="num d-none d-md-table-cell"><?= (int)$r['employees'] ?></td>
                                <td class="num"><?= e(money($r['gross'], false)) ?></td>
                                <td class="num d-none d-lg-table-cell"><?= e(money($r['tds'], false)) ?></td>
                                <td class="num fw-semibold"><?= e(money($r['net'], false)) ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
        <p class="small text-body-secondary mt-2">Salary TDS is credited to <em>TDS Payable</em> when payroll is posted. Deposit it to the IRD by the 25th of the following month and record that payment as a journal voucher.</p>
    </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
