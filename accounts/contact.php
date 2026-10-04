<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM acc_contacts WHERE id = ?');
$stmt->execute([$id]);
$contact = $stmt->fetch();
if (!$contact) { flash('warning', 'Not found.'); redirect('clients.php'); }
$type = $contact['type'];
$meta = CONTACT_TYPES[$type];
$isClient = $type === 'client';

// Statement: this party's lines on the receivable/payable control accounts.
$controlIds = party_control_account_ids();
$in = implode(',', array_fill(0, count($controlIds), '?'));
$lines = db()->prepare(
    "SELECT e.id AS entry_id, e.voucher_no, e.entry_date, e.narration, l.description, l.debit, l.credit, a.name AS account_name
     FROM acc_journal_lines l
     JOIN acc_journal_entries e ON e.id = l.entry_id
     JOIN acc_accounts a ON a.id = l.account_id
     WHERE e.status = 'posted' AND l.contact_id = ? AND l.account_id IN ({$in})
     ORDER BY e.entry_date, e.posted_at, e.id, l.line_no"
);
$lines->execute([$id, ...$controlIds]);
$statement = [];
$running = 0;
foreach ($lines->fetchAll() as $r) {
    $r['dr'] = decimal_to_cents($r['debit']);
    $r['cr'] = decimal_to_cents($r['credit']);
    $running += $r['dr'] - $r['cr'];
    $r['balance'] = $running;
    $statement[] = $r;
}
$balance = $isClient ? $running : -$running;

// Volume: everything billed to this client / by this supplier = increases on
// their receivable (debits) or payable (credits) control-account lines, incl. VAT.
$volume = 0;
foreach ($statement as $r) $volume += $isClient ? $r['dr'] : $r['cr'];

$projects = [];
if ($isClient) {
    $p = db()->prepare('SELECT id, code, name, status, contract_value FROM acc_projects WHERE client_id = ? ORDER BY name');
    $p->execute([$id]);
    $projects = $p->fetchAll();
}
$drcr = fn(int $raw) => $raw === 0 ? '0.00' : money_cents(abs($raw)) . ($raw > 0 ? ' Dr' : ' Cr');

