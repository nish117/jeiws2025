<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

$accounts = account_tree();
$errors = [];
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? ($accounts[$editId] ?? null) : null;
if ($editId && !$editing) redirect('chart-of-accounts.php');

function account_has_postings(int $id): bool {
    $stmt = db()->prepare('SELECT 1 FROM acc_journal_lines WHERE account_id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}
function account_has_children(array $accounts, int $id): bool {
    foreach ($accounts as $a) if ((int)$a['parent_id'] === $id) return true;
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Read-only access.'); }
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    $validateCommon = function (?int $selfId) use ($code, $name, $description, &$errors) {
        if (!preg_match('/^[A-Z0-9][A-Z0-9.\-]{0,19}$/', $code)) $errors[] = 'Code must be 1–20 letters, digits, dots or dashes.';
        if ($name === '' || mb_strlen($name) > 150) $errors[] = 'Name is required (max 150 characters).';
        if (mb_strlen($description) > 255) $errors[] = 'Description is too long.';
        $dupe = db()->prepare('SELECT id FROM acc_accounts WHERE code = ? AND id <> ?');
        $dupe->execute([$code, $selfId ?? 0]);
        if ($dupe->fetchColumn()) $errors[] = "Code {$code} is already used by another account.";
    };

    if ($action === 'create') {
        $parent = $accounts[(int)($_POST['parent_id'] ?? 0)] ?? null;
        if (!$parent || !$parent['is_group']) $errors[] = 'Choose a parent group.';
        $validateCommon(null);
        if (!$errors) {
            db()->prepare('INSERT INTO acc_accounts (parent_id, code, name, type, is_group, description) VALUES (?,?,?,?,?,?)')
                ->execute([$parent['id'], $code, $name, $parent['type'], isset($_POST['is_group']) ? 1 : 0, $description ?: null]);
            $newId = (int)db()->lastInsertId();
            // An account added under Bank Accounts is a bank account — make it available for payments and reconciliation.
            if ((int)$parent['id'] === system_account_id('bank_accounts_group') && !isset($_POST['is_group'])) {
                db()->prepare("INSERT IGNORE INTO acc_bank_accounts (account_id, kind, created_by) VALUES (?, 'bank', ?)")->execute([$newId, $user['id']]);
            }
            audit('create_account', 'account', $newId, "{$code} {$name}");
            flash('success', "Account {$code} · {$name} created.");
            redirect('chart-of-accounts.php#acc-' . $newId);
        }
    }

    if ($action === 'update' && $editing) {
        $active = isset($_POST['is_active']) ? 1 : 0;
        $validateCommon((int)$editing['id']);
        if (!$active && $editing['system_key']) $errors[] = 'System accounts are used by other modules and cannot be deactivated.';
        if (!$errors) {
            db()->prepare('UPDATE acc_accounts SET code = ?, name = ?, description = ?, is_active = ? WHERE id = ?')
                ->execute([$code, $name, $description ?: null, $active, $editing['id']]);
            audit('update_account', 'account', (int)$editing['id'], "{$code} {$name}" . ($active ? '' : ' (inactive)'));
            flash('success', 'Account updated.');
            redirect('chart-of-accounts.php#acc-' . $editing['id']);
        }
    }

    if ($action === 'delete' && $editing) {
        if ($editing['system_key'])                               $errors[] = 'System accounts cannot be deleted.';
        elseif (account_has_children($accounts, (int)$editing['id'])) $errors[] = 'Move or delete the accounts under this group first.';
        elseif (account_has_postings((int)$editing['id']))       $errors[] = 'This account has transactions. Deactivate it instead.';
        if (!$errors) {
            db()->prepare('DELETE FROM acc_accounts WHERE id = ?')->execute([$editing['id']]);
            audit('delete_account', 'account', (int)$editing['id'], "{$editing['code']} {$editing['name']}");
            flash('success', "Account {$editing['code']} deleted.");
            redirect('chart-of-accounts.php');
        }
    }
}

// Natural balances as of today, rolled up into groups.
$totals = account_totals();
$balances = [];
foreach (array_reverse($accounts, true) as $id => $a) { // children before parents
    $t = $totals[$id] ?? ['debit' => 0, 'credit' => 0];
    $balances[$id] = ($balances[$id] ?? 0) + natural_balance($a['type'], $t['debit'], $t['credit']);
    if ($a['parent_id']) $balances[(int)$a['parent_id']] = ($balances[(int)$a['parent_id']] ?? 0) + $balances[$id];
}
$groups = array_filter($accounts, fn($a) => $a['is_group']);
$form = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($editing ?? ['parent_id' => $_GET['parent'] ?? '']);

$pageTitle   = 'Chart of accounts';
$activeNav   = 'accounting';
$breadcrumbs = [['label' => 'Accounting', 'href' => 'accounting.php'], ['label' => 'Chart of accounts']];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
$accountingTab = 'chart';
require __DIR__ . '/includes/accounting-nav.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<div class="row g-3">
    <div class="<?= $canEdit ? 'col-xl-8' : 'col-12' ?>">
        <div class="acc-card">
            <div class="acc-filters">
                <div class="flex-grow-1" style="max-width:320px">
                    <label class="form-label" for="coaSearch">Search</label>
                    <input type="search" id="coaSearch" class="form-control form-control-sm" placeholder="Code or name…">
                </div>
                <div class="form-check ms-auto mb-1">
                    <input class="form-check-input" type="checkbox" id="coaShowInactive">
                    <label class="form-check-label small" for="coaShowInactive">Show inactive</label>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="coaTable">
                    <thead><tr><th style="width:90px">Code</th><th>Account</th><th class="num">Balance</th><th style="width:1%"></th></tr></thead>
                    <tbody>
                    <?php foreach ($accounts as $id => $a): ?>
                        <tr id="acc-<?= $id ?>" class="<?= $a['is_group'] ? 'acc-tree-row-group' : '' ?><?= $a['is_active'] ? '' : ' acc-inactive d-none' ?>"
                            data-search="<?= e(mb_strtolower($a['code'] . ' ' . $a['name'])) ?>" data-active="<?= (int)$a['is_active'] ?>">
                            <td class="acc-code"><?= e($a['code']) ?></td>
                            <td>
                                <span class="acc-tree-name" style="padding-left:<?= $a['depth'] * 22 ?>px">
                                    <i class="fa-<?= $a['is_group'] ? 'solid fa-folder-open text-warning' : 'regular fa-file-lines text-body-tertiary' ?>"></i>
                                    <span class="<?= $a['is_group'] ? 'acc-tree-group' : '' ?>"><?= e($a['name']) ?></span>
                                    <?php if ($a['system_key']): ?><span class="acc-sys-tag" title="Used automatically by other modules">System</span><?php endif ?>
                                    <?php if (!$a['is_active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif ?>
                                </span>
                            </td>
                            <td class="num <?= $a['is_group'] ? 'fw-bold' : '' ?> <?= $balances[$id] < 0 ? 'text-danger' : '' ?>">
                                <?= $balances[$id] ? e(money_cents($balances[$id])) : '<span class="text-body-tertiary">—</span>' ?>
                            </td>
                            <td class="text-nowrap text-end">
                                <?php if (!$a['is_group']): ?>
                                    <a class="btn btn-sm btn-link px-1" href="ledger.php?account=<?= $id ?>" title="View ledger"><i class="fa-solid fa-book-open"></i></a>
                                <?php elseif ($canEdit): ?>
                                    <a class="btn btn-sm btn-link px-1" href="chart-of-accounts.php?parent=<?= $id ?>#accountForm" title="Add account under this group"><i class="fa-solid fa-plus"></i></a>
                                <?php endif ?>
                                <?php if ($canEdit): ?>
                                    <a class="btn btn-sm btn-link px-1" href="chart-of-accounts.php?edit=<?= $id ?>#accountForm" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="small text-body-secondary mt-2 mb-0">Balances include posted vouchers only and are shown on each account's normal side (debit for assets &amp; expenses, credit for liabilities, equity &amp; income). Red means the balance is on the opposite side.</p>
    </div>

    <?php if ($canEdit): ?>
    <div class="col-xl-4">
        <form method="post" class="acc-card position-sticky" style="top:calc(var(--acc-topbar-h) + 16px)" id="accountForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <div class="acc-card-head">
                <h2><?= $editing ? 'Edit account' : 'New account' ?></h2>
                <?php if ($editing): ?><a href="chart-of-accounts.php" class="small">+ New instead</a><?php endif ?>
            </div>
            <div class="acc-card-body">
                <?php if ($editing): ?>
                    <p class="small text-body-secondary mb-3">
                        <?= e(ACC_ACCOUNT_TYPES[$editing['type']]['label']) ?> ·
                        <?= $editing['is_group'] ? 'Group' : 'Posting account' ?>
                        <?php if ($editing['parent_id']): ?> · under <?= e($accounts[(int)$editing['parent_id']]['name']) ?><?php endif ?>
                    </p>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="parent_id">Parent group</label>
                        <select class="form-select" id="parent_id" name="parent_id" required>
                            <option value="">Choose…</option>
                            <?php foreach ($groups as $gid => $g): ?>
                                <option value="<?= $gid ?>" <?= (string)$gid === (string)($form['parent_id'] ?? '') ? 'selected' : '' ?>>
                                    <?= str_repeat('— ', $g['depth']) . e($g['code'] . ' · ' . $g['name']) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                        <div class="form-text">The account type (asset, expense…) comes from the group.</div>
                    </div>
                <?php endif ?>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold" for="code">Code</label>
                        <input class="form-control" id="code" name="code" value="<?= e($form['code'] ?? '') ?>" required maxlength="20">
                    </div>
                    <div class="col-8">
                        <label class="form-label fw-semibold" for="name">Name</label>
                        <input class="form-control" id="name" name="name" value="<?= e($form['name'] ?? '') ?>" required maxlength="150">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="description">Description <span class="fw-normal text-body-secondary">(optional)</span></label>
                    <input class="form-control" id="description" name="description" value="<?= e($form['description'] ?? '') ?>" maxlength="255">
                </div>
                <?php if ($editing): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= !empty($form['is_active']) ? 'checked' : '' ?> <?= $editing['system_key'] ? 'disabled checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                        <?php if ($editing['system_key']): ?>
                            <input type="hidden" name="is_active" value="1">
                            <div class="form-text">System account — always active.</div>
                        <?php endif ?>
                    </div>
                <?php else: ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_group" name="is_group" <?= !empty($form['is_group']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_group">This is a group (holds other accounts, no postings)</label>
                    </div>
                <?php endif ?>
            </div>
            <div class="border-top px-4 py-3 d-flex justify-content-between gap-2">
                <?php if ($editing && !$editing['system_key']): ?>
                    <button type="submit" name="action" value="delete" class="btn btn-outline-danger" formnovalidate
                            onclick="return confirm('Delete account <?= e($editing['code']) ?>? This only works if it has no transactions.')">Delete</button>
                <?php else: ?><span></span><?php endif ?>
                <button class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create account' ?></button>
            </div>
        </form>
    </div>
    <?php endif ?>
</div>

<?php
$inlineScript = '(function () {
    const search = document.getElementById("coaSearch");
    const showInactive = document.getElementById("coaShowInactive");
    const rows = Array.from(document.querySelectorAll("#coaTable tbody tr"));
    function apply() {
        const q = search.value.trim().toLowerCase();
        rows.forEach(r => {
            const hidden = (!showInactive.checked && r.dataset.active === "0") || (q && !r.dataset.search.includes(q));
            r.classList.toggle("d-none", hidden);
        });
    }
    search.addEventListener("input", apply);
    showInactive.addEventListener("change", apply);
    const target = location.hash && document.querySelector(location.hash);
    if (target && target.tagName === "TR") target.classList.add("table-active");
})();';
require __DIR__ . '/includes/layout-bottom.php';
