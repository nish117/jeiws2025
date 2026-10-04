<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('dashboard.php');
$mode = (int)db()->query('SELECT COUNT(*) FROM acc_users')->fetchColumn() === 0 ? 'setup' : 'login';

$errors = [];
$email  = '';
$fullName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($mode === 'setup') {
        // First admin can only be created by someone who knows the server-side
        // secret (same SetEnv as the CMS), so a fresh install isn't claimable
        // by whoever finds the URL first.
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $secret   = getenv('ACC_SETUP_SECRET') ?: getenv('CMS_SETUP_SECRET');
        if (!$secret) {
            $errors[] = 'Setup is locked: set ACC_SETUP_SECRET (or CMS_SETUP_SECRET) in the server config first.';
        } elseif (!hash_equals($secret, (string)($_POST['setup_secret'] ?? ''))) {
            $errors[] = 'Invalid setup secret.';
        }
        if ($fullName === '') $errors[] = 'Enter your full name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if (strlen($password) < 10) $errors[] = 'Password must be at least 10 characters.';
        if ($password !== ($_POST['password_confirm'] ?? '')) $errors[] = 'Passwords do not match.';

        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            // Re-check inside the transaction so two simultaneous setups can't both succeed.
            if ((int)$pdo->query('SELECT COUNT(*) FROM acc_users FOR UPDATE')->fetchColumn() === 0) {
                $pdo->prepare('INSERT INTO acc_users (full_name, email, password_hash, role) VALUES (?,?,?,?)')
                    ->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
                $userId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO acc_notifications (user_id, title, message, link, icon) VALUES (?,?,?,?,?)')
                    ->execute([$userId, 'Welcome to JEIWS Accounts', 'Start by filling in your company details, PAN and VAT number.', 'settings.php', 'fa-building']);
                $pdo->commit();

                login_user(['id' => $userId]);
                audit('setup_admin', 'user', $userId);
                flash('success', 'Admin account created. Welcome!');
                redirect('dashboard.php');
            }
            $pdo->rollBack();
            $mode = 'login';
        }
    } else {
        if (too_many_login_attempts($email)) {
            $errors[] = 'Too many failed attempts. Please wait ' . ACC_LOGIN_WINDOW_MINUTES . ' minutes and try again.';
        } else {
            $stmt = db()->prepare('SELECT id, password_hash, is_active FROM acc_users WHERE email = ?');
            $stmt->execute([$email]);
            $row = $stmt->fetch();

            // Verify against a real (random, unguessable) hash when the email is unknown,
            // so response time doesn't reveal which emails have accounts.
            $valid = password_verify($password, $row['password_hash'] ?? '$2y$10$Souvn24IRyE8LiMEwNandOFH3RHExNxmtLQWcgxoK9nxWFgaRvHHK');
            if ($row && $valid && $row['is_active']) {
                record_login_attempt($email, true);
                if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE acc_users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
                }
                login_user($row);
                audit('login', 'user', (int)$row['id']);

                $next = $_SESSION['acc_after_login'] ?? '';
                unset($_SESSION['acc_after_login']);
                // Only follow same-app paths — never an attacker-supplied external URL.
                if (is_string($next) && str_starts_with($next, acc_base_path() . '/') && !str_contains($next, '//')) {
                    redirect($next);
                }
                redirect('dashboard.php');
            }
            record_login_attempt($email, false);
            $errors[] = ($row && $valid && !$row['is_active'])
                ? 'This account has been deactivated. Contact your administrator.'
                : 'Incorrect email or password.';
        }
    }
}

$companyShort = setting('company_short_name', 'JEIWS');
$flashes = take_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $mode === 'setup' ? 'Set up' : 'Sign in' ?> · <?= e($companyShort) ?> Accounts</title>
    <link rel="icon" href="../favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/accounts.css?v=2">
</head>
<body class="acc-body">
<div class="acc-auth">
    <aside class="acc-auth-aside">
        <a href="../" class="acc-brand">
            <img src="../assets/logo.png" alt="" class="acc-brand-logo">
            <span class="acc-brand-text"><strong><?= e($companyShort) ?></strong><small>Accounts</small></span>
        </a>
        <div>
            <h2>Construction finance, in one place.</h2>
            <p>Project costing, invoicing, expenses and Nepal tax compliance for <?= e(setting('company_name', 'JEIWS Engineering & Construction')) ?>.</p>
            <ul class="acc-auth-features">
                <li><i class="fa-solid fa-helmet-safety"></i> Cost and profit tracked per project</li>
                <li><i class="fa-solid fa-percent"></i> VAT, TDS and income tax ready for IRD</li>
                <li><i class="fa-solid fa-book"></i> Double-entry ledgers and reports</li>
            </ul>
        </div>
        <small class="text-white-50">&copy; <?= date('Y') ?> <?= e($companyShort) ?></small>
    </aside>

    <main class="acc-auth-main">
        <div class="acc-auth-form">
            <div class="acc-auth-mobile-brand">
                <img src="../assets/logo.png" alt="">
                <strong><?= e($companyShort) ?> Accounts</strong>
            </div>

                <h1><?= $mode === 'setup' ? 'Create admin account' : 'Sign in' ?></h1>
                <p class="text-body-secondary mb-4">
                    <?= $mode === 'setup'
                        ? 'This is a fresh install. Create the first administrator account.'
                        : 'Welcome back. Sign in to manage your accounts.' ?>
                </p>

                <?php foreach ($flashes as $flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?> py-2"><?= e($flash['message']) ?></div>
                <?php endforeach ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger py-2" role="alert">
                        <?php foreach ($errors as $error): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($error) ?></div><?php endforeach ?>
                    </div>
                <?php endif ?>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <?php if ($mode === 'setup'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="setup_secret">Setup secret</label>
                            <input type="password" class="form-control" id="setup_secret" name="setup_secret" required autocomplete="off">
                            <div class="form-text">The server-side setup secret from the site's configuration.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="full_name">Full name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($fullName) ?>" required autocomplete="name">
                        </div>
                    <?php endif ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" required autocomplete="username" <?= $mode === 'login' ? 'autofocus' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="password">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" required
                                   autocomplete="<?= $mode === 'setup' ? 'new-password' : 'current-password' ?>"
                                   <?= $mode === 'setup' ? 'minlength="10"' : '' ?>>
                            <button type="button" class="btn btn-outline-secondary" id="togglePassword" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                        </div>
                        <?php if ($mode === 'setup'): ?><div class="form-text">At least 10 characters.</div><?php endif ?>
                    </div>
                    <?php if ($mode === 'setup'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="password_confirm">Confirm password</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" required autocomplete="new-password">
                        </div>
                    <?php endif ?>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mt-2">
                        <?= $mode === 'setup' ? 'Create account' : 'Sign in' ?>
                    </button>
                </form>
                <?php if ($mode === 'login'): ?>
                    <p class="small text-body-secondary mt-4 mb-0">Forgot your password? Ask an administrator to reset it.</p>
                <?php endif ?>
        </div>
    </main>
</div>
<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const input = document.getElementById('password');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.innerHTML = show ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
</script>
</body>
</html>
