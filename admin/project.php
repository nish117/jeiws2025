<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/functions.php';

$projects = loadProjects();
$id       = isset($_GET['id']) ? intval($_GET['id']) : null;
$isNew    = $id === null;
$idx      = $isNew ? -1 : findProject($projects, $id);

if (!$isNew && $idx === -1) { header('Location: index.php'); exit; }

$p = $isNew
    ? ['id' => nextId($projects), 'title' => '', 'description' => '', 'image' => '', 'gallery' => []]
    : $projects[$idx];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $isNew ? 'New Project' : 'Edit: ' . htmlspecialchars($p['title']) ?> — JEIWS CMS</title>
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
  <div class="breadcrumb">
    <a href="index.php">Projects</a>
    <span class="sep">›</span>
    <span><?= $isNew ? 'New Project' : htmlspecialchars($p['title']) ?></span>
  </div>

  <div class="page-hdr">
    <div>
      <h1><?= $isNew ? 'Add New Project' : 'Edit Project' ?></h1>
      <p>ID #<?= $p['id'] ?></p>
    </div>
    <a href="index.php" class="btn btn-ghost btn-sm">← Back</a>
  </div>

  <div class="editor-grid">

    <!-- ── Left: details ─────────────────────────── -->
    <div>
      <div class="card">
        <div class="card-title">Project Details</div>
        <div class="form-group">
          <label>Title</label>
          <input type="text" id="proj-title"
                 value="<?= htmlspecialchars($p['title']) ?>"
                 placeholder="e.g. Khokana Residential House">
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea id="proj-desc" rows="6"
                    placeholder="Location, area, notes..."><?= htmlspecialchars($p['description']) ?></textarea>
        </div>
        <button class="btn btn-primary" id="save-btn" onclick="saveDetails()">
          💾 Save Details
        </button>
      </div>

      <?php if (!$isNew && !empty($p['image'])): ?>
      <div class="card">
        <div class="card-title">Main / Featured Image</div>
        <img id="main-preview"
             src="../<?= htmlspecialchars($p['image']) ?>"
             alt="Main image"
             style="width:100%;border-radius:8px;object-fit:cover;max-height:190px;display:block"
             onerror="this.style.display='none'">
        <p style="font-size:11px;color:#6b849a;margin-top:8px">
          <?= htmlspecialchars(basename($p['image'])) ?>
        </p>
      </div>
      <?php endif ?>
    </div>

    <!-- ── Right: photos ─────────────────────────── -->
    <div>
      <div class="card">
        <div class="card-title">
          Gallery
          <small id="photo-count"><?= count($p['gallery']) ?> photo<?= count($p['gallery']) !== 1 ? 's' : '' ?></small>
        </div>

        <?php if ($isNew): ?>
        <div class="alert alert-ok" style="margin-bottom:16px">
          💡 Save the project details first, then upload photos.
        </div>
        <?php endif ?>

        <div class="photo-grid" id="photo-grid">
          <?php foreach ($p['gallery'] as $photo):
            $isMain = ($photo === $p['image']);
            $esc    = htmlspecialchars($photo);
            $js     = htmlspecialchars(addslashes($photo));
          ?>
          <div class="photo-item <?= $isMain ? 'is-main' : '' ?>" data-path="<?= $esc ?>">
            <img src="../<?= $esc ?>" loading="lazy" alt=""
                 onerror="this.parentElement.style.background='#e8eef4';this.style.display='none'">
            <div class="photo-overlay">
              <?php if (!$isMain): ?>
              <button class="photo-btn" title="Set as main" onclick="setMain('<?= $js ?>')">⭐</button>
              <?php endif ?>
              <button class="photo-btn del" title="Delete" onclick="deletePhoto('<?= $js ?>', this)">🗑</button>
            </div>
          </div>
          <?php endforeach ?>
        </div>

        <!-- Upload zone -->
        <div class="upload-zone <?= $isNew ? 'disabled' : '' ?>" id="upload-zone">
          <input type="file" id="file-input"
                 accept="image/jpeg,image/png,image/webp" multiple>
          <div class="uz-icon">📷</div>
          <p><strong>Click to upload</strong> or drag &amp; drop</p>
          <p style="font-size:12px">JPEG · PNG · WebP &mdash; max 10 MB each</p>
          <div class="progress-wrap" id="prog-wrap">
            <div class="progress-label" id="prog-label"></div>
            <div class="progress-bar"><div class="progress-fill" id="prog-fill"></div></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /editor-grid -->
</main>

<div class="toasts" id="toasts"></div>

<script>
const PID   = <?= $p['id'] ?>;
const IS_NEW = <?= $isNew ? 'true' : 'false' ?>;

