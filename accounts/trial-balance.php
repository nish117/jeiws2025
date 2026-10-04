<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$asOf = (string)($_GET['as_of'] ?? date('Y-m-d'));
if (!valid_ad_date($asOf)) $asOf = date('Y-m-d');
$showZero = !empty($_GET['zero']);
$fy = fiscal_year_for($asOf);
[$fyStart] = fiscal_year_range($fy);

/*
 * Balance-sheet accounts carry everything up to the date. Income and
 * expense accounts show only the current fiscal year; earlier years'
 * net profit is rolled into Retained Earnings so the trial balance still
 * balances before a formal year-end closing entry exists.
 */
$accounts   = account_tree();
$cumulative = account_totals(null, $asOf);
$thisYear   = account_totals($fyStart, $asOf);
$priorYears = account_totals(null, date('Y-m-d', strtotime($fyStart . ' -1 day')));

$priorProfit = 0; // credit-positive
foreach ($priorYears as $id => $t) {
    if (in_array($accounts[$id]['type'] ?? '', ['income', 'expense'], true)) $priorProfit += $t['credit'] - $t['debit'];
}
$retainedId = system_account_id('retained_earnings');

$sections = [];
$totalDr = $totalCr = 0;
foreach ($accounts as $id => $a) {
    if ($a['is_group']) continue;
    $t = in_array($a['type'], ['income', 'expense'], true) ? ($thisYear[$id] ?? null) : ($cumulative[$id] ?? null);
    $raw = ($t['debit'] ?? 0) - ($t['credit'] ?? 0);
    if ($id === $retainedId) $raw -= $priorProfit;
    if ($raw === 0 && !$showZero) continue;

    $dr = max($raw, 0);
    $cr = max(-$raw, 0);
    $sections[$a['type']]['rows'][] = ['account' => $a, 'dr' => $dr, 'cr' => $cr];
    $sections[$a['type']]['dr'] = ($sections[$a['type']]['dr'] ?? 0) + $dr;
    $sections[$a['type']]['cr'] = ($sections[$a['type']]['cr'] ?? 0) + $cr;
    $totalDr += $dr;
    $totalCr += $cr;
}
$balanced = $totalDr === $totalCr;

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"trial-balance-{$asOf}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [setting('company_name'), "Trial balance as of {$asOf} (" . bs_date($asOf) . " B.S.)", "FY {$fy}"]);
    fputcsv($out, ['Code', 'Account', 'Type', 'Debit', 'Credit']);
    foreach (ACC_ACCOUNT_TYPES as $type => $meta) {
        foreach ($sections[$type]['rows'] ?? [] as $r) {
            fputcsv($out, [$r['account']['code'], $r['account']['name'], $meta['label'], $r['dr'] ? cents_to_decimal($r['dr']) : '', $r['cr'] ? cents_to_decimal($r['cr']) : '']);
        }
    }
    fputcsv($out, ['', 'Total', '', cents_to_decimal($totalDr), cents_to_decimal($totalCr)]);
    exit;
}

$pageTitle   = 'Trial balance';
$activeNav   = 'accounting';
$breadcrumbs = [['label' => 'Accounting', 'href' => 'accounting.php'], ['label' => 'Trial balance']];
$pageActions = '<a class="btn btn-outline-secondary" href="?' . e(http_build_query(['as_of' => $asOf, 'zero' => $showZero ? 1 : null, 'export' => 'csv'])) . '"><i class="fa-solid fa-file-csv me-1"></i> Export CSV</a>'
             . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
require __DIR__ . '/includes/layout-top.php';
$accountingTab = 'trial';
require __DIR__ . '/includes/accounting-nav.php';
?>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <div><label class="form-label" for="as_of">As of</label><input type="date" class="form-control form-control-sm" id="as_of" name="as_of" value="<?= e($asOf) ?>"></div>
        <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="zero" name="zero" value="1" <?= $showZero ? 'checked' : '' ?>>
            <label class="form-check-label small" for="zero">Show zero balances</label>
        </div>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>

    <div class="px-4 pt-3 pb-2 d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div>
            <div class="fw-bold"><?= e(setting('company_name')) ?></div>
            <div class="small text-body-secondary">Trial balance as of <?= e(bs_date($asOf)) ?> B.S. (<?= e(date('d M Y', strtotime($asOf))) ?>) · FY <?= e($fy) ?></div>
        </div>
        <?php if ($totalDr || $totalCr): ?>
            <?= $balanced
                ? '<span class="badge text-bg-success"><i class="fa-solid fa-scale-balanced me-1"></i> Balanced</span>'
                : '<span class="badge text-bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Out of balance by ' . e(money_cents(abs($totalDr - $totalCr))) . '</span>' ?>
        <?php endif ?>
    </div>

    <?php if (!$sections): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <h3>Nothing posted yet</h3>
            <p>Once vouchers are posted, every account's debit or credit balance appears here and the two totals should match.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th class="d-none d-sm-table-cell" style="width:90px">Code</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
                <?php foreach (ACC_ACCOUNT_TYPES as $type => $meta): if (empty($sections[$type])) continue; ?>
                    <tbody>
                        <tr><th colspan="4" class="text-uppercase small text-body-secondary pt-3" style="letter-spacing:.05em"><?= e($meta['label']) ?></th></tr>
                        <?php foreach ($sections[$type]['rows'] as $r): $a = $r['account']; ?>
                            <tr>
                                <td class="acc-code d-none d-sm-table-cell"><?= e($a['code']) ?></td>
                                <td>
                                    <a href="ledger.php?account=<?= (int)$a['id'] ?>&to=<?= e($asOf) ?><?= in_array($type, ['income', 'expense'], true) ? '&from=' . e($fyStart) : '&from=1944-01-01' ?>" class="text-decoration-none text-body"><?= e($a['name']) ?></a>
                                    <?php if ((int)$a['id'] === $retainedId && $priorProfit): ?>
                                        <div class="small text-body-secondary">Includes <?= e(money_cents(abs($priorProfit))) ?> prior-year <?= $priorProfit >= 0 ? 'profit' : 'loss' ?> not yet closed</div>
                                    <?php endif ?>
                                </td>
                                <td class="num"><?= $r['dr'] ? e(money_cents($r['dr'])) : '' ?></td>
                                <td class="num"><?= $r['cr'] ? e(money_cents($r['cr'])) : '' ?></td>
                            </tr>
                        <?php endforeach ?>
                        <tr class="table-subtotal">
                            <td class="d-none d-sm-table-cell"></td><td>Total <?= e(mb_strtolower($meta['label'])) ?></td>
                            <td class="num"><?= e(money_cents($sections[$type]['dr'])) ?></td>
                            <td class="num"><?= e(money_cents($sections[$type]['cr'])) ?></td>
                        </tr>
                    </tbody>
                <?php endforeach ?>
                <tfoot>
                    <tr class="table-total"><td class="d-none d-sm-table-cell"></td><td>Grand total</td><td class="num"><?= e(money_cents($totalDr)) ?></td><td class="num"><?= e(money_cents($totalCr)) ?></td></tr>
                </tfoot>
            </table>
        </div>
    <?php endif ?>
</div>
<p class="small text-body-secondary mt-2">Asset, liability and equity accounts show balances since inception. Income and expense accounts show FY <?= e($fy) ?> only.</p>

<?php require __DIR__ . '/includes/layout-bottom.php';
