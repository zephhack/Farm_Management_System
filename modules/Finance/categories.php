<?php
/**
 * Module 13 - Finance categories (chart of income/expense accounts).
 * Most transactions are posted automatically by Modules 11, 12, 14 and 15;
 * this page exists so the categories those postings rely on can be
 * extended, and so manual entries have somewhere to be classified.
 */
$pageTitle = 'Finance categories';
$activeNav = 'categories';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'accountant']);
    csrf_check();

    $name      = post('category_name');
    $type      = post('category_type', 'expense');
    $costClass = post('cost_class', 'none');
    $parentId  = post('parent_id') ? (int) post('parent_id') : null;

    $errors = [];
    if ($name === '' || $name === null) {
        $errors[] = 'Enter a category name.';
    }
    if (!in_array($type, ['income', 'expense'], true)) {
        $errors[] = 'Choose a valid category type.';
    }
    if (!in_array($costClass, ['direct', 'indirect', 'capital', 'none'], true)) {
        $errors[] = 'Choose a valid cost class.';
    }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
    } else {
        try {
            db()->prepare(
                'INSERT INTO finance_categories (category_name, category_type, cost_class, parent_id)
                 VALUES (:name, :type, :cost_class, :parent_id)'
            )->execute([
                ':name' => $name, ':type' => $type, ':cost_class' => $costClass, ':parent_id' => $parentId,
            ]);
            flash('success', "$name added.");
        } catch (Throwable $e) {
            flash('error', 'A category with that name and type already exists.');
        }
    }

    redirect('categories.php');
}

require_once __DIR__ . '/../../includes/header.php';

$categories = db()->query(
    "SELECT fc.*, p.category_name AS parent_name,
            COALESCE((SELECT COUNT(*) FROM financial_transactions WHERE category_id = fc.category_id), 0) AS txn_count
       FROM finance_categories fc
  LEFT JOIN finance_categories p ON p.category_id = fc.parent_id
      ORDER BY fc.category_type, fc.category_name"
)->fetchAll();

$parents   = db()->query('SELECT category_id, category_name, category_type FROM finance_categories ORDER BY category_name')->fetchAll();
$canManage = user_can(['admin', 'owner', 'accountant']);
?>

<div class="page-head">
  <div>
    <h1>Finance categories</h1>
    <p class="lede">The chart of accounts every transaction is classified under.
      "Cost class" marks which expenses count toward cost per hectare and cost per kilogram.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Category</th><th>Type</th><th>Cost class</th><th>Parent</th><th class="num">Transactions</th></tr>
      </thead>
      <tbody>
<?php foreach ($categories as $c): ?>
        <tr>
          <td><?= e($c['category_name']) ?></td>
          <td><?= e(ucfirst($c['category_type'])) ?></td>
          <td><?= e(ucfirst($c['cost_class'])) ?></td>
          <td><?= e($c['parent_name'] ?? '—') ?></td>
          <td class="num"><?= (int) $c['txn_count'] ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>Add a category</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid-2">
        <div class="field">
          <label for="category_name">Name</label>
          <input type="text" name="category_name" id="category_name" required maxlength="100">
        </div>
        <div class="field">
          <label for="category_type">Type</label>
          <select name="category_type" id="category_type">
            <option value="expense">Expense</option>
            <option value="income">Income</option>
          </select>
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="cost_class">Cost class</label>
          <select name="cost_class" id="cost_class">
            <option value="direct">Direct (counts toward cost per ha / kg)</option>
            <option value="indirect">Indirect (overhead)</option>
            <option value="capital">Capital</option>
            <option value="none">None (income categories)</option>
          </select>
        </div>
        <div class="field">
          <label for="parent_id">Parent category</label>
          <select name="parent_id" id="parent_id">
            <option value="">None</option>
<?php foreach ($parents as $p): ?>
            <option value="<?= (int) $p['category_id'] ?>"><?= e($p['category_name']) ?> (<?= e($p['category_type']) ?>)</option>
<?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Add category</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
