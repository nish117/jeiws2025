<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$id = (int)($_GET['id'] ?? 0);
$acc = $id ? (cash_bank_accounts(false)[$id] ?? null) : null;
if ($id && !$acc) { flash('warning', 'Account not found.'); redirect('cash-bank.php'); }
$kind = $acc['kind'] ?? (isset(CASH_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'bank');
$meta = CASH_KINDS[$kind];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $check = validate_cash_account($_POST, $kind, !$acc);
    $errors = $check['errors'];
    if (!$errors) {
        try {
            if ($acc) {
                update_cash_account($acc, $check['values'], !empty($_POST['is_active']) || (bool)$acc['system_key']);
                flash('success', 'Account updated.');
                redirect('cash-account.php?id=' . $id);
            }
            $newId = create_cash_account($kind, $check['values'], (int)$user['id']);
            flash('success', "{$meta['label']} added" . ($check['values']['opening_amount'] ? ' with its opening balance' : '') . '.');
            redirect('cash-account.php?id=' . $newId);
        } catch (DomainException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($acc ?? ['opening_date' => date('Y-m-d'), 'account_type' => 'current', 'is_active' => 1]);

$pageTitle   = $acc ? 'Edit ' . $acc['name'] : 'Add ' . strtolower($meta['label']);
$activeNav   = 'cash-bank';
$breadcrumbs = [['label' => 'Cash & Bank', 'href' => 'cash-bank.php']];
if ($acc) $breadcrumbs[] = ['label' => $acc['name'], 'href' => 'cash-account.php?id=' . $id];
$breadcrumbs[] = ['label' => $acc ? 'Edit' : 'Add'];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<form method="post" class="acc-card" style="max-width:820px" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card-head"><h2><i class="fa-solid <?= $meta['icon'] ?> me-1"></i> <?= e($meta['label']) ?></h2><?php if ($acc): ?><span class="acc-code small"><?= e($acc['code']) ?></span><?php endif ?></div>
    <div class="acc-card-body">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label fw-semibold" for="name">Name <span class="text-danger">*</span></label>
                <input class="form-control" id="name" name="name" value="<?= e($f['name'] ?? '') ?>" required maxlength="150" autofocus
                       placeholder="<?= $kind === 'bank' ? 'e.g. Nabil Bank – Current' : ($kind === 'cash' ? 'e.g. Site cash – Sanepa' : 'e.g. eSewa merchant') ?>">
                <div class="form-text">How it appears in lists and on vouchers.</div>
            </div>
            <?php if ($kind === 'bank'): ?>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="bank_name">Bank <span class="text-danger">*</span></label>
                    <input class="form-control" id="bank_name" name="bank_name" value="<?= e($f['bank_name'] ?? '') ?>" maxlength="100" placeholder="e.g. Nabil Bank Ltd" list="nepalBanks">
                    <datalist id="nepalBanks">
                        <?php foreach (['Nabil Bank', 'Nepal Investment Mega Bank', 'Global IME Bank', 'NIC Asia Bank', 'Himalayan Bank', 'Standard Chartered Bank Nepal', 'Everest Bank', 'Prabhu Bank', 'Kumari Bank', 'Laxmi Sunrise Bank', 'Siddhartha Bank', 'Sanima Bank', 'Machhapuchchhre Bank', 'Citizens Bank International', 'Prime Commercial Bank', 'NMB Bank', 'Nepal SBI Bank', 'Agricultural Development Bank', 'Rastriya Banijya Bank', 'Nepal Bank'] as $b): ?><option value="<?= e($b) ?>"><?php endforeach ?>
                    </datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="branch">Branch</label>
                    <input class="form-control" id="branch" name="branch" value="<?= e($f['branch'] ?? '') ?>" maxlength="100" placeholder="e.g. Pulchowk">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="account_number">Account number</label>
                    <input class="form-control" id="account_number" name="account_number" value="<?= e($f['account_number'] ?? '') ?>" maxlength="40" inputmode="numeric">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="account_type">Account type</label>
                    <select class="form-select" id="account_type" name="account_type">
                        <?php foreach (BANK_ACCOUNT_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= ($f['account_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
                    </select>
                </div>
            <?php elseif ($kind === 'wallet'): ?>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="bank_name">Provider</label>
                    <input class="form-control" id="bank_name" name="bank_name" value="<?= e($f['bank_name'] ?? '') ?>" maxlength="100" list="wallets" placeholder="eSewa">
                    <datalist id="wallets"><option value="eSewa"><option value="Khalti"><option value="Fonepay"><option value="IME Pay"></datalist>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="account_number">Wallet ID / phone</label>
                    <input class="form-control" id="account_number" name="account_number" value="<?= e($f['account_number'] ?? '') ?>" maxlength="40">
                </div>
            <?php endif ?>
            <div class="col-12">
                <label class="form-label fw-semibold" for="notes">Notes</label>
                <input class="form-control" id="notes" name="notes" value="<?= e($f['notes'] ?? '') ?>" maxlength="255" placeholder="<?= $kind === 'cash' ? 'Who holds this cash?' : 'Signatories, purpose…' ?>">
            </div>

            <?php if (!$acc): ?>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold" for="opening_amount">Opening balance</label>
                    <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', 'Rs.')) ?></span>
                        <input class="form-control text-end" id="opening_amount" name="opening_amount" value="<?= e((string)($f['opening_amount'] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></div>
                    <?php if ($kind === 'bank'): ?>
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="overdrawn" name="overdrawn" value="1" <?= !empty($f['overdrawn']) ? 'checked' : '' ?>><label class="form-check-label small" for="overdrawn">Account is overdrawn (OD balance owed to the bank)</label></div>
                    <?php endif ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="opening_date">As on</label>
                    <input type="date" class="form-control" id="opening_date" name="opening_date" value="<?= e($f['opening_date'] ?? '') ?>">
                    <div class="acc-bs-hint" data-date-hint="opening_date">&nbsp;</div>
                </div>
                <div class="col-12"><div class="form-text mt-0">The balance on the day you start recording this account here — from the bank statement or a cash count. It's posted against <em>Opening Balance Equity</em>.</div></div>
            <?php else: ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= !empty($f['is_active']) ? 'checked' : '' ?> <?= $acc['system_key'] ? 'disabled checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                        <div class="form-text"><?= $acc['system_key'] ? 'System account — always active.' : 'Untick to close the account. Its balance must be zero first; history is kept.' ?></div>
                    </div>
                </div>
            <?php endif ?>
        </div>
    </div>
    <div class="border-top px-4 py-3 d-flex justify-content-between">
        <a href="<?= $acc ? 'cash-account.php?id=' . $id : 'cash-bank.php' ?>" class="btn btn-link text-body-secondary">Cancel</a>
        <button class="btn btn-primary"><?= $acc ? 'Save changes' : 'Add ' . strtolower($meta['label']) ?></button>
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
