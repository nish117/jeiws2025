<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $voucher = transfer_between_accounts((int)($_POST['from_id'] ?? 0), (int)($_POST['to_id'] ?? 0), (string)($_POST['amount'] ?? ''),
            (string)($_POST['entry_date'] ?? ''), trim((string)($_POST['reference'] ?? '')), trim((string)($_POST['notes'] ?? '')), (int)$user['id']);
        flash('success', "Transfer {$voucher} recorded.");
        redirect('cash-account.php?id=' . (int)$_POST['to_id']);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}
$accounts = cash_bank_accounts();
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ['from_id' => (int)($_GET['from'] ?? 0), 'entry_date' => date('Y-m-d')];
$option = fn(int $selected) => implode('', array_map(fn($a) =>
    '<option value="' . (int)$a['id'] . '" data-balance="' . $a['balance'] . '"' . ((int)$a['id'] === $selected ? ' selected' : '') . '>'
    . e(cash_account_label($a) . ' — ' . money_cents($a['balance'], true)) . '</option>', $accounts));

$pageTitle   = 'Transfer between accounts';
$activeNav   = 'cash-bank';
$breadcrumbs = [['label' => 'Cash & Bank', 'href' => 'cash-bank.php'], ['label' => 'Transfer']];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<form method="post" class="acc-card" style="max-width:760px" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card-head"><h2><i class="fa-solid fa-right-left me-1"></i> Move money</h2></div>
    <div class="acc-card-body">
        <p class="small text-body-secondary">Cash deposited to the bank, cash withdrawn, a top-up of site petty cash, or bank to bank.</p>
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-semibold" for="from_id">From</label>
                <select class="form-select" id="from_id" name="from_id" required><option value="">Choose…</option><?= $option((int)($f['from_id'] ?? 0)) ?></select>
            </div>
            <div class="col-md-2 text-center pb-2"><i class="fa-solid fa-arrow-right-long fa-lg text-body-tertiary d-none d-md-inline"></i></div>
            <div class="col-md-5">
                <label class="form-label fw-semibold" for="to_id">To</label>
                <select class="form-select" id="to_id" name="to_id" required><option value="">Choose…</option><?= $option((int)($f['to_id'] ?? 0)) ?></select>
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label fw-semibold" for="amount">Amount</label>
                <input class="form-control text-end fw-semibold" id="amount" name="amount" value="<?= e((string)($f['amount'] ?? '')) ?>" inputmode="decimal" required placeholder="0.00">
                <div class="form-text" id="balanceHint">&nbsp;</div>
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label fw-semibold" for="entry_date">Date</label>
                <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= e($f['entry_date'] ?? '') ?>" required>
                <div class="acc-bs-hint" data-date-hint="entry_date">&nbsp;</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="reference">Reference</label>
                <input class="form-control" id="reference" name="reference" value="<?= e($f['reference'] ?? '') ?>" maxlength="100" placeholder="Deposit slip / cheque no.">
                <div class="form-text">&nbsp;</div>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="notes">Notes</label>
                <input class="form-control" id="notes" name="notes" value="<?= e($f['notes'] ?? '') ?>" maxlength="200">
            </div>
        </div>
    </div>
    <div class="border-top px-4 py-3 d-flex justify-content-between">
        <a href="cash-bank.php" class="btn btn-link text-body-secondary">Cancel</a>
        <button class="btn btn-primary"><i class="fa-solid fa-check me-1"></i> Record transfer</button>
    </div>
</form>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = <<<'JS'
(function () {
    document.querySelectorAll('[data-date-hint]').forEach(hint => {
        const input = document.getElementById(hint.dataset.dateHint);
        const show = () => { const bs = typeof adToBs === 'function' ? adToBs(input.value) : null; hint.textContent = bs ? `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]})` : ' '; };
        input.addEventListener('input', show); show();
    });
    // Warn (don't block) when moving more than the source account holds — overdrafts are legitimate.
    const from = document.getElementById('from_id'), amount = document.getElementById('amount'), hint = document.getElementById('balanceHint');
    const check = () => {
        const bal = Number(from.selectedOptions[0]?.dataset.balance || 0) / 100, v = parseFloat(String(amount.value).replace(/,/g, '')) || 0;
        hint.textContent = from.value && v > bal ? `More than the ${bal.toLocaleString('en-IN', { minimumFractionDigits: 2 })} in this account.` : ' ';
        hint.className = 'form-text' + (from.value && v > bal ? ' text-warning-emphasis' : '');
    };
    from.addEventListener('change', check); amount.addEventListener('input', check); check();
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
