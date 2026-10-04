<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Renders a "module under construction" page inside the app shell. Each
 * module file (invoices.php, vat.php, …) calls this until its real
 * implementation replaces it.
 *
 * @param array{key:string,title:string,icon:string,description:string,planned:string[]} $module
 */
function render_module_placeholder(array $module): void {
    $user        = require_login();
    $pageTitle   = $module['title'];
    $activeNav   = $module['key'];
    $breadcrumbs = [['label' => $module['title']]];

    require __DIR__ . '/layout-top.php';
    ?>
    <div class="acc-card">
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid <?= e($module['icon']) ?>"></i></div>
            <h3><?= e($module['title']) ?> is being set up</h3>
            <p><?= e($module['description']) ?></p>
        </div>
        <?php if (!empty($module['planned'])): ?>
            <div class="border-top px-4 py-4">
                <div class="small fw-bold text-uppercase text-body-secondary mb-3" style="letter-spacing:.06em">What this module will include</div>
                <div class="row g-2">
                    <?php foreach ($module['planned'] as $feature): ?>
                        <div class="col-md-6">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fa-solid fa-check text-success mt-1"></i>
                                <span><?= e($feature) ?></span>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endif ?>
    </div>
    <?php
    require __DIR__ . '/layout-bottom.php';
}
