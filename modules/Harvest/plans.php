<?php
/**
 * Module 11 - Harvest scheduling.
 * List and create plans; the actual harvest is then recorded against one.
 */
$pageTitle = 'Harvest plans';
$activeNav = 'plans';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

/* ---------- Create ---------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager', 'agronomist']);
    csrf_check();

    $cycleId      = (int) post('cycle_id', 0);
    $expectedDate = post('expected_date');
    $expectedQty  = (float) post('expected_qty', 0);
    $unitId       = (int) post('unit_id', 0);
    $labour       = post('labour_required') !== '' ? (int) post('labour_required') : null;
    $notes        = post('notes') ?: null;

    $errors = [];

    if ($cycleId <= 0)             { $errors[] = 'Choose the crop cycle to schedule.'; }
    if (!valid_date($expectedDate)) { $errors[] = 'Enter a valid expected harvest date.'; }
    if ($expectedQty <= 0)          { $errors[] = 'Expected quantity must be greater than zero.'; }
    if ($unitId <= 0)               { $errors[] = 'Choose a unit of measure.'; }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
    } else {
        $stmt = db()->prepare(
            "INSERT INTO harvest_plans
               (cycle_id, expected_date, expected_qty, unit_id, labour_required, notes, created_by)
             VALUES
               (:cycle_id, :expected_date, :expected_qty, :unit_id, :labour, :notes, :user_id)"
        );

        $stmt->execute([
            ':cycle_id'      => $cycleId,
            ':expected_date' => $expectedDate,
            ':expected_qty'  => $expectedQty,
            ':unit_id'       => $unitId,
            ':labour'        => $labour,
            ':notes'         => $notes,
            ':user_id'       => current_user()['user_id'],
        ]);

        flash('success', 'Harvest plan added.');
    }

    redirect('plans.php');
}

require_once __DIR__ . '/../../includes/header.php';

/* ---------- Read ------------------------------------------------------- */

$plans = db()->query(
    "SELECT hp.*, c.crop_name, f.field_name, cc.area_planted_ha, u.unit_code,
            DATEDIFF(hp.expected_date, CURDATE()) AS days_away,
            COALESCE((SELECT SUM(h.net_qty) FROM harvests h
                       WHERE h.plan_id = hp.plan_id AND h.status = 'confirmed'), 0) AS harvested_qty
       FROM harvest_plans hp
       JOIN crop_cycles cc ON cc.cycle_id = hp.cycle_id
       JOIN crops  c ON c.crop_id  = cc.crop_id
       JOIN fields f ON f.field_id = cc.field_id
       JOIN units  u ON u.unit_id  = hp.unit_id
   ORDER BY hp.expected_date"
)->fetchAll();

$cycles = db()->query(
    "SELECT cc.cycle_id, cc.season_label, c.crop_name, f.field_name
       FROM crop_cycles cc
       JOIN crops  c ON c.crop_id  = cc.crop_id
       JOIN fields f ON f.field_id = cc.field_id
      WHERE cc.status IN ('planned', 'growing', 'harvesting')
   ORDER BY c.crop_name"
)->fetchAll();

$units    = db()->query('SELECT unit_id, unit_code FROM units ORDER BY unit_code')->fetchAll();
$canPlan  = user_can(['admin', 'owner', 'manager', 'agronomist']);
$dueSoon  = 0;

foreach ($plans as $plan) {
    if ($plan['status'] === 'planned' && $plan['days_away'] !== null
        && $plan['days_away'] >= 0 && $plan['days_away'] <= 14) {
        $dueSoon++;
    }
}
?>

<div class="page-head">
  <div>
    <h1>Harvest plans</h1>
    <p class="lede">Expected dates and yields per field. Recording an actual harvest against a plan
      is what makes the expected-versus-actual comparison possible.</p>
  </div>
</div>

<?php if ($dueSoon): ?>
<p class="msg msg-info"><?= $dueSoon ?> harvest<?= $dueSoon === 1 ? '' : 's' ?> due within the next two weeks.</p>
<?php endif; ?>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Crop</th>
          <th>Field</th>
          <th>Expected</th>
          <th class="num">Due in</th>
          <th class="num">Expected qty</th>
          <th class="num">Harvested</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$plans): ?>
        <tr><td colspan="8" class="empty">No harvests scheduled yet.</td></tr>
<?php endif; ?>
<?php foreach ($plans as $plan): ?>
        <tr>
          <td><?= e($plan['crop_name']) ?></td>
          <td><?= e($plan['field_name']) ?></td>
          <td><?= e(fmt_date($plan['expected_date'])) ?></td>
          <td class="num">
            <?= $plan['days_away'] < 0 ? abs((int) $plan['days_away']) . ' d ago' : (int) $plan['days_away'] . ' d' ?>
          </td>
          <td class="num"><?= fmt_qty($plan['expected_qty']) ?> <?= e($plan['unit_code']) ?></td>
          <td class="num"><?= fmt_qty($plan['harvested_qty']) ?></td>
          <td><?= e(str_replace('_', ' ', ucfirst($plan['status']))) ?></td>
          <td class="actions">
<?php if ($canPlan && $plan['status'] !== 'completed'): ?>
            <a class="btn btn-sm" href="form.php">Record harvest</a>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canPlan): ?>
<div class="panel">
  <header>Schedule a harvest</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="filters">
        <div class="field">
          <label for="cycle_id">Crop cycle</label>
          <select name="cycle_id" id="cycle_id" required>
            <option value="">Select</option>
<?php foreach ($cycles as $cycle): ?>
            <option value="<?= (int) $cycle['cycle_id'] ?>">
              <?= e($cycle['crop_name']) ?> — <?= e($cycle['field_name']) ?>
            </option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="expected_date">Expected date</label>
          <input type="date" name="expected_date" id="expected_date" required>
        </div>
        <div class="field">
          <label for="expected_qty">Expected quantity</label>
          <input type="number" name="expected_qty" id="expected_qty" step="0.001" min="0.001" required>
        </div>
        <div class="field">
          <label for="unit_id">Unit</label>
          <select name="unit_id" id="unit_id" required>
<?php foreach ($units as $unit): ?>
            <option value="<?= (int) $unit['unit_id'] ?>"><?= e($unit['unit_code']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="labour_required">Workers needed</label>
          <input type="number" name="labour_required" id="labour_required" min="0" step="1">
        </div>
        <div class="field" style="flex:1">
          <label for="notes">Notes</label>
          <input type="text" name="notes" id="notes" maxlength="255">
        </div>
        <button class="btn btn-primary" type="submit">Add plan</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
