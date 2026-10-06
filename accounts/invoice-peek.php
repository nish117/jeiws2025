<?php
/** HTML fragment for the hover preview on the invoice / bill lists (invoices.php). ?id=… */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/attachments.php';
$user = current_user();
if (!$user) { http_response_code(401); exit; }

$inv = load_invoice((int)($_GET['id'] ?? 0));
if (!$inv) { http_response_code(404); exit; }
$meta = INVOICE_TYPES[$inv['type']];
$project = $inv['project_id'] ? (project_options()[(int)$inv['project_id']] ?? null) : null;
$photo = null;
$fileCount = 0;
foreach (list_attachments('invoice', (int)$inv['id']) as $att) {
    $fileCount++;
    if (!$photo && str_starts_with($att['mime_type'], 'image/')) $photo = $att;
}
$m = fn($v) => money($v, false);
$outstanding = decimal_to_cents($inv['net_amount']) - decimal_to_cents($inv['amount_paid']);
$maxLines = 5;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
?>
<div class="acc-peek-head">
    <div class="min-w-0">
        <div class="acc-peek-kicker"><?= e($meta['label']) ?></div>
        <div class="acc-peek-title"><?= e($inv['number'] ?? 'Draft #' . $inv['id']) ?></div>
        <div class="acc-peek-sub text-truncate"><?= e($inv['contact_name']) ?><?= $inv['contact_pan'] ? ' · PAN ' . e($inv['contact_pan']) : '' ?></div>
    </div>
    <div class="text-end flex-none"><?= invoice_status_badge($inv) ?></div>
</div>

<div class="acc-peek-body">
    <?php if ($photo): ?>
        <img class="acc-peek-photo" src="attachment.php?id=<?= (int)$photo['id'] ?>" alt="Photo of the bill" loading="lazy">
    <?php endif ?>
    <div class="min-w-0 flex-grow-1">
        <dl class="acc-peek-facts">
            <dt>Date</dt><dd><?= e(bs_date($inv['invoice_date'])) ?> <span class="text-body-secondary">· <?= e(date('d M Y', strtotime($inv['invoice_date']))) ?></span></dd>
            <?php if ($inv['due_date']): ?><dt>Due</dt><dd><?= e(bs_date($inv['due_date'])) ?></dd><?php endif ?>
            <?php if ($project): ?><dt>Project</dt><dd class="text-truncate"><?= e($project['code'] . ' · ' . $project['name']) ?></dd><?php endif ?>
            <?php if ($fileCount): ?><dt>Files</dt><dd><i class="fa-solid fa-paperclip me-1 text-body-tertiary"></i><?= $fileCount ?> attached</dd><?php endif ?>
        </dl>
    </div>
</div>

<table class="acc-peek-lines">
    <?php foreach (array_slice($inv['lines'], 0, $maxLines) as $l): ?>
        <tr>
            <td><div class="text-truncate"><?= e($l['description']) ?></div>
                <div class="acc-peek-muted"><?= e(rtrim(rtrim(number_format((float)$l['quantity'], 3, '.', ''), '0'), '.')) ?><?= $l['unit'] ? ' ' . e($l['unit']) : '' ?> × <?= e($m($l['rate'])) ?><?= $l['vat_applicable'] ? '' : ' · no VAT' ?></div></td>
            <td class="num"><?= e($m($l['amount'])) ?></td>
        </tr>
    <?php endforeach ?>
    <?php if (count($inv['lines']) > $maxLines): ?>
        <tr><td colspan="2" class="acc-peek-muted">+ <?= count($inv['lines']) - $maxLines ?> more item<?= count($inv['lines']) - $maxLines > 1 ? 's' : '' ?></td></tr>
    <?php endif ?>
</table>

<table class="acc-peek-totals">
    <?php if ((float)$inv['vat_amount']): ?>
        <tr><td>Taxable</td><td class="num"><?= e($m($inv['taxable_amount'])) ?></td></tr>
        <?php if ((float)$inv['exempt_amount']): ?><tr><td>Non-taxable</td><td class="num"><?= e($m($inv['exempt_amount'])) ?></td></tr><?php endif ?>
        <tr><td>VAT <?= e(rtrim(rtrim($inv['vat_rate'], '0'), '.')) ?>%</td><td class="num"><?= e($m($inv['vat_amount'])) ?></td></tr>
    <?php endif ?>
    <tr class="acc-peek-total"><td>Total</td><td class="num"><?= e(money($inv['total_amount'])) ?></td></tr>
    <?php if ((float)$inv['retention_amount']): ?><tr><td>Retention <?= e(rtrim(rtrim($inv['retention_percent'], '0'), '.')) ?>%</td><td class="num">− <?= e($m($inv['retention_amount'])) ?></td></tr><?php endif ?>
    <?php if ((float)$inv['tds_amount']): ?><tr><td>TDS <?= e(rtrim(rtrim($inv['tds_percent'], '0'), '.')) ?>%</td><td class="num">− <?= e($m($inv['tds_amount'])) ?></td></tr><?php endif ?>
    <?php if ((float)$inv['retention_amount'] || (float)$inv['tds_amount']): ?><tr><td>Net <?= $inv['type'] === 'sales' ? 'receivable' : 'payable' ?></td><td class="num"><?= e($m($inv['net_amount'])) ?></td></tr><?php endif ?>
    <?php if ((float)$inv['amount_paid']): ?><tr><td><?= e($meta['paid_label']) ?></td><td class="num"><?= e($m($inv['amount_paid'])) ?></td></tr><?php endif ?>
    <?php if ($inv['status'] === 'posted'): ?><tr class="acc-peek-due"><td>Outstanding</td><td class="num"><?= e(money_cents($outstanding, true)) ?></td></tr><?php endif ?>
</table>

<?php if (trim((string)$inv['notes']) !== ''): ?>
    <div class="acc-peek-notes"><?= e($inv['notes']) ?></div>
<?php endif ?>
