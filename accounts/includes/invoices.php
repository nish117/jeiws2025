<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Sales invoices (we bill a client) and purchase bills (a supplier bills us).
 *
 * Totals, all in paisa:
 *   taxable  = Σ amount of lines with VAT
 *   exempt   = Σ amount of lines without VAT
 *   VAT      = taxable × VAT rate (13%)
 *   total    = taxable + exempt + VAT
 *   base     = taxable + exempt              (work value, excl. VAT)
 *   retention = base × retention %          (withheld until the defects period ends)
 *   TDS      = base × TDS %                 (sales: withheld by the client; purchase: withheld by us)
 *   net      = total − retention − TDS      (what is actually received / paid)
 *
 * Posting a sales invoice:   Dr Receivable (net) + Retention receivable + TDS receivable
 *                            Cr income lines (by project) + Output VAT
 * Posting a purchase bill:   Dr expense lines (by project) + Input VAT
 *                            Cr Payable (net) + Retention payable + TDS payable
 */

const INVOICE_TYPES = [
    'sales' => [
        'label' => 'Sales invoice', 'plural' => 'Sales invoices', 'contact' => 'client', 'icon' => 'fa-file-invoice-dollar',
        'source' => 'sales_invoice', 'payment_source' => 'sales_receipt', 'nav' => 'invoices',
        'line_types' => ['income'], 'default_account' => 'contract_revenue', 'paid_label' => 'Received',
    ],
    'purchase' => [
        'label' => 'Purchase bill', 'plural' => 'Purchase bills', 'contact' => 'supplier', 'icon' => 'fa-receipt',
        'source' => 'purchase_bill', 'payment_source' => 'bill_payment', 'nav' => 'expenses',
        'line_types' => ['expense', 'asset'], 'default_account' => 'materials_cost', 'paid_label' => 'Paid',
    ],
];

const INVOICE_MONEY_FIELDS = ['taxable_amount', 'exempt_amount', 'vat_amount', 'total_amount', 'retention_amount', 'tds_amount', 'net_amount', 'amount_paid'];

function invoice_status_badge(array $inv): string {
    if ($inv['status'] === 'posted' && decimal_to_cents($inv['amount_paid']) > 0) return '<span class="badge text-bg-info">Part paid</span>';
    if ($inv['status'] === 'posted' && $inv['due_date'] && $inv['due_date'] < date('Y-m-d')) return '<span class="badge text-bg-danger">Overdue</span>';
    return match ($inv['status']) {
        'draft'  => '<span class="badge text-bg-warning">Draft</span>',
        'posted' => '<span class="badge text-bg-primary">Unpaid</span>',
        'paid'   => '<span class="badge text-bg-success">Paid</span>',
        'void'   => '<span class="badge text-bg-secondary">Void</span>',
        default  => e($inv['status']),
    };
}

function invoice_title(array $inv): string {
    $label = INVOICE_TYPES[$inv['type']]['label'];
    return $inv['number'] ? "{$label} {$inv['number']}" : "Draft {$label} #{$inv['id']}";
}

/** Accounts a line may post to: income for sales; expense + asset (e.g. inventory, equipment) for purchases. */
function invoice_line_accounts(string $type): array {
    $out = [];
    foreach (postable_accounts_by_type() as $accType => $list) {
        if (in_array($accType, INVOICE_TYPES[$type]['line_types'], true)) $out[$accType] = $list;
    }
    return $out;
}

/**
 * Usual TDS % for a party (Nepal Income Tax Act rates; the user can change it per invoice).
 * Sales: what the client withholds from us. Purchase: what we withhold from the supplier.
 */
function suggested_tds_percent(array $contact, string $type): float {
    return match ($contact['tds_category'] ?? 'none') {
        'contract' => 1.5,
        'service'  => $type === 'sales' ? 1.5 : ($contact['vat_registered'] ? 1.5 : 15),
        'rent'     => 10,
        default    => 0,
    };
}

