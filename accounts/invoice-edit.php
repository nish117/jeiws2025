<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/attachments.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$id = (int)($_GET['id'] ?? 0);
$inv = $id ? load_invoice($id) : null;
if ($id && !$inv) { flash('warning', 'Not found.'); redirect('invoices.php'); }
if ($inv && $inv['status'] !== 'draft') redirect('invoice.php?id=' . $id);
$type = $inv['type'] ?? (($_GET['type'] ?? '') === 'purchase' ? 'purchase' : 'sales');
$meta = INVOICE_TYPES[$type];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete' && $inv) {
        db()->prepare("DELETE FROM acc_invoices WHERE id = ? AND status = 'draft'")->execute([$id]);
        delete_entity_attachments('invoice', $id);
        audit('delete_' . $type . '_draft', 'invoice', $id);
        flash('success', 'Draft deleted.');
        redirect('invoices.php?type=' . $type);
    }
    $check = validate_invoice($type, $_POST, $inv ? $id : null);
    $errors = $check['errors'];
    if (!$errors) {
        try {
            $savedId = save_invoice_draft($check, (int)$user['id'], $inv ? $id : null);
            foreach ((array)($_POST['remove_files'] ?? []) as $attId) {
                foreach (list_attachments('invoice', $savedId) as $att) if ((int)$att['id'] === (int)$attId) delete_attachment($att);
            }
            $up = store_attachments($_FILES['files'] ?? null, 'invoice', $savedId, (int)$user['id']);
            foreach ($up['errors'] as $err) flash('warning', $err);
            if (($_POST['action'] ?? '') === 'save_post') {
                $number = post_invoice($savedId, (int)$user['id']);
                $message = "{$meta['label']} {$number} posted to the books.";
            } else {
                $message = 'Draft saved' . ($up['saved'] ? " with {$up['saved']} attachment" . ($up['saved'] > 1 ? 's' : '') : '') . '.';
            }
            // Shown on the bill page with an "Add another" button (see invoice.php).
            $_SESSION['invoice_saved'] = ['id' => $savedId, 'message' => $message, 'photo' => (bool)list_attachments('invoice', $savedId)];
            redirect('invoice.php?id=' . $savedId);
        } catch (DomainException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    if ($errors && !empty(array_filter(uploaded_file_list($_FILES['files'] ?? null), fn($f) => $f['error'] === UPLOAD_ERR_OK))) {
        $errors[] = 'Your photo was not saved because of the problems above — please choose it again after fixing them.';
    }
}

// Form values: posted data after an error, the draft being edited, or defaults.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_POST;
    $f['lines'] = array_values($_POST['lines'] ?? []);
} elseif ($inv) {
    $f = $inv;
    foreach (['retention_percent', 'tds_percent'] as $k) $f[$k] = (float)$inv[$k] ? rtrim(rtrim($inv[$k], '0'), '.') : '';
    $f['lines'] = array_map(fn($l) => [
        'description' => $l['description'], 'quantity' => rtrim(rtrim($l['quantity'], '0'), '.'), 'unit' => $l['unit'],
        'rate' => $l['rate'], 'vat_applicable' => $l['vat_applicable'], 'account_id' => $l['account_id'],
    ], $inv['lines']);
} else {
    $f = ['invoice_date' => date('Y-m-d'), 'contact_id' => (int)($_GET['contact'] ?? 0), 'project_id' => (int)($_GET['project'] ?? 0), 'lines' => []];
}
$defaultAccount = system_account_id($meta['default_account']);
if (!$f['lines']) $f['lines'][] = ['vat_applicable' => 1, 'account_id' => $defaultAccount, 'quantity' => '1'];

