<?php
session_start();
define('SITE_LOADED', 1);
require_once __DIR__ . '/functions.php';
requireSiteAuth();

$userId    = currentSiteUserId();
$projectId = trim($_GET['project'] ?? '');
if (!$projectId || !userCanAccessProject($userId, $projectId)) {
    header('Location: index.php'); exit;
}
$projectTitle = getProjectTitle($projectId) ?? 'Unknown project';
$csrf = siteCsrfToken();

// Material catalog, grouped by category (uncategorized last)
$materials = db()->query(
    "SELECT id, name, unit, category FROM materials WHERE is_active = TRUE
     ORDER BY (category IS NULL), category, name"
)->fetchAll();
$materialsByCategory = [];
foreach ($materials as $m) {
    $materialsByCategory[$m['category'] ?? 'Other'][] = $m;
}

// Current balance per material for this project
$stmt = db()->prepare(
    "SELECT m.id, m.name, m.unit, m.category,
            COALESCE(SUM(CASE WHEN ms.txn_type = 'in' THEN ms.quantity ELSE -ms.quantity END), 0) AS balance
     FROM materials_stock ms
     JOIN materials m ON m.id = ms.material_id
     WHERE ms.project_id = :pid
     GROUP BY m.id, m.name, m.unit, m.category
     HAVING COALESCE(SUM(CASE WHEN ms.txn_type = 'in' THEN ms.quantity ELSE -ms.quantity END), 0) <> 0
     ORDER BY (m.category IS NULL), m.category, m.name"
);
$stmt->execute(['pid' => $projectId]);
$balances = $stmt->fetchAll();

// Transaction history — optionally filtered by material, type and/or date range
$filterMaterial = (int)($_GET['material_id'] ?? 0);
$filterType     = trim($_GET['txn_type'] ?? '');
$filterFrom     = trim($_GET['date_from'] ?? '');
$filterTo       = trim($_GET['date_to']   ?? '');
if (!in_array($filterType, ['in', 'out'], true)) $filterType = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = '';

$where  = ['ms.project_id = :pid'];
$params = ['pid' => $projectId];
if ($filterMaterial > 0)  { $where[] = 'ms.material_id = :mid';    $params['mid']  = $filterMaterial; }
if ($filterType !== '')   { $where[] = 'ms.txn_type = :type';      $params['type'] = $filterType; }
if ($filterFrom !== '')   { $where[] = 'ms.txn_date >= :dfrom';    $params['dfrom'] = $filterFrom; }
if ($filterTo !== '')     { $where[] = 'ms.txn_date <= :dto';      $params['dto']   = $filterTo; }

$stmt = db()->prepare(
    'SELECT ms.id, ms.material_id, ms.txn_date, m.name, m.unit, m.category, ms.txn_type, ms.quantity, ms.notes
     FROM materials_stock ms
     JOIN materials m ON m.id = ms.material_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY ms.txn_date DESC, ms.id DESC
     LIMIT 200'
);
$stmt->execute($params);
$history = $stmt->fetchAll();
$hasFilters = $filterMaterial > 0 || $filterType !== '' || $filterFrom !== '' || $filterTo !== '';
$totals = computeStockTotals($history);

