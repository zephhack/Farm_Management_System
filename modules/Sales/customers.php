<?php
/**
 * Module 12 - Buyer / customer management.
 * List, create, and edit in one file — the same combined pattern as
 * modules/harvest/plans.php, since customers have few enough fields
 * not to need a separate form page.
 */
$pageTitle = 'Customers';
$activeNav = 'customers';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$editId = get_int('edit');
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager']);
    csrf_check();

    $id            = (int) post('customer_id', 0);
    $name          = post('customer_name');
    $type          = post('customer_type', 'individual');
    $contactPerson = post('contact_person') ?: null;
    $phone         = post('phone') ?: null;
    $email         = post('email') ?: null;
    $address       = post('address') ?: null;
    $tpin          = post('tpin') ?: null;
    $creditLimit   = (float) post('credit_limit', 0);
    $termsDays     = (int) post('payment_terms_days', 0);

    $validTypes = ['individual', 'retailer', 'wholesaler', 'processor', 'exporter', 'institution', 'cooperative'];

    $errors = [];
    if ($name === '' || $name === null)       { $errors[] = 'Enter the customer name.'; }
    if (!in_array($type, $validTypes, true))  { $errors[] = 'Choose a valid customer type.'; }
    if ($creditLimit < 0)                     { $errors[] = 'Credit limit cannot be negative.'; }
    if ($termsDays < 0)                       { $errors[] = 'Payment terms cannot be negative.'; }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect($id ? "customers.php?edit=$id" : 'customers.php');
    }

    if ($id > 0) {
        $stmt = db()->prepare(
            "UPDATE customers
                SET customer_name = :name, customer_type = :type, contact_person = :contact,
                    phone = :phone, email = :email, address = :address, tpin = :tpin,
                    credit_limit = :credit, payment_terms_days = :terms
              WHERE customer_id = :id"
        );
        $stmt->execute([
            ':name' => $name, ':type' => $type, ':contact' => $contactPerson,
            ':phone' => $phone, ':email' => $email, ':address' => $address, ':tpin' => $tpin,
            ':credit' => $creditLimit, ':terms' => $termsDays, ':id' => $id,
        ]);
        flash('success', "$name updated.");
    } else {
        $code = next_code('customers', 'customer_code', 'CUS');
        $stmt = db()->prepare(
            "INSERT INTO customers
               (customer_code, customer_name, customer_type, contact_person, phone, email,
                address, tpin, credit_limit, payment_terms_days)
             VALUES
               (:code, :name, :type, :contact, :phone, :email, :address, :tpin, :credit, :terms)"
        );
        $stmt->execute([
            ':code' => $code, ':name' => $name, ':type' => $type, ':contact' => $contactPerson,
            ':phone' => $phone, ':email' => $email, ':address' => $address, ':tpin' => $tpin,
            ':credit' => $creditLimit, ':terms' => $termsDays,
        ]);
        flash('success', "$name added as $code.");
    }

    redirect('customers.php');
}

require_once __DIR__ . '/../../includes/header.php';

if ($editId) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE customer_id = :id');
    $stmt->execute([':id' => $editId]);
    $editing = $stmt->fetch();
}

$customers = db()->query(
    "SELECT c.*, COALESCE(SUM(i.balance), 0) AS outstanding
       FROM customers c
  LEFT JOIN invoices i ON i.customer_id = c.customer_id AND i.status IN ('unpaid', 'partially_paid', 'overdue')
      GROUP BY c.customer_id
      ORDER BY c.customer_name"
)->fetchAll();

$canManage = user_can(['admin', 'owner', 'manager']);
$types = ['individual', 'retailer', 'wholesaler', 'processor', 'exporter', 'institution', 'cooperative'];
?>

<div class="page-head">
  <div>
    <h1>Customers</h1>
    <p class="lede">Buyers your produce is sold to. Credit limit and payment terms
      feed the invoice due date and the receivables report.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Code</th><th>Name</th><th>Type</th><th>Contact</th>
          <th class="num">Terms (days)</th><th class="num">Outstanding</th><th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$customers): ?>
        <tr><td colspan="7" class="empty">No customers yet. Add one below.</td></tr>
<?php endif; ?>
<?php foreach ($customers as $c): ?>
        <tr>
          <td><?= e($c['customer_code']) ?></td>
          <td><?= e($c['customer_name']) ?></td>
          <td><?= e(ucfirst($c['customer_type'])) ?></td>
          <td><?= e($c['contact_person'] ?? '—') ?><?= $c['phone'] ? ' · ' . e($c['phone']) : '' ?></td>
          <td class="num"><?= (int) $c['payment_terms_days'] ?></td>
          <td class="num"><?= fmt_money($c['outstanding']) ?></td>
          <td class="actions">
<?php if ($canManage): ?>
            <a class="btn btn-sm" href="?edit=<?= (int) $c['customer_id'] ?>">Edit</a>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header><?= $editing ? 'Edit ' . e($editing['customer_name']) : 'Add a customer' ?></header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
<?php if ($editing): ?>
      <input type="hidden" name="customer_id" value="<?= (int) $editing['customer_id'] ?>">
<?php endif; ?>
      <div class="grid-2">
        <div class="field">
          <label for="customer_name">Name</label>
          <input type="text" name="customer_name" id="customer_name" required maxlength="150"
                 value="<?= e($editing['customer_name'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="customer_type">Type</label>
          <select name="customer_type" id="customer_type">
<?php foreach ($types as $type): ?>
            <option value="<?= e($type) ?>" <?= ($editing['customer_type'] ?? '') === $type ? 'selected' : '' ?>>
              <?= e(ucfirst($type)) ?>
            </option>
<?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="contact_person">Contact person</label>
          <input type="text" name="contact_person" id="contact_person" maxlength="120"
                 value="<?= e($editing['contact_person'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="phone">Phone</label>
          <input type="text" name="phone" id="phone" maxlength="30"
                 value="<?= e($editing['phone'] ?? '') ?>">
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="email">Email</label>
          <input type="email" name="email" id="email" maxlength="150"
                 value="<?= e($editing['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="tpin">Tax ID (TPIN)</label>
          <input type="text" name="tpin" id="tpin" maxlength="30"
                 value="<?= e($editing['tpin'] ?? '') ?>">
        </div>
      </div>
      <div class="field">
        <label for="address">Address</label>
        <input type="text" name="address" id="address" maxlength="255"
               value="<?= e($editing['address'] ?? '') ?>">
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="credit_limit">Credit limit</label>
          <input type="number" name="credit_limit" id="credit_limit" step="0.01" min="0"
                 value="<?= e($editing['credit_limit'] ?? '0') ?>">
        </div>
        <div class="field">
          <label for="payment_terms_days">Payment terms (days)</label>
          <input type="number" name="payment_terms_days" id="payment_terms_days" step="1" min="0"
                 value="<?= e($editing['payment_terms_days'] ?? '0') ?>">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add customer' ?></button>
<?php if ($editing): ?>
        <a class="btn" href="customers.php">Cancel</a>
<?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
