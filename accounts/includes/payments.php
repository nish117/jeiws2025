<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Payments: money received from clients (direction 'in') and paid to
 * suppliers (direction 'out').
 *
 * One payment can settle several invoices; whatever isn't allocated is an
 * advance held for that party:
 *   in:  Dr Cash/Bank (amount)          Cr Receivable per invoice + Cr Client advances (remainder)
 *   out: Dr Payable per invoice + Dr Supplier advances (remainder)          Cr Cash/Bank (amount)
 * Applying an advance to an invoice later moves it without any cash:
 *   in:  Dr Client advances  Cr Receivable        out: Dr Payable  Cr Supplier advances
 */

const PAYMENT_METHODS = [
    'cash'          => 'Cash',
    'cheque'        => 'Cheque',
    'bank_transfer' => 'Bank transfer',
    'wallet'        => 'eSewa / Khalti / Fonepay',
    'advance'       => 'Advance applied',
];

const PAYMENT_DIRECTIONS = [
    'in'  => ['label' => 'Money received', 'verb' => 'Receive money', 'contact' => 'client',   'invoice' => 'sales',    'source' => 'sales_receipt', 'advance' => 'client_advances',   'control' => 'accounts_receivable', 'icon' => 'fa-arrow-down'],
    'out' => ['label' => 'Money paid',     'verb' => 'Make payment',  'contact' => 'supplier', 'invoice' => 'purchase', 'source' => 'bill_payment',  'advance' => 'supplier_advances', 'control' => 'accounts_payable',    'icon' => 'fa-arrow-up'],
];

/** Posted, not fully paid invoices of a party, oldest invoice date first (the "oldest first" fill order), with outstanding in paisa. */
function open_invoices_for(int $contactId, string $invoiceType): array {
    $stmt = db()->prepare(
        "SELECT id, number, invoice_date, due_date, project_id, net_amount, amount_paid
         FROM acc_invoices WHERE contact_id = ? AND type = ? AND status = 'posted'
         ORDER BY invoice_date, id"
    );
    $stmt->execute([$contactId, $invoiceType]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['outstanding'] = decimal_to_cents($r['net_amount']) - decimal_to_cents($r['amount_paid']);
        if ($r['outstanding'] > 0) $rows[(int)$r['id']] = $r;
    }
    return $rows;
}

/** Advance held for a party, in paisa (positive = advance available to apply). */
function contact_advance_balance(int $contactId, string $direction): int {
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(l.debit), 0) AS dr, COALESCE(SUM(l.credit), 0) AS cr
         FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
         WHERE e.status = 'posted' AND l.contact_id = ? AND l.account_id = ?"
    );
    $stmt->execute([$contactId, system_account_id(PAYMENT_DIRECTIONS[$direction]['advance'])]);
    $r = $stmt->fetch();
    $raw = decimal_to_cents($r['dr']) - decimal_to_cents($r['cr']);
    return $direction === 'in' ? -$raw : $raw; // client advance is a credit balance, supplier advance a debit
}

function load_payment(int $id): ?array {
    $stmt = db()->prepare(
        'SELECT p.*, c.name AS contact_name, c.type AS contact_type, c.pan_number AS contact_pan, c.address AS contact_address,
                a.code AS account_code, a.name AS account_name, j.voucher_no, u.full_name AS created_by_name
         FROM acc_payments p JOIN acc_contacts c ON c.id = p.contact_id JOIN acc_accounts a ON a.id = p.account_id
         LEFT JOIN acc_journal_entries j ON j.id = p.journal_entry_id LEFT JOIN acc_users u ON u.id = p.created_by
         WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) return null;
    $alloc = db()->prepare('SELECT ip.*, i.number, i.invoice_date, i.net_amount FROM acc_invoice_payments ip JOIN acc_invoices i ON i.id = ip.invoice_id WHERE ip.payment_id = ? ORDER BY ip.id');
    $alloc->execute([$id]);
    $p['allocations'] = $alloc->fetchAll();
    return $p;
}

/**
 * Records a receipt or payment. $in: contact_id, payment_date, amount, account_id, method, reference, notes,
 * alloc => [invoice_id => amount]. Returns ['id' => payment id, 'voucher' => …].
 * Throws DomainException with a readable message on any problem.
 */