// Common construction material categories, offered as suggestions (free text still allowed)
$categorySuggestions = ['Reinforcement', 'Cement', 'Sand', 'Aggregate', 'Bricks & Blocks', 'Electrical', 'Plumbing', 'Paint & Finishing', 'Other'];
$rebarDiameters = [6, 8, 10, 12, 16, 20, 25, 32];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Materials — <?= htmlspecialchars($projectTitle) ?></title>
<link rel="stylesheet" href="../admin/cms.css">
<link rel="stylesheet" href="site.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">
    JEIWS <span>SITE</span>
  </a>
  <div class="cms-nav-right">
    <span class="site-welcome">Hi, <?= htmlspecialchars($_SESSION['site_user_name']) ?></span>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="breadcrumb">
    <a href="index.php">My Projects</a>
    <span class="sep">›</span>
    <span><?= htmlspecialchars($projectTitle) ?></span>
  </div>

  <div class="page-hdr">
    <div>
      <h1>Materials Stock</h1>
      <p><?= htmlspecialchars($projectTitle) ?></p>
    </div>
  </div>

  <div class="site-tabs">
    <a href="attendance.php?project=<?= urlencode($projectId) ?>" class="site-tab"><i class="fa-solid fa-clipboard-user"></i> Attendance</a>
    <a href="stock.php?project=<?= urlencode($projectId) ?>" class="site-tab active"><i class="fa-solid fa-boxes-stacked"></i> Materials</a>
  </div>

  <div class="card">
    <div class="card-title">Current Stock</div>
    <?php if (empty($balances)): ?>
    <div class="empty"><p>No stock recorded yet for this project.</p></div>
    <?php else: ?>
    <div class="stock-balance-grid">
      <?php foreach ($balances as $b): ?>
      <div class="stock-balance-card">
        <?php if ($b['category']): ?><div class="mat-category"><?= htmlspecialchars($b['category']) ?></div><?php endif ?>
        <div class="mat-name"><?= htmlspecialchars($b['name']) ?></div>
        <span class="mat-qty"><?= rtrim(rtrim(number_format((float)$b['balance'], 2), '0'), '.') ?></span>
        <span class="mat-unit"><?= htmlspecialchars($b['unit']) ?></span>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>

  <div class="card">
    <div class="card-title">Log Stock Movement</div>
    <form onsubmit="return logStock(event)">
      <div class="form-group">
        <label>Material</label>
        <select id="stock-material" required>
          <option value="">— Select material —</option>
          <?php foreach ($materialsByCategory as $cat => $mats): ?>
          <optgroup label="<?= htmlspecialchars($cat) ?>">
            <?php foreach ($mats as $m): ?>
            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['unit']) ?>)</option>
            <?php endforeach ?>
          </optgroup>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label>Type</label>
        <div class="txn-type-toggle">
          <label><input type="radio" name="txn_type" value="in" checked><span>Received (IN)</span></label>
          <label><input type="radio" name="txn_type" value="out"><span>Used (OUT)</span></label>
        </div>
      </div>
      <div class="form-group">
        <label>Quantity</label>
        <input type="text" id="stock-qty" required placeholder="e.g. 50">
      </div>
      <div class="form-group">
        <label>Date</label>
        <div class="date-input-wrap">
          <input type="text" id="stock-date-display" class="date-display-input" readonly placeholder="YYYY-MM-DD" value="<?= date('Y-m-d') ?>">
          <input type="date" id="stock-date" class="date-native-hidden" required value="<?= date('Y-m-d') ?>">
          <i class="fa-solid fa-calendar-days date-input-icon"></i>
        </div>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea id="stock-notes" rows="2" placeholder="Optional — e.g. delivery from supplier, used for foundation"></textarea>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Log Transaction</button>
    </form>
  </div>

  <div class="card collapsible">
    <button type="button" class="card-title collapsible-toggle" onclick="toggleCollapse(this)">
      Add Material to Catalog
      <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    <div class="collapsible-body">
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
        <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-plus"></i> Add</button>
      </form>
    </div>
  </div>

  <div class="card collapsible">
    <button type="button" class="card-title collapsible-toggle" onclick="toggleCollapse(this)">
      <span>Transaction History <small id="history-count"><?= count($history) ?> entr<?= count($history) === 1 ? 'y' : 'ies' ?><?= $hasFilters ? ' (filtered)' : '' ?></small></span>
      <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    <div class="collapsible-body">

    <form class="site-date-row" id="stock-filter-form" onsubmit="return applyStockFilters(event)">
      <div class="form-group">
        <label>Material</label>
        <select name="material_id" id="filter-material">
          <option value="">All materials</option>
          <?php foreach ($materialsByCategory as $cat => $mats): ?>
          <optgroup label="<?= htmlspecialchars($cat) ?>">
            <?php foreach ($mats as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $filterMaterial === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
            <?php endforeach ?>
          </optgroup>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label>Type</label>
        <select id="filter-type">
          <option value="">All types</option>
          <option value="in" <?= $filterType === 'in' ? 'selected' : '' ?>>Received (IN)</option>
          <option value="out" <?= $filterType === 'out' ? 'selected' : '' ?>>Used (OUT)</option>
        </select>
      </div>
      <div class="form-group">
        <label>From</label>
        <div class="date-input-wrap">
          <input type="text" id="filter-from-display" class="date-display-input" readonly placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($filterFrom) ?>">
          <input type="date" id="filter-from" class="date-native-hidden" value="<?= htmlspecialchars($filterFrom) ?>">
          <i class="fa-solid fa-calendar-days date-input-icon"></i>
        </div>
      </div>
      <div class="form-group">
        <label>To</label>
        <div class="date-input-wrap">
          <input type="text" id="filter-to-display" class="date-display-input" readonly placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($filterTo) ?>">
          <input type="date" id="filter-to" class="date-native-hidden" value="<?= htmlspecialchars($filterTo) ?>">
          <i class="fa-solid fa-calendar-days date-input-icon"></i>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Apply</button>
      <button type="button" class="btn btn-ghost btn-sm" id="clear-filters-btn" onclick="clearStockFilters()" style="<?= $hasFilters ? '' : 'display:none' ?>"><i class="fa-solid fa-xmark"></i> Clear</button>
    </form>

    <div class="stock-totals-grid" id="stock-totals-grid">
      <div class="stock-total-box total-in">
        <div class="total-label"><i class="fa-solid fa-arrow-down"></i> Total In</div>
        <div class="total-value" id="total-in-value"><?= htmlspecialchars(formatStockTotals($totals['in'])) ?></div>
      </div>
      <div class="stock-total-box total-out">
        <div class="total-label"><i class="fa-solid fa-arrow-up"></i> Total Out</div>
        <div class="total-value" id="total-out-value"><?= htmlspecialchars(formatStockTotals($totals['out'])) ?></div>
      </div>
    </div>

    <div class="empty" id="history-empty" style="<?= empty($history) ? '' : 'display:none' ?>">
      <p id="history-empty-text"><?= $hasFilters ? 'No transactions match these filters.' : 'No transactions logged yet.' ?></p>
    </div>
    <div class="site-table-wrap" id="history-table-wrap" style="<?= empty($history) ? 'display:none' : '' ?>">
      <table class="site-table">
        <thead><tr><th>Date</th><th>Category</th><th>Material</th><th>Type</th><th>Qty</th><th>Notes</th><th></th></tr></thead>
        <tbody id="history-tbody">
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['txn_date']) ?></td>
            <td><?= htmlspecialchars($h['category'] ?? '—') ?></td>
            <td><?= htmlspecialchars($h['name']) ?></td>
            <td><span class="status-badge <?= htmlspecialchars($h['txn_type']) ?>"><?= strtoupper(htmlspecialchars($h['txn_type'])) ?></span></td>
            <td><?= rtrim(rtrim(number_format((float)$h['quantity'], 2), '0'), '.') ?> <?= htmlspecialchars($h['unit']) ?></td>
            <td class="wrap"><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
            <td><button class="btn btn-ghost btn-sm" onclick="openEditStock(<?= (int)$h['id'] ?>)"><i class="fa-solid fa-pen"></i> Edit</button></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>
