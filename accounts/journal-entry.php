<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

$id = (int)($_GET['id'] ?? 0);
$copyId = (int)($_GET['copy'] ?? 0);

function load_entry(int $id): ?array {
    $stmt = db()->prepare(
        'SELECT e.*, cu.full_name AS created_by_name, pu.full_name AS posted_by_name, vu.full_name AS voided_by_name
         FROM acc_journal_entries e
         LEFT JOIN acc_users cu ON cu.id = e.created_by
         LEFT JOIN acc_users pu ON pu.id = e.posted_by
         LEFT JOIN acc_users vu ON vu.id = e.voided_by
         WHERE e.id = ?'
    );
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    if (!$entry) return null;
    $lines = db()->prepare(
        'SELECT l.*, a.code, a.name AS account_name FROM acc_journal_lines l
         JOIN acc_accounts a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.line_no'
    );
    $lines->execute([$id]);
    $entry['lines'] = $lines->fetchAll();
    return $entry;
}

$entry = $id ? load_entry($id) : null;
if ($id && !$entry) { flash('warning', 'Voucher not found.'); redirect('journal.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_draft' || $action === 'save_post') {
            $data = [
                'entry_date' => (string)($_POST['entry_date'] ?? ''),
                'narration'  => (string)($_POST['narration'] ?? ''),
                'reference'  => (string)($_POST['reference'] ?? ''),
                'lines'      => is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [],
            ];
            $result = save_journal_entry($data, (int)$user['id'], $entry ? (int)$entry['id'] : null, $action === 'save_post');
            if ($result['errors']) {
                $errors = $result['errors'];
            } else {
                if (!$entry) audit('create_journal', 'journal_entry', $result['id']);
                $saved = load_entry($result['id']);
                flash('success', $action === 'save_post' ? "Voucher {$saved['voucher_no']} posted." : 'Draft saved.');
                redirect('journal-entry.php?id=' . $result['id']);
            }
        } elseif ($action === 'post' && $entry) {
            $voucherNo = post_journal_entry((int)$entry['id'], (int)$user['id']);
            flash('success', "Voucher {$voucherNo} posted.");
            redirect('journal-entry.php?id=' . $entry['id']);
        } elseif ($action === 'void' && $entry) {
            void_journal_entry((int)$entry['id'], (int)$user['id'], (string)($_POST['void_reason'] ?? ''));
            flash('success', "Voucher {$entry['voucher_no']} voided.");
            redirect('journal-entry.php?id=' . $entry['id']);
        } elseif ($action === 'delete' && $entry) {
            delete_draft_entry((int)$entry['id']);
            flash('success', 'Draft deleted.');
            redirect('journal.php');
        }
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    }
}

// Edit mode: new voucher, a copy, or an existing draft. Everything else is read-only view.
$editMode = $canEdit && (!$entry || $entry['status'] === 'draft') && ($entry === null || isset($_GET['edit']) || $errors);
if (!$canEdit && !$entry) redirect('journal.php');

if ($editMode) {
    $source = $copyId ? load_entry($copyId) : $entry;
    $form = $_SERVER['REQUEST_METHOD'] === 'POST' && $errors && in_array($_POST['action'] ?? '', ['save_draft', 'save_post'], true)
        ? ['entry_date' => $_POST['entry_date'] ?? '', 'narration' => $_POST['narration'] ?? '', 'reference' => $_POST['reference'] ?? '', 'lines' => array_values($_POST['lines'] ?? [])]
        : [
            'entry_date' => $entry['entry_date'] ?? date('Y-m-d'),
            'narration'  => $source['narration'] ?? '',
            'reference'  => $source['reference'] ?? '',
            'lines'      => array_map(fn($l) => [
                'account_id'  => $l['account_id'],
                'description' => $l['description'],
                'project_id'  => $l['project_id'],
                'contact_id'  => $l['contact_id'],
                'debit'       => (float)$l['debit'] ? $l['debit'] : '',
                'credit'      => (float)$l['credit'] ? $l['credit'] : '',
            ], $source['lines'] ?? []),
        ];
    while (count($form['lines']) < 2) $form['lines'][] = [];
    $accountsByType = postable_accounts_by_type();
    $projects = project_options();
    $contacts = contact_options();
}

