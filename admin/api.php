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

    default:
        echo json_encode(['error' => 'Unknown action']);
}

function ok_err(string $msg): void {
    echo json_encode(['error' => $msg]); exit;
}
