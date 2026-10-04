<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT p.*, c.name AS client_name FROM acc_projects p LEFT JOIN acc_contacts c ON c.id = p.client_id WHERE p.id = ?');
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) { flash('warning', 'Project not found.'); redirect('projects.php'); }

$fin = project_financials($id)[$id] ?? ['revenue' => 0, 'cost' => 0];
$contract = decimal_to_cents($project['contract_value']);
$budget   = decimal_to_cents($project['budget']);
$profit   = $fin['revenue'] - $fin['cost'];
$margin   = $fin['revenue'] > 0 ? $profit / $fin['revenue'] * 100 : null;
$billedPct = $contract > 0 ? $fin['revenue'] / $contract * 100 : null;
$budgetPct = $budget > 0 ? $fin['cost'] / $budget * 100 : null;

// Cost breakdown by expense account.
$breakdown = db()->prepare(
    "SELECT a.id, a.code, a.name, SUM(l.debit) - SUM(l.credit) AS amount
     FROM acc_journal_lines l
     JOIN acc_journal_entries e ON e.id = l.entry_id
     JOIN acc_accounts a ON a.id = l.account_id
     WHERE e.status = 'posted' AND l.project_id = ? AND a.type = 'expense'
     GROUP BY a.id, a.code, a.name HAVING amount <> 0 ORDER BY amount DESC"
);
$breakdown->execute([$id]);
$costs = $breakdown->fetchAll();

$recent = db()->prepare(
    "SELECT e.id AS entry_id, e.voucher_no, e.entry_date, e.narration, a.code, a.name AS account_name, a.type, l.debit, l.credit, l.contact_id
     FROM acc_journal_lines l
     JOIN acc_journal_entries e ON e.id = l.entry_id
     JOIN acc_accounts a ON a.id = l.account_id
     WHERE e.status = 'posted' AND l.project_id = ?
     ORDER BY e.entry_date DESC, e.id DESC LIMIT 25"
);
$recent->execute([$id]);
$transactions = $recent->fetchAll();
$site = $project['site_project_id'] ? (site_project_options()[$project['site_project_id']] ?? null) : null;

