<?php
defined('SITE_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../lib/Db.php';

function requireSiteAuth(): void {
    if (empty($_SESSION['site_user_id'])) {
        header('Location: login.php'); exit;
    }
}

function currentSiteUserId(): int {
    return (int)($_SESSION['site_user_id'] ?? 0);
}

// Normalizes a worker's name for duplicate comparison — catches the same
// person entered twice in the roster under different worker_ids (which the
// DB's UNIQUE(project_id, worker_id, attendance_date) constraint can't
// catch, since it only sees IDs, not names).
function normalizeWorkerName(string $name): string {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
}

// Deleting a worker used to be a hard DELETE, blocked once they had any
// attendance history (the FK is ON DELETE CASCADE — a real delete would
// wipe that history). Deletion is now a soft-delete via this column so
// the worker disappears from the roster while every past attendance
// record (still linked by worker_id) stays intact. Self-migrating since
// there's no way to run a schema migration directly against the live
// production database from here.
function ensureWorkersDeletedColumn(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $pdo = db();
    $exists = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workers' AND COLUMN_NAME = 'is_deleted'"
    )->fetchColumn();
    if (!$exists) {
        $pdo->exec('ALTER TABLE workers ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0');
    }
}

// Workers are now scoped to the project(s) they're added under (a laborer
// can be assigned to several projects) instead of one global pool visible
// to every project. Self-migrating for the same reason as above.
function ensureWorkerProjectsTable(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db()->exec(
        'CREATE TABLE IF NOT EXISTS worker_projects (
            worker_id   INT         NOT NULL,
            project_id  VARCHAR(64) COLLATE utf8mb4_general_ci NOT NULL,
            is_active   TINYINT(1)  NOT NULL DEFAULT 1,
            assigned_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (worker_id, project_id),
            FOREIGN KEY (worker_id)  REFERENCES workers(id)  ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

// Projects assigned to the given site user, ordered by title.
// Not filtered by is_active — that flag tracks public-site publish
// state, which is unrelated to whether site staff should be able to
// log attendance/stock against the project (drafts still need it).
function getAssignedProjects(int $userId): array {
    ensureProjectsImageColumn();
    $stmt = db()->prepare(
        'SELECT p.id, p.title, p.image
         FROM projects p
         JOIN user_projects up ON up.project_id = p.id
         WHERE up.user_id = :uid
         ORDER BY p.title'
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
}

// The image column is populated by the admin CMS (admin/functions.php's
// syncProjectFromArray) — this mirrors that same self-migration on the
// read side, since deployment order between admin/ and site/ isn't
// guaranteed and this table can't be altered directly on the live server.
function ensureProjectsImageColumn(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $pdo = db();
    $exists = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'image'"
    )->fetchColumn();
    if (!$exists) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN image VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

// True if the user is assigned to this project — call before any
// project-scoped write so a user can't log data against a project
// that isn't theirs by tampering with the project_id in a request.
function userCanAccessProject(int $userId, string $projectId): bool {
    $stmt = db()->prepare('SELECT 1 FROM user_projects WHERE user_id = :uid AND project_id = :pid');
    $stmt->execute(['uid' => $userId, 'pid' => $projectId]);
    return (bool)$stmt->fetchColumn();
}

function getProjectTitle(string $projectId): ?string {
    $stmt = db()->prepare('SELECT title FROM projects WHERE id = :pid');
    $stmt->execute(['pid' => $projectId]);
    $title = $stmt->fetchColumn();
    return $title === false ? null : $title;
}

// Sums IN vs OUT quantities from a set of stock_transaction rows, grouped
// by category then unit — materials can be tracked in different units
// (bags, kg, cft), so a single combined number would be misleading
// whenever more than one is present; grouping by category first also
// makes each line meaningful on its own (e.g. "Reinforcement: 100 kg"
// instead of an unlabeled "100 kg" that could be any material).
// Expects rows with 'txn_type', 'category', 'quantity', 'unit', 'bundle_qty' keys.
function computeStockTotals(array $rows): array {
    $totals = ['in' => [], 'out' => []];
    foreach ($rows as $r) {
        $key  = $r['txn_type'] === 'out' ? 'out' : 'in';
        $cat  = ($r['category'] ?? '') !== '' ? $r['category'] : 'Other';
        $unit = $r['unit'];
        if (!isset($totals[$key][$cat])) {
            $totals[$key][$cat] = ['units' => [], 'bundles' => 0];
        }
        $totals[$key][$cat]['units'][$unit] = ($totals[$key][$cat]['units'][$unit] ?? 0) + (float)$r['quantity'];
        if (!empty($r['bundle_qty'])) {
            $totals[$key][$cat]['bundles'] += (float)$r['bundle_qty'];
        }
    }
    return $totals;
}

// Renders a computeStockTotals() bucket (e.g. $totals['in']) into one
// display line per category, e.g. ['category' => 'Reinforcement', 'text' => '100 kg (10 bundles)']
function formatStockTotals(array $byCategory): array {
    $lines = [];
    foreach ($byCategory as $cat => $data) {
        $parts = [];
        foreach ($data['units'] as $unit => $qty) {
            $parts[] = rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' ' . $unit;
        }
        $text = implode(', ', $parts);
        if ($data['bundles'] > 0) {
            $text .= ' (' . rtrim(rtrim(number_format($data['bundles'], 2), '0'), '.') . ' bundle' . ($data['bundles'] == 1 ? '' : 's') . ')';
        }
        $lines[] = ['category' => $cat, 'text' => $text];
    }
    return $lines;
}

// ── CSRF (mirrors admin/functions.php) ──────────────────
function siteCsrfToken(): string {
    if (empty($_SESSION['site_csrf_token'])) {
        $_SESSION['site_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['site_csrf_token'];
}

function verifySiteCsrf(): void {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!isset($_SESSION['site_csrf_token']) || !hash_equals($_SESSION['site_csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']); exit;
    }
}
