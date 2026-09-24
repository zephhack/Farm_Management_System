<?php
/**
 * Module 14 - Quotation detail: line items, and marking it selected/rejected.
 */
$activeNav = 'quotations';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$quotationId = get_int('id');
if (!$quotationId) {
    redirect('quotations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager']);
    csrf_check();
    $action = post('action');

    if ($action === 'add_item') {
        $itemName    = post('item_name');
        $category    = post('item_category', 'other');
        $qty         = (float) post('qty', 0);
        $unitId      = (int) post('unit_id', 0);
        $unitPrice   = (float) post('unit_price', 0);

        if ($itemName === '' || $itemName === null) {
            flash('error', 'Enter the item name.');
        } elseif ($qty <= 0) {
            flash('error', 'Quantity must be greater than zero.');
        } elseif ($unitId <= 0) {
            flash('error', 'Choose a unit.');
        } elseif ($unitPrice <= 0) {
            flash('error', 'Enter a unit price.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO quotation_items (quotation_id, item_name, item_category, qty, unit_id, unit_price)
                 VALUES (:qid, :name, :category, :qty, :unit_id, :price)"
            )->execute([
                ':qid' => $quotationId, ':name' => $itemName, ':category' => $category,
                ':qty' => $qty, ':unit_id' => $unitId, ':price' => $unitPrice,
            ]);
            $pdo->prepare(
                "UPDATE quotations SET total_amount = (
                   SELECT COALESCE(SUM(line_total), 0) FROM quotation_items WHERE quotation_id = :qid
                 ), status = IF(status = 'requested', 'received', status)
                 WHERE quotation_id = :qid"
            )->execute([':qid' => $quotationId]);
            $pdo->commit();
            flash('success', 'Item added.');
        }
    } elseif ($action === 'set_status') {
        $newStatus = post('status');
        if (in_array($newStatus, ['selected', 'rejected'], true)) {
            db()->prepare('UPDATE quotations SET status = :status WHERE quotation_id = :id')
                ->execute([':status' => $newStatus, ':id' => $quotationId]);
            flash('success', 'Quotation marked ' . $newStatus . '.');
        }
    }

    redirect("quotation_view.php?id=$quotationId");
}

$stmt = db()->prepare(
    "SELECT q.*, s.supplier_name FROM quotations q JOIN suppliers s ON s.supplier_id = q.supplier_id WHERE q.quotation_id = :id"
);
$stmt->execute([':id' => $quotationId]);
$quotation = $stmt->fetch();

if (!$quotation) {
    flash('error', 'That quotation does not exist.');
    redirect('quotations.php');
}

$pageTitle = $quotation['quotation_no'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT qi.*, u.unit_code FROM quotation_items qi JOIN units u ON u.unit_id = qi.unit_id
      WHERE qi.quotation_id = :id ORDER BY qi.quotation_item_id"
);
$stmt->execute([':id' => $quotationId]);
$items = $stmt->fetchAll();

$units     = db()->query('SELECT unit_id, unit_code FROM units ORDER BY unit_code')->fetchAll();
$canManage = user_can(['admin', 'owner', 'manager']) && in_array($quotation['status'], ['requested', 'received'], true);
$categories = ['seeds', 'fertilizer', 'chemicals', 'feed', 'veterinary', 'fuel', 'equipment', 'packaging', 'services', 'other'];
?>

<div class="page-head">
  <div>
    <h1><?= e($quotation['quotation_no']) ?></h1>
    <p class="lede"><?= e($quotation['supplier_name']) ?> · requested <?= e(fmt_date($quotation['request_date'])) ?>
      <span class="status is-<?= $quotation['status'] === 'selected' ? 'confirmed' : (in_array($quotation['status'], ['rejected','expired'], true) ? 'cancelled' : 'draft') ?>">
        <?= e(ucfirst($quotation['status'])) ?>
      </span>
    </p>
  </div>
  <div>
<?php if (user_can(['admin', 'owner', 'manager']) && in_array($quotation['status'], ['requested', 'received'], true)): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="selected">
      <button class="btn btn-primary" type="submit">Mark selected</button>
    </form>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="rejected">
      <button class="btn btn-danger" type="submit">Reject</button>
    </form>
<?php endif; ?>
    <a class="btn" href="quotations.php">Back to list</a>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Item</th><th>Category</th><th class="num">Quantity</th><th class="num">Unit price</th><th class="num">Line total</th></tr></thead>
      <tbody>
<?php if (!$items): ?>
        <tr><td colspan="5" class="empty">No items quoted yet.</td></tr>
<?php endif; ?>
<?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['item_name']) ?></td>
          <td><?= e(ucfirst($item['item_category'])) ?></td>
          <td class="num"><?= fmt_qty($item['qty']) ?> <?= e($item['unit_code']) ?></td>
          <td class="num"><?= fmt_money($item['unit_price']) ?></td>
          <td class="num"><?= fmt_money($item['line_total']) ?></td>
        </tr>
<?php endforeach; ?>
<?php if ($items): ?>
        <tr><td colspan="4"><strong>Total</strong></td><td class="num"><strong><?= fmt_money($quotation['total_amount']) ?></strong></td></tr>
<?php endif; ?>
      </tbody>
    </table>
  </div>

<?php if ($canManage): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_item">
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
          <label for="qty">Quantity</label>
          <input type="number" name="qty" id="qty" step="0.001" min="0.001" required>
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
        <button class="btn" type="submit">Add</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
