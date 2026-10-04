<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

$id = (int)($_GET['id'] ?? 0);
$run = load_payroll_run($id);
if (!$run) { flash('warning', 'Payroll run not found.'); redirect('payroll.php'); }
$period = payroll_period_label($run);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save' || $action === 'save_post') {
            $errors = update_payroll_run($run, is_array($_POST['slips'] ?? null) ? $_POST['slips'] : []);
            if (!$errors && $action === 'save_post') {
                $voucher = post_payroll_run(load_payroll_run($id), (int)$user['id'], (string)($_POST['post_date'] ?? ''));
                flash('success', "Payroll {$period} posted as {$voucher}. Record the payment once salaries are transferred.");
                redirect("payroll-run.php?id={$id}");
            }
            if (!$errors) { flash('success', 'Payroll recalculated and saved.'); redirect("payroll-run.php?id={$id}"); }
        } elseif ($action === 'delete' && $run['status'] === 'draft') {
            db()->prepare('DELETE FROM acc_payroll_runs WHERE id = ? AND status = ?')->execute([$id, 'draft']);
            audit('delete_payroll', 'payroll_run', $id, $period);
            flash('success', "Draft payroll {$period} deleted.");
            redirect('payroll.php');
        } elseif ($action === 'pay') {
            $voucher = pay_payroll_run($run, (int)$user['id'], (string)($_POST['pay_date'] ?? ''), (int)($_POST['account_id'] ?? 0), trim((string)($_POST['reference'] ?? '')));
            flash('success', "Salary payment recorded as {$voucher}.");
            // Emails go out after the payment is committed, so a mail problem can never undo the payment.
            if (!empty($_POST['email_payslips'])) {
                $r = email_payslips(load_payroll_run($id));
                flash($r['failed'] || $r['no_email'] ? 'warning' : 'success', email_result_message($r));
            }
            redirect("payroll-run.php?id={$id}");
        } elseif ($action === 'email') {
            $r = email_payslips($run, null, !empty($_POST['only_unsent']));
            flash($r['failed'] || $r['no_email'] ? 'warning' : 'success', email_result_message($r));
            redirect("payroll-run.php?id={$id}");
        } elseif ($action === 'email_one') {
            $r = email_payslips($run, [(int)($_POST['employee_id'] ?? 0)]);
            flash($r['sent'] ? 'success' : 'warning', email_result_message($r));
            redirect("payroll-run.php?id={$id}");
        } elseif ($action === 'void' && $user['role'] === 'admin') {
            void_payroll_run($run, (int)$user['id'], (string)($_POST['void_reason'] ?? ''));
            flash('success', "Payroll {$period} voided. You can now run {$period} again.");
            redirect("payroll-run.php?id={$id}");
        }
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$slips = load_payslips($id);
$totals = payslip_totals($slips);
$isDraft = $run['status'] === 'draft' && $canEdit;
$vouchers = [];
foreach (['journal_entry_id' => 'Salary accrual', 'payment_entry_id' => 'Salary payment'] as $col => $label) {
    if ($run[$col]) {
        $v = db()->prepare('SELECT voucher_no, status FROM acc_journal_entries WHERE id = ?');
        $v->execute([$run[$col]]);
        $vouchers[] = ['id' => (int)$run[$col], 'label' => $label] + $v->fetch();
    }
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salary-sheet-' . $run['bs_year'] . '-' . str_pad((string)$run['bs_month'], 2, '0', STR_PAD_LEFT) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [setting('company_name'), "Salary sheet {$period}", "FY {$run['fiscal_year']}"]);
    fputcsv($out, ['Code', 'Name', 'Designation', 'PAN', 'Days present', 'Basic', 'Allowance', 'Overtime', 'Bonus', 'Other', 'Gross', 'TDS', 'Advance', 'Net pay', 'Bank', 'Account no.']);
    foreach ($slips as $s) {
        fputcsv($out, [$s['employee_code'], $s['full_name'], $s['designation'], $s['pan_number'], $s['days_paid'],
            ...array_map(fn($f) => cents_to_decimal($s[$f]), ['basic_pay', 'allowance_pay', 'overtime', 'bonus', 'other_earnings', 'gross_pay', 'tds', 'advance_recovery', 'net_pay']),
            $s['bank_name'], $s['bank_account']]);
    }
    fputcsv($out, ['', 'Total', '', '', '', ...array_map(fn($f) => cents_to_decimal($totals[$f]), ['basic_pay', 'allowance_pay', 'overtime', 'bonus', 'other_earnings', 'gross_pay', 'tds', 'advance_recovery', 'net_pay'])]);
    exit;
}

$m = fn(int $c) => $c ? e(money_cents($c)) : '<span class="text-body-tertiary">–</span>';
$inputVal = fn(int $c) => $c ? cents_to_decimal($c) : '';

