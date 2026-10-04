<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

const REPORTS = [
    'pl'       => ['Profit & loss',          'fa-chart-line'],
    'bs'       => ['Balance sheet',          'fa-scale-balanced'],
    'ar'       => ['Receivables ageing',     'fa-hourglass-half'],
    'ap'       => ['Payables ageing',        'fa-hourglass-end'],
    'projects' => ['Project profitability',  'fa-helmet-safety'],
    'cash'     => ['Cash summary',           'fa-building-columns'],
];
$r = isset(REPORTS[$_GET['r'] ?? '']) ? $_GET['r'] : 'pl';
[$fyStart, $fyEnd] = fiscal_year_range(fiscal_year_for(date('Y-m-d')));
$from = valid_ad_date((string)($_GET['from'] ?? '')) ? $_GET['from'] : $fyStart;
$to   = valid_ad_date((string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$asOf = valid_ad_date((string)($_GET['as_of'] ?? '')) ? $_GET['as_of'] : date('Y-m-d');

$accounts = account_tree();
$topAncestor = function (int $id) use ($accounts): array {
    while ($accounts[$id]['parent_id'] && isset($accounts[(int)$accounts[$id]['parent_id']])) $id = (int)$accounts[$id]['parent_id'];
    return $accounts[$id];
};
$parentName = fn(array $a) => $a['parent_id'] && isset($accounts[(int)$a['parent_id']]) ? $accounts[(int)$a['parent_id']]['name'] : '';
$csv = [];       // rows for CSV export: [label, amount|null, …]
$title = REPORTS[$r][0];

// ── Profit & loss ────────────────────────────────────────────────────
if ($r === 'pl') {
    $totals = account_totals($from, $to);
    $taxId = system_account_id('income_tax_expense');
    $sections = ['income' => [], 'direct' => [], 'operating' => [], 'tax' => []];
    foreach ($totals as $id => $t) {
        $a = $accounts[$id] ?? null;
        if (!$a) continue;
        if ($a['type'] === 'income') $sections['income'][] = ['a' => $a, 'amt' => $t['credit'] - $t['debit']];
        elseif ($a['type'] === 'expense') {
            $key = $id === $taxId ? 'tax' : ($topAncestor($id)['code'] === '5000' ? 'direct' : 'operating');
            $sections[$key][] = ['a' => $a, 'amt' => $t['debit'] - $t['credit']];
        }
    }
    foreach ($sections as &$rows) { $rows = array_filter($rows, fn($x) => $x['amt'] !== 0); usort($rows, fn($x, $y) => strcmp($x['a']['code'], $y['a']['code'])); }
    unset($rows);
    $sum = fn(string $k) => array_sum(array_column($sections[$k], 'amt'));
    $income = $sum('income'); $direct = $sum('direct'); $operating = $sum('operating'); $tax = $sum('tax');
    $gross = $income - $direct; $pbt = $gross - $operating; $pat = $pbt - $tax;
}

// ── Balance sheet ────────────────────────────────────────────────────
if ($r === 'bs') {
    $fy = fiscal_year_for($asOf);
    [$bsFyStart] = fiscal_year_range($fy);
    $cum = account_totals(null, $asOf);
    $groups = ['asset' => [], 'liability' => [], 'equity' => []];
    $currentProfit = $priorProfit = 0;
    $current = account_totals($bsFyStart, $asOf);
    foreach ($cum as $id => $t) {
        $a = $accounts[$id] ?? null;
        if (!$a) continue;
        if (isset($groups[$a['type']])) {
            $bal = natural_balance($a['type'], $t['debit'], $t['credit']);
            if ($bal !== 0) $groups[$a['type']][$parentName($a) ?: ACC_ACCOUNT_TYPES[$a['type']]['label']][] = ['a' => $a, 'amt' => $bal];
        }
    }
    foreach ($current as $id => $t) {
        $type = $accounts[$id]['type'] ?? '';
        if ($type === 'income') $currentProfit += $t['credit'] - $t['debit'];
        if ($type === 'expense') $currentProfit -= $t['debit'] - $t['credit'];
    }
    foreach ($cum as $id => $t) {
        $type = $accounts[$id]['type'] ?? '';
        if ($type === 'income') $priorProfit += $t['credit'] - $t['debit'];
        if ($type === 'expense') $priorProfit -= $t['debit'] - $t['credit'];
    }
    $priorProfit -= $currentProfit;   // income/expense before this FY, not yet closed into retained earnings
    $groupTotal = fn(array $g) => array_sum(array_map(fn($rows) => array_sum(array_column($rows, 'amt')), $g));
    $assets = $groupTotal($groups['asset']);
    $liabilities = $groupTotal($groups['liability']);
    $equity = $groupTotal($groups['equity']) + $priorProfit + $currentProfit;
}

// ── Ageing ───────────────────────────────────────────────────────────
if ($r === 'ar' || $r === 'ap') {
    $type = $r === 'ar' ? 'sales' : 'purchase';
    $buckets = ['current' => 'Not yet due', '1_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', '90' => 'Over 90 days'];
    $stmt = db()->prepare(
        "SELECT i.id, i.number, i.invoice_date, i.due_date, i.net_amount, i.amount_paid, c.id AS cid, c.name
         FROM acc_invoices i JOIN acc_contacts c ON c.id = i.contact_id
         WHERE i.type = ? AND i.status = 'posted' AND i.invoice_date <= ? ORDER BY c.name, i.invoice_date"
    );
    $stmt->execute([$type, $asOf]);
    $parties = [];
    $grand = array_fill_keys(array_keys($buckets), 0);
    foreach ($stmt->fetchAll() as $i) {
        $out = decimal_to_cents($i['net_amount']) - decimal_to_cents($i['amount_paid']);
        if ($out <= 0) continue;
        $due = $i['due_date'] ?: $i['invoice_date'];
        $days = (int)floor((strtotime($asOf) - strtotime($due)) / 86400);
        $b = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90')));
        $cid = (int)$i['cid'];
        $parties[$cid] ??= ['name' => $i['name'], 'buckets' => array_fill_keys(array_keys($buckets), 0), 'total' => 0, 'invoices' => []];
        $parties[$cid]['buckets'][$b] += $out;
        $parties[$cid]['total'] += $out;
        $parties[$cid]['invoices'][] = $i + ['outstanding' => $out, 'days' => $days];
        $grand[$b] += $out;
    }
    uasort($parties, fn($x, $y) => $y['total'] <=> $x['total']);
}

// ── Project profitability ───────────────────────────────────────────
if ($r === 'projects') {
    $stmt = db()->prepare(
        "SELECT l.project_id, a.type, SUM(l.debit) dr, SUM(l.credit) cr FROM acc_journal_lines l
         JOIN acc_journal_entries e ON e.id = l.entry_id JOIN acc_accounts a ON a.id = l.account_id
         WHERE e.status = 'posted' AND l.project_id IS NOT NULL AND a.type IN ('income','expense') AND e.entry_date BETWEEN ? AND ?
         GROUP BY l.project_id, a.type"
    );
    $stmt->execute([$from, $to]);
    $fin = [];
    foreach ($stmt->fetchAll() as $x) {
        $fin[(int)$x['project_id']] ??= ['revenue' => 0, 'cost' => 0];
        if ($x['type'] === 'income') $fin[(int)$x['project_id']]['revenue'] += decimal_to_cents($x['cr']) - decimal_to_cents($x['dr']);
        else $fin[(int)$x['project_id']]['cost'] += decimal_to_cents($x['dr']) - decimal_to_cents($x['cr']);
    }
    $projects = db()->query("SELECT p.*, c.name AS client FROM acc_projects p LEFT JOIN acc_contacts c ON c.id = p.client_id ORDER BY FIELD(p.status,'ongoing','planned','on_hold','completed','cancelled'), p.name")->fetchAll();
}

// ── Cash summary ─────────────────────────────────────────────────────
if ($r === 'cash') {
    $cashRows = [];
    foreach (cash_bank_accounts(false) as $id => $a) {
        $open = account_movement($id, null, date('Y-m-d', strtotime($from . ' -1 day')));
        $all = account_movement($id, $from, $to);
        $tr = account_movement($id, $from, $to);
        $nonTransfer = account_movement($id, $from, $to, ['transfer']);
        $row = ['a' => $a, 'opening' => $open['dr'] - $open['cr'], 'in' => $nonTransfer['dr'], 'out' => $nonTransfer['cr'],
                'transfer_net' => ($tr['dr'] - $tr['cr']) - ($nonTransfer['dr'] - $nonTransfer['cr'])];
        $row['closing'] = $row['opening'] + $all['dr'] - $all['cr'];
        if ($row['opening'] || $all['dr'] || $all['cr'] || $a['is_active']) $cashRows[] = $row;
    }
}

// ── CSV ──────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $r . '-' . ($r === 'bs' || $r === 'ar' || $r === 'ap' ? $asOf : "{$from}-to-{$to}") . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [setting('company_name'), $title, in_array($r, ['bs', 'ar', 'ap'], true) ? 'As of ' . bs_date($asOf) . ' B.S.' : bs_date($from) . ' to ' . bs_date($to) . ' B.S.']);
    $d = fn(int $c) => cents_to_decimal($c);
    if ($r === 'pl') {
        foreach (['income' => 'Income', 'direct' => 'Direct project costs', 'operating' => 'Operating expenses', 'tax' => 'Income tax'] as $k => $label) {
            fputcsv($out, [$label]);
            foreach ($sections[$k] as $x) fputcsv($out, ['  ' . $x['a']['code'] . ' ' . $x['a']['name'], $d($x['amt'])]);
            fputcsv($out, ["Total {$label}", $d($sum($k))]);
            if ($k === 'direct') fputcsv($out, ['Gross profit', $d($gross)]);
            if ($k === 'operating') fputcsv($out, ['Profit before tax', $d($pbt)]);
        }
        fputcsv($out, ['Net profit', $d($pat)]);
    } elseif ($r === 'bs') {
        foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $k => $label) {
            fputcsv($out, [$label]);
            foreach ($groups[$k] as $g => $rows) foreach ($rows as $x) fputcsv($out, ["  {$g}: {$x['a']['code']} {$x['a']['name']}", $d($x['amt'])]);
            if ($k === 'equity') { fputcsv($out, ['  Profit of earlier years (not yet closed)', $d($priorProfit)]); fputcsv($out, ['  Profit for the current year', $d($currentProfit)]); }
            fputcsv($out, ["Total {$label}", $d($k === 'asset' ? $assets : ($k === 'liability' ? $liabilities : $equity))]);
        }
    } elseif ($r === 'ar' || $r === 'ap') {
        fputcsv($out, ['Party', ...array_values($buckets), 'Total']);
        foreach ($parties as $p) fputcsv($out, [$p['name'], ...array_map($d, array_values($p['buckets'])), $d($p['total'])]);
        fputcsv($out, ['Total', ...array_map($d, array_values($grand)), $d(array_sum($grand))]);
    } elseif ($r === 'projects') {
        fputcsv($out, ['Code', 'Project', 'Client', 'Status', 'Contract value', 'Revenue', 'Cost', 'Profit', 'Margin %', 'Budget', 'Budget used %']);
        foreach ($projects as $p) {
            $f = $fin[(int)$p['id']] ?? ['revenue' => 0, 'cost' => 0]; $budget = decimal_to_cents($p['budget']);
            fputcsv($out, [$p['code'], $p['name'], $p['client'], $p['status'], $p['contract_value'], $d($f['revenue']), $d($f['cost']), $d($f['revenue'] - $f['cost']),
                $f['revenue'] ? round(($f['revenue'] - $f['cost']) / $f['revenue'] * 100, 1) : '', $p['budget'], $budget ? round($f['cost'] / $budget * 100, 1) : '']);
        }
    } elseif ($r === 'cash') {
        fputcsv($out, ['Account', 'Opening', 'Money in', 'Money out', 'Transfers (net)', 'Closing']);
        foreach ($cashRows as $x) fputcsv($out, [$x['a']['name'], $d($x['opening']), $d($x['in']), $d($x['out']), $d($x['transfer_net']), $d($x['closing'])]);
    }
    exit;
}

