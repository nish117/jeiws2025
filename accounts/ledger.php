<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$accounts = account_tree();
$accountId = (int)($_GET['account'] ?? 0);
$account = $accounts[$accountId] ?? null;
if ($account && $account['is_group']) $account = null;

[$fyStart] = fiscal_year_range(fiscal_year_for(date('Y-m-d')));
$from = (string)($_GET['from'] ?? $fyStart);
$to   = (string)($_GET['to'] ?? date('Y-m-d'));
if (!valid_ad_date($from)) $from = $fyStart;
if (!valid_ad_date($to))   $to = date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$projectId = (int)($_GET['project'] ?? 0);
$projects = project_options();

$rows = [];
$opening = $totalDebit = $totalCredit = 0;
if ($account) {
    $projectSql = $projectId ? ' AND l.project_id = ?' : '';
    $params = fn(array $p) => $projectId ? [...$p, $projectId] : $p;

    $stmt = db()->prepare("SELECT COALESCE(SUM(l.debit),0) AS dr, COALESCE(SUM(l.credit),0) AS cr
        FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
        WHERE e.status = 'posted' AND l.account_id = ? AND e.entry_date < ?{$projectSql}");
    $stmt->execute($params([$accountId, $from]));
    $o = $stmt->fetch();
    $opening = decimal_to_cents($o['dr']) - decimal_to_cents($o['cr']); // raw Dr − Cr

    $stmt = db()->prepare("SELECT e.id AS entry_id, e.voucher_no, e.entry_date, e.narration, e.source, l.description, l.project_id, l.debit, l.credit
        FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
        WHERE e.status = 'posted' AND l.account_id = ? AND e.entry_date BETWEEN ? AND ?{$projectSql}
        ORDER BY e.entry_date, e.posted_at, e.id, l.line_no");
    $stmt->execute($params([$accountId, $from, $to]));
    $running = $opening;
    foreach ($stmt->fetchAll() as $r) {
        $dr = decimal_to_cents($r['debit']);
        $cr = decimal_to_cents($r['credit']);
        $running += $dr - $cr;
        $totalDebit += $dr;
        $totalCredit += $cr;
        $r['dr'] = $dr; $r['cr'] = $cr; $r['balance'] = $running;
        $rows[] = $r;
    }
}
$closing = $opening + $totalDebit - $totalCredit;
$drcr = fn(int $raw) => $raw === 0 ? '0.00' : money_cents(abs($raw)) . ($raw > 0 ? ' Dr' : ' Cr');

if ($account && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ledger-' . preg_replace('/[^A-Za-z0-9.-]/', '', $account['code']) . "-{$from}-to-{$to}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, ["Ledger: {$account['code']} {$account['name']}", "{$from} to {$to}"]);
    fputcsv($out, ['Date (BS)', 'Date (AD)', 'Voucher', 'Narration', 'Project', 'Debit', 'Credit', 'Balance']);
    fputcsv($out, ['', $from, '', 'Opening balance', '', '', '', $drcr($opening)]);
    foreach ($rows as $r) {
        fputcsv($out, [bs_date($r['entry_date']), $r['entry_date'], $r['voucher_no'], $r['narration'] . ($r['description'] ? " — {$r['description']}" : ''),
            project_label($r['project_id'] ? (int)$r['project_id'] : null),
            $r['dr'] ? cents_to_decimal($r['dr']) : '', $r['cr'] ? cents_to_decimal($r['cr']) : '', $drcr($r['balance'])]);
    }
    fputcsv($out, ['', $to, '', 'Closing balance', '', cents_to_decimal($totalDebit), cents_to_decimal($totalCredit), $drcr($closing)]);
    exit;
}

$pageTitle   = $account ? "Ledger · {$account['name']}" : 'General ledger';
$activeNav   = 'accounting';
$breadcrumbs = [['label' => 'Accounting', 'href' => 'accounting.php'], ['label' => 'General ledger']];
$pageActions = $account
    ? '<a class="btn btn-outline-secondary" href="?' . e(http_build_query(['account' => $accountId, 'from' => $from, 'to' => $to, 'project' => $projectId ?: null, 'export' => 'csv'])) . '"><i class="fa-solid fa-file-csv me-1"></i> Export CSV</a>'
      . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>'
    : '';
require __DIR__ . '/includes/layout-top.php';
$accountingTab = 'ledger';
require __DIR__ . '/includes/accounting-nav.php';
?>

<div class="acc-card">
    <form class="acc-filters" method="get">
        <div class="flex-grow-1" style="min-width:240px;max-width:380px">
            <label class="form-label" for="account">Account</label>
            <select class="form-select form-select-sm" id="account" name="account" onchange="this.form.submit()">
                <option value="">Choose an account…</option>
                <?php foreach (postable_accounts_by_type() as $type => $list): ?>
                    <optgroup label="<?= e(ACC_ACCOUNT_TYPES[$type]['label']) ?>">
                        <?php foreach ($list as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $accountId ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach ?>
                    </optgroup>
                <?php endforeach ?>
            </select>
        </div>
        <div><label class="form-label" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label class="form-label" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <?php if ($projects): ?>
            <div>
                <label class="form-label" for="project">Project</label>
                <select class="form-select form-select-sm" id="project" name="project">
                    <option value="">All projects</option>
                    <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $projectId ? 'selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?></option><?php endforeach ?>
                </select>
            </div>
        <?php endif ?>
        <button class="btn btn-sm btn-outline-secondary">Apply</button>
    </form>

    <?php if (!$account): ?>
        <div class="acc-empty">
            <div class="acc-empty-icon"><i class="fa-solid fa-book-open"></i></div>
            <h3>Choose an account</h3>
            <p>See every posted transaction for an account with its running balance.</p>
        </div>
    <?php else: ?>
        <div class="px-4 pt-3 pb-2">
            <div class="fw-bold"><span class="acc-code"><?= e($account['code']) ?></span> <?= e($account['name']) ?></div>
            <div class="small text-body-secondary">
                <?= e(ACC_ACCOUNT_TYPES[$account['type']]['label']) ?> ·
                <?= e(bs_date($from)) ?> to <?= e(bs_date($to)) ?> B.S. (<?= e(date('d M Y', strtotime($from))) ?> – <?= e(date('d M Y', strtotime($to))) ?>)
                <?php if ($projectId): ?> · Project: <?= e(project_label($projectId)) ?><?php endif ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Date</th><th>Voucher</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
                <tbody>
                    <tr class="table-subtotal"><td colspan="5">Opening balance</td><td class="num"><?= e($drcr($opening)) ?></td></tr>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="text-center text-body-secondary py-4">No posted transactions in this period.</td></tr>
                    <?php endif ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(bs_date($r['entry_date'])) ?><div class="small text-body-secondary"><?= e(date('d M Y', strtotime($r['entry_date']))) ?></div></td>
                            <td class="text-nowrap"><a href="journal-entry.php?id=<?= (int)$r['entry_id'] ?>" class="text-decoration-none"><?= e($r['voucher_no']) ?></a></td>
                            <td>
                                <?= e($r['narration']) ?>
                                <?php if ($r['description'] || $r['project_id']): ?>
                                    <div class="small text-body-secondary">
                                        <?= e($r['description'] ?? '') ?>
                                        <?php if ($r['project_id']): ?><span class="badge text-bg-light border"><i class="fa-solid fa-helmet-safety"></i> <?= e(project_label((int)$r['project_id'])) ?></span><?php endif ?>
                                    </div>
                                <?php endif ?>
                            </td>
                            <td class="num"><?= $r['dr'] ? e(money_cents($r['dr'])) : '' ?></td>
                            <td class="num"><?= $r['cr'] ? e(money_cents($r['cr'])) : '' ?></td>
                            <td class="num"><?= e($drcr($r['balance'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr class="table-total">
                        <td colspan="3">Closing balance</td>
                        <td class="num"><?= e(money_cents($totalDebit)) ?></td>
                        <td class="num"><?= e(money_cents($totalCredit)) ?></td>
                        <td class="num"><?= e($drcr($closing)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php';
