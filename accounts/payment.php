<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$isAdmin = $user['role'] === 'admin';

$id = (int)($_GET['id'] ?? 0);
$p = load_payment($id);
if (!$p) { flash('warning', 'Payment not found.'); redirect('payments.php'); }
$isIn = $p['direction'] === 'in';
$isAdj = $p['method'] === 'advance';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    if (!$isAdmin) { http_response_code(403); exit('Only admins can void payments.'); }
    verify_csrf();
    try {
        void_payment($p, (int)$user['id'], (string)($_POST['void_reason'] ?? ''));
        flash('success', 'Payment voided. The invoices it paid are unpaid again by those amounts.');
        redirect('payment.php?id=' . $id);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$title = $isAdj ? 'Advance applied' : ($isIn ? 'Payment receipt' : 'Payment voucher');
$pageTitle   = $title . ' ' . ($p['voucher_no'] ?? '');
$activeNav   = 'payments';
$breadcrumbs = [['label' => 'Payments', 'href' => 'payments.php'], ['label' => $p['voucher_no'] ?? '#' . $id]];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>
<?php if ($p['status'] === 'void'): ?>
    <div class="alert alert-secondary"><i class="fa-solid fa-ban me-1"></i> Voided: <?= e($p['void_reason']) ?>. It no longer affects any balance.</div>
<?php endif ?>

<div class="acc-card" style="max-width:820px">
    <div class="acc-card-body">
        <div class="d-flex flex-wrap justify-content-between gap-3 border-bottom pb-3 mb-3">
            <div>
                <div class="fw-bold fs-5"><?= e(setting('company_name')) ?></div>
                <div class="small text-body-secondary"><?= e(setting('company_address')) ?><?= setting('pan_number') ? ' · PAN ' . e(setting('pan_number')) : '' ?></div>
            </div>
            <div class="text-end ms-auto">
                <div class="fw-bold text-uppercase" style="letter-spacing:.08em"><?= e($title) ?></div>
                <div class="fs-5 fw-semibold"><?= e($p['voucher_no'] ?? '—') ?></div>
                <div class="small"><?= e(bs_date($p['payment_date'])) ?> B.S. · <?= e(date('d M Y', strtotime($p['payment_date']))) ?></div>
            </div>
        </div>

        <p class="mb-3">
            <?php if ($isAdj): ?>
                Advance held for <strong><?= e($p['contact_name']) ?></strong> applied to the <?= $isIn ? 'invoice' : 'bill' ?> below.
            <?php elseif ($isIn): ?>
                Received with thanks from <strong><?= e($p['contact_name']) ?></strong><?= $p['contact_pan'] ? ' (PAN ' . e($p['contact_pan']) . ')' : '' ?>
                the sum of <strong><?= e(money($p['amount'])) ?></strong>
                by <?= e(strtolower(PAYMENT_METHODS[$p['method']])) ?><?= $p['reference'] ? ' (' . e($p['reference']) . ')' : '' ?>.
            <?php else: ?>
                Paid to <strong><?= e($p['contact_name']) ?></strong><?= $p['contact_pan'] ? ' (PAN ' . e($p['contact_pan']) . ')' : '' ?>
                the sum of <strong><?= e(money($p['amount'])) ?></strong>
                by <?= e(strtolower(PAYMENT_METHODS[$p['method']])) ?><?= $p['reference'] ? ' (' . e($p['reference']) . ')' : '' ?> from <?= e($p['account_name']) ?>.
            <?php endif ?>
        </p>

        <table class="table table-sm mb-0">
            <thead><tr><th>Against</th><th>Date</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($p['allocations'] as $a): ?>
                <tr class="<?= $a['status'] === 'void' ? 'acc-inactive' : '' ?>">
                    <td><a href="invoice.php?id=<?= (int)$a['invoice_id'] ?>" class="text-decoration-none"><?= e($a['number']) ?></a></td>
                    <td class="small"><?= e(bs_date($a['invoice_date'])) ?></td>
                    <td class="num"><?= e(money($a['amount'], false)) ?></td>
                </tr>
            <?php endforeach ?>
            <?php if ((float)$p['unallocated_amount'] > 0): ?>
                <tr><td colspan="2">Advance (held for future <?= $isIn ? 'invoices' : 'bills' ?>)</td><td class="num"><?= e(money($p['unallocated_amount'], false)) ?></td></tr>
            <?php endif ?>
            </tbody>
            <tfoot><tr class="table-total"><td colspan="2">Total</td><td class="num"><?= e(money($p['amount'])) ?></td></tr></tfoot>
        </table>
        <?php if ($p['notes']): ?><p class="small mt-3 mb-0"><span class="text-body-secondary">Notes:</span> <?= e($p['notes']) ?></p><?php endif ?>

        <div class="d-none d-print-flex justify-content-between mt-5 pt-4 small">
            <span style="border-top:1px solid #999;padding-top:4px;min-width:180px"><?= $isIn ? 'Received by' : 'Prepared by' ?></span>
            <span style="border-top:1px solid #999;padding-top:4px;min-width:180px;text-align:right"><?= $isIn ? 'For ' . e(setting('company_name')) : 'Received by ' . e($p['contact_name']) ?></span>
        </div>
    </div>
    <div class="border-top px-4 py-2 small text-body-secondary d-print-none d-flex flex-wrap gap-3">
        <span>Recorded by <?= e($p['created_by_name'] ?? '—') ?> · <?= e(date('d M Y, H:i', strtotime($p['created_at']))) ?></span>
        <?php if ($p['journal_entry_id']): ?><span>Ledger: <a href="journal-entry.php?id=<?= (int)$p['journal_entry_id'] ?>"><?= e($p['voucher_no']) ?></a></span><?php endif ?>
        <span><a href="contact.php?id=<?= (int)$p['contact_id'] ?>">Statement of <?= e($p['contact_name']) ?></a></span>
    </div>
</div>

<?php if ($isAdmin && $p['status'] === 'active'): ?>
    <form method="post" class="acc-card mt-3 d-print-none" style="max-width:820px" onsubmit="return confirm('Void this payment? Its voucher is cancelled and the invoices it paid become unpaid again by those amounts.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="void">
        <div class="acc-card-body d-flex flex-wrap align-items-end gap-2">
            <div class="flex-grow-1">
                <label class="form-label fw-semibold small" for="void_reason">Entered by mistake, or the cheque bounced? Void it (admin only)</label>
                <input class="form-control form-control-sm" id="void_reason" name="void_reason" required maxlength="200" placeholder="Reason (required)">
            </div>
            <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-ban me-1"></i> Void payment</button>
        </div>
    </form>
<?php endif ?>

<?php require __DIR__ . '/includes/layout-bottom.php';
