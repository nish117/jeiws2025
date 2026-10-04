<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

$type = ($_GET['type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
$meta = INVOICE_TYPES[$type];
$status = (string)($_GET['status'] ?? 'open');
$q = trim((string)($_GET['q'] ?? ''));

$where = ['i.type = ?'];
$params = [$type];
switch ($status) {
    case 'open':    $where[] = "i.status IN ('draft','posted')"; break;
    case 'overdue': $where[] = "i.status = 'posted' AND i.due_date < CURDATE()"; break;
    case 'all':     break;
    default:
        if (in_array($status, ['draft', 'posted', 'paid', 'void'], true)) { $where[] = 'i.status = ?'; $params[] = $status; }
}
if ($q !== '') { $where[] = '(i.number LIKE ? OR c.name LIKE ? OR p.name LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%"); }
$stmt = db()->prepare(
    "SELECT i.*, c.name AS contact_name, p.code AS project_code,
            (SELECT COUNT(*) FROM acc_attachments a WHERE a.entity = 'invoice' AND a.entity_id = i.id) AS files
     FROM acc_invoices i JOIN acc_contacts c ON c.id = i.contact_id LEFT JOIN acc_projects p ON p.id = i.project_id
     WHERE " . implode(' AND ', $where) . " ORDER BY (i.status = 'draft') DESC, i.invoice_date DESC, i.id DESC LIMIT 300"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sum = db()->prepare("SELECT COALESCE(SUM(net_amount - amount_paid), 0) AS outstanding,
        COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN net_amount - amount_paid END), 0) AS overdue
    FROM acc_invoices WHERE type = ? AND status = 'posted'");
$sum->execute([$type]);
$totals = $sum->fetch();

$pageTitle   = $meta['plural'];
$activeNav   = $meta['nav'];
$breadcrumbs = [['label' => 'Invoices', 'href' => 'invoices.php'], ['label' => $meta['plural']]];
$pageActions = $canEdit
    ? '<a href="invoice-edit.php?type=' . $type . '&photo=1" class="btn btn-outline-primary"><i class="fa-solid fa-camera me-1"></i> From photo</a>'
    . '<a href="invoice-edit.php?type=' . $type . '" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New ' . strtolower($meta['label']) . '</a>'
    : '';
require __DIR__ . '/includes/layout-top.php';
?>

<nav class="acc-subnav" aria-label="Invoice types">
    <?php foreach (INVOICE_TYPES as $t => $m): ?>
        <a href="invoices.php?type=<?= $t ?>" class="<?= $t === $type ? 'active' : '' ?>"><i class="fa-solid <?= $m['icon'] ?>"></i> <?= e($m['plural']) ?></a>
    <?php endforeach ?>
</nav>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label"><?= $type === 'sales' ? 'Receivable' : 'Payable' ?></span><span class="acc-kpi-icon acc-tone-gold"><i class="fa-solid fa-hourglass-half"></i></span></div>
            <div class="acc-kpi-value"><?= e(money($totals['outstanding'])) ?></div>
            <div class="acc-kpi-note">Unpaid posted <?= strtolower($meta['plural']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a class="acc-card acc-kpi text-decoration-none text-body" href="invoices.php?type=<?= $type ?>&status=overdue">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Overdue</span><span class="acc-kpi-icon acc-tone-red"><i class="fa-solid fa-triangle-exclamation"></i></span></div>
            <div class="acc-kpi-value"><?= e(money($totals['overdue'])) ?></div>
            <div class="acc-kpi-note">Past the due date</div>
        </a>
    </div>
</div>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <input type="hidden" name="type" value="<?= $type ?>">
        <div class="flex-grow-1" style="max-width:340px">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Number, <?= $meta['contact'] ?> or project">
        </div>
        <div>
            <label class="form-label" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                <?php foreach (['open' => 'Open (draft + unpaid)', 'draft' => 'Drafts', 'posted' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void', 'all' => 'All'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>

    <?php if (!$rows): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid <?= $meta['icon'] ?>"></i></div>
            <h3>No <?= strtolower($meta['plural']) ?><?= $q || $status !== 'open' ? ' match' : ' yet' ?></h3>
            <p><?= $type === 'sales'
                ? 'Bill clients with 13% VAT, retention and TDS. Snap a photo of a bill-book invoice and type the figures beside it, or create one here.'
                : 'Record supplier and subcontractor bills against projects. Take a photo of the bill and enter the figures while looking at it.' ?></p>
            <?php if ($canEdit && !$q): ?>
                <div class="d-flex gap-2 justify-content-center mt-3">
                    <a href="invoice-edit.php?type=<?= $type ?>&photo=1" class="btn btn-outline-primary"><i class="fa-solid fa-camera me-1"></i> From photo</a>
                    <a href="invoice-edit.php?type=<?= $type ?>" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New <?= strtolower($meta['label']) ?></a>
                </div>
            <?php endif ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Number</th><th>Date</th><th><?= ucfirst($meta['contact']) ?></th><th class="d-none d-lg-table-cell">Project</th>
                    <th class="num">Total</th><th class="num d-none d-md-table-cell">Outstanding</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $out = decimal_to_cents($r['net_amount']) - decimal_to_cents($r['amount_paid']); ?>
                    <tr class="<?= $r['status'] === 'void' ? 'acc-inactive' : '' ?>" style="cursor:pointer" onclick="location.href='invoice.php?id=<?= (int)$r['id'] ?>'">
                        <td class="text-nowrap">
                            <a class="fw-semibold text-decoration-none" href="invoice.php?id=<?= (int)$r['id'] ?>"><?= e($r['number'] ?? 'Draft #' . $r['id']) ?></a>
                            <?php if ($r['files']): ?><i class="fa-solid fa-paperclip text-body-tertiary ms-1" title="<?= (int)$r['files'] ?> attachment(s)"></i><?php endif ?>
                        </td>
                        <td class="text-nowrap"><?= e(bs_date($r['invoice_date'])) ?><div class="small text-body-secondary"><?= e(date('d M Y', strtotime($r['invoice_date']))) ?></div></td>
                        <td><?= e($r['contact_name']) ?></td>
                        <td class="d-none d-lg-table-cell small"><?= e($r['project_code'] ?? '—') ?></td>
                        <td class="num"><?= e(money($r['total_amount'], false)) ?></td>
                        <td class="num d-none d-md-table-cell"><?= in_array($r['status'], ['posted'], true) ? e(money_cents($out)) : '<span class="text-body-tertiary">—</span>' ?></td>
                        <td><?= invoice_status_badge($r) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
