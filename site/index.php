<?php
session_start();
define('SITE_LOADED', 1);
require_once __DIR__ . '/functions.php';
requireSiteAuth();

$projects = getAssignedProjects(currentSiteUserId());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Projects — JEIWS Site</title>
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
    <a href="change_password.php"><i class="fa-solid fa-key"></i> Password</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <div>
      <h1>My Projects</h1>
      <p><?= count($projects) ?> project<?= count($projects) !== 1 ? 's' : '' ?> assigned to you</p>
    </div>
  </div>

  <?php if (empty($projects)): ?>
  <div class="empty">
    <div class="empty-icon"><i class="fa-regular fa-folder-open"></i></div>
    <h3>No projects assigned yet</h3>
    <p>Ask your admin to assign you to a project.</p>
  </div>
  <?php else: ?>
  <div class="site-projects-grid">
    <?php foreach ($projects as $p): ?>
    <div class="card site-project-card">
      <h3><?= htmlspecialchars($p['title']) ?></h3>
      <div class="site-project-actions">
        <a href="attendance.php?project=<?= urlencode($p['id']) ?>" class="btn btn-primary btn-sm">
          <i class="fa-solid fa-clipboard-user"></i> Attendance
        </a>
        <a href="stock.php?project=<?= urlencode($p['id']) ?>" class="btn btn-ghost btn-sm">
          <i class="fa-solid fa-boxes-stacked"></i> Materials
        </a>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</main>

</body>
</html>
