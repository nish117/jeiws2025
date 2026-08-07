<?php
session_start();
header('Content-Type: application/json');

define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';

if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../lib/NepaliDate.php';

// All mutating requests must carry a valid CSRF token
verifyCsrf();

$action = $_POST['action'] ?? '';

switch ($action) {

    /* ── Create or update project details ───────── */
    case 'save_project': {
        $id    = parseId($_POST['project_id'] ?? '');
        $title = trim($_POST['title']       ?? '');
        $desc  = trim($_POST['description'] ?? '');

        if (!$title) { ok_err('Title is required'); }

        $projects = loadProjects();
        $idx      = findProject($projects, $id);

        $isDraft = isset($_POST['is_draft']) && $_POST['is_draft'] === '1';

        if ($idx === -1) {
            // New project — always generate ID server-side
            $id  = generateId($projects);
            $dir = IMG_BASE . '/' . $id;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $projects[] = ['id' => $id, 'title' => $title, 'description' => $desc, 'image' => '', 'gallery' => [], 'is_draft' => $isDraft];
        } else {
            $projects[$idx]['title']       = $title;
            $projects[$idx]['description'] = $desc;
            $projects[$idx]['is_draft']    = $isDraft;
        }

        saveProjects($projects);
        syncProjectToDb($id, $title, !$isDraft);
        echo json_encode(['success' => true, 'project_id' => $id]);
        break;
    }

    /* ── Upload a photo ─────────────────────────── */
    case 'upload_photo': {
        $id = parseId($_POST['project_id'] ?? '');
        if (!$id) { ok_err('Invalid project ID'); }

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            ok_err('Upload error code: ' . ($_FILES['photo']['error'] ?? 'no file'));
        }

        $file = $_FILES['photo'];

        if ($file['size'] > 10 * 1024 * 1024) { ok_err('File exceeds 10 MB limit'); }

        // Validate real MIME type
        $mime = detectImageMimeType($file['tmp_name']);

        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extMap[$mime])) { ok_err('Invalid file type — use JPEG, PNG or WebP'); }

        $imgDir = IMG_BASE . '/' . $id;
        if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);

        $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
        $dest     = $imgDir . '/' . $filename;

        if (!processImage($file['tmp_name'], $dest, $mime)) { ok_err('Could not process image'); }

        $urlPath  = IMG_URL . '/' . $id . '/' . $filename;
        $projects = loadProjects();
        $idx      = findProject($projects, $id);

        if ($idx === -1) { ok_err('Project not found'); }

        $projects[$idx]['gallery'][] = $urlPath;
        $isFirst = empty($projects[$idx]['image']);
        if ($isFirst) $projects[$idx]['image'] = $urlPath;

        saveProjects($projects);
        $savedKb = round(filesize($dest) / 1024);
        echo json_encode(['success' => true, 'path' => $urlPath, 'is_first_image' => $isFirst, 'saved_kb' => $savedKb]);
        break;
    }

    /* ── Delete a photo ─────────────────────────── */
    case 'delete_photo': {
        $id    = parseId($_POST['project_id'] ?? '');
        $photo = safePath($_POST['photo'] ?? '');

        if (!$id || !$photo) { ok_err('Invalid request'); }

        $projects = loadProjects();
        $idx      = findProject($projects, $id);
        if ($idx === -1) { ok_err('Project not found'); }

        $p       = &$projects[$idx];
        $p['gallery'] = array_values(array_filter($p['gallery'], fn($g) => $g !== $photo));

        // If deleted image was the main, promote first gallery image
        if ($p['image'] === $photo) {
            $p['image'] = $p['gallery'][0] ?? '';
        }
        unset($p);

        // Remove file from disk
        $filePath = realpath(__DIR__ . '/../' . $photo);
        $baseDir  = realpath(IMG_BASE);
        if ($filePath && $baseDir && strncmp($filePath, $baseDir, strlen($baseDir)) === 0 && is_file($filePath)) {
            unlink($filePath);
        }

        saveProjects($projects);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Set main / featured image ──────────────── */
    case 'set_main_image': {
        $id    = parseId($_POST['project_id'] ?? '');
        $photo = safePath($_POST['photo'] ?? '');

        if (!$id || !$photo) { ok_err('Invalid request'); }

        $projects = loadProjects();
        $idx      = findProject($projects, $id);
        if ($idx === -1) { ok_err('Project not found'); }

        // Only allow photos that are already in this project's gallery
        if (!in_array($photo, $projects[$idx]['gallery'], true)) {
            ok_err('Photo does not belong to this project');
        }

        $projects[$idx]['image'] = $photo;
        saveProjects($projects);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Toggle individual image publish state ──── */
    case 'toggle_image_publish': {
        $id    = parseId($_POST['project_id'] ?? '');
        $photo = safePath($_POST['photo'] ?? '');

        if (!$id || !$photo) { ok_err('Invalid request'); }

        $projects = loadProjects();
        $idx      = findProject($projects, $id);
        if ($idx === -1) { ok_err('Project not found'); }

        if (!in_array($photo, $projects[$idx]['gallery'], true)) {
            ok_err('Photo not in this project');
        }

        $unpublished    = $projects[$idx]['unpublished_images'] ?? [];
        $wasUnpublished = in_array($photo, $unpublished, true);

        if ($wasUnpublished) {
            $unpublished  = array_values(array_filter($unpublished, fn($p) => $p !== $photo));
            $nowPublished = true;
        } else {
            $unpublished[] = $photo;
            $nowPublished  = false;
        }

        $projects[$idx]['unpublished_images'] = $unpublished;
        saveProjects($projects);
        echo json_encode(['success' => true, 'published' => $nowPublished]);
        break;
    }

    /* ── Delete entire project ──────────────────── */
    case 'delete_project': {
        $id = parseId($_POST['project_id'] ?? '');
        if (!$id) { ok_err('Invalid ID'); }

        $projects = loadProjects();
        $projects = array_values(array_filter($projects, fn($p) => $p['id'] !== $id));

        // Delete image directory
        deleteDir(IMG_BASE . '/' . $id);

        saveProjects($projects);
        removeProjectFromDb($id);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Reorder gallery ────────────────────────── */
    case 'reorder': {
        $id    = parseId($_POST['project_id'] ?? '');
        $order = $_POST['order'] ?? [];   // array of photo paths

        if (!$id || !is_array($order)) { ok_err('Invalid request'); }

        $projects = loadProjects();
        $idx      = findProject($projects, $id);
        if ($idx === -1) { ok_err('Project not found'); }

        $existing = $projects[$idx]['gallery'];
        $clean    = array_values(array_intersect($order, $existing));
        // Append any photos not included in the new order
        $rest     = array_values(array_diff($existing, $clean));
        $projects[$idx]['gallery'] = array_merge($clean, $rest);

        saveProjects($projects);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Create or update a site user ───────────── */
    case 'save_site_user': {
        $userId   = trim($_POST['user_id'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $isActive = (isset($_POST['is_active']) && $_POST['is_active'] === '1') ? 1 : 0;

        if (!$fullName || !$username) { ok_err('Full name and username are required'); }

        try {
            if ($userId === '') {
                if (strlen($password) < 8) { ok_err('Password must be at least 8 characters'); }
                $pdo  = db();
                $stmt = $pdo->prepare(
                    'INSERT INTO site_users (username, password_hash, full_name, is_active)
                     VALUES (:u, :p, :f, 1)'
                );
                $stmt->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_BCRYPT), 'f' => $fullName]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'user_id' => $newId]);
            } else {
                if ($password !== '') {
                    if (strlen($password) < 8) { ok_err('Password must be at least 8 characters'); }
                    db()->prepare(
                        'UPDATE site_users SET username = :u, full_name = :f, is_active = :a,
                                                password_hash = :p, updated_at = NOW() WHERE id = :id'
                    )->execute(['u' => $username, 'f' => $fullName, 'a' => $isActive,
                                 'p' => password_hash($password, PASSWORD_BCRYPT), 'id' => $userId]);
                } else {
                    db()->prepare(
                        'UPDATE site_users SET username = :u, full_name = :f, is_active = :a,
                                                updated_at = NOW() WHERE id = :id'
                    )->execute(['u' => $username, 'f' => $fullName, 'a' => $isActive, 'id' => $userId]);
                }
                echo json_encode(['success' => true, 'user_id' => (int)$userId]);
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) { ok_err('That username is already taken'); }
            throw $e;
        }
        break;
    }

    /* ── Set a user's project assignments ───────── */
    case 'set_user_projects': {
        $userId     = trim($_POST['user_id'] ?? '');
        $projectIds = $_POST['project_ids'] ?? [];
        if (!$userId) { ok_err('Invalid user'); }
        if (!is_array($projectIds)) { $projectIds = []; }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_projects WHERE user_id = :uid')->execute(['uid' => $userId]);
            $ins = $pdo->prepare('INSERT IGNORE INTO user_projects (user_id, project_id) VALUES (:uid, :pid)');
            foreach ($projectIds as $pid) {
                $pid = trim((string)$pid);
                if ($pid === '') continue;
                $ins->execute(['uid' => $userId, 'pid' => $pid]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            ok_err('One or more selected projects are not yet synced — try again in a moment');
        }

        echo json_encode(['success' => true]);
        break;
    }

    /* ── Delete a site user ──────────────────────── */
    case 'delete_site_user': {
        $userId = trim($_POST['user_id'] ?? '');
        if (!$userId) { ok_err('Invalid user'); }
        db()->prepare('DELETE FROM site_users WHERE id = :id')->execute(['id' => $userId]);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Add a material to the global catalog ────── */
    case 'save_material': {
        $name     = trim($_POST['name'] ?? '');
        $unit     = trim($_POST['unit'] ?? '');
        $category = trim($_POST['category'] ?? '');

        if ($name === '' || $unit === '') { ok_err('Material name and unit are required'); }

        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO materials (name, unit, category) VALUES (:name, :unit, :cat)
             ON DUPLICATE KEY UPDATE category = VALUES(category), id = LAST_INSERT_ID(id)'
        );
        $stmt->execute(['name' => $name, 'unit' => $unit, 'cat' => $category ?: null]);

        echo json_encode(['success' => true, 'material_id' => $pdo->lastInsertId()]);
        break;
    }

    /* ── Show/hide a material from the site portal ── */
    case 'toggle_material_active': {
        $materialId = (int)($_POST['material_id'] ?? 0);
        if ($materialId <= 0) { ok_err('Invalid material'); }

        $stmt = db()->prepare('UPDATE materials SET is_active = NOT is_active WHERE id = :id');
        $stmt->execute(['id' => $materialId]);
        if ($stmt->rowCount() === 0) { ok_err('Material not found'); }

        $isActive = db()->prepare('SELECT is_active FROM materials WHERE id = :id');
        $isActive->execute(['id' => $materialId]);

        echo json_encode(['success' => true, 'is_active' => (bool)$isActive->fetchColumn()]);
        break;
    }

    /* ── Delete a material (only if never used in a transaction) ── */
    case 'delete_material': {
        $materialId = (int)($_POST['material_id'] ?? 0);
        if ($materialId <= 0) { ok_err('Invalid material'); }

        // Server-side guard — deleting a material would CASCADE-delete every
        // stock transaction ever logged against it, across every project.
        // The UI already hides this option once a material has history, but
        // enforce it here too in case that check is ever bypassed.
        $count = db()->prepare('SELECT COUNT(*) FROM materials_stock WHERE material_id = :id');
        $count->execute(['id' => $materialId]);
        if ((int)$count->fetchColumn() > 0) {
            ok_err('This material has stock transactions logged against it — hide it instead of deleting');
        }

        db()->prepare('DELETE FROM materials WHERE id = :id')->execute(['id' => $materialId]);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Edit a labour attendance record ─────────── */
    case 'update_attendance': {
        $attendanceId = (int)($_POST['attendance_id'] ?? 0);
        $status       = trim($_POST['status'] ?? '');
        $date         = trim($_POST['date']   ?? '');
        $notes        = trim($_POST['notes']  ?? '');

        if ($attendanceId <= 0) { ok_err('Invalid attendance record'); }
        if (!in_array($status, ['present', 'absent', 'half_day'], true)) { ok_err('Invalid status'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { ok_err('Invalid date'); }

        $current = db()->prepare(
            'SELECT la.project_id, la.worker_id, w.full_name
             FROM labour_attendance la JOIN workers w ON w.id = la.worker_id
             WHERE la.id = :id'
        );
        $current->execute(['id' => $attendanceId]);
        $row = $current->fetch();
        if (!$row) { ok_err('Attendance record not found'); }

        // Absent workers are never stored — only present/half_day rows exist
        // in labour_attendance. Marking a record absent here removes it
        // instead of updating its status, keeping both save paths consistent.
        if ($status === 'absent') {
            db()->prepare('DELETE FROM labour_attendance WHERE id = :id')->execute(['id' => $attendanceId]);
            echo json_encode(['success' => true, 'deleted' => true]);
            break;
        }

        // Guard against a name-based duplicate on the target date — the
        // same person recorded under a different worker_id, which the
        // UNIQUE(project_id, worker_id, attendance_date) constraint alone
        // wouldn't catch since it only compares IDs.
        $dupCheck = db()->prepare(
            'SELECT w.full_name FROM labour_attendance la
             JOIN workers w ON w.id = la.worker_id
             WHERE la.project_id = :pid AND la.attendance_date = :date AND la.worker_id != :wid'
        );
        $dupCheck->execute(['pid' => $row['project_id'], 'date' => $date, 'wid' => $row['worker_id']]);
        $targetName = normalizeWorkerName($row['full_name']);
        foreach ($dupCheck->fetchAll() as $other) {
            if (normalizeWorkerName($other['full_name']) === $targetName) {
                ok_err('Another record for this worker (under a different roster entry) already exists on that date');
            }
        }

        try {
            db()->prepare(
                'UPDATE labour_attendance
                    SET status = :status, attendance_date = :date, nepali_date = :ndate, notes = :notes
                  WHERE id = :id'
            )->execute([
                'status' => $status, 'date' => $date, 'ndate' => NepaliDate::adToBs($date),
                'notes' => $notes ?: null, 'id' => $attendanceId,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                ok_err('This worker already has an attendance record for that date on this project');
            }
            throw $e;
        }

        echo json_encode(['success' => true]);
        break;
    }

    /* ══ Read-only endpoints added for the mobile app — purely additive,
       mirror what the PHP pages already compute server-side, don't touch
       or change any existing action/page. ══ */

    /* ── All projects (CMS source of truth: data/projects.json) ──── */
    case 'list_projects': {
        echo json_encode(['success' => true, 'projects' => array_values(loadProjects())]);
        break;
    }

    /* ── All site users + how many projects each is assigned to ──── */
    case 'list_site_users': {
        $users = db()->query(
            'SELECT u.id, u.username, u.full_name, u.is_active,
                    COUNT(up.project_id) AS project_count
             FROM site_users u
             LEFT JOIN user_projects up ON up.user_id = u.id
             GROUP BY u.id
             ORDER BY u.full_name'
        )->fetchAll();
        echo json_encode(['success' => true, 'users' => $users]);
        break;
    }

    /* ── Project IDs a given site user is assigned to (for the edit form) ── */
    case 'get_user_projects': {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId <= 0) { ok_err('Invalid user'); }
        $stmt = db()->prepare('SELECT project_id FROM user_projects WHERE user_id = :id');
        $stmt->execute(['id' => $targetUserId]);
        echo json_encode(['success' => true, 'project_ids' => array_column($stmt->fetchAll(), 'project_id')]);
        break;
    }

    /* ── Materials catalog with usage counts ──────────────────────── */
    case 'list_materials': {
        $materials = db()->query(
            'SELECT m.id, m.name, m.unit, m.category, m.is_active, COUNT(ms.id) AS txn_count
             FROM materials m
             LEFT JOIN materials_stock ms ON ms.material_id = m.id
             GROUP BY m.id
             ORDER BY (m.category IS NULL), m.category, m.name'
        )->fetchAll();
        echo json_encode(['success' => true, 'materials' => $materials]);
        break;
    }

    /* ── Global worker roster (for the Attendance Log's worker filter) ── */
    case 'list_workers': {
        $workers = db()->query(
            'SELECT w.id, w.full_name, w.category, w.daily_wage, w.is_active, COUNT(la.id) AS attendance_count
             FROM workers w
             LEFT JOIN labour_attendance la ON la.worker_id = w.id
             GROUP BY w.id, w.full_name, w.category, w.daily_wage, w.is_active
             ORDER BY w.full_name'
        )->fetchAll();
        echo json_encode(['success' => true, 'workers' => $workers]);
        break;
    }

    /* ── Add a worker to the roster ──────────────────────── */
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

    /* ── Show/hide a worker from the attendance list ─────── */
    case 'toggle_worker_active': {
        $workerId = (int)($_POST['worker_id'] ?? 0);
        if ($workerId <= 0) { ok_err('Invalid worker'); }

        $stmt = db()->prepare('UPDATE workers SET is_active = NOT is_active WHERE id = :id');
        $stmt->execute(['id' => $workerId]);
        if ($stmt->rowCount() === 0) { ok_err('Worker not found'); }

        $isActive = db()->prepare('SELECT is_active FROM workers WHERE id = :id');
        $isActive->execute(['id' => $workerId]);

        echo json_encode(['success' => true, 'is_active' => (bool)$isActive->fetchColumn()]);
        break;
    }

    /* ── Delete a worker (only if never used in an attendance record) ── */
    case 'delete_worker': {
        $workerId = (int)($_POST['worker_id'] ?? 0);
        if ($workerId <= 0) { ok_err('Invalid worker'); }

        // Deleting a worker would CASCADE-delete every attendance record
        // ever logged for them, across every project — the UI already
        // hides this option once a worker has history, but enforce it
        // here too in case that check is ever bypassed.
        $count = db()->prepare('SELECT COUNT(*) FROM labour_attendance WHERE worker_id = :id');
        $count->execute(['id' => $workerId]);
        if ((int)$count->fetchColumn() > 0) {
            ok_err('This worker has attendance records logged — hide them instead of deleting');
        }

        db()->prepare('DELETE FROM workers WHERE id = :id')->execute(['id' => $workerId]);
        echo json_encode(['success' => true]);
        break;
    }

    /* ── Stock Log page: current stock + totals + transactions, filtered ── */
    case 'get_stock_log': {
        $projectId  = trim($_POST['project_id']   ?? '');
        $materialId = (int)($_POST['material_id'] ?? 0);
        $txnType    = trim($_POST['txn_type']     ?? '');
        $dateFrom   = trim($_POST['date_from']    ?? '');
        $dateTo     = trim($_POST['date_to']      ?? '');
        if (!in_array($txnType, ['in', 'out'], true)) $txnType = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';
        if (!$projectId) { ok_err('Select a project'); }

        $pdo = db();
        $where  = ['ms.project_id = :pid'];
        $params = ['pid' => $projectId];
        if ($materialId > 0) { $where[] = 'ms.material_id = :mid'; $params['mid']   = $materialId; }
        if ($txnType !== '') { $where[] = 'ms.txn_type = :type';   $params['type']  = $txnType; }
        if ($dateFrom !== '') { $where[] = 'ms.txn_date >= :dfrom'; $params['dfrom'] = $dateFrom; }
        if ($dateTo !== '')   { $where[] = 'ms.txn_date <= :dto';   $params['dto']   = $dateTo; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT ms.id, ms.txn_date, ms.nepali_date, ms.txn_type, ms.quantity, ms.bundle_qty, ms.notes,
                    m.name AS material_name, m.unit, m.category,
                    u.username AS recorded_by_username
             FROM materials_stock ms
             JOIN materials m ON m.id = ms.material_id
             LEFT JOIN site_users u ON u.id = ms.recorded_by
             $whereSql
             ORDER BY ms.txn_date DESC, ms.id DESC
             LIMIT 500"
        );
        $stmt->execute($params);
        $history = $stmt->fetchAll();
        $totals  = computeStockTotals($history);

        $stmt = $pdo->prepare(
            "SELECT m.id AS material_id, m.name, m.unit, m.category,
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

        echo json_encode(['success' => true, 'history' => $history, 'totals' => $totals, 'balances' => $balances]);
        break;
    }

    /* ── Attendance Log page: summary + by-worker + records, filtered ── */
    case 'get_attendance_log': {
        $projectId = trim($_POST['project_id'] ?? '');
        $workerId  = (int)($_POST['worker_id']  ?? 0);
        $status    = trim($_POST['status']      ?? '');
        $dateFrom  = trim($_POST['date_from']   ?? '');
        $dateTo    = trim($_POST['date_to']     ?? '');
        if (!in_array($status, ['present', 'absent', 'half_day'], true)) $status = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';
        if (!$projectId) { ok_err('Select a project'); }

        $pdo = db();
        $where  = ['la.project_id = :pid'];
        $params = ['pid' => $projectId];
        if ($workerId > 0)    { $where[] = 'la.worker_id = :wid';          $params['wid']   = $workerId; }
        if ($status !== '')   { $where[] = 'la.status = :status';         $params['status'] = $status; }
        if ($dateFrom !== '') { $where[] = 'la.attendance_date >= :dfrom'; $params['dfrom'] = $dateFrom; }
        if ($dateTo !== '')   { $where[] = 'la.attendance_date <= :dto';   $params['dto']   = $dateTo; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT la.id, la.attendance_date, la.nepali_date, la.status, la.notes,
                    w.id AS worker_id, w.full_name AS worker_name, w.category AS worker_category,
                    u.username AS recorded_by_username
             FROM labour_attendance la
             JOIN workers w  ON w.id = la.worker_id
             LEFT JOIN site_users u ON u.id = la.recorded_by
             $whereSql
             ORDER BY la.attendance_date DESC, w.full_name
             LIMIT 500"
        );
        $stmt->execute($params);
        $history = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT la.status, COUNT(*) AS cnt FROM labour_attendance la $whereSql GROUP BY la.status");
        $stmt->execute($params);
        $statusCounts = array_column($stmt->fetchAll(), 'cnt', 'status');
        $presentDays  = (int)($statusCounts['present']  ?? 0);
        $absentDays   = (int)($statusCounts['absent']   ?? 0);
        $halfDays     = (int)($statusCounts['half_day'] ?? 0);

        $workerWhere  = ['la.project_id = :pid'];
        $workerParams = ['pid' => $projectId];
        if ($workerId > 0)    { $workerWhere[] = 'la.worker_id = :wid';          $workerParams['wid']   = $workerId; }
        if ($dateFrom !== '') { $workerWhere[] = 'la.attendance_date >= :dfrom'; $workerParams['dfrom'] = $dateFrom; }
        if ($dateTo !== '')   { $workerWhere[] = 'la.attendance_date <= :dto';   $workerParams['dto']   = $dateTo; }
        $workerWhereSql = 'WHERE ' . implode(' AND ', $workerWhere);

        $stmt = $pdo->prepare(
            "SELECT w.id AS worker_id, w.full_name, w.category, la.status, COUNT(*) AS cnt
             FROM labour_attendance la
             JOIN workers w ON w.id = la.worker_id
             $workerWhereSql
             GROUP BY w.id, w.full_name, w.category, la.status"
        );
        $stmt->execute($workerParams);
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
            'success' => true,
            'history' => $history,
            'summary' => ['present' => $presentDays, 'absent' => $absentDays, 'half_day' => $halfDays, 'man_days' => $presentDays + $halfDays * 0.5],
            'by_worker' => $byWorker,
        ]);
        break;
    }

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function ok_err(string $msg): void {
    echo json_encode(['error' => $msg]); exit;
}
