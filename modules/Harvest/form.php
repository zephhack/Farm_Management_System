<?php
/**
 * Module 11 - Create or edit a harvest record.
 * Same form serves both; an id in the query string switches it to edit mode.
 */
$pageTitle = 'Record a harvest';
$activeNav = 'harvest';
require_once __DIR__ . '/../../includes/header.php';
require_role(['admin', 'owner', 'manager', 'agronomist']);

$harvestId = get_int('id');
$harvest   = null;

if ($harvestId) {
    $stmt = db()->prepare('SELECT * FROM harvests WHERE harvest_id = :id');
    $stmt->execute([':id' => $harvestId]);
    $harvest = $stmt->fetch();

    if (!$harvest) {
        flash('error', 'That harvest record no longer exists.');
        redirect('index.php');
    }

    if ($harvest['status'] !== 'draft') {
        flash('error', 'Only draft harvests can be edited. Confirmed records are locked for audit.');
        redirect('view.php?id=' . $harvestId);
    }

    $pageTitle = 'Edit ' . $harvest['harvest_code'];
}

/* Only cycles that are actually harvestable. */
$cycles = db()->query(
    "SELECT cc.cycle_id, cc.season_label, cc.area_planted_ha,
            c.crop_name, f.field_name
       FROM crop_cycles cc
       JOIN crops  c ON c.crop_id  = cc.crop_id
       JOIN fields f ON f.field_id = cc.field_id
      WHERE cc.status IN ('growing', 'harvesting')
      ORDER BY c.crop_name, f.field_name"
)->fetchAll();

$units = db()->query('SELECT unit_id, unit_code, unit_name FROM units ORDER BY unit_code')->fetchAll();

$supervisors = db()->query(
    "SELECT user_id, full_name FROM users
      WHERE role IN ('manager', 'agronomist', 'owner') AND is_active = 1
      ORDER BY full_name"
)->fetchAll();

/* Plans available to link, so expected vs actual can be compared later. */
$plans = db()->query(
    "SELECT hp.plan_id, hp.expected_date, hp.expected_qty, hp.cycle_id, c.crop_name
       FROM harvest_plans hp
       JOIN crop_cycles cc ON cc.cycle_id = hp.cycle_id
       JOIN crops c ON c.crop_id = cc.crop_id
      WHERE hp.status IN ('planned', 'in_progress')
      ORDER BY hp.expected_date DESC"
)->fetchAll();

/* Repopulates the form after a validation error, else uses the stored value. */
$old = $_SESSION['old'] ?? [];
unset($_SESSION['old']);
?>

<div class="page-head">
  <div>
    <h1><?= $harvest ? 'Edit ' . e($harvest['harvest_code']) : 'Record a harvest' ?></h1>
    <p class="lede">Enter the gross quantity brought off the field. Losses are added afterwards
      and subtracted automatically to give the net figure.</p>
  </div>
  <a class="btn" href="<?= $harvest ? 'view.php?id=' . (int) $harvest['harvest_id'] : 'index.php' ?>">Cancel</a>
</div>

<form method="post" action="save.php" class="panel">
  <?= csrf_field() ?>
<?php if ($harvest): ?>
  <input type="hidden" name="harvest_id" value="<?= (int) $harvest['harvest_id'] ?>">
<?php endif; ?>

  <div class="body">
    <div class="grid-2">
      <div class="field">
        <label for="cycle_id">Crop cycle</label>
        <select name="cycle_id" id="cycle_id" required>
          <option value="">Select the field and crop</option>
<?php foreach ($cycles as $cycle):
          $selected = (int) ($old['cycle_id'] ?? $harvest['cycle_id'] ?? 0) === (int) $cycle['cycle_id']; ?>
          <option value="<?= (int) $cycle['cycle_id'] ?>" <?= $selected ? 'selected' : '' ?>>
            <?= e($cycle['crop_name']) ?> — <?= e($cycle['field_name']) ?>
            (<?= e($cycle['season_label'] ?? 'no season') ?>, <?= fmt_qty($cycle['area_planted_ha']) ?> ha)
          </option>
