<?php
/**
 * Module 14 - Goods receipt form.
 * Shows every line with an editable "received" quantity defaulted to
 * what's still outstanding, same partial-fulfilment pattern as Module 12's
 * dispatch form.
 */
$activeNav = 'purchases';
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'storekeeper']);

$poId = get_int('po_id');
if (!$poId) {
    redirect('orders.php');
}

$stmt = db()->prepare('SELECT po_no, status FROM purchase_orders WHERE po_id = :id');
$stmt->execute([':id' => $poId]);
$po = $stmt->fetch();

if (!$po) {
    flash('error', 'That purchase order does not exist.');
    redirect('orders.php');
}

if (!in_array($po['status'], ['approved', 'partially_received'], true)) {
    flash('error', 'Only approved orders can receive goods.');
    redirect("order_view.php?id=$poId");
}

$pageTitle = 'Receive ' . $po['po_no'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT poi.*, u.unit_code, (poi.qty_ordered - poi.qty_received) AS remaining
       FROM purchase_order_items poi
       JOIN units u ON u.unit_id = poi.unit_id
      WHERE poi.po_id = :id AND (poi.qty_ordered - poi.qty_received) > 0
   ORDER BY poi.po_item_id"
);
$stmt->execute([':id' => $poId]);
$items = $stmt->fetchAll();

if (!$items) {
    flash('info', 'Everything on this order has already been received.');
    redirect("order_view.php?id=$poId");
}
?>

<div class="page-head">
  <div>
    <h1>Receive <?= e($po['po_no']) ?></h1>
    <p class="lede">Set the quantity actually delivered for each line. Leave a line at 0 if it wasn't in this delivery.</p>
  </div>
  <a class="btn" href="order_view.php?id=<?= $poId ?>">Cancel</a>
</div>

<form method="post" action="receipt_save.php" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="po_id" value="<?= $poId ?>">
  <div class="body">
    <div class="grid-2">
      <div class="field">
        <label for="received_on">Date received</label>
        <input type="date" name="received_on" id="received_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label for="condition_on_arrival">Condition on arrival</label>
        <select name="condition_on_arrival" id="condition_on_arrival">
          <option value="good">Good</option>
          <option value="damaged">Damaged</option>
          <option value="partial">Partial delivery</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label for="delivery_note_no">Delivery note number</label>
        <input type="text" name="delivery_note_no" id="delivery_note_no" maxlength="60">
      </div>
      <div class="field">
        <label for="remarks">Remarks</label>
        <input type="text" name="remarks" id="remarks" maxlength="255">
      </div>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Item</th><th>Category</th><th class="num">Remaining</th><th class="num">Received qty</th><th>Batch / expiry</th></tr>
      </thead>
      <tbody>
<?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['item_name']) ?></td>
          <td><?= e(ucfirst($item['item_category'])) ?></td>
          <td class="num"><?= fmt_qty($item['remaining']) ?> <?= e($item['unit_code']) ?></td>
          <td class="num">
            <input type="hidden" name="items[<?= (int) $item['po_item_id'] ?>][po_item_id]" value="<?= (int) $item['po_item_id'] ?>">
            <input type="number" name="items[<?= (int) $item['po_item_id'] ?>][qty]"
                   step="0.001" min="0" max="<?= e((string) $item['remaining']) ?>"
                   value="<?= e((string) $item['remaining']) ?>" style="width:110px">
          </td>
          <td>
            <input type="text" name="items[<?= (int) $item['po_item_id'] ?>][batch_no]" placeholder="Batch no." maxlength="60" style="width:100px">
            <input type="date" name="items[<?= (int) $item['po_item_id'] ?>][expiry_date]" style="width:130px">
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="body form-actions">
    <button class="btn btn-primary" type="submit">Confirm receipt</button>
    <a class="btn" href="order_view.php?id=<?= $poId ?>">Cancel</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
