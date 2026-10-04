<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_site') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    $imported = 0;
    foreach (unlinked_site_projects() as $siteId => $sp) {
        $check = validate_project(['name' => $sp['title'], 'site_project_id' => $siteId, 'status' => $sp['is_active'] ? 'ongoing' : 'completed']);
        if (!$check['errors']) { save_project($check['values'], (int)$user['id']); $imported++; }
    }
    flash('success', $imported ? "Imported {$imported} project(s) from the site portal. Add each one's client, contract value and budget." : 'Nothing to import.');
    redirect('projects.php');
}

$status = (string)($_GET['status'] ?? 'open');
$q = trim((string)($_GET['q'] ?? ''));
$where = ['1=1'];
$params = [];
if ($status === 'open') $where[] = "p.status IN ('planned','ongoing','on_hold')";
elseif (isset(PROJECT_STATUSES[$status])) { $where[] = 'p.status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.location LIKE ? OR c.name LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"); }
$stmt = db()->prepare(
    "SELECT p.*, c.name AS client_name FROM acc_projects p LEFT JOIN acc_contacts c ON c.id = p.client_id
     WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(p.status,'ongoing','planned','on_hold','completed','cancelled'), p.name"
);
$stmt->execute($params);
$projects = $stmt->fetchAll();
$fin = project_financials();
$unlinked = $canEdit ? unlinked_site_projects() : [];

$pageTitle   = 'Projects';
$activeNav   = 'projects';
$breadcrumbs = [['label' => 'Projects']];
$pageActions = $canEdit ? '<a href="project-edit.php" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New project</a>' : '';
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($unlinked): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="fa-solid fa-link me-1"></i>
            <?= count($unlinked) ?> project<?= count($unlinked) > 1 ? 's' : '' ?> from the site portal (<?= e(implode(', ', array_slice(array_column($unlinked, 'title'), 0, 3))) ?><?= count($unlinked) > 3 ? '…' : '' ?>) <?= count($unlinked) > 1 ? 'are' : 'is' ?> not in accounts yet.</span>
        <form method="post"><?= csrf_field() ?><button name="action" value="import_site" class="btn btn-sm btn-primary">Import <?= count($unlinked) > 1 ? 'them' : 'it' ?></button></form>
    </div>
<?php endif ?>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <div class="flex-grow-1" style="max-width:340px">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Project, code, location or client">
        </div>
        <div>
            <label class="form-label" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open (planned, ongoing, on hold)</option>
                <?php foreach (PROJECT_STATUSES as $k => $s): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach ?>
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
            </select>
        </div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>

    <?php if (!$projects): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid fa-helmet-safety"></i></div>
            <h3><?= $q || $status !== 'open' ? 'No projects match' : 'No projects yet' ?></h3>
            <p>Each project is a cost centre: tag income and expenses to it and see revenue, cost, profit and budget use in one place.</p>
            <?php if ($canEdit && !$q): ?><a href="project-edit.php" class="btn btn-primary mt-3"><i class="fa-solid fa-plus me-1"></i> Create a project</a><?php endif ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Project</th><th class="d-none d-md-table-cell">Client</th><th>Status</th>
                    <th class="num d-none d-lg-table-cell">Contract value</th><th class="num">Revenue</th><th class="num">Cost</th><th class="num">Profit</th>
                    <th class="d-none d-xl-table-cell" style="width:140px">Budget used</th>
                </tr></thead>
                <tbody>
                <?php foreach ($projects as $p):
                    $f = $fin[(int)$p['id']] ?? ['revenue' => 0, 'cost' => 0];
                    $profit = $f['revenue'] - $f['cost'];
                    $budget = decimal_to_cents($p['budget']);
                    $used = $budget > 0 ? $f['cost'] / $budget * 100 : null;
                ?>
                    <tr style="cursor:pointer" onclick="location.href='project.php?id=<?= (int)$p['id'] ?>'">
                        <td>
                            <a class="fw-semibold text-decoration-none" href="project.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a>
                            <div class="small text-body-secondary"><span class="acc-code"><?= e($p['code']) ?></span><?= $p['location'] ? ' · ' . e($p['location']) : '' ?></div>
                        </td>
                        <td class="d-none d-md-table-cell small"><?= e($p['client_name'] ?? '—') ?></td>
                        <td><?= project_status_badge($p['status']) ?></td>
                        <td class="num d-none d-lg-table-cell"><?= (float)$p['contract_value'] ? e(money($p['contract_value'], false)) : '<span class="text-body-tertiary">—</span>' ?></td>
                        <td class="num"><?= e(money_cents($f['revenue'])) ?></td>
                        <td class="num"><?= e(money_cents($f['cost'])) ?></td>
                        <td class="num fw-semibold <?= $profit < 0 ? 'text-danger' : ($profit > 0 ? 'text-success' : '') ?>"><?= e(money_cents($profit)) ?></td>
                        <td class="d-none d-xl-table-cell">
                            <?php if ($used === null): ?><span class="small text-body-tertiary">No budget</span>
                            <?php else: ?>
                                <div class="progress" style="height:6px"><div class="progress-bar <?= $used > 100 ? 'bg-danger' : ($used > 85 ? 'bg-warning' : '') ?>" style="width:<?= min(100, round($used)) ?>%"></div></div>
                                <div class="small text-body-secondary mt-1"><?= number_format($used, 0) ?>%</div>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
<p class="small text-body-secondary mt-2">Revenue and cost come from posted vouchers whose lines are tagged to the project (income and expense accounts, excluding VAT).</p>

<?php require __DIR__ . '/includes/layout-bottom.php';
