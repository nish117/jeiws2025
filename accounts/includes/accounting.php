<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Double-entry ledger service. Every module records money through
 * save_journal_entry() so the balancing rules below are enforced in one
 * place:
 *   - an entry has ≥ 2 lines and total debits = total credits
 *   - each line is a debit OR a credit, never both, never zero
 *   - lines post only to active, non-group accounts
 *   - drafts are editable and ignored by balances; posted entries are
 *     immutable and can only be voided (the record stays for audit)
 *   - voucher numbers are assigned at posting, per fiscal year, gap-free
 * Amounts are handled as integer paisa internally to avoid float drift.
 */

require_once ACC_ROOT . '/../lib/NepaliDate.php';

const ACC_ACCOUNT_TYPES = [
    'asset'     => ['label' => 'Assets',      'normal' => 'debit'],
    'liability' => ['label' => 'Liabilities', 'normal' => 'credit'],
    'equity'    => ['label' => 'Equity',      'normal' => 'credit'],
    'income'    => ['label' => 'Income',      'normal' => 'credit'],
    'expense'   => ['label' => 'Expenses',    'normal' => 'debit'],
];

const ACC_VOUCHER_PREFIXES = [
    'manual'          => 'JV',
    'payroll'         => 'PR',   // salary accrual
    'payroll_payment' => 'PP',   // salary paid out
    'sales_invoice'   => 'SV',   // sales invoice posted (receivable + output VAT)
    'sales_receipt'   => 'RV',   // money received against a sales invoice
    'purchase_bill'   => 'PB',   // supplier bill posted (payable + input VAT)
    'bill_payment'    => 'BP',   // money paid to a supplier
    'advance_adjustment' => 'AJ', // held advance applied to an invoice
    'transfer'        => 'CT',   // contra: between cash / bank accounts
    'cash_bank'       => 'CB',   // other money in / out of a cash or bank account (charges, interest …)
    'opening_balance' => 'OB',   // opening balance of a new cash / bank account
    'vat_settlement'  => 'VT',   // monthly VAT return: output set off against input, net paid to IRD
    'tds_deposit'     => 'TD',   // TDS deposited to IRD
    'income_tax'      => 'IT',   // advance income-tax instalment paid
    'tax_provision'   => 'TP',   // year-end income-tax provision
];

// ── Money ────────────────────────────────────────────────────────────
/** Parses user input like "1,23,456.5" into paisa. Returns null if not a valid non-negative amount. */
function to_cents(mixed $value): ?int {
    $value = str_replace([',', ' '], '', trim((string)$value));
    if ($value === '') return 0;
    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $value)) return null;
    [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
    return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
}

function cents_to_decimal(int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** DECIMAL string from MySQL → paisa, without passing through float. */
function decimal_to_cents(string|int|float|null $decimal): int {
    $decimal = (string)($decimal ?? '0');
    $negative = str_starts_with($decimal, '-');
    [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, '');
    $cents = (int)$whole * 100 + (int)str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$cents : $cents;
}

function money_cents(int $cents, bool $withSymbol = false): string {
    return money(cents_to_decimal($cents), $withSymbol);
}

/** Balance in the account's natural direction (positive = normal side). */
function natural_balance(string $type, int $debitCents, int $creditCents): int {
    return ACC_ACCOUNT_TYPES[$type]['normal'] === 'debit' ? $debitCents - $creditCents : $creditCents - $debitCents;
}

// ── Dates ────────────────────────────────────────────────────────────
function valid_ad_date(string $date): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date && NepaliDate::adToBs($date) !== null;
}

/** AD 'YYYY-MM-DD' → BS 'YYYY-MM-DD' (empty string if out of range). */
function bs_date(?string $adDate): string {
    return $adDate ? (NepaliDate::adToBs(substr($adDate, 0, 10)) ?? '') : '';
}

/** Nepal fiscal year (Shrawan–Asar) containing an AD date, e.g. "2082/83". */
function fiscal_year_for(string $adDate): string {
    $bs = NepaliDate::adToBs($adDate);
    if (!$bs) throw new InvalidArgumentException('Date outside the supported Nepali calendar range.');
    [$year, $month] = array_map('intval', explode('-', $bs));
    $start = $month >= 4 ? $year : $year - 1; // month 4 = Shrawan
    return sprintf('%d/%02d', $start, ($start + 1) % 100);
}

/** [startAD, endAD] of a fiscal year label like "2082/83". */
function fiscal_year_range(string $label): array {
    $start = (int)substr($label, 0, 4);
    $end = NepaliDate::bsToAd($start + 1, 4, 1);
    return [NepaliDate::bsToAd($start, 4, 1), date('Y-m-d', strtotime($end . ' -1 day'))];
}

