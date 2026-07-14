<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/functions.php';

// Check the database connection explicitly so a bad config/db.php shows a
// clear message here instead of a raw PHP fatal error.
$dbError  = null;
$projects = [];
$materials = [];
$history  = [];
$totals   = ['in' => [], 'out' => [], 'bundles_in' => 0, 'bundles_out' => 0];

// Filters
$filterProject  = trim($_GET['project_id']  ?? '');
$filterMaterial = (int)($_GET['material_id'] ?? 0);
$filterType     = trim($_GET['txn_type']     ?? '');
$filterFrom     = trim($_GET['date_from']    ?? '');
$filterTo       = trim($_GET['date_to']      ?? '');
if (!in_array($filterType, ['in', 'out'], true)) $filterType = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = '';
$hasFilters = $filterProject !== '' || $filterMaterial > 0 || $filterType !== '' || $filterFrom !== '' || $filterTo !== '';

try {
    db()->query('SELECT 1');

    $projects  = db()->query('SELECT id, title FROM projects ORDER BY title')->fetchAll();
    $materials = db()->query('SELECT id, name, unit, category FROM materials ORDER BY (category IS NULL), category, name')->fetchAll();

    $where  = [];
    $params = [];
    if ($filterProject !== '')  { $where[] = 'ms.project_id = :pid';   $params['pid']  = $filterProject; }
    if ($filterMaterial > 0)    { $where[] = 'ms.material_id = :mid';  $params['mid']  = $filterMaterial; }
    if ($filterType !== '')     { $where[] = 'ms.txn_type = :type';    $params['type'] = $filterType; }
    if ($filterFrom !== '')     { $where[] = 'ms.txn_date >= :dfrom';  $params['dfrom'] = $filterFrom; }
    if ($filterTo !== '')       { $where[] = 'ms.txn_date <= :dto';    $params['dto']   = $filterTo; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = db()->prepare(
        "SELECT ms.id, ms.txn_date, ms.txn_type, ms.quantity, ms.bundle_qty, ms.notes,
                m.name AS material_name, m.unit, m.category,
                p.id AS project_id, p.title AS project_title,
                u.username AS recorded_by_username
         FROM materials_stock ms
         JOIN materials m ON m.id = ms.material_id
         JOIN projects p  ON p.id = ms.project_id
         LEFT JOIN site_users u ON u.id = ms.recorded_by
         $whereSql
         ORDER BY ms.txn_date DESC, ms.id DESC
         LIMIT 500"
    );
    $stmt->execute($params);
    $history = $stmt->fetchAll();
    $totals  = computeStockTotals($history);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// Renders formatStockTotals() lines as "Category: qty unit" rows for the Total In/Out cards
function renderStockLogTotalLines(array $lines): string {
    if (empty($lines)) return '<div class="val-line"><span class="val-cat">—</span> 0</div>';
    $html = '';
    foreach ($lines as $l) {
        $html .= '<div class="val-line"><span class="val-cat">' . htmlspecialchars($l['category']) . ':</span> ' . htmlspecialchars($l['text']) . '</div>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stock Log — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.stock-log-totals{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px}
.stock-log-total-box{border-radius:var(--r);padding:16px 18px;border:1.5px solid var(--border-2)}
.stock-log-total-box.in{background:#F0FBF4;border-color:#C6F6D5}
.stock-log-total-box.out{background:#FFF5F5;border-color:#FED7D7}
.stock-log-total-box .lbl{display:flex;align-items:center;gap:7px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px}
.stock-log-total-box.in .lbl{color:#1A9966}
.stock-log-total-box.out .lbl{color:#C0392B}
.stock-log-total-box .val{display:flex;flex-direction:column;gap:4px}
.val-line{font-family:'Sora',sans-serif;font-size:1.05rem;font-weight:800;color:var(--navy);word-break:break-word;line-height:1.3}
.val-cat{font-family:'Plus Jakarta Sans',sans-serif;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.3px;margin-right:4px}
.date-input-wrap{position:relative;max-width:220px}
.date-display-input{padding-right:40px!important;cursor:pointer;background:var(--surface)}
.date-input-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;font-size:14px}
.date-native-hidden{position:absolute;inset:0;opacity:0;pointer-events:none;width:100%;height:100%}
.log-filter-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.log-filter-form .form-group{margin-bottom:0}

/* ── Log table (cms.css doesn't define these — they normally
   belong to the site portal's stylesheet, not the admin CMS) ── */
.site-table-wrap{overflow-x:auto;border:1px solid var(--border-2);border-radius:var(--r)}
.site-table{width:100%;border-collapse:collapse;font-size:13px}
.site-table th{text-align:left;padding:12px 14px;background:#F6F9FC;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:0.4px;font-size:10.5px;border-bottom:1px solid var(--border-2);white-space:nowrap}
.site-table td{padding:12px 14px;border-bottom:1px solid var(--border-2);color:var(--text);white-space:nowrap;vertical-align:top}
.site-table tr:last-child td{border-bottom:none}
.site-table tr:hover td{background:#F8FBFD}
.site-table td.wrap{white-space:normal}
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.3px}
.status-badge.in{background:#E8F7EF;color:#1A9966}
.status-badge.out{background:#FDE8E8;color:#C0392B}
</style>
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
    <a href="materials.php">Materials</a>
    <a href="stock_log.php" class="active">Stock Log</a>
    <a href="attendance_log.php">Attendance</a>
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <div>
      <h1>Stock Log</h1>
      <p>Materials stock movements logged by site users, across all projects</p>
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
    <div class="card-title">
      Filters
    </div>
    <form class="log-filter-form" method="GET">
      <div class="form-group">
        <label>Project</label>
        <select name="project_id">
          <option value="">All projects</option>
          <?php foreach ($projects as $p): ?>
          <option value="<?= htmlspecialchars($p['id']) ?>" <?= $filterProject === $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['title']) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label>Material</label>
        <select name="material_id">
          <option value="">All materials</option>
          <?php $curCat = null; foreach ($materials as $m): if ($m['category'] !== $curCat): if ($curCat !== null) echo '</optgroup>'; $curCat = $m['category']; ?>
          <optgroup label="<?= htmlspecialchars($curCat ?? 'Other') ?>">
          <?php endif ?>
          <option value="<?= $m['id'] ?>" <?= $filterMaterial === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
          <?php endforeach; if ($materials) echo '</optgroup>'; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Type</label>
        <select name="txn_type">
          <option value="">All types</option>
          <option value="in" <?= $filterType === 'in' ? 'selected' : '' ?>>Received (IN)</option>
          <option value="out" <?= $filterType === 'out' ? 'selected' : '' ?>>Used (OUT)</option>
        </select>
      </div>
      <div class="form-group">
        <label>From</label>
        <div class="date-input-wrap">
          <input type="text" id="filter-from-display" class="date-display-input" readonly placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($filterFrom) ?>">
          <input type="date" name="date_from" id="filter-from" class="date-native-hidden" value="<?= htmlspecialchars($filterFrom) ?>">
          <i class="fa-solid fa-calendar-days date-input-icon"></i>
        </div>
      </div>
      <div class="form-group">
        <label>To</label>
        <div class="date-input-wrap">
          <input type="text" id="filter-to-display" class="date-display-input" readonly placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($filterTo) ?>">
          <input type="date" name="date_to" id="filter-to" class="date-native-hidden" value="<?= htmlspecialchars($filterTo) ?>">
          <i class="fa-solid fa-calendar-days date-input-icon"></i>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Apply</button>
      <?php if ($hasFilters): ?>
      <a href="stock_log.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
      <?php endif ?>
    </form>

    <div class="stock-log-totals">
      <div class="stock-log-total-box in">
        <div class="lbl"><i class="fa-solid fa-arrow-down"></i> Total In</div>
        <div class="val"><?= renderStockLogTotalLines(formatStockTotals($totals['in'])) ?></div>
      </div>
      <div class="stock-log-total-box out">
        <div class="lbl"><i class="fa-solid fa-arrow-up"></i> Total Out</div>
        <div class="val"><?= renderStockLogTotalLines(formatStockTotals($totals['out'])) ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">
      Transactions <small><?= count($history) ?> entr<?= count($history) === 1 ? 'y' : 'ies' ?><?= $hasFilters ? ' (filtered)' : '' ?><?= count($history) === 500 ? ' — showing most recent 500' : '' ?></small>
    </div>
    <?php if (empty($history)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
      <h3>No transactions<?= $hasFilters ? ' match these filters' : ' logged yet' ?></h3>
    </div>
    <?php else: ?>
    <div class="site-table-wrap">
      <table class="site-table">
        <thead>
          <tr><th>Date</th><th>Project</th><th>Category</th><th>Material</th><th>Type</th><th>Qty</th><th>User</th><th>Notes</th></tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['txn_date']) ?></td>
            <td><?= htmlspecialchars($h['project_title']) ?></td>
            <td><?= htmlspecialchars($h['category'] ?? '—') ?></td>
            <td><?= htmlspecialchars($h['material_name']) ?></td>
            <td><span class="status-badge <?= htmlspecialchars($h['txn_type']) ?>"><?= strtoupper(htmlspecialchars($h['txn_type'])) ?></span></td>
            <td><?= rtrim(rtrim(number_format((float)$h['quantity'], 2), '0'), '.') ?> <?= htmlspecialchars($h['unit']) ?><?php if (!empty($h['bundle_qty'])): ?><br><small style="color:var(--muted)"><?= rtrim(rtrim(number_format((float)$h['bundle_qty'], 2), '0'), '.') ?> bundle<?= (float)$h['bundle_qty'] == 1 ? '' : 's' ?></small><?php endif ?></td>
            <td><?= htmlspecialchars($h['recorded_by_username'] ?? '—') ?></td>
            <td class="wrap"><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>
  </div>
  <?php endif ?>
</main>

<script>
function setupDateField(displayId, nativeId) {
  const display = document.getElementById(displayId);
  const native  = document.getElementById(nativeId);
  native.addEventListener('change', () => { display.value = native.value; });
  display.addEventListener('click', () => {
    if (native.showPicker) native.showPicker(); else native.focus();
  });
}
setupDateField('filter-from-display', 'filter-from');
setupDateField('filter-to-display', 'filter-to');
</script>
</body>
</html>