$pageTitle   = "Payroll · {$period}";
$activeNav   = 'payroll';
$breadcrumbs = [['label' => 'Payroll', 'href' => 'payroll.php'], ['label' => $period]];
$pageActions = '<a class="btn btn-outline-secondary" href="?id=' . $id . '&export=csv"><i class="fa-solid fa-file-csv me-1"></i> Salary sheet CSV</a>'
             . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3 small text-body-secondary">
    <?= payroll_status_badge($run['status']) ?>
    <span><?= e(bs_date($run['period_start'])) ?> → <?= e(bs_date($run['period_end'])) ?> B.S. (<?= e(date('d M', strtotime($run['period_start']))) ?> – <?= e(date('d M Y', strtotime($run['period_end']))) ?>)</span>
    <span>· FY <?= e($run['fiscal_year']) ?></span>
    <?php foreach ($vouchers as $v): ?>
        · <a href="journal-entry.php?id=<?= $v['id'] ?>" class="text-decoration-none"><?= e($v['label']) ?>: <?= e($v['voucher_no']) ?></a><?= $v['status'] === 'void' ? ' <span class="badge text-bg-secondary">void</span>' : '' ?>
    <?php endforeach ?>
    <?php if ($run['notes']): ?><span>· <?= e($run['notes']) ?></span><?php endif ?>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([['Gross pay', $totals['gross_pay'], 'acc-tone-blue', 'fa-sack-dollar'], ['Advances recovered', $totals['advance_recovery'], 'acc-tone-gold', 'fa-rotate-left'],
                    ['Salary TDS', $totals['tds'], 'acc-tone-red', 'fa-landmark'], ['Net pay', $totals['net_pay'], 'acc-tone-green', 'fa-hand-holding-dollar']] as [$label, $val, $tone, $icon]): ?>
        <div class="col-6 col-xl-3">
            <div class="acc-card acc-kpi">
                <div class="acc-kpi-top"><span class="acc-kpi-label"><?= e($label) ?></span><span class="acc-kpi-icon <?= $tone ?>"><i class="fa-solid <?= $icon ?>"></i></span></div>
                <div class="acc-kpi-value"><?= e(money_cents($val, true)) ?></div>
                <div class="acc-kpi-note"><?= count($slips) ?> employee<?= count($slips) === 1 ? '' : 's' ?></div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<form method="post" id="payrollForm" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card mb-3">
        <div class="acc-card-head">
            <h2><?= $isDraft ? 'Salary sheet — draft' : 'Salary sheet' ?></h2>
            <?php if ($isDraft): ?><span class="small text-body-secondary">Edit the white cells, then <strong>Recalculate</strong>. TDS and net pay are worked out for you.</span><?php endif ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 acc-payroll-table">
                <thead>
                    <tr>
                        <th>Employee</th><th class="num">Days present</th><th class="num">Basic</th><th class="num" title="Rs <?= e(payroll_config()['allowance_per_day']) ?> per day present">Allowance</th>
                        <th class="num">Overtime</th><th class="num">Bonus</th><th class="num">Other</th><th class="num">Gross</th>
                        <th class="num">TDS</th><th class="num">Advance</th><th class="num">Net pay</th>
                        <th class="num d-print-none"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($slips as $s): $eid = (int)$s['employee_id']; $n = "slips[{$eid}]"; ?>
                    <tr>
                        <td class="text-nowrap">
                            <a href="employee.php?id=<?= $eid ?>" class="fw-semibold text-decoration-none"><?= e($s['full_name']) ?></a>
                            <div class="small text-body-secondary"><?= e($s['designation'] ?? $s['employee_code']) ?><?= $s['project_id'] ? ' · ' . e(project_options()[(int)$s['project_id']]['code'] ?? '') : '' ?></div>
                        </td>
                        <?php if ($isDraft): ?>
                            <td class="num"><input class="form-control form-control-sm text-end" name="<?= $n ?>[days_paid]" data-w="days" value="<?= e(rtrim(rtrim((string)$s['days_paid'], '0'), '.')) ?>" inputmode="decimal"><div class="small text-body-secondary">of <?= (int)$s['standard_days'] ?></div></td>
                            <td class="num"><?= $m($s['basic_pay']) ?></td>
                            <td class="num"><?= $m($s['allowance_pay']) ?></td>
                            <?php foreach (['overtime', 'bonus', 'other_earnings'] as $f): ?>
                                <td class="num"><input class="form-control form-control-sm text-end" data-w="amt" name="<?= $n ?>[<?= $f ?>]" value="<?= e($inputVal($s[$f])) ?>" inputmode="decimal" placeholder="0"></td>
                            <?php endforeach ?>
                            <td class="num fw-semibold"><?= $m($s['gross_pay']) ?></td>
                            <td class="num">
                                <input class="form-control form-control-sm text-end" data-w="amt" name="<?= $n ?>[tds]" value="<?= e(cents_to_decimal($s['tds'])) ?>" inputmode="decimal" <?= $s['tds_override'] ? '' : 'readonly' ?>>
                                <label class="small text-body-secondary text-nowrap"><input type="checkbox" class="form-check-input tds-override" name="<?= $n ?>[tds_override]" value="1" <?= $s['tds_override'] ? 'checked' : '' ?>> manual</label>
                            </td>
                            <td class="num"><input class="form-control form-control-sm text-end" data-w="amt" name="<?= $n ?>[advance_recovery]" value="<?= e($inputVal($s['advance_recovery'])) ?>" inputmode="decimal" placeholder="0"></td>
                            <td class="num fw-bold"><?= $m($s['net_pay']) ?></td>
                            <td class="num d-print-none"><label class="text-danger text-nowrap" title="Leave this employee out of this month"><input type="checkbox" class="form-check-input" name="<?= $n ?>[remove]" value="1" aria-label="Remove from this month"> <i class="fa-solid fa-user-minus"></i></label></td>
                        <?php else: ?>
                            <td class="num"><?= e(rtrim(rtrim((string)$s['days_paid'], '0'), '.')) ?>/<?= (int)$s['standard_days'] ?></td>
                            <td class="num"><?= $m($s['basic_pay']) ?></td>
                            <td class="num"><?= $m($s['allowance_pay']) ?></td>
                            <td class="num"><?= $m($s['overtime']) ?></td>
                            <td class="num"><?= $m($s['bonus']) ?></td>
                            <td class="num"><?= $m($s['other_earnings']) ?></td>
                            <td class="num fw-semibold"><?= $m($s['gross_pay']) ?></td>
                            <td class="num"><?= $m($s['tds']) ?></td>
                            <td class="num"><?= $m($s['advance_recovery']) ?></td>
                            <td class="num fw-bold"><?= $m($s['net_pay']) ?></td>
                            <td class="num d-print-none"><a class="btn btn-sm btn-link" href="payslip.php?id=<?= (int)$s['id'] ?>" title="Payslip"><i class="fa-solid fa-file-invoice"></i></a></td>
                        <?php endif ?>
                    </tr>
                <?php endforeach ?>
                <?php if (!$slips): ?><tr><td colspan="13" class="text-center text-body-secondary py-4">No payslips in this run.</td></tr><?php endif ?>
                </tbody>
                <tfoot>
                    <tr class="table-total">
                        <td>Total</td><td></td>
                        <?php foreach (['basic_pay', 'allowance_pay', 'overtime', 'bonus', 'other_earnings', 'gross_pay', 'tds', 'advance_recovery', 'net_pay'] as $f): ?>
                            <td class="num"><?= e(money_cents($totals[$f])) ?></td>
                        <?php endforeach ?>
                        <td class="d-print-none"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php if ($isDraft): ?>
            <div class="border-top px-4 py-3 d-flex flex-wrap align-items-end justify-content-between gap-3">
                <div class="d-flex gap-2">
                    <button type="submit" name="action" value="delete" class="btn btn-outline-danger" formnovalidate onclick="return confirm('Delete this draft payroll?')">Delete draft</button>
                    <button type="submit" name="action" value="save" class="btn btn-outline-secondary"><i class="fa-solid fa-calculator me-1"></i> Recalculate</button>
                </div>
                <div class="d-flex align-items-end gap-2">
                    <div>
                        <label class="form-label small fw-semibold mb-1" for="post_date">Posting date</label>
                        <input type="date" class="form-control form-control-sm" id="post_date" name="post_date" value="<?= e($run['period_end']) ?>">
                    </div>
                    <button type="submit" name="action" value="save_post" class="btn btn-primary" onclick="return confirm('Post payroll <?= e($period) ?> to the books? Payslips can\'t be edited afterwards (you can void and redo).')"><i class="fa-solid fa-check me-1"></i> Post to books</button>
                </div>
            </div>
        <?php endif ?>
    </div>
