<?php
session_start();
if (isset($_SESSION['cms_auth'])) { header('Location: index.php'); exit; }

$credFile = __DIR__ . '/../data/cms_credentials.txt';
$isSetup  = !file_exists($credFile);
$error    = '';
$success  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isSetup) {
        $pw  = $_POST['password']  ?? '';
        $pw2 = $_POST['password2'] ?? '';
        if (strlen($pw) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($pw !== $pw2) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($pw, PASSWORD_BCRYPT);
            $dir  = dirname($credFile);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($credFile, $hash);
            $success = 'Password created — you can now log in.';
            $isSetup = false;
        }
    } else {
        $stored = trim(file_get_contents($credFile));
        if (password_verify($_POST['password'] ?? '', $stored)) {
            $_SESSION['cms_auth'] = true;
            header('Location: index.php'); exit;
        }
        $error = 'Incorrect password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $isSetup ? 'Setup' : 'Login' ?> — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <img src="../assets/logo.png" alt="JEIWS" style="height:40px;object-fit:contain;margin-bottom:18px;display:block;filter:brightness(0) saturate(100%) invert(27%) sepia(55%) saturate(500%) hue-rotate(175deg)">
    <h1><?= $isSetup ? 'Create Admin Password' : 'CMS Login' ?></h1>
    <p><?= $isSetup ? 'Set a password to protect your CMS.' : 'Sign in to manage projects.' ?></p>

    <?php if ($error):   ?><div class="alert alert-err"><?= htmlspecialchars($error)   ?></div><?php endif ?>
    <?php if ($success): ?><div class="alert alert-ok" ><?= htmlspecialchars($success) ?></div><?php endif ?>

    <form method="POST">
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required autofocus placeholder="Enter password">
      </div>
      <?php if ($isSetup): ?>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="password2" required placeholder="Repeat password">
      </div>
      <?php endif ?>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:4px">
        <?= $isSetup ? 'Set Password' : 'Sign In' ?>
      </button>
    </form>
  </div>
</div>
</body>
</html>
