<?php
session_start();
define('SITE_LOADED', 1);
require_once __DIR__ . '/functions.php';
requireSiteAuth();

$userId  = currentSiteUserId();
$csrf    = siteCsrfToken();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['site_csrf_token'] ?? '', $token)) {
        $error = 'Your session expired — please try again.';
    } else {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = db()->prepare('SELECT password_hash FROM site_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, $hash)) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (password_verify($new, $hash)) {
            $error = 'New password must be different from your current password.';
        } else {
            db()->prepare('UPDATE site_users SET password_hash = :h, updated_at = NOW() WHERE id = :id')
                ->execute(['h' => password_hash($new, PASSWORD_BCRYPT), 'id' => $userId]);
            $success = 'Password updated successfully.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Change Password — JEIWS Site</title>
<link rel="stylesheet" href="../admin/cms.css">
<link rel="stylesheet" href="site.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">
    JEIWS <span>SITE</span>
  </a>
  <div class="cms-nav-right">
    <span class="site-welcome">Hi, <?= htmlspecialchars($_SESSION['site_user_name']) ?></span>
    <a href="change_password.php" class="active"><i class="fa-solid fa-key"></i> Password</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="breadcrumb">
    <a href="index.php">My Projects</a>
    <span class="sep">›</span>
    <span>Change Password</span>
  </div>

  <div class="page-hdr">
    <div>
      <h1>Change Password</h1>
      <p>Update the password for your account</p>
    </div>
  </div>

  <div class="card" style="max-width:420px">
    <?php if ($error): ?>
    <div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif ?>
    <?php if ($success): ?>
    <div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" name="current_password" required autocomplete="current-password" placeholder="Enter current password">
      </div>
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" required autocomplete="new-password" placeholder="Minimum 8 characters">
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="Repeat new password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
        <i class="fa-solid fa-key"></i> Update Password
      </button>
    </form>
  </div>
</main>

</body>
</html>