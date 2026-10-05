<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/attachments.php';
$user = require_login();
$canEdit = can_edit_books($user);
$isAdmin = $user['role'] === 'admin';

$id = (int)($_GET['id'] ?? 0);
$inv = load_invoice($id);
if (!$inv) { flash('warning', 'Not found.'); redirect('invoices.php'); }
$type = $inv['type'];
$meta = INVOICE_TYPES[$type];
$isSales = $type === 'sales';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'post' && $inv['status'] === 'draft') {
            $number = post_invoice($id, (int)$user['id']);
            flash('success', "{$meta['label']} {$number} posted to the books.");
        } elseif ($action === 'pay') {
            $voucher = record_invoice_payment($inv, (int)$user['id'], (string)($_POST['payment_date'] ?? ''), (string)($_POST['amount'] ?? ''), (int)($_POST['account_id'] ?? 0), (string)($_POST['method'] ?? ''), trim((string)($_POST['reference'] ?? '')));
            flash('success', ($isSales ? 'Receipt' : 'Payment') . " recorded as {$voucher}.");
        } elseif ($action === 'apply_advance') {
            $r = apply_advance($inv, (string)($_POST['advance_amount'] ?? ''), (string)($_POST['advance_date'] ?? ''), (int)$user['id']);
            flash('success', "Advance applied ({$r['voucher']}).");
        } elseif ($action === 'void' && $isAdmin) {
            void_invoice($inv, (int)$user['id'], (string)($_POST['void_reason'] ?? ''));
            flash('success', invoice_title($inv) . ' voided.');
        } elseif ($action === 'upload') {
            $up = store_attachments($_FILES['files'] ?? null, 'invoice', $id, (int)$user['id']);
            foreach ($up['errors'] as $err) flash('warning', $err);
            if ($up['saved']) { audit('attach_file', 'invoice', $id, "{$up['saved']} file(s)"); flash('success', "{$up['saved']} file(s) attached."); }
        } elseif ($action === 'delete_file') {
            foreach (list_attachments('invoice', $id) as $att) {
                if ((int)$att['id'] === (int)($_POST['attachment_id'] ?? 0)) { delete_attachment($att); audit('delete_file', 'invoice', $id, $att['original_name']); flash('success', 'File removed.'); }
            }
        }
        redirect('invoice.php?id=' . $id);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$files = list_attachments('invoice', $id);
$m = fn(string|int|float|null $v) => money($v, false);
$outstanding = decimal_to_cents($inv['net_amount']) - decimal_to_cents($inv['amount_paid']);
$journal = $inv['journal_entry_id'] ? db()->query('SELECT voucher_no FROM acc_journal_entries WHERE id = ' . (int)$inv['journal_entry_id'])->fetchColumn() : null;
$project = $inv['project_id'] ? (project_options()[(int)$inv['project_id']] ?? null) : null;

$pageTitle   = invoice_title($inv);
$activeNav   = $meta['nav'];
$breadcrumbs = [['label' => 'Invoices', 'href' => 'invoices.php'], ['label' => $meta['plural'], 'href' => 'invoices.php?type=' . $type], ['label' => $inv['number'] ?? 'Draft']];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
if ($canEdit && $inv['status'] === 'draft') {
    $pageActions .= '<a class="btn btn-outline-secondary" href="invoice-edit.php?id=' . $id . '"><i class="fa-solid fa-pen me-1"></i> Edit</a>';
}
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>
<?php if ($inv['status'] === 'draft'): ?>
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 d-print-none">
        <span><i class="fa-solid fa-pen-ruler me-1"></i> Draft — not in the books yet.<?= $isSales && !$inv['number'] ? ' The invoice number is assigned when you post.' : '' ?></span>
        <?php if ($canEdit): ?>
            <form method="post"><?= csrf_field() ?><button name="action" value="post" class="btn btn-sm btn-primary" onclick="return confirm('Post to the books? It can\'t be edited afterwards.')"><i class="fa-solid fa-check me-1"></i> Post now</button></form>
        <?php endif ?>
    </div>
<?php elseif ($inv['status'] === 'void'): ?>
    <div class="alert alert-secondary"><i class="fa-solid fa-ban me-1"></i> Voided — no longer affects any balance. <?= e(trim(strrchr("\n" . ($inv['notes'] ?? ''), "\n"))) ?></div>
