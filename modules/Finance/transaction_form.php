<?php
/**
 * Module 13 - Manual transaction entry.
 * For anything with no other module to log it in — rent, salaries, a
 * cash sale of something small. Automatically-posted transactions
 * (harvest labour, invoices, spoilage) are never edited from here; they
 * carry a source_type other than 'manual' and stay as a record of what
 * actually happened in the module that created them.
 */
$pageTitle = 'Add a transaction';
$activeNav = 'finance';
require_once __DIR__ . '/../../includes/header.php';
require_role(['admin', 'owner', 'accountant', 'manager']);

$categories = db()->query('SELECT category_id, category_name, category_type FROM finance_categories ORDER BY category_type, category_name')->fetchAll();
$cycles = db()->query(
    "SELECT cc.cycle_id, c.crop_name, cc.season_label FROM crop_cycles cc JOIN crops c ON c.crop_id = cc.crop_id ORDER BY c.crop_name"
)->fetchAll();
$fields = db()->query('SELECT field_id, field_name FROM fields ORDER BY field_name')->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>Add a transaction</h1>
    <p class="lede">For income or expenses with no other module to record them in.
      Tag it to a crop cycle if it belongs to one, so it counts toward that crop's profitability.</p>
  </div>
  <a class="btn" href="transactions.php">Cancel</a>
</div>

<form method="post" action="transaction_save.php" class="panel">
  <?= csrf_field() ?>
  <div class="body">
    <div class="grid-2">
      <div class="field">
        <label for="txn_type">Type</label>
        <select name="txn_type" id="txn_type" required onchange="filterCategories()">
          <option value="expense">Expense</option>
          <option value="income">Income</option>
        </select>
      </div>
      <div class="field">
        <label for="txn_date">Date</label>
        <input type="date" name="txn_date" id="txn_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="category_id">Category</label>
        <select name="category_id" id="category_id" required>
<?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['category_id'] ?>" data-type="<?= e($c['category_type']) ?>">
            <?= e($c['category_name']) ?> (<?= e($c['category_type']) ?>)
          </option>
<?php endforeach; ?>
        </select>
        <p class="hint">No matching category? <a href="categories.php">Add one first</a>.</p>
      </div>
      <div class="field">
        <label for="amount">Amount</label>
        <input type="number" name="amount" id="amount" step="0.01" min="0.01" required>
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="cycle_id">Crop cycle (optional)</label>
        <select name="cycle_id" id="cycle_id">
          <option value="">Not tied to a crop</option>
<?php foreach ($cycles as $c): ?>
          <option value="<?= (int) $c['cycle_id'] ?>"><?= e($c['crop_name']) ?> (<?= e($c['season_label'] ?? '—') ?>)</option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="field_id">Field (optional)</label>
        <select name="field_id" id="field_id">
          <option value="">Not tied to a field</option>
<?php foreach ($fields as $f): ?>
          <option value="<?= (int) $f['field_id'] ?>"><?= e($f['field_name']) ?></option>
<?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid-2">
      <div class="field">
        <label for="payment_method">Payment method</label>
        <select name="payment_method" id="payment_method">
          <option value="cash">Cash</option>
          <option value="bank_transfer">Bank transfer</option>
          <option value="mobile_money">Mobile money</option>
          <option value="cheque">Cheque</option>
          <option value="credit">Credit</option>
        </select>
      </div>
      <div class="field">
        <label for="description">Description</label>
        <input type="text" name="description" id="description" maxlength="255" required>
      </div>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save transaction</button>
      <a class="btn" href="transactions.php">Cancel</a>
    </div>
  </div>
</form>

<script>
// Only show categories matching the chosen type, so an expense can't
// accidentally get filed under an income category or vice versa.
function filterCategories() {
  const type = document.getElementById('txn_type').value;
  const select = document.getElementById('category_id');
  let firstVisible = null;
  for (const opt of select.options) {
    const show = opt.dataset.type === type;
    opt.hidden = !show;
    if (show && firstVisible === null) firstVisible = opt.value;
  }
  if (select.options[select.selectedIndex]?.hidden) {
    select.value = firstVisible;
  }
}
filterCategories();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
