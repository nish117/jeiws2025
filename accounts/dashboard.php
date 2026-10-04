<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$pageTitle   = 'Dashboard';
$activeNav   = 'dashboard';
$breadcrumbs = [['label' => 'Dashboard']];
$pageActions = '<a href="invoice-edit.php?type=sales" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New invoice</a>'
             . '<a href="invoice-edit.php?type=purchase&photo=1" class="btn btn-outline-secondary"><i class="fa-solid fa-camera me-1"></i> Record supplier bill</a>';

// Nepal fiscal year months, Shrawan → Ashadh.
$fiscalMonths = ['Shrawan', 'Bhadra', 'Ashwin', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra', 'Baishakh', 'Jestha', 'Ashadh'];
$fy = fiscal_year_for(date('Y-m-d'));
[$fyStart, $fyEnd] = fiscal_year_range($fy);

// All figures come from posted ledger entries.
$accounts = account_tree();
$fyTotals = account_totals($fyStart, $fyEnd);
$allTotals = account_totals();
$revenue = $expenses = 0;
foreach ($fyTotals as $id => $t) {
    $type = $accounts[$id]['type'] ?? '';
    if ($type === 'income')  $revenue  += $t['credit'] - $t['debit'];
    if ($type === 'expense') $expenses += $t['debit'] - $t['credit'];
}
$balanceOf = fn(int $id) => ($allTotals[$id]['debit'] ?? 0) - ($allTotals[$id]['credit'] ?? 0);
$receivables = $balanceOf(system_account_id('accounts_receivable')) + $balanceOf(system_account_id('retention_receivable'));
$cash = array_sum(array_column(cash_bank_accounts(), 'balance'));

$kpis = [
    ['label' => 'Income (FY)',   'value' => $revenue,     'icon' => 'fa-arrow-trend-up',   'tone' => 'green', 'note' => 'FY ' . $fy],
    ['label' => 'Expenses (FY)', 'value' => $expenses,    'icon' => 'fa-arrow-trend-down', 'tone' => 'red',   'note' => 'FY ' . $fy],
    ['label' => 'Receivables',   'value' => $receivables, 'icon' => 'fa-hourglass-half',   'tone' => 'gold',  'note' => 'Owed by clients incl. retention'],
    ['label' => 'Cash & Bank',   'value' => $cash,        'icon' => 'fa-building-columns', 'tone' => 'blue',  'note' => 'All cash, bank and wallet accounts'],
];

// Monthly income/expense by B.S. month of the fiscal year.
$monthlyIncome   = array_fill(0, 12, 0);
$monthlyExpenses = array_fill(0, 12, 0);
$byDay = db()->prepare(
    "SELECT e.entry_date, a.type, SUM(l.debit) AS dr, SUM(l.credit) AS cr
     FROM acc_journal_lines l
     JOIN acc_journal_entries e ON e.id = l.entry_id
     JOIN acc_accounts a ON a.id = l.account_id
     WHERE e.status = 'posted' AND a.type IN ('income','expense') AND e.entry_date BETWEEN ? AND ?
     GROUP BY e.entry_date, a.type"
);
$byDay->execute([$fyStart, $fyEnd]);
foreach ($byDay->fetchAll() as $row) {
    $bsMonth = (int)explode('-', bs_date($row['entry_date']))[1];
    $slot = ($bsMonth + 8) % 12; // Shrawan (4) → 0 … Ashadh (3) → 11
    $net = decimal_to_cents($row['cr']) - decimal_to_cents($row['dr']);
    if ($row['type'] === 'income') $monthlyIncome[$slot] += $net / 100;
    else $monthlyExpenses[$slot] += -$net / 100;
}

// Setup checklist — real, from settings.
$checklist = [
    ['done' => setting('company_address') !== '', 'label' => 'Add company address and contact details', 'href' => 'settings.php'],
    ['done' => setting('pan_number') !== '',      'label' => 'Enter your PAN number',                   'href' => 'settings.php#tax'],
    ['done' => setting('vat_number') !== '',      'label' => 'Enter your VAT registration number',      'href' => 'settings.php#tax'],
    ['done' => (int)db()->query('SELECT COUNT(*) FROM acc_users')->fetchColumn() > 1, 'label' => 'Invite your accountant', 'href' => 'settings.php#users'],
    ['done' => (bool)db()->query("SELECT 1 FROM acc_journal_entries WHERE status = 'posted' LIMIT 1")->fetchColumn(), 'label' => 'Post your opening balances', 'href' => 'accounting.php'],
];
$checklistDone = count(array_filter($checklist, fn($c) => $c['done']));

$activity = db()->query(
    'SELECT l.action, l.entity, l.created_at, u.full_name
     FROM acc_audit_log l LEFT JOIN acc_users u ON u.id = l.user_id
     ORDER BY l.created_at DESC LIMIT 6'
)->fetchAll();
$actionLabels = [
    'login' => 'signed in', 'logout' => 'signed out', 'setup_admin' => 'set up the accounts system',
    'update_settings' => 'updated company settings', 'create_user' => 'added a user',
    'update_user' => 'updated a user', 'change_password' => 'changed their password', 'update_profile' => 'updated their profile',
    'create_journal' => 'created a journal voucher', 'post_journal' => 'posted a voucher', 'void_journal' => 'voided a voucher',
    'delete_journal_draft' => 'deleted a draft voucher', 'create_account' => 'added an account', 'update_account' => 'updated an account', 'delete_account' => 'deleted an account',
    'create_client' => 'added a client', 'update_client' => 'updated a client', 'delete_client' => 'deleted a client',
    'create_supplier' => 'added a supplier', 'update_supplier' => 'updated a supplier', 'delete_supplier' => 'deleted a supplier',
    'create_project' => 'created a project', 'update_project' => 'updated a project', 'delete_project' => 'deleted a project',
    'create_employee' => 'added an employee', 'update_employee' => 'updated an employee', 'delete_employee' => 'removed an employee',
    'create_payroll' => 'started a payroll run', 'update_payroll' => 'recalculated payroll', 'post_payroll' => 'posted payroll',
    'pay_payroll' => 'recorded salary payment', 'void_payroll' => 'voided a payroll run', 'delete_payroll' => 'deleted a draft payroll', 'update_payroll_settings' => 'changed payroll rates', 'email_payslips' => 'emailed payslips',
    'save_sales_draft' => 'saved a draft invoice', 'save_purchase_draft' => 'saved a draft bill', 'post_sales' => 'posted a sales invoice', 'post_purchase' => 'posted a supplier bill',
    'record_sales_payment' => 'recorded a receipt', 'record_purchase_payment' => 'recorded a bill payment', 'void_sales' => 'voided an invoice', 'void_purchase' => 'voided a bill',
    'attach_file' => 'attached a file', 'delete_file' => 'removed a file', 'void_payment' => 'voided a payment', 'apply_advance' => 'applied an advance', 'record_payment_in' => 'recorded money received', 'record_payment_out' => 'recorded a payment', 'create_cash_account' => 'added a cash/bank account', 'update_cash_account' => 'updated a cash/bank account', 'cash_transfer' => 'transferred money between accounts', 'cash_entry_in' => 'recorded money in', 'cash_entry_out' => 'recorded money out', 'start_reconciliation' => 'started a bank reconciliation', 'complete_reconciliation' => 'reconciled a bank account', 'undo_reconciliation' => 'reopened a bank reconciliation', 'tax_filing_vat' => 'recorded a VAT return', 'tax_filing_tds' => 'recorded a TDS deposit', 'tax_filing_income_tax' => 'recorded income tax', 'void_tax_filing' => 'voided a tax filing', 'update_income_tax_settings' => 'changed income tax settings', 'delete_sales_draft' => 'deleted a draft invoice', 'delete_purchase_draft' => 'deleted a draft bill',
];

require __DIR__ . '/includes/layout-top.php';
?>

<div class="row g-3 mb-4">
    <?php foreach ($kpis as $kpi): ?>
        <div class="col-6 col-xl-3">
            <div class="acc-card acc-kpi">
                <div class="acc-kpi-top">
                    <span class="acc-kpi-label"><?= e($kpi['label']) ?></span>
                    <span class="acc-kpi-icon acc-tone-<?= e($kpi['tone']) ?>"><i class="fa-solid <?= e($kpi['icon']) ?>"></i></span>
                </div>
                <div class="acc-kpi-value"><?= e(money_cents($kpi['value'], true)) ?></div>
                <div class="acc-kpi-note"><?= e($kpi['note']) ?></div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="acc-card h-100">
            <div class="acc-card-head">
                <h2>Income vs expenses</h2>
                <span class="small text-body-secondary">FY <?= e($fy) ?></span>
            </div>
            <div class="acc-card-body">
                <div class="acc-chart-wrap">
                    <canvas id="incomeExpenseChart" aria-label="Monthly income and expenses chart" role="img"></canvas>
                    <?php if (!array_filter($monthlyIncome) && !array_filter($monthlyExpenses)): ?>
                        <div class="acc-chart-empty">
                            <strong>No income or expenses this year yet</strong>
                            <span>Monthly totals appear here as income and expense entries are posted.</span>
                        </div>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="acc-card h-100">
            <div class="acc-card-head">
                <h2>Getting started</h2>
                <span class="badge text-bg-light border"><?= $checklistDone ?>/<?= count($checklist) ?></span>
            </div>
            <div class="acc-card-body">
                <div class="progress mb-3" style="height:6px" role="progressbar" aria-valuenow="<?= $checklistDone ?>" aria-valuemin="0" aria-valuemax="<?= count($checklist) ?>">
                    <div class="progress-bar" style="width:<?= round($checklistDone / count($checklist) * 100) ?>%"></div>
                </div>
                <ul class="list-unstyled mb-0 d-grid gap-2">
                    <?php foreach ($checklist as $item): ?>
                        <li class="d-flex align-items-center gap-2">
                            <?php if ($item['done']): ?>
                                <i class="fa-solid fa-circle-check text-success"></i>
                                <span class="text-body-secondary text-decoration-line-through"><?= e($item['label']) ?></span>
                            <?php else: ?>
                                <i class="fa-regular fa-circle text-body-tertiary"></i>
                                <a href="<?= e($item['href']) ?>" class="text-decoration-none"><?= e($item['label']) ?></a>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Quick actions</h2></div>
            <div class="acc-card-body">
                <div class="acc-quick-links">
                    <a class="acc-quick-link" href="project-edit.php"><i class="fa-solid fa-helmet-safety"></i> New project</a>
                    <a class="acc-quick-link" href="contact-edit.php?type=client"><i class="fa-solid fa-user-tie"></i> Add client</a>
                    <a class="acc-quick-link" href="contact-edit.php?type=supplier"><i class="fa-solid fa-truck-field"></i> Add supplier</a>
                    <a class="acc-quick-link" href="invoice-edit.php?type=sales"><i class="fa-solid fa-file-invoice-dollar"></i> Create invoice</a>
                    <a class="acc-quick-link" href="payments.php"><i class="fa-solid fa-money-bill-transfer"></i> Record payment</a>
                    <a class="acc-quick-link" href="vat.php"><i class="fa-solid fa-percent"></i> VAT return</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Recent activity</h2></div>
            <div class="acc-card-body py-2">
                <?php if (!$activity): ?>
                    <p class="text-body-secondary my-3">No activity yet.</p>
                <?php endif ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($activity as $row): ?>
                        <li class="d-flex justify-content-between gap-3 py-2 border-bottom small">
                            <span><strong><?= e($row['full_name'] ?? 'System') ?></strong> <?= e($actionLabels[$row['action']] ?? str_replace('_', ' ', $row['action'])) ?></span>
                            <span class="text-body-secondary text-nowrap"><?= e(time_ago($row['created_at'])) ?></span>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
$inlineScript = 'window.accDashboard = ' . json_encode([
    'labels'   => $fiscalMonths,
    'income'   => $monthlyIncome,
    'expenses' => $monthlyExpenses,
    'currency' => setting('currency_symbol', 'Rs.'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ';
(function () {
    const d = window.accDashboard;
    const fmt = v => d.currency + " " + Number(v).toLocaleString("en-IN");
    new Chart(document.getElementById("incomeExpenseChart"), {
        type: "bar",
        data: {
            labels: d.labels,
            datasets: [
                { label: "Income",   data: d.income,   backgroundColor: "#1B6799", borderRadius: 4, maxBarThickness: 22 },
                { label: "Expenses", data: d.expenses, backgroundColor: "#C8911A", borderRadius: 4, maxBarThickness: 22 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: "index", intersect: false },
            plugins: {
                legend: { position: "top", align: "end", labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: "rectRounded" } },
                tooltip: { callbacks: { label: c => c.dataset.label + ": " + fmt(c.parsed.y) } }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, suggestedMax: 100000, grid: { color: "#EEF1F5" }, border: { display: false },
                     ticks: { callback: v => v >= 100000 ? (v / 100000) + "L" : v.toLocaleString("en-IN") } }
            }
        }
    });
})();';
require __DIR__ . '/includes/layout-bottom.php';
