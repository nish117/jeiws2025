<?php
defined('ACC_LOADED') or die('Direct access denied.');

/** List page for clients or suppliers. Set $contactType ('client'|'supplier') before including. */
$user = require_login();
$meta = CONTACT_TYPES[$contactType];
$isClient = $contactType === 'client';

$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'active');

$where = ['c.type = ?'];
$params = [$contactType];
if ($status === 'active')   $where[] = 'c.is_active = 1';
if ($status === 'inactive') $where[] = 'c.is_active = 0';
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.contact_person LIKE ? OR c.pan_number LIKE ? OR c.phone LIKE ?)';
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
}
$stmt = db()->prepare(
    'SELECT c.*, (SELECT COUNT(*) FROM acc_projects p WHERE p.client_id = c.id) AS project_count
     FROM acc_contacts c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.name'
);
$stmt->execute($params);
$contacts = $stmt->fetchAll();
$balances = contact_balances(array_map(fn($c) => (int)$c['id'], $contacts));
$totalOutstanding = 0;
foreach ($balances as $b) $totalOutstanding += $isClient ? $b : -$b;

$pageTitle   = $meta['plural'];
$activeNav   = $contactType . 's';
$breadcrumbs = [['label' => $meta['plural']]];
$pageActions = can_edit_books($user)
    ? '<a href="contact-edit.php?type=' . $contactType . '" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New ' . strtolower($meta['singular']) . '</a>'
    : '';
require __DIR__ . '/layout-top.php';
?>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <div class="flex-grow-1" style="max-width:340px">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, contact person, PAN or phone">
        </div>
        <div>
            <label class="form-label" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
        <?php if ($contacts): ?>
            <div class="ms-auto text-end">
                <div class="form-label mb-0"><?= $isClient ? 'Total receivable' : 'Total payable' ?></div>
                <div class="fw-bold"><?= e(money_cents($totalOutstanding, true)) ?></div>
            </div>
        <?php endif ?>
    </form>

    <?php if (!$contacts): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid <?= $meta['icon'] ?>"></i></div>
            <h3><?= $q || $status !== 'active' ? 'No ' . strtolower($meta['plural']) . ' match' : 'No ' . strtolower($meta['plural']) . ' yet' ?></h3>
            <p><?= $isClient
                ? 'Add the people and organisations you build for. Their PAN is printed on tax invoices, and their outstanding balance is tracked here.'
                : 'Add material suppliers, subcontractors and service providers. Their PAN and TDS category are used when you record bills and payments.' ?></p>
            <?php if (can_edit_books($user) && !$q): ?>
                <a href="contact-edit.php?type=<?= $contactType ?>" class="btn btn-primary mt-3"><i class="fa-solid fa-plus me-1"></i> Add your first <?= strtolower($meta['singular']) ?></a>
            <?php endif ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Name</th><th>PAN / VAT</th><th class="d-none d-md-table-cell">Contact</th>
                    <th class="d-none d-lg-table-cell"><?= $isClient ? 'Projects' : 'Type' ?></th>
                    <th class="num"><?= $isClient ? 'Receivable' : 'Payable' ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($contacts as $c): $bal = $balances[(int)$c['id']] ?? 0; $shown = $isClient ? $bal : -$bal; ?>
                    <tr class="<?= $c['is_active'] ? '' : 'acc-inactive' ?>" style="cursor:pointer" onclick="location.href='contact.php?id=<?= (int)$c['id'] ?>'">
                        <td>
                            <a href="contact.php?id=<?= (int)$c['id'] ?>" class="fw-semibold text-decoration-none"><?= e($c['name']) ?></a>
                            <?php if (!$c['is_active']): ?> <span class="badge text-bg-secondary">Inactive</span><?php endif ?>
                            <?php if ($c['contact_person']): ?><div class="small text-body-secondary"><?= e($c['contact_person']) ?></div><?php endif ?>
                        </td>
                        <td class="text-nowrap">
                            <?= $c['pan_number'] ? '<span class="acc-code">' . e($c['pan_number']) . '</span>' : '<span class="text-body-tertiary">—</span>' ?>
                            <?php if ($c['vat_registered']): ?><span class="acc-sys-tag ms-1">VAT</span><?php endif ?>
                        </td>
                        <td class="d-none d-md-table-cell small">
                            <?= e($c['phone'] ?? '') ?><?php if ($c['email']): ?><div class="text-body-secondary"><?= e($c['email']) ?></div><?php endif ?>
                        </td>
                        <td class="d-none d-lg-table-cell small">
                            <?= $isClient ? ((int)$c['project_count'] ?: '<span class="text-body-tertiary">—</span>') : e(SUPPLIER_TYPES[$c['supplier_type']] ?? '—') ?>
                        </td>
                        <td class="num <?= $shown < 0 ? 'text-success' : '' ?>">
                            <?= $shown ? e(money_cents($shown)) : '<span class="text-body-tertiary">—</span>' ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top small text-body-secondary"><?= count($contacts) ?> <?= strtolower(count($contacts) === 1 ? $meta['singular'] : $meta['plural']) ?>. Negative balances (green) are advances.</div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/layout-bottom.php';
