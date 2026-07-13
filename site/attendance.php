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

$date = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$csrf = siteCsrfToken();

// Active worker roster
$workers = db()->query('SELECT id, full_name, category FROM workers WHERE is_active = TRUE ORDER BY full_name')->fetchAll();

// Existing statuses for this project + date
$stmt = db()->prepare('SELECT worker_id, status FROM labour_attendance WHERE project_id = :pid AND attendance_date = :date');
$stmt->execute(['pid' => $projectId, 'date' => $date]);
$existing = array_column($stmt->fetchAll(), 'status', 'worker_id');

// Recent history for this project (last 20 days worth of entries)
$stmt = db()->prepare(
    'SELECT la.attendance_date, w.full_name, w.category, la.status
     FROM labour_attendance la
     JOIN workers w ON w.id = la.worker_id
     WHERE la.project_id = :pid
     ORDER BY la.attendance_date DESC, w.full_name
     LIMIT 100'
);
$stmt->execute(['pid' => $projectId]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance — <?= htmlspecialchars($projectTitle) ?></title>
<link rel="stylesheet" href="../admin/cms.css">
<link rel="stylesheet" href="site.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body<?= !empty($workers) ? ' class="has-mobile-save-bar"' : '' ?>>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">
    JEIWS <span>SITE</span>
  </a>
  <div class="cms-nav-right">
    <span class="site-welcome">Hi, <?= htmlspecialchars($_SESSION['site_user_name']) ?></span>
    <a href="change_password.php"><i class="fa-solid fa-key"></i> Password</a>
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
      <h1>Attendance</h1>
      <p><?= htmlspecialchars($projectTitle) ?></p>
    </div>
  </div>

  <div class="site-tabs">
    <a href="attendance.php?project=<?= urlencode($projectId) ?>" class="site-tab active"><i class="fa-solid fa-clipboard-user"></i> Attendance</a>
    <a href="stock.php?project=<?= urlencode($projectId) ?>" class="site-tab"><i class="fa-solid fa-boxes-stacked"></i> Materials</a>
  </div>

  <div class="card">
    <div class="card-title">
      Mark Attendance
      <small id="save-status"></small>
    </div>

    <form class="site-date-row" id="date-form" method="GET">
      <input type="hidden" name="project" value="<?= htmlspecialchars($projectId) ?>">
      <div class="form-group">
        <label>Date</label>
        <div class="date-input-wrap">
          <input type="text" id="att-date-display" class="date-display-input" readonly placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($date) ?>">
          <input type="date" name="date" id="att-date-native" class="date-native-hidden" value="<?= htmlspecialchars($date) ?>" onchange="this.form.submit()">
          <i class="fa-solid fa-calendar-days date-input-icon"></i>
        </div>
      </div>
    </form>

    <?php if (empty($workers)): ?>
    <div class="empty">
      <div class="empty-icon"><i class="fa-regular fa-user"></i></div>
      <h3>No workers in the roster yet</h3>
      <p>Add your first worker below.</p>
    </div>
    <?php else: ?>
    <div id="worker-list">
      <?php foreach ($workers as $w):
        $status = $existing[$w['id']] ?? 'present';
      ?>
      <div class="worker-row">
        <div class="worker-row-info">
          <div class="worker-row-name"><?= htmlspecialchars($w['full_name']) ?></div>
          <?php if ($w['category']): ?><div class="worker-row-meta"><?= htmlspecialchars($w['category']) ?></div><?php endif ?>
        </div>
        <select class="status-<?= $status ?>" data-worker-id="<?= $w['id'] ?>" onchange="this.className='status-'+this.value">
          <option value="present" <?= $status === 'present' ? 'selected' : '' ?>>Present</option>
          <option value="absent" <?= $status === 'absent' ? 'selected' : '' ?>>Absent</option>
          <option value="half_day" <?= $status === 'half_day' ? 'selected' : '' ?>>Half day</option>
        </select>
      </div>
      <?php endforeach ?>
    </div>
    <button class="btn btn-primary" id="save-btn" style="margin-top:18px" onclick="saveAttendance()">
      <i class="fa-solid fa-floppy-disk"></i> Save Attendance
    </button>
    <?php endif ?>
  </div>

  <div class="card collapsible">
    <button type="button" class="card-title collapsible-toggle" onclick="toggleCollapse(this)">
      Add Worker to Roster
      <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    <div class="collapsible-body">
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
        <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-plus"></i> Add</button>
      </form>
    </div>
  </div>

  <div class="card collapsible">
    <button type="button" class="card-title collapsible-toggle" onclick="toggleCollapse(this)">
      <span>Recent Attendance <small><?= count($history) ?> entries</small></span>
      <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    <div class="collapsible-body">
      <?php if (empty($history)): ?>
      <div class="empty"><p>No attendance recorded yet for this project.</p></div>
      <?php else: ?>
      <div class="site-table-wrap">
        <table class="site-table">
          <thead><tr><th>Date</th><th>Worker</th><th>Category</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= htmlspecialchars($h['attendance_date']) ?></td>
              <td><?= htmlspecialchars($h['full_name']) ?></td>
              <td><?= htmlspecialchars($h['category'] ?? '—') ?></td>
              <td><span class="status-badge <?= htmlspecialchars($h['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $h['status'])) ?></span></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php endif ?>
    </div>
  </div>
</main>

<?php if (!empty($workers)): ?>
<div class="mobile-save-bar">
  <button class="btn btn-primary" onclick="saveAttendance()">
    <i class="fa-solid fa-floppy-disk"></i> Save Attendance
  </button>
</div>
<?php endif ?>

<div class="toasts" id="toasts"></div>

<script src="site.js"></script>
<script>
const CSRF      = <?= json_encode($csrf) ?>;
const PROJECT   = <?= json_encode($projectId) ?>;
const DATE      = <?= json_encode($date) ?>;

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
setupDateField('att-date-display', 'att-date-native');

async function saveAttendance() {
  const btn = document.getElementById('save-btn');
  btn.disabled = true;
  const data = { action: 'mark_attendance_bulk', project_id: PROJECT, date: DATE };
  document.querySelectorAll('#worker-list select').forEach(sel => {
    data['status[' + sel.dataset.workerId + ']'] = sel.value;
  });
  const r = await post(data);
  btn.disabled = false;
  if (r.success) {
    toast(`Saved attendance for ${r.saved} worker(s).`, 'ok');
    setTimeout(() => location.reload(), 700);
  } else {
    toast(r.error || 'Save failed.', 'err');
  }
}

async function addWorker(e) {
  e.preventDefault();
  const full_name = document.getElementById('new-worker-name').value.trim();
  const category  = document.getElementById('new-worker-category').value.trim();
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
