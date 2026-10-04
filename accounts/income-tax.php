<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);
$isAdmin = $user['role'] === 'admin';

$fy = in_array($_GET['fy'] ?? '', fiscal_year_options(), true) ? $_GET['fy'] : fiscal_year_for(date('Y-m-d'));
$fyKey = str_replace('/', '_', $fy);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'settings' && $isAdmin) {
            $rate = trim((string)($_POST['rate'] ?? ''));
            if (!is_numeric($rate) || $rate < 0 || $rate > 100) throw new DomainException('Tax rate must be between 0 and 100%.');
            $estimate = trim((string)($_POST['estimate'] ?? ''));
            $estimateCents = $estimate === '' ? null : to_cents($estimate);
            if ($estimate !== '' && $estimateCents === null) throw new DomainException('Estimated taxable profit must be an amount.');
            $st = db()->prepare('INSERT INTO acc_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $st->execute(['income_tax_rate', (string)(float)$rate]);
            if ($estimateCents === null) db()->prepare('DELETE FROM acc_settings WHERE setting_key = ?')->execute(['it_estimate_' . $fyKey]);
            else $st->execute(['it_estimate_' . $fyKey, cents_to_decimal($estimateCents)]);
            audit('update_income_tax_settings', 'settings', null, "rate {$rate}%, FY {$fy} estimate " . ($estimate ?: 'from books'));
            flash('success', 'Saved.');
        } elseif ($action === 'pay') {
            pay_advance_tax($fy, (int)($_POST['instalment'] ?? 0), (string)($_POST['filing_date'] ?? ''), (string)($_POST['amount'] ?? ''), (int)($_POST['account_id'] ?? 0), trim((string)($_POST['reference'] ?? '')), (int)$user['id']);
            flash('success', 'Advance tax payment recorded.');
        } elseif ($action === 'provision' && $isAdmin) {
            book_tax_provision($fy, (string)($_POST['filing_date'] ?? ''), (string)($_POST['amount'] ?? ''), (int)$user['id']);
            flash('success', 'Income tax provision booked for FY ' . $fy . '.');
        } elseif ($action === 'void' && $isAdmin) {
            void_tax_filing((int)($_POST['filing_id'] ?? 0), (int)$user['id'], (string)($_POST['reason'] ?? ''));
            flash('success', 'Voided.');
        }
        redirect('income-tax.php?fy=' . urlencode($fy));
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$t = income_tax_summary($fy);
[$fyFrom, $fyTo] = fiscal_year_range($fy);
$accounts = payment_account_options();
$mc = fn(int $c) => e(money_cents($c));
$nextDue = null;
foreach ($t['instalments'] as $n => $i) if ($i['shortfall'] > 0) { $nextDue = $n; break; }

$pageTitle   = 'Income Tax';
$activeNav   = 'income-tax';
$breadcrumbs = [['label' => 'Income Tax'], ['label' => 'FY ' . $fy]];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none">
    <form method="get"><select class="form-select form-select-sm" name="fy" onchange="this.form.submit()"><?php foreach (fiscal_year_options() as $opt): ?><option value="<?= e($opt) ?>" <?= $opt === $fy ? 'selected' : '' ?>>FY <?= e($opt) ?></option><?php endforeach ?></select></form>
    <span class="small text-body-secondary"><?= e(bs_date($fyFrom)) ?> → <?= e(bs_date($fyTo)) ?> B.S.</span>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Profit so far (books)</span><div class="acc-kpi-value <?= $t['actual']['profit'] < 0 ? 'text-danger' : '' ?>"><?= $mc($t['actual']['profit']) ?></div><div class="acc-kpi-note">Income <?= $mc($t['actual']['income']) ?> − expenses <?= $mc($t['actual']['expense']) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Estimated tax</span><div class="acc-kpi-value"><?= $mc($t['tax']) ?></div><div class="acc-kpi-note"><?= e(rtrim(rtrim(number_format($t['rate'], 2), '0'), '.')) ?>% of <?= $mc($t['estimate']) ?><?= $t['estimate_is_custom'] ? ' (your estimate)' : '' ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Paid / credited</span><div class="acc-kpi-value text-success"><?= $mc($t['paid'] + $t['tds_credit']) ?></div><div class="acc-kpi-note">Advance tax <?= $mc($t['paid']) ?> + client TDS <?= $mc($t['tds_credit']) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Still to pay</span><div class="acc-kpi-value <?= $t['remaining'] ? 'text-danger' : '' ?>"><?= $mc($t['remaining']) ?></div><div class="acc-kpi-note">For the year, on this estimate</div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="acc-card mb-3">
            <div class="acc-card-head"><h2>Advance tax instalments</h2><span class="small text-body-secondary">Income Tax Act §94</span></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Instalment</th><th>Due by</th><th class="num">Cumulative due</th><th class="num">Short</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($t['instalments'] as $n => $i): $late = $i['shortfall'] > 0 && date('Y-m-d') > $i['due']; ?>
                        <tr>
                            <td><?= e($i['label']) ?> <span class="text-body-secondary small"><?= $i['percent'] ?>%</span></td>
                            <td class="small"><?= e(bs_date($i['due'])) ?> B.S.<div class="text-body-secondary"><?= e(date('d M Y', strtotime($i['due']))) ?></div></td>
                            <td class="num"><?= $mc($i['cumulative']) ?></td>
                            <td class="num <?= $i['shortfall'] ? 'fw-semibold' : '' ?>"><?= $i['shortfall'] ? $mc($i['shortfall']) : '–' ?></td>
                            <td><?= !$i['shortfall'] ? '<span class="badge text-bg-success">Covered</span>' : ($late ? '<span class="badge text-bg-danger">Overdue</span>' : '<span class="badge text-bg-warning">Due</span>') ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <div class="px-3 py-2 small text-body-secondary border-top">Cumulative due is the share of estimated tax payable by each date; client TDS and advance tax already paid count towards it. Late instalments attract interest under §118 — confirm figures with your auditor.</div>
        </div>

        <div class="acc-card">
            <div class="acc-card-head"><h2>Payments &amp; provision — FY <?= e($fy) ?></h2></div>
            <?php if (!$t['filings']): ?>
                <div class="acc-card-body small text-body-secondary">No advance tax paid yet this year.</div>
            <?php else: ?>
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($t['filings'] as $f): ?>
                        <tr>
                            <td class="small"><?= e(bs_date($f['filing_date'])) ?></td>
                            <td><?= $f['period'] === 'provision' ? 'Year-end provision' : e(INCOME_TAX_INSTALMENTS[(int)substr($f['period'], -1)]['label'] ?? $f['period']) ?>
                                <div class="small text-body-secondary"><a href="journal-entry.php?id=<?= (int)$f['journal_entry_id'] ?>"><?= e($f['voucher_no']) ?></a><?= $f['reference'] ? ' · ' . e($f['reference']) : '' ?><?= $f['account_name'] ? ' · ' . e($f['account_name']) : '' ?></div></td>
                            <td class="num"><?= e(money($f['amount'], false)) ?></td>
                            <td class="text-end">
                                <?php if ($isAdmin): ?>
                                    <form method="post" class="d-print-none" onsubmit="const r = prompt('Reason for voiding?'); if (!r) return false; this.reason.value = r;">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="filing_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="reason">
                                        <button class="btn btn-sm btn-link text-danger p-0">Void</button>
                                    </form>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            <?php endif ?>
        </div>
    </div>

    <div class="col-lg-5 d-print-none">
        <?php if ($canEdit): ?>
            <form method="post" class="acc-card mb-3">
                <?= csrf_field() ?><input type="hidden" name="action" value="pay">
                <div class="acc-card-head"><h2>Record advance tax paid</h2></div>
                <div class="acc-card-body">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label small fw-semibold mb-1" for="instalment">Instalment</label>
                            <select class="form-select form-select-sm" id="instalment" name="instalment"><?php foreach (INCOME_TAX_INSTALMENTS as $n => $i): ?><option value="<?= $n ?>" <?= $n === $nextDue ? 'selected' : '' ?>><?= e($i['label']) ?> (by <?= e(bs_date($t['instalments'][$n]['due'])) ?>)</option><?php endforeach ?></select></div>
                        <div class="col-6"><label class="form-label small fw-semibold mb-1" for="amount">Amount</label><input class="form-control form-control-sm text-end" id="amount" name="amount" value="<?= $nextDue ? e(cents_to_decimal($t['instalments'][$nextDue]['shortfall'])) : '' ?>" inputmode="decimal"></div>
                        <div class="col-6"><label class="form-label small fw-semibold mb-1" for="filing_date">Date paid</label><input type="date" class="form-control form-control-sm" id="filing_date" name="filing_date" value="<?= e(date('Y-m-d')) ?>"></div>
                        <div class="col-12"><label class="form-label small fw-semibold mb-1" for="account_id">Paid from</label><select class="form-select form-select-sm" id="account_id" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?></select></div>
                        <div class="col-12"><label class="form-label small fw-semibold mb-1" for="reference">IRD voucher no.</label><input class="form-control form-control-sm" id="reference" name="reference" maxlength="100"></div>
                        <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="fa-solid fa-check me-1"></i> Record payment</button></div>
                    </div>
                </div>
            </form>
        <?php endif ?>

        <?php if ($isAdmin): ?>
            <form method="post" class="acc-card mb-3">
                <?= csrf_field() ?><input type="hidden" name="action" value="settings">
                <div class="acc-card-head"><h2>Rate &amp; estimate</h2></div>
                <div class="acc-card-body">
                    <div class="row g-2">
                        <div class="col-5"><label class="form-label small fw-semibold mb-1" for="rate">Tax rate</label><div class="input-group input-group-sm"><input class="form-control text-end" id="rate" name="rate" value="<?= e(rtrim(rtrim(number_format($t['rate'], 2), '0'), '.')) ?>" inputmode="decimal"><span class="input-group-text">%</span></div></div>
                        <div class="col-7"><label class="form-label small fw-semibold mb-1" for="estimate">Estimated taxable profit, FY <?= e($fy) ?></label><input class="form-control form-control-sm text-end" id="estimate" name="estimate" value="<?= $t['estimate_is_custom'] ? e(cents_to_decimal($t['estimate'])) : '' ?>" inputmode="decimal" placeholder="Blank = profit in the books"></div>
                        <div class="col-12 form-text mt-1">25% is the general company rate. Some activities (e.g. specified infrastructure, special industries) have concessional rates — set the rate your auditor confirms. Use the estimate for the full year's expected profit after tax adjustments (depreciation, disallowed expenses).</div>
                        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Save</button></div>
                    </div>
                </div>
            </form>

            <?php if (!$t['provision']): ?>
                <form method="post" class="acc-card" onsubmit="return confirm('Book the income tax provision for FY <?= e($fy) ?>?')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="provision">
                    <div class="acc-card-head"><h2>Year-end provision</h2></div>
                    <div class="acc-card-body">
                        <p class="small text-body-secondary">At year end, book the year's tax as an expense: Dr Income Tax Expense, Cr Income Tax Payable.</p>
                        <div class="row g-2">
                            <div class="col-6"><input class="form-control form-control-sm text-end" name="amount" value="<?= e(cents_to_decimal($t['tax'])) ?>" inputmode="decimal" aria-label="Amount"></div>
                            <div class="col-6"><input type="date" class="form-control form-control-sm" name="filing_date" value="<?= e(min($fyTo, date('Y-m-d'))) ?>" aria-label="Date"></div>
                            <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Book provision</button></div>
                        </div>
                    </div>
                </form>
            <?php endif ?>
        <?php endif ?>
    </div>
</div>
<p class="small text-body-secondary">This is a planning estimate from your books, not a tax return. The annual return (D-3) and the final assessment should be prepared with your auditor.</p>

<?php require __DIR__ . '/includes/layout-bottom.php';
