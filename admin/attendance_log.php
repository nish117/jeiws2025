<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/functions.php';

$dbError     = null;
$projects    = [];
$workers     = [];
$history     = [];
$presentDays = 0;
$absentDays  = 0;
$manDays     = 0.0;
$byWorker    = [];

// Filters
$filterProject = trim($_GET['project_id'] ?? '');
$filterWorker  = (int)($_GET['worker_id']  ?? 0);
$filterStatus  = trim($_GET['status']      ?? '');
$filterFrom    = trim($_GET['date_from']   ?? '');
$filterTo      = trim($_GET['date_to']     ?? '');
if (!in_array($filterStatus, ['present', 'absent', 'half_day'], true)) $filterStatus = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = '';
$hasFilters = $filterProject !== '' || $filterWorker > 0 || $filterStatus !== '' || $filterFrom !== '' || $filterTo !== '';

// Highlights the matching quick-date-filter button, if the current
// From/To exactly matches one of the preset ranges.
$todayStr      = date('Y-m-d');
$yesterdayStr  = date('Y-m-d', strtotime('-1 day'));
$weekAgoStr    = date('Y-m-d', strtotime('-6 days'));
$monthStartStr = date('Y-m-01');
$quickActive = '';
if ($filterFrom === $todayStr && $filterTo === $todayStr) $quickActive = 'today';
elseif ($filterFrom === $yesterdayStr && $filterTo === $yesterdayStr) $quickActive = 'yesterday';
elseif ($filterFrom === $weekAgoStr && $filterTo === $todayStr) $quickActive = 'week';
elseif ($filterFrom === $monthStartStr && $filterTo === $todayStr) $quickActive = 'month';

$csrf = csrfToken();

