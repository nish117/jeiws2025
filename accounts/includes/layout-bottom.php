<?php
defined('ACC_LOADED') or die('Direct access denied.');
/** Closes the shell opened by layout-top.php. Pages may set $pageScripts (array of script URLs) and $inlineScript (raw JS). */
$pageScripts  = $pageScripts ?? [];
$inlineScript = $inlineScript ?? '';
?>
    </main>

    <footer class="acc-footer">
        <span>&copy; <?= date('Y') ?> <?= e(setting('company_name', 'JEIWS')) ?></span>
        <span class="text-body-secondary">Accounts &amp; Financial Management</span>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ($pageScripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach ?>
<script src="<?= e(acc_url('assets/js/accounts.js')) ?>?v=1"></script>
<?php if ($inlineScript): ?>
<script><?= $inlineScript ?></script>
<?php endif ?>
</body>
</html>
