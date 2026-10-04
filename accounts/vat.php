<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);
$isAdmin = $user['role'] === 'admin';

[$py, $pm] = parse_period((string)($_GET['period'] ?? '')) ?? last_finished_bs_period();
$periodKey = period_key($py, $pm);
$fy = fiscal_year_for(bs_month_bounds($py, $pm)[0]);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    try {
        if (($_POST['action'] ?? '') === 'settle') {
            settle_vat($py, $pm, (string)($_POST['filing_date'] ?? ''), (int)($_POST['account_id'] ?? 0), trim((string)($_POST['reference'] ?? '')), (int)$user['id']);
            flash('success', 'VAT return for ' . period_label($py, $pm) . ' recorded.');
        } elseif (($_POST['action'] ?? '') === 'void' && $isAdmin) {
            void_tax_filing((int)($_POST['filing_id'] ?? 0), (int)$user['id'], (string)($_POST['reason'] ?? ''));
            flash('success', 'VAT filing voided.');
        }
        redirect('vat.php?period=' . $periodKey);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$s = vat_summary($py, $pm);
$filing = active_filing('vat', $periodKey);

// CSV exports in the IRD sales / purchase book layout.
if (in_array($_GET['export'] ?? '', ['sales', 'purchase'], true)) {
    $type = $_GET['export'];
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$type}-register-{$periodKey}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [setting('company_name'), ($type === 'sales' ? 'Sales' : 'Purchase') . ' register — ' . period_label($py, $pm), 'VAT/PAN ' . setting('vat_number', setting('pan_number'))]);
    fputcsv($out, ['Date (BS)', $type === 'sales' ? 'Invoice no.' : 'Bill no.', $type === 'sales' ? "Buyer's name" : "Supplier's name", 'PAN', 'Total', 'Non-taxable', 'Taxable amount', 'VAT']);
    foreach ($s['registers'][$type] as $r) {
        fputcsv($out, [bs_date($r['invoice_date']), $r['number'], $r['party'], $r['pan'], $r['total_amount'], $r['exempt_amount'], $r['taxable_amount'], $r['vat_amount']]);
    }
    exit;
}

// Year overview.
$year = [];
foreach (fiscal_year_months($fy) as [$y, $m]) {
    [$f, $t] = bs_month_bounds($y, $m);
    if ($f > date('Y-m-d')) { $year[] = ['y' => $y, 'm' => $m, 'future' => true]; continue; }
    $o = account_movement(system_account_id('vat_output'), $f, $t, ['vat_settlement']);
    $i = account_movement(system_account_id('vat_input'), $f, $t, ['vat_settlement']);
    $year[] = ['y' => $y, 'm' => $m, 'future' => false, 'output' => $o['cr'] - $o['dr'], 'input' => $i['dr'] - $i['cr'],
        'activity' => (bool)($o['dr'] || $o['cr'] || $i['dr'] || $i['cr']), 'filing' => active_filing('vat', period_key($y, $m)), 'due' => monthly_tax_due_date($y, $m)];
}
$accounts = payment_account_options();
$mc = fn(int $c) => e(money_cents($c));

$pageTitle   = 'VAT';
$activeNav   = 'vat';
$breadcrumbs = [['label' => 'VAT'], ['label' => period_label($py, $pm)]];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>
<?php if (!setting('vat_number')): ?>
    <div class="alert alert-warning small"><i class="fa-solid fa-circle-info me-1"></i> Your VAT registration number isn't set — add it under <a href="settings.php#tax">Settings → Tax</a> so it prints on invoices and registers.</div>
<?php endif ?>