</main>

<div class="toasts" id="toasts"></div>

<div class="mask" id="edit-mask" style="display:none">
  <div class="confirm-box" style="max-width:460px">
    <h3>Edit Transaction</h3>
    <div class="form-group">
      <label>Material</label>
      <select id="edit-material">
        <?php foreach ($materialsByCategory as $cat => $mats): ?>
        <optgroup label="<?= htmlspecialchars($cat) ?>">
          <?php foreach ($mats as $m): ?>
          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['unit']) ?>)</option>
          <?php endforeach ?>
        </optgroup>
        <?php endforeach ?>
      </select>
    </div>
    <div class="form-group">
      <label>Type</label>
      <div class="txn-type-toggle">
        <label><input type="radio" name="edit_txn_type" value="in"><span>Received (IN)</span></label>
        <label><input type="radio" name="edit_txn_type" value="out"><span>Used (OUT)</span></label>
      </div>
    </div>
    <div class="form-group">
      <label>Quantity</label>
      <input type="text" id="edit-qty">
    </div>
    <div class="form-group">
      <label>Date</label>
      <div class="date-input-wrap">
        <input type="text" id="edit-date-display" class="date-display-input" readonly placeholder="YYYY-MM-DD">
        <input type="date" id="edit-date" class="date-native-hidden">
        <i class="fa-solid fa-calendar-days date-input-icon"></i>
      </div>
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea id="edit-notes" rows="2"></textarea>
    </div>
    <div class="confirm-actions">
      <button class="btn btn-ghost btn-sm" onclick="closeEditMask()">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveStockEdit()"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
    </div>
  </div>