// Contacts of the right kind, with data for the TDS / VAT hints.
$contactRows = db()->prepare('SELECT id, name, pan_number, vat_registered, tds_category, is_active FROM acc_contacts WHERE type = ? ORDER BY name');
$contactRows->execute([$meta['contact']]);
$contacts = array_filter($contactRows->fetchAll(), fn($c) => $c['is_active'] || (int)$c['id'] === (int)($f['contact_id'] ?? 0));
// Completed / cancelled projects are hidden, unless this bill is already tagged with one.
$projectRows = db()->prepare("SELECT id, code, name, retention_percent, status FROM acc_projects
    WHERE status NOT IN ('completed','cancelled') OR id = ? ORDER BY name");
$projectRows->execute([(int)($f['project_id'] ?? 0)]);
$projectRows = $projectRows->fetchAll();
$lineAccounts = invoice_line_accounts($type);
$existingFiles = $inv ? list_attachments('invoice', $id) : [];
$vatRate = (float)setting('vat_rate', '13');
$parseSize = function (string $v): int { $n = (int)$v; return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n }; };
// Whole-save limit: the form fields need a little room too.
$postLimit = $parseSize(ini_get('post_max_size') ?: '8M') - 256 * 1024;
$uploadLimit = (function () use ($parseSize) {
    return min($parseSize(ini_get('upload_max_filesize') ?: '2M'), $parseSize(ini_get('post_max_size') ?: '8M'), ATTACH_MAX_BYTES);
})();
$startWithPhoto = !empty($_GET['photo']) || $existingFiles;

