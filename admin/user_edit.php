<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/functions.php';
$csrf = csrfToken();

$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$isNew  = $id === null;

$user = ['id' => '', 'username' => '', 'full_name' => '', 'is_active' => true];
$assignedIds = [];

if (!$isNew) {
    $stmt = db()->prepare('SELECT id, username, full_name, is_active FROM site_users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { header('Location: users.php'); exit; }
    $user = $row;

    $stmt = db()->prepare('SELECT project_id FROM user_projects WHERE user_id = :id');
    $stmt->execute(['id' => $id]);
    $assignedIds = array_column($stmt->fetchAll(), 'project_id');
}

// All CMS projects (JSON is the source of truth for titles) that are
// also mirrored into Postgres, so they can be safely FK-referenced.
$allProjects = array_filter(loadProjects(), fn($p) => !empty($p['id']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $isNew ? 'New Site User' : 'Edit: ' . htmlspecialchars($user['full_name']) ?> — JEIWS CMS</title>
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
    <a href="users.php" class="active">Site Users</a>
    <a href="materials.php">Materials</a>
    <a href="stock_log.php">Stock Log</a>
    <a href="attendance_log.php">Attendance</a>
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="breadcrumb">
    <a href="users.php">Site Users</a>
    <span class="sep">›</span>
    <span><?= $isNew ? 'New User' : htmlspecialchars($user['full_name']) ?></span>
  </div>

  <div class="page-hdr">
    <div>
      <h1><?= $isNew ? 'Add Site User' : 'Edit Site User' ?></h1>
    </div>
    <a href="users.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-arrow-left"></i> Back</a>
  </div>

  <div class="editor-grid">
    <div>
      <div class="card">
        <div class="card-title">Account Details</div>
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" id="full-name" value="<?= htmlspecialchars($user['full_name']) ?>" placeholder="e.g. Ram Bahadur Shrestha">
        </div>
        <div class="form-group">
          <label>Username</label>
          <input type="text" id="username" value="<?= htmlspecialchars($user['username']) ?>" placeholder="e.g. ram.site" <?= $isNew ? '' : '' ?>>
        </div>
        <div class="form-group">
          <label><?= $isNew ? 'Password' : 'Reset Password' ?></label>
          <input type="password" id="password" placeholder="<?= $isNew ? 'Minimum 8 characters' : 'Leave blank to keep current password' ?>">
        </div>
        <?php if (!$isNew): ?>
        <div class="draft-row">
          <label for="is-active">Account Status</label>
          <div class="toggle-wrap">
            <label class="toggle-switch">
              <input type="checkbox" id="is-active" <?= $user['is_active'] ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
            <span class="toggle-status <?= $user['is_active'] ? 'published' : 'draft' ?>" id="active-status">
              <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </div>
        </div>
        <?php endif ?>
        <button class="btn btn-primary" id="save-btn" style="margin-top:16px" onclick="saveUser()">
          <i class="fa-solid fa-floppy-disk"></i> Save
        </button>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-title">Assigned Projects <small>only assigned projects are visible to this user</small></div>
        <?php if (empty($allProjects)): ?>
        <div class="empty"><p>No projects exist yet.</p></div>
        <?php else: ?>
        <div id="project-checks" style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ($allProjects as $p): ?>
          <label style="display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500;color:var(--text);cursor:pointer">
            <input type="checkbox" class="proj-check" value="<?= htmlspecialchars($p['id']) ?>"
                   style="width:18px;height:18px;flex-shrink:0"
                   <?= in_array($p['id'], $assignedIds, true) ? 'checked' : '' ?>
                   <?= $isNew ? 'disabled' : '' ?>>
            <?= htmlspecialchars($p['title']) ?>
          </label>
          <?php endforeach ?>
        </div>
        <?php if ($isNew): ?>
        <div class="alert alert-ok" style="margin-top:16px"><i class="fa-solid fa-circle-info"></i> Save the user first, then assign projects.</div>
        <?php else: ?>
        <button class="btn btn-primary btn-sm" style="margin-top:16px" onclick="saveProjects()"><i class="fa-solid fa-floppy-disk"></i> Save Assignments</button>
        <?php endif ?>
        <?php endif ?>
      </div>
    </div>
  </div>
</main>

<div class="toasts" id="toasts"></div>

<script>
const CSRF   = <?= json_encode($csrf) ?>;
const USERID = <?= json_encode($isNew ? null : (int)$user['id']) ?>;
const IS_NEW = <?= $isNew ? 'true' : 'false' ?>;

async function post(data) {
  const body = new URLSearchParams({ ...data, csrf_token: CSRF });
  const res  = await fetch('api.php', { method: 'POST', body });
  return res.json();
}

<?php if (!$isNew): ?>
document.getElementById('is-active').addEventListener('change', function() {
  const status = document.getElementById('active-status');
  status.textContent = this.checked ? 'Active' : 'Inactive';
  status.className = 'toggle-status ' + (this.checked ? 'published' : 'draft');
});
<?php endif ?>

async function saveUser() {
  const full_name = document.getElementById('full-name').value.trim();
  const username  = document.getElementById('username').value.trim();
  const password  = document.getElementById('password').value;
  const is_active = IS_NEW ? 1 : (document.getElementById('is-active').checked ? 1 : 0);

  if (!full_name || !username) { toast('Full name and username are required.', 'err'); return; }
  if (IS_NEW && password.length < 8) { toast('Password must be at least 8 characters.', 'err'); return; }

  const btn = document.getElementById('save-btn');
  btn.disabled = true;

  const r = await post({ action: 'save_site_user', user_id: USERID || '', full_name, username, password, is_active });

  btn.disabled = false;
  if (r.success) {
    toast(IS_NEW ? 'User created.' : 'Saved.', 'ok');
    if (IS_NEW) window.location.href = 'user_edit.php?id=' + r.user_id;
  } else {
    toast(r.error || 'Save failed.', 'err');
  }
}

async function saveProjects() {
  const ids  = Array.from(document.querySelectorAll('.proj-check:checked')).map(c => c.value);
  const body = new URLSearchParams({ action: 'set_user_projects', user_id: USERID, csrf_token: CSRF });
  ids.forEach(id => body.append('project_ids[]', id));

  const res = await fetch('api.php', { method: 'POST', body });
  const r   = await res.json();
  if (r.success) toast('Project assignments saved.', 'ok');
  else toast(r.error || 'Failed to save assignments.', 'err');
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