function load_invoice(int $id): ?array {
    $stmt = db()->prepare(
        'SELECT i.*, c.name AS contact_name, c.pan_number AS contact_pan, c.vat_registered AS contact_vat_registered,
                c.address AS contact_address, c.phone AS contact_phone, c.email AS contact_email
         FROM acc_invoices i JOIN acc_contacts c ON c.id = i.contact_id WHERE i.id = ?'
    );
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if (!$inv) return null;
    $lines = db()->prepare('SELECT l.*, a.code AS account_code, a.name AS account_name FROM acc_invoice_lines l JOIN acc_accounts a ON a.id = l.account_id WHERE l.invoice_id = ? ORDER BY l.line_no');
    $lines->execute([$id]);
    $inv['lines'] = $lines->fetchAll();
    $pays = db()->prepare('SELECT p.*, a.name AS account_name, j.voucher_no, pm.method FROM acc_invoice_payments p JOIN acc_accounts a ON a.id = p.account_id LEFT JOIN acc_journal_entries j ON j.id = p.journal_entry_id LEFT JOIN acc_payments pm ON pm.id = p.payment_id WHERE p.invoice_id = ? ORDER BY p.payment_date, p.id');
    $pays->execute([$id]);
    $inv['payments'] = $pays->fetchAll();
    return $inv;
}

/**
 * Validates form input and computes all totals.
 * @return array{errors: string[], header: array, lines: array}
 */
function validate_invoice(string $type, array $in, ?int $selfId = null): array {
    $meta = INVOICE_TYPES[$type];
    $e = [];
    $contactId = (int)($in['contact_id'] ?? 0);
    $contact = contact_options()[$contactId] ?? null;
    if (!$contact || $contact['type'] !== $meta['contact']) $e[] = 'Choose a ' . $meta['contact'] . '.';
    $date = trim((string)($in['invoice_date'] ?? ''));
    if (!valid_ad_date($date)) $e[] = 'Enter a valid ' . ($type === 'sales' ? 'invoice' : 'bill') . ' date.';
    $due = trim((string)($in['due_date'] ?? '')) ?: null;
    if ($due !== null && !valid_ad_date($due)) $e[] = 'Due date is not valid.';
    elseif ($due !== null && valid_ad_date($date) && $due < $date) $e[] = 'Due date is before the invoice date.';
    $projectId = (int)($in['project_id'] ?? 0) ?: null;
    if ($projectId && !isset(project_options()[$projectId])) $e[] = 'Unknown project.';

    $number = trim((string)($in['number'] ?? ''));
    if (mb_strlen($number) > 40) $e[] = 'Number is too long.';
    if ($type === 'purchase' && $number === '') $e[] = "Enter the supplier's bill number (printed on the bill).";
    if ($number !== '' && $contact) {
        // Sales numbers are unique overall; a supplier's bill number is unique per supplier (catches duplicate entry of the same bill).
        $sql = $type === 'sales'
            ? "SELECT 1 FROM acc_invoices WHERE type = 'sales' AND number = ? AND status <> 'void' AND id <> ?"
            : "SELECT 1 FROM acc_invoices WHERE type = 'purchase' AND number = ? AND contact_id = " . $contactId . " AND status <> 'void' AND id <> ?";
        $dupe = db()->prepare($sql);
        $dupe->execute([$number, $selfId ?? 0]);
        if ($dupe->fetchColumn()) $e[] = $type === 'sales' ? "Invoice number {$number} is already used." : "Bill {$number} from this supplier is already entered.";
    }

    $pct = function (string $key, string $label) use ($in, &$e): string {
        $v = trim((string)($in[$key] ?? '')) ?: '0';
        if (!is_numeric($v) || $v < 0 || $v > 100) { $e[] = "{$label} must be between 0 and 100%."; return '0'; }
        return (string)round((float)$v, 2);
    };
    $retentionPct = $pct('retention_percent', 'Retention');
    $tdsPct = $pct('tds_percent', 'TDS');
    $vatRate = (float)setting('vat_rate', '13');

    $allowed = [];
    foreach (invoice_line_accounts($type) as $list) foreach ($list as $a) $allowed[(int)$a['id']] = true;
    $lines = [];
    $taxable = $exempt = 0;
    foreach (array_values($in['lines'] ?? []) as $i => $l) {
        $desc = trim((string)($l['description'] ?? ''));
        $qtyRaw = str_replace(',', '', trim((string)($l['quantity'] ?? '')));
        $rate = to_cents($l['rate'] ?? '');
        if ($desc === '' && $qtyRaw === '' && ($rate ?? 0) === 0) continue; // blank row
        $n = $i + 1;
        if ($desc === '' || mb_strlen($desc) > 255) $e[] = "Line {$n}: enter a description.";
        if ($qtyRaw === '') $qtyRaw = '1';
        if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $qtyRaw) || (float)$qtyRaw <= 0) { $e[] = "Line {$n}: quantity must be a positive number (up to 3 decimals)."; $qtyRaw = '0'; }
        if ($rate === null) { $e[] = "Line {$n}: rate must be an amount with up to 2 decimals."; $rate = 0; }
        $accountId = (int)($l['account_id'] ?? 0);
        if (!isset($allowed[$accountId])) $e[] = "Line {$n}: choose an account.";
        $amount = (int)round($rate * (float)$qtyRaw);
        $vat = !empty($l['vat_applicable']);
        if ($vat) $taxable += $amount; else $exempt += $amount;
        $lines[] = [
            'description' => $desc, 'quantity' => $qtyRaw, 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null,
            'rate' => $rate, 'amount' => $amount, 'vat_applicable' => $vat ? 1 : 0, 'account_id' => $accountId,
        ];
    }
    if (!$lines) $e[] = 'Add at least one line.';
    if ($type === 'purchase' && $taxable > 0 && $contact && !contact_is_vat_registered($contactId)) {
        $e[] = 'This supplier is not VAT-registered, so their bill cannot include VAT. Untick VAT on the lines, or mark the supplier as VAT-registered.';
    }

    $vatAmount = (int)round($taxable * $vatRate / 100);
    $base = $taxable + $exempt;
    $total = $base + $vatAmount;
    $retention = (int)round($base * (float)$retentionPct / 100);
    $tds = (int)round($base * (float)$tdsPct / 100);
    $net = $total - $retention - $tds;
    if ($lines && $net < 0) $e[] = 'Retention and TDS are more than the invoice total.';

    $notes = trim((string)($in['notes'] ?? ''));
    return ['errors' => $e, 'lines' => $lines, 'header' => [
        'type' => $type, 'number' => $number === '' ? null : $number, 'number_is_manual' => ($type === 'sales' && $number !== '') ? 1 : 0,
        'contact_id' => $contactId, 'project_id' => $projectId, 'invoice_date' => $date, 'due_date' => $due,
        'vat_rate' => (string)$vatRate, 'taxable_amount' => $taxable, 'exempt_amount' => $exempt, 'vat_amount' => $vatAmount,
        'total_amount' => $total, 'retention_percent' => $retentionPct, 'retention_amount' => $retention,
        'tds_percent' => $tdsPct, 'tds_amount' => $tds, 'net_amount' => $net, 'notes' => $notes === '' ? null : mb_substr($notes, 0, 500),
    ]];
}

