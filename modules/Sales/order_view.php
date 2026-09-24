<?php
/**
 * Module 12 - Sales order detail.
 * Draft: add/remove items, then confirm.
 * Confirmed / partially delivered: dispatch remaining quantities.
 * Delivered or confirmed: generate one invoice.
 */
$activeNav = 'sales';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$orderId = get_int('id');
if (!$orderId) {
    redirect('orders.php');
}

$stmt = db()->prepare(
    "SELECT so.*, cu.customer_name, cu.payment_terms_days, md.market_name, co.contract_no
       FROM sales_orders so
       JOIN customers cu ON cu.customer_id = so.customer_id
  LEFT JOIN market_destinations md ON md.destination_id = so.destination_id
  LEFT JOIN contracts co ON co.contract_id = so.contract_id
      WHERE so.order_id = :id"
);
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'That order does not exist.');
    redirect('orders.php');
}

$pageTitle = $order['order_no'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT soi.*, c.crop_name, pb.batch_code, u.unit_code,
            (soi.qty - soi.qty_delivered) AS remaining
       FROM sales_order_items soi
       JOIN crops c ON c.crop_id = soi.crop_id
       JOIN units u ON u.unit_id = soi.unit_id
  LEFT JOIN produce_batches pb ON pb.batch_id = soi.batch_id
      WHERE soi.order_id = :id
   ORDER BY soi.item_id"
);
$stmt->execute([':id' => $orderId]);
$items = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT d.*, GROUP_CONCAT(pb.batch_code SEPARATOR ', ') AS batches
       FROM dispatches d
  LEFT JOIN dispatch_items di ON di.dispatch_id = d.dispatch_id
  LEFT JOIN produce_batches pb ON pb.batch_id = di.batch_id
      WHERE d.order_id = :id
   GROUP BY d.dispatch_id
   ORDER BY d.dispatch_date DESC"
);
$stmt->execute([':id' => $orderId]);
$dispatches = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM invoices WHERE order_id = :id');
$stmt->execute([':id' => $orderId]);
$invoice = $stmt->fetch();

/* Batches with stock, for the "add item" picker. */
$batches = db()->query(
    "SELECT pb.batch_id, pb.batch_code, pb.current_qty, c.crop_name, pb.grade, u.unit_code, u.unit_id
       FROM produce_batches pb
       JOIN crops c ON c.crop_id = pb.crop_id
       JOIN units u ON u.unit_id = pb.unit_id
      WHERE pb.current_qty > 0
   ORDER BY c.crop_name, pb.batch_code"
)->fetchAll();

$isDraft      = $order['status'] === 'draft';
$canDispatch  = in_array($order['status'], ['confirmed', 'partially_delivered'], true);
$canInvoice   = in_array($order['status'], ['confirmed', 'partially_delivered', 'delivered'], true) && !$invoice;
$canManage    = user_can(['admin', 'owner', 'manager']);
$hasRemaining = false;
foreach ($items as $item) {
    if ((float) $item['remaining'] > 0.0001) {
        $hasRemaining = true;
    }
}
?>

<div class="page-head">
  <div>
    <h1><?= e($order['order_no']) ?></h1>
    <p class="lede">
      <?= e($order['customer_name']) ?> · ordered <?= e(fmt_date($order['order_date'])) ?>
<?php if ($order['market_name']): ?>
      · bound for <?= e($order['market_name']) ?>
<?php endif; ?>
<?php if ($order['contract_no']): ?>
      · under contract <?= e($order['contract_no']) ?>
<?php endif; ?>
      <span class="status is-<?= in_array($order['status'], ['delivered', 'invoiced'], true) ? 'confirmed' : ($order['status'] === 'cancelled' ? 'cancelled' : 'draft') ?>">
        <?= e(ucfirst(str_replace('_', ' ', $order['status']))) ?>
      </span>
    </p>
  </div>
  <div>
<?php if ($isDraft && $canManage): ?>
    <form method="post" action="order_confirm.php" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= $orderId ?>">
      <button class="btn btn-primary" type="submit"
              onclick="return confirm('Confirm this order? Items cannot be changed afterwards.')">Confirm order</button>
    </form>
<?php endif; ?>
<?php if (in_array($order['status'], ['draft', 'confirmed'], true) && $canManage): ?>
    <form method="post" action="order_cancel.php" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= $orderId ?>">
      <button class="btn btn-danger" type="submit" onclick="return confirm('Cancel this order?')">Cancel</button>
    </form>
<?php endif; ?>
    <a class="btn" href="orders.php">Back to list</a>
  </div>
</div>

<dl class="stats">
  <div><dt>Subtotal</dt><dd><?= fmt_money($order['subtotal']) ?></dd></div>
  <div><dt>Transport</dt><dd><?= fmt_money($order['transport_cost']) ?></dd></div>
  <div><dt>Total</dt><dd><?= fmt_money($order['total_amount']) ?></dd></div>
  <div><dt>Invoice</dt><dd><?= $invoice ? e($invoice['invoice_no']) : '—' ?></dd></div>