<?php endif ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="acc-card acc-invoice-doc">
            <div class="acc-card-body">
                <div class="d-flex flex-wrap justify-content-between gap-3 border-bottom pb-3 mb-3">
                    <div>
                        <?php if ($isSales): ?>
                            <div class="fw-bold fs-5"><?= e(setting('company_name')) ?></div>
                            <div class="small text-body-secondary"><?= e(setting('company_address')) ?></div>
                            <div class="small text-body-secondary"><?= setting('vat_number') ? 'VAT/PAN No. ' . e(setting('vat_number')) : (setting('pan_number') ? 'PAN ' . e(setting('pan_number')) : '') ?></div>
                        <?php else: ?>
                            <div class="small text-body-secondary">Bill from</div>
                            <div class="fw-bold fs-5"><?= e($inv['contact_name']) ?></div>
                            <div class="small text-body-secondary"><?= $inv['contact_pan'] ? ($inv['contact_vat_registered'] ? 'VAT' : 'PAN') . ' No. ' . e($inv['contact_pan']) : 'No PAN recorded' ?></div>
                        <?php endif ?>
                    </div>
                    <div class="text-end ms-auto">
                        <div class="fw-bold text-uppercase" style="letter-spacing:.08em"><?= $isSales ? ((float)$inv['vat_amount'] ? 'Tax invoice' : 'Invoice') : 'Purchase bill' ?></div>
                        <div class="fs-5 fw-semibold"><?= e($inv['number'] ?? 'Draft') ?></div>
                        <div class="small"><?= e(bs_date($inv['invoice_date'])) ?> B.S. · <?= e(date('d M Y', strtotime($inv['invoice_date']))) ?></div>
                        <?php if ($inv['due_date']): ?><div class="small text-body-secondary">Due <?= e(bs_date($inv['due_date'])) ?> B.S.</div><?php endif ?>
                        <div class="mt-1 d-print-none"><?= invoice_status_badge($inv) ?></div>
                    </div>
                </div>
                <div class="row small mb-3">
                    <?php if ($isSales): ?>
                        <div class="col-sm-7">
                            <div class="text-body-secondary">Bill to</div>
                            <div class="fw-semibold"><a href="contact.php?id=<?= (int)$inv['contact_id'] ?>" class="text-decoration-none text-body"><?= e($inv['contact_name']) ?></a></div>
                            <?php if ($inv['contact_address']): ?><div><?= e($inv['contact_address']) ?></div><?php endif ?>
                            <?php if ($inv['contact_pan']): ?><div>PAN/VAT No. <?= e($inv['contact_pan']) ?></div><?php endif ?>
                        </div>
                    <?php else: ?>
                        <div class="col-sm-7"><div class="text-body-secondary">Supplier</div><a href="contact.php?id=<?= (int)$inv['contact_id'] ?>" class="text-decoration-none"><?= e($inv['contact_name']) ?></a></div>
                    <?php endif ?>
                    <?php if ($project): ?>
                        <div class="col-sm-5 text-sm-end"><div class="text-body-secondary">Project</div><a href="project.php?id=<?= (int)$project['id'] ?>" class="text-decoration-none"><?= e($project['code'] . ' · ' . $project['name']) ?></a></div>
                    <?php endif ?>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th style="width:36px">#</th><th>Description</th><th class="num">Qty</th><th class="num d-none d-sm-table-cell">Rate</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($inv['lines'] as $l): ?>
                            <tr>
                                <td><?= (int)$l['line_no'] ?></td>
                                <td><?= e($l['description']) ?><?= $l['vat_applicable'] ? '' : ' <span class="acc-sys-tag">No VAT</span>' ?>
                                    <div class="small text-body-secondary d-print-none"><?= e($l['account_code'] . ' · ' . $l['account_name']) ?></div></td>
                                <td class="num"><?= e(rtrim(rtrim($l['quantity'], '0'), '.')) ?> <?= e($l['unit'] ?? '') ?></td>
                                <td class="num d-none d-sm-table-cell"><?= e($m($l['rate'])) ?></td>
                                <td class="num"><?= e($m($l['amount'])) ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>

                <div class="row justify-content-end mt-2">
                    <div class="col-md-7 col-lg-6">
                        <table class="table table-sm mb-0 acc-inv-totals">
                            <tbody>
                                <tr><td>Taxable amount</td><td class="num"><?= e($m($inv['taxable_amount'])) ?></td></tr>
                                <?php if ((float)$inv['exempt_amount']): ?><tr><td>Non-taxable amount</td><td class="num"><?= e($m($inv['exempt_amount'])) ?></td></tr><?php endif ?>
                                <tr><td>VAT <?= e(rtrim(rtrim($inv['vat_rate'], '0'), '.')) ?>%</td><td class="num"><?= e($m($inv['vat_amount'])) ?></td></tr>
                                <tr class="table-subtotal"><td>Total</td><td class="num"><?= e(money($inv['total_amount'])) ?></td></tr>
                                <?php if ((float)$inv['retention_amount']): ?><tr><td>− Retention <?= e(rtrim(rtrim($inv['retention_percent'], '0'), '.')) ?>%</td><td class="num"><?= e($m($inv['retention_amount'])) ?></td></tr><?php endif ?>
                                <?php if ((float)$inv['tds_amount']): ?><tr><td>− TDS <?= e(rtrim(rtrim($inv['tds_percent'], '0'), '.')) ?>%</td><td class="num"><?= e($m($inv['tds_amount'])) ?></td></tr><?php endif ?>
                                <?php if ((float)$inv['retention_amount'] || (float)$inv['tds_amount']): ?><tr class="table-total"><td><?= $isSales ? 'Net receivable' : 'Net payable' ?></td><td class="num"><?= e(money($inv['net_amount'])) ?></td></tr><?php endif ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($inv['notes'] && $inv['status'] !== 'void'): ?><p class="small mt-3 mb-0"><span class="text-body-secondary">Notes:</span> <?= nl2br(e($inv['notes'])) ?></p><?php endif ?>
                <?php if ($isSales): ?>
                    <div class="d-none d-print-flex justify-content-between mt-5 pt-4 small">
                        <span style="border-top:1px solid #999;padding-top:4px;min-width:180px">Received by</span>
                        <span style="border-top:1px solid #999;padding-top:4px;min-width:180px;text-align:right">For <?= e(setting('company_name')) ?></span>
                    </div>
                <?php endif ?>
            </div>
            <?php if ($journal): ?>
                <div class="border-top px-4 py-2 small text-body-secondary d-print-none">Posted as <a href="journal-entry.php?id=<?= (int)$inv['journal_entry_id'] ?>"><?= e($journal) ?></a></div>
            <?php endif ?>
        </div>
    </div>

    <div class="col-xl-4 d-print-none">
        <!-- Attachments -->
        <div class="acc-card mb-3">
            <div class="acc-card-head"><h2><i class="fa-solid fa-paperclip me-1"></i> Photos &amp; files</h2><span class="small text-body-secondary"><?= count($files) ?></span></div>
            <?php if ($files): ?>
                <div class="acc-viewer-thumbs border-top-0">
                    <?php foreach ($files as $att): ?>
                        <a class="acc-thumb" href="attachment.php?id=<?= (int)$att['id'] ?>" target="_blank" rel="noopener" title="<?= e($att['original_name']) ?>">
                            <?php if ($att['mime_type'] === 'application/pdf'): ?><span class="acc-thumb-pdf">PDF</span><?php else: ?><img src="attachment.php?id=<?= (int)$att['id'] ?>" alt=""><?php endif ?>
                        </a>
                    <?php endforeach ?>
                </div>
                <ul class="list-unstyled small px-3 mb-2">
                    <?php foreach ($files as $att): ?>
                        <li class="d-flex justify-content-between align-items-center gap-2 py-1">
                            <a href="attachment.php?id=<?= (int)$att['id'] ?>&download=1" class="text-truncate"><i class="fa-solid fa-download me-1"></i><?= e($att['original_name']) ?></a>
                            <span class="text-body-secondary text-nowrap"><?= number_format($att['size_bytes'] / 1024) ?> KB</span>
                            <?php if ($canEdit): ?>
                                <form method="post" onsubmit="return confirm('Remove this file?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_file"><input type="hidden" name="attachment_id" value="<?= (int)$att['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php else: ?>
                <div class="acc-card-body small text-body-secondary">No photo of the <?= $isSales ? 'invoice' : 'bill' ?> attached.</div>
            <?php endif ?>
            <?php if ($canEdit): ?>
                <form method="post" enctype="multipart/form-data" class="border-top px-3 py-2 d-flex align-items-center gap-2" id="uploadForm">
                    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
                    <label class="btn btn-sm btn-outline-primary mb-0" for="moreFiles"><i class="fa-solid fa-camera me-1"></i> Add photo / PDF</label>
                    <input type="file" id="moreFiles" name="files[]" accept="image/*,application/pdf" multiple class="d-none">
                    <span class="small text-body-secondary" id="moreNote"></span>
                </form>
            <?php endif ?>
        </div>

        <!-- Payments -->
        <?php if (in_array($inv['status'], ['posted', 'paid'], true)): ?>
            <div class="acc-card mb-3">
                <div class="acc-card-head"><h2><?= $isSales ? 'Receipts' : 'Payments' ?></h2>
                    <span class="small <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?> fw-semibold"><?= $outstanding > 0 ? e(money_cents($outstanding, true)) . ' due' : 'Fully ' . strtolower($meta['paid_label']) ?></span></div>
                <?php $active = array_filter($inv['payments'], fn($p) => $p['status'] === 'active'); ?>
                <?php if ($inv['payments']): ?>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($inv['payments'] as $p): ?>
                            <li class="px-3 py-2 border-bottom <?= $p['status'] === 'void' ? 'acc-inactive' : '' ?>">
                                <div class="d-flex justify-content-between">
                                    <span><?= e(bs_date($p['payment_date'])) ?> · <?= e($p['account_name']) ?></span>
                                    <strong><?= e(money($p['amount'])) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between text-body-secondary">
                                    <span><?= $p['payment_id'] ? '<a href="payment.php?id=' . (int)$p['payment_id'] . '">' . e($p['voucher_no']) . '</a>' : e($p['voucher_no']) ?><?= $p['method'] ? ' · ' . e(PAYMENT_METHODS[$p['method']] ?? '') : '' ?><?= $p['reference'] && $p['method'] !== 'advance' ? ' · ' . e($p['reference']) : '' ?><?= $p['status'] === 'void' ? ' · void' : '' ?></span>
                                </div>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
                <?php if ($canEdit && $inv['status'] === 'posted'): $accounts = payment_account_options(); ?>
                    <form method="post" class="px-3 py-3">
                        <?= csrf_field() ?><input type="hidden" name="action" value="pay">
                        <div class="fw-semibold small mb-2">Record <?= $isSales ? 'money received' : 'payment made' ?></div>
                        <?php if (!$accounts): ?>
                            <p class="small mb-0">Add a bank account under <a href="chart-of-accounts.php">1130 Bank Accounts</a> first.</p>
                        <?php else: ?>
                            <div class="row g-2">
                                <div class="col-6"><input type="date" class="form-control form-control-sm" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required aria-label="Date"></div>
                                <div class="col-6"><input class="form-control form-control-sm text-end" name="amount" value="<?= e(cents_to_decimal($outstanding)) ?>" inputmode="decimal" required aria-label="Amount"></div>
                                <div class="col-12"><select class="form-select form-select-sm" name="account_id" aria-label="<?= $isSales ? 'Received into' : 'Paid from' ?>"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?></select></div>
                                <div class="col-6"><select class="form-select form-select-sm" name="method" aria-label="Method"><?php foreach (PAYMENT_METHODS as $k => $label): if ($k === 'advance') continue; ?><option value="<?= $k ?>" <?= $k === 'bank_transfer' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
                                <div class="col-6"><input class="form-control form-control-sm" name="reference" maxlength="100" placeholder="Cheque / txn no."></div>
                                <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="fa-solid fa-money-bill-transfer me-1"></i> Record <?= $isSales ? 'receipt' : 'payment' ?></button></div>
                            </div>
                        <?php endif ?>
                    </form>
                    <?php $advanceAvailable = contact_advance_balance((int)$inv['contact_id'], $isSales ? 'in' : 'out'); ?>
                    <?php if ($advanceAvailable > 0): ?>
                        <form method="post" class="px-3 pb-3">
                            <?= csrf_field() ?><input type="hidden" name="action" value="apply_advance">
                            <div class="fw-semibold small mb-1"><i class="fa-solid fa-rotate me-1"></i> Apply advance</div>
                            <div class="small text-body-secondary mb-2"><?= e(money_cents($advanceAvailable, true)) ?> <?= $isSales ? 'received in advance from this client' : 'paid in advance to this supplier' ?> is available.</div>
                            <div class="row g-2">
                                <div class="col-6"><input type="date" class="form-control form-control-sm" name="advance_date" value="<?= e(date('Y-m-d')) ?>" aria-label="Date"></div>
                                <div class="col-6"><input class="form-control form-control-sm text-end" name="advance_amount" value="<?= e(cents_to_decimal(min($advanceAvailable, $outstanding))) ?>" inputmode="decimal" aria-label="Amount"></div>
                                <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Apply advance to this <?= $isSales ? 'invoice' : 'bill' ?></button></div>
                            </div>
                        </form>
                    <?php endif ?>
                <?php endif ?>
                <?php if ($active): ?><div class="px-3 pb-2 small text-body-secondary">To undo a <?= $isSales ? 'receipt' : 'payment' ?>, open it and void it there (admin).</div><?php endif ?>
            </div>
        <?php endif ?>

        <?php if ($isAdmin && in_array($inv['status'], ['posted', 'paid'], true)): ?>
            <form method="post" class="acc-card" onsubmit="return confirm('Void this <?= strtolower($meta['label']) ?>? Its voucher will be cancelled.')">
                <?= csrf_field() ?><input type="hidden" name="action" value="void">
                <div class="acc-card-body">
                    <label class="form-label fw-semibold small" for="void_reason">Void (admin only)</label>
                    <div class="input-group input-group-sm">
                        <input class="form-control" id="void_reason" name="void_reason" required maxlength="200" placeholder="Reason">
                        <button class="btn btn-outline-danger">Void</button>
                    </div>
                    <?php if ($active ?? []): ?><div class="form-text">Void its <?= $isSales ? 'receipts' : 'payments' ?> first.</div><?php endif ?>
                </div>
            </form>
        <?php endif ?>
    </div>