$pageTitle   = $contact['name'];
$activeNav   = $type . 's';
$breadcrumbs = [['label' => $meta['plural'], 'href' => $meta['page']], ['label' => $contact['name']]];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print statement</button>';
if (can_edit_books($user)) {
    if ($isClient) $pageActions .= '<a class="btn btn-outline-secondary" href="project-edit.php?client=' . $id . '"><i class="fa-solid fa-helmet-safety me-1"></i> New project</a>';
    $pageActions .= '<a class="btn btn-primary" href="contact-edit.php?id=' . $id . '"><i class="fa-solid fa-pen me-1"></i> Edit</a>';
}
require __DIR__ . '/includes/layout-top.php';
?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="acc-card h-100">
            <div class="acc-card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid <?= $meta['icon'] ?>"></i></span>
                    <div>
                        <div class="small text-body-secondary"><?= $isClient ? 'Client' : e(SUPPLIER_TYPES[$contact['supplier_type']] ?? 'Supplier') ?></div>
                        <?php if (!$contact['is_active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif ?>
                    </div>
                </div>
                <dl class="row small mb-0">
                    <dt class="col-5 text-body-secondary fw-normal">PAN / VAT</dt>
                    <dd class="col-7"><?= $contact['pan_number'] ? '<span class="acc-code">' . e($contact['pan_number']) . '</span>' : '—' ?><?= $contact['vat_registered'] ? ' <span class="acc-sys-tag">VAT</span>' : '' ?></dd>
                    <dt class="col-5 text-body-secondary fw-normal">Contact person</dt><dd class="col-7"><?= e($contact['contact_person'] ?: '—') ?></dd>
                    <dt class="col-5 text-body-secondary fw-normal">Phone</dt><dd class="col-7"><?= $contact['phone'] ? '<a href="tel:' . e($contact['phone']) . '">' . e($contact['phone']) . '</a>' : '—' ?></dd>
                    <dt class="col-5 text-body-secondary fw-normal">Email</dt><dd class="col-7 text-break"><?= $contact['email'] ? '<a href="mailto:' . e($contact['email']) . '">' . e($contact['email']) . '</a>' : '—' ?></dd>
                    <dt class="col-5 text-body-secondary fw-normal">Address</dt><dd class="col-7"><?= e($contact['address'] ?: '—') ?></dd>
                    <dt class="col-5 text-body-secondary fw-normal">TDS</dt><dd class="col-7"><?= e(TDS_CATEGORIES[$contact['tds_category']] ?? '—') ?></dd>
                    <?php if ($contact['notes']): ?><dt class="col-5 text-body-secondary fw-normal">Notes</dt><dd class="col-7"><?= nl2br(e($contact['notes'])) ?></dd><?php endif ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="acc-card acc-kpi">
                    <div class="acc-kpi-top"><span class="acc-kpi-label"><?= $isClient ? 'Receivable' : 'Payable' ?></span>
                        <span class="acc-kpi-icon <?= $balance > 0 ? 'acc-tone-gold' : 'acc-tone-green' ?>"><i class="fa-solid fa-hourglass-half"></i></span></div>
                    <div class="acc-kpi-value"><?= e(money_cents($balance, true)) ?></div>
                    <div class="acc-kpi-note"><?= $balance < 0 ? ($isClient ? 'Advance received from client' : 'Advance paid to supplier') : ($isClient ? 'Owed to us' : 'We owe') ?></div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="acc-card acc-kpi">
                    <div class="acc-kpi-top"><span class="acc-kpi-label"><?= $isClient ? 'Total billed' : 'Total purchases' ?></span>
                        <span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid fa-file-invoice"></i></span></div>
                    <div class="acc-kpi-value"><?= e(money_cents($volume, true)) ?></div>
                    <div class="acc-kpi-note">All time, including VAT and retention</div>
                </div>
            </div>
            <?php if ($isClient): ?>
                <div class="col-12">
                    <div class="acc-card">
                        <div class="acc-card-head"><h2>Projects</h2></div>
                        <?php if (!$projects): ?>
                            <div class="acc-card-body small text-body-secondary">No projects for this client yet.<?php if (can_edit_books($user)): ?> <a href="project-edit.php?client=<?= $id ?>">Create one</a>.<?php endif ?></div>
                        <?php else: ?>
                            <div class="table-responsive"><table class="table table-hover mb-0 align-middle"><tbody>
                                <?php foreach ($projects as $p): ?>
                                    <tr style="cursor:pointer" onclick="location.href='project.php?id=<?= (int)$p['id'] ?>'">
                                        <td><a class="text-decoration-none fw-semibold" href="project.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><div class="small acc-code"><?= e($p['code']) ?></div></td>
                                        <td><?= project_status_badge($p['status']) ?></td>
                                        <td class="num"><?= e(money($p['contract_value'])) ?></td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody></table></div>
                        <?php endif ?>
                    </div>
                </div>
            <?php endif ?>
        </div>
    </div>
</div>

<div class="acc-card">
    <div class="acc-card-head"><h2>Statement of account</h2><span class="small text-body-secondary">Posted entries</span></div>
    <?php if (!$statement): ?>
        <div class="acc-card-body text-body-secondary small">
            No transactions yet. When you record a voucher, choose <strong><?= e($contact['name']) ?></strong> in the <em>Party</em> column on the
            <?= $isClient ? 'Accounts Receivable' : 'Accounts Payable' ?> line — it will show up here.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
                <tbody>
                <?php foreach ($statement as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(bs_date($r['entry_date'])) ?><div class="small text-body-secondary"><?= e(date('d M Y', strtotime($r['entry_date']))) ?></div></td>
                        <td class="text-nowrap"><a class="text-decoration-none" href="journal-entry.php?id=<?= (int)$r['entry_id'] ?>"><?= e($r['voucher_no']) ?></a></td>
                        <td><?= e($r['narration']) ?><div class="small text-body-secondary"><?= e($r['account_name']) ?><?= $r['description'] ? ' · ' . e($r['description']) : '' ?></div></td>
                        <td class="num"><?= $r['dr'] ? e(money_cents($r['dr'])) : '' ?></td>
                        <td class="num"><?= $r['cr'] ? e(money_cents($r['cr'])) : '' ?></td>
                        <td class="num"><?= e($drcr($r['balance'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot><tr class="table-total"><td colspan="5">Closing balance</td><td class="num"><?= e($drcr($running)) ?></td></tr></tfoot>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
