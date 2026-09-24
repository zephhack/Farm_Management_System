<?php
/**
 * Module 13 - The single ledger every module posts to.
 * Most rows here arrive automatically: harvest confirmation posts labour
 * (Module 11), invoicing posts sale income per crop cycle (Module 12),
 * spoilage posts a write-off (Module 15). This page is where those show
 * up alongside anything entered manually (rent, salaries, a cash expense
 * with no other module to log it in).
 */
$pageTitle = 'Income & expenses';
$activeNav = 'finance';
require_once __DIR__ . '/../../includes/header.php';

$typeFilter   = $_GET['type'] ?? '';
$categoryId   = get_int('category_id');
$cycleId      = get_int('cycle_id');
$sourceFilter = $_GET['source'] ?? '';
$fromDate     = $_GET['from'] ?? '';
$toDate       = $_GET['to'] ?? '';
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 25;

$where  = [];
$params = [];

if (in_array($typeFilter, ['income', 'expense'], true)) {
    $where[] = 'ft.txn_type = :type';
    $params[':type'] = $typeFilter;
}
if ($categoryId) {
    $where[] = 'ft.category_id = :category_id';
    $params[':category_id'] = $categoryId;
}
if ($cycleId) {
    $where[] = 'ft.cycle_id = :cycle_id';
    $params[':cycle_id'] = $cycleId;
}
if ($sourceFilter !== '') {
    $where[] = 'ft.source_type = :source';
    $params[':source'] = $sourceFilter;
}
if (valid_date($fromDate)) {
    $where[] = 'ft.txn_date >= :from';
    $params[':from'] = $fromDate;
}
if (valid_date($toDate)) {
    $where[] = 'ft.txn_date <= :to';
    $params[':to'] = $toDate;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalsStmt = db()->prepare(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN txn_type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN txn_type = 'expense' THEN amount ELSE 0 END), 0) AS expense
       FROM financial_transactions ft $whereSql"
);
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch();

$rowsFound  = (int) $totals['n'];
$totalPages = max(1, (int) ceil($rowsFound / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sql = "SELECT ft.*, fc.category_name, fc.category_type AS cat_type, c.crop_name, f.field_name
          FROM financial_transactions ft
          JOIN finance_categories fc ON fc.category_id = ft.category_id
     LEFT JOIN crop_cycles cc ON cc.cycle_id = ft.cycle_id
     LEFT JOIN crops c ON c.crop_id = cc.crop_id
     LEFT JOIN fields f ON f.field_id = ft.field_id
          $whereSql
      ORDER BY ft.txn_date DESC, ft.transaction_id DESC
         LIMIT :limit OFFSET :offset";

$stmt = db()->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll();

$categories = db()->query('SELECT category_id, category_name, category_type FROM finance_categories ORDER BY category_type, category_name')->fetchAll();
$cycles     = db()->query(
    "SELECT cc.cycle_id, c.crop_name, cc.season_label FROM crop_cycles cc JOIN crops c ON c.crop_id = cc.crop_id ORDER BY c.crop_name"
)->fetchAll();

$sourceLabels = [
    'manual' => 'Manual entry', 'invoice' => 'Invoice', 'customer_payment' => 'Customer payment',
    'purchase_order' => 'Purchase order', 'supplier_payment' => 'Supplier payment',
    'harvest_labour' => 'Harvest labour', 'spoilage' => 'Spoilage', 'loan' => 'Loan', 'loan_repayment' => 'Loan repayment',
];

function page_link(int $page): string
{
    return '?' . http_build_query(array_merge($_GET, ['page' => $page]));
}
?>

<div class="page-head">
  <div>
    <h1>Income &amp; expenses</h1>
    <p class="lede">Every transaction, whichever module posted it. Harvest labour, sale income,
      and spoilage write-offs land here automatically — this is also where you record anything
      that has no other module to log it in.</p>
  </div>
<?php if (user_can(['admin', 'owner', 'accountant', 'manager'])): ?>
  <a class="btn btn-primary" href="transaction_form.php">Add manual entry</a>
<?php endif; ?>
</div>

<dl class="stats">
  <div><dt>Transactions</dt><dd><?= number_format($rowsFound) ?></dd></div>
  <div><dt>Income</dt><dd><?= fmt_money($totals['income']) ?></dd></div>
  <div><dt>Expense</dt><dd><?= fmt_money($totals['expense']) ?></dd></div>
  <div><dt>Net</dt><dd><?= fmt_money($totals['income'] - $totals['expense']) ?></dd></div>
</dl>

<form class="panel" method="get">
  <div class="body filters">
    <div class="field">
      <label for="type">Type</label>
      <select name="type" id="type">
        <option value="">Both</option>
        <option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Income</option>
        <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Expense</option>
      </select>
    </div>
    <div class="field">
      <label for="category_id">Category</label>
      <select name="category_id" id="category_id">
        <option value="">All categories</option>
<?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['category_id'] ?>" <?= $categoryId === (int) $c['category_id'] ? 'selected' : '' ?>>
          <?= e($c['category_name']) ?> (<?= e($c['category_type']) ?>)
        </option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="cycle_id">Crop cycle</label>
      <select name="cycle_id" id="cycle_id">
        <option value="">All cycles</option>
<?php foreach ($cycles as $c): ?>
        <option value="<?= (int) $c['cycle_id'] ?>" <?= $cycleId === (int) $c['cycle_id'] ? 'selected' : '' ?>>
          <?= e($c['crop_name']) ?> (<?= e($c['season_label'] ?? '—') ?>)
        </option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="source">Source</label>
      <select name="source" id="source">
        <option value="">Any source</option>
<?php foreach ($sourceLabels as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $sourceFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="from">From</label>
      <input type="date" name="from" id="from" value="<?= e($fromDate) ?>">
    </div>
    <div class="field">
      <label for="to">To</label>
      <input type="date" name="to" id="to" value="<?= e($toDate) ?>">
    </div>
    <button class="btn" type="submit">Apply</button>
    <a class="btn" href="transactions.php">Clear</a>
  </div>
</form>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Date</th><th>Category</th><th>Crop cycle</th><th>Description</th>
          <th>Source</th><th class="num">Income</th><th class="num">Expense</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$transactions): ?>
        <tr><td colspan="7" class="empty">No transactions match these filters.</td></tr>
<?php endif; ?>
<?php foreach ($transactions as $t): ?>
        <tr>
          <td><?= e(fmt_date($t['txn_date'])) ?></td>
          <td><?= e($t['category_name']) ?></td>
          <td><?= e($t['crop_name'] ?? '—') ?></td>
          <td><?= e($t['description'] ?? '—') ?></td>
          <td><?= e($sourceLabels[$t['source_type']] ?? ucfirst($t['source_type'])) ?></td>
          <td class="num"><?= $t['txn_type'] === 'income' ? fmt_money($t['amount']) : '—' ?></td>
          <td class="num"><?= $t['txn_type'] === 'expense' ? fmt_money($t['amount']) : '—' ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="pager">
  <span class="count">Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($rowsFound) ?> transactions</span>
<?php if ($page > 1): ?>
  <a class="btn btn-sm" href="<?= e(page_link($page - 1)) ?>">Previous</a>
<?php endif; ?>
<?php if ($page < $totalPages): ?>
  <a class="btn btn-sm" href="<?= e(page_link($page + 1)) ?>">Next</a>
<?php endif; ?>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
