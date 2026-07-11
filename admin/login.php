<?php
session_start();
if (isset($_SESSION['cms_auth'])) { header('Location: index.php'); exit; }

$credFile = __DIR__ . '/../data/cms_credentials.txt';
$isSetup  = !file_exists($credFile);
$error    = '';
$success  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isSetup) {
        // Require a server-side setup secret to prevent anyone from creating admin credentials
        $setupSecret = getenv('CMS_SETUP_SECRET');
        $givenSecret = $_POST['setup_secret'] ?? '';
        if ($setupSecret && !hash_equals($setupSecret, $givenSecret)) {
            $error = 'Invalid setup secret.';
        } else {
            $pw  = $_POST['password']  ?? '';
            $pw2 = $_POST['password2'] ?? '';
            if (strlen($pw) < 8) {
                $error = 'Password must be at least 8 characters.';
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
        }
    } else {
        $stored = trim(file_get_contents($credFile));
        if (password_verify($_POST['password'] ?? '', $stored)) {
            session_regenerate_id(true);
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <img src="../assets/logo.png" alt="JEIWS" style="height:40px;object-fit:contain;margin-bottom:18px;display:block;filter:brightness(0) saturate(100%) invert(27%) sepia(55%) saturate(500%) hue-rotate(175deg)">
    <h1><?= $isSetup ? 'Create Admin Password' : 'CMS Login' ?></h1>
    <p><?= $isSetup ? 'Set a password to protect your CMS.' : 'Sign in to manage projects.' ?></p>

    <?php if ($error):   ?><div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error)   ?></div><?php endif ?>
    <?php if ($success): ?><div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div><?php endif ?>

    <form method="POST">
      <?php if ($isSetup): ?>
      <div class="form-group">
        <label>Setup Secret</label>
        <input type="password" name="setup_secret" required autofocus autocomplete="off" placeholder="Server setup secret">
      </div>
      <?php endif ?>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required <?= !$isSetup ? 'autofocus' : '' ?>
               autocomplete="<?= $isSetup ? 'new-password' : 'current-password' ?>" placeholder="Enter password">
      </div>
      <?php if ($isSetup): ?>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="password2" required autocomplete="new-password" placeholder="Repeat password">
      </div>
      <?php endif ?>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:4px">
        <i class="fa-solid <?= $isSetup ? 'fa-shield-halved' : 'fa-right-to-bracket' ?>"></i>
        <?= $isSetup ? 'Set Password' : 'Sign In' ?>
      </button>
    </form>
  </div>
</div>
</body>
</html>