<?php endforeach; ?>
        </select>
        <p class="hint">Only cycles marked growing or harvesting appear here.</p>
      </div>

      <div class="field">
        <label for="harvest_date">Harvest date</label>
        <input type="date" name="harvest_date" id="harvest_date" required
               max="<?= date('Y-m-d') ?>"
               value="<?= e($old['harvest_date'] ?? $harvest['harvest_date'] ?? date('Y-m-d')) ?>">
        <p class="hint">Cannot be in the future.</p>
      </div>
    </div>

    <div class="grid-3">
      <div class="field">
        <label for="area_harvested_ha">Area harvested (ha)</label>
        <input type="number" name="area_harvested_ha" id="area_harvested_ha"
               step="0.001" min="0" required
               value="<?= e($old['area_harvested_ha'] ?? $harvest['area_harvested_ha'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="gross_qty">Gross quantity</label>
        <input type="number" name="gross_qty" id="gross_qty" step="0.001" min="0" required
               value="<?= e($old['gross_qty'] ?? $harvest['gross_qty'] ?? '') ?>">
        <p class="hint">Total picked, before losses.</p>
      </div>

      <div class="field">
        <label for="unit_id">Unit</label>
        <select name="unit_id" id="unit_id" required>
<?php foreach ($units as $unit):
          $selected = (int) ($old['unit_id'] ?? $harvest['unit_id'] ?? 0) === (int) $unit['unit_id']; ?>
          <option value="<?= (int) $unit['unit_id'] ?>" <?= $selected ? 'selected' : '' ?>>
            <?= e($unit['unit_code']) ?> — <?= e($unit['unit_name']) ?>
          </option>
<?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid-3">
      <div class="field">
        <label for="method">Harvesting method</label>
        <select name="method" id="method">
<?php foreach (['manual' => 'Manual', 'mechanical' => 'Mechanical', 'mixed' => 'Mixed'] as $value => $label):
          $selected = ($old['method'] ?? $harvest['method'] ?? 'manual') === $value; ?>
          <option value="<?= e($value) ?>" <?= $selected ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="supervisor_id">Supervised by</label>
        <select name="supervisor_id" id="supervisor_id">
          <option value="">Not recorded</option>
<?php foreach ($supervisors as $person):
          $selected = (int) ($old['supervisor_id'] ?? $harvest['supervisor_id'] ?? 0) === (int) $person['user_id']; ?>
          <option value="<?= (int) $person['user_id'] ?>" <?= $selected ? 'selected' : '' ?>>
            <?= e($person['full_name']) ?>
          </option>
<?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="plan_id">Against plan</label>
        <select name="plan_id" id="plan_id">
          <option value="">No plan linked</option>
<?php foreach ($plans as $plan):
          $selected = (int) ($old['plan_id'] ?? $harvest['plan_id'] ?? 0) === (int) $plan['plan_id']; ?>
          <option value="<?= (int) $plan['plan_id'] ?>" <?= $selected ? 'selected' : '' ?>>
            <?= e($plan['crop_name']) ?> — <?= e(fmt_date($plan['expected_date'])) ?>
            (<?= fmt_qty($plan['expected_qty']) ?> expected)
          </option>
<?php endforeach; ?>
        </select>
        <p class="hint">Links this record to an expected yield for variance reporting.</p>
      </div>
    </div>

    <div class="field">
      <label for="weather_note">Conditions on the day</label>
      <input type="text" name="weather_note" id="weather_note" maxlength="150"
             placeholder="Dry and clear, light morning rain, etc."
             value="<?= e($old['weather_note'] ?? $harvest['weather_note'] ?? '') ?>">
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">
        <?= $harvest ? 'Save changes' : 'Save harvest' ?>
      </button>
      <a class="btn" href="<?= $harvest ? 'view.php?id=' . (int) $harvest['harvest_id'] : 'index.php' ?>">Cancel</a>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