function save_payment(string $direction, array $in, int $userId): array {
    $d = PAYMENT_DIRECTIONS[$direction];
    $contactId = (int)($in['contact_id'] ?? 0);
    $contact = contact_options()[$contactId] ?? null;
    if (!$contact || $contact['type'] !== $d['contact']) throw new DomainException('Choose a ' . $d['contact'] . '.');
    $date = trim((string)($in['payment_date'] ?? ''));
    if (!valid_ad_date($date)) throw new DomainException('Enter a valid date.');
    $amount = to_cents($in['amount'] ?? '');
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the amount.');
    $method = (string)($in['method'] ?? '');
    if (!isset(PAYMENT_METHODS[$method]) || $method === 'advance') throw new DomainException('Choose how the money was ' . ($direction === 'in' ? 'received' : 'paid') . '.');
    $accountId = (int)($in['account_id'] ?? 0);
    if (!isset(payment_account_options()[$accountId])) throw new DomainException('Choose the cash or bank account.');
    $reference = mb_substr(trim((string)($in['reference'] ?? '')), 0, 100);
    if ($method === 'cheque' && $reference === '') throw new DomainException('Enter the cheque number in Reference.');
    $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 255);

    $open = open_invoices_for($contactId, $d['invoice']);
    $allocs = [];
    foreach ((array)($in['alloc'] ?? []) as $invId => $raw) {
        $c = to_cents($raw);
        if ($c === null) throw new DomainException('Allocation amounts must be numbers.');
        if ($c === 0) continue;
        $inv = $open[(int)$invId] ?? null;
        if (!$inv) throw new DomainException('One of the invoices is no longer open — reload and try again.');
        if ($c > $inv['outstanding']) throw new DomainException("More than the {$inv['number']} outstanding (" . money_cents($inv['outstanding'], true) . ').');
        $allocs[(int)$invId] = $c;
    }
    $allocated = array_sum($allocs);
    if ($allocated > $amount) throw new DomainException('Allocated ' . money_cents($allocated, true) . ' is more than the amount ' . money_cents($amount, true) . '.');
    $advance = $amount - $allocated;

    return post_payment($direction, $contactId, $contact['name'], $date, $amount, $advance, $accountId, $method, $reference, $notes, $allocs, $open, $userId);
}

/** Applies a party's held advance to one invoice (no cash moves). */
function apply_advance(array $inv, string $amountRaw, string $date, int $userId): array {
    $direction = $inv['type'] === 'sales' ? 'in' : 'out';
    $d = PAYMENT_DIRECTIONS[$direction];
    if (!valid_ad_date($date)) throw new DomainException('Enter a valid date.');
    $amount = to_cents($amountRaw);
    $open = open_invoices_for((int)$inv['contact_id'], $inv['type']);
    $outstanding = $open[(int)$inv['id']]['outstanding'] ?? 0;
    $available = contact_advance_balance((int)$inv['contact_id'], $direction);
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the amount to apply.');
    if ($amount > $available) throw new DomainException('Only ' . money_cents($available, true) . ' of advance is available.');
    if ($amount > $outstanding) throw new DomainException('More than the ' . money_cents($outstanding, true) . ' outstanding.');
    return post_payment($direction, (int)$inv['contact_id'], $inv['contact_name'], $date, $amount, 0, system_account_id($d['advance']),
        'advance', 'Advance applied', '', [(int)$inv['id'] => $amount], $open, $userId);
}

