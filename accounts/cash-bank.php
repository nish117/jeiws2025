<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

$showClosed = !empty($_GET['closed']);
$accounts = cash_bank_accounts(!$showClosed);
$totals = ['bank' => 0, 'cash' => 0, 'wallet' => 0];
foreach ($accounts as $a) if ($a['is_active']) $totals[$a['kind']] += $a['balance'];
$inProgress = [];
foreach (db()->query("SELECT account_id, statement_date FROM acc_bank_reconciliations WHERE status = 'in_progress'") as $r) $inProgress[(int)$r['account_id']] = $r['statement_date'];
$lastRecon = [];
foreach (db()->query("SELECT account_id, MAX(statement_date) d FROM acc_bank_reconciliations WHERE status = 'completed' GROUP BY account_id") as $r) $lastRecon[(int)$r['account_id']] = $r['d'];

$pageTitle   = 'Cash & Bank';
$activeNav   = 'cash-bank';
$breadcrumbs = [['label' => 'Cash & Bank']];
$pageActions = $canEdit
    ? '<a href="cash-transfer.php" class="btn btn-outline-primary"><i class="fa-solid fa-right-left me-1"></i> Transfer</a>'
    . '<a href="cash-entry.php" class="btn btn-outline-primary"><i class="fa-solid fa-plus-minus me-1"></i> Other entry</a>'
    . '<div class="dropdown d-inline-block"><button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="fa-solid fa-plus me-1"></i> Add account</button><ul class="dropdown-menu dropdown-menu-end">'
    . '<li><a class="dropdown-item" href="cash-account-edit.php?kind=bank"><i class="fa-solid fa-building-columns fa-fw me-2"></i>Bank account</a></li>'
    . '<li><a class="dropdown-item" href="cash-account-edit.php?kind=cash"><i class="fa-solid fa-money-bill-wave fa-fw me-2"></i>Cash box (e.g. site cash)</a></li>'
    . '<li><a class="dropdown-item" href="cash-account-edit.php?kind=wallet"><i class="fa-solid fa-mobile-screen fa-fw me-2"></i>eSewa / Khalti wallet</a></li></ul></div>'
    : '';
require __DIR__ . '/includes/layout-top.php';
?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Total available</span><span class="acc-kpi-icon acc-tone-green"><i class="fa-solid fa-sack-dollar"></i></span></div>
            <div class="acc-kpi-value <?= array_sum($totals) < 0 ? 'text-danger' : '' ?>"><?= e(money_cents(array_sum($totals), true)) ?></div>
            <div class="acc-kpi-note">All cash, bank and wallets</div>
        </div>
    </div>
    <?php foreach (CASH_KINDS as $k => $meta): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="acc-card acc-kpi">
                <div class="acc-kpi-top"><span class="acc-kpi-label"><?= e($meta['plural']) ?></span><span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid <?= $meta['icon'] ?>"></i></span></div>
                <div class="acc-kpi-value <?= $totals[$k] < 0 ? 'text-danger' : '' ?>"><?= e(money_cents($totals[$k], true)) ?></div>
                <div class="acc-kpi-note"><?= count(array_filter($accounts, fn($a) => $a['kind'] === $k && $a['is_active'])) ?> account(s)</div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<?php if (!array_filter($accounts, fn($a) => $a['kind'] === 'bank')): ?>
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fa-solid fa-building-columns me-1"></i> No bank accounts yet. Add each company bank account with its balance on the day you start using the system.</span>
        <?php if ($canEdit): ?><a href="cash-account-edit.php?kind=bank" class="btn btn-sm btn-primary">Add bank account</a><?php endif ?>
    </div>
<?php endif ?>

<div class="row g-3">
    <?php foreach ($accounts as $id => $a): $meta = CASH_KINDS[$a['kind']]; ?>
        <div class="col-md-6 col-xl-4">
            <a href="cash-account.php?id=<?= $id ?>" class="acc-card acc-cash-card text-decoration-none text-body d-block h-100 <?= $a['is_active'] ? '' : 'acc-inactive' ?>">
                <div class="acc-card-body">
                    <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid <?= $meta['icon'] ?>"></i></span>
                            <div>
                                <div class="fw-bold"><?= e($a['name']) ?></div>
                                <div class="small text-body-secondary">
                                    <?php if ($a['kind'] === 'bank'): ?>
                                        <?= e(trim(($a['bank_name'] ?? '') . ($a['branch'] ? ', ' . $a['branch'] : ''))) ?>
                                    <?php else: ?><?= e($meta['label']) ?><?php endif ?>
                                </div>
                            </div>
                        </div>
                        <span class="acc-code small"><?= e($a['code']) ?></span>
                    </div>
                    <div class="fs-4 fw-bold <?= $a['balance'] < 0 ? 'text-danger' : '' ?>"><?= e(money_cents($a['balance'], true)) ?></div>
                    <div class="small text-body-secondary d-flex flex-wrap gap-2 mt-1">
                        <?php if ($a['account_number']): ?><span><?= e(mask_account_number($a['account_number'])) ?><?= $a['account_type'] ? ' · ' . e(BANK_ACCOUNT_TYPES[$a['account_type']]) : '' ?></span><?php endif ?>
                        <span><?= $a['last_date'] ? 'Last activity ' . e(bs_date($a['last_date'])) : 'No transactions yet' ?></span>
                        <?php if (!$a['is_active']): ?><span class="badge text-bg-secondary">Closed</span><?php endif ?>
                    </div>
                    <?php if ($a['kind'] === 'bank'): ?>
                        <div class="small mt-2">
                            <?php if (isset($inProgress[$id])): ?>
                                <span class="text-warning-emphasis"><i class="fa-solid fa-scale-unbalanced me-1"></i>Reconciliation in progress (<?= e(bs_date($inProgress[$id])) ?>)</span>
                            <?php elseif (isset($lastRecon[$id])): ?>
                                <span class="text-success"><i class="fa-solid fa-check-double me-1"></i>Reconciled to <?= e(bs_date($lastRecon[$id])) ?></span>
                            <?php else: ?>
                                <span class="text-body-secondary"><i class="fa-regular fa-circle me-1"></i>Never reconciled</span>
                            <?php endif ?>
                        </div>
                    <?php endif ?>
                </div>
            </a>
        </div>
    <?php endforeach ?>
</div>
<p class="small text-body-secondary mt-3">
    Balances come from posted vouchers. Money received from clients and paid to suppliers is recorded under <a href="payments.php">Payments</a>;
    use <strong>Other entry</strong> here for bank charges, interest and anything not tied to an invoice.
    <a href="?<?= $showClosed ? '' : 'closed=1' ?>"><?= $showClosed ? 'Hide' : 'Show' ?> closed accounts</a>.
</p>

<?php require __DIR__ . '/includes/layout-bottom.php';
