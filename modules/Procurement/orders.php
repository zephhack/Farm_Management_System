<?php
/**
 * Module 14 - Purchase order list.
 */
$pageTitle = 'Purchase orders';
$activeNav = 'purchases';
require_once __DIR__ . '/../../includes/header.php';

$statusFilter = $_GET['status'] ?? '';
$where  = [];
$params = [];

if (in_array($statusFilter, ['draft', 'pending_approval', 'approved', 'rejected', 'partially_received', 'received', 'cancelled'], true)) {
    $where[] = 'po.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare(
    "SELECT po.po_id, po.po_no, po.order_date, po.total_amount, po.amount_paid, po.status, s.supplier_name
       FROM purchase_orders po
       JOIN suppliers s ON s.supplier_id = po.supplier_id
       $whereSql
   ORDER BY po.order_date DESC, po.po_id DESC"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$totals = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS value FROM purchase_orders po $whereSql");
$totals->execute($params);
$totals = $totals->fetch();

$canCreate = user_can(['admin', 'owner', 'manager']);
?>

<div class="page-head">
  <div>
    <h1>Purchase orders</h1>
    <p class="lede">What's been ordered from suppliers. Submit for approval once items are set,
      receive goods as they arrive, then settle the supplier.</p>
  </div>
<?php if ($canCreate): ?>
  <a class="btn btn-primary" href="order_form.php">New purchase order</a>
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
<?php foreach (['draft', 'pending_approval', 'approved', 'rejected', 'partially_received', 'received', 'cancelled'] as $s): ?>
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
        <tr><th>PO</th><th>Date</th><th>Supplier</th><th class="num">Total</th><th class="num">Paid</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
<?php if (!$orders): ?>
        <tr><td colspan="7" class="empty">No purchase orders match. Create one to start procuring inputs.</td></tr>
<?php endif; ?>
<?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int) $o['po_id'] ?>"><?= e($o['po_no']) ?></a></td>
          <td><?= e(fmt_date($o['order_date'])) ?></td>
          <td><?= e($o['supplier_name']) ?></td>
          <td class="num"><?= fmt_money($o['total_amount']) ?></td>
          <td class="num"><?= fmt_money($o['amount_paid']) ?></td>
          <td><span class="status is-<?= in_array($o['status'], ['received'], true) ? 'confirmed' : (in_array($o['status'], ['rejected', 'cancelled'], true) ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst(str_replace('_', ' ', $o['status']))) ?></span></td>
          <td class="actions"><a class="btn btn-sm" href="order_view.php?id=<?= (int) $o['po_id'] ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
