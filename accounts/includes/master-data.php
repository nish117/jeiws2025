<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Clients, suppliers and projects: labels, validation, persistence and the
 * ledger-derived figures shown on their pages.
 *
 * Party balances only count the "control" accounts a party can owe or be
 * owed on (receivables, payables, retention, advances), and only lines
 * tagged with that party — so tag the party on the receivable/payable
 * line of a voucher. Project figures count every line tagged to the
 * project on income and expense accounts.
 */

const CONTACT_TYPES = [
    'client'   => ['singular' => 'Client',   'plural' => 'Clients',   'icon' => 'fa-user-tie',    'page' => 'clients.php'],
    'supplier' => ['singular' => 'Supplier', 'plural' => 'Suppliers', 'icon' => 'fa-truck-field', 'page' => 'suppliers.php'],
];

const SUPPLIER_TYPES = [
    'material'      => 'Material supplier',
    'subcontractor' => 'Subcontractor',
    'service'       => 'Service provider',
    'equipment'     => 'Equipment / machinery hire',
    'other'         => 'Other',
];

// Nepal Income Tax Act TDS categories (rates applied by the TDS module).
const TDS_CATEGORIES = [
    'none'     => 'No TDS',
    'contract' => 'Contract / subcontract payment (1.5%)',
    'service'  => 'Service fee (1.5% VAT-registered, 15% otherwise)',
    'rent'     => 'House / equipment rent (10%)',
    'other'    => 'Other',
];

const PROJECT_STATUSES = [
    'planned'   => ['label' => 'Planned',   'badge' => 'text-bg-info'],
    'ongoing'   => ['label' => 'Ongoing',   'badge' => 'text-bg-primary'],
    'on_hold'   => ['label' => 'On hold',   'badge' => 'text-bg-warning'],
    'completed' => ['label' => 'Completed', 'badge' => 'text-bg-success'],
    'cancelled' => ['label' => 'Cancelled', 'badge' => 'text-bg-secondary'],
];

// Control accounts that make up what a party owes / is owed, by system_key.
// Sign: +1 means a debit balance increases the amount the client owes us
// (or reduces what we owe the supplier).
const PARTY_CONTROL_ACCOUNTS = ['accounts_receivable', 'retention_receivable', 'client_advances', 'accounts_payable', 'retention_payable', 'supplier_advances'];

function project_status_badge(string $status): string {
    $s = PROJECT_STATUSES[$status] ?? ['label' => $status, 'badge' => 'text-bg-light'];
    return '<span class="badge ' . $s['badge'] . '">' . e($s['label']) . '</span>';
}

function party_control_account_ids(): array {
    static $ids = null;
    return $ids ??= array_map('system_account_id', PARTY_CONTROL_ACCOUNTS);
}

/**
 * Raw (Dr − Cr) balance per contact on the control accounts, in paisa.
 * For a client, positive = they owe us. For a supplier, negative = we owe them.
 * @return array<int,int>
 */
function contact_balances(?array $contactIds = null): array {
    $ids = party_control_account_ids();
    $sql = "SELECT l.contact_id, SUM(l.debit) AS dr, SUM(l.credit) AS cr
            FROM acc_journal_lines l JOIN acc_journal_entries e ON e.id = l.entry_id
            WHERE e.status = 'posted' AND l.contact_id IS NOT NULL
              AND l.account_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params = $ids;
    if ($contactIds !== null) {
        if (!$contactIds) return [];
        $sql .= ' AND l.contact_id IN (' . implode(',', array_fill(0, count($contactIds), '?')) . ')';
        $params = [...$params, ...$contactIds];
    }
    $stmt = db()->prepare($sql . ' GROUP BY l.contact_id');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[(int)$r['contact_id']] = decimal_to_cents($r['dr']) - decimal_to_cents($r['cr']);
    return $out;
}

/**
 * Revenue and cost per project from posted lines, in paisa.
 * @return array<int, array{revenue:int, cost:int}>
 */
function project_financials(?int $projectId = null): array {
    $sql = "SELECT l.project_id, a.type, SUM(l.debit) AS dr, SUM(l.credit) AS cr
            FROM acc_journal_lines l
            JOIN acc_journal_entries e ON e.id = l.entry_id
            JOIN acc_accounts a ON a.id = l.account_id
            WHERE e.status = 'posted' AND l.project_id IS NOT NULL AND a.type IN ('income','expense')";
    $params = [];
    if ($projectId) { $sql .= ' AND l.project_id = ?'; $params[] = $projectId; }
    $stmt = db()->prepare($sql . ' GROUP BY l.project_id, a.type');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $id = (int)$r['project_id'];
        $out[$id] ??= ['revenue' => 0, 'cost' => 0];
        $dr = decimal_to_cents($r['dr']);
        $cr = decimal_to_cents($r['cr']);
        if ($r['type'] === 'income') $out[$id]['revenue'] += $cr - $dr;
        else $out[$id]['cost'] += $dr - $cr;
    }
    return $out;
}

