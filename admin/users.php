<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/functions.php';
$csrf = csrfToken();

// Check the database connection explicitly so a bad config/db.php (e.g.
// while pointing this at a new host) shows a clear message here instead
// of a raw PHP fatal error.
$dbError = null;
$users   = [];
try {
    db()->query('SELECT 1');
    $users = db()->query(
        'SELECT u.id, u.username, u.full_name, u.is_active,
                COUNT(up.project_id) AS project_count
         FROM site_users u
         LEFT JOIN user_projects up ON up.user_id = u.id
         GROUP BY u.id
         ORDER BY u.full_name'
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
<title>Site Users — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">
    JEIWS <span>CMS</span>
  </a>
  <div class="cms-nav-right">
    <a href="index.php">Projects</a>
    <a href="analytics.php">Analytics</a>
    <a href="users.php" class="active">Site Users</a>
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <div>
      <h1>Site Users</h1>
      <p>Accounts for logging labour attendance &amp; materials stock</p>
    </div>
    <a href="user_edit.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Site User</a>
  </div>

  <?php if ($dbError !== null): ?>
  <div class="alert alert-err" style="align-items:flex-start">
    <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i>
    <div>
      <strong>Database connection failed.</strong> Site Users, Attendance and Materials Stock all depend on this
      connection — check the host/dbname/username/password in <code>config/db.php</code>.
      <div style="margin-top:6px;font-family:monospace;font-size:12px;opacity:.85"><?= htmlspecialchars($dbError) ?></div>
    </div>
  </div>
  <?php elseif (empty($users)): ?>
  <div class="empty">
    <div class="empty-icon"><i class="fa-regular fa-address-card"></i></div>
    <h3>No site users yet</h3>
    <p>Click "Add Site User" to create the first account.</p>
  </div>
  <?php else: ?>
  <div class="projects-grid">
    <?php foreach ($users as $u): ?>
    <div class="card">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px">
        <div>
          <h3 style="font-size:14.5px;font-weight:700;color:var(--navy);margin-bottom:3px"><?= htmlspecialchars($u['full_name']) ?></h3>
          <div style="font-size:12px;color:var(--muted)">@<?= htmlspecialchars($u['username']) ?></div>
        </div>
        <?php if ($u['is_active']): ?>
        <span class="toggle-status published">Active</span>
        <?php else: ?>
        <span class="toggle-status draft">Inactive</span>
        <?php endif ?>
      </div>
      <div class="proj-card-meta" style="margin-bottom:14px"><?= $u['project_count'] ?> project<?= $u['project_count'] != 1 ? 's' : '' ?> assigned</div>
      <div class="proj-card-actions">
        <a href="user_edit.php?id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen"></i> Manage</a>
        <button class="btn btn-danger btn-sm"
                data-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['full_name']) ?>"
                onclick="confirmDelete(this.dataset.id, this.dataset.name)">
          <i class="fa-solid fa-trash"></i> Delete
        </button>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</main>

<div class="toasts" id="toasts"></div>

<div class="mask" id="mask" style="display:none">
  <div class="confirm-box">
    <h3>Delete Site User</h3>
    <p id="confirm-msg"></p>
    <div class="confirm-actions">
      <button class="btn btn-ghost btn-sm" onclick="closeMask()">Cancel</button>
      <button class="btn btn-danger btn-sm" id="confirm-ok">Delete</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let _cb = null;
function confirmDelete(id, name) {
  document.getElementById('confirm-msg').textContent = `Delete "${name}"? This cannot be undone.`;
  document.getElementById('mask').style.display = 'flex';
  _cb = () => doDelete(id);
}
document.getElementById('confirm-ok').onclick = () => { if (_cb) _cb(); };
function closeMask() { document.getElementById('mask').style.display = 'none'; _cb = null; }

async function doDelete(id) {
  closeMask();
  const body = new URLSearchParams({ action: 'delete_site_user', user_id: id, csrf_token: CSRF });
  const res  = await fetch('api.php', { method: 'POST', body });
  const r    = await res.json();
  if (r.success) location.reload();
  else toast(r.error || 'Delete failed.', 'err');
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
