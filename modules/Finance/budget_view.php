<?php
/**
 * Module 13 - Budget detail: each line's plan against actual spend for
 * that category within the budget's period (and crop cycle, if the line
 * is tied to one).
 */
$activeNav = 'budgets';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$budgetId = get_int('id');
if (!$budgetId) {
    redirect('budgets.php');
}

$stmt = db()->prepare('SELECT * FROM budgets WHERE budget_id = :id');
$stmt->execute([':id' => $budgetId]);
$budget = $stmt->fetch();

if (!$budget) {
    flash('error', 'That budget does not exist.');
    redirect('budgets.php');
}

$pageTitle = $budget['budget_name'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT bl.*, fc.category_name, fc.category_type, c.crop_name,
            COALESCE((
              SELECT SUM(ft.amount) FROM financial_transactions ft
               WHERE ft.category_id = bl.category_id
                 AND ft.txn_date BETWEEN :start AND :end
                 AND (bl.cycle_id IS NULL OR ft.cycle_id = bl.cycle_id)
            ), 0) AS actual_amount
       FROM budget_lines bl
       JOIN finance_categories fc ON fc.category_id = bl.category_id
  LEFT JOIN crop_cycles cc ON cc.cycle_id = bl.cycle_id
  LEFT JOIN crops c ON c.crop_id = cc.crop_id
      WHERE bl.budget_id = :budget_id
   ORDER BY fc.category_name"
);
$stmt->execute([':start' => $budget['period_start'], ':end' => $budget['period_end'], ':budget_id' => $budgetId]);
$lines = $stmt->fetchAll();

$totalPlanned = array_sum(array_column($lines, 'planned_amount'));
$totalActual  = array_sum(array_column($lines, 'actual_amount'));

$categories = db()->query("SELECT category_id, category_name FROM finance_categories WHERE category_type = 'expense' ORDER BY category_name")->fetchAll();
$cycles = db()->query(
    "SELECT cc.cycle_id, c.crop_name, cc.season_label FROM crop_cycles cc JOIN crops c ON c.crop_id = cc.crop_id ORDER BY c.crop_name"
)->fetchAll();

$canManage = user_can(['admin', 'owner', 'accountant']) && $budget['status'] !== 'closed';
?>

<div class="page-head">
  <div>
    <h1><?= e($budget['budget_name']) ?></h1>
    <p class="lede"><?= e(fmt_date($budget['period_start'])) ?> – <?= e(fmt_date($budget['period_end'])) ?>
      <span class="status is-<?= $budget['status'] === 'active' ? 'confirmed' : ($budget['status'] === 'closed' ? 'cancelled' : 'draft') ?>">
        <?= e(ucfirst($budget['status'])) ?></span>
    </p>
  </div>
  <a class="btn" href="budgets.php">Back to list</a>
</div>

<dl class="stats">
  <div><dt>Planned</dt><dd><?= fmt_money($totalPlanned) ?></dd></div>
  <div><dt>Actual</dt><dd><?= fmt_money($totalActual) ?></dd></div>
  <div><dt>Variance</dt><dd><?= fmt_money($totalPlanned - $totalActual) ?></dd></div>
</dl>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Category</th><th>Crop cycle</th><th class="num">Planned</th><th class="num">Actual</th><th class="num">Variance</th></tr>
      </thead>
      <tbody>
<?php if (!$lines): ?>
        <tr><td colspan="5" class="empty">No lines yet. Add categories to plan against below.</td></tr>
<?php endif; ?>
<?php foreach ($lines as $line):
        $variance = (float) $line['planned_amount'] - (float) $line['actual_amount']; ?>
        <tr>
          <td><?= e($line['category_name']) ?></td>
          <td><?= e($line['crop_name'] ?? 'All crops') ?></td>
          <td class="num"><?= fmt_money($line['planned_amount']) ?></td>
          <td class="num"><?= fmt_money($line['actual_amount']) ?></td>
          <td class="num" style="color:<?= $variance < 0 ? 'var(--rust)' : 'inherit' ?>">
            <?= fmt_money($variance) ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canManage): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post" action="budget_line_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="budget_id" value="<?= $budgetId ?>">
      <div class="filters">
        <div class="field">
          <label for="category_id">Category</label>
          <select name="category_id" id="category_id" required>
<?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['category_id'] ?>"><?= e($c['category_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="cycle_id">Crop cycle</label>
          <select name="cycle_id" id="cycle_id">
            <option value="">All crops</option>
<?php foreach ($cycles as $c): ?>
            <option value="<?= (int) $c['cycle_id'] ?>"><?= e($c['crop_name']) ?> (<?= e($c['season_label'] ?? '—') ?>)</option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="planned_amount">Planned amount</label>
          <input type="number" name="planned_amount" id="planned_amount" step="0.01" min="0.01" required>
        </div>
        <div class="field" style="flex:1">
          <label for="notes">Notes</label>
          <input type="text" name="notes" id="notes" maxlength="255">
        </div>
        <button class="btn" type="submit">Add line</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
