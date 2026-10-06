<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Opens the app shell: <head>, sidebar, top bar, breadcrumb and flash
 * messages. Pages set these before including it:
 *   $pageTitle   string   — heading + <title>
 *   $activeNav   string   — sidebar key to highlight (see navigation.php)
 *   $breadcrumbs array    — [['label' => 'Invoices', 'href' => 'invoices.php'], ['label' => 'New']]
 *   $pageActions string   — optional raw HTML for buttons beside the heading
 * Close the shell with layout-bottom.php.
 */
$user        = $user ?? require_login();
$pageTitle   = $pageTitle ?? 'Accounts';
$activeNav   = $activeNav ?? '';
$breadcrumbs = $breadcrumbs ?? [['label' => $pageTitle]];
$pageActions = $pageActions ?? '';
$navItems    = require __DIR__ . '/navigation.php';

$companyName  = setting('company_name', 'JEIWS Engineering & Construction');
$companyShort = setting('company_short_name', 'JEIWS');

$notifStmt = db()->prepare(
    'SELECT id, title, message, link, icon, is_read, created_at FROM acc_notifications
     WHERE user_id = ? ORDER BY created_at DESC LIMIT 8'
);
$notifStmt->execute([$user['id']]);
$notifications = $notifStmt->fetchAll();
$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));

$initials = implode('', array_map(
    fn($part) => mb_strtoupper(mb_substr($part, 0, 1)),
    array_slice(preg_split('/\s+/', trim($user['full_name'])), 0, 2)
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e($companyShort) ?> Accounts</title>
    <link rel="icon" href="<?= e(acc_url('../favicon.ico')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(acc_url('assets/css/accounts.css')) ?>?v=5">
</head>
<body class="acc-body">

<!-- ═════════════════ SIDEBAR ═════════════════ -->
<aside class="acc-sidebar" id="accSidebar" aria-label="Main navigation">
    <a href="<?= e(acc_url('dashboard.php')) ?>" class="acc-brand">
        <img src="<?= e(acc_url('../assets/logo.png')) ?>" alt="" class="acc-brand-logo">
        <span class="acc-brand-text">
            <strong><?= e($companyShort) ?></strong>
            <small>Accounts</small>
        </span>
    </a>

    <nav class="acc-nav">
        <?php $lastSection = null; foreach ($navItems as $item): ?>
            <?php if ($item['section'] !== $lastSection): $lastSection = $item['section']; ?>
                <div class="acc-nav-section"><?= e($item['section']) ?></div>
            <?php endif ?>
            <a href="<?= e(acc_url($item['href'])) ?>"
               class="acc-nav-link<?= $item['key'] === $activeNav ? ' active' : '' ?>"
               <?= $item['key'] === $activeNav ? 'aria-current="page"' : '' ?>
               data-bs-title="<?= e($item['label']) ?>">
                <i class="fa-solid <?= e($item['icon']) ?> fa-fw"></i>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach ?>
    </nav>

    <div class="acc-sidebar-footer">
        <span class="acc-fy-badge"><i class="fa-regular fa-calendar"></i> <span>FY <?= e(fiscal_year_for(date('Y-m-d'))) ?></span></span>
    </div>
</aside>
<div class="acc-sidebar-backdrop" id="accSidebarBackdrop"></div>

<!-- ═════════════════ MAIN ═════════════════ -->
<div class="acc-main">
    <header class="acc-topbar">
        <button type="button" class="acc-icon-btn" id="accSidebarToggle" aria-label="Toggle navigation" aria-controls="accSidebar">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="acc-topbar-company d-none d-md-flex">
            <span class="acc-topbar-company-name"><?= e($companyName) ?></span>
        </div>

        <div class="acc-topbar-actions">
            <!-- Notifications -->
            <div class="dropdown">
                <button type="button" class="acc-icon-btn position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unreadCount): ?>
                        <span class="acc-badge-dot" id="accNotifCount"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                    <?php endif ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end acc-notif-menu shadow">
                    <div class="acc-notif-head">
                        <strong>Notifications</strong>
                        <?php if ($unreadCount): ?>
                            <button type="button" class="btn btn-link btn-sm p-0" id="accMarkAllRead">Mark all read</button>
                        <?php endif ?>
                    </div>
                    <div class="acc-notif-list">
                        <?php if (!$notifications): ?>
                            <div class="acc-notif-empty">
                                <i class="fa-regular fa-bell-slash"></i>
                                <p>You're all caught up.</p>
                            </div>
                        <?php endif ?>
                        <?php foreach ($notifications as $n): ?>
                            <a href="<?= e($n['link'] ? acc_url($n['link']) : '#') ?>" class="acc-notif-item<?= $n['is_read'] ? '' : ' unread' ?>">
                                <span class="acc-notif-icon"><i class="fa-solid <?= e($n['icon']) ?>"></i></span>
                                <span class="acc-notif-body">
                                    <strong><?= e($n['title']) ?></strong>
                                    <?php if ($n['message']): ?><span><?= e($n['message']) ?></span><?php endif ?>
                                    <small><?= e(time_ago($n['created_at'])) ?></small>
                                </span>
                            </a>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>

            <!-- User menu -->
            <div class="dropdown">
                <button type="button" class="acc-user-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="acc-avatar"><?= e($initials) ?></span>
                    <span class="acc-user-meta d-none d-sm-flex">
                        <strong><?= e($user['full_name']) ?></strong>
                        <small><?= e(ucfirst($user['role'])) ?></small>
                    </span>
                    <i class="fa-solid fa-chevron-down acc-user-caret d-none d-sm-inline"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow acc-user-menu">
                    <li class="px-3 py-2 border-bottom">
                        <div class="fw-semibold"><?= e($user['full_name']) ?></div>
                        <div class="small text-body-secondary"><?= e($user['email']) ?></div>
                    </li>
                    <li><a class="dropdown-item" href="<?= e(acc_url('profile.php')) ?>"><i class="fa-regular fa-user fa-fw me-2"></i>My profile</a></li>
                    <li><a class="dropdown-item" href="<?= e(acc_url('profile.php#password')) ?>"><i class="fa-solid fa-key fa-fw me-2"></i>Change password</a></li>
                    <?php if ($user['role'] === 'admin'): ?>
                        <li><a class="dropdown-item" href="<?= e(acc_url('settings.php')) ?>"><i class="fa-solid fa-gear fa-fw me-2"></i>Settings</a></li>
                    <?php endif ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="<?= e(acc_url('logout.php')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item text-danger"><i class="fa-solid fa-arrow-right-from-bracket fa-fw me-2"></i>Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <main class="acc-content">
        <div class="acc-page-head">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb acc-breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= e(acc_url('dashboard.php')) ?>"><i class="fa-solid fa-house"></i><span class="visually-hidden">Home</span></a></li>
                        <?php foreach ($breadcrumbs as $i => $crumb): $isLast = $i === array_key_last($breadcrumbs); ?>
                            <li class="breadcrumb-item<?= $isLast ? ' active' : '' ?>"<?= $isLast ? ' aria-current="page"' : '' ?>>
                                <?php if (!$isLast && !empty($crumb['href'])): ?>
                                    <a href="<?= e(acc_url($crumb['href'])) ?>"><?= e($crumb['label']) ?></a>
                                <?php else: ?>
                                    <?= e($crumb['label']) ?>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ol>
                </nav>
                <h1 class="acc-page-title"><?= e($pageTitle) ?></h1>
            </div>
            <?php if ($pageActions): ?><div class="acc-page-actions"><?= $pageActions ?></div><?php endif ?>
        </div>

        <?php foreach (take_flashes() as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach ?>
