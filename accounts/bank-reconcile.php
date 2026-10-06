<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }
$isAdmin = $user['role'] === 'admin';

$id = (int)($_GET['id'] ?? 0);
$acc = cash_bank_accounts()[$id] ?? null;
if (!$acc || $acc['kind'] === 'cash') { flash('warning', 'Choose a bank or wallet account to reconcile.'); redirect('cash-bank.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'start') {
            start_reconciliation($id, (string)($_POST['statement_date'] ?? ''), (string)($_POST['statement_balance'] ?? ''), (int)$user['id']);
            redirect('bank-reconcile.php?id=' . $id);
        }
        $recon = current_reconciliation($id);
        if (in_array($action, ['save', 'finish'], true) && $recon) {
            save_reconciliation($recon, (array)($_POST['lines'] ?? []), $action === 'finish', (int)$user['id']);
            flash('success', $action === 'finish' ? 'Reconciliation complete — the books agree with the bank statement.' : 'Progress saved.');
            redirect($action === 'finish' ? 'cash-account.php?id=' . $id : 'bank-reconcile.php?id=' . $id);
        }
        if ($action === 'cancel' && $recon) {
            db()->prepare('DELETE FROM acc_bank_reconciliations WHERE id = ?')->execute([$recon['id']]);
            flash('success', 'Reconciliation discarded.');
            redirect('cash-account.php?id=' . $id);
        }
        if ($action === 'undo' && $isAdmin) {
            undo_reconciliation($id, (int)$user['id']);
            flash('success', 'Last reconciliation reopened — its items are uncleared again.');
            redirect('bank-reconcile.php?id=' . $id);
        }
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$recon = current_reconciliation($id);
$last = last_completed_reconciliation($id);
$cleared = cleared_balance($id);
$lines = $recon ? unreconciled_lines($id, $recon['statement_date'], (int)$recon['id']) : [];
$history = db()->prepare("SELECT * FROM acc_bank_reconciliations WHERE account_id = ? AND status = 'completed' ORDER BY statement_date DESC LIMIT 6");
$history->execute([$id]);
$history = $history->fetchAll();

$pageTitle   = 'Reconcile ' . $acc['name'];
$activeNav   = 'cash-bank';
$breadcrumbs = [['label' => 'Cash & Bank', 'href' => 'cash-bank.php'], ['label' => $acc['name'], 'href' => 'cash-account.php?id=' . $id], ['label' => 'Reconcile']];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<?php if (!$recon): ?>
    <div class="row g-3">
        <div class="col-lg-6">
            <form method="post" class="acc-card">
                <?= csrf_field() ?><input type="hidden" name="action" value="start">
                <div class="acc-card-head"><h2>Start a reconciliation</h2></div>
                <div class="acc-card-body">
                    <p class="small text-body-secondary">Get the bank statement for <?= e($acc['name']) ?>. Enter its last date and closing balance, then tick every transaction that appears on it.</p>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" for="statement_date">Statement date</label>
                            <input type="date" class="form-control" id="statement_date" name="statement_date" value="<?= e($_POST['statement_date'] ?? date('Y-m-d')) ?>" required>
                            <div class="acc-bs-hint" data-date-hint="statement_date">&nbsp;</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold" for="statement_balance">Closing balance on statement</label>
                            <input class="form-control text-end" id="statement_balance" name="statement_balance" value="<?= e($_POST['statement_balance'] ?? '') ?>" inputmode="decimal" required placeholder="0.00">
                            <div class="form-text">Use a minus sign if overdrawn.</div>
                        </div>
                    </div>
                </div>
                <div class="border-top px-4 py-3 text-end"><button class="btn btn-primary">Start</button></div>
            </form>
        </div>
        <div class="col-lg-6">
            <div class="acc-card">
                <div class="acc-card-head"><h2>Previous reconciliations</h2></div>
                <?php if (!$history): ?>
                    <div class="acc-card-body small text-body-secondary">This account has never been reconciled.</div>
                <?php else: ?>
                    <table class="table table-sm mb-0 small"><tbody>
                        <?php foreach ($history as $h): ?>
                            <tr><td><?= e(bs_date($h['statement_date'])) ?> B.S.</td><td class="num"><?= e(money($h['statement_balance'])) ?></td><td class="text-body-secondary"><?= e(date('d M Y', strtotime($h['completed_at']))) ?></td></tr>
                        <?php endforeach ?>
                    </tbody></table>
                    <?php if ($isAdmin): ?>
                        <form method="post" class="border-top px-3 py-2" data-confirm="Reopen the latest reconciliation? Its items become uncleared again.">
                            <?= csrf_field() ?><input type="hidden" name="action" value="undo">
                            <button class="btn btn-sm btn-link text-danger p-0">Undo the latest reconciliation (admin)</button>
                        </form>
                    <?php endif ?>
                <?php endif ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="post" id="reconForm">
        <?= csrf_field() ?>
        <div class="acc-card mb-3">
            <div class="acc-card-body">
                <div class="row g-3 text-center small">
                    <div class="col-6 col-md"><div class="text-body-secondary">Statement date</div><div class="fw-bold fs-6"><?= e(bs_date($recon['statement_date'])) ?></div></div>
                    <div class="col-6 col-md"><div class="text-body-secondary">Statement balance</div><div class="fw-bold fs-6"><?= e(money($recon['statement_balance'], false)) ?></div></div>
                    <div class="col-6 col-md"><div class="text-body-secondary">Cleared earlier</div><div class="fw-bold fs-6"><?= e(money_cents($cleared)) ?></div></div>
                    <div class="col-6 col-md"><div class="text-body-secondary">Ticked now</div><div class="fw-bold fs-6" id="rTicked">0.00</div></div>
                    <div class="col-12 col-md"><div class="text-body-secondary">Difference</div><div class="fw-bold fs-5" id="rDiff">0.00</div></div>
                </div>
            </div>
        </div>

        <div class="acc-card mb-3">
            <div class="acc-card-head">
                <h2>Transactions up to <?= e(bs_date($recon['statement_date'])) ?> not yet cleared</h2>
                <div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="tickAll">Tick all</button><button type="button" class="btn btn-sm btn-outline-secondary" id="tickNone">Clear ticks</button></div>
            </div>
            <?php if (!$lines): ?>
                <div class="acc-card-body small text-body-secondary">Nothing to tick. If the bank shows charges or interest that aren't recorded yet, add them first.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th style="width:40px"></th><th>Date</th><th>Voucher</th><th>Particulars</th><th class="num">Deposit</th><th class="num">Withdrawal</th></tr></thead>
                        <tbody>
                        <?php foreach ($lines as $l): $amt = decimal_to_cents($l['debit']) - decimal_to_cents($l['credit']); ?>
                            <tr>
                                <td><input class="form-check-input recon-tick" type="checkbox" name="lines[]" value="<?= (int)$l['line_id'] ?>" data-amount="<?= $amt ?>" <?= $l['ticked'] ? 'checked' : '' ?> aria-label="On statement"></td>
                                <td class="text-nowrap small"><?= e(bs_date($l['entry_date'])) ?></td>
                                <td class="small"><a href="journal-entry.php?id=<?= (int)$l['entry_id'] ?>" target="_blank" rel="noopener"><?= e($l['voucher_no']) ?></a></td>
                                <td><?= e($l['narration']) ?><?= $l['reference'] ? '<div class="small text-body-secondary">Ref ' . e($l['reference']) . '</div>' : '' ?></td>
                                <td class="num text-success"><?= $amt > 0 ? e(money_cents($amt)) : '' ?></td>
                                <td class="num"><?= $amt < 0 ? e(money_cents(-$amt)) : '' ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>

        <div class="d-flex flex-wrap justify-content-between gap-2 mb-4">
            <div class="d-flex gap-2">
                <button name="action" value="cancel" class="btn btn-outline-danger" formnovalidate data-confirm="Discard this reconciliation?">Discard</button>
                <a href="cash-entry.php?account=<?= $id ?>&direction=out" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="fa-solid fa-plus me-1"></i> Record a bank charge</a>
            </div>
            <div class="d-flex gap-2">
                <button name="action" value="save" class="btn btn-outline-secondary">Save progress</button>
                <button name="action" value="finish" class="btn btn-primary" id="finishBtn"><i class="fa-solid fa-check-double me-1"></i> Finish</button>
            </div>
        </div>
    </form>
<?php endif ?>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = 'window.accRecon = ' . json_encode(['statement' => $recon ? decimal_to_cents($recon['statement_balance']) : 0, 'cleared' => $cleared]) . ";\n" . <<<'JS'
(function () {
    document.querySelectorAll('[data-date-hint]').forEach(hint => {
        const input = document.getElementById(hint.dataset.dateHint);
        const show = () => { const bs = typeof adToBs === 'function' ? adToBs(input.value) : null; hint.textContent = bs ? `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]})` : ' '; };
        input.addEventListener('input', show); show();
    });
    const form = document.getElementById('reconForm');
    if (!form) return;
    const ticks = Array.from(form.querySelectorAll('.recon-tick'));
    const fmt = c => (c / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function update() {
        const ticked = ticks.filter(t => t.checked).reduce((s, t) => s + Number(t.dataset.amount), 0);
        const diff = window.accRecon.statement - (window.accRecon.cleared + ticked);
        document.getElementById('rTicked').textContent = fmt(ticked);
        const d = document.getElementById('rDiff');
        d.textContent = diff === 0 ? '0.00 ✓' : fmt(diff);
        d.className = 'fw-bold fs-5 ' + (diff === 0 ? 'text-success' : 'text-danger');
        document.getElementById('finishBtn').disabled = diff !== 0;
    }
    ticks.forEach(t => t.addEventListener('change', update));
    document.getElementById('tickAll')?.addEventListener('click', () => { ticks.forEach(t => { t.checked = true; }); update(); });
    document.getElementById('tickNone')?.addEventListener('click', () => { ticks.forEach(t => { t.checked = false; }); update(); });
    update();
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
