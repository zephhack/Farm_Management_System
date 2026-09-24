<?php
/**
 * Module 12/15 - Dispatch form.
 * Shows every undelivered line on the order with an editable quantity,
 * defaulted to the full remaining amount, so a partial dispatch is just
 * reducing a number rather than a separate workflow.
 */
$activeNav = 'sales';
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

$orderId = get_int('order_id');
if (!$orderId) {
    redirect('orders.php');
}

$stmt = db()->prepare(
    "SELECT so.order_no, so.status, so.destination_id, md.market_name
       FROM sales_orders so
  LEFT JOIN market_destinations md ON md.destination_id = so.destination_id
      WHERE so.order_id = :id"
);
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'That order does not exist.');
    redirect('orders.php');
}

if (!in_array($order['status'], ['confirmed', 'partially_delivered'], true)) {
    flash('error', 'Only confirmed orders can be dispatched.');
    redirect("order_view.php?id=$orderId");
}

$pageTitle = 'Dispatch ' . $order['order_no'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT soi.item_id, soi.batch_id, soi.qty, soi.qty_delivered,
            (soi.qty - soi.qty_delivered) AS remaining,
            c.crop_name, pb.batch_code, pb.current_qty, pb.facility_id, sf.facility_name, u.unit_code
       FROM sales_order_items soi
       JOIN crops c ON c.crop_id = soi.crop_id
       JOIN units u ON u.unit_id = soi.unit_id
  LEFT JOIN produce_batches pb ON pb.batch_id = soi.batch_id
  LEFT JOIN storage_facilities sf ON sf.facility_id = pb.facility_id
      WHERE soi.order_id = :id
        AND (soi.qty - soi.qty_delivered) > 0
   ORDER BY soi.item_id"
);
$stmt->execute([':id' => $orderId]);
$items = $stmt->fetchAll();

if (!$items) {
    flash('info', 'Everything on this order has already been dispatched.');
    redirect("order_view.php?id=$orderId");
}
?>

<div class="page-head">
  <div>
    <h1>Dispatch <?= e($order['order_no']) ?></h1>
    <p class="lede">Set the quantity actually leaving the store for each line.
      Leave a line at 0 to dispatch it later.</p>
  </div>
  <a class="btn" href="order_view.php?id=<?= $orderId ?>">Cancel</a>
</div>

<form method="post" action="dispatch_save.php" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="order_id" value="<?= $orderId ?>">
  <div class="body">
    <div class="grid-2">
      <div class="field">
        <label for="dispatch_date">Dispatch date</label>
        <input type="date" name="dispatch_date" id="dispatch_date" required
               max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label for="vehicle_reg">Vehicle registration</label>
        <input type="text" name="vehicle_reg" id="vehicle_reg" maxlength="30">
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label for="driver_name">Driver</label>
        <input type="text" name="driver_name" id="driver_name" maxlength="120">
      </div>
      <div class="field">
        <label for="received_by">Received by (buyer's side)</label>
        <input type="text" name="received_by" id="received_by" maxlength="120">
      </div>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Batch</th><th>Crop</th><th>Store</th><th class="num">Remaining</th><th class="num">Dispatch qty</th></tr>
      </thead>
      <tbody>
<?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['batch_code'] ?? '—') ?></td>
          <td><?= e($item['crop_name']) ?></td>
          <td><?= e($item['facility_name'] ?? '—') ?></td>
          <td class="num"><?= fmt_qty($item['remaining']) ?> <?= e($item['unit_code']) ?></td>
          <td class="num">
            <input type="hidden" name="items[<?= (int) $item['item_id'] ?>][item_id]" value="<?= (int) $item['item_id'] ?>">
            <input type="number" name="items[<?= (int) $item['item_id'] ?>][qty]"
                   step="0.001" min="0" max="<?= e((string) min($item['remaining'], $item['current_qty'])) ?>"
                   value="<?= e((string) min($item['remaining'], $item['current_qty'])) ?>" style="width:120px">
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="body form-actions">
    <button class="btn btn-primary" type="submit">Confirm dispatch</button>
    <a class="btn" href="order_view.php?id=<?= $orderId ?>">Cancel</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