</div>

<script src="site.js"></script>
<script>
const CSRF    = <?= json_encode($csrf) ?>;
const PROJECT = <?= json_encode($projectId) ?>;
let HISTORY = <?= json_encode(array_map(fn($h) => [
  'id' => (int)$h['id'], 'material_id' => (int)$h['material_id'], 'txn_type' => $h['txn_type'],
  'quantity' => $h['quantity'], 'txn_date' => $h['txn_date'], 'notes' => $h['notes'],
], $history)) ?>;
let _editId = null;

function openEditStock(id) {
  const row = HISTORY.find(h => h.id === id);
  if (!row) return;
  _editId = id;
  document.getElementById('edit-material').value = row.material_id;
  document.querySelector(`input[name=edit_txn_type][value="${row.txn_type}"]`).checked = true;
  document.getElementById('edit-qty').value = row.quantity;
  document.getElementById('edit-date').value = row.txn_date;
  document.getElementById('edit-date-display').value = row.txn_date;
  document.getElementById('edit-notes').value = row.notes || '';
  document.getElementById('edit-mask').style.display = 'flex';
}

function closeEditMask() {
  document.getElementById('edit-mask').style.display = 'none';
  _editId = null;
}

async function saveStockEdit() {
  if (!_editId) return;
  const material_id = document.getElementById('edit-material').value;
  const txn_type     = document.querySelector('input[name=edit_txn_type]:checked').value;
  const quantity     = document.getElementById('edit-qty').value.trim();
  const date         = document.getElementById('edit-date').value;
  const notes        = document.getElementById('edit-notes').value.trim();

  if (!quantity || isNaN(quantity) || Number(quantity) <= 0) { toast('Enter a valid quantity.', 'err'); return; }

  const r = await post({ action: 'update_stock', stock_id: _editId, project_id: PROJECT, material_id, txn_type, quantity, date, notes });
  if (r.success) {
    toast('Transaction updated.', 'ok');
    setTimeout(() => location.reload(), 600);
  } else {
    toast(r.error || 'Failed to update transaction.', 'err');
  }
}

async function post(data) {
  const body = new URLSearchParams({ ...data, csrf_token: CSRF });
  const res  = await fetch('api.php', { method: 'POST', body });
  return res.json();
}

// Native <input type=date> always holds an ISO (YYYY-MM-DD) value regardless
// of how the browser renders it — pairing it with a read-only text field lets
// us always *display* YYYY-MM-DD while still getting the native calendar picker.
function setupDateField(displayId, nativeId) {
  const display = document.getElementById(displayId);
  const native  = document.getElementById(nativeId);
  native.addEventListener('change', () => { display.value = native.value; });
  display.addEventListener('click', () => {
    if (native.showPicker) native.showPicker(); else native.focus();
  });
}
setupDateField('stock-date-display', 'stock-date');
setupDateField('edit-date-display', 'edit-date');
setupDateField('filter-from-display', 'filter-from');
setupDateField('filter-to-display', 'filter-to');

function formatQty(q) {
  return parseFloat(q).toFixed(2).replace(/\.?0+$/, '');
}
function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : s;
  return d.innerHTML;
}

function renderHistoryRow(h) {
  return `<tr>
    <td>${escapeHtml(h.txn_date)}</td>
    <td>${escapeHtml(h.category || '—')}</td>
    <td>${escapeHtml(h.name)}</td>
    <td><span class="status-badge ${h.txn_type}">${h.txn_type.toUpperCase()}</span></td>
    <td>${formatQty(h.quantity)} ${escapeHtml(h.unit)}</td>
    <td class="wrap">${escapeHtml(h.notes || '—')}</td>
    <td><button class="btn btn-ghost btn-sm" onclick="openEditStock(${h.id})"><i class="fa-solid fa-pen"></i> Edit</button></td>
  </tr>`;
}

