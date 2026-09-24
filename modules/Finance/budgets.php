<?php
/**
 * Module 13 - Budgets.
 * A budget is a header with a period; lines set planned amounts per
 * category (and optionally per crop cycle). budget_view.php compares
 * each line's plan against what financial_transactions actually shows
 * for that category and period.
 */
$pageTitle = 'Budgets';
$activeNav = 'budgets';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'accountant']);
    csrf_check();

    $name         = post('budget_name');
    $periodStart  = post('period_start');
    $periodEnd    = post('period_end');

    $errors = [];
    if ($name === '' || $name === null) { $errors[] = 'Enter a budget name.'; }
    if (!valid_date($periodStart))      { $errors[] = 'Enter a valid start date.'; }
    if (!valid_date($periodEnd))        { $errors[] = 'Enter a valid end date.'; }
    if (valid_date($periodStart) && valid_date($periodEnd) && $periodEnd < $periodStart) {
        $errors[] = 'The end date is before the start date.';
    }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect('budgets.php');
    }

    $farmId = db()->query('SELECT farm_id FROM farms ORDER BY farm_id LIMIT 1')->fetchColumn();

    $stmt = db()->prepare(
        "INSERT INTO budgets (farm_id, budget_name, period_start, period_end, status)
         VALUES (:farm_id, :name, :start, :end, 'draft')"
    );
    $stmt->execute([':farm_id' => $farmId, ':name' => $name, ':start' => $periodStart, ':end' => $periodEnd]);

    flash('success', "$name created. Add category lines to it.");
    redirect('budget_view.php?id=' . db()->lastInsertId());
}

require_once __DIR__ . '/../../includes/header.php';

$budgets = db()->query(
    "SELECT b.*, COALESCE(SUM(bl.planned_amount), 0) AS total_planned,
            COALESCE((
              SELECT SUM(ft.amount) FROM financial_transactions ft
               WHERE ft.txn_type = 'expense' AND ft.txn_date BETWEEN b.period_start AND b.period_end
            ), 0) AS total_actual
       FROM budgets b
  LEFT JOIN budget_lines bl ON bl.budget_id = b.budget_id
      GROUP BY b.budget_id
      ORDER BY b.period_start DESC"
)->fetchAll();

$canManage = user_can(['admin', 'owner', 'accountant']);
?>

<div class="page-head">
  <div>
    <h1>Budgets</h1>
    <p class="lede">Planned spend by category and period, checked against what actually posted to the ledger.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Budget</th><th>Period</th><th class="num">Planned</th><th class="num">Actual (all expenses)</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
<?php if (!$budgets): ?>
        <tr><td colspan="6" class="empty">No budgets yet.</td></tr>
<?php endif; ?>
<?php foreach ($budgets as $b): ?>
        <tr>
          <td><a href="budget_view.php?id=<?= (int) $b['budget_id'] ?>"><?= e($b['budget_name']) ?></a></td>
          <td><?= e(fmt_date($b['period_start'])) ?> – <?= e(fmt_date($b['period_end'])) ?></td>
          <td class="num"><?= fmt_money($b['total_planned']) ?></td>
          <td class="num"><?= fmt_money($b['total_actual']) ?></td>
          <td><span class="status is-<?= $b['status'] === 'active' ? 'confirmed' : ($b['status'] === 'closed' ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst($b['status'])) ?></span></td>
          <td class="actions"><a class="btn btn-sm" href="budget_view.php?id=<?= (int) $b['budget_id'] ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>New budget</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid-3">
        <div class="field">
          <label for="budget_name">Name</label>
          <input type="text" name="budget_name" id="budget_name" required maxlength="120" placeholder="2026/27 Season">
        </div>
        <div class="field">
          <label for="period_start">Start</label>
          <input type="date" name="period_start" id="period_start" required>
        </div>
        <div class="field">
          <label for="period_end">End</label>
          <input type="date" name="period_end" id="period_end" required>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Create budget</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
