<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$isAdmin = $user['role'] === 'admin';
$cfg = payroll_config();

$numberFields = [
    'standard_days'        => ['Standard days per month', 'days', 'A full month\'s basic. Basic pay = basic × days present ÷ this number'],
    'allowance_per_day'    => ['Allowance per day present', 'Rs', 'Same for every employee. Allowance pay = this × days present'],
    'life_insurance_cap'   => ['Life insurance premium deduction limit', 'Rs', 'Per year'],
    'health_insurance_cap' => ['Health insurance premium deduction limit', 'Rs', 'Per year'],
    'female_rebate'        => ['Tax rebate for women', '%', 'Of the tax, when salary is the only income'],
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { http_response_code(403); exit('Only admins can change payroll rates.'); }
    verify_csrf();
    $stmt = db()->prepare('INSERT INTO acc_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');

    if (($_POST['action'] ?? '') === 'reset') {
        db()->prepare("DELETE FROM acc_settings WHERE setting_key LIKE 'payroll\\_%'")->execute();
        audit('update_payroll_settings', 'settings', null, 'reset to defaults');
        flash('success', 'Payroll rates reset to the built-in defaults.');
        redirect('payroll-settings.php');
    }

    $values = [];
    foreach ($numberFields as $key => [$label]) {
        $v = trim((string)($_POST[$key] ?? ''));
        if (!is_numeric($v) || $v < 0) $errors[] = "{$label} must be a number.";
        elseif ($key === 'standard_days' && (!ctype_digit($v) || $v < 1 || $v > 31)) $errors[] = "{$label} must be a whole number from 1 to 31.";
        $values[$key] = $v;
    }
    $slabs = [];
    foreach (['single', 'married'] as $status) {
        $prev = 0;
        $rows = array_values(array_filter($_POST['slabs'][$status] ?? [], fn($r) => trim((string)($r['rate'] ?? '')) !== ''));
        if (!$rows) $errors[] = ucfirst($status) . ' slabs are empty.';
        foreach ($rows as $i => $r) {
            $isLast = $i === count($rows) - 1;
            $limit = str_replace(',', '', trim((string)($r['limit'] ?? '')));
            $rate = trim((string)$r['rate']);
            if (!is_numeric($rate) || $rate < 0 || $rate > 100) { $errors[] = ucfirst($status) . ' slab ' . ($i + 1) . ': rate must be 0–100.'; continue; }
            if ($isLast) { $slabs[$status][] = [null, (float)$rate]; continue; } // top band has no ceiling
            if (!ctype_digit($limit) || (int)$limit <= $prev) { $errors[] = ucfirst($status) . ' slab ' . ($i + 1) . ': "up to" must be a whole number larger than the previous band.'; continue; }
            $slabs[$status][] = [(int)$limit, (float)$rate];
            $prev = (int)$limit;
        }
    }
    if (!$errors) {
        foreach ($values as $k => $v) $stmt->execute(["payroll_{$k}", $v]);
        $stmt->execute(['payroll_tax_slabs', json_encode($slabs)]);
        audit('update_payroll_settings', 'settings', null, 'payroll rates');
        flash('success', 'Payroll rates saved. Recalculate any draft payroll to apply them; posted payroll is unchanged.');
        redirect('payroll-settings.php');
    }
    $cfg = array_merge($cfg, $values, ['tax_slabs' => $slabs + $cfg['tax_slabs']]);
}

$pageTitle   = 'Payroll rates';
$activeNav   = 'payroll';
$breadcrumbs = [['label' => 'Payroll', 'href' => 'payroll.php'], ['label' => 'Payroll rates']];
require __DIR__ . '/includes/layout-top.php';
$payrollTab = 'settings';
require __DIR__ . '/includes/payroll-nav.php';
$dis = $isAdmin ? '' : 'disabled';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>
<div class="alert alert-info small">
    <i class="fa-solid fa-circle-info me-1"></i> These defaults follow the Income Tax Act slabs and limits in force at the time of writing.
    The Finance Act can change them every Shrawan — check with your auditor or the IRD each fiscal year and update them here.
    <?= $isAdmin ? '' : 'Only admins can change them.' ?>
</div>

<form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-xl-5">
            <div class="acc-card h-100">
                <div class="acc-card-head"><h2>Pay days, allowance, deductions &amp; rebate</h2></div>
                <div class="acc-card-body">
                    <?php foreach ($numberFields as $key => [$label, $unit, $help]): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small" for="<?= $key ?>"><?= e($label) ?></label>
                            <div class="input-group input-group-sm">
                                <?php if ($unit === 'Rs'): ?><span class="input-group-text">Rs</span><?php endif ?>
                                <input class="form-control text-end" id="<?= $key ?>" name="<?= $key ?>" value="<?= e((string)$cfg[$key]) ?>" inputmode="decimal" <?= $dis ?>>
                                <?php if ($unit === '%' || $unit === 'days'): ?><span class="input-group-text"><?= $unit ?></span><?php endif ?>
                            </div>
                            <div class="form-text"><?= e($help) ?></div>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="acc-card h-100">
                <div class="acc-card-head"><h2>Annual salary tax slabs</h2></div>
                <div class="acc-card-body">
                    <div class="row g-3">
                        <?php foreach (['single' => 'Single (individual)', 'married' => 'Married (couple)'] as $status => $title): ?>
                            <div class="col-md-6">
                                <div class="fw-semibold small mb-2"><?= e($title) ?></div>
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>Income up to (Rs)</th><th class="num">Rate %</th></tr></thead>
                                    <tbody>
                                    <?php $rows = $cfg['tax_slabs'][$status]; for ($i = 0; $i < max(6, count($rows)); $i++): [$limit, $rate] = $rows[$i] ?? ['', '']; ?>
                                        <tr>
                                            <td><input class="form-control form-control-sm text-end" name="slabs[<?= $status ?>][<?= $i ?>][limit]" value="<?= $limit === null ? '' : e((string)$limit) ?>" placeholder="<?= $i === count($rows) - 1 ? 'and above' : '' ?>" inputmode="numeric" <?= $dis ?>></td>
                                            <td><input class="form-control form-control-sm text-end" name="slabs[<?= $status ?>][<?= $i ?>][rate]" value="<?= e((string)$rate) ?>" inputmode="decimal" style="width:80px" <?= $dis ?>></td>
                                        </tr>
                                    <?php endfor ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach ?>
                    </div>
                    <p class="form-text mt-3 mb-0">Each row taxes income from the previous limit up to this one. Leave "up to" empty on the last row (top band). The first band is the 1% Social Security Tax.</p>
                </div>
            </div>
        </div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="d-flex justify-content-between mt-3">
            <button type="submit" name="action" value="reset" class="btn btn-outline-secondary" formnovalidate data-confirm="Reset all payroll rates to the built-in defaults?">Reset to defaults</button>
            <button type="submit" name="action" value="save" class="btn btn-primary">Save rates</button>
        </div>
    <?php endif ?>
</form>

<?php require __DIR__ . '/includes/layout-bottom.php';
