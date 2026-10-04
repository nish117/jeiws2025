<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$id = (int)($_GET['id'] ?? 0);
$project = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM acc_projects WHERE id = ?');
    $stmt->execute([$id]);
    $project = $stmt->fetch() ?: null;
    if (!$project) { flash('warning', 'Project not found.'); redirect('projects.php'); }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete' && $project) {
        if (project_in_use($id)) {
            $errors[] = 'This project has transactions tagged to it, so it can\'t be deleted. Set its status to Completed or Cancelled instead.';
        } else {
            db()->prepare('DELETE FROM acc_projects WHERE id = ?')->execute([$id]);
            audit('delete_project', 'project', $id, "{$project['code']} {$project['name']}");
            flash('success', "Project {$project['name']} deleted.");
            redirect('projects.php');
        }
    } else {
        $check = validate_project($_POST, $project ? $id : null);
        $errors = $check['errors'];
        if (!$errors) {
            $savedId = save_project($check['values'], (int)$user['id'], $project ? $id : null);
            flash('success', $project ? 'Project updated.' : "Project {$check['values']['code']} created.");
            redirect('project.php?id=' . $savedId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_POST;
} elseif ($project) {
    $f = $project;
    foreach (['contract_value', 'budget'] as $k) $f[$k] = (float)$project[$k] ? $project[$k] : '';
    $f['retention_percent'] = (float)$project['retention_percent'] ? rtrim(rtrim($project['retention_percent'], '0'), '.') : '';
} else {
    $f = ['status' => 'ongoing', 'client_id' => (int)($_GET['client'] ?? 0), 'code' => ''];
}
$clients = array_filter(contact_options(), fn($c) => $c['type'] === 'client' && ($c['is_active'] || (int)$c['id'] === (int)($f['client_id'] ?? 0)));
$siteProjects = site_project_options();
$linkedElsewhere = db()->prepare('SELECT site_project_id FROM acc_projects WHERE site_project_id IS NOT NULL AND id <> ?');
$linkedElsewhere->execute([$id]);
$linkedElsewhere = array_flip($linkedElsewhere->fetchAll(PDO::FETCH_COLUMN));

$pageTitle   = $project ? "Edit {$project['name']}" : 'New project';
$activeNav   = 'projects';
$breadcrumbs = [['label' => 'Projects', 'href' => 'projects.php']];
if ($project) $breadcrumbs[] = ['label' => $project['name'], 'href' => 'project.php?id=' . $id];
$breadcrumbs[] = ['label' => $project ? 'Edit' : 'New'];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<form method="post" class="acc-card" style="max-width:920px" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card-head"><h2>Project details</h2></div>
    <div class="acc-card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label fw-semibold" for="name">Project name <span class="text-danger">*</span></label>
                <input class="form-control" id="name" name="name" value="<?= e($f['name'] ?? '') ?>" required maxlength="150" autofocus placeholder="e.g. Sanepa Residential Building">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="code">Project code</label>
                <input class="form-control" id="code" name="code" value="<?= e($f['code'] ?? '') ?>" maxlength="20" placeholder="Auto: <?= e(next_project_code()) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="client_id">Client</label>
                <select class="form-select" id="client_id" name="client_id">
                    <option value="">— No client yet —</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)($f['client_id'] ?? 0) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <div class="form-text">Not listed? <a href="contact-edit.php?type=client" target="_blank" rel="noopener">Add a client</a> (opens a new tab), then reload.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <?php foreach (PROJECT_STATUSES as $k => $s): ?><option value="<?= $k ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="location">Location</label>
                <input class="form-control" id="location" name="location" value="<?= e($f['location'] ?? '') ?>" maxlength="150" placeholder="e.g. Lalitpur-2">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold" for="contract_value">Contract value (excl. VAT)</label>
                <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', 'Rs.')) ?></span>
                    <input class="form-control text-end" id="contract_value" name="contract_value" value="<?= e((string)($f['contract_value'] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="budget">Cost budget</label>
                <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', 'Rs.')) ?></span>
                    <input class="form-control text-end" id="budget" name="budget" value="<?= e((string)($f['budget'] ?? '')) ?>" inputmode="decimal" placeholder="0.00"></div>
                <div class="form-text" id="marginHint">&nbsp;</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="retention_percent">Retention</label>
                <div class="input-group">
                    <input class="form-control text-end" id="retention_percent" name="retention_percent" value="<?= e((string)($f['retention_percent'] ?? '')) ?>" inputmode="decimal" placeholder="e.g. 5">
                    <span class="input-group-text">%</span></div>
                <div class="form-text">Withheld by the client from each running bill.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold" for="start_date">Start date</label>
                <input type="date" class="form-control" id="start_date" name="start_date" value="<?= e($f['start_date'] ?? '') ?>">
                <div class="acc-bs-hint" data-date-hint="start_date">&nbsp;</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="end_date">Expected completion</label>
                <input type="date" class="form-control" id="end_date" name="end_date" value="<?= e($f['end_date'] ?? '') ?>">
                <div class="acc-bs-hint" data-date-hint="end_date">&nbsp;</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="site_project_id">Linked site-portal project</label>
                <select class="form-select" id="site_project_id" name="site_project_id" <?= $siteProjects ? '' : 'disabled' ?>>
                    <option value="">— Not linked —</option>
                    <?php foreach ($siteProjects as $sid => $sp): if (isset($linkedElsewhere[$sid])) continue; ?>
                        <option value="<?= e($sid) ?>" <?= (string)$sid === (string)($f['site_project_id'] ?? '') ? 'selected' : '' ?>><?= e($sp['title']) ?></option>
                    <?php endforeach ?>
                </select>
                <div class="form-text">Connects labour attendance and materials from the site portal.</div>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="description">Scope / notes</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= e($f['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    <div class="border-top px-4 py-3 d-flex flex-wrap justify-content-between gap-2">
        <div>
            <?php if ($project): ?>
                <button type="submit" name="action" value="delete" class="btn btn-outline-danger" formnovalidate onclick="return confirm('Delete this project? Only possible if nothing is tagged to it.')">Delete</button>
            <?php else: ?>
                <a href="projects.php" class="btn btn-link text-body-secondary">Cancel</a>
            <?php endif ?>
        </div>
        <button type="submit" class="btn btn-primary"><?= $project ? 'Save changes' : 'Create project' ?></button>
    </div>
</form>

<?php
$pageScripts = [acc_url('../site/nepali-date.js')];
$inlineScript = <<<'JS'
(function () {
    document.querySelectorAll('[data-date-hint]').forEach(hint => {
        const input = document.getElementById(hint.dataset.dateHint);
        const show = () => {
            const bs = typeof adToBs === 'function' ? adToBs(input.value) : null;
            hint.textContent = bs ? `B.S. ${formatBsDisplay(bs)} (${NEPALI_MONTHS[bs.month - 1]})` : ' ';
        };
        input.addEventListener('input', show);
        show();
    });
    const num = v => parseFloat(String(v).replace(/[,\s]/g, '')) || 0;
    const cv = document.getElementById('contract_value'), bu = document.getElementById('budget'), hint = document.getElementById('marginHint');
    function margin() {
        const c = num(cv.value), b = num(bu.value);
        hint.textContent = c > 0 && b > 0 ? `Planned margin: ${(((c - b) / c) * 100).toFixed(1)}%` : ' ';
        hint.className = 'form-text ' + (c > 0 && b > c ? 'text-danger' : '');
    }
    cv.addEventListener('input', margin); bu.addEventListener('input', margin); margin();
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
