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
try {
    db()->query('SELECT 1');
    // attendance_count is global (across every project, not just one) so
    // deletion can be blocked if a worker has any history anywhere —
    // mirrors materials.php's hide-vs-delete pattern.
    $workers = db()->query(
        'SELECT w.id, w.full_name, w.category, w.daily_wage, w.is_active, COUNT(la.id) AS attendance_count
         FROM workers w
         LEFT JOIN labour_attendance la ON la.worker_id = w.id
         GROUP BY w.id, w.full_name, w.category, w.daily_wage, w.is_active
         ORDER BY w.full_name'
    )->fetchAll();
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
      <div class="materials-table">
        <div class="materials-table-head">
          <span>Worker</span>
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
          <span><?= $w['daily_wage'] !== null ? 'Rs. ' . htmlspecialchars($w['daily_wage']) : '—' ?></span>
          <span>
            <span class="toggle-status <?= $w['is_active'] ? 'published' : 'draft' ?>" id="status-<?= $w['id'] ?>">
              <?= $w['is_active'] ? 'Active' : 'Hidden' ?>
            </span>
          </span>
          <span class="mt-actions">
            <button class="btn btn-ghost btn-sm" title="<?= $w['is_active'] ? 'Hide from attendance list' : 'Unhide' ?>" onclick="toggleWorker(<?= $w['id'] ?>, this)">
              <i class="fa-solid fa-eye<?= $w['is_active'] ? '-slash' : '' ?>"></i>
            </button>
            <?php if ($w['attendance_count'] > 0): ?>
            <button class="btn btn-ghost btn-sm" disabled title="<?= $w['attendance_count'] ?> attendance record<?= $w['attendance_count'] == 1 ? '' : 's' ?> logged — hide instead of deleting">
              <i class="fa-solid fa-trash"></i>
            </button>
            <?php else: ?>
            <button class="btn btn-ghost btn-sm mt-delete"
                    data-id="<?= $w['id'] ?>" data-name="<?= htmlspecialchars($w['full_name']) ?>"
                    title="Delete" onclick="confirmDeleteWorker(this.dataset.id, this.dataset.name)">
              <i class="fa-solid fa-trash"></i>
            </button>
            <?php endif ?>
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

<script>
const CSRF = <?= json_encode($csrf) ?>;

async function post(data) {
  const body = new URLSearchParams({ ...data, csrf_token: CSRF });
  const res  = await fetch('api.php', { method: 'POST', body });
  return res.json();
}

async function addWorker(e) {
  e.preventDefault();
  const full_name  = document.getElementById('new-worker-name').value.trim();
  const category   = document.getElementById('new-worker-category').value.trim();
  const daily_wage = document.getElementById('new-worker-wage').value.trim();
  if (!full_name) { toast('Enter a worker name.', 'err'); return false; }

  const r = await post({ action: 'add_worker', full_name, category, daily_wage });
  if (r.success) {
    toast('Worker added.', 'ok');
    setTimeout(() => location.reload(), 500);
  } else {
    toast(r.error || 'Failed to add worker.', 'err');
  }
  return false;
}

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
  document.getElementById('confirm-msg').textContent = `Delete "${name}"? This cannot be undone.`;
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