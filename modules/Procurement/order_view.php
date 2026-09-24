<?php
/**
 * Module 14 - Purchase order detail.
 * Draft: add/remove items, then submit for approval.
 * Pending approval: an admin/owner approves or rejects.
 * Approved / partially received: receive goods, which posts the expense.
 * Any state with a balance: pay the supplier.
 * After some receipt exists: rate the supplier.
 */
$activeNav = 'purchases';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$poId = get_int('id');
if (!$poId) {
    redirect('orders.php');
}

$stmt = db()->prepare(
    "SELECT po.*, s.supplier_name, s.rating, q.quotation_no
       FROM purchase_orders po
       JOIN suppliers s ON s.supplier_id = po.supplier_id
  LEFT JOIN quotations q ON q.quotation_id = po.quotation_id
      WHERE po.po_id = :id"
);
$stmt->execute([':id' => $poId]);
$po = $stmt->fetch();

if (!$po) {
    flash('error', 'That purchase order does not exist.');
    redirect('orders.php');
}

$pageTitle = $po['po_no'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT poi.*, u.unit_code, c.crop_name,
            (poi.qty_ordered - poi.qty_received) AS remaining
       FROM purchase_order_items poi
       JOIN units u ON u.unit_id = poi.unit_id
  LEFT JOIN crop_cycles cc ON cc.cycle_id = poi.cycle_id
  LEFT JOIN crops c ON c.crop_id = cc.crop_id
      WHERE poi.po_id = :id
   ORDER BY poi.po_item_id"
);
$stmt->execute([':id' => $poId]);
$items = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT gr.*, u.full_name AS received_by_name FROM goods_receipts gr
  LEFT JOIN users u ON u.user_id = gr.received_by
      WHERE gr.po_id = :id ORDER BY gr.received_on DESC"
);
$stmt->execute([':id' => $poId]);
$receipts = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM supplier_payments WHERE po_id = :id ORDER BY payment_date');
$stmt->execute([':id' => $poId]);
$payments = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM supplier_evaluations WHERE po_id = :id');
$stmt->execute([':id' => $poId]);
$evaluation = $stmt->fetch();

$stmt = db()->prepare('SELECT decision, comments FROM procurement_approvals WHERE po_id = :id AND approval_level = 1');
$stmt->execute([':id' => $poId]);
$approval = $stmt->fetch();

$cycles = db()->query(
    "SELECT cc.cycle_id, c.crop_name FROM crop_cycles cc JOIN crops c ON c.crop_id = cc.crop_id ORDER BY c.crop_name"
)->fetchAll();
$units = db()->query('SELECT unit_id, unit_code FROM units ORDER BY unit_code')->fetchAll();

$isDraft       = $po['status'] === 'draft';
$canApprove    = user_can(['admin', 'owner']) && $po['status'] === 'pending_approval';
$canReceive    = user_can(['admin', 'owner', 'manager', 'storekeeper']) && in_array($po['status'], ['approved', 'partially_received'], true);
$canPay        = user_can(['admin', 'owner', 'manager', 'accountant']) && (float) $po['amount_paid'] < (float) $po['total_amount']
                 && !in_array($po['status'], ['draft', 'rejected', 'cancelled'], true);
$canEvaluate   = user_can(['admin', 'owner', 'manager']) && $receipts && !$evaluation;
$canManage     = user_can(['admin', 'owner', 'manager']);
$categories    = ['seeds', 'fertilizer', 'chemicals', 'feed', 'veterinary', 'fuel', 'equipment', 'packaging', 'services', 'other'];
$balance       = (float) $po['total_amount'] - (float) $po['amount_paid'];
?>

<div class="page-head">
  <div>
    <h1><?= e($po['po_no']) ?></h1>
    <p class="lede">
      <?= e($po['supplier_name']) ?> · ordered <?= e(fmt_date($po['order_date'])) ?>
<?php if ($po['quotation_no']): ?>
      · against quotation <?= e($po['quotation_no']) ?>
<?php endif; ?>
      <span class="status is-<?= $po['status'] === 'received' ? 'confirmed' : (in_array($po['status'], ['rejected', 'cancelled'], true) ? 'cancelled' : 'draft') ?>">
        <?= e(ucfirst(str_replace('_', ' ', $po['status']))) ?>
      </span>
    </p>
  </div>
  <div>