function contact_is_vat_registered(int $id): bool {
    $stmt = db()->prepare('SELECT vat_registered FROM acc_contacts WHERE id = ?');
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

/** Saves a draft (insert or update). Returns the invoice id. */
function save_invoice_draft(array $check, int $userId, ?int $id = null): int {
    $h = $check['header'];
    return in_transaction(function (PDO $pdo) use ($h, $check, $userId, $id) {
        $cols = array_keys($h);
        $vals = array_map(fn($c) => in_array($c, INVOICE_MONEY_FIELDS, true) ? cents_to_decimal($h[$c]) : $h[$c], $cols);
        if ($id) {
            $st = $pdo->prepare('SELECT status FROM acc_invoices WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            if ($st->fetchColumn() !== 'draft') throw new DomainException('Only drafts can be edited.');
            $pdo->prepare('UPDATE acc_invoices SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            $pdo->prepare('DELETE FROM acc_invoice_lines WHERE invoice_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO acc_invoices (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)')
                ->execute([...$vals, $userId]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO acc_invoice_lines (invoice_id, line_no, description, quantity, unit, rate, amount, vat_applicable, account_id) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($check['lines'] as $i => $l) {
            $ins->execute([$id, $i + 1, $l['description'], $l['quantity'], $l['unit'], cents_to_decimal($l['rate']), cents_to_decimal($l['amount']), $l['vat_applicable'], $l['account_id']]);
        }
        audit('save_' . $h['type'] . '_draft', 'invoice', $id);
        return $id;
    });
}

/** Posts a draft to the ledger (assigning the next sales invoice number if none was typed). */
function post_invoice(int $id, int $userId): string {
    return in_transaction(function (PDO $pdo) use ($id, $userId) {
        $st = $pdo->prepare('SELECT status FROM acc_invoices WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        if ($st->fetchColumn() !== 'draft') throw new DomainException('Only drafts can be posted.');
        $inv = load_invoice($id);
        foreach (INVOICE_MONEY_FIELDS as $f) $inv[$f] = decimal_to_cents($inv[$f]);
        $type = $inv['type'];
        $meta = INVOICE_TYPES[$type];

        if ($type === 'sales' && !$inv['number']) {
            $fy = fiscal_year_for($inv['invoice_date']);
            $inv['number'] = sprintf('SI/%s/%04d', str_replace('/', '-', $fy), next_sequence('SI', $fy));
            $pdo->prepare('UPDATE acc_invoices SET number = ? WHERE id = ?')->execute([$inv['number'], $id]);
        }

        $c = $inv['contact_id'];
        $p = $inv['project_id'] ?: '';
        $byAccount = [];
        foreach ($inv['lines'] as $l) $byAccount[(int)$l['account_id']] = ($byAccount[(int)$l['account_id']] ?? 0) + decimal_to_cents($l['amount']);
        $side = $type === 'sales' ? 'credit' : 'debit';     // side the income/expense lines go on
        $other = $type === 'sales' ? 'debit' : 'credit';

        $lines = [];
        foreach ($byAccount as $acc => $amt) $lines[] = ['account_id' => $acc, $side => cents_to_decimal($amt), 'project_id' => $p, 'contact_id' => $c];
        if ($inv['vat_amount']) $lines[] = ['account_id' => system_account_id($type === 'sales' ? 'vat_output' : 'vat_input'), $side => cents_to_decimal($inv['vat_amount']), 'description' => 'VAT ' . rtrim(rtrim($inv['vat_rate'], '0'), '.') . '%'];
        $control = $type === 'sales'
            ? [['accounts_receivable', $inv['net_amount'], 'Net receivable'], ['retention_receivable', $inv['retention_amount'], 'Retention withheld by client'], ['tds_receivable', $inv['tds_amount'], 'TDS withheld by client']]
            : [['accounts_payable', $inv['net_amount'], 'Net payable'], ['retention_payable', $inv['retention_amount'], 'Retention withheld from supplier'], ['tds_payable', $inv['tds_amount'], 'TDS withheld from supplier']];
        foreach ($control as [$key, $amt, $desc]) {
            if ($amt) $lines[] = ['account_id' => system_account_id($key), $other => cents_to_decimal($amt), 'contact_id' => $c, 'project_id' => $p, 'description' => $desc];
        }

        $result = save_journal_entry([
            'entry_date' => $inv['invoice_date'],
            'narration'  => $meta['label'] . ' ' . $inv['number'] . ' — ' . $inv['contact_name'],
            'reference'  => $inv['number'],
            'lines'      => $lines,
        ], $userId, null, true, $meta['source'], $id);
        if ($result['errors']) throw new DomainException(implode(' ', $result['errors']));

        $pdo->prepare("UPDATE acc_invoices SET status = IF(net_amount = 0, 'paid', 'posted'), journal_entry_id = ?, posted_by = ?, posted_at = NOW() WHERE id = ?")
            ->execute([$result['id'], $userId, $id]);
        audit('post_' . $type, 'invoice', $id, $inv['number']);
        return $inv['number'];
    });
}

/** Records money received/paid against this one invoice — a payment with a single allocation (see payments.php). */
function record_invoice_payment(array $inv, int $userId, string $date, string $amountRaw, int $accountId, string $method, string $reference): string {
    if ($inv['status'] !== 'posted') throw new DomainException('Payments can only be recorded against a posted, unpaid invoice.');
    $amount = to_cents($amountRaw);
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the amount.');
    $result = save_payment($inv['type'] === 'sales' ? 'in' : 'out', [
        'contact_id' => $inv['contact_id'], 'payment_date' => $date, 'amount' => $amountRaw, 'account_id' => $accountId,
        'method' => $method, 'reference' => $reference, 'alloc' => [(int)$inv['id'] => $amountRaw],
    ], $userId);
    return $result['voucher'];
}
function void_invoice(array $inv, int $userId, string $reason): void {
    if (!in_array($inv['status'], ['posted', 'paid'], true)) throw new DomainException('Only posted invoices can be voided.');
    if (trim($reason) === '') throw new DomainException('Give a reason for voiding.');
    foreach ($inv['payments'] as $p) if ($p['status'] === 'active') throw new DomainException('Void its payments first.');
    in_transaction(function (PDO $pdo) use ($inv, $userId, $reason) {
        void_journal_entry((int)$inv['journal_entry_id'], $userId, "Invoice voided: {$reason}", true);
        $pdo->prepare("UPDATE acc_invoices SET status = 'void', notes = LEFT(CONCAT(COALESCE(notes, ''), ?), 500) WHERE id = ?")
            ->execute([($inv['notes'] ? "\n" : '') . 'Voided: ' . mb_substr($reason, 0, 200), $inv['id']]);
        audit('void_' . $inv['type'], 'invoice', (int)$inv['id'], ($inv['number'] ?? '') . ": {$reason}");
    });
}