// ── Accounts ─────────────────────────────────────────────────────────
function system_account_id(string $key): int {
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = db()->prepare('SELECT id FROM acc_accounts WHERE system_key = ?');
        $stmt->execute([$key]);
        $cache[$key] = (int)$stmt->fetchColumn() ?: throw new RuntimeException("System account '{$key}' is missing.");
    }
    return $cache[$key];
}

/**
 * All accounts in chart order (depth-first by code) with a `depth` key.
 * @return array<int, array> keyed by id
 */
function account_tree(bool $activeOnly = false): array {
    $rows = db()->query('SELECT * FROM acc_accounts ORDER BY code')->fetchAll();
    $children = [];
    foreach ($rows as $row) $children[(int)$row['parent_id']][] = $row;

    $ordered = [];
    $walk = function (int $parentId, int $depth) use (&$walk, &$children, &$ordered, $activeOnly) {
        foreach ($children[$parentId] ?? [] as $row) {
            if ($activeOnly && !$row['is_active']) continue;
            $row['depth'] = $depth;
            $ordered[(int)$row['id']] = $row;
            $walk((int)$row['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $ordered;
}

/** Leaf accounts that can take postings, grouped by type — for <select> menus. */
function postable_accounts_by_type(): array {
    $grouped = [];
    foreach (account_tree(true) as $account) {
        if (!$account['is_group']) $grouped[$account['type']][] = $account;
    }
    return $grouped;
}

/**
 * Projects for cost tagging, keyed by id: [id => [id, code, name, status]].
 * Open projects first.
 */
function project_options(): array {
    static $projects = null;
    if ($projects === null) {
        $rows = db()->query(
            "SELECT id, code, name, status FROM acc_projects
             ORDER BY status IN ('completed','cancelled'), name"
        )->fetchAll();
        $projects = array_column($rows, null, 'id');
    }
    return $projects;
}

/**
 * Projects to offer in entry forms: completed and cancelled ones are left out (as on the Projects
 * page), except any in $keepIds — e.g. the project an existing record is already tagged with.
 */
function active_project_options(array $keepIds = []): array {
    $keep = array_flip(array_map('intval', array_filter($keepIds)));
    return array_filter(project_options(), fn($p) => !in_array($p['status'], ['completed', 'cancelled'], true) || isset($keep[(int)$p['id']]));
}

/** Clients and suppliers, keyed by id: [id => [id, type, name, is_active]]. */
function contact_options(): array {
    static $contacts = null;
    if ($contacts === null) {
        $rows = db()->query('SELECT id, type, name, is_active FROM acc_contacts ORDER BY type, name')->fetchAll();
        $contacts = array_column($rows, null, 'id');
    }
    return $contacts;
}

function project_label(?int $id): string {
    $p = $id ? (project_options()[$id] ?? null) : null;
    return $p ? $p['code'] . ' · ' . $p['name'] : '';
}

function contact_label(?int $id): string {
    return $id ? (contact_options()[$id]['name'] ?? '') : '';
}

/**
 * Posted debit/credit totals per account, in paisa.
 * @return array<int, array{debit:int, credit:int}>
 */
function account_totals(?string $fromDate = null, ?string $toDate = null): array {
    $sql = "SELECT l.account_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit
            FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
            WHERE e.status = 'posted'";
    $params = [];
    if ($fromDate) { $sql .= ' AND e.entry_date >= ?'; $params[] = $fromDate; }
    if ($toDate)   { $sql .= ' AND e.entry_date <= ?'; $params[] = $toDate; }
    $stmt = db()->prepare($sql . ' GROUP BY l.account_id');
    $stmt->execute($params);

    $totals = [];
    foreach ($stmt->fetchAll() as $row) {
        $totals[(int)$row['account_id']] = ['debit' => decimal_to_cents($row['debit']), 'credit' => decimal_to_cents($row['credit'])];
    }
    return $totals;
}

// ── Journal entries ──────────────────────────────────────────────────
/** Runs $fn inside a transaction, reusing one that's already open. */
function in_transaction(callable $fn): mixed {
    $pdo = db();
    if ($pdo->inTransaction()) return $fn($pdo);
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Validates entry input. $data = [entry_date, narration, reference, lines => [[account_id, debit, credit, description, project_id, contact_id], …]].
 * Fully blank lines are ignored.
 * @return array{errors: string[], lines: array, total: int}
 */
function validate_journal(array $data): array {
    $errors = [];
    $date = trim((string)($data['entry_date'] ?? ''));
    if (!valid_ad_date($date)) $errors[] = 'Enter a valid date.';
    $narration = trim((string)($data['narration'] ?? ''));
    if ($narration === '') $errors[] = 'Narration is required.';
    if (mb_strlen($narration) > 500) $errors[] = 'Narration must be 500 characters or fewer.';
    if (mb_strlen(trim((string)($data['reference'] ?? ''))) > 100) $errors[] = 'Reference must be 100 characters or fewer.';

    $accounts = account_tree();
    $projects = project_options();
    $contacts = contact_options();
    $clean = [];
    $totalDebit = $totalCredit = 0;

    foreach (array_values($data['lines'] ?? []) as $i => $line) {
        $n = $i + 1;
        $accountId   = (int)($line['account_id'] ?? 0);
        $rawDebit    = trim((string)($line['debit'] ?? ''));
        $rawCredit   = trim((string)($line['credit'] ?? ''));
        $description = trim((string)($line['description'] ?? ''));
        $projectId   = (int)($line['project_id'] ?? 0);
        $contactId   = (int)($line['contact_id'] ?? 0);
        if (!$accountId && $rawDebit === '' && $rawCredit === '' && $description === '') continue; // blank row

        $debit = to_cents($rawDebit);
        $credit = to_cents($rawCredit);
        $account = $accounts[$accountId] ?? null;

        if (!$account)                               $errors[] = "Line {$n}: choose an account.";
        elseif ($account['is_group'])                $errors[] = "Line {$n}: \"{$account['name']}\" is a group — choose an account under it.";
        elseif (!$account['is_active'])              $errors[] = "Line {$n}: \"{$account['name']}\" is inactive.";
        if ($debit === null || $credit === null)     $errors[] = "Line {$n}: amounts must be numbers with up to 2 decimals.";
        elseif ($debit > 0 && $credit > 0)           $errors[] = "Line {$n}: enter either a debit or a credit, not both.";
        elseif ($debit === 0 && $credit === 0)       $errors[] = "Line {$n}: enter a debit or credit amount.";
        if ($projectId && !isset($projects[$projectId])) $errors[] = "Line {$n}: unknown project.";
        if ($contactId && !isset($contacts[$contactId])) $errors[] = "Line {$n}: unknown client or supplier.";
        if (mb_strlen($description) > 255)           $errors[] = "Line {$n}: description is too long.";

        $totalDebit  += (int)$debit;
        $totalCredit += (int)$credit;
        $clean[] = [
            'account_id'  => $accountId,
            'debit'       => (int)$debit,
            'credit'      => (int)$credit,
            'description' => $description === '' ? null : $description,
            'project_id'  => $projectId ?: null,
            'contact_id'  => $contactId ?: null,
        ];
    }

    if (count($clean) < 2) $errors[] = 'An entry needs at least two lines.';
    if (!$errors && $totalDebit !== $totalCredit) {
        $errors[] = 'Debits (' . money_cents($totalDebit) . ') and credits (' . money_cents($totalCredit) . ') must be equal. Difference: ' . money_cents(abs($totalDebit - $totalCredit)) . '.';
    }

    return ['errors' => $errors, 'lines' => $clean, 'total' => $totalDebit];
}

/**
 * Creates or updates a journal entry, optionally posting it.
 * Only drafts can be updated.
 * @return array{id: ?int, errors: string[]}
 */
function save_journal_entry(array $data, int $userId, ?int $entryId = null, bool $post = false, string $source = 'manual', ?int $sourceId = null): array {
    $check = validate_journal($data);
    if ($check['errors']) return ['id' => $entryId, 'errors' => $check['errors']];

    $id = in_transaction(function (PDO $pdo) use ($data, $userId, $entryId, $post, $source, $sourceId, $check) {
        $header = [
            $data['entry_date'],
            fiscal_year_for($data['entry_date']),
            trim($data['narration']),
            trim((string)($data['reference'] ?? '')) ?: null,
            cents_to_decimal($check['total']),
        ];

        if ($entryId) {
            $existing = $pdo->prepare('SELECT status FROM acc_journal_entries WHERE id = ? FOR UPDATE');
            $existing->execute([$entryId]);
            if ($existing->fetchColumn() !== 'draft') throw new DomainException('Only draft entries can be edited.');
            $pdo->prepare('UPDATE acc_journal_entries SET entry_date = ?, fiscal_year = ?, narration = ?, reference = ?, total_amount = ? WHERE id = ?')
                ->execute([...$header, $entryId]);
            $pdo->prepare('DELETE FROM acc_journal_lines WHERE entry_id = ?')->execute([$entryId]);
        } else {
            $pdo->prepare('INSERT INTO acc_journal_entries (entry_date, fiscal_year, narration, reference, total_amount, source, source_id, created_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([...$header, $source, $sourceId, $userId]);
            $entryId = (int)$pdo->lastInsertId();
        }

        $insertLine = $pdo->prepare('INSERT INTO acc_journal_lines (entry_id, line_no, account_id, debit, credit, description, project_id, contact_id) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($check['lines'] as $i => $line) {
            $insertLine->execute([
                $entryId, $i + 1, $line['account_id'],
                cents_to_decimal($line['debit']), cents_to_decimal($line['credit']),
                $line['description'], $line['project_id'], $line['contact_id'],
            ]);
        }

        if ($post) post_journal_entry($entryId, $userId);
        return $entryId;
    });

    return ['id' => $id, 'errors' => []];
}

/** Posts a draft: re-checks balance from the stored lines and assigns the next voucher number. */
function post_journal_entry(int $entryId, int $userId): string {
    return in_transaction(function (PDO $pdo) use ($entryId, $userId) {
        $stmt = $pdo->prepare('SELECT status, fiscal_year, source FROM acc_journal_entries WHERE id = ? FOR UPDATE');
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch();
        if (!$entry) throw new DomainException('Entry not found.');
        if ($entry['status'] !== 'draft') throw new DomainException('Only draft entries can be posted.');

        $sums = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(debit),0) AS dr, COALESCE(SUM(credit),0) AS cr FROM acc_journal_lines WHERE entry_id = ?');
        $sums->execute([$entryId]);
        $s = $sums->fetch();
        if ($s['n'] < 2 || decimal_to_cents($s['dr']) !== decimal_to_cents($s['cr']) || decimal_to_cents($s['dr']) === 0) {
            throw new DomainException('Entry is not balanced and cannot be posted.');
        }

        $prefix = ACC_VOUCHER_PREFIXES[$entry['source']] ?? 'JV';
        $seq = next_sequence($prefix, $entry['fiscal_year']);
        $voucherNo = sprintf('%s/%s/%04d', $prefix, str_replace('/', '-', $entry['fiscal_year']), $seq); // JV/2082-83/0001

        $pdo->prepare("UPDATE acc_journal_entries SET status = 'posted', voucher_no = ?, posted_by = ?, posted_at = NOW() WHERE id = ?")
            ->execute([$voucherNo, $userId, $entryId]);
        audit('post_journal', 'journal_entry', $entryId, $voucherNo);
        return $voucherNo;
    });
}

/** $fromModule = true lets the owning module (e.g. payroll) void its own entries. */
function void_journal_entry(int $entryId, int $userId, string $reason, bool $fromModule = false): void {
    $reason = trim($reason);
    if ($reason === '') throw new DomainException('A reason is required to void an entry.');
    in_transaction(function (PDO $pdo) use ($entryId, $userId, $reason, $fromModule) {
        $stmt = $pdo->prepare('SELECT status, voucher_no, source FROM acc_journal_entries WHERE id = ? FOR UPDATE');
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch();
        if (!$entry || $entry['status'] !== 'posted') throw new DomainException('Only posted entries can be voided.');
        if ($entry['source'] !== 'manual' && !$fromModule) throw new DomainException('This entry was created by another module — void it from there.');
        $pdo->prepare("UPDATE acc_journal_entries SET status = 'void', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?")
            ->execute([$userId, mb_substr($reason, 0, 255), $entryId]);
        audit('void_journal', 'journal_entry', $entryId, $entry['voucher_no'] . ': ' . $reason);
    });
}

function delete_draft_entry(int $entryId): void {
    in_transaction(function (PDO $pdo) use ($entryId) {
        $stmt = $pdo->prepare('SELECT status, source FROM acc_journal_entries WHERE id = ? FOR UPDATE');
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch();
        if (!$entry || $entry['status'] !== 'draft') throw new DomainException('Only drafts can be deleted.');
        if ($entry['source'] !== 'manual') throw new DomainException('This draft belongs to another module.');
        $pdo->prepare('DELETE FROM acc_journal_entries WHERE id = ?')->execute([$entryId]);
        audit('delete_journal_draft', 'journal_entry', $entryId);
    });
}

/** Next value of a per-fiscal-year counter. Must run inside a transaction (row lock). */
function next_sequence(string $name, string $fiscalYear): int {
    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO acc_sequences (name, fiscal_year, next_value) VALUES (?, ?, 1)')->execute([$name, $fiscalYear]);
    $stmt = $pdo->prepare('SELECT next_value FROM acc_sequences WHERE name = ? AND fiscal_year = ? FOR UPDATE');
    $stmt->execute([$name, $fiscalYear]);
    $value = (int)$stmt->fetchColumn();
    $pdo->prepare('UPDATE acc_sequences SET next_value = next_value + 1 WHERE name = ? AND fiscal_year = ?')->execute([$name, $fiscalYear]);
    return $value;
}

function can_edit_books(array $user): bool {
    return in_array($user['role'], ['admin', 'accountant'], true);
}

function status_badge(string $status): string {
    return match ($status) {
        'posted' => '<span class="badge text-bg-success">Posted</span>',
        'draft'  => '<span class="badge text-bg-warning">Draft</span>',
        'void'   => '<span class="badge text-bg-secondary">Void</span>',
        default  => '<span class="badge text-bg-light">' . e($status) . '</span>',
    };
}
