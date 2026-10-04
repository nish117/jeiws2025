<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$fy = fiscal_year_for(date('Y-m-d'));
$stats = db()->prepare(
    "SELECT SUM(status = 'posted') AS posted, SUM(status = 'draft') AS drafts,
            COALESCE(SUM(CASE WHEN status = 'posted' THEN total_amount END), 0) AS volume
     FROM acc_journal_entries WHERE fiscal_year = ?"
);
$stats->execute([$fy]);
$s = $stats->fetch();
$accountCount = (int)db()->query('SELECT COUNT(*) FROM acc_accounts WHERE is_group = 0 AND is_active = 1')->fetchColumn();
$allDrafts = (int)db()->query("SELECT COUNT(*) FROM acc_journal_entries WHERE status = 'draft'")->fetchColumn();

$totals = account_totals();
$dr = array_sum(array_column($totals, 'debit'));
$cr = array_sum(array_column($totals, 'credit'));
$hasPostings = $dr > 0 || $cr > 0;

$recent = db()->query(
    "SELECT id, voucher_no, entry_date, narration, total_amount, status FROM acc_journal_entries
     ORDER BY created_at DESC LIMIT 6"
)->fetchAll();

$pageTitle   = 'Accounting';
$activeNav   = 'accounting';
$breadcrumbs = [['label' => 'Accounting']];
$pageActions = can_edit_books($user) ? '<a href="journal-entry.php" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New voucher</a>' : '';
require __DIR__ . '/includes/layout-top.php';
$accountingTab = 'overview';
require __DIR__ . '/includes/accounting-nav.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Posted vouchers</span><span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid fa-file-circle-check"></i></span></div>
            <div class="acc-kpi-value"><?= (int)$s['posted'] ?></div>
            <div class="acc-kpi-note">FY <?= e($fy) ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <a href="journal.php?status=draft&fy=all" class="acc-card acc-kpi text-decoration-none text-body">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Drafts</span><span class="acc-kpi-icon acc-tone-gold"><i class="fa-solid fa-pen-ruler"></i></span></div>
            <div class="acc-kpi-value"><?= $allDrafts ?></div>
            <div class="acc-kpi-note"><?= $allDrafts ? 'Waiting to be posted' : 'Nothing pending' ?></div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Posted volume</span><span class="acc-kpi-icon acc-tone-green"><i class="fa-solid fa-arrow-right-arrow-left"></i></span></div>
            <div class="acc-kpi-value"><?= e(money($s['volume'])) ?></div>
            <div class="acc-kpi-note">Total debits, FY <?= e($fy) ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <a href="trial-balance.php" class="acc-card acc-kpi text-decoration-none text-body">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Books</span>
                <span class="acc-kpi-icon <?= $dr === $cr ? 'acc-tone-green' : 'acc-tone-red' ?>"><i class="fa-solid fa-scale-balanced"></i></span></div>
            <div class="acc-kpi-value"><?= !$hasPostings ? 'Empty' : ($dr === $cr ? 'Balanced' : 'Unbalanced') ?></div>
            <div class="acc-kpi-note"><?= $accountCount ?> active accounts</div>
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Recent vouchers</h2><a href="journal.php" class="small">View all</a></div>
            <?php if (!$recent): ?>
                <div class="acc-card-body text-body-secondary">No vouchers yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr style="cursor:pointer" onclick="location.href='journal-entry.php?id=<?= (int)$r['id'] ?>'">
                                <td class="text-nowrap"><a href="journal-entry.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= e($r['voucher_no'] ?? 'Draft #' . $r['id']) ?></a><div class="small text-body-secondary"><?= e(bs_date($r['entry_date'])) ?></div></td>
                                <td><div class="text-truncate" style="max-width:260px"><?= e($r['narration']) ?></div></td>
                                <td class="num"><?= e(money($r['total_amount'], false)) ?></td>
                                <td><?= status_badge($r['status']) ?></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Starting your books</h2></div>
            <div class="acc-card-body">
                <ol class="ps-3 mb-0 d-grid gap-2">
                    <li>Review the <a href="chart-of-accounts.php">chart of accounts</a> — rename accounts or add your bank accounts under <em>1130 Bank Accounts</em>.</li>
                    <li>Post an <strong>opening balance voucher</strong> dated the first day you start using the system: debit each asset (cash, bank, receivables…), credit each liability, and put the difference to <em>3300 Opening Balance Equity</em>.</li>
                    <li>Check the <a href="trial-balance.php">trial balance</a> matches your last audited balance sheet.</li>
                </ol>
                <?php if (can_edit_books($user)): ?>
                    <a href="journal-entry.php" class="btn btn-outline-primary btn-sm mt-3"><i class="fa-solid fa-plus me-1"></i> New voucher</a>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
