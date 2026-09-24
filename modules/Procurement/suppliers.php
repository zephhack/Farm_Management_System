<?php
/**
 * Module 14 - Supplier registration.
 * Rating is never set here — it's the rolling average kept correct by
 * the database trigger (trg_evaluation_after_insert) whenever a supplier
 * evaluation is recorded on a purchase order.
 */
$pageTitle = 'Suppliers';
$activeNav = 'suppliers';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$editId  = get_int('edit');
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager']);
    csrf_check();

    $id            = (int) post('supplier_id', 0);
    $name          = post('supplier_name');
    $category      = post('category', 'other');
    $contactPerson = post('contact_person') ?: null;
    $phone         = post('phone') ?: null;
    $email         = post('email') ?: null;
    $address       = post('address') ?: null;
    $tpin          = post('tpin') ?: null;
    $termsDays     = (int) post('payment_terms_days', 0);

    $validCategories = ['seeds', 'fertilizer', 'chemicals', 'feed', 'veterinary', 'fuel', 'equipment', 'packaging', 'services', 'other'];

    $errors = [];
    if ($name === '' || $name === null)              { $errors[] = 'Enter the supplier name.'; }
    if (!in_array($category, $validCategories, true)) { $errors[] = 'Choose a valid supplier category.'; }
    if ($termsDays < 0)                               { $errors[] = 'Payment terms cannot be negative.'; }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect($id ? "suppliers.php?edit=$id" : 'suppliers.php');
    }

    if ($id > 0) {
        db()->prepare(
            "UPDATE suppliers
                SET supplier_name = :name, category = :category, contact_person = :contact,
                    phone = :phone, email = :email, address = :address, tpin = :tpin,
                    payment_terms_days = :terms
              WHERE supplier_id = :id"
        )->execute([
            ':name' => $name, ':category' => $category, ':contact' => $contactPerson,
            ':phone' => $phone, ':email' => $email, ':address' => $address, ':tpin' => $tpin,
            ':terms' => $termsDays, ':id' => $id,
        ]);
        flash('success', "$name updated.");
    } else {
        $code = next_code('suppliers', 'supplier_code', 'SUP');
        db()->prepare(
            "INSERT INTO suppliers
               (supplier_code, supplier_name, category, contact_person, phone, email, address, tpin, payment_terms_days)
             VALUES
               (:code, :name, :category, :contact, :phone, :email, :address, :tpin, :terms)"
        )->execute([
            ':code' => $code, ':name' => $name, ':category' => $category, ':contact' => $contactPerson,
            ':phone' => $phone, ':email' => $email, ':address' => $address, ':tpin' => $tpin, ':terms' => $termsDays,
        ]);
        flash('success', "$name added as $code.");
    }

    redirect('suppliers.php');
}

require_once __DIR__ . '/../../includes/header.php';

if ($editId) {
    $stmt = db()->prepare('SELECT * FROM suppliers WHERE supplier_id = :id');
    $stmt->execute([':id' => $editId]);
    $editing = $stmt->fetch();
}

$suppliers = db()->query(
    "SELECT s.*,
            COALESCE((SELECT SUM(po.total_amount - po.amount_paid) FROM purchase_orders po
                       WHERE po.supplier_id = s.supplier_id AND po.status IN ('approved','partially_received','received')
                         AND po.amount_paid < po.total_amount), 0) AS owed
       FROM suppliers s
      ORDER BY s.supplier_name"
)->fetchAll();

$canManage = user_can(['admin', 'owner', 'manager']);
$categories = ['seeds', 'fertilizer', 'chemicals', 'feed', 'veterinary', 'fuel', 'equipment', 'packaging', 'services', 'other'];
?>

<div class="page-head">
  <div>
    <h1>Suppliers</h1>
    <p class="lede">Who inputs and equipment are bought from. Rating updates automatically
      whenever a purchase order is evaluated after receipt.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Code</th><th>Name</th><th>Category</th><th>Contact</th>
          <th class="num">Rating</th><th class="num">We owe</th><th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$suppliers): ?>
        <tr><td colspan="7" class="empty">No suppliers yet. Add one below.</td></tr>
<?php endif; ?>
<?php foreach ($suppliers as $s): ?>
        <tr>
          <td><?= e($s['supplier_code']) ?></td>
          <td><?= e($s['supplier_name']) ?></td>
          <td><?= e(ucfirst($s['category'])) ?></td>
          <td><?= e($s['contact_person'] ?? '—') ?><?= $s['phone'] ? ' · ' . e($s['phone']) : '' ?></td>
          <td class="num"><?= $s['rating'] > 0 ? fmt_qty($s['rating'], 1) . ' / 5' : '—' ?></td>
          <td class="num"><?= fmt_money($s['owed']) ?></td>
          <td class="actions">
<?php if ($canManage): ?>
            <a class="btn btn-sm" href="?edit=<?= (int) $s['supplier_id'] ?>">Edit</a>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header><?= $editing ? 'Edit ' . e($editing['supplier_name']) : 'Add a supplier' ?></header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
<?php if ($editing): ?>
      <input type="hidden" name="supplier_id" value="<?= (int) $editing['supplier_id'] ?>">
<?php endif; ?>
      <div class="grid-2">
        <div class="field">
          <label for="supplier_name">Name</label>
          <input type="text" name="supplier_name" id="supplier_name" required maxlength="150"
                 value="<?= e($editing['supplier_name'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="category">Category</label>
          <select name="category" id="category">
<?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>" <?= ($editing['category'] ?? '') === $cat ? 'selected' : '' ?>>
              <?= e(ucfirst($cat)) ?>
            </option>
<?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="contact_person">Contact person</label>
          <input type="text" name="contact_person" id="contact_person" maxlength="120"
                 value="<?= e($editing['contact_person'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="phone">Phone</label>
          <input type="text" name="phone" id="phone" maxlength="30"
                 value="<?= e($editing['phone'] ?? '') ?>">
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="email">Email</label>
          <input type="email" name="email" id="email" maxlength="150"
                 value="<?= e($editing['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="tpin">Tax ID (TPIN)</label>
          <input type="text" name="tpin" id="tpin" maxlength="30"
                 value="<?= e($editing['tpin'] ?? '') ?>">
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="address">Address</label>
          <input type="text" name="address" id="address" maxlength="255"
                 value="<?= e($editing['address'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="payment_terms_days">Payment terms (days)</label>
          <input type="number" name="payment_terms_days" id="payment_terms_days" step="1" min="0"
                 value="<?= e($editing['payment_terms_days'] ?? '0') ?>">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add supplier' ?></button>
<?php if ($editing): ?>
        <a class="btn" href="suppliers.php">Cancel</a>
<?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
