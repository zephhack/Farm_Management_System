<?php
/**
 * Module 12 - Invoice detail and payment recording.
 */
$activeNav = 'invoices';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$invoiceId = get_int('id');
if (!$invoiceId) {
    redirect('invoices.php');
}

$stmt = db()->prepare(
    "SELECT i.*, cu.customer_name, cu.phone, cu.email, so.order_no
       FROM invoices i
       JOIN customers cu ON cu.customer_id = i.customer_id
  LEFT JOIN sales_orders so ON so.order_id = i.order_id
      WHERE i.invoice_id = :id"
);
$stmt->execute([':id' => $invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    flash('error', 'That invoice does not exist.');
    redirect('invoices.php');
}

$pageTitle = $invoice['invoice_no'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    'SELECT * FROM payments_received WHERE invoice_id = :id ORDER BY payment_date'
);
$stmt->execute([':id' => $invoiceId]);
$payments = $stmt->fetchAll();

$canRecord = user_can(['admin', 'owner', 'manager', 'accountant'])
    && in_array($invoice['status'], ['unpaid', 'partially_paid', 'overdue'], true);
?>

<div class="page-head">
  <div>
    <h1><?= e($invoice['invoice_no']) ?></h1>
    <p class="lede">
      <?= e($invoice['customer_name']) ?>
<?php if ($invoice['order_no']): ?>
      · from order <a href="order_view.php?id=<?= (int) $invoice['order_id'] ?>"><?= e($invoice['order_no']) ?></a>
<?php endif; ?>
      <span class="status is-<?= $invoice['status'] === 'paid' ? 'confirmed' : ($invoice['status'] === 'cancelled' ? 'cancelled' : 'draft') ?>">
        <?= e(str_replace('_', ' ', ucfirst($invoice['status']))) ?>
      </span>
    </p>
  </div>
  <a class="btn" href="invoices.php">Back to list</a>
</div>

<dl class="stats">
  <div><dt>Total</dt><dd><?= fmt_money($invoice['total_amount']) ?></dd></div>
  <div><dt>Paid</dt><dd><?= fmt_money($invoice['amount_paid']) ?></dd></div>
  <div><dt>Balance</dt><dd><?= fmt_money($invoice['balance']) ?></dd></div>
  <div><dt>Due</dt><dd><?= e(fmt_date($invoice['due_date'])) ?></dd></div>
</dl>

<div class="panel">
  <header>Payments</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Receipt</th><th>Date</th><th class="num">Amount</th><th>Method</th><th>Reference</th></tr></thead>
      <tbody>
<?php if (!$payments): ?>
        <tr><td colspan="5" class="empty">No payments recorded yet.</td></tr>
<?php endif; ?>
<?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e($p['receipt_no']) ?></td>
          <td><?= e(fmt_date($p['payment_date'])) ?></td>
          <td class="num"><?= fmt_money($p['amount']) ?></td>
          <td><?= e(str_replace('_', ' ', ucfirst($p['method']))) ?></td>
          <td><?= e($p['reference'] ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canRecord): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post" action="payment_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
      <div class="filters">
        <div class="field">
          <label for="payment_date">Date</label>
          <input type="date" name="payment_date" id="payment_date" required
                 max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="amount">Amount</label>
          <input type="number" name="amount" id="amount" step="0.01" min="0.01"
                 max="<?= e((string) $invoice['balance']) ?>" required value="<?= e((string) $invoice['balance']) ?>">
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
