<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$direction = ($_POST['direction'] ?? $_GET['direction'] ?? 'out') === 'in' ? 'in' : 'out';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $voucher = record_other_entry($_POST, (int)$user['id']);
        flash('success', "Entry {$voucher} recorded.");
        redirect('cash-account.php?id=' . (int)$_POST['account_id']);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}
$accounts = cash_bank_accounts();
$counter = other_entry_counter_accounts();
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ['account_id' => (int)($_GET['account'] ?? 0), 'entry_date' => date('Y-m-d'), 'direction' => $direction];
// Sensible default "what for": bank charges for money out, interest income for money in.
$defaultCounter = (int)($f['counter_account_id'] ?? 0) ?: ($direction === 'out'
    ? system_account_id('bank_charges')
    : (int)db()->query("SELECT id FROM acc_accounts WHERE code = '4310'")->fetchColumn());
$projects = project_options();

$pageTitle   = 'Other cash / bank entry';
$activeNav   = 'cash-bank';
$breadcrumbs = [['label' => 'Cash & Bank', 'href' => 'cash-bank.php'], ['label' => 'Other entry']];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<form method="post" class="acc-card" style="max-width:760px" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card-head"><h2>Money in or out — not tied to an invoice</h2></div>
    <div class="acc-card-body">
        <p class="small text-body-secondary">Bank service charges, interest received, a cheque book fee, a small site purchase paid from petty cash… For client and supplier money use <a href="payments.php">Payments</a>; for moving money between your own accounts use <a href="cash-transfer.php">Transfer</a>.</p>
        <div class="row g-3">
            <div class="col-12">
                <div class="btn-group w-100" role="group" aria-label="Direction">
                    <input type="radio" class="btn-check" name="direction" id="dirOut" value="out" <?= ($f['direction'] ?? 'out') === 'out' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="dirOut"><i class="fa-solid fa-arrow-up me-1"></i> Money out</label>
                    <input type="radio" class="btn-check" name="direction" id="dirIn" value="in" <?= ($f['direction'] ?? '') === 'in' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="dirIn"><i class="fa-solid fa-arrow-down me-1"></i> Money in</label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="account_id">Cash / bank account</label>
                <select class="form-select" id="account_id" name="account_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)($f['account_id'] ?? 0) ? 'selected' : '' ?>><?= e(cash_account_label($a)) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="counter_account_id">What for (account)</label>
                <select class="form-select" id="counter_account_id" name="counter_account_id" required>
                    <?php foreach ($counter as $type => $list): ?>
                        <optgroup label="<?= e(ACC_ACCOUNT_TYPES[$type]['label']) ?>">
                            <?php foreach ($list as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $defaultCounter ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?>
                        </optgroup>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="description">Description</label>
                <input class="form-control" id="description" name="description" value="<?= e($f['description'] ?? '') ?>" maxlength="500" required placeholder="e.g. Bank service charge — Ashwin">
            </div>
            <div class="col-sm-6 col-md-3">
                <label class="form-label fw-semibold" for="amount">Amount</label>
                <input class="form-control text-end fw-semibold" id="amount" name="amount" value="<?= e((string)($f['amount'] ?? '')) ?>" inputmode="decimal" required placeholder="0.00">
            </div>
            <div class="col-sm-6 col-md-3">
                <label class="form-label fw-semibold" for="entry_date">Date</label>
                <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= e($f['entry_date'] ?? '') ?>" required>
                <div class="acc-bs-hint" data-date-hint="entry_date">&nbsp;</div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="project_id">Project</label>
                <select class="form-select" id="project_id" name="project_id"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)($f['project_id'] ?? 0) ? 'selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?></option><?php endforeach ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="reference">Reference</label>
                <input class="form-control" id="reference" name="reference" value="<?= e($f['reference'] ?? '') ?>" maxlength="100">
            </div>
        </div>
    </div>
    <div class="border-top px-4 py-3 d-flex justify-content-between">
        <a href="cash-bank.php" class="btn btn-link text-body-secondary">Cancel</a>
        <button class="btn btn-primary"><i class="fa-solid fa-check me-1"></i> Record entry</button>
    </div>
</form>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = <<<'JS'
document.querySelectorAll('[data-date-hint]').forEach(hint => {
    const input = document.getElementById(hint.dataset.dateHint);
    const show = () => { const bs = typeof adToBs === 'function' ? adToBs(input.value) : null; hint.textContent = bs ? `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]})` : ' '; };
    input.addEventListener('input', show); show();
});
JS;
require __DIR__ . '/includes/layout-bottom.php';
