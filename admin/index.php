<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/functions.php';
$projects = loadProjects();
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Projects — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
</head>
<body>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">
    JEIWS <span>CMS</span>
  </a>
  <div class="cms-nav-right">
    <a href="../index.html" target="_blank">← View Site</a>
    <a href="logout.php" class="btn-logout">Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <?php
      $totalCount   = count($projects);
      $draftCount   = count(array_filter($projects, fn($p) => !empty($p['is_draft'])));
      $publishCount = $totalCount - $draftCount;
    ?>
    <div>
      <h1>Projects</h1>
      <p><?= $publishCount ?> published<?= $draftCount ? ' &middot; ' . $draftCount . ' draft' . ($draftCount !== 1 ? 's' : '') : '' ?></p>
    </div>
    <a href="project.php" class="btn btn-primary">+ Add Project</a>
  </div>

  <?php if (empty($projects)): ?>
  <div class="empty">
    <div class="empty-icon">📁</div>
    <h3>No projects yet</h3>
    <p>Click "Add Project" to get started.</p>
  </div>
  <?php else: ?>
  <div class="projects-grid">
    <?php foreach ($projects as $p):
      $imgSrc = !empty($p['image']) ? '../' . htmlspecialchars($p['image']) : '../assets/favicon.png';
    ?>
    <div class="proj-card" id="card-<?= $p['id'] ?>">
      <img class="proj-card-img" src="<?= $imgSrc ?>" alt=""
           onerror="this.src='../assets/favicon.png'">
      <div class="proj-card-body">
        <h3 title="<?= htmlspecialchars($p['title']) ?>"><?= htmlspecialchars($p['title']) ?></h3>
        <div class="proj-card-meta">
          <?php if (!empty($p['is_draft'])): ?><span class="proj-draft-badge">Draft</span> <?php endif ?>
          <?= count($p['gallery'] ?? []) ?> photos &middot; ID #<?= $p['id'] ?>
        </div>
        <div class="proj-card-actions">
          <a href="project.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">✏ Edit</a>
          <button class="btn btn-danger btn-sm"
                  data-id="<?= htmlspecialchars($p['id']) ?>"
                  data-title="<?= htmlspecialchars($p['title']) ?>"
                  onclick="confirmDelete(this.dataset.id, this.dataset.title)">
            🗑 Delete
          </button>
        </div>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</main>

<div class="toasts" id="toasts"></div>

<div class="mask" id="mask" style="display:none">
  <div class="confirm-box">
    <h3>Delete Project</h3>
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
function confirmDelete(id, title) {
  document.getElementById('confirm-msg').textContent =
    `Delete "${title}" and all its photos? This cannot be undone.`;
  document.getElementById('mask').style.display = 'flex';
  _cb = () => doDelete(id);
}
document.getElementById('confirm-ok').onclick = () => { if (_cb) _cb(); };
function closeMask() { document.getElementById('mask').style.display = 'none'; _cb = null; }

async function doDelete(id) {
  closeMask();
  const r = await post({ action: 'delete_project', project_id: id });
  if (r.success) {
    document.getElementById('card-' + id)?.remove();
    toast('Project deleted.', 'ok');
  } else {
    toast(r.error || 'Delete failed.', 'err');
  }
}

async function post(data) {
  const body = new URLSearchParams({ ...data, csrf_token: CSRF });
  const res  = await fetch('api.php', { method: 'POST', body });
  return res.json();
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