$title = $entry ? ($entry['voucher_no'] ?? 'Draft voucher #' . $entry['id']) : 'New journal voucher';
$pageTitle   = $title;
$activeNav   = 'accounting';
$breadcrumbs = [['label' => 'Accounting', 'href' => 'accounting.php'], ['label' => 'Journal vouchers', 'href' => 'journal.php'], ['label' => $entry ? $title : 'New']];
$pageActions = '';
if ($entry && !$editMode) {
    $pageActions .= '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
    if ($canEdit && $entry['source'] === 'manual') $pageActions .= '<a class="btn btn-outline-secondary" href="journal-entry.php?copy=' . (int)$entry['id'] . '"><i class="fa-regular fa-copy me-1"></i> Duplicate</a>';
    if ($canEdit && $entry['status'] === 'draft') $pageActions .= '<a class="btn btn-primary" href="journal-entry.php?id=' . (int)$entry['id'] . '&edit=1"><i class="fa-solid fa-pen me-1"></i> Edit</a>';
}
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<?php if ($editMode): ?>
<!-- ═══════════ EDIT ═══════════ -->
<form method="post" class="acc-card" id="jeForm" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label fw-semibold" for="entry_date">Date (A.D.)</label>
                <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= e($form['entry_date']) ?>" required>
                <div class="acc-bs-hint" id="bsHint">&nbsp;</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label fw-semibold" for="reference">Reference <span class="fw-normal text-body-secondary">(optional)</span></label>
                <input class="form-control" id="reference" name="reference" value="<?= e($form['reference']) ?>" maxlength="100" placeholder="Bill no., cheque no.…">
            </div>
            <div class="col-lg-6">
                <label class="form-label fw-semibold" for="narration">Narration</label>
                <input class="form-control" id="narration" name="narration" value="<?= e($form['narration']) ?>" maxlength="500" required placeholder="What is this entry for?">
            </div>
        </div>
    </div>

    <div class="table-responsive border-top">
        <table class="table acc-je-table mb-0">
            <thead><tr><th style="min-width:240px">Account</th><th style="min-width:180px">Line description</th><th style="min-width:170px">Project</th><th style="min-width:170px">Party</th><th class="num">Debit</th><th class="num">Credit</th><th></th></tr></thead>
            <tbody id="jeLines">
            <?php foreach ($form['lines'] as $i => $line): ?>
                <tr class="je-line">
                    <td>
                        <select class="form-select form-select-sm" name="lines[<?= $i ?>][account_id]">
                            <option value="">Choose account…</option>
                            <?php foreach ($accountsByType as $type => $list): ?>
                                <optgroup label="<?= e(ACC_ACCOUNT_TYPES[$type]['label']) ?>">
                                    <?php foreach ($list as $a): ?>
                                        <option value="<?= (int)$a['id'] ?>" <?= (string)$a['id'] === (string)($line['account_id'] ?? '') ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?></option>
                                    <?php endforeach ?>
                                </optgroup>
                            <?php endforeach ?>
                        </select>
                    </td>
                    <td data-label="Description"><input class="form-control form-control-sm" name="lines[<?= $i ?>][description]" value="<?= e($line['description'] ?? '') ?>" maxlength="255"></td>
                    <td data-label="Project">
                        <select class="form-select form-select-sm" name="lines[<?= $i ?>][project_id]">
                            <option value="">—</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (string)$p['id'] === (string)($line['project_id'] ?? '') ? 'selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?><?= in_array($p['status'], ['completed', 'cancelled'], true) ? ' (closed)' : '' ?></option>
                            <?php endforeach ?>
                        </select>
                    </td>
                    <td data-label="Party">
                        <select class="form-select form-select-sm" name="lines[<?= $i ?>][contact_id]">
                            <option value="">—</option>
                            <?php foreach (['client' => 'Clients', 'supplier' => 'Suppliers'] as $ctype => $clabel): ?>
                                <optgroup label="<?= $clabel ?>">
                                    <?php foreach ($contacts as $c): if ($c['type'] !== $ctype) continue; ?>
                                        <option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === (string)($line['contact_id'] ?? '') ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['is_active'] ? '' : ' (inactive)' ?></option>
                                    <?php endforeach ?>
                                </optgroup>
                            <?php endforeach ?>
                        </select>
                    </td>
                    <td data-label="Debit"><input class="form-control form-control-sm acc-amount je-debit" name="lines[<?= $i ?>][debit]" value="<?= e((string)($line['debit'] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></td>
                    <td data-label="Credit"><input class="form-control form-control-sm acc-amount je-credit" name="lines[<?= $i ?>][credit]" value="<?= e((string)($line['credit'] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></td>
                    <td><button type="button" class="btn btn-sm btn-link text-danger je-remove" title="Remove line" aria-label="Remove line"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="jeAddLine"><i class="fa-solid fa-plus me-1"></i> Add line</button>
                        <span class="ms-3 small fw-semibold" id="jeDiff"></span>
                    </td>
                    <td class="num" id="jeTotalDebit">0.00</td>
                    <td class="num" id="jeTotalCredit">0.00</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="border-top px-4 py-3 d-flex flex-wrap justify-content-between gap-2">
        <a href="<?= $entry ? 'journal-entry.php?id=' . (int)$entry['id'] : 'journal.php' ?>" class="btn btn-link text-body-secondary">Cancel</a>
        <div class="d-flex gap-2">
            <button type="submit" name="action" value="save_draft" class="btn btn-outline-secondary">Save as draft</button>
            <button type="submit" name="action" value="save_post" class="btn btn-primary" id="jePostBtn"><i class="fa-solid fa-check me-1"></i> Save &amp; post</button>
        </div>
    </div>
