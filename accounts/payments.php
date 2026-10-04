<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canEdit = can_edit_books($user);

[$fyStart] = fiscal_year_range(fiscal_year_for(date('Y-m-d')));
$dir    = in_array($_GET['dir'] ?? '', ['in', 'out'], true) ? $_GET['dir'] : '';
$method = isset(PAYMENT_METHODS[$_GET['method'] ?? '']) ? $_GET['method'] : '';
$from   = valid_ad_date((string)($_GET['from'] ?? '')) ? $_GET['from'] : $fyStart;
$to     = valid_ad_date((string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$q      = trim((string)($_GET['q'] ?? ''));
$showVoid = !empty($_GET['void']);

// Client/supplier payments.
$where = ['p.payment_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($dir)       { $where[] = 'p.direction = ?'; $params[] = $dir; }
if ($method)    { $where[] = 'p.method = ?'; $params[] = $method; }
if (!$showVoid) $where[] = "p.status = 'active'";
if ($q !== '')  { $where[] = '(c.name LIKE ? OR p.reference LIKE ? OR j.voucher_no LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%"); }
$stmt = db()->prepare(
    "SELECT p.id, p.direction, p.payment_date, p.amount, p.unallocated_amount, p.method, p.reference, p.status,
            c.name AS party, a.name AS account_name, j.voucher_no,
            (SELECT GROUP_CONCAT(i.number ORDER BY i.id SEPARATOR ', ') FROM acc_invoice_payments ip JOIN acc_invoices i ON i.id = ip.invoice_id WHERE ip.payment_id = p.id) AS invoices
     FROM acc_payments p JOIN acc_contacts c ON c.id = p.contact_id JOIN acc_accounts a ON a.id = p.account_id
     LEFT JOIN acc_journal_entries j ON j.id = p.journal_entry_id
     WHERE " . implode(' AND ', $where) . ' ORDER BY p.payment_date DESC, p.id DESC LIMIT 500'
);
$stmt->execute($params);
$rows = array_map(fn($r) => $r + ['kind' => 'party', 'link' => 'payment.php?id=' . $r['id']], $stmt->fetchAll());

// Salary payouts from payroll (money out, paid in one transfer per month).
if ($dir !== 'in' && (!$method || $method === 'bank_transfer') && ($q === '' || stripos('salaries payroll', $q) !== false)) {
    $sal = db()->prepare(
        "SELECT r.id AS run_id, r.bs_year, r.bs_month, r.status, j.entry_date, j.voucher_no, j.total_amount, j.reference, j.status AS je_status,
                (SELECT a.name FROM acc_journal_lines l JOIN acc_accounts a ON a.id = l.account_id WHERE l.entry_id = j.id AND l.credit > 0 LIMIT 1) AS account_name
         FROM acc_payroll_runs r JOIN acc_journal_entries j ON j.id = r.payment_entry_id
         WHERE j.entry_date BETWEEN ? AND ?" . ($showVoid ? '' : " AND j.status = 'posted'")
    );
    $sal->execute([$from, $to]);
    foreach ($sal->fetchAll() as $s) {
        $rows[] = [
            'kind' => 'payroll', 'link' => 'payroll-run.php?id=' . $s['run_id'], 'direction' => 'out', 'payment_date' => $s['entry_date'],
            'amount' => $s['total_amount'], 'unallocated_amount' => 0, 'method' => 'bank_transfer', 'reference' => $s['reference'],
            'status' => $s['je_status'] === 'void' ? 'void' : 'active', 'party' => 'Staff salaries — ' . payroll_period_label($s),
            'account_name' => $s['account_name'], 'voucher_no' => $s['voucher_no'], 'invoices' => null,
        ];
    }
    usort($rows, fn($a, $b) => strcmp($b['payment_date'], $a['payment_date']));
}

$totIn = $totOut = 0;
foreach ($rows as $r) {
    if ($r['status'] !== 'active' || $r['method'] === 'advance') continue; // adjustments move no cash
    if ($r['direction'] === 'in') $totIn += decimal_to_cents($r['amount']); else $totOut += decimal_to_cents($r['amount']);
}
$query = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['dir' => $dir, 'method' => $method, 'from' => $from, 'to' => $to, 'q' => $q, 'void' => $showVoid ? 1 : null], $o), fn($v) => $v !== null && $v !== ''));

$pageTitle   = 'Payments';
$activeNav   = 'payments';
$breadcrumbs = [['label' => 'Payments']];
$pageActions = $canEdit
    ? '<a href="payment-edit.php?direction=in" class="btn btn-primary"><i class="fa-solid fa-arrow-down me-1"></i> Receive money</a>'
    . '<a href="payment-edit.php?direction=out" class="btn btn-outline-primary"><i class="fa-solid fa-arrow-up me-1"></i> Make payment</a>'
    : '';
require __DIR__ . '/includes/layout-top.php';
?>

<nav class="acc-subnav">
    <a href="<?= e($query(['dir' => ''])) ?>" class="<?= $dir === '' ? 'active' : '' ?>"><i class="fa-solid fa-right-left"></i> All</a>
    <a href="<?= e($query(['dir' => 'in'])) ?>" class="<?= $dir === 'in' ? 'active' : '' ?>"><i class="fa-solid fa-arrow-down"></i> Received</a>
    <a href="<?= e($query(['dir' => 'out'])) ?>" class="<?= $dir === 'out' ? 'active' : '' ?>"><i class="fa-solid fa-arrow-up"></i> Paid</a>
</nav>

<div class="row g-3 mb-3">
    <div class="col-sm-4"><div class="acc-card acc-kpi"><div class="acc-kpi-top"><span class="acc-kpi-label">Received</span><span class="acc-kpi-icon acc-tone-green"><i class="fa-solid fa-arrow-down"></i></span></div><div class="acc-kpi-value"><?= e(money_cents($totIn, true)) ?></div><div class="acc-kpi-note">In this period</div></div></div>
    <div class="col-sm-4"><div class="acc-card acc-kpi"><div class="acc-kpi-top"><span class="acc-kpi-label">Paid</span><span class="acc-kpi-icon acc-tone-red"><i class="fa-solid fa-arrow-up"></i></span></div><div class="acc-kpi-value"><?= e(money_cents($totOut, true)) ?></div><div class="acc-kpi-note">Including salaries</div></div></div>
    <div class="col-sm-4"><div class="acc-card acc-kpi"><div class="acc-kpi-top"><span class="acc-kpi-label">Net cash flow</span><span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid fa-scale-balanced"></i></span></div><div class="acc-kpi-value <?= $totIn - $totOut < 0 ? 'text-danger' : '' ?>"><?= e(money_cents($totIn - $totOut, true)) ?></div><div class="acc-kpi-note">Received − paid</div></div></div>
</div>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
        <div><label class="form-label" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label class="form-label" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <div>
            <label class="form-label" for="method">Method</label>
            <select class="form-select form-select-sm" id="method" name="method">
                <option value="">All</option>
                <?php foreach (PAYMENT_METHODS as $k => $label): ?><option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="flex-grow-1" style="max-width:280px"><label class="form-label" for="q">Search</label><input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Party, reference or voucher"></div>
        <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="void" name="void" value="1" <?= $showVoid ? 'checked' : '' ?>><label class="form-check-label small" for="void">Show void</label></div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>

    <?php if (!$rows): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid fa-money-bill-transfer"></i></div>
            <h3>No payments in this period</h3>
            <p>Record money received from clients and paid to suppliers. One payment can settle several invoices, and anything extra is kept as an advance.</p>
            <?php if ($canEdit): ?>
                <div class="d-flex gap-2 justify-content-center mt-3">
                    <a href="payment-edit.php?direction=in" class="btn btn-primary"><i class="fa-solid fa-arrow-down me-1"></i> Receive money</a>
                    <a href="payment-edit.php?direction=out" class="btn btn-outline-primary"><i class="fa-solid fa-arrow-up me-1"></i> Make payment</a>
                </div>
            <?php endif ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Voucher</th><th>Party</th><th class="d-none d-lg-table-cell">For</th><th class="d-none d-md-table-cell">Account / method</th><th class="num">In</th><th class="num">Out</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $amt = e(money($r['amount'], false)); $isAdj = $r['method'] === 'advance'; ?>
                    <tr class="<?= $r['status'] === 'void' ? 'acc-inactive' : '' ?>" style="cursor:pointer" onclick="location.href='<?= e($r['link']) ?>'">
                        <td class="text-nowrap"><?= e(bs_date($r['payment_date'])) ?><div class="small text-body-secondary"><?= e(date('d M Y', strtotime($r['payment_date']))) ?></div></td>
                        <td class="text-nowrap"><a href="<?= e($r['link']) ?>" class="text-decoration-none fw-semibold"><?= e($r['voucher_no'] ?? '—') ?></a><?= $r['status'] === 'void' ? ' <span class="badge text-bg-secondary">Void</span>' : '' ?></td>
                        <td><?= e($r['party']) ?><?= $r['reference'] && !$isAdj ? '<div class="small text-body-secondary">Ref ' . e($r['reference']) . '</div>' : '' ?></td>
                        <td class="d-none d-lg-table-cell small">
                            <?= $r['invoices'] ? e($r['invoices']) : '' ?>
                            <?php if ((float)$r['unallocated_amount'] > 0): ?><span class="badge text-bg-light border">Advance <?= e(money($r['unallocated_amount'], false)) ?></span><?php endif ?>
                            <?= $r['kind'] === 'payroll' ? '<span class="text-body-secondary">Payroll</span>' : '' ?>
                        </td>
                        <td class="d-none d-md-table-cell small"><?= $isAdj ? '<span class="badge text-bg-light border">Advance applied</span>' : e($r['account_name'] . ' · ' . (PAYMENT_METHODS[$r['method']] ?? '')) ?></td>
                        <td class="num text-success"><?= $r['direction'] === 'in' ? ($isAdj ? '<span class="text-body-secondary">(' . $amt . ')</span>' : $amt) : '' ?></td>
                        <td class="num"><?= $r['direction'] === 'out' ? ($isAdj ? '<span class="text-body-secondary">(' . $amt . ')</span>' : $amt) : '' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top small text-body-secondary">Amounts in brackets are advances applied to invoices — no cash moved, so they're not in the totals.</div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
