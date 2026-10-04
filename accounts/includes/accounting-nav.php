<?php
defined('ACC_LOADED') or die('Direct access denied.');
/** Tab strip shared by the Accounting pages. Set $accountingTab before including. */
$accountingTabs = [
    'overview' => ['Overview',          'accounting.php',         'fa-book'],
    'chart'    => ['Chart of accounts', 'chart-of-accounts.php',  'fa-sitemap'],
    'journal'  => ['Journal vouchers',  'journal.php',            'fa-file-pen'],
    'ledger'   => ['General ledger',    'ledger.php',             'fa-book-open'],
    'trial'    => ['Trial balance',     'trial-balance.php',      'fa-scale-balanced'],
];
?>
<nav class="acc-subnav d-print-none" aria-label="Accounting sections">
    <?php foreach ($accountingTabs as $key => [$label, $href, $icon]): ?>
        <a href="<?= e(acc_url($href)) ?>" class="<?= ($accountingTab ?? '') === $key ? 'active' : '' ?>" <?= ($accountingTab ?? '') === $key ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= $icon ?>"></i> <?= e($label) ?>
        </a>
    <?php endforeach ?>
</nav>
