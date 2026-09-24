<?php
/**
 * Module 12 - Sales order list.
 */
$pageTitle = 'Sales orders';
$activeNav = 'sales';
require_once __DIR__ . '/../../includes/header.php';

$statusFilter = $_GET['status'] ?? '';
$where  = [];
$params = [];

if (in_array($statusFilter, ['draft', 'confirmed', 'partially_delivered', 'delivered', 'invoiced', 'cancelled'], true)) {
    $where[] = 'so.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare(
    "SELECT so.order_id, so.order_no, so.order_date, so.total_amount, so.status,
            cu.customer_name,
            COALESCE((SELECT SUM(qty) FROM sales_order_items WHERE order_id = so.order_id), 0) AS qty_ordered,
            COALESCE((SELECT SUM(qty_delivered) FROM sales_order_items WHERE order_id = so.order_id), 0) AS qty_delivered
       FROM sales_orders so
       JOIN customers cu ON cu.customer_id = so.customer_id
       $whereSql
   ORDER BY so.order_date DESC, so.order_id DESC"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$totals = db()->prepare(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS value
       FROM sales_orders so $whereSql"
);
$totals->execute($params);
$totals = $totals->fetch();

$canCreate = user_can(['admin', 'owner', 'manager']);
?>

<div class="page-head">
  <div>
    <h1>Sales orders</h1>
    <p class="lede">What buyers have committed to. Confirm an order once its items are set,
      dispatch it to move produce out of store, then invoice it.</p>
  </div>
<?php if ($canCreate): ?>
  <a class="btn btn-primary" href="order_form.php">New sales order</a>
<?php endif; ?>
</div>

<dl class="stats">
  <div><dt>Orders</dt><dd><?= number_format($totals['n']) ?></dd></div>
  <div><dt>Total value</dt><dd><?= fmt_money($totals['value']) ?></dd></div>
</dl>

<div class="panel">
  <div class="body filters">
    <div class="field">
      <label for="status">Status</label>
      <select name="status" id="status" onchange="location.href='?status='+this.value">
        <option value="">Any status</option>
<?php foreach (['draft', 'confirmed', 'partially_delivered', 'delivered', 'invoiced', 'cancelled'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
          <?= e(ucfirst(str_replace('_', ' ', $s))) ?>
        </option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Order</th><th>Date</th><th>Customer</th>
          <th class="num">Ordered</th><th class="num">Delivered</th>
          <th class="num">Value</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$orders): ?>
        <tr><td colspan="8" class="empty">No sales orders match. Create one to start selling stock.</td></tr>
<?php endif; ?>
<?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int) $o['order_id'] ?>"><?= e($o['order_no']) ?></a></td>
          <td><?= e(fmt_date($o['order_date'])) ?></td>
          <td><?= e($o['customer_name']) ?></td>
          <td class="num"><?= fmt_qty($o['qty_ordered']) ?></td>
          <td class="num"><?= fmt_qty($o['qty_delivered']) ?></td>
          <td class="num"><?= fmt_money($o['total_amount']) ?></td>
          <td><span class="status is-<?= in_array($o['status'], ['delivered', 'invoiced'], true) ? 'confirmed' : ($o['status'] === 'cancelled' ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst(str_replace('_', ' ', $o['status']))) ?></span></td>
          <td class="actions"><a class="btn btn-sm" href="order_view.php?id=<?= (int) $o['order_id'] ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
