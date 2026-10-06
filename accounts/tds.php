<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);
$isAdmin = $user['role'] === 'admin';

$tab = ($_GET['tab'] ?? '') === 'receivable' ? 'receivable' : 'payable';
[$py, $pm] = parse_period((string)($_GET['period'] ?? '')) ?? last_finished_bs_period();
$periodKey = period_key($py, $pm);
$fy = fiscal_year_for(bs_month_bounds($py, $pm)[0]);
if ($tab === 'receivable' && in_array($_GET['fy'] ?? '', fiscal_year_options(), true)) $fy = $_GET['fy'];

const TDS_CATEGORY_LABELS = [
    'contract' => 'Contract / subcontract (1.5%)', 'service' => 'Service fee', 'rent' => 'Rent (10%)',
    'salary' => 'Salary (remuneration)', 'other' => 'Other', 'none' => 'Other',
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    try {
        if (($_POST['action'] ?? '') === 'deposit') {
            deposit_tds($py, $pm, (string)($_POST['filing_date'] ?? ''), (string)($_POST['amount'] ?? ''), (int)($_POST['account_id'] ?? 0), trim((string)($_POST['reference'] ?? '')), (int)$user['id']);
            flash('success', 'TDS deposit for ' . period_label($py, $pm) . ' recorded.');
        } elseif (($_POST['action'] ?? '') === 'void' && $isAdmin) {
            void_tax_filing((int)($_POST['filing_id'] ?? 0), (int)$user['id'], (string)($_POST['reason'] ?? ''));
            flash('success', 'TDS deposit voided.');
        }
        redirect('tds.php?period=' . $periodKey);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$s = tds_summary($py, $pm);
$filing = active_filing('tds', $periodKey);
$tdsPayableBalance = account_movement(system_account_id('tds_payable'), null, date('Y-m-d'));
$tdsPayableBalance = $tdsPayableBalance['cr'] - $tdsPayableBalance['dr'];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"tds-{$periodKey}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [setting('company_name'), 'TDS withheld — ' . period_label($py, $pm), 'PAN ' . setting('pan_number')]);
    fputcsv($out, ['Date (BS)', 'Payee', 'PAN', 'Category', 'Document', 'Amount paid', 'Rate %', 'TDS']);
    foreach ($s['rows'] as $r) fputcsv($out, [bs_date($r['date']), $r['payee'], $r['pan'], TDS_CATEGORY_LABELS[$r['category']] ?? $r['category'], $r['document'],
        $r['base'] !== null ? cents_to_decimal($r['base']) : '', $r['rate'] ?? '', cents_to_decimal($r['tds'])]);
    exit;
}

$year = [];
foreach (fiscal_year_months($fy) as [$y, $m]) {
    [$f, $t] = bs_month_bounds($y, $m);
    if ($f > date('Y-m-d')) { $year[] = ['y' => $y, 'm' => $m, 'future' => true]; continue; }
    $mv = account_movement(system_account_id('tds_payable'), $f, $t, ['tds_deposit']);
    $year[] = ['y' => $y, 'm' => $m, 'future' => false, 'withheld' => $mv['cr'] - $mv['dr'], 'filing' => active_filing('tds', period_key($y, $m)), 'due' => monthly_tax_due_date($y, $m)];
}
$receivable = $tab === 'receivable' ? tds_receivable_rows($fy) : [];
$recBal = account_movement(system_account_id('tds_receivable'), null, date('Y-m-d'));
$accounts = payment_account_options();
$mc = fn(int $c) => e(money_cents($c));

$pageTitle   = 'TDS';
$activeNav   = 'tds';
$breadcrumbs = [['label' => 'TDS'], ['label' => $tab === 'payable' ? period_label($py, $pm) : 'Receivable FY ' . $fy]];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<nav class="acc-subnav d-print-none">
    <a href="tds.php?period=<?= $periodKey ?>" class="<?= $tab === 'payable' ? 'active' : '' ?>"><i class="fa-solid fa-hand-holding-dollar"></i> Withheld by us (to deposit)</a>
    <a href="tds.php?tab=receivable&fy=<?= e($fy) ?>" class="<?= $tab === 'receivable' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Withheld by clients (our credit)</a>
</nav>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3"><div class="acc-card acc-kpi"><div class="acc-kpi-top"><span class="acc-kpi-label">TDS payable now</span><span class="acc-kpi-icon acc-tone-red"><i class="fa-solid fa-landmark"></i></span></div><div class="acc-kpi-value"><?= e(money_cents($tdsPayableBalance, true)) ?></div><div class="acc-kpi-note">Withheld, not yet deposited</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="acc-card acc-kpi"><div class="acc-kpi-top"><span class="acc-kpi-label">TDS receivable</span><span class="acc-kpi-icon acc-tone-green"><i class="fa-solid fa-receipt"></i></span></div><div class="acc-kpi-value"><?= e(money_cents($recBal['dr'] - $recBal['cr'], true)) ?></div><div class="acc-kpi-note">Withheld by clients — credit against income tax</div></div></div>
</div>

<?php if ($tab === 'payable'): ?>
<div class="acc-card mb-3 d-print-none">
    <div class="acc-card-head"><h2>FY <?= e($fy) ?> — monthly TDS</h2><span class="small text-body-secondary">Deposit by the 25th of the following month</span></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Month</th><th class="num">Withheld</th><th class="num">Deposited</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($year as $r): $k = period_key($r['y'], $r['m']); ?>
                <tr class="<?= $k === $periodKey ? 'table-active' : '' ?>" style="cursor:pointer" onclick="location.href='tds.php?period=<?= $k ?>'">
                    <td><a href="tds.php?period=<?= $k ?>" class="text-decoration-none <?= $k === $periodKey ? 'fw-bold' : '' ?>"><?= e(period_label($r['y'], $r['m'])) ?></a></td>
                    <?php if ($r['future']): ?><td colspan="2"></td><td><span class="text-body-tertiary small">Not started</span></td>
                    <?php else: ?>
                        <td class="num"><?= $r['withheld'] ? $mc($r['withheld']) : '–' ?></td>
                        <td class="num"><?= $r['filing'] ? e(money($r['filing']['amount'], false)) : '–' ?></td>
                        <td><?= filing_status_badge($r['filing'], $r['due'], $r['withheld'] !== 0) ?></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Withheld in <?= e(period_label($py, $pm)) ?></h2><a class="btn btn-sm btn-outline-secondary d-print-none" href="?period=<?= $periodKey ?>&export=csv"><i class="fa-solid fa-file-csv me-1"></i> CSV</a></div>
            <?php if (!$s['rows']): ?>
                <div class="acc-card-body small text-body-secondary">No TDS was withheld in <?= e(period_label($py, $pm)) ?>.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Payee</th><th>PAN</th><th>For</th><th class="num">Amount paid</th><th class="num">TDS</th></tr></thead>
                        <tbody>
                        <?php foreach ($s['rows'] as $r): ?>
                            <tr style="cursor:pointer" onclick="location.href='journal-entry.php?id=<?= (int)$r['entry_id'] ?>'">
                                <td><?= e($r['payee']) ?><div class="small text-body-secondary"><?= e(TDS_CATEGORY_LABELS[$r['category']] ?? $r['category']) ?></div></td>
                                <td class="acc-code <?= $r['pan'] ? '' : 'text-danger' ?>"><?= e($r['pan'] ?? 'missing') ?></td>
                                <td class="small"><?= e($r['document']) ?><div class="text-body-secondary"><?= e(bs_date($r['date'])) ?></div></td>
                                <td class="num"><?= $r['base'] !== null ? $mc($r['base']) : '–' ?><?= $r['rate'] ? '<div class="small text-body-secondary">@ ' . e(rtrim(rtrim(number_format($r['rate'], 2), '0'), '.')) . '%</div>' : '' ?></td>
                                <td class="num fw-semibold"><?= $mc($r['tds']) ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                        <tfoot><tr class="table-total"><td colspan="4">Total withheld</td><td class="num"><?= $mc($s['withheld']) ?></td></tr></tfoot>
                    </table>
                </div>
                <?php if (array_filter($s['rows'], fn($r) => !$r['pan'])): ?><div class="px-3 py-2 small text-danger border-top">Some payees have no PAN — add it on their record; IRD needs it for TDS credit.</div><?php endif ?>
            <?php endif ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="acc-card mb-3">
            <div class="acc-card-head"><h2>By category</h2></div>
            <table class="table table-sm mb-0"><tbody>
                <?php foreach ($s['by_category'] as $cat => $amt): ?><tr><td><?= e(TDS_CATEGORY_LABELS[$cat] ?? $cat) ?></td><td class="num"><?= $mc($amt) ?></td></tr><?php endforeach ?>
                <?php if (!$s['by_category']): ?><tr><td class="text-body-secondary small">Nothing withheld.</td></tr><?php endif ?>
            </tbody></table>
        </div>
        <div class="acc-card">
            <div class="acc-card-head"><h2>Deposit to IRD</h2><?= filing_status_badge($filing, $s['due'], $s['withheld'] !== 0) ?></div>
            <?php if ($filing): ?>
                <div class="acc-card-body small">
                    Deposited <strong><?= e(money($filing['amount'])) ?></strong> on <?= e(bs_date($filing['filing_date'])) ?> B.S.<?= $filing['reference'] ? ' · ref ' . e($filing['reference']) : '' ?>
                    · <a href="journal-entry.php?id=<?= (int)$filing['journal_entry_id'] ?>"><?= e($filing['voucher_no']) ?></a>
                    <?php if ($isAdmin): ?>
                        <form method="post" class="mt-2 d-print-none" data-confirm="Void this TDS deposit? Its voucher will be cancelled." data-confirm-reason="Reason for voiding" data-confirm-ok="Void">
                            <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="filing_id" value="<?= (int)$filing['id'] ?>"><input type="hidden" name="reason">
                            <button class="btn btn-sm btn-link text-danger p-0">Void (admin)</button>
                        </form>
                    <?php endif ?>
                </div>
            <?php elseif ($canEdit && $s['balance_at_end'] > 0): ?>
                <form method="post" class="acc-card-body d-print-none">
                    <?= csrf_field() ?><input type="hidden" name="action" value="deposit">
                    <p class="small text-body-secondary">Payable at month end: <?= e(money_cents($s['balance_at_end'], true)) ?><?= $s['balance_at_end'] > $s['withheld'] ? ' (includes earlier months not yet deposited)' : '' ?>.</p>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label small fw-semibold mb-1" for="amount">Amount</label><input class="form-control form-control-sm text-end" id="amount" name="amount" value="<?= e(cents_to_decimal($s['balance_at_end'])) ?>" inputmode="decimal"></div>
                        <div class="col-6"><label class="form-label small fw-semibold mb-1" for="filing_date">Date</label><input type="date" class="form-control form-control-sm" id="filing_date" name="filing_date" value="<?= e(date('Y-m-d')) ?>"></div>
                        <div class="col-12"><label class="form-label small fw-semibold mb-1" for="account_id">Paid from</label><select class="form-select form-select-sm" id="account_id" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?></select></div>
                        <div class="col-12"><label class="form-label small fw-semibold mb-1" for="reference">IRD challan / voucher no.</label><input class="form-control form-control-sm" id="reference" name="reference" maxlength="100"></div>
                        <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="fa-solid fa-check me-1"></i> Record deposit</button></div>
                    </div>
                </form>
            <?php else: ?>
                <div class="acc-card-body small text-body-secondary">Nothing to deposit for this month.</div>
            <?php endif ?>
        </div>
    </div>
</div>

<?php else: ?>
<div class="acc-card">
    <div class="acc-card-head">
        <h2>TDS withheld by clients — FY <?= e($fy) ?></h2>
        <form method="get" class="d-flex gap-2"><input type="hidden" name="tab" value="receivable">
            <select class="form-select form-select-sm" name="fy" onchange="this.form.submit()"><?php foreach (fiscal_year_options() as $opt): ?><option <?= $opt === $fy ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach ?></select></form>
    </div>
    <?php if (!$receivable): ?>
        <div class="acc-card-body small text-body-secondary">No sales invoices with TDS in FY <?= e($fy) ?>. Set the TDS % on a sales invoice when the client deducts tax from your bill.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Invoice</th><th>Client</th><th>Client PAN</th><th class="num">Invoice amount (excl. VAT)</th><th class="num">TDS</th></tr></thead>
                <tbody>
                <?php foreach ($receivable as $r): ?>
                    <tr style="cursor:pointer" onclick="location.href='invoice.php?id=<?= (int)$r['id'] ?>'">
                        <td class="small"><?= e(bs_date($r['invoice_date'])) ?></td>
                        <td><a class="text-decoration-none" href="invoice.php?id=<?= (int)$r['id'] ?>"><?= e($r['number']) ?></a></td>
                        <td><?= e($r['name']) ?></td>
                        <td class="acc-code"><?= e($r['pan_number'] ?? '—') ?></td>
                        <td class="num"><?= $mc(decimal_to_cents($r['taxable_amount']) + decimal_to_cents($r['exempt_amount'])) ?></td>
                        <td class="num fw-semibold"><?= e(money($r['tds_amount'], false)) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot><tr class="table-total"><td colspan="5">Total</td><td class="num"><?= $mc(array_sum(array_map(fn($r) => decimal_to_cents($r['tds_amount']), $receivable))) ?></td></tr></tfoot>
            </table>
        </div>
        <div class="px-3 py-2 small text-body-secondary border-top">Check each amount against the client's TDS certificate / your IRD tax-ledger; it reduces your <a href="income-tax.php?fy=<?= e($fy) ?>">income tax</a> for the year.</div>
    <?php endif ?>
</div>
<?php endif ?>

<?php require __DIR__ . '/includes/layout-bottom.php';