<div class="acc-card mb-3 d-print-none">
    <div class="acc-card-head"><h2>FY <?= e($fy) ?> — monthly VAT returns</h2><span class="small text-body-secondary">Due by the 25th of the following month</span></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Month</th><th class="num">Output VAT</th><th class="num">Input VAT</th><th class="num">Net</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($year as $r): $k = period_key($r['y'], $r['m']); ?>
                <tr class="<?= $k === $periodKey ? 'table-active' : '' ?>" style="cursor:pointer" onclick="location.href='vat.php?period=<?= $k ?>'">
                    <td><a href="vat.php?period=<?= $k ?>" class="text-decoration-none <?= $k === $periodKey ? 'fw-bold' : '' ?>"><?= e(period_label($r['y'], $r['m'])) ?></a></td>
                    <?php if ($r['future']): ?>
                        <td colspan="3"></td><td><span class="text-body-tertiary small">Not started</span></td>
                    <?php else: ?>
                        <td class="num"><?= $r['output'] ? $mc($r['output']) : '–' ?></td>
                        <td class="num"><?= $r['input'] ? $mc($r['input']) : '–' ?></td>
                        <td class="num fw-semibold <?= $r['output'] - $r['input'] < 0 ? 'text-success' : '' ?>"><?= $r['activity'] ? $mc($r['output'] - $r['input']) : '–' ?></td>
                        <td><?= filing_status_badge($r['filing'], $r['due'], $r['activity']) ?></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 py-2 border-top small"><form method="get" class="d-flex gap-2 align-items-center">
        <label class="text-body-secondary" for="fyPick">Other year</label>
        <select class="form-select form-select-sm" style="width:auto" id="fyPick" name="period" onchange="this.form.submit()">
            <?php foreach (fiscal_year_options() as $opt): $first = period_key((int)substr($opt, 0, 4), 4); ?><option value="<?= $first ?>" <?= $opt === $fy ? 'selected' : '' ?>>FY <?= e($opt) ?></option><?php endforeach ?>
        </select>
    </form></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>VAT return — <?= e(period_label($py, $pm)) ?></h2><span class="small text-body-secondary"><?= e(bs_date($s['from'])) ?> → <?= e(bs_date($s['to'])) ?></span></div>
            <table class="table table-sm mb-0 acc-inv-totals">
                <tbody>
                    <tr><td>Sales — taxable</td><td class="num"><?= $mc(array_sum(array_map(fn($r) => decimal_to_cents($r['taxable_amount']), $s['registers']['sales']))) ?></td></tr>
                    <tr><td>Sales — non-taxable / exempt</td><td class="num"><?= $mc(array_sum(array_map(fn($r) => decimal_to_cents($r['exempt_amount']), $s['registers']['sales']))) ?></td></tr>
                    <tr><td>Purchases — taxable</td><td class="num"><?= $mc(array_sum(array_map(fn($r) => decimal_to_cents($r['taxable_amount']), $s['registers']['purchase']))) ?></td></tr>
                    <tr><td>Purchases — non-taxable</td><td class="num"><?= $mc(array_sum(array_map(fn($r) => decimal_to_cents($r['exempt_amount']), $s['registers']['purchase']))) ?></td></tr>
                    <tr class="table-subtotal"><td>Output VAT (on sales)</td><td class="num"><?= $mc($s['output']) ?></td></tr>
                    <tr class="table-subtotal"><td>− Input VAT (on purchases)</td><td class="num"><?= $mc($s['input']) ?></td></tr>
                    <tr class="table-total"><td><?= $s['net'] >= 0 ? 'VAT payable for the month' : 'Excess input credit for the month' ?></td><td class="num"><?= $mc(abs($s['net'])) ?></td></tr>
                </tbody>
            </table>
            <?php if ($s['output_adjustment'] || $s['input_adjustment']): ?>
                <div class="px-3 py-2 small text-body-secondary border-top">Includes manual journal adjustments: output <?= $mc($s['output_adjustment']) ?>, input <?= $mc($s['input_adjustment']) ?>.</div>
            <?php endif ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Settlement</h2><?= filing_status_badge($filing, $s['due'], $s['output'] || $s['input']) ?></div>
            <?php if ($filing): ?>
                <div class="acc-card-body small">
                    <p class="mb-2">Filed on <strong><?= e(bs_date($filing['filing_date'])) ?> B.S.</strong>
                        <?= (float)$filing['amount'] ? '— paid <strong>' . e(money($filing['amount'])) . '</strong>' : '— nothing to pay' ?>
                        <?= $filing['reference'] ? ' · IRD ref ' . e($filing['reference']) : '' ?>
                        <?= $filing['voucher_no'] ? ' · <a href="journal-entry.php?id=' . (int)$filing['journal_entry_id'] . '">' . e($filing['voucher_no']) . '</a>' : '' ?></p>
                    <?php if ($filing['notes']): ?><p class="text-body-secondary mb-2"><?= e($filing['notes']) ?></p><?php endif ?>
                    <?php if ($isAdmin): ?>
                        <form method="post" class="d-print-none" onsubmit="const r = prompt('Reason for voiding this VAT filing?'); if (!r) return false; this.reason.value = r;">
                            <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="filing_id" value="<?= (int)$filing['id'] ?>"><input type="hidden" name="reason">
                            <button class="btn btn-sm btn-link text-danger p-0">Void this filing (admin)</button>
                        </form>
                    <?php endif ?>
                </div>
            <?php else: ?>
                <table class="table table-sm mb-0 acc-inv-totals">
                    <tbody>
                        <tr><td>Output VAT owed at month end <span class="text-body-secondary">(incl. earlier unpaid)</span></td><td class="num"><?= $mc($s['output_balance']) ?></td></tr>
                        <tr><td>Input VAT credit at month end <span class="text-body-secondary">(incl. carried forward)</span></td><td class="num"><?= $mc($s['input_balance']) ?></td></tr>
                        <tr><td>Set off</td><td class="num"><?= $mc($s['setoff']) ?></td></tr>
                        <tr class="table-total"><td>Pay to IRD</td><td class="num"><?= e(money_cents($s['payable'], true)) ?></td></tr>
                        <?php if ($s['carry_forward']): ?><tr><td>Credit carried forward to next month</td><td class="num text-success"><?= $mc($s['carry_forward']) ?></td></tr><?php endif ?>
                    </tbody>
                </table>
                <?php if ($canEdit && date('Y-m-d') > $s['to']): ?>
                    <form method="post" class="px-3 py-3 border-top d-print-none">
                        <?= csrf_field() ?><input type="hidden" name="action" value="settle">
                        <div class="row g-2">
                            <div class="col-sm-4"><label class="form-label small fw-semibold mb-1" for="filing_date">Filing / payment date</label><input type="date" class="form-control form-control-sm" id="filing_date" name="filing_date" value="<?= e(date('Y-m-d')) ?>"></div>
                            <?php if ($s['payable']): ?>
                                <div class="col-sm-4"><label class="form-label small fw-semibold mb-1" for="account_id">Paid from</label>
                                    <select class="form-select form-select-sm" id="account_id" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?></select></div>
                            <?php endif ?>
                            <div class="col-sm-4"><label class="form-label small fw-semibold mb-1" for="reference">IRD voucher / ref</label><input class="form-control form-control-sm" id="reference" name="reference" maxlength="100"></div>
                            <div class="col-12"><button class="btn btn-sm btn-primary" onclick="return confirm('Record the VAT return for <?= e(period_label($py, $pm)) ?>?')"><i class="fa-solid fa-check me-1"></i> <?= $s['payable'] ? 'Record return &amp; payment' : 'Record return (nothing to pay)' ?></button></div>
                        </div>
                    </form>
                <?php elseif ($canEdit): ?>
                    <div class="px-3 py-2 border-top small text-body-secondary">The return can be recorded once the month has ended.</div>
                <?php endif ?>
            <?php endif ?>
        </div>
    </div>
