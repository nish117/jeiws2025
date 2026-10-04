<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$direction = ($_GET['direction'] ?? '') === 'out' ? 'out' : 'in';
$d = PAYMENT_DIRECTIONS[$direction];
$isIn = $direction === 'in';
$contactId = (int)($_POST['contact_id'] ?? $_GET['contact'] ?? 0);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $r = save_payment($direction, $_POST, (int)$user['id']);
        flash('success', ($isIn ? 'Receipt' : 'Payment') . " {$r['voucher']} recorded.");
        redirect('payment.php?id=' . $r['id']);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$contacts = array_filter(contact_options(), fn($c) => $c['type'] === $d['contact'] && ($c['is_active'] || (int)$c['id'] === $contactId));
$contact = $contacts[$contactId] ?? null;
$open = $contact ? open_invoices_for($contactId, $d['invoice']) : [];
$advance = $contact ? contact_advance_balance($contactId, $direction) : 0;
$accounts = payment_account_options();
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ['payment_date' => date('Y-m-d'), 'method' => 'bank_transfer', 'alloc' => []];
$totalOpen = array_sum(array_column($open, 'outstanding'));

$pageTitle   = $d['verb'];
$activeNav   = 'payments';
$breadcrumbs = [['label' => 'Payments', 'href' => 'payments.php'], ['label' => $d['verb']]];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<!-- Step 1: party (reloads the page to list their open invoices). -->
<form method="get" class="acc-card mb-3" style="max-width:980px">
    <input type="hidden" name="direction" value="<?= $direction ?>">
    <div class="acc-card-body">
        <label class="form-label fw-semibold" for="contact"><?= $isIn ? 'Received from (client)' : 'Paid to (supplier)' ?></label>
        <select class="form-select" id="contact" name="contact" onchange="this.form.submit()">
            <option value="">Choose…</option>
            <?php foreach ($contacts as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $contactId ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?>
        </select>
        <?php if ($contact): ?>
            <div class="small text-body-secondary mt-2">
                <?= count($open) ?> unpaid <?= $isIn ? 'invoice' : 'bill' ?><?= count($open) === 1 ? '' : 's' ?> totalling <strong><?= e(money_cents($totalOpen, true)) ?></strong>
                <?php if ($advance > 0): ?> · <span class="text-success"><?= e(money_cents($advance, true)) ?> advance already held</span><?php endif ?>
                · <a href="contact.php?id=<?= $contactId ?>">Statement</a>
            </div>
        <?php else: ?>
            <div class="form-text"><?= $isIn ? 'For a mobilisation advance, choose the client and leave all invoices unticked.' : 'For an advance to a supplier, choose them and leave all bills unticked.' ?></div>
        <?php endif ?>
    </div>
</form>

<?php if ($contact): ?>
<!-- Step 2: amount, account and allocation. -->
<form method="post" id="payForm" style="max-width:980px" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="contact_id" value="<?= $contactId ?>">
    <div class="acc-card mb-3">
        <div class="acc-card-head"><h2><?= $isIn ? 'Money received' : 'Payment made' ?></h2></div>
        <div class="acc-card-body">
            <div class="row g-3">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label fw-semibold" for="amount">Amount <span class="text-danger">*</span></label>
                    <input class="form-control text-end fw-semibold" id="amount" name="amount" value="<?= e((string)($f['amount'] ?? '')) ?>" inputmode="decimal" required autofocus placeholder="0.00">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label fw-semibold" for="payment_date">Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= e($f['payment_date'] ?? '') ?>" required>
                    <div class="acc-bs-hint" data-date-hint="payment_date">&nbsp;</div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label fw-semibold" for="method">Method</label>
                    <select class="form-select" id="method" name="method">
                        <?php foreach (PAYMENT_METHODS as $k => $label): if ($k === 'advance') continue; ?><option value="<?= $k ?>" <?= ($f['method'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label fw-semibold" for="reference">Reference</label>
                    <input class="form-control" id="reference" name="reference" value="<?= e($f['reference'] ?? '') ?>" maxlength="100" placeholder="Cheque / txn no.">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="account_id"><?= $isIn ? 'Received into' : 'Paid from' ?></label>
                    <?php if ($accounts): ?>
                        <select class="form-select" id="account_id" name="account_id">
                            <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)($f['account_id'] ?? 0) ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?>
                        </select>
                    <?php else: ?>
                        <div class="form-control-plaintext small">Add a bank account under <a href="chart-of-accounts.php">1130 Bank Accounts</a> first.</div>
                    <?php endif ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" value="<?= e($f['notes'] ?? '') ?>" maxlength="255">
                </div>
            </div>
        </div>
    </div>

    <div class="acc-card mb-3">
        <div class="acc-card-head">
            <h2>Apply to <?= $isIn ? 'invoices' : 'bills' ?></h2>
            <?php if ($open): ?><button type="button" class="btn btn-sm btn-outline-primary" id="autoAlloc"><i class="fa-solid fa-wand-magic-sparkles me-1"></i> Oldest first</button><?php endif ?>
        </div>
        <?php if (!$open): ?>
            <div class="acc-card-body small text-body-secondary">No unpaid <?= $isIn ? 'invoices' : 'bills' ?> for <?= e($contact['name']) ?> — the whole amount will be held as an <strong>advance</strong> and can be applied to a future <?= $isIn ? 'invoice' : 'bill' ?>.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Number</th><th>Date</th><th>Due</th><th class="num">Outstanding</th><th class="num" style="width:170px">Apply</th></tr></thead>
                    <tbody>
                    <?php foreach ($open as $invId => $o): $overdue = $o['due_date'] && $o['due_date'] < date('Y-m-d'); ?>
                        <tr>
                            <td><a href="invoice.php?id=<?= $invId ?>" target="_blank" rel="noopener" class="text-decoration-none fw-semibold"><?= e($o['number']) ?></a></td>
                            <td class="small"><?= e(bs_date($o['invoice_date'])) ?></td>
                            <td class="small <?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= $o['due_date'] ? e(bs_date($o['due_date'])) . ($overdue ? ' · overdue' : '') : '—' ?></td>
                            <td class="num"><?= e(money_cents($o['outstanding'])) ?></td>
                            <td class="num"><input class="form-control form-control-sm text-end alloc" name="alloc[<?= $invId ?>]" data-max="<?= $o['outstanding'] ?>" value="<?= e((string)($f['alloc'][$invId] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
        <div class="border-top px-4 py-3">
            <div class="row text-center small g-2">
                <div class="col-4"><div class="text-body-secondary">Amount</div><div class="fw-bold fs-6" id="sAmount">0.00</div></div>
                <div class="col-4"><div class="text-body-secondary">Applied to <?= $isIn ? 'invoices' : 'bills' ?></div><div class="fw-bold fs-6" id="sAlloc">0.00</div></div>
                <div class="col-4"><div class="text-body-secondary">Held as advance</div><div class="fw-bold fs-6" id="sAdvance">0.00</div></div>
            </div>
            <div class="small text-danger text-center mt-2" id="sError" hidden></div>
        </div>
    </div>

    <div class="d-flex justify-content-between mb-4">
        <a href="payments.php" class="btn btn-link text-body-secondary">Cancel</a>
        <button class="btn btn-primary" id="saveBtn" <?= $accounts ? '' : 'disabled' ?>><i class="fa-solid fa-check me-1"></i> Record <?= $isIn ? 'receipt' : 'payment' ?></button>
    </div>
</form>
<?php endif ?>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = <<<'JS'
(function () {
    document.querySelectorAll('[data-date-hint]').forEach(hint => {
        const input = document.getElementById(hint.dataset.dateHint);
        const show = () => { const bs = typeof adToBs === 'function' ? adToBs(input.value) : null; hint.textContent = bs ? `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]})` : ' '; };
        input.addEventListener('input', show); show();
    });
    const form = document.getElementById('payForm');
    if (!form) return;
    const amountEl = document.getElementById('amount'), allocs = Array.from(form.querySelectorAll('.alloc'));
    const cents = v => { v = String(v || '').replace(/[,\s]/g, ''); return /^\d+(\.\d{0,2})?$/.test(v) ? Math.round(parseFloat(v) * 100) : (v === '' ? 0 : NaN); };
    const fmt = c => (c / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const dec = c => (c / 100).toFixed(2);
    function update() {
        const amount = cents(amountEl.value);
        let alloc = 0, bad = '';
        allocs.forEach(i => {
            const c = cents(i.value);
            const over = c > Number(i.dataset.max);
            i.classList.toggle('is-invalid', Number.isNaN(c) || over);
            if (over) bad = 'An amount is more than that invoice\'s outstanding.';
            if (!Number.isNaN(c)) alloc += c;
        });
        if (Number.isNaN(amount)) bad = 'Amount must be a number.';
        else if (alloc > amount) bad = 'Applied amount is more than the payment.';
        document.getElementById('sAmount').textContent = Number.isNaN(amount) ? '—' : fmt(amount);
        document.getElementById('sAlloc').textContent = fmt(alloc);
        document.getElementById('sAdvance').textContent = Number.isNaN(amount) ? '—' : fmt(Math.max(0, amount - alloc));
        const err = document.getElementById('sError'); err.hidden = !bad; err.textContent = bad;
        document.getElementById('saveBtn').disabled = !!bad || !(amount > 0);
    }
    function autoAllocate() {
        let left = cents(amountEl.value);
        if (Number.isNaN(left)) return;
        allocs.forEach(i => { const take = Math.min(left, Number(i.dataset.max)); i.value = take > 0 ? dec(take) : ''; left -= Math.max(0, take); });
        update();
    }
    document.getElementById('autoAlloc')?.addEventListener('click', autoAllocate);
    // Typing the amount fills invoices oldest-first until the user edits an allocation themselves.
    let manual = allocs.some(i => i.value !== '');
    allocs.forEach(i => i.addEventListener('input', () => { manual = true; update(); }));
    amountEl.addEventListener('input', () => { if (!manual) autoAllocate(); else update(); });
    update();
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
