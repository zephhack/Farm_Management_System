<?php
/**
 * Module 14 - Create a purchase order header. Items are added afterwards
 * on order_view.php, same "create shell, then add lines" pattern used
 * throughout this app.
 */
$pageTitle = 'New purchase order';
$activeNav = 'purchases';
require_once __DIR__ . '/../../includes/header.php';
require_role(['admin', 'owner', 'manager']);

$suppliers  = db()->query('SELECT supplier_id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name')->fetchAll();
$quotations = db()->query(
    "SELECT quotation_id, quotation_no, supplier_id FROM quotations WHERE status = 'selected' ORDER BY quotation_no"
)->fetchAll();
$farms = db()->query('SELECT farm_id, farm_name FROM farms ORDER BY farm_name')->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>New purchase order</h1>
    <p class="lede">Start with the supplier. You'll add what's being bought on the next screen.</p>
  </div>
  <a class="btn" href="orders.php">Cancel</a>
</div>

<form method="post" action="order_save.php" class="panel">
  <?= csrf_field() ?>
  <div class="body">
    <div class="grid-2">
      <div class="field">
        <label for="supplier_id">Supplier</label>
        <select name="supplier_id" id="supplier_id" required>
          <option value="">Select a supplier</option>
<?php foreach ($suppliers as $s): ?>
          <option value="<?= (int) $s['supplier_id'] ?>"><?= e($s['supplier_name']) ?></option>
<?php endforeach; ?>
        </select>
<?php if (!$suppliers): ?>
        <p class="hint">No suppliers yet. <a href="suppliers.php">Add one first</a>.</p>
<?php endif; ?>
      </div>
      <div class="field">
        <label for="order_date">Order date</label>
        <input type="date" name="order_date" id="order_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="quotation_id">Against quotation</label>
        <select name="quotation_id" id="quotation_id">
          <option value="">No quotation</option>
<?php foreach ($quotations as $q): ?>
          <option value="<?= (int) $q['quotation_id'] ?>" data-supplier="<?= (int) $q['supplier_id'] ?>">
            <?= e($q['quotation_no']) ?>
          </option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="expected_date">Expected delivery</label>
        <input type="date" name="expected_date" id="expected_date">
      </div>
    </div>

    <div class="field">
      <label for="farm_id">Delivering to</label>
      <select name="farm_id" id="farm_id">
<?php foreach ($farms as $f): ?>
        <option value="<?= (int) $f['farm_id'] ?>"><?= e($f['farm_name']) ?></option>
<?php endforeach; ?>
      </select>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Create purchase order</button>
      <a class="btn" href="orders.php">Cancel</a>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
