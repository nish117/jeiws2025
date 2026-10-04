<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!can_edit_books($user)) { http_response_code(403); exit('Read-only access.'); }

$id = (int)($_GET['id'] ?? 0);
$contact = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM acc_contacts WHERE id = ?');
    $stmt->execute([$id]);
    $contact = $stmt->fetch() ?: null;
    if (!$contact) { flash('warning', 'Not found.'); redirect('clients.php'); }
}
$type = $contact['type'] ?? ((string)($_GET['type'] ?? '') === 'supplier' ? 'supplier' : 'client');
$meta = CONTACT_TYPES[$type];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete' && $contact) {
        if (contact_in_use($id)) {
            $errors[] = "This {$type} has transactions or projects, so it can't be deleted. Untick \"Active\" to hide it instead.";
        } else {
            db()->prepare('DELETE FROM acc_contacts WHERE id = ?')->execute([$id]);
            audit('delete_' . $type, 'contact', $id, $contact['name']);
            flash('success', "{$contact['name']} deleted.");
            redirect($meta['page']);
        }
    } else {
        $check = validate_contact($_POST, $type, $contact ? $id : null);
        $errors = $check['errors'];
        if (!$errors) {
            $savedId = save_contact($check['values'], $type, (int)$user['id'], $contact ? $id : null);
            flash('success', $contact ? 'Changes saved.' : "{$meta['singular']} {$check['values']['name']} added.");
            redirect(!empty($_POST['add_another']) ? "contact-edit.php?type={$type}" : "contact.php?id={$savedId}");
        }
    }
}
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST + ['vat_registered' => null, 'is_active' => null] : ($contact ?? ['is_active' => 1, 'tds_category' => 'none']);

$pageTitle   = $contact ? "Edit {$contact['name']}" : 'New ' . strtolower($meta['singular']);
$activeNav   = $type . 's';
$breadcrumbs = [['label' => $meta['plural'], 'href' => $meta['page']]];
if ($contact) $breadcrumbs[] = ['label' => $contact['name'], 'href' => 'contact.php?id=' . $id];
$breadcrumbs[] = ['label' => $contact ? 'Edit' : 'New'];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach ?></div>
<?php endif ?>

<form method="post" class="acc-card" style="max-width:920px" novalidate>
    <?= csrf_field() ?>
    <div class="acc-card-head"><h2><?= $meta['singular'] ?> details</h2></div>
    <div class="acc-card-body">
        <div class="row g-3">
            <div class="col-md-7">
                <label class="form-label fw-semibold" for="name"><?= $type === 'client' ? 'Client / organisation name' : 'Supplier / firm name' ?> <span class="text-danger">*</span></label>
                <input class="form-control" id="name" name="name" value="<?= e($f['name'] ?? '') ?>" required maxlength="150" autofocus>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-semibold" for="contact_person">Contact person</label>
                <input class="form-control" id="contact_person" name="contact_person" value="<?= e($f['contact_person'] ?? '') ?>" maxlength="100">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="pan_number">PAN / VAT number</label>
                <input class="form-control" id="pan_number" name="pan_number" value="<?= e($f['pan_number'] ?? '') ?>" inputmode="numeric" maxlength="9" pattern="\d{9}" placeholder="9 digits">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="vat_registered" name="vat_registered" value="1" <?= !empty($f['vat_registered']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="vat_registered">VAT registered</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="phone">Phone</label>
                <input class="form-control" id="phone" name="phone" value="<?= e($f['phone'] ?? '') ?>" inputmode="tel" maxlength="40">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($f['email'] ?? '') ?>" maxlength="150">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="address">Address</label>
                <input class="form-control" id="address" name="address" value="<?= e($f['address'] ?? '') ?>" maxlength="255" placeholder="Municipality, ward, district">
            </div>

            <?php if ($type === 'supplier'): ?>
                <div class="col-md-5">
                    <label class="form-label fw-semibold" for="supplier_type">Supplier type <span class="text-danger">*</span></label>
                    <select class="form-select" id="supplier_type" name="supplier_type" required>
                        <option value="">Choose…</option>
                        <?php foreach (SUPPLIER_TYPES as $k => $label): ?>
                            <option value="<?= $k ?>" <?= ($f['supplier_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            <?php endif ?>
            <div class="<?= $type === 'supplier' ? 'col-md-7' : 'col-md-7' ?>">
                <label class="form-label fw-semibold" for="tds_category">TDS category</label>
                <select class="form-select" id="tds_category" name="tds_category">
                    <?php foreach (TDS_CATEGORIES as $k => $label): ?>
                        <option value="<?= $k ?>" <?= ($f['tds_category'] ?? 'none') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <div class="form-text">
                    <?= $type === 'client'
                        ? 'Tax your client withholds from your bills (usually 1.5% on contract payments). Tracked as TDS receivable.'
                        : 'Tax you must withhold when paying this supplier. Rates are applied by the TDS module.' ?>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="notes">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"><?= e($f['notes'] ?? '') ?></textarea>
            </div>
            <?php if ($contact): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= !empty($f['is_active']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                        <div class="form-text">Inactive <?= strtolower($meta['plural']) ?> are hidden from lists and dropdowns but keep their history.</div>
                    </div>
                </div>
            <?php endif ?>
        </div>
    </div>
    <div class="border-top px-4 py-3 d-flex flex-wrap justify-content-between gap-2">
        <div>
            <?php if ($contact): ?>
                <button type="submit" name="action" value="delete" class="btn btn-outline-danger" formnovalidate onclick="return confirm('Delete <?= e(addslashes($contact['name'])) ?>? This only works if they have no transactions or projects.')">Delete</button>
            <?php else: ?>
                <a href="<?= $meta['page'] ?>" class="btn btn-link text-body-secondary">Cancel</a>
            <?php endif ?>
        </div>
        <div class="d-flex gap-2">
            <?php if (!$contact): ?><button type="submit" name="add_another" value="1" class="btn btn-outline-secondary">Save &amp; add another</button><?php endif ?>
            <button type="submit" class="btn btn-primary"><?= $contact ? 'Save changes' : 'Save ' . strtolower($meta['singular']) ?></button>
        </div>
    </div>
</form>

<?php
if ($type === 'supplier') {
    // Suggest the usual TDS category from the supplier type until the user picks one themselves.
    $inlineScript = '(function () {
        const type = document.getElementById("supplier_type"), tds = document.getElementById("tds_category");
        const suggest = { material: "none", subcontractor: "contract", service: "service", equipment: "rent", other: "none" };
        let touched = ' . ($contact ? 'true' : 'false') . ';
        tds.addEventListener("change", () => { touched = true; });
        type.addEventListener("change", () => { if (!touched && suggest[type.value]) tds.value = suggest[type.value]; });
    })();';
}
require __DIR__ . '/includes/layout-bottom.php';
