<?php
defined('ACC_LOADED') or die('Direct access denied.');
/** Tab strip shared by the Payroll pages. Set $payrollTab before including. */
$payrollTabs = [
    'runs'      => ['Payroll runs',        'payroll.php',          'fa-money-check-dollar'],
    'employees' => ['Employees',           'employees.php',        'fa-id-badge'],
    'settings'  => ['Payroll rates', 'payroll-settings.php', 'fa-sliders'],
];
?>
<nav class="acc-subnav d-print-none" aria-label="Payroll sections">
    <?php foreach ($payrollTabs as $key => [$label, $href, $icon]): ?>
        <a href="<?= e(acc_url($href)) ?>" class="<?= ($payrollTab ?? '') === $key ? 'active' : '' ?>" <?= ($payrollTab ?? '') === $key ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= $icon ?>"></i> <?= $label ?>
        </a>
    <?php endforeach ?>
</nav>