</div>

<?php
// Photos added here are shrunk in the browser the same way as on the edit page, then submitted.
$parseSize = fn(string $v): int => match (strtolower(substr(trim($v), -1))) { 'g' => (int)$v << 30, 'm' => (int)$v << 20, 'k' => (int)$v << 10, default => (int)$v };
$inlineScript = 'window.accUpload = ' . json_encode(['postLimit' => $parseSize(ini_get('post_max_size') ?: '8M') - 256 * 1024, 'fileLimit' => min($parseSize(ini_get('upload_max_filesize') ?: '2M'), ATTACH_MAX_BYTES)]) . ";
" . <<<'JS'
(function () {
    const input = document.getElementById('moreFiles');
    if (!input) return;
    function shrink(file) {
        return new Promise(resolve => {
            if (!file.type.startsWith('image/') && !/\.hei[cf]$/i.test(file.name)) return resolve(file);
            const url = URL.createObjectURL(file), im = new Image();
            im.onload = () => {
                const s = Math.min(1, 2000 / Math.max(im.naturalWidth, im.naturalHeight)), c = document.createElement('canvas');
                c.width = Math.round(im.naturalWidth * s); c.height = Math.round(im.naturalHeight * s);
                const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(im, 0, 0, c.width, c.height);
                c.toBlob(b => { URL.revokeObjectURL(url); resolve(b ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.85);
            };
            im.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
            im.src = url;
        });
    }
    input.addEventListener('change', async () => {
        const note = document.getElementById('moreNote'), lim = window.accUpload, mb = n => n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toFixed(1) + ' MB';
        note.textContent = 'Preparing…';
        const dt = new DataTransfer(), problems = [];
        for (const f of input.files) {
            const isHeic = /hei[cf]$/i.test(f.type) || /\.hei[cf]$/i.test(f.name);
            const out = await shrink(f);
            if (out === f && isHeic) { problems.push(`${f.name}: iPhone HEIC photo this browser can't read — use "Most Compatible" camera format or a screenshot`); continue; }
            if (out.size > lim.fileLimit) { problems.push(`${f.name} is ${mb(out.size)} (max ${mb(lim.fileLimit)})`); continue; }
            dt.items.add(out);
        }
        const total = Array.from(dt.files).reduce((s, f) => s + f.size, 0);
        if (total > lim.postLimit) problems.push(`together ${mb(total)} — upload at most ${mb(lim.postLimit)} at a time`);
        if (problems.length || !dt.files.length) { note.textContent = problems.join('; ') || 'Nothing to upload.'; note.className = 'small text-danger'; input.value = ''; return; }
        note.textContent = 'Uploading…';
        input.files = dt.files;
        document.getElementById('uploadForm').submit();
    });
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
