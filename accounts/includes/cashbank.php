<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Cash & bank: the accounts money is held in, transfers between them,
 * other money in/out (bank charges, interest …) and bank reconciliation.
 *
 * Every cash/bank/wallet account is an ordinary ledger account (banks and
 * wallets under 1130 Bank Accounts, cash boxes under 1100 Current Assets)
 * plus a row in acc_bank_accounts with its details. Balances are always
 * read from posted journal lines — nothing is stored twice.
 */

const CASH_KINDS = [
    'bank'   => ['label' => 'Bank account',    'plural' => 'Bank accounts',   'icon' => 'fa-building-columns'],
    'cash'   => ['label' => 'Cash box',        'plural' => 'Cash',            'icon' => 'fa-money-bill-wave'],
    'wallet' => ['label' => 'Digital wallet',  'plural' => 'Digital wallets', 'icon' => 'fa-mobile-screen'],
];

const BANK_ACCOUNT_TYPES = [
    'current'       => 'Current',
    'savings'       => 'Savings',
    'overdraft'     => 'Overdraft (OD)',
    'fixed_deposit' => 'Fixed deposit',
    'other'         => 'Other',
];

/**
 * Cash/bank accounts with their details and posted balance (paisa, Dr − Cr).
 * @return array<int, array> keyed by ledger account id
 */
