<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

$id = (int)($_GET['id'] ?? 0);
$acc = cash_bank_accounts(false)[$id] ?? null;
if (!$acc) { flash('warning', 'Account not found.'); redirect('cash-bank.php'); }
$meta = CASH_KINDS[$acc['kind']];

[$fyStart] = fiscal_year_range(fiscal_year_for(date('Y-m-d')));
$from = valid_ad_date((string)($_GET['from'] ?? '')) ? $_GET['from'] : $fyStart;
$to   = valid_ad_date((string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$reg = cash_account_register($id, $from, $to);
$totalIn = array_sum(array_column($reg['rows'], 'dr'));
$totalOut = array_sum(array_column($reg['rows'], 'cr'));
$recon = current_reconciliation($id);
$last = last_completed_reconciliation($id);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '-', $acc['name']) . "-{$from}-{$to}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$acc['name'], "{$from} to {$to}"]);
    fputcsv($out, ['Date (BS)', 'Date (AD)', 'Voucher', 'Particulars', 'Other side', 'Reference', 'In', 'Out', 'Balance', 'Cleared']);
    fputcsv($out, ['', $from, '', 'Opening balance', '', '', '', '', cents_to_decimal($reg['opening']), '']);
    foreach ($reg['rows'] as $r) {
        fputcsv($out, [bs_date($r['entry_date']), $r['entry_date'], $r['voucher_no'], $r['narration'], $r['other_side'], $r['reference'],
            $r['dr'] ? cents_to_decimal($r['dr']) : '', $r['cr'] ? cents_to_decimal($r['cr']) : '', cents_to_decimal($r['balance']), $r['recon_status'] === 'completed' ? 'Yes' : '']);
    }
    exit;
}

$pageTitle   = $acc['name'];
$activeNav   = 'cash-bank';
$breadcrumbs = [['label' => 'Cash & Bank', 'href' => 'cash-bank.php'], ['label' => $acc['name']]];
$pageActions = '<a class="btn btn-outline-secondary" href="?' . e(http_build_query(['id' => $id, 'from' => $from, 'to' => $to, 'export' => 'csv'])) . '"><i class="fa-solid fa-file-csv me-1"></i> CSV</a>'
             . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
if ($canEdit) {
    $pageActions .= '<a class="btn btn-outline-secondary" href="cash-account-edit.php?id=' . $id . '"><i class="fa-solid fa-pen me-1"></i> Edit</a>';
    if ($acc['kind'] !== 'cash') $pageActions .= '<a class="btn btn-primary" href="bank-reconcile.php?id=' . $id . '"><i class="fa-solid fa-scale-balanced me-1"></i> ' . ($recon ? 'Continue reconciliation' : 'Reconcile') . '</a>';
}
require __DIR__ . '/includes/layout-top.php';
?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="acc-card h-100">
            <div class="acc-card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid <?= $meta['icon'] ?>"></i></span>
                    <div><div class="small text-body-secondary"><?= e($meta['label']) ?> · <span class="acc-code"><?= e($acc['code']) ?></span></div>
                        <?php if (!$acc['is_active']): ?><span class="badge text-bg-secondary">Closed</span><?php endif ?></div>
                </div>
                <div class="fs-3 fw-bold <?= $acc['balance'] < 0 ? 'text-danger' : '' ?>"><?= e(money_cents($acc['balance'], true)) ?></div>
                <div class="small text-body-secondary mb-2">Current balance<?= $acc['balance'] < 0 ? ($acc['account_type'] === 'overdraft' ? ' (overdraft used)' : ' — overdrawn') : '' ?></div>
                <dl class="row small mb-0">
                    <?php if ($acc['bank_name']): ?><dt class="col-5 fw-normal text-body-secondary"><?= $acc['kind'] === 'wallet' ? 'Provider' : 'Bank' ?></dt><dd class="col-7"><?= e($acc['bank_name']) ?><?= $acc['branch'] ? ', ' . e($acc['branch']) : '' ?></dd><?php endif ?>
                    <?php if ($acc['account_number']): ?><dt class="col-5 fw-normal text-body-secondary">Account no.</dt><dd class="col-7 acc-code"><?= e($acc['account_number']) ?></dd><?php endif ?>
                    <?php if ($acc['account_type']): ?><dt class="col-5 fw-normal text-body-secondary">Type</dt><dd class="col-7"><?= e(BANK_ACCOUNT_TYPES[$acc['account_type']]) ?></dd><?php endif ?>
                    <?php if ($acc['kind'] !== 'cash'): ?><dt class="col-5 fw-normal text-body-secondary">Reconciled</dt><dd class="col-7"><?= $last ? 'to ' . e(bs_date($last['statement_date'])) . ' B.S.' : 'Never' ?><?= $recon ? ' <span class="badge text-bg-warning">in progress</span>' : '' ?></dd><?php endif ?>
                    <?php if ($acc['notes']): ?><dt class="col-5 fw-normal text-body-secondary">Notes</dt><dd class="col-7"><?= e($acc['notes']) ?></dd><?php endif ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="row g-3 h-100">
            <div class="col-sm-4"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Opening</span><div class="acc-kpi-value fs-5"><?= e(money_cents($reg['opening'])) ?></div><div class="acc-kpi-note"><?= e(bs_date($from)) ?></div></div></div>
            <div class="col-sm-4"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Money in</span><div class="acc-kpi-value fs-5 text-success"><?= e(money_cents($totalIn)) ?></div><div class="acc-kpi-note">This period</div></div></div>
            <div class="col-sm-4"><div class="acc-card acc-kpi"><span class="acc-kpi-label">Money out</span><div class="acc-kpi-value fs-5"><?= e(money_cents($totalOut)) ?></div><div class="acc-kpi-note">This period</div></div></div>
            <?php if ($canEdit && $acc['is_active']): ?>
                <div class="col-12 d-flex flex-wrap gap-2 align-items-end">
                    <a class="btn btn-sm btn-outline-primary" href="payment-edit.php?direction=in"><i class="fa-solid fa-arrow-down me-1"></i> Receive from client</a>
                    <a class="btn btn-sm btn-outline-primary" href="payment-edit.php?direction=out"><i class="fa-solid fa-arrow-up me-1"></i> Pay supplier</a>
                    <a class="btn btn-sm btn-outline-primary" href="cash-transfer.php?from=<?= $id ?>"><i class="fa-solid fa-right-left me-1"></i> Transfer</a>
                    <a class="btn btn-sm btn-outline-primary" href="cash-entry.php?account=<?= $id ?>&direction=out"><i class="fa-solid fa-minus me-1"></i> Charge / other out</a>
                    <a class="btn btn-sm btn-outline-primary" href="cash-entry.php?account=<?= $id ?>&direction=in"><i class="fa-solid fa-plus me-1"></i> Interest / other in</a>
                </div>
            <?php endif ?>
        </div>
    </div>