$pageTitle   = $project['name'];
$activeNav   = 'projects';
$breadcrumbs = [['label' => 'Projects', 'href' => 'projects.php'], ['label' => $project['name']]];
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>';
if (can_edit_books($user)) {
    $pageActions .= '<a class="btn btn-primary" href="project-edit.php?id=' . $id . '"><i class="fa-solid fa-pen me-1"></i> Edit</a>';
}
require __DIR__ . '/includes/layout-top.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3 small text-body-secondary">
    <?= project_status_badge($project['status']) ?>
    <span class="acc-code"><?= e($project['code']) ?></span>
    <?php if ($project['client_name']): ?>· <a href="contact.php?id=<?= (int)$project['client_id'] ?>" class="text-decoration-none"><i class="fa-solid fa-user-tie me-1"></i><?= e($project['client_name']) ?></a><?php endif ?>
    <?php if ($project['location']): ?>· <i class="fa-solid fa-location-dot"></i> <?= e($project['location']) ?><?php endif ?>
    <?php if ($project['start_date']): ?>· <?= e(bs_date($project['start_date'])) ?><?= $project['end_date'] ? ' → ' . e(bs_date($project['end_date'])) : '' ?> B.S.<?php endif ?>
    <?php if ($site): ?>· <i class="fa-solid fa-link"></i> Site portal: <?= e($site['title']) ?><?php endif ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Contract value</span><span class="acc-kpi-icon acc-tone-blue"><i class="fa-solid fa-file-signature"></i></span></div>
            <div class="acc-kpi-value"><?= $contract ? e(money_cents($contract, true)) : '—' ?></div>
            <div class="acc-kpi-note"><?= (float)$project['retention_percent'] ? e(rtrim(rtrim($project['retention_percent'], '0'), '.')) . '% retention' : 'Excluding VAT' ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Revenue to date</span><span class="acc-kpi-icon acc-tone-green"><i class="fa-solid fa-arrow-trend-up"></i></span></div>
            <div class="acc-kpi-value"><?= e(money_cents($fin['revenue'], true)) ?></div>
            <div class="acc-kpi-note"><?= $billedPct !== null ? number_format($billedPct, 1) . '% of contract billed' : 'Set a contract value to track billing' ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Cost to date</span><span class="acc-kpi-icon acc-tone-red"><i class="fa-solid fa-arrow-trend-down"></i></span></div>
            <div class="acc-kpi-value"><?= e(money_cents($fin['cost'], true)) ?></div>
            <?php if ($budgetPct !== null): ?>
                <div class="progress mt-1" style="height:6px"><div class="progress-bar <?= $budgetPct > 100 ? 'bg-danger' : ($budgetPct > 85 ? 'bg-warning' : '') ?>" style="width:<?= min(100, round($budgetPct)) ?>%"></div></div>
                <div class="acc-kpi-note <?= $budgetPct > 100 ? 'text-danger fw-semibold' : '' ?>"><?= number_format($budgetPct, 1) ?>% of <?= e(money_cents($budget)) ?> budget<?= $budgetPct > 100 ? ' — over budget' : '' ?></div>
            <?php else: ?>
                <div class="acc-kpi-note">No budget set</div>
            <?php endif ?>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="acc-card acc-kpi">
            <div class="acc-kpi-top"><span class="acc-kpi-label">Profit</span><span class="acc-kpi-icon <?= $profit < 0 ? 'acc-tone-red' : 'acc-tone-green' ?>"><i class="fa-solid fa-sack-dollar"></i></span></div>
            <div class="acc-kpi-value <?= $profit < 0 ? 'text-danger' : '' ?>"><?= e(money_cents($profit, true)) ?></div>
            <div class="acc-kpi-note"><?= $margin !== null ? number_format($margin, 1) . '% margin' : 'No revenue yet' ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Cost breakdown</h2></div>
            <?php if (!$costs): ?>
                <div class="acc-card-body small text-body-secondary">No costs recorded yet. Tag expense lines to this project in a voucher (Project column) and they'll appear here by cost head.</div>
            <?php else: ?>
                <div class="acc-card-body pb-0"><div style="height:200px;position:relative"><canvas id="costChart" role="img" aria-label="Cost breakdown chart"></canvas></div></div>
                <table class="table mb-0 small">
                    <tbody>
                    <?php foreach ($costs as $c): $amt = decimal_to_cents($c['amount']); ?>
                        <tr>
                            <td><a class="text-decoration-none" href="ledger.php?account=<?= (int)$c['id'] ?>&project=<?= $id ?>&from=1944-01-01"><?= e($c['name']) ?></a></td>
                            <td class="num"><?= e(money_cents($amt)) ?></td>
                            <td class="num text-body-secondary" style="width:60px"><?= $fin['cost'] ? number_format($amt / $fin['cost'] * 100, 0) . '%' : '' ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            <?php endif ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="acc-card h-100">
            <div class="acc-card-head"><h2>Recent transactions</h2></div>
            <?php if (!$transactions): ?>
                <div class="acc-card-body small text-body-secondary">Nothing tagged to this project yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 small align-middle">
                        <tbody>
                        <?php foreach ($transactions as $t): $amt = decimal_to_cents($t['debit']) - decimal_to_cents($t['credit']); ?>
                            <tr style="cursor:pointer" onclick="location.href='journal-entry.php?id=<?= (int)$t['entry_id'] ?>'">
                                <td class="text-nowrap"><?= e(bs_date($t['entry_date'])) ?><div class="text-body-secondary"><?= e($t['voucher_no']) ?></div></td>
                                <td><?= e($t['narration']) ?><div class="text-body-secondary"><?= e($t['account_name']) ?><?= $t['contact_id'] ? ' · ' . e(contact_label((int)$t['contact_id'])) : '' ?></div></td>
                                <td class="num"><?= e(money_cents(abs($amt))) ?> <span class="text-body-secondary"><?= $amt >= 0 ? 'Dr' : 'Cr' ?></span></td>
                            </tr>
                        <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
    </div>
</div>

<?php if ($project['description']): ?>
    <div class="acc-card"><div class="acc-card-head"><h2>Scope / notes</h2></div><div class="acc-card-body"><?= nl2br(e($project['description'])) ?></div></div>
<?php endif ?>

<?php
if ($costs) {
    $pageScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
    $inlineScript = 'window.accProjectCosts = ' . json_encode([
        'labels' => array_column($costs, 'name'),
        'values' => array_map(fn($c) => decimal_to_cents($c['amount']) / 100, $costs),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ';
    (function () {
        const d = window.accProjectCosts;
        const palette = ["#1B6799", "#C8911A", "#2B82BC", "#1E8E5A", "#8A5CC2", "#C8412E", "#5B7083", "#E0B04A", "#7FB3D5"];
        new Chart(document.getElementById("costChart"), {
            type: "doughnut",
            data: { labels: d.labels, datasets: [{ data: d.values, backgroundColor: d.labels.map((_, i) => palette[i % palette.length]), borderWidth: 2, borderColor: "#fff" }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: "62%",
                plugins: { legend: { position: "right", labels: { boxWidth: 10, boxHeight: 10, font: { size: 11 } } },
                    tooltip: { callbacks: { label: c => c.label + ": Rs. " + Number(c.parsed).toLocaleString("en-IN", { minimumFractionDigits: 2 }) } } } }
        });
    })();';
}
require __DIR__ . '/includes/layout-bottom.php';