</dl>

<!-- ---------------- Items ---------------- -->
<div class="panel">
  <header>Produce ordered</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Batch</th><th>Crop</th><th>Grade</th>
          <th class="num">Qty</th><th class="num">Delivered</th><th class="num">Price</th><th class="num">Line total</th><th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$items): ?>
        <tr><td colspan="8" class="empty">No produce added yet.</td></tr>
<?php endif; ?>
<?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['batch_code'] ?? '—') ?></td>
          <td><?= e($item['crop_name']) ?></td>
          <td><?= e($item['grade']) ?></td>
          <td class="num"><?= fmt_qty($item['qty']) ?> <?= e($item['unit_code']) ?></td>
          <td class="num"><?= fmt_qty($item['qty_delivered']) ?></td>
          <td class="num"><?= fmt_money($item['unit_price']) ?></td>
          <td class="num"><?= fmt_money($item['line_total']) ?></td>
          <td class="actions">
<?php if ($isDraft && $canManage): ?>
            <form method="post" action="item_delete.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
              <input type="hidden" name="order_id" value="<?= $orderId ?>">
              <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('Remove this line?')">Remove</button>
            </form>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($isDraft && $canManage): ?>
  <div class="body" style="border-top:1px solid var(--line)">
<?php if (!$batches): ?>
    <p class="hint">No produce in store yet. Confirm a harvest first.</p>
<?php else: ?>
    <form method="post" action="item_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="order_id" value="<?= $orderId ?>">
      <div class="filters">
        <div class="field" style="flex:1">
          <label for="batch_id">Batch</label>
          <select name="batch_id" id="batch_id" required>
<?php foreach ($batches as $b): ?>
            <option value="<?= (int) $b['batch_id'] ?>">
              <?= e($b['batch_code']) ?> — <?= e($b['crop_name']) ?> (<?= e($b['grade']) ?>),
              <?= fmt_qty($b['current_qty']) ?> <?= e($b['unit_code']) ?> available
            </option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="qty">Quantity</label>
          <input type="number" name="qty" id="qty" step="0.001" min="0.001" required>
        </div>
        <div class="field">
          <label for="unit_price">Unit price</label>
          <input type="number" name="unit_price" id="unit_price" step="0.01" min="0.01" required>
        </div>
        <button class="btn" type="submit">Add</button>
      </div>
    </form>
<?php endif; ?>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Dispatch ---------------- -->
<?php if ($canDispatch || $dispatches): ?>
<div class="panel">
  <header>Dispatch</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Dispatch</th><th>Date</th><th>Batches</th><th>Vehicle</th><th>Status</th></tr>
      </thead>
      <tbody>
<?php if (!$dispatches): ?>
        <tr><td colspan="5" class="empty">Nothing dispatched yet.</td></tr>
<?php endif; ?>
<?php foreach ($dispatches as $d): ?>
        <tr>
          <td><?= e($d['dispatch_no']) ?></td>
          <td><?= e(fmt_date($d['dispatch_date'])) ?></td>
          <td><?= e($d['batches'] ?? '—') ?></td>
          <td><?= e($d['vehicle_reg'] ?? '—') ?></td>
          <td><?= e(str_replace('_', ' ', ucfirst($d['status']))) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canDispatch && $hasRemaining && $canManage): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <a class="btn btn-primary" href="dispatch_new.php?order_id=<?= $orderId ?>">Dispatch remaining produce</a>
  </div>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- ---------------- Invoice ---------------- -->
<div class="panel">
  <header>Invoice</header>
  <div class="body">
<?php if ($invoice): ?>
    <dl class="detail">
      <div><dt>Invoice</dt><dd><a href="invoice_view.php?id=<?= (int) $invoice['invoice_id'] ?>"><?= e($invoice['invoice_no']) ?></a></dd></div>
      <div><dt>Total</dt><dd><?= fmt_money($invoice['total_amount']) ?></dd></div>
      <div><dt>Paid</dt><dd><?= fmt_money($invoice['amount_paid']) ?></dd></div>
      <div><dt>Balance</dt><dd><?= fmt_money($invoice['balance']) ?></dd></div>
      <div><dt>Status</dt><dd><?= e(str_replace('_', ' ', ucfirst($invoice['status']))) ?></dd></div>
    </dl>
<?php elseif ($canInvoice && $canManage): ?>
    <p class="hint">Raising an invoice books the sale as income against each batch's crop cycle,
      for accurate profitability reporting.</p>
    <form method="post" action="invoice_generate.php">
      <?= csrf_field() ?>
      <input type="hidden" name="order_id" value="<?= $orderId ?>">
      <button class="btn btn-primary" type="submit">Generate invoice</button>
    </form>
<?php else: ?>
    <p class="hint">Confirm and dispatch the order before invoicing.</p>
<?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