function contact_in_use(int $id): bool {
    $stmt = db()->prepare('SELECT (SELECT COUNT(*) FROM acc_journal_lines WHERE contact_id = ?) + (SELECT COUNT(*) FROM acc_projects WHERE client_id = ?)');
    $stmt->execute([$id, $id]);
    return (int)$stmt->fetchColumn() > 0;
}

function project_in_use(int $id): bool {
    $stmt = db()->prepare('SELECT 1 FROM acc_journal_lines WHERE project_id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

// ── Contacts ─────────────────────────────────────────────────────────
/** @return array{errors: string[], values: array} */
function validate_contact(array $in, string $type, ?int $selfId = null): array {
    $v = [
        'name'           => trim((string)($in['name'] ?? '')),
        'contact_person' => trim((string)($in['contact_person'] ?? '')),
        'pan_number'     => preg_replace('/\s+/', '', (string)($in['pan_number'] ?? '')),
        'vat_registered' => !empty($in['vat_registered']) ? 1 : 0,
        'phone'          => trim((string)($in['phone'] ?? '')),
        'email'          => strtolower(trim((string)($in['email'] ?? ''))),
        'address'        => trim((string)($in['address'] ?? '')),
        'supplier_type'  => $type === 'supplier' ? (string)($in['supplier_type'] ?? '') : null,
        'tds_category'   => (string)($in['tds_category'] ?? 'none'),
        'notes'          => trim((string)($in['notes'] ?? '')),
        'is_active'      => $selfId === null || !empty($in['is_active']) ? 1 : 0,
    ];
    $errors = [];
    if ($v['name'] === '' || mb_strlen($v['name']) > 150) $errors[] = 'Name is required (max 150 characters).';
    if ($v['pan_number'] !== '' && !preg_match('/^\d{9}$/', $v['pan_number'])) $errors[] = 'PAN / VAT number must be exactly 9 digits.';
    if ($v['vat_registered'] && $v['pan_number'] === '') $errors[] = 'Enter the VAT number (9 digits) for a VAT-registered party.';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if (mb_strlen($v['contact_person']) > 100 || mb_strlen($v['phone']) > 40 || mb_strlen($v['address']) > 255) $errors[] = 'One of the fields is too long.';
    if ($type === 'supplier' && !isset(SUPPLIER_TYPES[$v['supplier_type']])) $errors[] = 'Choose a supplier type.';
    if (!isset(TDS_CATEGORIES[$v['tds_category']])) $errors[] = 'Choose a TDS category.';

    if (!$errors && $v['pan_number'] !== '') {
        $dupe = db()->prepare('SELECT name FROM acc_contacts WHERE type = ? AND pan_number = ? AND id <> ?');
        $dupe->execute([$type, $v['pan_number'], $selfId ?? 0]);
        if ($existing = $dupe->fetchColumn()) $errors[] = "PAN {$v['pan_number']} is already used by {$existing}.";
    }
    foreach (['contact_person', 'pan_number', 'phone', 'email', 'address', 'notes'] as $k) if ($v[$k] === '') $v[$k] = null;
    return ['errors' => $errors, 'values' => $v];
}

function save_contact(array $values, string $type, int $userId, ?int $id = null): int {
    $cols = ['name', 'contact_person', 'pan_number', 'vat_registered', 'phone', 'email', 'address', 'supplier_type', 'tds_category', 'notes', 'is_active'];
    $params = array_map(fn($c) => $values[$c], $cols);
    if ($id) {
        db()->prepare('UPDATE acc_contacts SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ' WHERE id = ? AND type = ?')
            ->execute([...$params, $id, $type]);
        audit('update_' . $type, 'contact', $id, $values['name']);
        return $id;
    }
    db()->prepare('INSERT INTO acc_contacts (type, ' . implode(', ', $cols) . ', created_by) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)')
        ->execute([$type, ...$params, $userId]);
    $newId = (int)db()->lastInsertId();
    audit('create_' . $type, 'contact', $newId, $values['name']);
    return $newId;
}

// ── Projects ─────────────────────────────────────────────────────────
function next_project_code(): string {
    $max = (int)db()->query("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) FROM acc_projects WHERE code REGEXP '^PRJ-[0-9]+$'")->fetchColumn();
    return sprintf('PRJ-%03d', $max + 1);
}

/** @return array{errors: string[], values: array} */
function validate_project(array $in, ?int $selfId = null): array {
    $v = [
        'code'              => strtoupper(trim((string)($in['code'] ?? ''))),
        'name'              => trim((string)($in['name'] ?? '')),
        'client_id'         => (int)($in['client_id'] ?? 0) ?: null,
        'site_project_id'   => trim((string)($in['site_project_id'] ?? '')) ?: null,
        'location'          => trim((string)($in['location'] ?? '')) ?: null,
        'status'            => (string)($in['status'] ?? 'ongoing'),
        'contract_value'    => to_cents($in['contract_value'] ?? ''),
        'budget'            => to_cents($in['budget'] ?? ''),
        'retention_percent' => trim((string)($in['retention_percent'] ?? '')) === '' ? '0' : trim((string)$in['retention_percent']),
        'start_date'        => trim((string)($in['start_date'] ?? '')) ?: null,
        'end_date'          => trim((string)($in['end_date'] ?? '')) ?: null,
        'description'       => trim((string)($in['description'] ?? '')) ?: null,
    ];
    $errors = [];
    if ($v['code'] === '') $v['code'] = next_project_code();
    if (!preg_match('/^[A-Z0-9][A-Z0-9.\-\/]{0,19}$/', $v['code'])) $errors[] = 'Code must be up to 20 letters, digits, dots, dashes or slashes.';
    if ($v['name'] === '' || mb_strlen($v['name']) > 150) $errors[] = 'Project name is required (max 150 characters).';
    if (!isset(PROJECT_STATUSES[$v['status']])) $errors[] = 'Choose a status.';
    if ($v['contract_value'] === null) $errors[] = 'Contract value must be an amount with up to 2 decimals.';
    if ($v['budget'] === null) $errors[] = 'Budget must be an amount with up to 2 decimals.';
    if (!is_numeric($v['retention_percent']) || $v['retention_percent'] < 0 || $v['retention_percent'] > 100) $errors[] = 'Retention must be between 0 and 100%.';
    foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $k => $label) {
        if ($v[$k] !== null && !valid_ad_date($v[$k])) $errors[] = "{$label} is not a valid date.";
    }
    if (!$errors && $v['start_date'] && $v['end_date'] && $v['end_date'] < $v['start_date']) $errors[] = 'End date is before the start date.';
    if ($v['client_id']) {
        $c = contact_options()[$v['client_id']] ?? null;
        if (!$c || $c['type'] !== 'client') $errors[] = 'Choose a valid client.';
    }
    if ($v['site_project_id'] && !isset(site_project_options()[$v['site_project_id']])) $errors[] = 'Unknown site project.';

    $dupe = db()->prepare('SELECT 1 FROM acc_projects WHERE code = ? AND id <> ?');
    $dupe->execute([$v['code'], $selfId ?? 0]);
    if ($dupe->fetchColumn()) $errors[] = "Project code {$v['code']} is already in use.";
    if ($v['site_project_id']) {
        $dupe = db()->prepare('SELECT name FROM acc_projects WHERE site_project_id = ? AND id <> ?');
        $dupe->execute([$v['site_project_id'], $selfId ?? 0]);
        if ($other = $dupe->fetchColumn()) $errors[] = "That site project is already linked to \"{$other}\".";
    }
    return ['errors' => $errors, 'values' => $v];
}

function save_project(array $v, int $userId, ?int $id = null): int {
    $cols = ['code', 'name', 'client_id', 'site_project_id', 'location', 'status', 'contract_value', 'budget', 'retention_percent', 'start_date', 'end_date', 'description'];
    $params = array_map(fn($c) => in_array($c, ['contract_value', 'budget'], true) ? cents_to_decimal($v[$c]) : $v[$c], $cols);
    if ($id) {
        db()->prepare('UPDATE acc_projects SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", $cols)) . ' WHERE id = ?')->execute([...$params, $id]);
        audit('update_project', 'project', $id, "{$v['code']} {$v['name']}");
        return $id;
    }
    db()->prepare('INSERT INTO acc_projects (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)')
        ->execute([...$params, $userId]);
    $newId = (int)db()->lastInsertId();
    audit('create_project', 'project', $newId, "{$v['code']} {$v['name']}");
    return $newId;
}

/** Site-ops projects (attendance/materials portal), keyed by id. */
function site_project_options(): array {
    static $rows = null;
    if ($rows === null) {
        try {
            $rows = array_column(db()->query('SELECT id, title, is_active FROM projects ORDER BY title')->fetchAll(), null, 'id');
        } catch (PDOException) {
            $rows = []; // site-ops tables not installed on this database
        }
    }
    return $rows;
}

/** Site projects not yet linked to an accounts project. */
function unlinked_site_projects(): array {
    $linked = db()->query('SELECT site_project_id FROM acc_projects WHERE site_project_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    return array_diff_key(site_project_options(), array_flip($linked));
}
