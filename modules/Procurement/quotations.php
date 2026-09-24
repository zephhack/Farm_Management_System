<?php
/**
 * Module 14 - Supplier quotations.
 * A quotation records what a supplier offered before a purchase order
 * commits to it. A PO can be created directly, or built from a selected
 * quotation via order_form.php's quotation picker.
 */
$pageTitle = 'Quotations';
$activeNav = 'quotations';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create_quotation') {
    require_role(['admin', 'owner', 'manager']);
    csrf_check();

    $supplierId = (int) post('supplier_id', 0);
    $requestDate = post('request_date');
    $validUntil  = post('valid_until') ?: null;

    $errors = [];
    if ($supplierId <= 0)          { $errors[] = 'Choose a supplier.'; }
    if (!valid_date($requestDate)) { $errors[] = 'Enter a valid request date.'; }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect('quotations.php');
    }

    $quotationNo = next_code('quotations', 'quotation_no', 'QUO');
    db()->prepare(
        "INSERT INTO quotations (quotation_no, supplier_id, request_date, valid_until, status)
         VALUES (:no, :supplier_id, :request_date, :valid_until, 'requested')"
    )->execute([
        ':no' => $quotationNo, ':supplier_id' => $supplierId,
        ':request_date' => $requestDate, ':valid_until' => $validUntil,
    ]);

    flash('success', "Quotation $quotationNo created. Add items to it below.");
    redirect('quotation_view.php?id=' . db()->lastInsertId());
}

require_once __DIR__ . '/../../includes/header.php';

$quotations = db()->query(
    "SELECT q.*, s.supplier_name FROM quotations q
       JOIN suppliers s ON s.supplier_id = q.supplier_id
   ORDER BY q.request_date DESC"
)->fetchAll();

$suppliers = db()->query('SELECT supplier_id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name')->fetchAll();
$canManage = user_can(['admin', 'owner', 'manager']);
?>

<div class="page-head">
  <div>
    <h1>Supplier quotations</h1>
    <p class="lede">What suppliers have quoted, before a purchase order commits to one.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Quotation</th><th>Supplier</th><th>Requested</th><th>Valid until</th><th class="num">Total</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
<?php if (!$quotations): ?>
        <tr><td colspan="7" class="empty">No quotations yet.</td></tr>
<?php endif; ?>
<?php foreach ($quotations as $q): ?>
        <tr>
          <td><a href="quotation_view.php?id=<?= (int) $q['quotation_id'] ?>"><?= e($q['quotation_no']) ?></a></td>
          <td><?= e($q['supplier_name']) ?></td>
          <td><?= e(fmt_date($q['request_date'])) ?></td>
          <td><?= e(fmt_date($q['valid_until'])) ?></td>
          <td class="num"><?= fmt_money($q['total_amount']) ?></td>
          <td><span class="status is-<?= $q['status'] === 'selected' ? 'confirmed' : (in_array($q['status'], ['rejected','expired'], true) ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst($q['status'])) ?></span></td>
          <td class="actions"><a class="btn btn-sm" href="quotation_view.php?id=<?= (int) $q['quotation_id'] ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>Request a quotation</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_quotation">
      <div class="filters">
        <div class="field" style="flex:1">
          <label for="supplier_id">Supplier</label>
          <select name="supplier_id" id="supplier_id" required>
            <option value="">Select</option>
<?php foreach ($suppliers as $s): ?>
            <option value="<?= (int) $s['supplier_id'] ?>"><?= e($s['supplier_name']) ?></option>
<?php endforeach; ?>
          </select>
<?php if (!$suppliers): ?>
          <p class="hint">No suppliers yet. <a href="suppliers.php">Add one first</a>.</p>
<?php endif; ?>
        </div>
        <div class="field">
          <label for="request_date">Request date</label>
          <input type="date" name="request_date" id="request_date" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="valid_until">Valid until</label>
          <input type="date" name="valid_until" id="valid_until">
        </div>
        <button class="btn btn-primary" type="submit">Create</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
