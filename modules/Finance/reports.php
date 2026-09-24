<?php
/**
 * Module 13 - Reports.
 * Every number here comes from a view already defined in the schema
 * (v_cycle_profitability, v_receivables, v_payables, v_stock_on_hand) or
 * a straight aggregate of financial_transactions — nothing is recomputed
 * with different logic than what the rest of the app already uses, so
 * this page can't disagree with the ledger it's reporting on.
 */
$pageTitle = 'Reports';
$activeNav = 'reports';
require_once __DIR__ . '/../../includes/header.php';

$overall = db()->query(
    "SELECT
       COALESCE(SUM(CASE WHEN txn_type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
       COALESCE(SUM(CASE WHEN txn_type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
     FROM financial_transactions"
)->fetch();

$netProfit = (float) $overall['total_income'] - (float) $overall['total_expense'];

$profitability = db()->query(
    "SELECT crop_name, season_label, area_planted_ha, total_income, total_expense, net_profit,
            roi_percent, cost_per_hectare, cost_per_unit
       FROM v_cycle_profitability
      ORDER BY net_profit DESC"
)->fetchAll();

$receivables = db()->query('SELECT * FROM v_receivables ORDER BY days_overdue DESC')->fetchAll();
$payables    = db()->query('SELECT * FROM v_payables ORDER BY order_date')->fetchAll();

$totalReceivable = array_sum(array_column($receivables, 'balance'));
$totalPayable    = array_sum(array_column($payables, 'balance'));

$stockValue = db()->query('SELECT COALESCE(SUM(stock_value), 0) FROM v_stock_on_hand')->fetchColumn();

$expenseByCategory = db()->query(
    "SELECT fc.category_name, SUM(ft.amount) AS total
       FROM financial_transactions ft
       JOIN finance_categories fc ON fc.category_id = ft.category_id
      WHERE ft.txn_type = 'expense'
      GROUP BY fc.category_name
      ORDER BY total DESC
      LIMIT 8"
)->fetchAll();
?>

<div class="page-head">
  <div>
    <h1>Reports</h1>
    <p class="lede">Farm-wide position, then profitability crop by crop.</p>
  </div>
</div>

<dl class="stats">
  <div><dt>Total income</dt><dd><?= fmt_money($overall['total_income']) ?></dd></div>
  <div><dt>Total expense</dt><dd><?= fmt_money($overall['total_expense']) ?></dd></div>
  <div><dt>Net profit</dt><dd><?= fmt_money($netProfit) ?></dd></div>
  <div><dt>Stock on hand (value)</dt><dd><?= fmt_money($stockValue) ?></dd></div>
  <div><dt>Owed to us</dt><dd><?= fmt_money($totalReceivable) ?></dd></div>
  <div><dt>We owe</dt><dd><?= fmt_money($totalPayable) ?></dd></div>
</dl>

<div class="panel">
  <header>Profitability by crop cycle</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Crop</th><th>Season</th><th class="num">Area (ha)</th>
          <th class="num">Income</th><th class="num">Expense</th><th class="num">Net profit</th>
          <th class="num">ROI</th><th class="num">Cost / ha</th><th class="num">Cost / kg</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$profitability): ?>
        <tr><td colspan="9" class="empty">No crop cycles with transactions yet.</td></tr>
<?php endif; ?>
<?php foreach ($profitability as $p): ?>
        <tr>
          <td><?= e($p['crop_name']) ?></td>
          <td><?= e($p['season_label'] ?? '—') ?></td>
          <td class="num"><?= fmt_qty($p['area_planted_ha']) ?></td>
          <td class="num"><?= fmt_money($p['total_income']) ?></td>
          <td class="num"><?= fmt_money($p['total_expense']) ?></td>
          <td class="num" style="color:<?= $p['net_profit'] < 0 ? 'var(--rust)' : 'var(--leaf-dark)' ?>">
            <?= fmt_money($p['net_profit']) ?>
          </td>
          <td class="num"><?= $p['roi_percent'] !== null ? fmt_qty($p['roi_percent'], 1) . '%' : '—' ?></td>
          <td class="num"><?= $p['cost_per_hectare'] !== null ? fmt_money($p['cost_per_hectare']) : '—' ?></td>
          <td class="num"><?= $p['cost_per_unit'] !== null ? fmt_money($p['cost_per_unit']) : '—' ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <header>Expense by category</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Category</th><th class="num">Total</th></tr></thead>
      <tbody>
<?php if (!$expenseByCategory): ?>
        <tr><td colspan="2" class="empty">No expenses recorded yet.</td></tr>
<?php endif; ?>
<?php foreach ($expenseByCategory as $e): ?>
        <tr><td><?= e($e['category_name']) ?></td><td class="num"><?= fmt_money($e['total']) ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <header>Receivables — what customers owe</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Invoice</th><th>Customer</th><th class="num">Balance</th><th class="num">Days overdue</th></tr></thead>
      <tbody>
<?php if (!$receivables): ?>
        <tr><td colspan="4" class="empty">Nothing outstanding.</td></tr>
<?php endif; ?>
<?php foreach ($receivables as $r): ?>
        <tr>
          <td><a href="<?= e(base_url('modules/sales/invoice_view.php?id=' . (int) $r['invoice_id'])) ?>"><?= e($r['invoice_no']) ?></a></td>
          <td><?= e($r['customer_name']) ?></td>
          <td class="num"><?= fmt_money($r['balance']) ?></td>
          <td class="num" style="color:<?= $r['days_overdue'] > 0 ? 'var(--rust)' : 'inherit' ?>">
            <?= $r['days_overdue'] > 0 ? (int) $r['days_overdue'] : '—' ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <header>Payables — what we owe suppliers</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Purchase order</th><th>Supplier</th><th class="num">Balance</th></tr></thead>
      <tbody>
<?php if (!$payables): ?>
        <tr><td colspan="3" class="empty">Nothing outstanding.</td></tr>
<?php endif; ?>
<?php foreach ($payables as $p): ?>
        <tr>
          <td><?= e($p['po_no']) ?></td>
          <td><?= e($p['supplier_name']) ?></td>
          <td class="num"><?= fmt_money($p['balance']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
