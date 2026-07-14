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
        $stmt = db()->prepare(
            'INSERT INTO labour_attendance (project_id, worker_id, attendance_date, nepali_date, status, recorded_by)
             VALUES (:pid, :wid, :date, :ndate, :status, :uid)
             ON DUPLICATE KEY UPDATE status = VALUES(status), nepali_date = VALUES(nepali_date), recorded_by = VALUES(recorded_by)'
        );

        $saved = 0;
        foreach ($statuses as $workerId => $status) {
            $workerId = (int)$workerId;
            if ($workerId <= 0 || !in_array($status, $allowed, true)) continue;
            $stmt->execute(['pid' => $projectId, 'wid' => $workerId, 'date' => $date, 'ndate' => $nepaliDate, 'status' => $status, 'uid' => $userId]);
            $saved++;
        }

        echo json_encode(['success' => true, 'saved' => $saved]);
        break;
    }

    /* ── Add a worker to the global roster ──────────────── */
    case 'add_worker': {
        $fullName  = trim($_POST['full_name'] ?? '');
        $category  = trim($_POST['category']  ?? '');
        $dailyWage = trim($_POST['daily_wage'] ?? '');
        $phone     = trim($_POST['phone']      ?? '');

        if ($fullName === '') { ok_err('Worker name is required'); }

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

        echo json_encode(['success' => true, 'worker_id' => $pdo->lastInsertId()]);
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

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function ok_err(string $msg): void {
    echo json_encode(['error' => $msg]); exit;
}