</div>

<?php foreach (['sales' => 'Sales register', 'purchase' => 'Purchase register'] as $type => $title): $rows = $s['registers'][$type]; ?>
    <div class="acc-card mb-3">
        <div class="acc-card-head"><h2><?= $title ?></h2><a class="btn btn-sm btn-outline-secondary d-print-none" href="?period=<?= $periodKey ?>&export=<?= $type ?>"><i class="fa-solid fa-file-csv me-1"></i> CSV</a></div>
        <?php if (!$rows): ?>
            <div class="acc-card-body small text-body-secondary">No posted <?= $type === 'sales' ? 'sales invoices' : 'purchase bills' ?> in <?= e(period_label($py, $pm)) ?>.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th><?= $type === 'sales' ? 'Invoice' : 'Bill' ?></th><th><?= $type === 'sales' ? 'Buyer' : 'Supplier' ?></th><th>PAN</th><th class="num">Non-taxable</th><th class="num">Taxable</th><th class="num">VAT</th><th class="num">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr style="cursor:pointer" onclick="location.href='invoice.php?id=<?= (int)$r['id'] ?>'">
                            <td class="small text-nowrap"><?= e(bs_date($r['invoice_date'])) ?></td>
                            <td class="text-nowrap"><a class="text-decoration-none" href="invoice.php?id=<?= (int)$r['id'] ?>"><?= e($r['number']) ?></a></td>
                            <td><?= e($r['party']) ?></td>
                            <td class="acc-code"><?= e($r['pan'] ?? '—') ?></td>
                            <td class="num"><?= e(money($r['exempt_amount'], false)) ?></td>
                            <td class="num"><?= e(money($r['taxable_amount'], false)) ?></td>
                            <td class="num"><?= e(money($r['vat_amount'], false)) ?></td>
                            <td class="num"><?= e(money($r['total_amount'], false)) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                    <tfoot><tr class="table-total"><td colspan="4">Total</td>
                        <?php foreach (['exempt_amount', 'taxable_amount', 'vat_amount', 'total_amount'] as $col): ?><td class="num"><?= $mc(array_sum(array_map(fn($r) => decimal_to_cents($r[$col]), $rows))) ?></td><?php endforeach ?>
                    </tr></tfoot>
                </table>
            </div>
        <?php endif ?>
    </div>
<?php endforeach ?>
<p class="small text-body-secondary">Figures come from posted invoices and vouchers. Record the return here after filing it on the IRD portal; it sets off output against input VAT and posts any payment.</p>

<?php require __DIR__ . '/includes/layout-bottom.php';