function cash_bank_accounts(bool $activeOnly = true): array {
    $rows = db()->query(
        "SELECT a.id, a.code, a.name, a.is_active, a.system_key, b.id AS detail_id, b.kind, b.bank_name, b.branch, b.account_number, b.account_type, b.notes,
                COALESCE(t.dr, 0) AS dr, COALESCE(t.cr, 0) AS cr, t.last_date
         FROM acc_bank_accounts b JOIN acc_accounts a ON a.id = b.account_id
         LEFT JOIN (SELECT l.account_id, SUM(l.debit) AS dr, SUM(l.credit) AS cr, MAX(e.entry_date) AS last_date
                    FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
                    WHERE e.status = 'posted' GROUP BY l.account_id) t ON t.account_id = a.id
         ORDER BY FIELD(b.kind, 'bank', 'wallet', 'cash'), a.code"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ($activeOnly && !$r['is_active']) continue;
        $r['balance'] = decimal_to_cents($r['dr']) - decimal_to_cents($r['cr']);
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

/** Accounts money can be received into or paid from (used by payments, invoices and payroll). */
function payment_account_options(): array {
    $tree = account_tree(true);
    $out = [];
    foreach (cash_bank_accounts() as $id => $a) if (isset($tree[$id])) $out[$id] = $tree[$id];
    return $out;
}

function cash_account_label(array $a): string {
    $extra = $a['kind'] === 'bank' && $a['account_number'] ? ' · ' . mask_account_number($a['account_number']) : '';
    return $a['name'] . $extra;
}

function mask_account_number(?string $n): string {
    $n = (string)$n;
    return strlen($n) > 4 ? '••••' . substr($n, -4) : $n;
}

/** Next free code like 1130-01 under a parent. */
function next_child_code(string $base): string {
    $stmt = db()->prepare('SELECT code FROM acc_accounts WHERE code LIKE ?');
    $stmt->execute([$base . '-%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) $max = max($max, (int)substr($code, strlen($base) + 1));
    return sprintf('%s-%02d', $base, $max + 1);
}

/** @return array{errors: string[], values: array} */
function validate_cash_account(array $in, string $kind, bool $isNew): array {
    $t = fn(string $k) => trim((string)($in[$k] ?? ''));
    $v = [
        'name' => $t('name'), 'bank_name' => $t('bank_name') ?: null, 'branch' => $t('branch') ?: null,
        'account_number' => preg_replace('/\s+/', '', $t('account_number')) ?: null,
        'account_type' => $t('account_type') ?: null, 'notes' => $t('notes') ?: null,
        'opening_amount' => 0, 'opening_date' => $t('opening_date'), 'overdrawn' => !empty($in['overdrawn']),
    ];
    $e = [];
    if ($v['name'] === '' || mb_strlen($v['name']) > 150) $e[] = 'Enter a name (e.g. "Nabil Bank – Current" or "Site cash – Sanepa").';
    if ($kind === 'bank') {
        if (!$v['bank_name']) $e[] = 'Enter the bank name.';
        if ($v['account_type'] !== null && !isset(BANK_ACCOUNT_TYPES[$v['account_type']])) $e[] = 'Choose the account type.';
    } else {
        $v['account_type'] = null;
    }
    if ($v['account_number'] !== null && !preg_match('/^[A-Za-z0-9\-\/]{3,40}$/', $v['account_number'])) $e[] = 'Account number can only contain letters, digits, dashes and slashes.';
    foreach (['bank_name', 'branch', 'notes'] as $k) if ($v[$k] !== null && mb_strlen($v[$k]) > ($k === 'notes' ? 255 : 100)) $e[] = 'One of the fields is too long.';
    if ($isNew) {
        $amt = to_cents($in['opening_amount'] ?? '');
        if ($amt === null) $e[] = 'Opening balance must be an amount.';
        $v['opening_amount'] = (int)$amt;
        if ($v['opening_amount'] && !valid_ad_date($v['opening_date'])) $e[] = 'Enter the date of the opening balance.';
    }
    return ['errors' => $e, 'values' => $v];
}

/** Creates the ledger account + details, and posts the opening balance if any. Returns the ledger account id. */
function create_cash_account(string $kind, array $v, int $userId): int {
    return in_transaction(function (PDO $pdo) use ($kind, $v, $userId) {
        if ($kind === 'cash') {
            $parentId = (int)$pdo->query("SELECT parent_id FROM acc_accounts WHERE system_key = 'cash_in_hand'")->fetchColumn();
            $code = next_child_code('1110');
        } else {
            $parentId = system_account_id('bank_accounts_group');
            $code = next_child_code('1130');
        }
        $pdo->prepare("INSERT INTO acc_accounts (parent_id, code, name, type, is_group, description) VALUES (?, ?, ?, 'asset', 0, ?)")
            ->execute([$parentId, $code, $v['name'], CASH_KINDS[$kind]['label']]);
        $accountId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO acc_bank_accounts (account_id, kind, bank_name, branch, account_number, account_type, notes, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$accountId, $kind, $v['bank_name'], $v['branch'], $v['account_number'], $v['account_type'], $v['notes'], $userId]);

        if ($v['opening_amount']) {
            $amt = cents_to_decimal($v['opening_amount']);
            $obe = system_account_id('opening_balance_equity');
            $lines = $v['overdrawn']
                ? [['account_id' => $obe, 'debit' => $amt], ['account_id' => $accountId, 'credit' => $amt]]
                : [['account_id' => $accountId, 'debit' => $amt], ['account_id' => $obe, 'credit' => $amt]];
            $r = save_journal_entry(['entry_date' => $v['opening_date'], 'narration' => "Opening balance — {$v['name']}", 'reference' => 'Opening balance', 'lines' => $lines],
                $userId, null, true, 'opening_balance', $accountId);
            if ($r['errors']) throw new DomainException(implode(' ', $r['errors']));
        }
        audit('create_cash_account', 'account', $accountId, "{$code} {$v['name']}");
        return $accountId;
    });
}

function update_cash_account(array $acc, array $v, bool $active): void {
    if (!$active && $acc['balance'] !== 0) throw new DomainException('Bring the balance to zero (transfer it out) before closing this account.');
    if (!$active && $acc['system_key']) throw new DomainException('This is a system account and stays active.');
    in_transaction(function (PDO $pdo) use ($acc, $v, $active) {
        $pdo->prepare('UPDATE acc_accounts SET name = ?, is_active = ? WHERE id = ?')->execute([$v['name'], $active ? 1 : 0, $acc['id']]);
        $pdo->prepare('UPDATE acc_bank_accounts SET bank_name = ?, branch = ?, account_number = ?, account_type = ?, notes = ? WHERE account_id = ?')
            ->execute([$v['bank_name'], $v['branch'], $v['account_number'], $v['account_type'], $v['notes'], $acc['id']]);
        audit('update_cash_account', 'account', (int)$acc['id'], $v['name'] . ($active ? '' : ' (closed)'));
    });
}

/** Moves money between two cash/bank accounts. Returns the voucher number. */
function transfer_between_accounts(int $fromId, int $toId, string $amountRaw, string $date, string $reference, string $notes, int $userId): string {
    $accounts = payment_account_options();
    if (!isset($accounts[$fromId], $accounts[$toId])) throw new DomainException('Choose both accounts.');
    if ($fromId === $toId) throw new DomainException('Choose two different accounts.');
    $amount = to_cents($amountRaw);
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the amount.');
    if (!valid_ad_date($date)) throw new DomainException('Enter a valid date.');
    $from = $accounts[$fromId]['name'];
    $to = $accounts[$toId]['name'];
    $r = save_journal_entry([
        'entry_date' => $date, 'narration' => mb_substr("Transfer from {$from} to {$to}" . ($notes !== '' ? " — {$notes}" : ''), 0, 500),
        'reference' => mb_substr($reference, 0, 100),
        'lines' => [['account_id' => $toId, 'debit' => cents_to_decimal($amount)], ['account_id' => $fromId, 'credit' => cents_to_decimal($amount)]],
    ], $userId, null, true, 'transfer', null);
    if ($r['errors']) throw new DomainException(implode(' ', $r['errors']));
    audit('cash_transfer', 'journal_entry', $r['id'], money_cents($amount) . " {$from} → {$to}");
    return (string)db()->query('SELECT voucher_no FROM acc_journal_entries WHERE id = ' . (int)$r['id'])->fetchColumn();
}

/** Accounts an "other" entry can be booked against: income, expense, and non-control assets/liabilities/equity. */
function other_entry_counter_accounts(): array {
    $exclude = array_keys(cash_bank_accounts(false));
    foreach (PARTY_CONTROL_ACCOUNTS as $k) $exclude[] = system_account_id($k);   // those need a party → use Payments
    $out = [];
    foreach (postable_accounts_by_type() as $type => $list) {
        foreach ($list as $a) if (!in_array((int)$a['id'], $exclude, true)) $out[$type][] = $a;
    }
    return $out;
}

/** Money in or out of a cash/bank account that isn't a client/supplier payment. Returns the voucher number. */
function record_other_entry(array $in, int $userId): string {
    $direction = ($in['direction'] ?? '') === 'out' ? 'out' : 'in';
    $accountId = (int)($in['account_id'] ?? 0);
    $counterId = (int)($in['counter_account_id'] ?? 0);
    if (!isset(payment_account_options()[$accountId])) throw new DomainException('Choose the cash or bank account.');
    $allowed = [];
    foreach (other_entry_counter_accounts() as $list) foreach ($list as $a) $allowed[(int)$a['id']] = $a;
    if (!isset($allowed[$counterId])) throw new DomainException('Choose what the money is for.');
    $amount = to_cents($in['amount'] ?? '');
    if ($amount === null || $amount <= 0) throw new DomainException('Enter the amount.');
    $date = trim((string)($in['entry_date'] ?? ''));
    if (!valid_ad_date($date)) throw new DomainException('Enter a valid date.');
    $description = trim((string)($in['description'] ?? ''));
    if ($description === '') throw new DomainException('Describe the entry (e.g. "Bank service charge Ashwin").');
    $projectId = (int)($in['project_id'] ?? 0) ?: '';

    $amt = cents_to_decimal($amount);
    $lines = $direction === 'in'
        ? [['account_id' => $accountId, 'debit' => $amt], ['account_id' => $counterId, 'credit' => $amt, 'project_id' => $projectId]]
        : [['account_id' => $counterId, 'debit' => $amt, 'project_id' => $projectId], ['account_id' => $accountId, 'credit' => $amt]];
    $r = save_journal_entry(['entry_date' => $date, 'narration' => mb_substr($description, 0, 500), 'reference' => mb_substr(trim((string)($in['reference'] ?? '')), 0, 100), 'lines' => $lines],
        $userId, null, true, 'cash_bank', $accountId);
    if ($r['errors']) throw new DomainException(implode(' ', $r['errors']));
    audit('cash_entry_' . $direction, 'journal_entry', $r['id'], money_cents($amount));
    return (string)db()->query('SELECT voucher_no FROM acc_journal_entries WHERE id = ' . (int)$r['id'])->fetchColumn();
}

/**
 * Posted transactions on one account with running balance, the other side of each entry,
 * and whether the line has been cleared in a completed bank reconciliation.
 */
function cash_account_register(int $accountId, string $from, string $to): array {
    $o = db()->prepare("SELECT COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id WHERE e.status = 'posted' AND l.account_id = ? AND e.entry_date < ?");
    $o->execute([$accountId, $from]);
    $op = $o->fetch();
    $opening = decimal_to_cents($op['dr']) - decimal_to_cents($op['cr']);

    $stmt = db()->prepare(
        "SELECT l.id AS line_id, e.id AS entry_id, e.voucher_no, e.entry_date, e.narration, e.reference, e.source, l.debit, l.credit,
                (SELECT GROUP_CONCAT(DISTINCT a2.name ORDER BY a2.name SEPARATOR ', ') FROM acc_journal_lines l2 JOIN acc_accounts a2 ON a2.id = l2.account_id WHERE l2.entry_id = e.id AND l2.id <> l.id) AS other_side,
                (SELECT r.status FROM acc_reconciliation_lines rl JOIN acc_bank_reconciliations r ON r.id = rl.reconciliation_id WHERE rl.journal_line_id = l.id) AS recon_status
         FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
         WHERE e.status = 'posted' AND l.account_id = ? AND e.entry_date BETWEEN ? AND ?
         ORDER BY e.entry_date, e.posted_at, e.id, l.line_no"
    );
    $stmt->execute([$accountId, $from, $to]);
    $rows = [];
    $running = $opening;
    foreach ($stmt->fetchAll() as $r) {
        $r['dr'] = decimal_to_cents($r['debit']);
        $r['cr'] = decimal_to_cents($r['credit']);
        $running += $r['dr'] - $r['cr'];
        $r['balance'] = $running;
        $rows[] = $r;
    }
    return ['opening' => $opening, 'rows' => $rows, 'closing' => $running];
}

// ── Bank reconciliation ──────────────────────────────────────────────
function current_reconciliation(int $accountId): ?array {
    $stmt = db()->prepare("SELECT * FROM acc_bank_reconciliations WHERE account_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$accountId]);
    return $stmt->fetch() ?: null;
}

function last_completed_reconciliation(int $accountId): ?array {
    $stmt = db()->prepare("SELECT * FROM acc_bank_reconciliations WHERE account_id = ? AND status = 'completed' ORDER BY statement_date DESC, id DESC LIMIT 1");
    $stmt->execute([$accountId]);
    return $stmt->fetch() ?: null;
}

/** Sum (Dr − Cr, paisa) of lines cleared in completed reconciliations of an account. */
function cleared_balance(int $accountId): int {
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr FROM acc_reconciliation_lines rl
         JOIN acc_bank_reconciliations r ON r.id = rl.reconciliation_id AND r.status = 'completed'
         JOIN acc_journal_lines l ON l.id = rl.journal_line_id JOIN acc_journal_entries e ON e.id = l.entry_id AND e.status = 'posted'
         WHERE r.account_id = ?"
    );
    $stmt->execute([$accountId]);
    $r = $stmt->fetch();
    return decimal_to_cents($r['dr']) - decimal_to_cents($r['cr']);
}

/** Posted lines not yet cleared by a completed reconciliation, up to the statement date. */
function unreconciled_lines(int $accountId, string $upTo, ?int $reconId): array {
    $stmt = db()->prepare(
        "SELECT l.id AS line_id, e.id AS entry_id, e.voucher_no, e.entry_date, e.narration, e.reference, l.debit, l.credit,
                (rl.reconciliation_id IS NOT NULL) AS ticked
         FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
         LEFT JOIN acc_reconciliation_lines rl ON rl.journal_line_id = l.id
         LEFT JOIN acc_bank_reconciliations r ON r.id = rl.reconciliation_id
         WHERE e.status = 'posted' AND l.account_id = ? AND e.entry_date <= ?
           AND (rl.reconciliation_id IS NULL OR (r.status = 'in_progress' AND r.id = ?))
         ORDER BY e.entry_date, e.id, l.line_no"
    );
    $stmt->execute([$accountId, $upTo, $reconId ?? 0]);
    return $stmt->fetchAll();
}

function start_reconciliation(int $accountId, string $date, string $balanceRaw, int $userId): int {
    if (!isset(cash_bank_accounts()[$accountId])) throw new DomainException('Unknown account.');
    if (current_reconciliation($accountId)) throw new DomainException('A reconciliation is already in progress for this account.');
    if (!valid_ad_date($date)) throw new DomainException('Enter the statement date.');
    $last = last_completed_reconciliation($accountId);
    if ($last && $date <= $last['statement_date']) throw new DomainException('The statement date must be after the last reconciliation (' . bs_date($last['statement_date']) . ' B.S.).');
    $raw = str_replace([',', ' '], '', trim($balanceRaw));
    $negative = str_starts_with($raw, '-');
    $cents = to_cents(ltrim($raw, '-'));
    if ($raw === '' || $cents === null) throw new DomainException('Enter the closing balance shown on the bank statement.');
    db()->prepare('INSERT INTO acc_bank_reconciliations (account_id, statement_date, statement_balance, created_by) VALUES (?,?,?,?)')
        ->execute([$accountId, $date, cents_to_decimal($negative ? -$cents : $cents), $userId]);
    $id = (int)db()->lastInsertId();
    audit('start_reconciliation', 'account', $accountId, bs_date($date));
    return $id;
}

/** Saves the ticked lines; completes the reconciliation when asked and the difference is zero. */
function save_reconciliation(array $recon, array $lineIds, bool $complete, int $userId): array {
    $eligible = array_column(unreconciled_lines((int)$recon['account_id'], $recon['statement_date'], (int)$recon['id']), null, 'line_id');
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), fn($id) => isset($eligible[$id]))));
    $ticked = 0;
    foreach ($lineIds as $id) $ticked += decimal_to_cents($eligible[$id]['debit']) - decimal_to_cents($eligible[$id]['credit']);
    $cleared = cleared_balance((int)$recon['account_id']) + $ticked;
    $difference = decimal_to_cents($recon['statement_balance']) - $cleared;
    if ($complete && $difference !== 0) throw new DomainException('Difference must be zero to finish — it is ' . money_cents($difference, true) . '. Tick the missing items, or record bank charges/interest first.');

    in_transaction(function (PDO $pdo) use ($recon, $lineIds, $complete) {
        $pdo->prepare('DELETE FROM acc_reconciliation_lines WHERE reconciliation_id = ?')->execute([$recon['id']]);
        $ins = $pdo->prepare('INSERT INTO acc_reconciliation_lines (reconciliation_id, journal_line_id) VALUES (?, ?)');
        foreach ($lineIds as $id) $ins->execute([$recon['id'], $id]);
        if ($complete) $pdo->prepare("UPDATE acc_bank_reconciliations SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$recon['id']]);
    });
    if ($complete) audit('complete_reconciliation', 'account', (int)$recon['account_id'], bs_date($recon['statement_date']));
    return ['cleared' => $cleared, 'difference' => $difference];
}

/** Admin: reopen/remove the latest reconciliation of an account (completed or in progress). */
function undo_reconciliation(int $accountId, int $userId): void {
    $stmt = db()->prepare('SELECT * FROM acc_bank_reconciliations WHERE account_id = ? ORDER BY statement_date DESC, id DESC LIMIT 1');
    $stmt->execute([$accountId]);
    $r = $stmt->fetch();
    if (!$r) throw new DomainException('Nothing to undo.');
    db()->prepare('DELETE FROM acc_bank_reconciliations WHERE id = ?')->execute([$r['id']]);
    audit('undo_reconciliation', 'account', $accountId, bs_date($r['statement_date']));
}