try {
    db()->query('SELECT 1');

    $projects = db()->query('SELECT id, title FROM projects ORDER BY title')->fetchAll();
    $workers  = db()->query('SELECT id, full_name, category FROM workers ORDER BY full_name')->fetchAll();

    // A project must be selected before any log data is queried/shown —
    // this is a cross-project admin view, and defaulting to "all projects"
    // both encourages accidentally merging separate job sites' figures and
    // is expensive to compute for no clear reason. See the prompt state
    // rendered below when $filterProject === ''.
    if ($filterProject !== '') {
        $where  = ['la.project_id = :pid'];
        $params = ['pid' => $filterProject];
        if ($filterWorker > 0)     { $where[] = 'la.worker_id = :wid';        $params['wid']    = $filterWorker; }
        if ($filterStatus !== '')  { $where[] = 'la.status = :status';        $params['status']  = $filterStatus; }
        if ($filterFrom !== '')    { $where[] = 'la.attendance_date >= :dfrom'; $params['dfrom'] = $filterFrom; }
        if ($filterTo !== '')      { $where[] = 'la.attendance_date <= :dto';   $params['dto']   = $filterTo; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = db()->prepare(
            "SELECT la.id, la.attendance_date, la.nepali_date, la.status, la.notes,
                    w.id AS worker_id, w.full_name AS worker_name, w.category AS worker_category,
                    p.id AS project_id, p.title AS project_title,
                    u.username AS recorded_by_username
             FROM labour_attendance la
             JOIN workers w  ON w.id = la.worker_id
             JOIN projects p ON p.id = la.project_id
             LEFT JOIN site_users u ON u.id = la.recorded_by
             $whereSql
             ORDER BY la.attendance_date DESC, w.full_name
             LIMIT 500"
        );
        $stmt->execute($params);
        $history = $stmt->fetchAll();

        // Present/absent/man-day totals for the current filter scope — a
        // separate unlimited aggregate so "Total" stays accurate even past
        // the 500-row display cap above.
        $stmt = db()->prepare("SELECT la.status, COUNT(*) AS cnt FROM labour_attendance la $whereSql GROUP BY la.status");
        $stmt->execute($params);
        $statusCounts = array_column($stmt->fetchAll(), 'cnt', 'status');
        $presentDays  = (int)($statusCounts['present']  ?? 0);
        $absentDays   = (int)($statusCounts['absent']   ?? 0);
        $halfDays     = (int)($statusCounts['half_day'] ?? 0);
        $manDays      = $presentDays + ($halfDays * 0.5);

        // Per-worker present/absent breakdown — scoped to project + worker +
        // date range like the records above, but deliberately ignores the
        // status filter since this table's whole point is to show every
        // status side by side for each worker.
        $workerWhere  = ['la.project_id = :pid'];
        $workerParams = ['pid' => $filterProject];
        if ($filterWorker > 0)  { $workerWhere[] = 'la.worker_id = :wid';          $workerParams['wid']   = $filterWorker; }
        if ($filterFrom !== '') { $workerWhere[] = 'la.attendance_date >= :dfrom'; $workerParams['dfrom'] = $filterFrom; }
        if ($filterTo !== '')   { $workerWhere[] = 'la.attendance_date <= :dto';   $workerParams['dto']   = $filterTo; }
        $workerWhereSql = 'WHERE ' . implode(' AND ', $workerWhere);

        $stmt = db()->prepare(
            "SELECT w.id AS worker_id, w.full_name, w.category, la.status, COUNT(*) AS cnt
             FROM labour_attendance la
             JOIN workers w ON w.id = la.worker_id
             $workerWhereSql
             GROUP BY w.id, w.full_name, w.category, la.status"
        );
        $stmt->execute($workerParams);
        foreach ($stmt->fetchAll() as $row) {
            $wid = $row['worker_id'];
            if (!isset($byWorker[$wid])) {
                $byWorker[$wid] = ['full_name' => $row['full_name'], 'category' => $row['category'], 'present' => 0, 'absent' => 0, 'half_day' => 0];
            }
            $byWorker[$wid][$row['status']] = (int)$row['cnt'];
        }
        usort($byWorker, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Log — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
.date-input-wrap{position:relative;max-width:220px}
.date-display-input{padding-right:40px!important;cursor:pointer;background:var(--surface)}
.date-input-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;font-size:14px}
.date-native-hidden{position:absolute;inset:0;opacity:0;pointer-events:none;width:100%;height:100%}
.log-filter-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:4px}
.log-filter-form .form-group{margin-bottom:0}

.quick-filters{flex-basis:100%;display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:2px}
.quick-filters-label{font-size:12px;font-weight:600;color:var(--muted);margin-right:2px}
.quick-filter-btn{font-family:inherit;font-size:12.5px;font-weight:600;padding:6px 14px;border-radius:100px;border:1.5px solid var(--border);color:var(--muted);background:var(--surface);cursor:pointer;transition:background var(--t),border-color var(--t),color var(--t)}
.quick-filter-btn:hover{border-color:var(--blue);color:var(--blue)}
.quick-filter-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}

/* ── Attendance summary (Present / Absent / Man-Days) ── */
.attendance-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-top:20px}
.attendance-summary-box{border-radius:var(--r);padding:16px 18px;border:1.5px solid var(--border-2)}
.attendance-summary-box.present{background:#F0FBF4;border-color:#C6F6D5}
.attendance-summary-box.absent{background:#FFF5F5;border-color:#FED7D7}
.attendance-summary-box.mandays{background:var(--blue-pale);border-color:#CFE3F0}
.attendance-summary-box .as-label{display:flex;align-items:center;gap:7px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px}
.attendance-summary-box.present .as-label{color:#1A9966}
.attendance-summary-box.absent .as-label{color:#C0392B}
.attendance-summary-box.mandays .as-label{color:var(--blue)}
.attendance-summary-box .as-value{font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:800;color:var(--navy)}

/* ── Per-worker Present/Absent cards ── */
.labour-summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}
.labour-summary-card{background:var(--bg);border:1px solid var(--border-2);border-radius:var(--r);padding:16px}
.labour-summary-card .ls-category{font-size:10px;font-weight:700;color:var(--gold-dim);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px}
.labour-summary-card .ls-name{font-size:13.5px;font-weight:700;color:var(--navy);margin-bottom:12px}
.labour-stat-row{display:flex;gap:16px}
.labour-stat{display:flex;flex-direction:column;gap:2px}
.labour-stat .val{font-family:'Sora',sans-serif;font-size:1.3rem;font-weight:800}
.labour-stat .lbl{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;color:var(--muted)}
.labour-stat.present .val{color:#1A9966}
.labour-stat.absent .val{color:#C0392B}

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
.status-badge.present{background:#E8F7EF;color:#1A9966}
.status-badge.absent{background:#FDE8E8;color:#C0392B}
.status-badge.half_day{background:#FFF7ED;color:#B7791F}

/* ── Nepali (Bikram Sambat) popup calendar in the edit modal —
   mirrors site/site.css since this page doesn't link that stylesheet ── */
.bs-date-label{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.4px;margin-bottom:4px;display:block;margin-top:10px}
.bs-datepicker-wrap{position:relative;max-width:220px}
.bs-display-input{padding-right:40px!important;cursor:pointer;background:var(--surface)}
.bs-calendar-popup{display:none;position:absolute;z-index:60;top:calc(100% + 6px);left:0;width:264px;max-width:calc(100vw - 32px);background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:0 12px 32px rgba(12,30,45,0.16);padding:12px}
.bs-calendar-popup.is-open{display:block}
.bs-cal-header{display:flex;align-items:center;gap:6px;margin-bottom:10px}
.bs-cal-nav{flex-shrink:0;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);cursor:pointer;font-size:11px}
.bs-cal-nav:hover{background:var(--blue-pale);color:var(--blue);border-color:var(--blue)}
.bs-cal-header select{flex:1;min-width:0;padding:6px 6px;font-size:12px}
.bs-cal-weekdays{display:grid;grid-template-columns:repeat(7,1fr);text-align:center;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px}
.bs-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.bs-cal-empty{height:32px}
.bs-cal-day{height:32px;border:none;border-radius:8px;background:transparent;color:var(--text);font-size:13px;font-weight:600;cursor:pointer;transition:background var(--t),color var(--t)}
.bs-cal-day:hover{background:var(--blue-pale);color:var(--blue)}
.bs-cal-day.is-selected{background:var(--blue);color:#fff}
</style>
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
    <a href="attendance_log.php" class="active">Attendance</a>
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <div>
      <h1>Attendance Log</h1>
      <p>Labour attendance recorded by site users, across all projects</p>
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
    <div class="card-title">Filters</div>
    <form class="log-filter-form" method="GET">
      <div class="form-group">
        <label>Project <span style="color:var(--danger)">*</span></label>
        <select name="project_id" required>
          <option value="">— Select a project —</option>
          <?php foreach ($projects as $p): ?>
          <option value="<?= htmlspecialchars($p['id']) ?>" <?= $filterProject === $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['title']) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label>Worker</label>
        <select name="worker_id">
          <option value="">All workers</option>
          <?php foreach ($workers as $w): ?>
          <option value="<?= $w['id'] ?>" <?= $filterWorker === (int)$w['id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['full_name']) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="">All statuses</option>
          <option value="present" <?= $filterStatus === 'present' ? 'selected' : '' ?>>Present</option>
          <option value="absent" <?= $filterStatus === 'absent' ? 'selected' : '' ?>>Absent</option>
          <option value="half_day" <?= $filterStatus === 'half_day' ? 'selected' : '' ?>>Half day</option>
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
      <a href="attendance_log.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
      <?php endif ?>

      <div class="quick-filters">
        <span class="quick-filters-label">Quick range:</span>
        <button type="button" class="quick-filter-btn<?= $quickActive === 'today' ? ' active' : '' ?>" onclick="quickDateFilter('today')">Today</button>
        <button type="button" class="quick-filter-btn<?= $quickActive === 'yesterday' ? ' active' : '' ?>" onclick="quickDateFilter('yesterday')">Yesterday</button>
        <button type="button" class="quick-filter-btn<?= $quickActive === 'week' ? ' active' : '' ?>" onclick="quickDateFilter('week')">Last 7 Days</button>
        <button type="button" class="quick-filter-btn<?= $quickActive === 'month' ? ' active' : '' ?>" onclick="quickDateFilter('month')">This Month</button>
      </div>
    </form>
  </div>

  <?php if ($filterProject === ''): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><i class="fa-solid fa-diagram-project"></i></div>
      <h3>Select a project to view its log</h3>
      <p>Choose a project above to see its attendance summary and records.</p>
    </div>
  </div>
  <?php else: ?>

  <div class="card">
    <div class="card-title">Summary</div>
    <div class="attendance-summary-grid">
      <div class="attendance-summary-box present">
        <div class="as-label"><i class="fa-solid fa-user-check"></i> Total Present Days</div>
        <span class="as-value"><?= $presentDays ?></span>
      </div>
      <div class="attendance-summary-box absent">
        <div class="as-label"><i class="fa-solid fa-user-xmark"></i> Total Absent Days</div>
        <span class="as-value"><?= $absentDays ?></span>
      </div>
      <div class="attendance-summary-box mandays">
        <div class="as-label"><i class="fa-solid fa-people-group"></i> Total Man-Days</div>
        <span class="as-value"><?= rtrim(rtrim(number_format($manDays, 1), '0'), '.') ?></span>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">By Worker</div>
    <?php if (empty($byWorker)): ?>
    <div class="empty"><p>No attendance recorded for this project<?= $hasFilters ? ' with these filters' : '' ?>.</p></div>
    <?php else: ?>
    <div class="labour-summary-grid">
      <?php foreach ($byWorker as $w): ?>
      <div class="labour-summary-card">
        <?php if ($w['category']): ?><div class="ls-category"><?= htmlspecialchars($w['category']) ?></div><?php endif ?>
        <div class="ls-name"><?= htmlspecialchars($w['full_name']) ?></div>
        <div class="labour-stat-row">
          <div class="labour-stat present"><span class="val"><?= $w['present'] ?></span><span class="lbl">Present</span></div>
          <div class="labour-stat absent"><span class="val"><?= $w['absent'] ?></span><span class="lbl">Absent</span></div>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>

  <div class="card">
    <div class="card-title">
      Records <small><?= count($history) ?> entr<?= count($history) === 1 ? 'y' : 'ies' ?><?= $hasFilters ? ' (filtered)' : '' ?><?= count($history) === 500 ? ' — showing most recent 500' : '' ?></small>
    </div>
    <?php if (empty($history)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fa-solid fa-clipboard-user"></i></div>
      <h3>No attendance records<?= $hasFilters ? ' match these filters' : ' yet' ?></h3>
    </div>
    <?php else: ?>
    <div class="site-table-wrap">
      <table class="site-table">
        <thead>
          <tr><th>Date</th><th>Nepali Date</th><th>Worker</th><th>Category</th><th>Status</th><th>User</th><th>Notes</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['attendance_date']) ?></td>
            <td><?= htmlspecialchars($h['nepali_date'] ?? '—') ?></td>
            <td><?= htmlspecialchars($h['worker_name']) ?></td>
            <td><?= htmlspecialchars($h['worker_category'] ?? '—') ?></td>
            <td><span class="status-badge <?= htmlspecialchars($h['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $h['status'])) ?></span></td>
            <td><?= htmlspecialchars($h['recorded_by_username'] ?? '—') ?></td>
            <td class="wrap"><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
            <td><button class="btn btn-ghost btn-sm" onclick="openEditAttendance(<?= (int)$h['id'] ?>)"><i class="fa-solid fa-pen"></i> Edit</button></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>
  </div>
  <?php endif ?>
  <?php endif ?>
</main>

<div class="toasts" id="toasts"></div>

<div class="mask" id="edit-mask" style="display:none">
  <div class="confirm-box" style="max-width:440px">
    <h3>Edit Attendance</h3>
    <div class="form-group">
      <label>Worker</label>
      <input type="text" id="edit-worker-display" disabled>
    </div>
    <div class="form-group">
      <label>Project</label>
      <input type="text" id="edit-project-display" disabled>
    </div>
    <div class="form-group">
      <label>Status</label>
      <select id="edit-status">
        <option value="present">Present</option>
        <option value="absent">Absent</option>
        <option value="half_day">Half day</option>
      </select>
    </div>
    <div class="form-group">
      <label>Date</label>
      <div class="date-input-wrap">
        <input type="text" id="edit-date-display" class="date-display-input" readonly placeholder="YYYY-MM-DD">
        <input type="date" id="edit-date" class="date-native-hidden">
        <i class="fa-solid fa-calendar-days date-input-icon"></i>
      </div>
      <span class="bs-date-label">Nepali date (B.S.)</span>
      <div id="edit-date-bs" class="bs-datepicker-wrap"></div>
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea id="edit-notes" rows="2"></textarea>
    </div>
    <div class="confirm-actions">
      <button class="btn btn-ghost btn-sm" onclick="closeEditMask()">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveAttendanceEdit()"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
    </div>
  </div>
</div>

<script src="../site/nepali-date.js"></script>
<script>
const CSRF = <?= json_encode($csrf) ?>;
let HISTORY = <?= json_encode(array_map(fn($h) => [
  'id' => (int)$h['id'], 'worker_name' => $h['worker_name'], 'project_title' => $h['project_title'],
  'status' => $h['status'], 'attendance_date' => $h['attendance_date'], 'notes' => $h['notes'],
], $history)) ?>;
let _editId = null;

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
setupDateField('edit-date-display', 'edit-date');

function toIsoDate(d) {
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function quickDateFilter(range) {
  const today = new Date();
  let from = today, to = today;
  if (range === 'today') {
    // from/to already default to today
  } else if (range === 'yesterday') {
    const y = new Date(today);
    y.setDate(y.getDate() - 1);
    from = to = y;
  } else if (range === 'week') {
    from = new Date(today);
    from.setDate(from.getDate() - 6);
  } else if (range === 'month') {
    from = new Date(today.getFullYear(), today.getMonth(), 1);
  } else {
    return;
  }
  const fromStr = toIsoDate(from), toStr = toIsoDate(to);
  document.getElementById('filter-from').value = fromStr;
  document.getElementById('filter-from-display').value = fromStr;
  document.getElementById('filter-to').value = toStr;
  document.getElementById('filter-to-display').value = toStr;
  document.querySelector('.log-filter-form').submit();
}

const editNepaliPicker = attachNepaliDatePicker({
  adNative:  document.getElementById('edit-date'),
  adDisplay: document.getElementById('edit-date-display'),
  wrapper:   document.getElementById('edit-date-bs'),
});

function openEditAttendance(id) {
  const row = HISTORY.find(h => h.id === id);
  if (!row) return;
  _editId = id;
  document.getElementById('edit-worker-display').value = row.worker_name;
  document.getElementById('edit-project-display').value = row.project_title;
  document.getElementById('edit-status').value = row.status;
  document.getElementById('edit-date').value = row.attendance_date;
  document.getElementById('edit-date-display').value = row.attendance_date;
  editNepaliPicker.setFromAdValue(row.attendance_date);
  document.getElementById('edit-notes').value = row.notes || '';
  document.getElementById('edit-mask').style.display = 'flex';
}

function closeEditMask() {
  document.getElementById('edit-mask').style.display = 'none';
  _editId = null;
}

async function saveAttendanceEdit() {
  if (!_editId) return;
  const status = document.getElementById('edit-status').value;
  const date   = document.getElementById('edit-date').value;
  const notes  = document.getElementById('edit-notes').value.trim();

  if (!date) { toast('Pick a date.', 'err'); return; }

  const body = new URLSearchParams({ action: 'update_attendance', attendance_id: _editId, status, date, notes, csrf_token: CSRF });
  const res  = await fetch('api.php', { method: 'POST', body });
  const r    = await res.json();

  if (r.success) {
    toast('Attendance updated.', 'ok');
    setTimeout(() => location.reload(), 600);
  } else {
    toast(r.error || 'Failed to update attendance.', 'err');
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