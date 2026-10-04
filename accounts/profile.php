<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        if ($name === '') $errors[] = 'Name is required.';
        if (!$errors) {
            db()->prepare('UPDATE acc_users SET full_name = ? WHERE id = ?')->execute([$name, $user['id']]);
            audit('update_profile', 'user', (int)$user['id']);
            flash('success', 'Profile updated.');
            redirect('profile.php');
        }
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $stmt = db()->prepare('SELECT password_hash FROM acc_users WHERE id = ?');
        $stmt->execute([$user['id']]);
        if (!password_verify($current, (string)$stmt->fetchColumn())) $errors[] = 'Current password is incorrect.';
        if (strlen($new) < 10) $errors[] = 'New password must be at least 10 characters.';
        if ($new !== ($_POST['confirm_password'] ?? '')) $errors[] = 'New passwords do not match.';
        if (!$errors) {
            db()->prepare('UPDATE acc_users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            audit('change_password', 'user', (int)$user['id']);
            flash('success', 'Password changed.');
            redirect('profile.php');
        }
    }
}

$pageTitle   = 'My profile';
$activeNav   = '';
$breadcrumbs = [['label' => 'My profile']];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($error) ?></div><?php endforeach ?>
    </div>
<?php endif ?>

<div class="row g-3">
    <div class="col-lg-6">
        <form method="post" class="acc-card h-100">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="acc-card-head"><h2>Profile</h2></div>
            <div class="acc-card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="full_name">Full name</label>
                    <input class="form-control" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" required autocomplete="name">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input class="form-control" value="<?= e($user['email']) ?>" disabled>
                    <div class="form-text">Ask an administrator to change your sign-in email.</div>
                </div>
                <div>
                    <label class="form-label fw-semibold">Role</label>
                    <input class="form-control" value="<?= e(ucfirst($user['role'])) ?>" disabled>
                </div>
            </div>
            <div class="border-top px-4 py-3 text-end"><button class="btn btn-primary">Save profile</button></div>
        </form>
    </div>
    <div class="col-lg-6">
        <form method="post" class="acc-card h-100" id="password">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="acc-card-head"><h2>Change password</h2></div>
            <div class="acc-card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="current_password">Current password</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="new_password">New password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" minlength="10" required autocomplete="new-password">
                    <div class="form-text">At least 10 characters.</div>
                </div>
                <div>
                    <label class="form-label fw-semibold" for="confirm_password">Confirm new password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
            </div>
            <div class="border-top px-4 py-3 text-end"><button class="btn btn-primary">Change password</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
