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

// Projects assigned to the given site user, ordered by title.
// Not filtered by is_active — that flag tracks public-site publish
// state, which is unrelated to whether site staff should be able to
// log attendance/stock against the project (drafts still need it).
function getAssignedProjects(int $userId): array {
    $stmt = db()->prepare(
        'SELECT p.id, p.title
         FROM projects p
         JOIN user_projects up ON up.project_id = p.id
         WHERE up.user_id = :uid
         ORDER BY p.title'
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
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
// by unit — materials can be tracked in different units (bags, kg, cft),
// so a single combined number would be misleading whenever more than one
// unit is present in the result set.
// Expects rows with 'txn_type' and 'quantity' + 'unit' keys.
function computeStockTotals(array $rows): array {
    $totals = ['in' => [], 'out' => []];
    foreach ($rows as $r) {
        $key  = $r['txn_type'] === 'out' ? 'out' : 'in';
        $unit = $r['unit'];
        $totals[$key][$unit] = ($totals[$key][$unit] ?? 0) + (float)$r['quantity'];
    }
    return $totals;
}

// Renders a computeStockTotals() bucket (e.g. $totals['in']) as "50 bags, 12 cft"
function formatStockTotals(array $byUnit): string {
    if (empty($byUnit)) return '0';
    $parts = [];
    foreach ($byUnit as $unit => $qty) {
        $parts[] = rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' ' . $unit;
    }
    return implode(', ', $parts);
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
