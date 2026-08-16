<?php
session_start();
header('Content-Type: application/json');
define('SITE_LOADED', 1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../lib/NepaliDate.php';

if (empty($_SESSION['site_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

verifySiteCsrf();

$userId = currentSiteUserId();
$action = $_POST['action'] ?? '';

switch ($action) {

    /* ── Save a day's attendance for a project (bulk) ───── */
    case 'mark_attendance_bulk': {
        $projectId = trim($_POST['project_id'] ?? '');
        $date      = trim($_POST['date'] ?? '');
        $statuses  = $_POST['status'] ?? []; // [worker_id => status]

        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { ok_err('Invalid date'); }
        if (!is_array($statuses) || !$statuses) { ok_err('No attendance data submitted'); }

        $allowed = ['present', 'absent', 'half_day'];
        $nepaliDate = NepaliDate::adToBs($date);

        // Worker names for everyone being submitted, to check for
        // duplicates by name (not just worker_id — see normalizeWorkerName()).
        $workerIds = array_values(array_unique(array_map('intval', array_keys($statuses))));
        $namesById = [];
        if ($workerIds) {
            $ph = implode(',', array_fill(0, count($workerIds), '?'));
            $stmt = db()->prepare("SELECT id, full_name FROM workers WHERE id IN ($ph)");
            $stmt->execute($workerIds);
            $namesById = array_column($stmt->fetchAll(), 'full_name', 'id');
        }

        // Existing records for this project + date, by normalized name, so
        // a worker entered twice under two different worker_ids doesn't
        // silently produce two attendance rows for the same day.
        $stmt = db()->prepare(
            'SELECT la.worker_id, w.full_name
             FROM labour_attendance la
             JOIN workers w ON w.id = la.worker_id
             WHERE la.project_id = :pid AND la.attendance_date = :date'
        );
        $stmt->execute(['pid' => $projectId, 'date' => $date]);
        $existingByName = [];
        foreach ($stmt->fetchAll() as $row) {
            $existingByName[normalizeWorkerName($row['full_name'])] = (int)$row['worker_id'];
        }

        $insertStmt = db()->prepare(
            'INSERT INTO labour_attendance (project_id, worker_id, attendance_date, nepali_date, status, recorded_by)
             VALUES (:pid, :wid, :date, :ndate, :status, :uid)
             ON DUPLICATE KEY UPDATE status = VALUES(status), nepali_date = VALUES(nepali_date), recorded_by = VALUES(recorded_by)'
        );
        // Absent workers are never stored — only present/half_day rows exist
        // in labour_attendance. If a worker already has a row for this date
        // (e.g. previously marked present) and is now being corrected to
        // absent, remove that row rather than leaving stale data behind.
        $deleteStmt = db()->prepare(
            'DELETE FROM labour_attendance WHERE project_id = :pid AND worker_id = :wid AND attendance_date = :date'
        );

        $saved = 0;
        $duplicates = [];
        foreach ($statuses as $workerId => $status) {
            $workerId = (int)$workerId;
            if ($workerId <= 0 || !in_array($status, $allowed, true)) continue;

            if ($status === 'absent') {
                $deleteStmt->execute(['pid' => $projectId, 'wid' => $workerId, 'date' => $date]);
                continue;
            }

            $name = $namesById[$workerId] ?? null;
            if ($name !== null) {
                $norm = normalizeWorkerName($name);
                if (isset($existingByName[$norm]) && $existingByName[$norm] !== $workerId) {
                    $duplicates[] = $name;
                    continue; // same person already recorded today under a different worker_id — skip, don't double up
                }
            }

            $insertStmt->execute(['pid' => $projectId, 'wid' => $workerId, 'date' => $date, 'ndate' => $nepaliDate, 'status' => $status, 'uid' => $userId]);
            $saved++;
            if ($name !== null) $existingByName[normalizeWorkerName($name)] = $workerId;
        }

        echo json_encode(['success' => true, 'saved' => $saved, 'duplicates' => array_values(array_unique($duplicates))]);
        break;
    }

    /* ── Add a brand-new worker to this project's roster ──── */
    case 'add_worker': {
        $projectId = trim($_POST['project_id'] ?? '');
        $fullName  = trim($_POST['full_name'] ?? '');
        $category  = trim($_POST['category']  ?? '');
        $dailyWage = trim($_POST['daily_wage'] ?? '');
        $phone     = trim($_POST['phone']      ?? '');

        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if ($fullName === '') { ok_err('Worker name is required'); }

        ensureWorkerProjectsTable();
        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO workers (full_name, category, daily_wage, phone)
             VALUES (:name, :cat, :wage, :phone)'
        );
        $stmt->execute([
            'name'  => $fullName,
            'cat'   => $category ?: null,
            'wage'  => $dailyWage !== '' ? $dailyWage : null,
            'phone' => $phone ?: null,
        ]);
        $workerId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO worker_projects (worker_id, project_id) VALUES (:wid, :pid)')
            ->execute(['wid' => $workerId, 'pid' => $projectId]);

        echo json_encode(['success' => true, 'worker_id' => $workerId]);
        break;
    }

    /* ── List workers already on OTHER projects, for "add existing worker" ── */
    case 'list_unassigned_workers': {
        $projectId = trim($_POST['project_id'] ?? '');
        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }

        ensureWorkersDeletedColumn();
        ensureWorkerProjectsTable();
        $stmt = db()->prepare(
            'SELECT w.id, w.full_name, w.category
             FROM workers w
             WHERE w.is_deleted = 0
               AND w.id NOT IN (SELECT worker_id FROM worker_projects WHERE project_id = :pid)
             ORDER BY w.full_name'
        );
        $stmt->execute(['pid' => $projectId]);
        echo json_encode(['success' => true, 'workers' => $stmt->fetchAll()]);
        break;
    }

    /* ── Assign an existing worker (from another project) to this one ── */
    case 'assign_worker': {
        $projectId = trim($_POST['project_id'] ?? '');
        $workerId  = (int)($_POST['worker_id'] ?? 0);
        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if ($workerId <= 0) { ok_err('Invalid worker'); }

        ensureWorkerProjectsTable();
        $pdo = db();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM workers WHERE id = :id AND is_deleted = 0');
        $exists->execute(['id' => $workerId]);
        if ((int)$exists->fetchColumn() === 0) { ok_err('Worker not found'); }

        $pdo->prepare(
            'INSERT INTO worker_projects (worker_id, project_id) VALUES (:wid, :pid)
             ON DUPLICATE KEY UPDATE is_active = 1'
        )->execute(['wid' => $workerId, 'pid' => $projectId]);

        echo json_encode(['success' => true]);
        break;
    }

    /* ── Show/hide a worker from this project's attendance list ── */
    case 'toggle_worker_active': {
        $projectId = trim($_POST['project_id'] ?? '');
        $workerId  = (int)($_POST['worker_id'] ?? 0);
        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if ($workerId <= 0) { ok_err('Invalid worker'); }

        ensureWorkerProjectsTable();
        $stmt = db()->prepare('UPDATE worker_projects SET is_active = NOT is_active WHERE worker_id = :wid AND project_id = :pid');
        $stmt->execute(['wid' => $workerId, 'pid' => $projectId]);
        if ($stmt->rowCount() === 0) { ok_err('Worker not found on this project'); }

        $isActive = db()->prepare('SELECT is_active FROM worker_projects WHERE worker_id = :wid AND project_id = :pid');
        $isActive->execute(['wid' => $workerId, 'pid' => $projectId]);

        echo json_encode(['success' => true, 'is_active' => (bool)$isActive->fetchColumn()]);
        break;
    }

    /* ── Remove a worker from this project's roster only ──── */
    case 'delete_worker': {
        $projectId = trim($_POST['project_id'] ?? '');
        $workerId  = (int)($_POST['worker_id'] ?? 0);
        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if ($workerId <= 0) { ok_err('Invalid worker'); }

        // Unassigns the worker from this project only — their global
        // identity and every attendance record (here and on any other
        // project) are completely untouched.
        ensureWorkerProjectsTable();
        db()->prepare('DELETE FROM worker_projects WHERE worker_id = :wid AND project_id = :pid')
            ->execute(['wid' => $workerId, 'pid' => $projectId]);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Log a stock movement (IN / OUT) ─────────────────── */
    case 'log_stock': {
        $projectId  = trim($_POST['project_id']  ?? '');
        $materialId = (int)($_POST['material_id'] ?? 0);
        $txnType    = trim($_POST['txn_type']     ?? '');
        $quantity   = trim($_POST['quantity']     ?? '');
        $bundleQty  = trim($_POST['bundle_qty']   ?? '');
        $date       = trim($_POST['date']         ?? '');
        $notes      = trim($_POST['notes']        ?? '');

        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if ($materialId <= 0) { ok_err('Select a material'); }
        if (!in_array($txnType, ['in', 'out'], true)) { ok_err('Invalid transaction type'); }
        if (!is_numeric($quantity) || (float)$quantity <= 0) { ok_err('Quantity must be a positive number'); }
        if ($bundleQty !== '' && (!is_numeric($bundleQty) || (float)$bundleQty <= 0)) { ok_err('Bundles must be a positive number'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { ok_err('Invalid date'); }

        db()->prepare(
            'INSERT INTO materials_stock (project_id, material_id, txn_type, quantity, bundle_qty, txn_date, nepali_date, notes, recorded_by)
             VALUES (:pid, :mid, :type, :qty, :bqty, :date, :ndate, :notes, :uid)'
        )->execute([
            'pid' => $projectId, 'mid' => $materialId, 'type' => $txnType, 'qty' => $quantity,
            'bqty' => $bundleQty !== '' ? $bundleQty : null,
            'date' => $date, 'ndate' => NepaliDate::adToBs($date), 'notes' => $notes ?: null, 'uid' => $userId,
        ]);

        echo json_encode(['success' => true]);
        break;
    }

    /* ── Update an existing stock movement ───────────────── */
    case 'update_stock': {
        $stockId    = (int)($_POST['stock_id']    ?? 0);
        $projectId  = trim($_POST['project_id']   ?? '');
        $materialId = (int)($_POST['material_id'] ?? 0);
        $txnType    = trim($_POST['txn_type']     ?? '');
        $quantity   = trim($_POST['quantity']     ?? '');
        $bundleQty  = trim($_POST['bundle_qty']   ?? '');
        $date       = trim($_POST['date']         ?? '');
        $notes      = trim($_POST['notes']        ?? '');

        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if ($stockId <= 0) { ok_err('Invalid transaction'); }
        if ($materialId <= 0) { ok_err('Select a material'); }
        if (!in_array($txnType, ['in', 'out'], true)) { ok_err('Invalid transaction type'); }
        if (!is_numeric($quantity) || (float)$quantity <= 0) { ok_err('Quantity must be a positive number'); }
        if ($bundleQty !== '' && (!is_numeric($bundleQty) || (float)$bundleQty <= 0)) { ok_err('Bundles must be a positive number'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { ok_err('Invalid date'); }

        // Scope the UPDATE to this project so a tampered stock_id can't touch another project's log
        $stmt = db()->prepare(
            'UPDATE materials_stock
                SET material_id = :mid, txn_type = :type, quantity = :qty, bundle_qty = :bqty, txn_date = :date, nepali_date = :ndate, notes = :notes
              WHERE id = :sid AND project_id = :pid'
        );
        $stmt->execute([
            'mid' => $materialId, 'type' => $txnType, 'qty' => $quantity,
            'bqty' => $bundleQty !== '' ? $bundleQty : null,
            'date' => $date, 'ndate' => NepaliDate::adToBs($date), 'notes' => $notes ?: null, 'sid' => $stockId, 'pid' => $projectId,
        ]);

        if ($stmt->rowCount() === 0) { ok_err('Transaction not found for this project'); }

        echo json_encode(['success' => true]);
        break;
    }

    /* ── Fetch (filtered) transaction history for a project ─ */
    case 'get_stock_history': {
        $projectId  = trim($_POST['project_id']   ?? '');
        $materialId = (int)($_POST['material_id'] ?? 0);
        $txnType    = trim($_POST['txn_type']     ?? '');
        $dateFrom   = trim($_POST['date_from']    ?? '');
        $dateTo     = trim($_POST['date_to']      ?? '');
        if (!in_array($txnType, ['in', 'out'], true)) $txnType = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }

        $where  = ['ms.project_id = :pid'];
        $params = ['pid' => $projectId];
        if ($materialId > 0)  { $where[] = 'ms.material_id = :mid'; $params['mid']   = $materialId; }
        if ($txnType !== '')  { $where[] = 'ms.txn_type = :type';   $params['type']  = $txnType; }
        if ($dateFrom !== '') { $where[] = 'ms.txn_date >= :dfrom'; $params['dfrom'] = $dateFrom; }
        if ($dateTo !== '')   { $where[] = 'ms.txn_date <= :dto';   $params['dto']   = $dateTo; }

        $stmt = db()->prepare(
            'SELECT ms.id, ms.material_id, ms.txn_date, ms.nepali_date, m.name, m.unit, m.category, ms.txn_type, ms.quantity, ms.bundle_qty, ms.notes,
                    u.username AS recorded_by_username
             FROM materials_stock ms
             JOIN materials m ON m.id = ms.material_id
             LEFT JOIN site_users u ON u.id = ms.recorded_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ms.txn_date DESC, ms.id DESC
             LIMIT 200'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        echo json_encode(['success' => true, 'rows' => $rows, 'totals' => computeStockTotals($rows)]);
        break;
    }

    /* ══ Read-only endpoints added for the mobile app — purely additive,
       mirror what the PHP pages already compute server-side, don't touch
       or change any existing action/page. ══ */

    /* ── Projects assigned to the current site user ──────── */
    case 'list_projects': {
        echo json_encode(['success' => true, 'projects' => getAssignedProjects($userId)]);
        break;
    }

    /* ── Everything the Attendance screen needs for one project+date ── */
    case 'get_attendance_page': {
        $projectId = trim($_POST['project_id'] ?? '');
        $date      = trim($_POST['date'] ?? '');
        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $pdo = db();
        ensureWorkersDeletedColumn();
        ensureWorkerProjectsTable();

        $stmt = $pdo->prepare(
            'SELECT w.id, w.full_name, w.category
             FROM workers w
             JOIN worker_projects wp ON wp.worker_id = w.id AND wp.project_id = :pid
             WHERE w.is_deleted = 0 AND wp.is_active = 1
             ORDER BY w.full_name'
        );
        $stmt->execute(['pid' => $projectId]);
        $workers = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT w.id, w.full_name, w.category, wp.is_active, COUNT(la.id) AS attendance_count
             FROM workers w
             JOIN worker_projects wp ON wp.worker_id = w.id AND wp.project_id = :pid
             LEFT JOIN labour_attendance la ON la.worker_id = w.id AND la.project_id = :pid2
             WHERE w.is_deleted = 0
             GROUP BY w.id, w.full_name, w.category, wp.is_active
             ORDER BY w.full_name'
        );
        $stmt->execute(['pid' => $projectId, 'pid2' => $projectId]);
        $roster = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT worker_id, status FROM labour_attendance WHERE project_id = :pid AND attendance_date = :date');
        $stmt->execute(['pid' => $projectId, 'date' => $date]);
        $existing = array_column($stmt->fetchAll(), 'status', 'worker_id');

        $stmt = $pdo->prepare(
            'SELECT la.id, la.attendance_date, la.nepali_date, w.full_name, w.category, la.status
             FROM labour_attendance la
             JOIN workers w ON w.id = la.worker_id
             WHERE la.project_id = :pid
             ORDER BY la.attendance_date DESC, w.full_name
             LIMIT 100'
        );
        $stmt->execute(['pid' => $projectId]);
        $history = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM labour_attendance WHERE project_id = :pid GROUP BY status');
        $stmt->execute(['pid' => $projectId]);
        $statusCounts = array_column($stmt->fetchAll(), 'cnt', 'status');
        $presentDays  = (int)($statusCounts['present']  ?? 0);
        $absentDays   = (int)($statusCounts['absent']   ?? 0);
        $halfDays     = (int)($statusCounts['half_day'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT w.id AS worker_id, w.full_name, w.category, la.status, COUNT(*) AS cnt
             FROM labour_attendance la
             JOIN workers w ON w.id = la.worker_id
             WHERE la.project_id = :pid
             GROUP BY w.id, w.full_name, w.category, la.status'
        );
        $stmt->execute(['pid' => $projectId]);
        $byWorker = [];
        foreach ($stmt->fetchAll() as $row) {
            $wid = $row['worker_id'];
            if (!isset($byWorker[$wid])) {
                $byWorker[$wid] = ['worker_id' => (int)$wid, 'full_name' => $row['full_name'], 'category' => $row['category'], 'present' => 0, 'absent' => 0, 'half_day' => 0];
            }
            $byWorker[$wid][$row['status']] = (int)$row['cnt'];
        }
        $byWorker = array_values($byWorker);
        usort($byWorker, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));

        echo json_encode([
            'success'  => true,
            'workers'  => $workers,
            'roster'   => $roster,
            'existing' => (object)$existing,
            'history'  => $history,
            'summary'  => ['present' => $presentDays, 'absent' => $absentDays, 'half_day' => $halfDays, 'man_days' => $presentDays + $halfDays * 0.5],
            'by_worker' => $byWorker,
        ]);
        break;
    }

    /* ── Everything the Materials Stock screen needs for one project ── */
    case 'get_stock_page': {
        $projectId = trim($_POST['project_id'] ?? '');
        if (!$projectId || !userCanAccessProject($userId, $projectId)) { ok_err('Project not found or not assigned to you'); }

        $pdo = db();

        $materials = $pdo->query(
            "SELECT id, name, unit, category FROM materials WHERE is_active = TRUE
             ORDER BY (category IS NULL), category, name"
        )->fetchAll();

        $stmt = $pdo->prepare(
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

        echo json_encode(['success' => true, 'materials' => $materials, 'balances' => $balances]);
        break;
    }

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function ok_err(string $msg): void {
    echo json_encode(['error' => $msg]); exit;
}