</form>

<?php if ($run['status'] === 'posted' && $canEdit): $accounts = payment_account_options(); ?>
    <form method="post" class="acc-card mb-3 d-print-none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pay">
        <div class="acc-card-head"><h2>Record salary payment</h2><span class="small text-body-secondary">Net pay <?= e(money_cents($totals['net_pay'], true)) ?></span></div>
        <div class="acc-card-body">
            <?php if (!$accounts): ?>
                <p class="small mb-0">Add a bank account under <a href="chart-of-accounts.php">1130 Bank Accounts</a> first.</p>
            <?php else: ?>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small" for="account_id">Paid from</label>
                        <select class="form-select" id="account_id" name="account_id" required>
                            <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small" for="pay_date">Payment date</label>
                        <input type="date" class="form-control" id="pay_date" name="pay_date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small" for="reference">Reference</label>
                        <input class="form-control" id="reference" name="reference" maxlength="100" placeholder="Cheque / transfer no.">
                    </div>
                    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fa-solid fa-money-bill-transfer me-1"></i> Mark paid</button></div>
                </div>
                <?php $withEmail = count(array_filter($slips, fn($s) => $s['email'])); ?>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="email_payslips" name="email_payslips" value="1" <?= $withEmail ? 'checked' : 'disabled' ?>>
                    <label class="form-check-label" for="email_payslips">
                        Email each employee their own payslip
                        <span class="text-body-secondary small">(<?= $withEmail ?> of <?= count($slips) ?> have an email address<?= $withEmail < count($slips) ? ' — add the missing ones on their employee record' : '' ?>)</span>
                    </label>
                </div>
            <?php endif ?>
        </div>
    </form>
