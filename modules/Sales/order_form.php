<?php
/**
 * Module 12 - Create a sales order header. Items are added afterwards
 * on order_view.php, the same "create shell, then add lines" pattern
 * used for harvest losses and labour.
 */
$pageTitle = 'New sales order';
$activeNav = 'sales';
require_once __DIR__ . '/../../includes/header.php';
require_role(['admin', 'owner', 'manager']);

$customers    = db()->query('SELECT customer_id, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name')->fetchAll();
$destinations = db()->query('SELECT destination_id, market_name FROM market_destinations ORDER BY market_name')->fetchAll();
$contracts    = db()->query(
    "SELECT contract_id, contract_no, customer_id FROM contracts WHERE status = 'active' ORDER BY contract_no"
)->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>New sales order</h1>
    <p class="lede">Start with the buyer and date. You'll add the produce and quantities on the next screen.</p>
  </div>
  <a class="btn" href="orders.php">Cancel</a>
</div>

<form method="post" action="order_save.php" class="panel">
  <?= csrf_field() ?>
  <div class="body">
    <div class="grid-2">
      <div class="field">
        <label for="customer_id">Customer</label>
        <select name="customer_id" id="customer_id" required>
          <option value="">Select a customer</option>
<?php foreach ($customers as $c): ?>
          <option value="<?= (int) $c['customer_id'] ?>"><?= e($c['customer_name']) ?></option>
<?php endforeach; ?>
        </select>
<?php if (!$customers): ?>
        <p class="hint">No customers yet. <a href="customers.php">Add one first</a>.</p>
<?php endif; ?>
      </div>

      <div class="field">
        <label for="order_date">Order date</label>
        <input type="date" name="order_date" id="order_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="destination_id">Market destination</label>
        <select name="destination_id" id="destination_id">
          <option value="">Not specified</option>
<?php foreach ($destinations as $d): ?>
          <option value="<?= (int) $d['destination_id'] ?>"><?= e($d['market_name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="contract_id">Against contract</label>
        <select name="contract_id" id="contract_id">
          <option value="">No contract</option>
<?php foreach ($contracts as $c): ?>
          <option value="<?= (int) $c['contract_id'] ?>" data-customer="<?= (int) $c['customer_id'] ?>">
            <?= e($c['contract_no']) ?>
          </option>
<?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="required_date">Required by</label>
        <input type="date" name="required_date" id="required_date">
      </div>
      <div class="field">
        <label for="transport_cost">Transport cost</label>
        <input type="number" name="transport_cost" id="transport_cost" step="0.01" min="0" value="0">
      </div>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Create order</button>
      <a class="btn" href="orders.php">Cancel</a>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