</form>
<p class="small text-body-secondary mt-2">Drafts don't affect balances. Once posted, a voucher gets its number and can no longer be edited — mistakes are corrected by voiding it and posting a new one.</p>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = <<<'JS'
(function () {
    const tbody = document.getElementById('jeLines');
    const toCents = v => {
        v = String(v || '').replace(/[,\s]/g, '');
        if (!/^\d+(\.\d{0,2})?$/.test(v)) return v === '' ? 0 : NaN;
        const [w, f = ''] = v.split('.');
        return Number(w) * 100 + Number((f + '00').slice(0, 2));
    };
    const fmt = c => (c / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function renumber() {
        tbody.querySelectorAll('.je-line').forEach((row, i) => {
            row.querySelectorAll('[name^="lines["]').forEach(el => { el.name = el.name.replace(/^lines\[\d+\]/, `lines[${i}]`); });
        });
    }
    function recalc() {
        let dr = 0, cr = 0, bad = false;
        tbody.querySelectorAll('.je-line').forEach(row => {
            const d = toCents(row.querySelector('.je-debit').value), c = toCents(row.querySelector('.je-credit').value);
            row.querySelector('.je-debit').classList.toggle('is-invalid', Number.isNaN(d));
            row.querySelector('.je-credit').classList.toggle('is-invalid', Number.isNaN(c));
            if (Number.isNaN(d) || Number.isNaN(c)) { bad = true; return; }
            dr += d; cr += c;
        });
        document.getElementById('jeTotalDebit').textContent = fmt(dr);
        document.getElementById('jeTotalCredit').textContent = fmt(cr);
        const diff = document.getElementById('jeDiff');
        const balanced = !bad && dr === cr && dr > 0;
        diff.className = 'ms-3 small fw-semibold ' + (balanced ? 'acc-diff-ok' : 'acc-diff-bad');
        diff.innerHTML = bad ? 'Check the highlighted amounts'
            : balanced ? '<i class="fa-solid fa-circle-check"></i> Balanced'
            : dr === 0 && cr === 0 ? '' : `Out of balance by ${fmt(Math.abs(dr - cr))}`;
        document.getElementById('jePostBtn').disabled = !balanced;
    }

    document.getElementById('jeAddLine').addEventListener('click', () => {
        const template = tbody.querySelector('.je-line');
        const row = template.cloneNode(true);
        row.querySelectorAll('input').forEach(i => { i.value = ''; i.classList.remove('is-invalid'); });
        row.querySelectorAll('select').forEach(s => { s.selectedIndex = 0; });
        tbody.appendChild(row);
        renumber();
        row.querySelector('select').focus();
    });
    tbody.addEventListener('click', e => {
        const btn = e.target.closest('.je-remove');
        if (!btn) return;
        if (tbody.querySelectorAll('.je-line').length <= 2) {
            btn.closest('tr').querySelectorAll('input').forEach(i => { i.value = ''; });
            btn.closest('tr').querySelectorAll('select').forEach(s => { s.selectedIndex = 0; });
        } else {
            btn.closest('tr').remove();
            renumber();
        }
        recalc();
    });
    tbody.addEventListener('input', e => {
        // Typing a debit clears the credit on the same line, and vice versa.
        if (e.target.classList.contains('je-debit') && e.target.value) e.target.closest('tr').querySelector('.je-credit').value = '';
        if (e.target.classList.contains('je-credit') && e.target.value) e.target.closest('tr').querySelector('.je-debit').value = '';
        recalc();
    });

    // Enter in the last amount cell adds a new line instead of submitting.
    tbody.addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target.matches('.je-debit, .je-credit')) {
            e.preventDefault();
            const rows = tbody.querySelectorAll('.je-line');
            if (e.target.closest('tr') === rows[rows.length - 1]) document.getElementById('jeAddLine').click();
        }
    });

    // B.S. date hint under the A.D. date input.
    const dateInput = document.getElementById('entry_date');
    function showBs() {
        const bs = typeof adToBs === 'function' ? adToBs(dateInput.value) : null;
        if (!bs) { document.getElementById('bsHint').textContent = ' '; return; }
        const fyStart = bs.month >= 4 ? bs.year : bs.year - 1;
        document.getElementById('bsHint').textContent =
            `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]}) · FY ${fyStart}/${String((fyStart + 1) % 100).padStart(2, '0')}`;
    }
    dateInput.addEventListener('input', showBs);
    showBs();
    recalc();

    let dirty = false;
    document.getElementById('jeForm').addEventListener('input', () => { dirty = true; });
    document.getElementById('jeForm').addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