<?php if ($isDraft && $canManage): ?>
    <form method="post" action="order_submit.php" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="po_id" value="<?= $poId ?>">
      <button class="btn btn-primary" type="submit"
              onclick="return confirm('Submit for approval? Items cannot be changed afterwards.')">Submit for approval</button>
    </form>
<?php endif; ?>
    <a class="btn" href="orders.php">Back to list</a>
  </div>
</div>

<dl class="stats">
  <div><dt>Subtotal</dt><dd><?= fmt_money($po['subtotal']) ?></dd></div>
  <div><dt>Total</dt><dd><?= fmt_money($po['total_amount']) ?></dd></div>
  <div><dt>Paid</dt><dd><?= fmt_money($po['amount_paid']) ?></dd></div>
  <div><dt>Balance</dt><dd><?= fmt_money($balance) ?></dd></div>
  <div><dt>Supplier rating</dt><dd><?= $po['rating'] > 0 ? fmt_qty($po['rating'], 1) . ' / 5' : '—' ?></dd></div>
</dl>

<!-- ---------------- Approval ---------------- -->
<?php if ($po['status'] === 'pending_approval' || $approval): ?>
<div class="panel">
  <header>Approval</header>
  <div class="body">
<?php if ($approval): ?>
    <p>Decision: <strong><?= e(ucfirst($approval['decision'])) ?></strong>
<?php if ($approval['comments']): ?>
      — <?= e($approval['comments']) ?>
<?php endif; ?>
    </p>
<?php elseif ($canApprove): ?>
    <form method="post" action="approve.php">
      <?= csrf_field() ?>
      <input type="hidden" name="po_id" value="<?= $poId ?>">
      <div class="filters">
        <div class="field" style="flex:1">
          <label for="comments">Comments</label>
          <input type="text" name="comments" id="comments" maxlength="255">
        </div>
        <button class="btn btn-primary" type="submit" name="decision" value="approved">Approve</button>
        <button class="btn btn-danger" type="submit" name="decision" value="rejected">Reject</button>
      </div>
    </form>
<?php else: ?>
    <p class="hint">Awaiting approval from an admin or owner.</p>
<?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ---------------- Items ---------------- -->
<div class="panel">
  <header>Items ordered</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Item</th><th>Category</th><th>Crop cycle</th>
          <th class="num">Ordered</th><th class="num">Received</th><th class="num">Unit price</th><th class="num">Line total</th><th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$items): ?>
        <tr><td colspan="8" class="empty">No items added yet.</td></tr>
<?php endif; ?>
<?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['item_name']) ?></td>
          <td><?= e(ucfirst($item['item_category'])) ?></td>
          <td><?= e($item['crop_name'] ?? '—') ?></td>
          <td class="num"><?= fmt_qty($item['qty_ordered']) ?> <?= e($item['unit_code']) ?></td>
          <td class="num"><?= fmt_qty($item['qty_received']) ?></td>
          <td class="num"><?= fmt_money($item['unit_price']) ?></td>
          <td class="num"><?= fmt_money($item['line_total']) ?></td>
          <td class="actions">
<?php if ($isDraft && $canManage): ?>
            <form method="post" action="item_delete.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="item_id" value="<?= (int) $item['po_item_id'] ?>">
              <input type="hidden" name="po_id" value="<?= $poId ?>">
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
    <form method="post" action="item_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="po_id" value="<?= $poId ?>">
      <div class="filters">
        <div class="field" style="flex:1">
          <label for="item_name">Item</label>
          <input type="text" name="item_name" id="item_name" required maxlength="150">
        </div>
        <div class="field">
          <label for="item_category">Category</label>
          <select name="item_category" id="item_category">
<?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>"><?= e(ucfirst($cat)) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="qty_ordered">Quantity</label>
          <input type="number" name="qty_ordered" id="qty_ordered" step="0.001" min="0.001" required>
        </div>
        <div class="field">
          <label for="unit_id">Unit</label>
          <select name="unit_id" id="unit_id" required>
