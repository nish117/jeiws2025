<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$id = (int)($_GET['id'] ?? 0);
$emp = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM acc_employees WHERE id = ?');
    $stmt->execute([$id]);
    $emp = $stmt->fetch() ?: null;
    if (!$emp) { flash('warning', 'Employee not found.'); redirect('employees.php'); }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete' && $emp) {
        $used = db()->prepare('SELECT 1 FROM acc_payslips WHERE employee_id = ? LIMIT 1');
        $used->execute([$id]);
        if ($used->fetchColumn()) {
            $errors[] = 'This employee has payslips, so they can\'t be deleted. Set a leaving date and untick "Active" instead.';
        } else {
            db()->prepare('DELETE FROM acc_employees WHERE id = ?')->execute([$id]);
            audit('delete_employee', 'employee', $id, $emp['full_name']);
            flash('success', "{$emp['full_name']} deleted.");
            redirect('employees.php');
        }
    } else {
        $check = validate_employee($_POST, $emp ? $id : null);
        $errors = $check['errors'];
        if (!$errors) {
            $savedId = save_employee($check['values'], (int)$user['id'], $emp ? $id : null);
            flash('success', $emp ? 'Employee updated. Draft payroll picks up the new figures when you recalculate it.' : "{$check['values']['full_name']} added.");
            redirect('employee.php?id=' . $savedId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_POST;
} elseif ($emp) {
    $f = $emp;
    foreach (['basic_salary', 'life_insurance_premium', 'health_insurance_premium'] as $k) $f[$k] = (float)$emp[$k] ? $emp[$k] : '';
} else {
    $f = ['gender' => '', 'marital_status' => '', 'join_date' => date('Y-m-d'), 'is_active' => 1];
}
$projects = project_options();
$cur = e(setting('currency_symbol', 'Rs.'));

$pageTitle   = $emp ? "Edit {$emp['full_name']}" : 'New employee';
$activeNav   = 'payroll';
$breadcrumbs = [['label' => 'Payroll', 'href' => 'payroll.php'], ['label' => 'Employees', 'href' => 'employees.php']];
if ($emp) $breadcrumbs[] = ['label' => $emp['full_name'], 'href' => 'employee.php?id=' . $id];
$breadcrumbs[] = ['label' => $emp ? 'Edit' : 'New'];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<form method="post" style="max-width:980px" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card mb-3">
        <div class="acc-card-head"><h2>Personal &amp; job details</h2></div>
        <div class="acc-card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="full_name">Full name <span class="text-danger">*</span></label>
                    <input class="form-control" id="full_name" name="full_name" value="<?= e($f['full_name'] ?? '') ?>" required maxlength="120" autofocus>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="employee_code">Employee code</label>
                    <input class="form-control" id="employee_code" name="employee_code" value="<?= e($f['employee_code'] ?? '') ?>" maxlength="20" placeholder="Auto: <?= e(next_employee_code()) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="gender">Gender <span class="text-danger">*</span></label>
                    <select class="form-select" id="gender" name="gender" required>
                        <option value="">Choose…</option>
                        <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= ($f['gender'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="designation">Designation</label>
                    <input class="form-control" id="designation" name="designation" value="<?= e($f['designation'] ?? '') ?>" maxlength="100" placeholder="e.g. Site Engineer">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="department">Department</label>
                    <input class="form-control" id="department" name="department" value="<?= e($f['department'] ?? '') ?>" maxlength="100" placeholder="e.g. Engineering, Admin">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="project_id">Charge salary to project</label>
                    <select class="form-select" id="project_id" name="project_id">
                        <option value="">— Head office (no project) —</option>
                        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (string)$p['id'] === (string)($f['project_id'] ?? '') ? 'selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="join_date">Joining date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="join_date" name="join_date" value="<?= e($f['join_date'] ?? '') ?>" required>
                    <div class="acc-bs-hint" data-date-hint="join_date">&nbsp;</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="leave_date">Leaving date</label>
                    <input type="date" class="form-control" id="leave_date" name="leave_date" value="<?= e($f['leave_date'] ?? '') ?>">
                    <div class="acc-bs-hint" data-date-hint="leave_date">&nbsp;</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="phone">Phone</label>
                    <input class="form-control" id="phone" name="phone" value="<?= e($f['phone'] ?? '') ?>" maxlength="40" inputmode="tel">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($f['email'] ?? '') ?>" maxlength="150">
                    <div class="form-text">Payslips are emailed here when salary is paid.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="address">Address</label>
                    <input class="form-control" id="address" name="address" value="<?= e($f['address'] ?? '') ?>" maxlength="255">
                </div>
            </div>
        </div>
    </div>

    <div class="acc-card mb-3">
        <div class="acc-card-head"><h2>Salary &amp; tax</h2></div>
        <div class="acc-card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="basic_salary">Basic salary / month <span class="text-danger">*</span></label>
                    <div class="input-group"><span class="input-group-text"><?= $cur ?></span><input class="form-control text-end" id="basic_salary" name="basic_salary" value="<?= e((string)($f['basic_salary'] ?? '')) ?>" inputmode="decimal" required></div>
                </div>
                <div class="col-md-4">
                    <span class="form-label fw-semibold d-block">Allowance</span>
                    <div class="form-control-plaintext py-1"><?= e(money(payroll_config()['allowance_per_day'])) ?> per day present</div>
                    <div class="form-text">Same for every employee — <?= e(money_cents(full_month_allowance())) ?> for a full month of <?= standard_days() ?> days. Changed under Payroll → Payroll rates.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="marital_status">Tax status <span class="text-danger">*</span></label>
                    <select class="form-select" id="marital_status" name="marital_status" required>
                        <option value="">Choose…</option>
                        <option value="single" <?= ($f['marital_status'] ?? '') === 'single' ? 'selected' : '' ?>>Single (individual slabs)</option>
                        <option value="married" <?= ($f['marital_status'] ?? '') === 'married' ? 'selected' : '' ?>>Married (couple slabs)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="pan_number">PAN</label>
                    <input class="form-control" id="pan_number" name="pan_number" value="<?= e($f['pan_number'] ?? '') ?>" maxlength="9" inputmode="numeric" placeholder="9 digits">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="life_insurance_premium">Life insurance premium / year</label>
                    <div class="input-group"><span class="input-group-text"><?= $cur ?></span><input class="form-control text-end" id="life_insurance_premium" name="life_insurance_premium" value="<?= e((string)($f['life_insurance_premium'] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></div>
                    <div class="form-text">Deductible up to <?= e(money(payroll_config()['life_insurance_cap'])) ?>.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="health_insurance_premium">Health insurance premium / year</label>
                    <div class="input-group"><span class="input-group-text"><?= $cur ?></span><input class="form-control text-end" id="health_insurance_premium" name="health_insurance_premium" value="<?= e((string)($f['health_insurance_premium'] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></div>
                    <div class="form-text">Deductible up to <?= e(money(payroll_config()['health_insurance_cap'])) ?>.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="bank_name">Bank</label>
                    <input class="form-control" id="bank_name" name="bank_name" value="<?= e($f['bank_name'] ?? '') ?>" maxlength="100" placeholder="e.g. Nabil Bank">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="bank_account">Bank account no.</label>
                    <input class="form-control" id="bank_account" name="bank_account" value="<?= e($f['bank_account'] ?? '') ?>" maxlength="40">
                </div>
                <?php if ($emp): ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= !empty($f['is_active']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Active (included in new payroll runs)</label>
                        </div>
                    </div>
                <?php endif ?>
            </div>
        </div>
        <div class="border-top px-4 py-3 d-flex flex-wrap justify-content-between gap-2">
            <div>
                <?php if ($emp): ?>
                    <button type="submit" name="action" value="delete" class="btn btn-outline-danger" formnovalidate onclick="return confirm('Delete this employee? Only possible if they have no payslips.')">Delete</button>
                <?php else: ?>
                    <a href="employees.php" class="btn btn-link text-body-secondary">Cancel</a>
                <?php endif ?>
            </div>
            <button type="submit" class="btn btn-primary"><?= $emp ? 'Save changes' : 'Add employee' ?></button>
        </div>
    </div>
</form>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = <<<'JS'
document.querySelectorAll('[data-date-hint]').forEach(hint => {
    const input = document.getElementById(hint.dataset.dateHint);
    const show = () => {
        const bs = typeof adToBs === 'function' ? adToBs(input.value) : null;
        hint.textContent = bs ? `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]})` : ' ';
    };
    input.addEventListener('input', show);
    show();
});
JS;
require __DIR__ . '/includes/layout-bottom.php';