<?php endif ?>

<?php if ($run['status'] === 'paid'):
    $emailed = count(array_filter($slips, fn($s) => $s['emailed_at']));
?>
    <div class="acc-card mb-3 d-print-none">
        <div class="acc-card-head">
            <h2><i class="fa-regular fa-envelope me-1"></i> Payslip emails</h2>
            <span class="small text-body-secondary"><?= $emailed ?> of <?= count($slips) ?> sent</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <tbody>
                <?php foreach ($slips as $s): ?>
                    <tr>
                        <td><?= e($s['full_name']) ?><div class="text-body-secondary"><?= e($s['email'] ?: 'No email address') ?></div></td>
                        <td>
                            <?php if ($s['email_error']): ?>
                                <span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($s['email_error']) ?></span>
                                <?php if (!$s['email']): ?> <a href="employee-edit.php?id=<?= (int)$s['employee_id'] ?>">Add email</a><?php endif ?>
                            <?php elseif ($s['emailed_at']): ?>
                                <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>Sent <?= e(date('d M Y, H:i', strtotime($s['emailed_at']))) ?></span>
                            <?php else: ?>
                                <span class="text-body-secondary">Not sent</span>
                            <?php endif ?>
                        </td>
                        <td class="text-end">
                            <?php if ($canEdit && $s['email']): ?>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="email_one">
                                    <input type="hidden" name="employee_id" value="<?= (int)$s['employee_id'] ?>">
                                    <button class="btn btn-sm btn-link"><?= $s['emailed_at'] ? 'Resend' : 'Send' ?></button>
                                </form>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit): ?>
            <form method="post" class="border-top px-4 py-3 d-flex flex-wrap gap-2 justify-content-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email">
                <?php if ($emailed < count($slips)): ?>
                    <button name="only_unsent" value="1" class="btn btn-sm btn-primary"><i class="fa-regular fa-paper-plane me-1"></i> Send to everyone not yet emailed</button>
                <?php endif ?>
                <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Email every employee their payslip again?')">Resend to all</button>
            </form>
        <?php endif ?>
    </div>
<?php endif ?>

<?php if (in_array($run['status'], ['posted', 'paid'], true) && $user['role'] === 'admin'): ?>
    <form method="post" class="acc-card d-print-none" onsubmit="return confirm('Void payroll <?= e($period) ?>? Its vouchers will be voided and the month can be run again.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="void">
        <div class="acc-card-body d-flex flex-wrap align-items-end gap-2">
            <div class="flex-grow-1">
                <label class="form-label fw-semibold small" for="void_reason">Need to correct a posted payroll? Void it (admin only)</label>
                <input class="form-control form-control-sm" id="void_reason" name="void_reason" required maxlength="200" placeholder="Reason (required)">
            </div>
            <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-ban me-1"></i> Void payroll</button>
        </div>
    </form>
<?php endif ?>

<?php
if ($isDraft) {
    $inlineScript = '(function () {
        document.querySelectorAll(".tds-override").forEach(cb => cb.addEventListener("change", () => {
            const input = cb.closest("td").querySelector("input[name$=\"[tds]\"]");
            input.readOnly = !cb.checked;
            if (cb.checked) input.focus();
        }));
        let dirty = false;
        const form = document.getElementById("payrollForm");
        form.addEventListener("input", () => { dirty = true; });
        form.addEventListener("submit", () => { dirty = false; });
        window.addEventListener("beforeunload", e => { if (dirty) { e.preventDefault(); e.returnValue = ""; } });
    })();';
}
require __DIR__ . '/includes/layout-bottom.php';
