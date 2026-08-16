<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/functions.php';
$csrf = csrfToken();

// Check the database connection explicitly so a bad config/db.php shows a
// clear message here instead of a raw PHP fatal error.
$dbError = null;
$workers = [];
$projects = [];
try {
    db()->query('SELECT 1');
    ensureWorkersDeletedColumn();
    ensureWorkerProjectsTable();
    // attendance_count is global (across every project, not just one) —
    // shown just for information now, since deleting a worker is a
    // soft-delete that keeps their attendance history intact.
    $workers = db()->query(
        "SELECT w.id, w.full_name, w.category, w.daily_wage, w.is_active, COUNT(DISTINCT la.id) AS attendance_count,
                GROUP_CONCAT(DISTINCT p.title ORDER BY p.title SEPARATOR ', ') AS project_names,
                GROUP_CONCAT(DISTINCT wp.project_id) AS project_ids
         FROM workers w
         LEFT JOIN labour_attendance la ON la.worker_id = w.id
         LEFT JOIN worker_projects wp ON wp.worker_id = w.id
         LEFT JOIN projects p ON p.id = wp.project_id
         WHERE w.is_deleted = 0
         GROUP BY w.id, w.full_name, w.category, w.daily_wage, w.is_active
         ORDER BY w.full_name"
    )->fetchAll();
    $projects = db()->query('SELECT id, title FROM projects ORDER BY title')->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Worker Roster — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">
    JEIWS <span>CMS</span>
  </a>
  <input type="checkbox" id="navToggle" class="nav-toggle">
  <label for="navToggle" class="nav-toggle-btn"><i class="fa-solid fa-bars"></i></label>
  <div class="cms-nav-right">
    <a href="index.php">Projects</a>
    <a href="analytics.php">Analytics</a>
    <a href="users.php">Site Users</a>
    <a href="materials.php">Materials</a>
    <a href="stock_log.php">Stock Log</a>
    <a href="attendance_log.php">Attendance</a>
    <a href="workers.php" class="active">Workers</a>
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <div>
      <h1>Worker Roster</h1>
      <p>Labourers available for site users to mark attendance against — only admins can add, hide, or delete workers</p>
    </div>
  </div>

  <?php if ($dbError !== null): ?>
  <div class="alert alert-err" style="align-items:flex-start">
    <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i>
    <div>
      <strong>Database connection failed.</strong> Check the host/dbname/username/password in <code>config/db.php</code>.
      <div style="margin-top:6px;font-family:monospace;font-size:12px;opacity:.85"><?= htmlspecialchars($dbError) ?></div>
    </div>
  </div>
  <?php else: ?>

  <div class="card">
    <div class="card-title">Add Worker</div>
    <form class="inline-add-form" onsubmit="return addWorker(event)">
      <div class="form-group">
        <label>Full name</label>
        <input type="text" id="new-worker-name" required placeholder="e.g. Ram Bahadur">
      </div>
      <div class="form-group">
        <label>Category</label>
        <input type="text" id="new-worker-category" placeholder="e.g. Mason">
      </div>
      <div class="form-group">
        <label>Daily wage</label>
        <input type="text" id="new-worker-wage" placeholder="Optional">
      </div>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add</button>
    </form>
    <?php if (!empty($projects)): ?>
    <div class="form-group" style="margin-top:12px">
      <label>Assign to project(s) <small style="font-weight:400;color:var(--muted)">— a worker only appears in a project's attendance roster once assigned</small></label>
      <div id="new-worker-projects" style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:6px">
        <?php foreach ($projects as $p): ?>
        <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px">
          <input type="checkbox" value="<?= htmlspecialchars($p['id']) ?>"> <?= htmlspecialchars($p['title']) ?>
        </label>
        <?php endforeach ?>
      </div>
    </div>
    <?php endif ?>
  </div>

  <div class="card">
    <div class="card-title">All Workers <small><?= count($workers) ?> total</small></div>
    <?php if (empty($workers)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fa-regular fa-user"></i></div>
      <h3>No workers yet</h3>
      <p>Add your first worker above.</p>
    </div>
    <?php else: ?>
    <div class="materials-table-wrap">
      <div class="materials-table" style="grid-template-columns:2fr 1.6fr 90px 90px 130px">
        <div class="materials-table-head">
          <span>Worker</span>
          <span>Projects</span>
          <span>Daily Wage</span>
          <span>Status</span>
          <span></span>
        </div>
        <?php foreach ($workers as $w): ?>
        <div class="materials-table-row<?= $w['is_active'] ? '' : ' is-hidden' ?>" id="worker-<?= $w['id'] ?>">
          <span class="mt-name-cell">
            <span class="mt-name"><?= htmlspecialchars($w['full_name']) ?></span>
            <span class="mt-unit"><?= htmlspecialchars($w['category'] ?: '—') ?><?php if ($w['attendance_count'] > 0): ?> · <?= $w['attendance_count'] ?> attendance record<?= $w['attendance_count'] == 1 ? '' : 's' ?><?php endif ?></span>
          </span>
          <span id="projects-<?= $w['id'] ?>" style="<?= $w['project_names'] ? '' : 'color:var(--warn)' ?>"><?= htmlspecialchars($w['project_names'] ?: 'None — won\'t appear in any roster') ?></span>
          <span><?= $w['daily_wage'] !== null ? 'Rs. ' . htmlspecialchars($w['daily_wage']) : '—' ?></span>
          <span>
            <span class="toggle-status <?= $w['is_active'] ? 'published' : 'draft' ?>" id="status-<?= $w['id'] ?>">
              <?= $w['is_active'] ? 'Active' : 'Hidden' ?>
            </span>
          </span>
          <span class="mt-actions">
            <button class="btn btn-ghost btn-sm" title="Edit assigned projects"
                    onclick="openProjectsEditor(<?= $w['id'] ?>, <?= json_encode($w['full_name']) ?>, <?= json_encode($w['project_ids'] ? explode(',', $w['project_ids']) : []) ?>)">
              <i class="fa-solid fa-diagram-project"></i>
            </button>
            <button class="btn btn-ghost btn-sm" title="<?= $w['is_active'] ? 'Hide from attendance list' : 'Unhide' ?>" onclick="toggleWorker(<?= $w['id'] ?>, this)">
              <i class="fa-solid fa-eye<?= $w['is_active'] ? '-slash' : '' ?>"></i>
            </button>
            <button class="btn btn-ghost btn-sm mt-delete"
                    data-id="<?= $w['id'] ?>" data-name="<?= htmlspecialchars($w['full_name']) ?>"
                    title="Delete" onclick="confirmDeleteWorker(this.dataset.id, this.dataset.name)">
              <i class="fa-solid fa-trash"></i>
            </button>
          </span>
        </div>
        <?php endforeach ?>
      </div>
    </div>
    <?php endif ?>
  </div>
  <?php endif ?>
</main>

<div class="toasts" id="toasts"></div>

<div class="mask" id="mask" style="display:none">
  <div class="confirm-box">
    <h3>Delete Worker</h3>
    <p id="confirm-msg"></p>
    <div class="confirm-actions">
      <button class="btn btn-ghost btn-sm" onclick="closeMask()">Cancel</button>
      <button class="btn btn-danger btn-sm" id="confirm-ok">Delete</button>
    </div>
  </div>
</div>

<div class="mask" id="projects-mask" style="display:none">
  <div class="confirm-box">
    <h3 id="projects-editor-title">Assigned Projects</h3>
    <div id="projects-editor-list" style="display:flex;flex-direction:column;gap:8px;margin:12px 0;text-align:left"></div>
    <div class="confirm-actions">
      <button class="btn btn-ghost btn-sm" onclick="closeProjectsEditor()">Cancel</button>
      <button class="btn btn-primary btn-sm" id="projects-editor-save">Save</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const ALL_PROJECTS = <?= json_encode(array_map(fn($p) => ['id' => $p['id'], 'title' => $p['title']], $projects)) ?>;

async function post(data) {
  const body = new URLSearchParams({ ...data, csrf_token: CSRF });
  const res  = await fetch('api.php', { method: 'POST', body });
  return res.json();
}

// URLSearchParams can't build repeated project_ids[] keys from a plain
// object (it stringifies arrays as "a,b" instead), so this appends them
// as separate entries the way PHP expects for an array field.
async function postWithProjectIds(data, projectIds) {
  const body = new URLSearchParams({ ...data, csrf_token: CSRF });
  for (const pid of projectIds) body.append('project_ids[]', pid);
  const res = await fetch('api.php', { method: 'POST', body });
  return res.json();
}

async function addWorker(e) {
  e.preventDefault();
  const full_name  = document.getElementById('new-worker-name').value.trim();
  const category   = document.getElementById('new-worker-category').value.trim();
  const daily_wage = document.getElementById('new-worker-wage').value.trim();
  if (!full_name) { toast('Enter a worker name.', 'err'); return false; }

  const projectIds = [...document.querySelectorAll('#new-worker-projects input[type=checkbox]:checked')].map(el => el.value);
  const r = await postWithProjectIds({ action: 'add_worker', full_name, category, daily_wage }, projectIds);
  if (r.success) {
    toast('Worker added.', 'ok');
    setTimeout(() => location.reload(), 500);
  } else {
    toast(r.error || 'Failed to add worker.', 'err');
  }
  return false;
}

let _editingWorkerId = null;
function openProjectsEditor(id, name, currentProjectIds) {
  _editingWorkerId = id;
  document.getElementById('projects-editor-title').textContent = `Assigned Projects — ${name}`;
  const list = document.getElementById('projects-editor-list');
  list.innerHTML = ALL_PROJECTS.map(p => `
    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:13px">
      <input type="checkbox" value="${p.id}" ${currentProjectIds.includes(p.id) ? 'checked' : ''}> ${p.title.replace(/</g, '&lt;')}
    </label>
  `).join('');
  document.getElementById('projects-mask').style.display = 'flex';
}
function closeProjectsEditor() {
  document.getElementById('projects-mask').style.display = 'none';
  _editingWorkerId = null;
}
document.getElementById('projects-editor-save').onclick = async () => {
  if (_editingWorkerId === null) return;
  const projectIds = [...document.querySelectorAll('#projects-editor-list input[type=checkbox]:checked')].map(el => el.value);
  const r = await postWithProjectIds({ action: 'set_worker_projects', worker_id: _editingWorkerId }, projectIds);
  if (r.success) {
    toast('Project assignments updated.', 'ok');
    setTimeout(() => location.reload(), 400);
  } else {
    toast(r.error || 'Failed to update.', 'err');
  }
  closeProjectsEditor();
};

async function toggleWorker(id, btn) {
  btn.disabled = true;
  const r = await post({ action: 'toggle_worker_active', worker_id: id });
  btn.disabled = false;
  if (r.success) {
    const badge = document.getElementById('status-' + id);
    badge.textContent = r.is_active ? 'Active' : 'Hidden';
    badge.className = 'toggle-status ' + (r.is_active ? 'published' : 'draft');
    btn.innerHTML = `<i class="fa-solid fa-eye${r.is_active ? '-slash' : ''}"></i>`;
    btn.title = r.is_active ? 'Hide from attendance list' : 'Unhide';
    document.getElementById('worker-' + id).classList.toggle('is-hidden', !r.is_active);
    toast(r.is_active ? 'Worker unhidden.' : 'Worker hidden from the attendance list.', 'ok');
  } else {
    toast(r.error || 'Failed to update worker.', 'err');
  }
}

let _delCb = null;
function confirmDeleteWorker(id, name) {
  document.getElementById('confirm-msg').textContent = `Remove "${name}" from the roster? Their attendance history will be kept.`;
  document.getElementById('mask').style.display = 'flex';
  _delCb = () => doDeleteWorker(id);
}
document.getElementById('confirm-ok').onclick = () => { if (_delCb) _delCb(); };
function closeMask() { document.getElementById('mask').style.display = 'none'; _delCb = null; }

async function doDeleteWorker(id) {
  closeMask();
  const r = await post({ action: 'delete_worker', worker_id: id });
  if (r.success) {
    toast('Worker deleted.', 'ok');
    setTimeout(() => location.reload(), 500);
  } else {
    toast(r.error || 'Delete failed.', 'err');
  }
}

function toast(msg, type = 'ok') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  document.getElementById('toasts').appendChild(t);
  setTimeout(() => t.remove(), 3200);
}
</script>
</body>
</html>