JS;
?>

<?php else: ?>
<!-- ═══════════ VIEW ═══════════ -->
<?php if ($entry['status'] === 'void'): ?>
    <div class="alert alert-secondary">
        <i class="fa-solid fa-ban me-1"></i> Voided by <?= e($entry['voided_by_name'] ?? 'unknown') ?> on <?= e(date('d M Y, H:i', strtotime($entry['voided_at']))) ?>.
        <strong>Reason:</strong> <?= e($entry['void_reason']) ?>. This voucher no longer affects any balance.
    </div>
<?php elseif ($entry['status'] === 'draft'): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="fa-solid fa-pen-ruler me-1"></i> This is a draft. It doesn't affect balances until it's posted.</span>
        <?php if ($canEdit): ?>
            <form method="post" class="d-flex gap-2">
                <?= csrf_field() ?>
                <button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this draft?')">Delete draft</button>
                <button name="action" value="post" class="btn btn-sm btn-primary"><i class="fa-solid fa-check me-1"></i> Post now</button>
            </form>
        <?php endif ?>
    </div>
<?php endif ?>

<div class="acc-card">
    <div class="acc-card-body">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="small text-body-secondary">Voucher no.</div>
                <div class="fw-bold"><?= e($entry['voucher_no'] ?? '—') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-body-secondary">Date</div>
                <div class="fw-semibold"><?= e(bs_date($entry['entry_date'])) ?> B.S.</div>
                <div class="small text-body-secondary"><?= e(date('d M Y', strtotime($entry['entry_date']))) ?> A.D.</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-body-secondary">Fiscal year</div>
                <div class="fw-semibold"><?= e($entry['fiscal_year']) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-body-secondary">Status</div>
                <div><?= status_badge($entry['status']) ?><?php if ($entry['source'] !== 'manual'): ?> <span class="badge text-bg-light border">From <?= e($entry['source']) ?></span><?php endif ?></div>
            </div>
            <div class="col-md-9">
                <div class="small text-body-secondary">Narration</div>
                <div><?= e($entry['narration']) ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-body-secondary">Reference</div>
                <div><?= e($entry['reference'] ?: '—') ?></div>
            </div>
        </div>
    </div>
    <div class="table-responsive border-top">
        <table class="table mb-0">
            <thead><tr><th>Account</th><th>Description</th><th>Project</th><th>Party</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
            <tbody>
            <?php
            $dr = $cr = 0;
            foreach ($entry['lines'] as $line):
                $dr += decimal_to_cents($line['debit']);
                $cr += decimal_to_cents($line['credit']);
            ?>
                <tr>
                    <td><a href="ledger.php?account=<?= (int)$line['account_id'] ?>" class="text-decoration-none"><span class="acc-code"><?= e($line['code']) ?></span> <?= e($line['account_name']) ?></a></td>
                    <td><?= e($line['description'] ?? '') ?></td>
                    <td><?= $line['project_id'] ? '<a class="text-decoration-none" href="project.php?id=' . (int)$line['project_id'] . '">' . e(project_label((int)$line['project_id'])) . '</a>' : '' ?></td>
                    <td><?= $line['contact_id'] ? '<a class="text-decoration-none" href="contact.php?id=' . (int)$line['contact_id'] . '">' . e(contact_label((int)$line['contact_id'])) . '</a>' : '' ?></td>
                    <td class="num"><?= (float)$line['debit'] ? e(money($line['debit'], false)) : '' ?></td>
                    <td class="num"><?= (float)$line['credit'] ? e(money($line['credit'], false)) : '' ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot>
                <tr class="table-total"><td colspan="4">Total</td><td class="num"><?= e(money_cents($dr)) ?></td><td class="num"><?= e(money_cents($cr)) ?></td></tr>
            </tfoot>
        </table>
    </div>
    <div class="border-top px-4 py-3 small text-body-secondary d-flex flex-wrap gap-4">
        <span>Prepared by <strong><?= e($entry['created_by_name'] ?? '—') ?></strong> · <?= e(date('d M Y, H:i', strtotime($entry['created_at']))) ?></span>
        <?php if ($entry['posted_at']): ?><span>Posted by <strong><?= e($entry['posted_by_name'] ?? '—') ?></strong> · <?= e(date('d M Y, H:i', strtotime($entry['posted_at']))) ?></span><?php endif ?>
    </div>
</div>

<?php if ($canEdit && $entry['status'] === 'posted' && $entry['source'] === 'manual'): ?>
    <form method="post" class="acc-card mt-3 d-print-none" onsubmit="return confirm('Void voucher <?= e($entry['voucher_no']) ?>? It will stop affecting all balances. This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="void">
        <div class="acc-card-body d-flex flex-wrap align-items-end gap-2">
            <div class="flex-grow-1">
                <label class="form-label fw-semibold small" for="void_reason">Made a mistake? Void this voucher</label>
                <input class="form-control form-control-sm" id="void_reason" name="void_reason" required maxlength="255" placeholder="Reason for voiding (required)">
            </div>
            <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-ban me-1"></i> Void voucher</button>
        </div>
    </form>
<?php endif ?>
<?php endif ?>

<?php require __DIR__ . '/includes/layout-bottom.php';
