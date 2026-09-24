<?php
/**
 * Module 12 - Invoices, with overdue highlighted.
 */
$pageTitle = 'Invoices';
$activeNav = 'invoices';
require_once __DIR__ . '/../../includes/header.php';

$statusFilter = $_GET['status'] ?? '';
$where  = [];
$params = [];

if (in_array($statusFilter, ['unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled'], true)) {
    $where[] = 'i.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare(
    "SELECT i.*, cu.customer_name,
            CASE WHEN i.status IN ('unpaid','partially_paid') AND i.due_date < CURDATE()
                 THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END AS days_overdue
       FROM invoices i
       JOIN customers cu ON cu.customer_id = i.customer_id
       $whereSql
   ORDER BY i.invoice_date DESC"
);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$totals = db()->query(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS billed, COALESCE(SUM(balance),0) AS outstanding
       FROM invoices WHERE status IN ('unpaid','partially_paid','overdue')"
)->fetch();
?>

<div class="page-head">
  <div>
    <h1>Invoices</h1>
    <p class="lede">What customers have been billed, and what's still owed.</p>
  </div>
</div>

<dl class="stats">
  <div><dt>Open invoices</dt><dd><?= number_format($totals['n']) ?></dd></div>
  <div><dt>Billed (open)</dt><dd><?= fmt_money($totals['billed']) ?></dd></div>
  <div><dt>Outstanding</dt><dd><?= fmt_money($totals['outstanding']) ?></dd></div>
</dl>

<div class="panel">
  <div class="body filters">
    <div class="field">
      <label for="status">Status</label>
      <select name="status" id="status" onchange="location.href='?status='+this.value">
        <option value="">Any status</option>
<?php foreach (['unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled'] as $s): ?>
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
        <tr>
          <th>Invoice</th><th>Customer</th><th>Date</th><th>Due</th>
          <th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$invoices): ?>
        <tr><td colspan="8" class="empty">No invoices match.</td></tr>
<?php endif; ?>
<?php foreach ($invoices as $inv): ?>
        <tr>
          <td><a href="invoice_view.php?id=<?= (int) $inv['invoice_id'] ?>"><?= e($inv['invoice_no']) ?></a></td>
          <td><?= e($inv['customer_name']) ?></td>
          <td><?= e(fmt_date($inv['invoice_date'])) ?></td>
          <td><?= e(fmt_date($inv['due_date'])) ?><?= $inv['days_overdue'] > 0 ? ' (' . (int) $inv['days_overdue'] . 'd overdue)' : '' ?></td>
          <td class="num"><?= fmt_money($inv['total_amount']) ?></td>
          <td class="num"><?= fmt_money($inv['amount_paid']) ?></td>
          <td class="num"><?= fmt_money($inv['balance']) ?></td>
          <td><span class="status is-<?= $inv['status'] === 'paid' ? 'confirmed' : ($inv['status'] === 'cancelled' ? 'cancelled' : 'draft') ?>">
            <?= e(str_replace('_', ' ', ucfirst($inv['status']))) ?></span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