<?php foreach ($units as $u): ?>
            <option value="<?= (int) $u['unit_id'] ?>"><?= e($u['unit_code']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="unit_price">Unit price</label>
          <input type="number" name="unit_price" id="unit_price" step="0.01" min="0.01" required>
        </div>
        <div class="field">
          <label for="cycle_id">Crop cycle</label>
          <select name="cycle_id" id="cycle_id">
            <option value="">General</option>
<?php foreach ($cycles as $c): ?>
            <option value="<?= (int) $c['cycle_id'] ?>"><?= e($c['crop_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <button class="btn" type="submit">Add</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Receipts ---------------- -->
<?php if ($canReceive || $receipts): ?>
<div class="panel">
  <header>Goods receipts</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>GRN</th><th>Date</th><th>Condition</th><th>Received by</th></tr></thead>
      <tbody>
<?php if (!$receipts): ?>
        <tr><td colspan="4" class="empty">Nothing received yet.</td></tr>
<?php endif; ?>
<?php foreach ($receipts as $r): ?>
        <tr>
          <td><?= e($r['grn_no']) ?></td>
          <td><?= e(fmt_date($r['received_on'])) ?></td>
          <td><?= e(ucfirst($r['condition_on_arrival'])) ?></td>
          <td><?= e($r['received_by_name'] ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php if ($canReceive): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <a class="btn btn-primary" href="receipt_new.php?po_id=<?= $poId ?>">Receive goods</a>
  </div>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- ---------------- Payments ---------------- -->
<div class="panel">
  <header>Payments to supplier</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Voucher</th><th>Date</th><th class="num">Amount</th><th>Method</th></tr></thead>
      <tbody>
<?php if (!$payments): ?>
        <tr><td colspan="4" class="empty">No payments recorded yet.</td></tr>
<?php endif; ?>
<?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e($p['voucher_no']) ?></td>
          <td><?= e(fmt_date($p['payment_date'])) ?></td>
          <td class="num"><?= fmt_money($p['amount']) ?></td>
          <td><?= e(str_replace('_', ' ', ucfirst($p['method']))) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canPay): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post" action="payment_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="po_id" value="<?= $poId ?>">
      <div class="filters">
        <div class="field">
          <label for="payment_date">Date</label>
          <input type="date" name="payment_date" id="payment_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="amount">Amount</label>
          <input type="number" name="amount" id="amount" step="0.01" min="0.01" max="<?= e((string) $balance) ?>" value="<?= e((string) $balance) ?>" required>
        </div>
        <div class="field">
          <label for="method">Method</label>
          <select name="method" id="method">
            <option value="bank_transfer">Bank transfer</option>
            <option value="mobile_money">Mobile money</option>
            <option value="cash">Cash</option>
            <option value="cheque">Cheque</option>
          </select>
        </div>
        <div class="field">
          <label for="reference">Reference</label>
          <input type="text" name="reference" id="reference" maxlength="80">
        </div>
        <button class="btn btn-primary" type="submit">Record payment</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Supplier evaluation ---------------- -->
<?php if ($evaluation || $canEvaluate): ?>
<div class="panel">
  <header>Supplier evaluation</header>
  <div class="body">
<?php if ($evaluation): ?>
    <dl class="detail">
      <div><dt>Delivery timeliness</dt><dd><?= (int) $evaluation['delivery_timeliness'] ?> / 5</dd></div>
      <div><dt>Quality</dt><dd><?= (int) $evaluation['quality_score'] ?> / 5</dd></div>
      <div><dt>Price</dt><dd><?= (int) $evaluation['price_competitiveness'] ?> / 5</dd></div>
      <div><dt>Service</dt><dd><?= (int) $evaluation['service_score'] ?> / 5</dd></div>
    </dl>
<?php elseif ($canEvaluate): ?>
    <form method="post" action="evaluation_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="po_id" value="<?= $poId ?>">
      <div class="grid-2">
        <div class="field">
          <label for="delivery_timeliness">Delivery timeliness (1-5)</label>
          <input type="number" name="delivery_timeliness" id="delivery_timeliness" min="1" max="5" value="3" required>
        </div>
        <div class="field">
          <label for="quality_score">Quality (1-5)</label>
          <input type="number" name="quality_score" id="quality_score" min="1" max="5" value="3" required>
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="price_competitiveness">Price (1-5)</label>
          <input type="number" name="price_competitiveness" id="price_competitiveness" min="1" max="5" value="3" required>
        </div>
        <div class="field">
          <label for="service_score">Service (1-5)</label>
          <input type="number" name="service_score" id="service_score" min="1" max="5" value="3" required>
        </div>
      </div>
      <div class="field">
        <label for="comments">Comments</label>
        <input type="text" name="comments" id="comments" maxlength="255">
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Rate supplier</button>
      </div>
    </form>
<?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