$m = fn(int $c) => e(money_cents($c));
$query = fn(array $o) => '?' . http_build_query(array_merge(['r' => $r, 'from' => $from, 'to' => $to, 'as_of' => $asOf], $o));
$pageTitle   = $title;
$activeNav   = 'reports';
$breadcrumbs = [['label' => 'Reports', 'href' => 'reports.php'], ['label' => $title]];
$pageActions = '<a class="btn btn-outline-secondary" href="' . e($query(['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv me-1"></i> CSV</a>'
             . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
?>

<nav class="acc-subnav d-print-none">
    <?php foreach (REPORTS as $k => [$label, $icon]): ?><a href="reports.php?r=<?= $k ?>" class="<?= $k === $r ? 'active' : '' ?>"><i class="fa-solid <?= $icon ?>"></i> <?= e($label) ?></a><?php endforeach ?>
</nav>

<div class="acc-card">
    <form class="acc-filters d-print-none" method="get">
        <input type="hidden" name="r" value="<?= $r ?>">
        <?php if (in_array($r, ['bs', 'ar', 'ap'], true)): ?>
            <div><label class="form-label" for="as_of">As of</label><input type="date" class="form-control form-control-sm" id="as_of" name="as_of" value="<?= e($asOf) ?>"></div>
        <?php else: ?>
            <div><label class="form-label" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
            <div><label class="form-label" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
            <div class="d-flex gap-1 align-items-end">
                <?php foreach (array_slice(fiscal_year_options(), 0, 3) as $opt): [$a, $b] = fiscal_year_range($opt); ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e($query(['from' => $a, 'to' => min($b, date('Y-m-d'))])) ?>">FY <?= e($opt) ?></a>
                <?php endforeach ?>
            </div>
        <?php endif ?>
        <button class="btn btn-sm btn-primary">Apply</button>
    </form>
    <div class="px-4 pt-3 pb-2">
        <div class="fw-bold"><?= e(setting('company_name')) ?></div>
        <div class="small text-body-secondary"><?= e($title) ?> · <?= in_array($r, ['bs', 'ar', 'ap'], true) ? 'as of ' . e(bs_date($asOf)) . ' B.S. (' . e(date('d M Y', strtotime($asOf))) . ')' : e(bs_date($from)) . ' to ' . e(bs_date($to)) . ' B.S.' ?></div>
    </div>

<?php if ($r === 'pl'): ?>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <?php foreach (['income' => 'Income', 'direct' => 'Direct project costs', 'operating' => 'Operating expenses'] as $k => $label): ?>
            <tbody>
                <tr><th colspan="2" class="text-uppercase small text-body-secondary pt-3" style="letter-spacing:.05em"><?= $label ?></th></tr>
                <?php foreach ($sections[$k] as $x): ?><tr><td class="ps-4"><a class="text-decoration-none text-body" href="ledger.php?account=<?= (int)$x['a']['id'] ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"><span class="acc-code"><?= e($x['a']['code']) ?></span> <?= e($x['a']['name']) ?></a></td><td class="num"><?= $m($x['amt']) ?></td></tr><?php endforeach ?>
                <?php if (!$sections[$k]): ?><tr><td class="ps-4 text-body-tertiary small" colspan="2">None</td></tr><?php endif ?>
                <tr class="table-subtotal"><td>Total <?= strtolower($label) ?></td><td class="num"><?= $m($sum($k)) ?></td></tr>
                <?php if ($k === 'direct'): ?><tr class="table-subtotal"><td>Gross profit <span class="small text-body-secondary fw-normal"><?= $income ? number_format($gross / $income * 100, 1) . '% of income' : '' ?></span></td><td class="num <?= $gross < 0 ? 'text-danger' : '' ?>"><?= $m($gross) ?></td></tr><?php endif ?>
            </tbody>
        <?php endforeach ?>
        <tbody>
            <tr class="table-subtotal"><td>Profit before tax</td><td class="num <?= $pbt < 0 ? 'text-danger' : '' ?>"><?= $m($pbt) ?></td></tr>
            <tr><td class="ps-4">Income tax expense</td><td class="num"><?= $m($tax) ?></td></tr>
        </tbody>
        <tfoot><tr class="table-total"><td>Net profit <?= $income ? '<span class="small fw-normal text-body-secondary">' . number_format($pat / $income * 100, 1) . '% margin</span>' : '' ?></td><td class="num <?= $pat < 0 ? 'text-danger' : '' ?>"><?= e(money_cents($pat, true)) ?></td></tr></tfoot>
    </table></div>

<?php elseif ($r === 'bs'): ?>
    <div class="row g-0">
        <?php foreach ([['asset' => 'Assets'], ['liability' => 'Liabilities', 'equity' => 'Equity']] as $col): ?>
            <div class="col-lg-6"><table class="table table-sm mb-0">
                <?php foreach ($col as $k => $label): ?>
                    <tbody>
                        <tr><th colspan="2" class="text-uppercase small text-body-secondary pt-3" style="letter-spacing:.05em"><?= $label ?></th></tr>
                        <?php foreach ($groups[$k] as $g => $rows): ?>
                            <tr><td colspan="2" class="fw-semibold small pt-2"><?= e($g) ?></td></tr>
                            <?php foreach ($rows as $x): ?><tr><td class="ps-4"><a class="text-decoration-none text-body" href="ledger.php?account=<?= (int)$x['a']['id'] ?>&from=1944-01-01&to=<?= e($asOf) ?>"><?= e($x['a']['name']) ?></a></td><td class="num <?= $x['amt'] < 0 ? 'text-danger' : '' ?>"><?= $m($x['amt']) ?></td></tr><?php endforeach ?>
                        <?php endforeach ?>
                        <?php if ($k === 'equity'): ?>
                            <?php if ($priorProfit): ?><tr><td class="ps-4">Profit of earlier years <span class="small text-body-secondary">(not yet closed)</span></td><td class="num"><?= $m($priorProfit) ?></td></tr><?php endif ?>
                            <tr><td class="ps-4">Profit for the year to date</td><td class="num <?= $currentProfit < 0 ? 'text-danger' : '' ?>"><?= $m($currentProfit) ?></td></tr>
                        <?php endif ?>
                        <?php if ($k !== 'asset'): /* the assets column total is the footer */ ?><tr class="table-subtotal"><td>Total <?= strtolower($label) ?></td><td class="num"><?= $m($k === 'liability' ? $liabilities : $equity) ?></td></tr><?php endif ?>
                    </tbody>
                <?php endforeach ?>
                <tfoot><tr class="table-total"><td><?= isset($col['asset']) ? 'Total assets' : 'Total liabilities & equity' ?></td><td class="num"><?= e(money_cents(isset($col['asset']) ? $assets : $liabilities + $equity, true)) ?></td></tr></tfoot>
            </table></div>
        <?php endforeach ?>
    </div>
    <div class="px-4 py-2 border-top small <?= $assets === $liabilities + $equity ? 'text-success' : 'text-danger fw-semibold' ?>">
        <?= $assets === $liabilities + $equity ? '<i class="fa-solid fa-check me-1"></i> Balanced — assets equal liabilities plus equity.' : 'Out of balance by ' . $m($assets - $liabilities - $equity) . ' — check the trial balance.' ?>
    </div>

<?php elseif ($r === 'ar' || $r === 'ap'): ?>
    <?php if (!$parties): ?>
        <div class="acc-empty"><div class="acc-empty-icon"><i class="fa-solid fa-circle-check"></i></div><h3>Nothing outstanding</h3><p>No unpaid <?= $r === 'ar' ? 'sales invoices' : 'supplier bills' ?> as of this date.</p></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th><?= $r === 'ar' ? 'Client' : 'Supplier' ?></th><?php foreach ($buckets as $label): ?><th class="num"><?= $label ?></th><?php endforeach ?><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($parties as $cid => $p): ?>
                <tr data-bs-toggle="collapse" data-bs-target="#inv<?= $cid ?>" style="cursor:pointer">
                    <td class="fw-semibold"><i class="fa-solid fa-chevron-right small text-body-tertiary me-1"></i><?= e($p['name']) ?></td>
                    <?php foreach ($p['buckets'] as $b => $amt): ?><td class="num <?= $amt && in_array($b, ['61_90', '90'], true) ? 'text-danger fw-semibold' : '' ?>"><?= $amt ? $m($amt) : '–' ?></td><?php endforeach ?>
                    <td class="num fw-bold"><?= $m($p['total']) ?></td>
                </tr>
                <tr class="collapse" id="inv<?= $cid ?>"><td colspan="7" class="bg-body-tertiary small">
                    <?php foreach ($p['invoices'] as $i): ?>
                        <div class="d-flex justify-content-between gap-3 py-1">
                            <span><a href="invoice.php?id=<?= (int)$i['id'] ?>"><?= e($i['number']) ?></a> · <?= e(bs_date($i['invoice_date'])) ?><?= $i['due_date'] ? ' · due ' . e(bs_date($i['due_date'])) : '' ?></span>
                            <span><?= $i['days'] > 0 ? '<span class="text-danger">' . $i['days'] . ' days overdue</span>' : 'not yet due' ?> · <strong><?= $m($i['outstanding']) ?></strong></span>
                        </div>
                    <?php endforeach ?>
                </td></tr>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr class="table-total"><td>Total</td><?php foreach ($grand as $amt): ?><td class="num"><?= $m($amt) ?></td><?php endforeach ?><td class="num"><?= $m(array_sum($grand)) ?></td></tr></tfoot>
        </table></div>
        <div class="px-4 py-2 border-top small text-body-secondary">Days counted from the due date (or invoice date if none). Retention and advances held aren't included — see each party's statement.</div>
    <?php endif ?>

<?php elseif ($r === 'projects'): ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Project</th><th class="num">Contract</th><th class="num">Revenue</th><th class="num">Cost</th><th class="num">Profit</th><th class="num">Margin</th><th class="num">Budget used</th></tr></thead>
        <tbody>
        <?php $tRev = $tCost = 0; foreach ($projects as $p): $f = $fin[(int)$p['id']] ?? ['revenue' => 0, 'cost' => 0]; $tRev += $f['revenue']; $tCost += $f['cost']; $profit = $f['revenue'] - $f['cost']; $budget = decimal_to_cents($p['budget']); ?>
            <tr style="cursor:pointer" onclick="location.href='project.php?id=<?= (int)$p['id'] ?>'">
                <td><a class="text-decoration-none fw-semibold" href="project.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><div class="small text-body-secondary"><span class="acc-code"><?= e($p['code']) ?></span><?= $p['client'] ? ' · ' . e($p['client']) : '' ?> · <?= e(PROJECT_STATUSES[$p['status']]['label']) ?></div></td>
                <td class="num"><?= (float)$p['contract_value'] ? e(money($p['contract_value'], false)) : '–' ?></td>
                <td class="num"><?= $m($f['revenue']) ?></td>
                <td class="num"><?= $m($f['cost']) ?></td>
                <td class="num fw-semibold <?= $profit < 0 ? 'text-danger' : ($profit > 0 ? 'text-success' : '') ?>"><?= $m($profit) ?></td>
                <td class="num"><?= $f['revenue'] ? number_format($profit / $f['revenue'] * 100, 1) . '%' : '–' ?></td>
                <td class="num <?= $budget && $f['cost'] > $budget ? 'text-danger fw-semibold' : '' ?>"><?= $budget ? number_format($f['cost'] / $budget * 100, 0) . '%' : '–' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr class="table-total"><td>Total</td><td></td><td class="num"><?= $m($tRev) ?></td><td class="num"><?= $m($tCost) ?></td><td class="num"><?= $m($tRev - $tCost) ?></td><td class="num"><?= $tRev ? number_format(($tRev - $tCost) / $tRev * 100, 1) . '%' : '–' ?></td><td></td></tr></tfoot>
    </table></div>
    <div class="px-4 py-2 border-top small text-body-secondary">Revenue and cost in the selected period, from lines tagged to each project (excluding VAT). Budget used compares cost in this period with the whole budget.</div>

<?php elseif ($r === 'cash'): ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Account</th><th class="num">Opening</th><th class="num">Money in</th><th class="num">Money out</th><th class="num">Transfers (net)</th><th class="num">Closing</th></tr></thead>
        <tbody>
        <?php foreach ($cashRows as $x): ?>
            <tr style="cursor:pointer" onclick="location.href='cash-account.php?id=<?= (int)$x['a']['id'] ?>&from=<?= e($from) ?>&to=<?= e($to) ?>'">
                <td><?= e($x['a']['name']) ?><div class="small text-body-secondary"><?= e(CASH_KINDS[$x['a']['kind']]['label']) ?></div></td>
                <td class="num"><?= $m($x['opening']) ?></td>
                <td class="num text-success"><?= $m($x['in']) ?></td>
                <td class="num"><?= $m($x['out']) ?></td>
                <td class="num text-body-secondary"><?= $x['transfer_net'] ? $m($x['transfer_net']) : '–' ?></td>
                <td class="num fw-semibold <?= $x['closing'] < 0 ? 'text-danger' : '' ?>"><?= $m($x['closing']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr class="table-total"><td>Total</td>
            <?php foreach (['opening', 'in', 'out', 'transfer_net', 'closing'] as $k): ?><td class="num"><?= $m(array_sum(array_column($cashRows, $k))) ?></td><?php endforeach ?></tr></tfoot>
    </table></div>
    <div class="px-4 py-2 border-top small text-body-secondary">Transfers between your own accounts are shown separately and net to zero overall.</div>
<?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
