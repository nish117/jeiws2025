<?php
session_start();
define('SITE_LOADED', 1);
require_once __DIR__ . '/functions.php';

if (!empty($_SESSION['site_user_id'])) { header('Location: index.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter both username and password.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, full_name, is_active FROM site_users WHERE username = :u');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Incorrect username or password.';
        } elseif (!$user['is_active']) {
            $error = 'This account has been deactivated. Contact your admin.';
        } else {
            session_regenerate_id(true);
            $_SESSION['site_user_id']   = (int)$user['id'];
            $_SESSION['site_user_name'] = $user['full_name'];
            header('Location: index.php'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Site Login — JEIWS</title>
<link rel="stylesheet" href="../admin/cms.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <img src="../assets/logo.png" alt="JEIWS" style="height:40px;object-fit:contain;margin-bottom:18px;display:block;filter:brightness(0) saturate(100%) invert(27%) sepia(55%) saturate(500%) hue-rotate(175deg)">
    <h1>Site Login</h1>
    <p>Sign in to log attendance &amp; materials for your projects.</p>

    <?php if ($error): ?><div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif ?>

    <form method="POST">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required autofocus autocomplete="username" placeholder="Your username">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password" placeholder="Enter password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:4px">
        <i class="fa-solid fa-right-to-bracket"></i> Sign In
      </button>
    </form>
  </div>
</div>
</body>
</html>
