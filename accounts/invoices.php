<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

$type = ($_GET['type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
$meta = INVOICE_TYPES[$type];
$status = (string)($_GET['status'] ?? 'open');
$q = trim((string)($_GET['q'] ?? ''));

// Fiscal years (Shrawan–Ashadh) from the oldest bill up to now; always offers this year and last.
$currentFy = fiscal_year_for(date('Y-m-d'));
$oldest = db()->prepare('SELECT MIN(invoice_date) FROM acc_invoices WHERE type = ?');
$oldest->execute([$type]);
$firstStart = (int)substr(($oldestDate = $oldest->fetchColumn()) ? fiscal_year_for($oldestDate) : $currentFy, 0, 4);
$fiscalYears = [];
for ($y = (int)substr($currentFy, 0, 4); $y >= min($firstStart, (int)substr($currentFy, 0, 4) - 1); $y--) {
    $fiscalYears[] = sprintf('%d/%02d', $y, ($y + 1) % 100);
}
$fy = (string)($_GET['fy'] ?? 'all');
if ($fy !== 'all' && !in_array($fy, $fiscalYears, true)) $fy = 'all';
$fyRange = $fy === 'all' ? null : fiscal_year_range($fy);

$where = ['i.type = ?'];
$params = [$type];
switch ($status) {
    case 'open':    $where[] = "i.status IN ('draft','posted')"; break;
    case 'overdue': $where[] = "i.status = 'posted' AND i.due_date < CURDATE()"; break;
    case 'all':     break;
    default:
        if (in_array($status, ['draft', 'posted', 'paid', 'void'], true)) { $where[] = 'i.status = ?'; $params[] = $status; }
}
if ($fyRange) { $where[] = 'i.invoice_date BETWEEN ? AND ?'; array_push($params, ...$fyRange); }
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
if ($fyRange) {
    $yearSum = db()->prepare("SELECT COUNT(*) AS bills, COALESCE(SUM(total_amount), 0) AS total, COALESCE(SUM(vat_amount), 0) AS vat
        FROM acc_invoices WHERE type = ? AND status <> 'void' AND invoice_date BETWEEN ? AND ?");
    $yearSum->execute([$type, ...$fyRange]);
    $yearTotals = $yearSum->fetch();
}
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
        <a href="invoices.php?type=<?= $t ?><?= $fy !== 'all' ? '&amp;fy=' . e(urlencode($fy)) : '' ?>" class="<?= $t === $type ? 'active' : '' ?>"><i class="fa-solid <?= $m['icon'] ?>"></i> <?= e($m['plural']) ?></a>
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
    <?php if ($fyRange): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="acc-card acc-kpi">
                <div class="acc-kpi-top"><span class="acc-kpi-label">FY <?= e($fy) ?> total</span><span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid fa-calendar-days"></i></span></div>
                <div class="acc-kpi-value"><?= e(money($yearTotals['total'])) ?></div>
                <div class="acc-kpi-note"><?= (int)$yearTotals['bills'] ?> <?= strtolower((int)$yearTotals['bills'] === 1 ? $meta['label'] : $meta['plural']) ?> incl. drafts<?= (float)$yearTotals['vat'] ? ' · VAT ' . e(money($yearTotals['vat'], false)) : '' ?></div>
            </div>
        </div>
    <?php endif ?>
</div>

<div class="acc-card">
    <?php
    $dash = '<span class="text-body-tertiary">—</span>';
    $amt = fn(string $col) => fn($r) => (float)$r[$col] ? e(money($r[$col], false)) : $dash;
    $outOf = fn($r) => $r['status'] === 'posted' ? decimal_to_cents($r['net_amount']) - decimal_to_cents($r['amount_paid']) : 0;
    // key => [label, extra cell classes, shown by default, cell renderer, footer total (cents) or null]
    // Shown-by-default columns keep their responsive classes (hidden on small screens) until the user picks otherwise.
    $columns = [
        'date'        => ['Date', 'text-nowrap', true, fn($r) => e(bs_date($r['invoice_date'])) . '<div class="small text-body-secondary">' . e(date('d M Y', strtotime($r['invoice_date']))) . '</div>', null],
        'due'         => ['Due date', 'text-nowrap', false, fn($r) => $r['due_date'] ? e(bs_date($r['due_date'])) : $dash, null],
        'contact'     => [ucfirst($meta['contact']), '', true, fn($r) => e($r['contact_name']), null],
        'project'     => ['Project', 'd-none d-lg-table-cell small', true, fn($r) => e($r['project_code'] ?? '—'), null],
        'taxable'     => ['Taxable', 'num d-none d-md-table-cell', true, $amt('taxable_amount'), 'taxable_amount'],
        'exempt'      => ['Non-taxable', 'num', false, $amt('exempt_amount'), 'exempt_amount'],
        'vat'         => ['VAT', 'num d-none d-md-table-cell', true, $amt('vat_amount'), 'vat_amount'],
        'total'       => ['Total', 'num', true, fn($r) => e(money($r['total_amount'], false)), 'total_amount'],
        'retention'   => ['Retention', 'num', false, $amt('retention_amount'), 'retention_amount'],
        'tds'         => ['TDS', 'num', false, $amt('tds_amount'), 'tds_amount'],
        'paid'        => [$meta['paid_label'], 'num', false, $amt('amount_paid'), 'amount_paid'],
        'outstanding' => ['Outstanding', 'num d-none d-md-table-cell', true, fn($r) => $r['status'] === 'posted' ? e(money_cents($outOf($r))) : $dash, 'outstanding'],
        'status'      => ['Status', '', true, fn($r) => invoice_status_badge($r), null],
    ];
    $leadCols = ['number', 'date', 'due', 'contact', 'project'];   // the footer's "Total" label spans these
    $cellAttr = fn(string $key, array $c) => ' data-col="' . $key . '" class="' . trim($c[1] . ($c[2] ? '' : ' acc-col-hidden')) . '"';
    $live = array_filter($rows, fn($r) => $r['status'] !== 'void');
    $sumOf = fn(string $col) => array_sum(array_map(fn($r) => $col === 'outstanding' ? $outOf($r) : decimal_to_cents($r[$col]), $live));
    ?>
    <form class="acc-filters" method="get">
        <input type="hidden" name="type" value="<?= $type ?>">
        <div class="flex-grow-1" style="max-width:340px">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Number, <?= $meta['contact'] ?> or project">
        </div>
        <div>
            <label class="form-label" for="fy">Fiscal year</label>
            <select class="form-select form-select-sm" id="fy" name="fy" onchange="this.form.submit()">
                <option value="all" <?= $fy === 'all' ? 'selected' : '' ?>>All years</option>
                <?php foreach ($fiscalYears as $year): ?>
                    <option value="<?= e($year) ?>" <?= $year === $fy ? 'selected' : '' ?>>FY <?= e($year) ?><?= $year === $currentFy ? ' (current)' : '' ?></option>
                <?php endforeach ?>
            </select>
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
        <?php if ($rows): ?>
            <div class="dropdown ms-auto" data-col-picker="invoices-<?= $type ?>">
                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                    <i class="fa-solid fa-table-columns me-1"></i> Columns
                </button>
                <div class="dropdown-menu dropdown-menu-end acc-col-menu">
                    <div class="dropdown-header">Show columns</div>
                    <div class="acc-col-grid">
                    <?php foreach ($columns as $key => $c): ?>
                        <label class="dropdown-item d-flex align-items-center gap-2">
                            <input class="form-check-input m-0" type="checkbox" data-col-toggle="<?= $key ?>"> <?= e($c[0]) ?>
                        </label>
                    <?php endforeach ?>
                    </div>
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item small text-body-secondary" data-col-reset><i class="fa-solid fa-rotate-left me-1"></i> Reset to default</button>
                </div>
            </div>
        <?php endif ?>
    </form>

    <?php if (!$rows): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid <?= $meta['icon'] ?>"></i></div>
            <h3>No <?= strtolower($meta['plural']) ?><?= $q || $status !== 'open' || $fyRange ? ' match' : ' yet' ?><?= $fyRange ? ' in FY ' . e($fy) : '' ?></h3>
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
            <table class="table table-hover align-middle mb-0" data-col-table="invoices-<?= $type ?>">
                <thead><tr>
                    <th data-col="number">Number</th>
                    <?php foreach ($columns as $key => $c): ?><th<?= $cellAttr($key, $c) ?>><?= e($c[0]) ?></th><?php endforeach ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['status'] === 'void' ? 'acc-inactive' : '' ?>" style="cursor:pointer" onclick="location.href='invoice.php?id=<?= (int)$r['id'] ?>'" data-peek="invoice-peek.php?id=<?= (int)$r['id'] ?>">
                        <td class="text-nowrap" data-col="number">
                            <a class="fw-semibold text-decoration-none" href="invoice.php?id=<?= (int)$r['id'] ?>"><?= e($r['number'] ?? 'Draft #' . $r['id']) ?></a>
                            <?php if ($r['files']): ?><i class="fa-solid fa-paperclip text-body-tertiary ms-1" title="<?= (int)$r['files'] ?> attachment(s)"></i><?php endif ?>
                        </td>
                        <?php foreach ($columns as $key => $c): ?><td<?= $cellAttr($key, $c) ?>><?= $c[3]($r) ?></td><?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <?php if (count($rows) > 1): ?>
                    <tfoot><tr class="table-total">
                        <td colspan="3" data-col-span="<?= implode(' ', $leadCols) ?>">Total · <?= count($live) ?> <?= strtolower(count($live) === 1 ? $meta['label'] : $meta['plural']) ?><?= count($live) < count($rows) ? ' <span class="fw-normal small text-body-secondary">(void excluded)</span>' : '' ?></td>
                        <?php foreach ($columns as $key => $c): if (in_array($key, $leadCols, true)) continue; ?>
                            <td<?= $cellAttr($key, $c) ?>><?= $c[4] ? e(money_cents($sumOf($c[4]))) : '' ?></td>
                        <?php endforeach ?>
                    </tr></tfoot>
                <?php endif ?>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