/* ── Save details ──────────────────────────────── */
async function saveDetails() {
  const title = document.getElementById('proj-title').value.trim();
  const desc  = document.getElementById('proj-desc').value.trim();
  if (!title) { toast('Please enter a project title.', 'err'); return; }

  const btn = document.getElementById('save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';

  const r = await post({ action: 'save_project', project_id: PID, title, description: desc });

  btn.disabled = false; btn.innerHTML = '💾 Save Details';

  if (r.success) {
    toast('Saved!', 'ok');
    if (IS_NEW) window.location.href = 'project.php?id=' + r.project_id;
  } else {
    toast(r.error || 'Save failed.', 'err');
  }
}

/* ── Set main image ────────────────────────────── */
async function setMain(photo) {
  const r = await post({ action: 'set_main_image', project_id: PID, photo });
  if (r.success) {
    document.querySelectorAll('.photo-item').forEach(el => el.classList.remove('is-main'));
    const el = document.querySelector(`.photo-item[data-path="${CSS.escape(photo)}"]`);
    if (el) el.classList.add('is-main');
    const prev = document.getElementById('main-preview');
    if (prev) { prev.src = '../' + photo; prev.style.display = 'block'; }
    toast('Main image updated.', 'ok');
    setTimeout(() => location.reload(), 700);
  } else {
    toast(r.error || 'Failed.', 'err');
  }
}

/* ── Delete photo ──────────────────────────────── */
async function deletePhoto(photo, btn) {
  if (!confirm('Delete this photo?')) return;
  btn.textContent = '…';
  const r = await post({ action: 'delete_photo', project_id: PID, photo });
  if (r.success) {
    btn.closest('.photo-item').remove();
    adjustCount(-1);
    toast('Photo deleted.', 'ok');
  } else {
    btn.textContent = '🗑';
    toast(r.error || 'Delete failed.', 'err');
  }
}

/* ── Upload ────────────────────────────────────── */
const zone      = document.getElementById('upload-zone');
const fileInput = document.getElementById('file-input');

zone.addEventListener('click', () => { if (!zone.classList.contains('disabled')) fileInput.click(); });
fileInput.addEventListener('change', () => uploadFiles(fileInput.files));
zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('over'); });
zone.addEventListener('dragleave', ()  => zone.classList.remove('over'));
zone.addEventListener('drop', e => {
  e.preventDefault(); zone.classList.remove('over');
  uploadFiles(e.dataTransfer.files);
});

async function uploadFiles(files) {
  if (!files.length) return;
  const wrap  = document.getElementById('prog-wrap');
  const label = document.getElementById('prog-label');
  const fill  = document.getElementById('prog-fill');
  wrap.style.display = 'block';

  for (let i = 0; i < files.length; i++) {
    label.textContent = `Uploading ${i + 1} / ${files.length}: ${files[i].name}`;
    fill.style.width  = Math.round((i / files.length) * 100) + '%';

    const fd = new FormData();
    fd.append('action', 'upload_photo');
    fd.append('project_id', PID);
    fd.append('photo', files[i]);

    const res = await fetch('api.php', { method: 'POST', body: fd });
    const r   = await res.json();

    if (r.success) {
      appendPhoto(r.path, r.is_first_image);
      adjustCount(1);
    } else {
      toast(`${files[i].name}: ${r.error || 'upload error'}`, 'err');
    }
  }

  fill.style.width  = '100%';
  label.textContent = 'Done!';
  setTimeout(() => { wrap.style.display = 'none'; fill.style.width = '0'; }, 1800);
  fileInput.value = '';
}

function appendPhoto(path, isFirst) {
  const grid = document.getElementById('photo-grid');
  const div  = document.createElement('div');
  div.className    = 'photo-item' + (isFirst ? ' is-main' : '');
  div.dataset.path = path;
  const esc = path.replace(/'/g, "\\'");
  div.innerHTML = `
    <img src="../${path}" loading="lazy" alt="">
    <div class="photo-overlay">
      ${!isFirst ? `<button class="photo-btn" title="Set as main" onclick="setMain('${esc}')">⭐</button>` : ''}
      <button class="photo-btn del" title="Delete" onclick="deletePhoto('${esc}', this)">🗑</button>
    </div>`;
  grid.appendChild(div);
  if (isFirst) {
    const prev = document.getElementById('main-preview');
    if (prev) { prev.src = '../' + path; prev.style.display = 'block'; }
  }
}

function adjustCount(delta) {
  const el  = document.getElementById('photo-count');
  const n   = (parseInt(el.textContent) || 0) + delta;
  el.textContent = n + ' photo' + (n !== 1 ? 's' : '');
}

/* ── Shared ────────────────────────────────────── */
async function post(data) {
  const r = await fetch('api.php', {
    method: 'POST',
    body: new URLSearchParams(data)
  });
  return r.json();
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
