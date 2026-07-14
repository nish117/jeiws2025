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
$dbError   = null;
$materials = [];
try {
    db()->query('SELECT 1');
    $materials = db()->query(
        'SELECT m.id, m.name, m.unit, m.category, m.is_active, COUNT(ms.id) AS txn_count
         FROM materials m
         LEFT JOIN materials_stock ms ON ms.material_id = m.id
         GROUP BY m.id, m.name, m.unit, m.category, m.is_active
         ORDER BY (m.category IS NULL), m.category, m.name'
    )->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$materialsByCategory = [];
foreach ($materials as $m) {
    $materialsByCategory[$m['category'] ?? 'Other'][] = $m;
}

$categorySuggestions = ['Reinforcement', 'Cement', 'Sand', 'Aggregate', 'Bricks & Blocks', 'Electrical', 'Plumbing', 'Paint & Finishing', 'Other'];
$rebarDiameters = [6, 8, 10, 12, 16, 20, 25, 32];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Materials Catalog — JEIWS CMS</title>
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
    <a href="users.php">Site Users</a>
    <a href="materials.php" class="active">Materials</a>
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <div>
      <h1>Materials Catalog</h1>
      <p>Materials available for site users to log stock against — only admins can add or hide catalog items</p>
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
    <div class="card-title">Add Material</div>

    <div class="form-group">
      <label>Reinforcement quick-add <small style="font-weight:400;color:var(--muted)">— pick a diameter to fill the fields below</small></label>
      <select id="rebar-diameter" onchange="applyRebarDiameter()">
        <option value="">— Choose diameter (optional) —</option>
        <?php foreach ($rebarDiameters as $d): ?>
        <option value="<?= $d ?>"><?= $d ?> mm</option>
        <?php endforeach ?>
      </select>
    </div>

    <form class="inline-add-form" onsubmit="return addMaterial(event)">
      <div class="form-group">
        <label>Material name</label>
        <input type="text" id="new-mat-name" required placeholder="e.g. Reinforcement 12mm">
      </div>
      <div class="form-group">
        <label>Category</label>
        <input type="text" id="new-mat-category" list="category-suggestions" placeholder="e.g. Reinforcement">
        <datalist id="category-suggestions">
          <?php foreach ($categorySuggestions as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>">
          <?php endforeach ?>
        </datalist>
      </div>
      <div class="form-group">
        <label>Unit</label>
        <input type="text" id="new-mat-unit" required placeholder="e.g. bags, kg, cft">
      </div>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title">All Materials <small><?= count($materials) ?> total</small></div>
    <?php if (empty($materials)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
      <h3>No materials yet</h3>
      <p>Add your first material above.</p>
    </div>
    <?php else: ?>
    <div class="materials-table-wrap">
      <div class="materials-table">
        <div class="materials-table-head">
          <span>Material</span>
          <span>Category</span>
          <span>Status</span>
          <span></span>
        </div>
        <?php foreach ($materialsByCategory as $cat => $mats): foreach ($mats as $m): ?>
        <div class="materials-table-row<?= $m['is_active'] ? '' : ' is-hidden' ?>" id="material-<?= $m['id'] ?>">
          <span class="mt-name-cell">
            <span class="mt-name"><?= htmlspecialchars($m['name']) ?></span>
            <span class="mt-unit"><?= htmlspecialchars($m['unit']) ?><?php if ($m['txn_count'] > 0): ?> · <?= $m['txn_count'] ?> transaction<?= $m['txn_count'] == 1 ? '' : 's' ?><?php endif ?></span>
          </span>
          <span><span class="material-category-badge"><?= htmlspecialchars($cat) ?></span></span>
          <span>
            <span class="toggle-status <?= $m['is_active'] ? 'published' : 'draft' ?>" id="status-<?= $m['id'] ?>">
              <?= $m['is_active'] ? 'Active' : 'Hidden' ?>
            </span>
          </span>
          <span class="mt-actions">
            <button class="btn btn-ghost btn-sm" title="<?= $m['is_active'] ? 'Hide from site users' : 'Unhide' ?>" onclick="toggleMaterial(<?= $m['id'] ?>, this)">
              <i class="fa-solid fa-eye<?= $m['is_active'] ? '-slash' : '' ?>"></i>
            </button>
            <?php if ($m['txn_count'] > 0): ?>
            <button class="btn btn-ghost btn-sm" disabled title="<?= $m['txn_count'] ?> stock transaction<?= $m['txn_count'] == 1 ? '' : 's' ?> logged against this material — use Hide instead">
              <i class="fa-solid fa-trash"></i>
            </button>
            <?php else: ?>
            <button class="btn btn-ghost btn-sm mt-delete"
                    data-id="<?= $m['id'] ?>" data-name="<?= htmlspecialchars($m['name']) ?>"
                    title="Delete" onclick="confirmDeleteMaterial(this.dataset.id, this.dataset.name)">
              <i class="fa-solid fa-trash"></i>
            </button>
            <?php endif ?>
          </span>
        </div>
        <?php endforeach; endforeach ?>
      </div>
    </div>
    <?php endif ?>
  </div>
  <?php endif ?>
</main>

<div class="toasts" id="toasts"></div>

<div class="mask" id="mask" style="display:none">
  <div class="confirm-box">
    <h3>Delete Material</h3>
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

function applyRebarDiameter() {
  const size = document.getElementById('rebar-diameter').value;
  if (!size) return;
  document.getElementById('new-mat-name').value = `Reinforcement ${size}mm`;
  document.getElementById('new-mat-category').value = 'Reinforcement';
  const unitField = document.getElementById('new-mat-unit');
  if (!unitField.value) unitField.value = 'kg';
}

async function addMaterial(e) {
  e.preventDefault();
  const name     = document.getElementById('new-mat-name').value.trim();
  const category = document.getElementById('new-mat-category').value.trim();
  const unit     = document.getElementById('new-mat-unit').value.trim();
  if (!name || !unit) { toast('Enter both name and unit.', 'err'); return false; }

  const r = await post({ action: 'save_material', name, unit, category });
  if (r.success) {
    toast('Material saved.', 'ok');
    setTimeout(() => location.reload(), 500);
  } else {
    toast(r.error || 'Failed to save material.', 'err');
  }
  return false;
}

async function toggleMaterial(id, btn) {
  btn.disabled = true;
  const r = await post({ action: 'toggle_material_active', material_id: id });
  btn.disabled = false;
  if (r.success) {
    const badge = document.getElementById('status-' + id);
    badge.textContent = r.is_active ? 'Active' : 'Hidden';
    badge.className = 'toggle-status ' + (r.is_active ? 'published' : 'draft');
    btn.innerHTML = `<i class="fa-solid fa-eye${r.is_active ? '-slash' : ''}"></i>`;
    btn.title = r.is_active ? 'Hide from site users' : 'Unhide';
    document.getElementById('material-' + id).classList.toggle('is-hidden', !r.is_active);
    toast(r.is_active ? 'Material unhidden.' : 'Material hidden from site users.', 'ok');
  } else {
    toast(r.error || 'Failed to update material.', 'err');
  }
}

let _delCb = null;
function confirmDeleteMaterial(id, name) {
  document.getElementById('confirm-msg').textContent = `Delete "${name}"? This cannot be undone.`;
  document.getElementById('mask').style.display = 'flex';
  _delCb = () => doDeleteMaterial(id);
}
document.getElementById('confirm-ok').onclick = () => { if (_delCb) _delCb(); };
function closeMask() { document.getElementById('mask').style.display = 'none'; _delCb = null; }

async function doDeleteMaterial(id) {
  closeMask();
  const r = await post({ action: 'delete_material', material_id: id });
  if (r.success) {
    toast('Material deleted.', 'ok');
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