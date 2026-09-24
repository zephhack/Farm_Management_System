<?php
/**
 * Module 12 - Contract farming agreements.
 * delivered_qty is kept up to date by invoice_generate.php whenever an
 * invoice is raised against an order linked to a contract.
 */
$pageTitle = 'Contracts';
$activeNav = 'contracts';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager']);
    csrf_check();

    $customerId = (int) post('customer_id', 0);
    $cropId     = (int) post('crop_id', 0);
    $qty        = (float) post('contracted_qty', 0);
    $unitId     = (int) post('unit_id', 0);
    $price      = (float) post('agreed_price', 0);
    $start      = post('start_date');
    $end        = post('end_date');
    $terms      = post('terms') ?: null;

    $errors = [];
    if ($customerId <= 0)          { $errors[] = 'Choose the customer.'; }
    if ($cropId <= 0)              { $errors[] = 'Choose the crop.'; }
    if ($qty <= 0)                 { $errors[] = 'Contracted quantity must be greater than zero.'; }
    if ($unitId <= 0)              { $errors[] = 'Choose a unit.'; }
    if ($price <= 0)               { $errors[] = 'Agreed price must be greater than zero.'; }
    if (!valid_date($start))       { $errors[] = 'Enter a valid start date.'; }
    if (!valid_date($end))         { $errors[] = 'Enter a valid end date.'; }
    if (valid_date($start) && valid_date($end) && $end < $start) {
        $errors[] = 'The end date is before the start date.';
    }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
    } else {
        $contractNo = next_code('contracts', 'contract_no', 'CON');
        db()->prepare(
            "INSERT INTO contracts
               (contract_no, customer_id, crop_id, contracted_qty, unit_id, agreed_price,
                start_date, end_date, status, terms)
             VALUES
               (:no, :customer_id, :crop_id, :qty, :unit_id, :price, :start, :end, 'active', :terms)"
        )->execute([
            ':no' => $contractNo, ':customer_id' => $customerId, ':crop_id' => $cropId,
            ':qty' => $qty, ':unit_id' => $unitId, ':price' => $price,
            ':start' => $start, ':end' => $end, ':terms' => $terms,
        ]);
        flash('success', "Contract $contractNo created.");
    }

    redirect('contracts.php');
}

require_once __DIR__ . '/../../includes/header.php';

$contracts = db()->query(
    "SELECT co.*, cu.customer_name, c.crop_name, u.unit_code,
            (co.contracted_qty - co.delivered_qty) AS remaining_qty
       FROM contracts co
       JOIN customers cu ON cu.customer_id = co.customer_id
       JOIN crops c ON c.crop_id = co.crop_id
       JOIN units u ON u.unit_id = co.unit_id
   ORDER BY co.end_date"
)->fetchAll();

$customers = db()->query('SELECT customer_id, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name')->fetchAll();
$crops     = db()->query('SELECT crop_id, crop_name FROM crops ORDER BY crop_name')->fetchAll();
$units     = db()->query('SELECT unit_id, unit_code FROM units ORDER BY unit_code')->fetchAll();
$canManage = user_can(['admin', 'owner', 'manager']);
?>

<div class="page-head">
  <div>
    <h1>Contract farming agreements</h1>
    <p class="lede">Standing supply agreements. Link a sales order to a contract
      and the delivered quantity here updates automatically as invoices are raised.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Contract</th><th>Customer</th><th>Crop</th>
          <th class="num">Contracted</th><th class="num">Delivered</th><th class="num">Remaining</th>
          <th class="num">Agreed price</th><th>Ends</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$contracts): ?>
        <tr><td colspan="9" class="empty">No contracts yet.</td></tr>
<?php endif; ?>
<?php foreach ($contracts as $c): ?>
        <tr>
          <td><?= e($c['contract_no']) ?></td>
          <td><?= e($c['customer_name']) ?></td>
          <td><?= e($c['crop_name']) ?></td>
          <td class="num"><?= fmt_qty($c['contracted_qty']) ?> <?= e($c['unit_code']) ?></td>
          <td class="num"><?= fmt_qty($c['delivered_qty']) ?></td>
          <td class="num"><?= fmt_qty($c['remaining_qty']) ?></td>
          <td class="num"><?= fmt_money($c['agreed_price']) ?></td>
          <td><?= e(fmt_date($c['end_date'])) ?></td>
          <td><span class="status is-<?= $c['status'] === 'active' ? 'confirmed' : ($c['status'] === 'breached' ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst($c['status'])) ?></span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>New contract</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid-3">
        <div class="field">
          <label for="customer_id">Customer</label>
          <select name="customer_id" id="customer_id" required>
            <option value="">Select</option>
<?php foreach ($customers as $c): ?>
            <option value="<?= (int) $c['customer_id'] ?>"><?= e($c['customer_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="crop_id">Crop</label>
          <select name="crop_id" id="crop_id" required>
            <option value="">Select</option>
<?php foreach ($crops as $c): ?>
            <option value="<?= (int) $c['crop_id'] ?>"><?= e($c['crop_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="agreed_price">Agreed price / unit</label>
          <input type="number" name="agreed_price" id="agreed_price" step="0.01" min="0.01" required>
        </div>
      </div>
      <div class="grid-3">
        <div class="field">
          <label for="contracted_qty">Contracted quantity</label>
          <input type="number" name="contracted_qty" id="contracted_qty" step="0.001" min="0.001" required>
        </div>
        <div class="field">
          <label for="unit_id">Unit</label>
          <select name="unit_id" id="unit_id" required>
<?php foreach ($units as $u): ?>
            <option value="<?= (int) $u['unit_id'] ?>"><?= e($u['unit_code']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field"></div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="start_date">Start date</label>
          <input type="date" name="start_date" id="start_date" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="end_date">End date</label>
          <input type="date" name="end_date" id="end_date" required>
        </div>
      </div>
      <div class="field">
        <label for="terms">Terms</label>
        <textarea name="terms" id="terms" rows="3"></textarea>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Create contract</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
