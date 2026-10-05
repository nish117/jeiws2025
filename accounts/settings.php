<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('admin');

$companyFields = [
    'company_name'       => ['label' => 'Company name',          'required' => true],
    'company_short_name' => ['label' => 'Short name',            'required' => true],
    'company_address'    => ['label' => 'Address',               'required' => false],
    'company_phone'      => ['label' => 'Phone',                 'required' => false],
    'company_email'      => ['label' => 'Email',                 'required' => false],
];
$taxFields = [
    'pan_number'         => ['label' => 'PAN number',            'required' => false],
    'vat_number'         => ['label' => 'VAT registration no.',  'required' => false],
    'vat_rate'           => ['label' => 'VAT rate (%)',          'required' => true],
    'currency_symbol'    => ['label' => 'Currency symbol',       'required' => true],
];
$roles = ['admin' => 'Admin — full access incl. settings & users', 'accountant' => 'Accountant — record and edit transactions', 'viewer' => 'Viewer — read-only reports'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_company' || $action === 'save_tax') {
        $fields = $action === 'save_company' ? $companyFields : $taxFields;
        $values = [];
        foreach ($fields as $key => $meta) {
            $values[$key] = trim((string)($_POST[$key] ?? ''));
            if ($meta['required'] && $values[$key] === '') $errors[] = $meta['label'] . ' is required.';
        }
        if (isset($values['company_email']) && $values['company_email'] !== '' && !filter_var($values['company_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Company email is not valid.';
        }
        if (isset($values['pan_number']) && $values['pan_number'] !== '' && !preg_match('/^\d{9}$/', $values['pan_number'])) {
            $errors[] = 'PAN number must be 9 digits.';
        }
        if (isset($values['vat_rate']) && (!is_numeric($values['vat_rate']) || $values['vat_rate'] < 0 || $values['vat_rate'] > 100)) {
            $errors[] = 'VAT rate must be a number between 0 and 100.';
        }

        if (!$errors) {
            $stmt = db()->prepare('INSERT INTO acc_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($values as $key => $value) $stmt->execute([$key, $value]);
            audit('update_settings', 'settings', null, implode(',', array_keys($values)));
            flash('success', 'Settings saved.');
            redirect('settings.php' . ($action === 'save_tax' ? '#tax' : ''));
        }
    }

    if ($action === 'create_user') {
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $role  = (string)($_POST['role'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        if ($name === '') $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if (!isset($roles[$role])) $errors[] = 'Choose a role.';
        if (strlen($pass) < 10) $errors[] = 'Temporary password must be at least 10 characters.';
        if (!$errors) {
            $exists = db()->prepare('SELECT 1 FROM acc_users WHERE email = ?');
            $exists->execute([$email]);
            if ($exists->fetchColumn()) $errors[] = 'A user with that email already exists.';
        }
        if (!$errors) {
            db()->prepare('INSERT INTO acc_users (full_name, email, password_hash, role) VALUES (?,?,?,?)')
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
            $newId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO acc_notifications (user_id, title, message, link, icon) VALUES (?,?,?,?,?)')
                ->execute([$newId, 'Welcome to JEIWS Accounts', 'Please change your temporary password.', 'profile.php#password', 'fa-key']);
            audit('create_user', 'user', $newId, $email . ' as ' . $role);
            flash('success', "User {$name} added. Share the temporary password with them securely.");
            redirect('settings.php#users');
        }
    }

    if ($action === 'upload_test') {
        require_once __DIR__ . '/includes/attachments.php';
        require_once __DIR__ . '/includes/system-check.php';
        $_SESSION['upload_test'] = upload_self_test();
        redirect('settings.php#system');
    }

    if ($action === 'update_user') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $role     = (string)($_POST['role'] ?? '');
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $newPass  = (string)($_POST['new_password'] ?? '');
        if (!isset($roles[$role])) $errors[] = 'Choose a valid role.';
        if ($newPass !== '' && strlen($newPass) < 10) $errors[] = 'New password must be at least 10 characters.';
        // Never let the last active admin lock everyone out.
        if ($targetId === (int)$user['id'] && ($role !== 'admin' || !$active)) {
            $errors[] = "You can't remove your own admin access or deactivate yourself.";
        }
        if (!$errors) {
            db()->prepare('UPDATE acc_users SET role = ?, is_active = ? WHERE id = ?')->execute([$role, $active, $targetId]);
            if ($newPass !== '') {
                db()->prepare('UPDATE acc_users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $targetId]);
            }
            audit('update_user', 'user', $targetId, "role={$role} active={$active}" . ($newPass !== '' ? ' password_reset' : ''));
            flash('success', 'User updated.');
            redirect('settings.php#users');
        }
    }
}

$settings = settings();
require_once __DIR__ . '/includes/attachments.php';
require_once __DIR__ . '/includes/system-check.php';
$checks = system_checks();
$systemProblems = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));
$errorsLog = recent_errors(20);
$uploadTest = $_SESSION['upload_test'] ?? null;
unset($_SESSION['upload_test']);
$val = fn(string $key) => $_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($key, $_POST) ? (string)$_POST[$key] : ($settings[$key] ?? '');
$users = db()->query('SELECT id, full_name, email, role, is_active, last_login_at FROM acc_users ORDER BY is_active DESC, full_name')->fetchAll();

$pageTitle   = 'Settings';
$activeNav   = 'settings';
$breadcrumbs = [['label' => 'Settings']];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($error) ?></div><?php endforeach ?>
    </div>
<?php endif ?>

<ul class="nav nav-underline mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#company" type="button" role="tab">Company</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tax" type="button" role="tab">Tax &amp; currency</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Users</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#system" type="button" role="tab">System check<?php if ($systemProblems): ?> <span class="badge text-bg-danger"><?= $systemProblems ?></span><?php endif ?></button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="company" role="tabpanel">
        <form method="post" class="acc-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_company">
            <div class="acc-card-head"><h2>Company details</h2></div>
            <div class="acc-card-body">
                <div class="row g-3">
                    <?php foreach ($companyFields as $key => $meta): ?>
                        <div class="<?= $key === 'company_address' ? 'col-12' : 'col-md-6' ?>">
                            <label class="form-label fw-semibold" for="<?= $key ?>"><?= e($meta['label']) ?><?= $meta['required'] ? ' <span class="text-danger">*</span>' : '' ?></label>
                            <input type="<?= $key === 'company_email' ? 'email' : 'text' ?>" class="form-control" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($val($key)) ?>" <?= $meta['required'] ? 'required' : '' ?>>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
            <div class="border-top px-4 py-3 text-end"><button class="btn btn-primary">Save company details</button></div>
        </form>
    </div>

    <div class="tab-pane fade" id="tax" role="tabpanel">
        <form method="post" class="acc-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_tax">
            <div class="acc-card-head"><h2>Tax registration &amp; currency</h2></div>
            <div class="acc-card-body">
                <div class="row g-3">
                    <?php foreach ($taxFields as $key => $meta): ?>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label fw-semibold" for="<?= $key ?>"><?= e($meta['label']) ?><?= $meta['required'] ? ' <span class="text-danger">*</span>' : '' ?></label>
                            <input type="text"
                                   class="form-control" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($val($key)) ?>"
                                   <?= $key === 'pan_number' ? 'inputmode="numeric" maxlength="9" pattern="\d{9}"' : '' ?>
                                   <?= $key === 'vat_rate' ? 'inputmode="decimal"' : '' ?>
                                   <?= $meta['required'] ? 'required' : '' ?>>
                        </div>
                    <?php endforeach ?>
                </div>
                <p class="form-text mt-3 mb-0">The fiscal year is worked out automatically from the Nepali calendar (1 Shrawan to end of Ashadh). Current: FY <?= e(fiscal_year_for(date('Y-m-d'))) ?>.</p>
            </div>
            <div class="border-top px-4 py-3 text-end"><button class="btn btn-primary">Save tax settings</button></div>
        </form>
    </div>

    <div class="tab-pane fade" id="users" role="tabpanel">
        <div class="acc-card mb-3">
            <div class="acc-card-head"><h2>Users</h2></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($u['full_name']) ?></div><div class="small text-body-secondary"><?= e($u['email']) ?></div></td>
                            <td><?= e(ucfirst($u['role'])) ?></td>
                            <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                            <td class="small text-body-secondary"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : 'Never' ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#editUser<?= (int)$u['id'] ?>">Edit</button>
                            </td>
                        </tr>
                        <tr class="collapse" id="editUser<?= (int)$u['id'] ?>">
                            <td colspan="5" class="bg-body-tertiary">
                                <form method="post" class="row g-2 align-items-end">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">Role</label>
                                        <select name="role" class="form-select form-select-sm">
                                            <?php foreach ($roles as $r => $label): ?>
                                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold">Reset password <span class="fw-normal text-body-secondary">(optional)</span></label>
                                        <input type="password" name="new_password" class="form-control form-control-sm" minlength="10" autocomplete="new-password">
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" name="is_active" id="active<?= (int)$u['id'] ?>" <?= $u['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="active<?= (int)$u['id'] ?>">Active</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3 text-end"><button class="btn btn-sm btn-primary">Save</button></div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>

        <form method="post" class="acc-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="acc-card-head"><h2>Add user</h2></div>
            <div class="acc-card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold" for="new_full_name">Full name</label><input class="form-control" id="new_full_name" name="full_name" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold" for="new_email">Email</label><input type="email" class="form-control" id="new_email" name="email" required></div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="new_role">Role</label>
                        <select class="form-select" id="new_role" name="role" required>
                            <?php foreach ($roles as $r => $label): ?><option value="<?= $r ?>" <?= $r === 'accountant' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label fw-semibold" for="new_password">Temporary password</label><input type="password" class="form-control" id="new_password" name="password" minlength="10" required autocomplete="new-password"><div class="form-text">At least 10 characters. They'll be asked to change it.</div></div>
                </div>
            </div>
            <div class="border-top px-4 py-3 text-end"><button class="btn btn-primary"><i class="fa-solid fa-user-plus me-1"></i> Add user</button></div>
        </form>
    </div>

    <div class="tab-pane fade" id="system" role="tabpanel">
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="acc-card">
                    <div class="acc-card-head"><h2>Server readiness</h2><span class="small text-body-secondary">PHP <?= e(PHP_VERSION) ?> · <?= e(php_sapi_name()) ?></span></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                            <?php $group = null; foreach ($checks as $c): ?>
                                <?php if ($c['group'] !== $group): $group = $c['group']; ?><tr><th colspan="3" class="small text-uppercase text-body-secondary pt-3" style="letter-spacing:.05em"><?= e($group) ?></th></tr><?php endif ?>
                                <tr>
                                    <td style="width:28px"><?= $c['status'] === 'ok' ? '<i class="fa-solid fa-circle-check text-success"></i>' : ($c['status'] === 'warn' ? '<i class="fa-solid fa-triangle-exclamation text-warning"></i>' : '<i class="fa-solid fa-circle-xmark text-danger"></i>') ?></td>
                                    <td><?= e($c['label']) ?><?php if ($c['status'] !== 'ok' && $c['help']): ?><div class="small text-body-secondary"><?= e($c['help']) ?></div><?php endif ?></td>
                                    <td class="small text-end text-nowrap acc-code"><?= e($c['value']) ?></td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <form method="post" class="acc-card mb-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="upload_test">
                    <div class="acc-card-head"><h2>Upload self-test</h2><button class="btn btn-sm btn-outline-primary">Run test</button></div>
                    <div class="acc-card-body small">
                        <?php if (!$uploadTest): ?>
                            <span class="text-body-secondary">Saves, processes and deletes a small test photo and PDF in the uploads folder — the same steps a real bill upload takes.</span>
                        <?php else: ?>
                            <?php foreach ($uploadTest as [$label, $result]): ?>
                                <div class="d-flex gap-2 py-1"><?= $result === true ? '<i class="fa-solid fa-circle-check text-success mt-1"></i>' : '<i class="fa-solid fa-circle-xmark text-danger mt-1"></i>' ?>
                                    <span><?= e($label) ?><?= is_string($result) ? '<div class="text-body-secondary">' . e($result) . '</div>' : '' ?></span></div>
                            <?php endforeach ?>
                        <?php endif ?>
                    </div>
                </form>
                <div class="acc-card">
                    <div class="acc-card-head"><h2>Recent errors</h2><span class="small text-body-secondary"><?= count($errorsLog) ?></span></div>
                    <?php if (!$errorsLog): ?>
                        <div class="acc-card-body small text-body-secondary">No errors recorded. If someone sees "Something went wrong", the reference code they're shown appears here.</div>
                    <?php else: ?>
                        <ul class="list-unstyled small mb-0">
                            <?php foreach ($errorsLog as $err): ?>
                                <li class="px-3 py-2 border-bottom">
                                    <div class="d-flex justify-content-between"><strong class="acc-code"><?= e($err['ref'] ?? '') ?></strong><span class="text-body-secondary"><?= e($err['time'] ?? '') ?></span></div>
                                    <div class="text-danger text-break"><?= e(($err['type'] ?? '') . ': ' . ($err['message'] ?? '')) ?></div>
                                    <div class="text-body-secondary text-break"><?= e(($err['method'] ?? '') . ' ' . ($err['url'] ?? '')) ?> · <?= e(basename((string)($err['where'] ?? ''))) ?></div>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Open the tab named in the URL hash (e.g. settings.php#tax), and keep the hash in sync.
$inlineScript = '(function () {
    const open = hash => { const btn = document.querySelector(`[data-bs-target="${hash}"]`); if (btn) bootstrap.Tab.getOrCreateInstance(btn).show(); };
    if (location.hash) open(location.hash);
    document.querySelectorAll("[data-bs-toggle=tab]").forEach(btn => btn.addEventListener("shown.bs.tab", () => history.replaceState(null, "", btn.dataset.bsTarget)));
})();';
require __DIR__ . '/includes/layout-bottom.php';