/** Writes the voucher, the payment row, its allocations and the invoices' paid amounts — all or nothing. */
function post_payment(string $direction, int $contactId, string $contactName, string $date, int $amount, int $advance, int $accountId,
                      string $method, string $reference, string $notes, array $allocs, array $open, int $userId): array {
    $d = PAYMENT_DIRECTIONS[$direction];
    $isAdjustment = $method === 'advance';
    $control = system_account_id($d['control']);
    $money = $direction === 'in' ? 'debit' : 'credit';   // side of the cash/bank (or advance) account
    $party = $direction === 'in' ? 'credit' : 'debit';   // side of receivable/payable

    $lines = [['account_id' => $accountId, $money => cents_to_decimal($amount), 'contact_id' => $isAdjustment ? $contactId : '', 'description' => $isAdjustment ? 'Advance applied' : PAYMENT_METHODS[$method]]];
    foreach ($allocs as $invId => $c) {
        $lines[] = ['account_id' => $control, $party => cents_to_decimal($c), 'contact_id' => $contactId, 'project_id' => $open[$invId]['project_id'] ?: '', 'description' => 'Against ' . $open[$invId]['number']];
    }
    if ($advance) $lines[] = ['account_id' => system_account_id($d['advance']), $party => cents_to_decimal($advance), 'contact_id' => $contactId, 'description' => 'Advance'];

    $invoiceList = implode(', ', array_map(fn($id) => $open[$id]['number'], array_keys($allocs)));
    $narration = $isAdjustment
        ? "Advance applied to {$invoiceList} — {$contactName}"
        : ($direction === 'in' ? 'Received from ' : 'Paid to ') . $contactName . ($invoiceList ? " against {$invoiceList}" : '') . ($advance ? ($invoiceList ? ' + advance' : ' — advance') : '');

    return in_transaction(function (PDO $pdo) use ($direction, $d, $contactId, $date, $amount, $advance, $accountId, $method, $reference, $notes, $allocs, $lines, $narration, $isAdjustment, $userId) {
        $result = save_journal_entry(['entry_date' => $date, 'narration' => mb_substr($narration, 0, 500), 'reference' => $reference, 'lines' => $lines],
            $userId, null, true, $isAdjustment ? 'advance_adjustment' : $d['source'], null);
        if ($result['errors']) throw new DomainException(implode(' ', $result['errors']));
        $jeId = $result['id'];

        $pdo->prepare('INSERT INTO acc_payments (direction, contact_id, payment_date, amount, unallocated_amount, account_id, method, reference, notes, journal_entry_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$direction, $contactId, $date, cents_to_decimal($amount), cents_to_decimal($advance), $accountId, $method, $reference ?: null, $notes ?: null, $jeId, $userId]);
        $paymentId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE acc_journal_entries SET source_id = ? WHERE id = ?')->execute([$paymentId, $jeId]);

        $ins = $pdo->prepare('INSERT INTO acc_invoice_payments (invoice_id, payment_id, payment_date, amount, account_id, reference, journal_entry_id, created_by) VALUES (?,?,?,?,?,?,?,?)');
        $upd = $pdo->prepare("UPDATE acc_invoices SET amount_paid = amount_paid + ?, status = IF(amount_paid >= net_amount, 'paid', 'posted') WHERE id = ? AND status = 'posted'");
        foreach ($allocs as $invId => $c) {
            $ins->execute([$invId, $paymentId, $date, cents_to_decimal($c), $accountId, $reference ?: null, $jeId, $userId]);
            $upd->execute([cents_to_decimal($c), $invId]);
            if ($upd->rowCount() !== 1) throw new DomainException('An invoice changed while saving — reload and try again.');
        }
        audit($isAdjustment ? 'apply_advance' : 'record_payment_' . $direction, 'payment', $paymentId, money_cents($amount));
        $voucher = (string)$pdo->query('SELECT voucher_no FROM acc_journal_entries WHERE id = ' . (int)$jeId)->fetchColumn();
        return ['id' => $paymentId, 'voucher' => $voucher];
    });
}

/** Voids a payment: its voucher, every allocation, and the invoices go back to unpaid by those amounts. */
function void_payment(array $payment, int $userId, string $reason): void {
    if ($payment['status'] !== 'active') throw new DomainException('This payment is already void.');
    $reason = trim($reason);
    if ($reason === '') throw new DomainException('Give a reason for voiding.');
    if ($payment['unallocated_amount'] > 0 && $payment['method'] !== 'advance') {
        $left = contact_advance_balance((int)$payment['contact_id'], $payment['direction']);
        if ($left < decimal_to_cents($payment['unallocated_amount'])) {
            throw new DomainException('Part of this payment\'s advance has already been applied to invoices. Void those advance adjustments first.');
        }
    }
    in_transaction(function (PDO $pdo) use ($payment, $userId, $reason) {
        if ($payment['journal_entry_id']) void_journal_entry((int)$payment['journal_entry_id'], $userId, "Payment voided: {$reason}", true);
        foreach ($payment['allocations'] as $a) {
            if ($a['status'] !== 'active') continue;
            $pdo->prepare("UPDATE acc_invoice_payments SET status = 'void' WHERE id = ?")->execute([$a['id']]);
            $pdo->prepare("UPDATE acc_invoices SET amount_paid = amount_paid - ?, status = IF(status = 'paid', 'posted', status) WHERE id = ?")->execute([$a['amount'], $a['invoice_id']]);
        }
        $pdo->prepare("UPDATE acc_payments SET status = 'void', void_reason = ? WHERE id = ?")->execute([mb_substr($reason, 0, 255), $payment['id']]);
        audit('void_payment', 'payment', (int)$payment['id'], $reason);
    });
}