function renderTotals(totals) {
  document.getElementById('total-in-value').textContent = formatTotalsBucket(totals.in);
  document.getElementById('total-out-value').textContent = formatTotalsBucket(totals.out);
}

function formatTotalsBucket(byUnit) {
  const keys = Object.keys(byUnit || {});
  if (!keys.length) return '0';
  return keys.map(unit => `${formatQty(byUnit[unit])} ${unit}`).join(', ');
}

async function fetchAndRenderHistory() {
  const material_id = document.getElementById('filter-material').value;
  const txn_type     = document.getElementById('filter-type').value;
  const date_from    = document.getElementById('filter-from').value;
  const date_to       = document.getElementById('filter-to').value;
  const hasFilters    = !!(material_id || txn_type || date_from || date_to);

  const r = await post({ action: 'get_stock_history', project_id: PROJECT, material_id, txn_type, date_from, date_to });
  if (!r.success) { toast(r.error || 'Failed to load transactions.', 'err'); return; }

  HISTORY = r.rows.map(h => ({
    id: parseInt(h.id, 10), material_id: parseInt(h.material_id, 10), txn_type: h.txn_type,
    quantity: h.quantity, txn_date: h.txn_date, notes: h.notes,
  }));

  document.getElementById('history-tbody').innerHTML = r.rows.map(renderHistoryRow).join('');
  document.getElementById('history-table-wrap').style.display = r.rows.length ? '' : 'none';
  document.getElementById('history-empty').style.display = r.rows.length ? 'none' : '';
  document.getElementById('history-empty-text').textContent = hasFilters ? 'No transactions match these filters.' : 'No transactions logged yet.';
  document.getElementById('history-count').textContent =
    `${r.rows.length} entr${r.rows.length === 1 ? 'y' : 'ies'}${hasFilters ? ' (filtered)' : ''}`;
  document.getElementById('clear-filters-btn').style.display = hasFilters ? '' : 'none';
  renderTotals(r.totals);

  // Reflect the filter in the URL without a page reload, so it stays shareable
  const url = new URL(window.location);
  material_id ? url.searchParams.set('material_id', material_id) : url.searchParams.delete('material_id');
  txn_type    ? url.searchParams.set('txn_type', txn_type)       : url.searchParams.delete('txn_type');
  date_from   ? url.searchParams.set('date_from', date_from)     : url.searchParams.delete('date_from');
  date_to     ? url.searchParams.set('date_to', date_to)         : url.searchParams.delete('date_to');
  history.replaceState(null, '', url);
}

function applyStockFilters(e) {
  e.preventDefault();
  fetchAndRenderHistory();
  return false;
}

function clearStockFilters() {
  document.getElementById('filter-material').value = '';
  document.getElementById('filter-type').value = '';
  document.getElementById('filter-from').value = '';
  document.getElementById('filter-from-display').value = '';
  document.getElementById('filter-to').value = '';
  document.getElementById('filter-to-display').value = '';
  fetchAndRenderHistory();
}

async function logStock(e) {
  e.preventDefault();
  const material_id = document.getElementById('stock-material').value;
  const txn_type     = document.querySelector('input[name=txn_type]:checked').value;
  const quantity     = document.getElementById('stock-qty').value.trim();
  const date         = document.getElementById('stock-date').value;
  const notes        = document.getElementById('stock-notes').value.trim();

  if (!material_id) { toast('Select a material.', 'err'); return false; }
  if (!quantity || isNaN(quantity) || Number(quantity) <= 0) { toast('Enter a valid quantity.', 'err'); return false; }

  const r = await post({ action: 'log_stock', project_id: PROJECT, material_id, txn_type, quantity, date, notes });
  if (r.success) {
    toast('Transaction logged.', 'ok');
    setTimeout(() => location.reload(), 600);
  } else {
    toast(r.error || 'Failed to log transaction.', 'err');
  }
  return false;
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

  const r = await post({ action: 'add_material', name, unit, category });
  if (r.success) {
    toast('Material added.', 'ok');
    document.getElementById('rebar-diameter').value = '';
    setTimeout(() => location.reload(), 500);
  } else {
    toast(r.error || 'Failed to add material.', 'err');
  }
  return false;
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