$pageTitle   = $inv ? 'Edit draft — ' . ($inv['number'] ?? strtolower($meta['label'])) : 'New ' . strtolower($meta['label']);
$activeNav   = $meta['nav'];
$breadcrumbs = [['label' => 'Invoices', 'href' => 'invoices.php'], ['label' => $meta['plural'], 'href' => 'invoices.php?type=' . $type], ['label' => $inv ? 'Edit draft' : 'New']];
require __DIR__ . '/includes/layout-top.php';
$isSales = $type === 'sales';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<form method="post" enctype="multipart/form-data" id="invForm" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3 acc-inv-layout <?= $startWithPhoto ? 'has-photo' : '' ?>" id="invLayout">

        <!-- ═══ Photo panel ═══ -->
        <div class="col-12 col-xl-5 acc-inv-photo-col">
            <div class="acc-card acc-inv-photo">
                <div class="acc-card-head">
                    <h2><i class="fa-solid fa-image me-1"></i> <?= $isSales ? 'Invoice' : 'Bill' ?> photo</h2>
                    <div class="btn-group btn-group-sm" id="viewerTools">
                        <button type="button" class="btn btn-outline-secondary" data-act="out" title="Zoom out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-act="in" title="Zoom in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-act="rotate" title="Rotate"><i class="fa-solid fa-rotate-right"></i></button>
                        <button type="button" class="btn btn-outline-secondary" data-act="fit" title="Fit"><i class="fa-solid fa-expand"></i></button>
                    </div>
                </div>
                <div class="acc-viewer" id="viewer">
                    <div class="acc-viewer-empty" id="viewerEmpty">
                        <i class="fa-solid fa-camera"></i>
                        <p>Take or choose a photo of the <?= $isSales ? 'invoice' : 'bill' ?>.<br>It stays beside the form while you type the figures.</p>
                    </div>
                    <img id="viewerImg" alt="" hidden>
                    <iframe id="viewerPdf" title="PDF preview" hidden></iframe>
                </div>
                <div class="acc-viewer-thumbs" id="thumbs">
                    <?php foreach ($existingFiles as $att): ?>
                        <div class="acc-thumb" data-src="attachment.php?id=<?= (int)$att['id'] ?>" data-pdf="<?= $att['mime_type'] === 'application/pdf' ? 1 : 0 ?>">
                            <?php if ($att['mime_type'] === 'application/pdf'): ?><span class="acc-thumb-pdf">PDF</span><?php else: ?><img src="attachment.php?id=<?= (int)$att['id'] ?>" alt=""><?php endif ?>
                            <label class="acc-thumb-remove" title="Remove this file when saving"><input type="checkbox" name="remove_files[]" value="<?= (int)$att['id'] ?>"> <i class="fa-solid fa-trash"></i></label>
                        </div>
                    <?php endforeach ?>
                </div>
                <div class="border-top px-3 py-2">
                    <label class="btn btn-sm btn-primary mb-0" for="fileInput"><i class="fa-solid fa-camera me-1"></i> Add photo / PDF</label>
                    <input type="file" id="fileInput" name="files[]" accept="image/*,application/pdf" multiple class="d-none">
                    <span class="small text-body-secondary ms-2" id="fileNote">Photos are shrunk before upload. PDFs up to <?= round($uploadLimit / 1048576, 1) ?> MB.</span>
                </div>
            </div>
        </div>

        <!-- ═══ Form ═══ -->
        <div class="col-12 acc-inv-form-col">
            <?php if (!$startWithPhoto): ?>
                <div class="mb-3"><button type="button" class="btn btn-sm btn-outline-primary" id="showPhoto"><i class="fa-solid fa-camera me-1"></i> Attach a photo of the <?= $isSales ? 'invoice' : 'bill' ?></button></div>
            <?php endif ?>

            <div class="acc-card mb-3">
                <div class="acc-card-head"><h2><?= e($meta['label']) ?> details</h2></div>
                <div class="acc-card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold" for="contact_id"><?= ucfirst($meta['contact']) ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="contact_id" name="contact_id" required>
                                <option value="">Choose…</option>
                                <?php foreach ($contacts as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" data-tds="<?= e((string)suggested_tds_percent($c, $type)) ?>" data-vat="<?= (int)$c['vat_registered'] ?>" data-pan="<?= e($c['pan_number'] ?? '') ?>"
                                        <?= (int)$c['id'] === (int)($f['contact_id'] ?? 0) ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['pan_number'] ? ' · PAN ' . e($c['pan_number']) : '' ?></option>
                                <?php endforeach ?>
                            </select>
                            <div class="form-text" id="contactHint">Not listed? <a href="contact-edit.php?type=<?= $meta['contact'] ?>" target="_blank" rel="noopener">Add a <?= $meta['contact'] ?></a> in a new tab, then reload.</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold" for="number"><?= $isSales ? 'Invoice no.' : "Supplier's bill no." ?><?= $isSales ? '' : ' <span class="text-danger">*</span>' ?></label>
                            <input class="form-control" id="number" name="number" value="<?= e($f['number'] ?? '') ?>" maxlength="40" <?= $isSales ? 'placeholder="Auto: SI/' . e(str_replace('/', '-', fiscal_year_for(date('Y-m-d')))) . '/…"' : 'required' ?>>
                            <div class="form-text"><?= $isSales ? 'Leave blank to auto-number on posting, or type the number printed on a bill-book invoice.' : 'As printed on the bill. Used to catch the same bill being entered twice.' ?></div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="form-label fw-semibold" for="invoice_date"><?= $isSales ? 'Invoice' : 'Bill' ?> date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?= e($f['invoice_date'] ?? '') ?>" required>
                            <div class="acc-bs-hint" data-date-hint="invoice_date">&nbsp;</div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <label class="form-label fw-semibold" for="due_date">Due date</label>
                            <input type="date" class="form-control" id="due_date" name="due_date" value="<?= e($f['due_date'] ?? '') ?>">
                            <div class="acc-bs-hint" data-date-hint="due_date">&nbsp;</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="project_id">Project</label>
                            <select class="form-select" id="project_id" name="project_id">
                                <option value="" data-retention="0">— None —</option>
                                <?php foreach ($projectRows as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" data-retention="<?= e(rtrim(rtrim($p['retention_percent'], '0'), '.') ?: '0') ?>" <?= (int)$p['id'] === (int)($f['project_id'] ?? 0) ? 'selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="acc-card mb-3">
                <div class="acc-card-head"><h2>Items</h2><span class="small text-body-secondary">VAT <?= e(rtrim(rtrim((string)$vatRate, '0'), '.')) ?>% on ticked lines</span></div>
                <div class="table-responsive">
                    <table class="table acc-je-table acc-inv-lines mb-0">
                        <thead><tr><th style="min-width:200px">Description</th><th class="num" style="width:90px">Qty</th><th style="width:80px">Unit</th><th class="num" style="width:120px">Rate</th><th class="text-center" style="width:50px">VAT</th><th style="min-width:180px">Account</th><th class="num" style="width:120px">Amount</th><th></th></tr></thead>
                        <tbody id="invLines">
                        <?php foreach ($f['lines'] as $i => $l): ?>
                            <tr class="inv-line">
                                <td><input class="form-control form-control-sm" name="lines[<?= $i ?>][description]" value="<?= e($l['description'] ?? '') ?>" maxlength="255" placeholder="<?= $isSales ? 'e.g. RA bill 1 — RCC work' : 'e.g. OPC cement 50 kg' ?>"></td>
                                <td data-label="Qty"><input class="form-control form-control-sm text-end inv-qty" name="lines[<?= $i ?>][quantity]" value="<?= e((string)($l['quantity'] ?? '')) ?>" inputmode="decimal"></td>
                                <td data-label="Unit"><input class="form-control form-control-sm" name="lines[<?= $i ?>][unit]" value="<?= e($l['unit'] ?? '') ?>" maxlength="20" placeholder="bag, m³"></td>
                                <td data-label="Rate"><input class="form-control form-control-sm text-end inv-rate" name="lines[<?= $i ?>][rate]" value="<?= e(isset($l['rate']) && $l['rate'] !== '' && (float)$l['rate'] ? (string)$l['rate'] : '') ?>" inputmode="decimal" placeholder="0.00"></td>
                                <td data-label="VAT" class="text-center"><input class="form-check-input inv-vat" type="checkbox" name="lines[<?= $i ?>][vat_applicable]" value="1" <?= !empty($l['vat_applicable']) ? 'checked' : '' ?>></td>
                                <td data-label="Account">
                                    <select class="form-select form-select-sm" name="lines[<?= $i ?>][account_id]">
                                        <?php foreach ($lineAccounts as $accType => $list): ?>
                                            <optgroup label="<?= e(ACC_ACCOUNT_TYPES[$accType]['label']) ?>">
                                                <?php foreach ($list as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)($l['account_id'] ?? $defaultAccount) ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?>
                                            </optgroup>
                                        <?php endforeach ?>
                                    </select>
                                </td>
                                <td class="num inv-amount fw-semibold" data-label="Amount">0.00</td>
                                <td><button type="button" class="btn btn-sm btn-link text-danger inv-remove" aria-label="Remove line"><i class="fa-solid fa-xmark"></i></button></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 border-top"><button type="button" class="btn btn-sm btn-outline-primary" id="addLine"><i class="fa-solid fa-plus me-1"></i> Add line</button></div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="acc-card h-100">
                        <div class="acc-card-body">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label fw-semibold small" for="retention_percent">Retention %</label>
                                    <input class="form-control form-control-sm text-end" id="retention_percent" name="retention_percent" value="<?= e((string)($f['retention_percent'] ?? '')) ?>" inputmode="decimal" placeholder="0">
                                    <div class="form-text"><?= $isSales ? 'Held back by the client' : 'Held back from the subcontractor' ?></div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small" for="tds_percent">TDS %</label>
                                    <input class="form-control form-control-sm text-end" id="tds_percent" name="tds_percent" value="<?= e((string)($f['tds_percent'] ?? '')) ?>" inputmode="decimal" placeholder="0">
                                    <div class="form-text" id="tdsHint"><?= $isSales ? 'Withheld by the client' : 'Withheld by us, paid to IRD' ?></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" for="notes">Notes</label>
                                    <textarea class="form-control form-control-sm" id="notes" name="notes" rows="2" maxlength="500"><?= e($f['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="acc-card h-100">
                        <table class="table table-sm mb-0 acc-inv-totals">
                            <tbody>
                                <tr><td>Taxable amount</td><td class="num" id="tTaxable">0.00</td></tr>
                                <tr><td>Non-taxable amount</td><td class="num" id="tExempt">0.00</td></tr>
                                <tr><td>VAT <?= e(rtrim(rtrim((string)$vatRate, '0'), '.')) ?>%</td><td class="num" id="tVat">0.00</td></tr>
                                <tr class="table-subtotal"><td>Total</td><td class="num" id="tTotal">0.00</td></tr>
                                <tr><td>− Retention</td><td class="num" id="tRetention">0.00</td></tr>
                                <tr><td>− TDS</td><td class="num" id="tTds">0.00</td></tr>
                                <tr class="table-total"><td><?= $isSales ? 'Net receivable' : 'Net payable' ?></td><td class="num" id="tNet">0.00</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between gap-2 mb-4">
                <div>
                    <?php if ($inv): ?>
                        <button type="submit" name="action" value="delete" class="btn btn-outline-danger" formnovalidate data-confirm="Delete this draft and its attachments?">Delete draft</button>
                    <?php else: ?>
                        <a href="invoices.php?type=<?= $type ?>" class="btn btn-link text-body-secondary">Cancel</a>
                    <?php endif ?>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" name="action" value="save" class="btn btn-outline-secondary">Save draft</button>
                    <button type="submit" name="action" value="save_post" class="btn btn-primary" data-confirm="Post this <?= e(strtolower($meta['label'])) ?> to the books? It can't be edited afterwards (only voided)."><i class="fa-solid fa-check me-1"></i> Save &amp; post</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = 'window.accInvoice = ' . json_encode(['vatRate' => $vatRate, 'uploadLimit' => $uploadLimit, 'postLimit' => $postLimit, 'isSales' => $isSales], JSON_HEX_TAG) . ";\n" . <<<'JS'
(function () {
    const cfg = window.accInvoice;
    const tbody = document.getElementById('invLines');
    const cents = v => { v = String(v || '').replace(/[,\s]/g, ''); return /^\d+(\.\d{0,2})?$/.test(v) ? Math.round(parseFloat(v) * 100) : 0; };
    const qty = v => { v = String(v || '').replace(/[,\s]/g, ''); return /^\d+(\.\d{0,3})?$/.test(v) ? parseFloat(v) : (v === '' ? 1 : 0); };
    const fmt = c => (c / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const pct = id => { const v = parseFloat(document.getElementById(id).value); return isNaN(v) ? 0 : v; };

    function recalc() {
        let taxable = 0, exempt = 0;
        tbody.querySelectorAll('.inv-line').forEach(row => {
            const amount = Math.round(cents(row.querySelector('.inv-rate').value) * qty(row.querySelector('.inv-qty').value));
            row.querySelector('.inv-amount').textContent = fmt(amount);
            if (row.querySelector('.inv-vat').checked) taxable += amount; else exempt += amount;
        });
        const vat = Math.round(taxable * cfg.vatRate / 100), base = taxable + exempt, total = base + vat;
        const ret = Math.round(base * pct('retention_percent') / 100), tds = Math.round(base * pct('tds_percent') / 100);
        const set = (id, v) => { document.getElementById(id).textContent = fmt(v); };
        set('tTaxable', taxable); set('tExempt', exempt); set('tVat', vat); set('tTotal', total);
        set('tRetention', ret); set('tTds', tds); set('tNet', total - ret - tds);
    }
    function renumber() {
        tbody.querySelectorAll('.inv-line').forEach((row, i) => row.querySelectorAll('[name^="lines["]').forEach(el => { el.name = el.name.replace(/^lines\[\d+\]/, `lines[${i}]`); }));
    }
    document.getElementById('addLine').addEventListener('click', () => {
        const rows = tbody.querySelectorAll('.inv-line');
        const row = rows[rows.length - 1].cloneNode(true);
        row.querySelectorAll('input[type=text], input:not([type])').forEach(i => { i.value = ''; });
        row.querySelector('.inv-qty').value = '1';
        tbody.appendChild(row); renumber(); recalc();
        row.querySelector('input').focus();
    });
    tbody.addEventListener('click', e => {
        const b = e.target.closest('.inv-remove'); if (!b) return;
        if (tbody.querySelectorAll('.inv-line').length > 1) { b.closest('tr').remove(); renumber(); }
        else b.closest('tr').querySelectorAll('input:not([type=checkbox])').forEach(i => { i.value = ''; });
        recalc();
    });
    document.getElementById('invForm').addEventListener('input', recalc);
    document.getElementById('invForm').addEventListener('change', recalc);

    // Party → suggested TDS (only while the user hasn't typed their own) and VAT warning.
    const contact = document.getElementById('contact_id'), tdsInput = document.getElementById('tds_percent');
    let tdsTouched = tdsInput.value !== '';
    tdsInput.addEventListener('input', () => { tdsTouched = true; });
    contact.addEventListener('change', () => {
        const opt = contact.selectedOptions[0];
        if (!opt || !opt.value) return;
        if (!tdsTouched) tdsInput.value = parseFloat(opt.dataset.tds) ? opt.dataset.tds : '';
        const hint = document.getElementById('tdsHint');
        hint.textContent = (cfg.isSales ? 'Withheld by the client' : 'Withheld by us, paid to IRD') + (parseFloat(opt.dataset.tds) ? ` · usual rate ${opt.dataset.tds}%` : '');
        if (!cfg.isSales && opt.dataset.vat === '0') {
            tbody.querySelectorAll('.inv-vat').forEach(cb => { cb.checked = false; });
            document.getElementById('contactHint').innerHTML = '<span class="text-warning-emphasis"><i class="fa-solid fa-circle-info"></i> Not VAT-registered — VAT has been unticked.</span>';
        }
        recalc();
    });
    // Project → its retention % (sales), unless already typed.
    const project = document.getElementById('project_id'), retInput = document.getElementById('retention_percent');
    let retTouched = retInput.value !== '';
    retInput.addEventListener('input', () => { retTouched = true; });
    project.addEventListener('change', () => {
        if (cfg.isSales && !retTouched) { const r = project.selectedOptions[0].dataset.retention; retInput.value = parseFloat(r) ? r : ''; recalc(); }
    });

    // B.S. hints under the dates.
    document.querySelectorAll('[data-date-hint]').forEach(hint => {
        const input = document.getElementById(hint.dataset.dateHint);
        const show = () => { const bs = typeof adToBs === 'function' ? adToBs(input.value) : null; hint.textContent = bs ? `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]})` : ' '; };
        input.addEventListener('input', show); show();
    });

    // ── Photo viewer ──────────────────────────────────────────────
    const layout = document.getElementById('invLayout'), img = document.getElementById('viewerImg'), pdf = document.getElementById('viewerPdf');
    const thumbs = document.getElementById('thumbs'), empty = document.getElementById('viewerEmpty');
    let zoom = 1, rotation = 0;
    const applyView = () => { img.style.transform = `rotate(${rotation}deg) scale(${zoom})`; };
    function show(src, isPdf) {
        empty.hidden = true; zoom = 1; rotation = 0; applyView();
        img.hidden = isPdf; pdf.hidden = !isPdf;
        if (isPdf) pdf.src = src; else img.src = src;
        thumbs.querySelectorAll('.acc-thumb').forEach(t => t.classList.toggle('active', t.dataset.src === src));
    }
    thumbs.addEventListener('click', e => {
        const t = e.target.closest('.acc-thumb');
        if (t && !e.target.closest('.acc-thumb-remove')) show(t.dataset.src, t.dataset.pdf === '1');
    });
    document.getElementById('viewerTools').addEventListener('click', e => {
        const act = e.target.closest('button')?.dataset.act;
        if (act === 'in') zoom = Math.min(4, zoom + 0.25);
        if (act === 'out') zoom = Math.max(0.5, zoom - 0.25);
        if (act === 'rotate') rotation = (rotation + 90) % 360;
        if (act === 'fit') { zoom = 1; rotation = 0; }
        applyView();
    });
    const first = thumbs.querySelector('.acc-thumb');
    if (first) show(first.dataset.src, first.dataset.pdf === '1');
    document.getElementById('showPhoto')?.addEventListener('click', e => { layout.classList.add('has-photo'); e.target.closest('div').remove(); document.getElementById('fileInput').click(); });

    // Shrink photos in the browser before upload (phone photos are 3–8 MB; most servers accept 2 MB).
    const fileInput = document.getElementById('fileInput'), note = document.getElementById('fileNote');
    function shrink(file) {
        return new Promise(resolve => {
            const url = URL.createObjectURL(file), im = new Image();
            im.onload = () => {
                const scale = Math.min(1, 2000 / Math.max(im.naturalWidth, im.naturalHeight));
                const c = document.createElement('canvas');
                c.width = Math.round(im.naturalWidth * scale); c.height = Math.round(im.naturalHeight * scale);
                const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(im, 0, 0, c.width, c.height);
                c.toBlob(b => { URL.revokeObjectURL(url); resolve(b ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.85);
            };
            im.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
            im.src = url;
        });
    }
    // Files picked in several goes accumulate here (assigning input.files replaces, so keep our own list).
    let pending = [];
    const mb = n => n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toFixed(1) + ' MB';
    function syncInput(problems = []) {
        const dt = new DataTransfer();
        pending.forEach(p => dt.items.add(p.file));
        fileInput.files = dt.files;
        const total = pending.reduce((s, p) => s + p.file.size, 0);
        if (total > cfg.postLimit) problems.push(`together the new files are ${mb(total)} — the server accepts ${mb(cfg.postLimit)} per save. Remove some, then add them after saving`);
        note.textContent = problems.length ? problems.join('; ') + '.' : (pending.length ? `${pending.length} file(s), ${mb(total)}, will be attached when you save.` : 'Photos are shrunk before upload.');
        note.classList.toggle('text-danger', problems.length > 0);
        document.querySelectorAll('#invForm button[type=submit]').forEach(b => { if (b.value !== 'delete') b.disabled = total > cfg.postLimit; });
    }
    fileInput.addEventListener('change', async () => {
        const chosen = Array.from(fileInput.files).filter(f => !pending.some(p => p.original === f));
        if (!chosen.length) { syncInput(); return; }
        layout.classList.add('has-photo');
        note.textContent = 'Preparing…';
        const problems = [];
        for (const f of chosen) {
            const isHeic = /hei[cf]$/i.test(f.type) || /\.hei[cf]$/i.test(f.name);
            const out = f.type.startsWith('image/') || isHeic ? await shrink(f) : f;
            if (out === f && isHeic) { problems.push(`${f.name} is an iPhone HEIC photo this browser can't read — choose "Most Compatible" in iPhone camera settings, or take a screenshot of it`); continue; }
            if (out.size > cfg.uploadLimit) { problems.push(`${f.name} is too large (${mb(out.size)}; max ${mb(cfg.uploadLimit)} per file)`); continue; }
            const src = URL.createObjectURL(out), isPdf = out.type === 'application/pdf';
            const t = document.createElement('div');
            t.className = 'acc-thumb acc-thumb-new'; t.dataset.src = src; t.dataset.pdf = isPdf ? '1' : '0';
            t.innerHTML = (isPdf ? '<span class="acc-thumb-pdf">PDF</span>' : `<img src="${src}" alt="">`)
                + '<button type="button" class="acc-thumb-remove border-0" title="Don\'t attach this file" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>';
            const entry = { file: out, original: f, thumb: t };
            t.querySelector('button').addEventListener('click', e => {
                e.stopPropagation();
                pending = pending.filter(p => p !== entry);
                t.remove(); URL.revokeObjectURL(src);
                const next = thumbs.querySelector('.acc-thumb');
                if (next) show(next.dataset.src, next.dataset.pdf === '1'); else { img.hidden = true; pdf.hidden = true; empty.hidden = false; }
                syncInput();
            });
            pending.push(entry);
            thumbs.appendChild(t);
            show(src, isPdf);
        }
        syncInput(problems);
    });

    // Card layout for the lines on phones and whenever the photo panel takes half the width.
    const linesTable = document.querySelector('.acc-inv-lines'), phone = window.matchMedia('(max-width: 767.98px)');
    const syncCards = () => linesTable.classList.toggle('acc-inv-lines-card', phone.matches || layout.classList.contains('has-photo'));
    new MutationObserver(syncCards).observe(layout, { attributes: true, attributeFilter: ['class'] });
    phone.addEventListener('change', syncCards);
    syncCards();

    let dirty = false;
    document.getElementById('invForm').addEventListener('input', () => { dirty = true; });
    document.getElementById('invForm').addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    recalc();
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