</div>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div><label class="form-label" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label class="form-label" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
        <span class="small text-body-secondary ms-auto"><?= e(bs_date($from)) ?> to <?= e(bs_date($to)) ?> B.S.</span>
    </form>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th><?php if ($acc['kind'] !== 'cash'): ?><th class="text-center" title="Cleared in a bank reconciliation"><i class="fa-solid fa-check-double"></i></th><?php endif ?></tr></thead>
            <tbody>
                <tr class="table-subtotal"><td colspan="5">Opening balance</td><td class="num"><?= e(money_cents($reg['opening'])) ?></td><?php if ($acc['kind'] !== 'cash'): ?><td></td><?php endif ?></tr>
                <?php if (!$reg['rows']): ?><tr><td colspan="7" class="text-center text-body-secondary py-4">No transactions in this period.</td></tr><?php endif ?>
                <?php foreach ($reg['rows'] as $r): ?>
                    <tr style="cursor:pointer" onclick="location.href='journal-entry.php?id=<?= (int)$r['entry_id'] ?>'">
                        <td class="text-nowrap"><?= e(bs_date($r['entry_date'])) ?><div class="small text-body-secondary"><?= e(date('d M Y', strtotime($r['entry_date']))) ?></div></td>
                        <td class="text-nowrap"><a class="text-decoration-none" href="journal-entry.php?id=<?= (int)$r['entry_id'] ?>"><?= e($r['voucher_no']) ?></a></td>
                        <td><?= e($r['narration']) ?><div class="small text-body-secondary"><?= e($r['other_side'] ?? '') ?><?= $r['reference'] ? ' · Ref ' . e($r['reference']) : '' ?></div></td>
                        <td class="num text-success"><?= $r['dr'] ? e(money_cents($r['dr'])) : '' ?></td>
                        <td class="num"><?= $r['cr'] ? e(money_cents($r['cr'])) : '' ?></td>
                        <td class="num fw-semibold <?= $r['balance'] < 0 ? 'text-danger' : '' ?>"><?= e(money_cents($r['balance'])) ?></td>
                        <?php if ($acc['kind'] !== 'cash'): ?><td class="text-center"><?= $r['recon_status'] === 'completed' ? '<i class="fa-solid fa-check text-success" title="Cleared"></i>' : '' ?></td><?php endif ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
            <tfoot><tr class="table-total"><td colspan="3">Closing balance</td><td class="num"><?= e(money_cents($totalIn)) ?></td><td class="num"><?= e(money_cents($totalOut)) ?></td><td class="num"><?= e(money_cents($reg['closing'])) ?></td><?php if ($acc['kind'] !== 'cash'): ?><td></td><?php endif ?></tr></tfoot>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
