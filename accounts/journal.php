<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$fy      = (string)($_GET['fy'] ?? fiscal_year_for(date('Y-m-d')));
$status  = (string)($_GET['status'] ?? '');
$q       = trim((string)($_GET['q'] ?? ''));

$where = ['1=1'];
$params = [];
if ($fy !== 'all')  { $where[] = 'e.fiscal_year = ?'; $params[] = $fy; }
if (in_array($status, ['draft', 'posted', 'void'], true)) { $where[] = 'e.status = ?'; $params[] = $status; }
if ($q !== '') {
    $where[] = '(e.voucher_no LIKE ? OR e.narration LIKE ? OR e.reference LIKE ?)';
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
}
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM acc_journal_entries e WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$stmt = db()->prepare(
    "SELECT e.*, u.full_name AS created_by_name FROM acc_journal_entries e
     LEFT JOIN acc_users u ON u.id = e.created_by
     WHERE {$whereSql}
     ORDER BY (e.status = 'draft') DESC, e.entry_date DESC, e.id DESC
     LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$fiscalYears = db()->query('SELECT DISTINCT fiscal_year FROM acc_journal_entries ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN);
$currentFy = fiscal_year_for(date('Y-m-d'));
if (!in_array($currentFy, $fiscalYears, true)) array_unshift($fiscalYears, $currentFy);

$query = fn(array $overrides) => '?' . http_build_query(array_merge(['fy' => $fy, 'status' => $status, 'q' => $q], $overrides));

$pageTitle   = 'Journal vouchers';
$activeNav   = 'accounting';
$breadcrumbs = [['label' => 'Accounting', 'href' => 'accounting.php'], ['label' => 'Journal vouchers']];
$pageActions = can_edit_books($user) ? '<a href="journal-entry.php" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New voucher</a>' : '';
require __DIR__ . '/includes/layout-top.php';
$accountingTab = 'journal';
require __DIR__ . '/includes/accounting-nav.php';
?>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <div>
            <label class="form-label" for="fy">Fiscal year</label>
            <select class="form-select form-select-sm" id="fy" name="fy" onchange="this.form.submit()">
                <?php foreach ($fiscalYears as $year): ?><option value="<?= e($year) ?>" <?= $year === $fy ? 'selected' : '' ?>>FY <?= e($year) ?></option><?php endforeach ?>
                <option value="all" <?= $fy === 'all' ? 'selected' : '' ?>>All years</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (['draft' => 'Drafts', 'posted' => 'Posted', 'void' => 'Void'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="flex-grow-1" style="max-width:320px">
            <label class="form-label" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Voucher no., narration, reference">
        </div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>

    <?php if (!$entries): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid fa-file-pen"></i></div>
            <h3>No vouchers found</h3>
            <p><?= $q || $status ? 'Try clearing the filters.' : 'Journal vouchers record transactions directly in the ledger — opening balances, adjustments, depreciation and so on.' ?></p>
            <?php if (can_edit_books($user) && !$q && !$status): ?><a href="journal-entry.php" class="btn btn-primary mt-3"><i class="fa-solid fa-plus me-1"></i> Create the first voucher</a><?php endif ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Voucher</th><th>Date</th><th>Narration</th><th class="num">Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $en): ?>
                    <tr class="<?= $en['status'] === 'void' ? 'acc-inactive' : '' ?>" style="cursor:pointer" onclick="location.href='journal-entry.php?id=<?= (int)$en['id'] ?>'">
                        <td class="text-nowrap">
                            <a href="journal-entry.php?id=<?= (int)$en['id'] ?>" class="fw-semibold text-decoration-none"><?= e($en['voucher_no'] ?? 'Draft #' . $en['id']) ?></a>
                            <?php if ($en['source'] !== 'manual'): ?><div class="small text-body-secondary"><?= e(ucfirst($en['source'])) ?></div><?php endif ?>
                        </td>
                        <td class="text-nowrap"><?= e(bs_date($en['entry_date'])) ?><div class="small text-body-secondary"><?= e(date('d M Y', strtotime($en['entry_date']))) ?></div></td>
                        <td>
                            <div class="text-truncate" style="max-width:420px"><?= e($en['narration']) ?></div>
                            <?php if ($en['reference']): ?><div class="small text-body-secondary">Ref: <?= e($en['reference']) ?></div><?php endif ?>
                        </td>
                        <td class="num"><?= e(money($en['total_amount'], false)) ?></td>
                        <td><?= status_badge($en['status']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top small">
                <span class="text-body-secondary"><?= $total ?> vouchers</span>
                <nav><ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($query(['page' => $page - 1])) ?>">Previous</a></li>
                    <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
                    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($query(['page' => $page + 1])) ?>">Next</a></li>
                </ul></nav>
            </div>
        <?php endif ?>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
