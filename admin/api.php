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

        $exists = db()->prepare('SELECT 1 FROM labour_attendance WHERE id = :id');
        $exists->execute(['id' => $attendanceId]);
        if (!$exists->fetchColumn()) { ok_err('Attendance record not found'); }

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

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function ok_err(string $msg): void {
    echo json_encode(['error' => $msg]); exit;
}
