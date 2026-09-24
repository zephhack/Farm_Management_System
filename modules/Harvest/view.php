<?php
/**
 * Module 11 - Harvest detail: summary, field losses, labour, resulting batch.
 */
$activeNav = 'harvest';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$harvestId = get_int('id');

if (!$harvestId) {
    redirect('index.php');
}

$sql = "SELECT h.*, c.crop_name, cv.variety_name, f.field_name, f.area_ha AS field_area,
               cc.area_planted_ha, cc.season_label, u.unit_code,
               sup.full_name AS supervisor_name,
               rec.full_name AS recorded_by_name,
               hp.expected_qty, hp.expected_date
          FROM harvests h
          JOIN crop_cycles cc ON cc.cycle_id = h.cycle_id
          JOIN crops  c ON c.crop_id  = cc.crop_id
          JOIN fields f ON f.field_id = cc.field_id
          JOIN units  u ON u.unit_id  = h.unit_id
     LEFT JOIN crop_varieties cv ON cv.variety_id = cc.variety_id
     LEFT JOIN users sup ON sup.user_id = h.supervisor_id
     LEFT JOIN users rec ON rec.user_id = h.recorded_by
     LEFT JOIN harvest_plans hp ON hp.plan_id = h.plan_id
         WHERE h.harvest_id = :id";

$stmt = db()->prepare($sql);
$stmt->execute([':id' => $harvestId]);
$harvest = $stmt->fetch();

if (!$harvest) {
    flash('error', 'That harvest record does not exist.');
    redirect('index.php');
}

$pageTitle = $harvest['harvest_code'];
require_once __DIR__ . '/../../includes/header.php';

/* Related records */
$stmt = db()->prepare(
    "SELECT l.*, u.unit_code, usr.full_name AS recorded_by_name
       FROM harvest_losses l
       JOIN units u ON u.unit_id = l.unit_id
  LEFT JOIN users usr ON usr.user_id = l.recorded_by
      WHERE l.harvest_id = :id
   ORDER BY l.loss_id"
);
$stmt->execute([':id' => $harvestId]);
$losses = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT hl.*, u.full_name
       FROM harvest_labour hl
       JOIN users u ON u.user_id = hl.worker_id
      WHERE hl.harvest_id = :id
   ORDER BY u.full_name"
);
$stmt->execute([':id' => $harvestId]);
$labour = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT batch_id, batch_code, grade, current_qty, status
       FROM produce_batches WHERE harvest_id = :id"
);
$stmt->execute([':id' => $harvestId]);
$batches = $stmt->fetchAll();

$workers = db()->query(
    "SELECT user_id, full_name FROM users WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

$units = db()->query('SELECT unit_id, unit_code FROM units ORDER BY unit_code')->fetchAll();

$facilities = db()->query(
    "SELECT facility_id, facility_name FROM storage_facilities
      WHERE is_active = 1 ORDER BY facility_name"
)->fetchAll();

$isDraft    = $harvest['status'] === 'draft';
$canManage  = user_can(['admin', 'owner', 'manager', 'agronomist']);
$labourCost = array_sum(array_column($labour, 'total_pay'));

$yieldPerHa = (float) $harvest['area_harvested_ha'] > 0
    ? (float) $harvest['net_qty'] / (float) $harvest['area_harvested_ha']
    : null;

$variance = $harvest['expected_qty'] !== null
    ? (float) $harvest['net_qty'] - (float) $harvest['expected_qty']
    : null;
?>

<div class="page-head">
  <div>
    <h1><?= e($harvest['harvest_code']) ?></h1>
    <p class="lede">
      <?= e($harvest['crop_name']) ?><?= $harvest['variety_name'] ? ' (' . e($harvest['variety_name']) . ')' : '' ?>
      from <?= e($harvest['field_name']) ?>, harvested <?= e(fmt_date($harvest['harvest_date'])) ?>.
      <span class="status is-<?= e($harvest['status']) ?>"><?= e(ucfirst($harvest['status'])) ?></span>
    </p>
  </div>
  <div>
<?php if ($isDraft && $canManage): ?>
    <a class="btn" href="form.php?id=<?= $harvestId ?>">Edit</a>
<?php if (!$facilities): ?>
    <span class="who">Add a storage facility before confirming.</span>
<?php else: ?>
    <form method="post" action="confirm.php" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="harvest_id" value="<?= $harvestId ?>">
      <select name="facility_id" required aria-label="Destination store" style="width:auto">
<?php foreach ($facilities as $facility): ?>
        <option value="<?= (int) $facility['facility_id'] ?>"><?= e($facility['facility_name']) ?></option>
<?php endforeach; ?>
      </select>
      <button class="btn btn-primary" type="submit"
              onclick="return confirm('Confirm this harvest? It will create a produce batch in store and lock the record.')">
        Confirm and send to store
      </button>
    </form>
<?php endif; ?>
<?php endif; ?>
    <a class="btn" href="index.php">Back to list</a>
  </div>
</div>

<dl class="stats">
  <div><dt>Gross</dt><dd><?= fmt_qty($harvest['gross_qty']) ?> <?= e($harvest['unit_code']) ?></dd></div>
  <div><dt>Losses</dt><dd><?= fmt_qty($harvest['gross_qty'] - $harvest['net_qty']) ?></dd></div>
  <div><dt>Net</dt><dd><?= fmt_qty($harvest['net_qty']) ?></dd></div>
  <div><dt>Yield per hectare</dt><dd><?= $yieldPerHa !== null ? fmt_qty($yieldPerHa) : '—' ?></dd></div>
  <div><dt>Labour cost</dt><dd><?= fmt_money($labourCost) ?></dd></div>
</dl>

<?php if ($harvest['expected_qty'] !== null): ?>
<p class="msg msg-info">
  Planned <?= fmt_qty($harvest['expected_qty']) ?> <?= e($harvest['unit_code']) ?>
  for <?= e(fmt_date($harvest['expected_date'])) ?>.
  Actual net is <?= fmt_qty($harvest['net_qty']) ?>,
  <?= $variance >= 0 ? 'ahead by ' : 'short by ' ?><?= fmt_qty(abs($variance)) ?>.
</p>
<?php endif; ?>

<div class="panel">
  <header>Record details</header>
  <div class="body">
    <dl class="detail">
      <div><dt>Field</dt><dd><?= e($harvest['field_name']) ?></dd></div>
      <div><dt>Season</dt><dd><?= e($harvest['season_label'] ?? '—') ?></dd></div>
      <div><dt>Area planted</dt><dd><?= fmt_qty($harvest['area_planted_ha']) ?> ha</dd></div>
      <div><dt>Area harvested</dt><dd><?= fmt_qty($harvest['area_harvested_ha']) ?> ha</dd></div>
      <div><dt>Method</dt><dd><?= e(ucfirst($harvest['method'])) ?></dd></div>
      <div><dt>Supervisor</dt><dd><?= e($harvest['supervisor_name'] ?? '—') ?></dd></div>
      <div><dt>Conditions</dt><dd><?= e($harvest['weather_note'] ?? '—') ?></dd></div>
      <div><dt>Recorded by</dt><dd><?= e($harvest['recorded_by_name'] ?? '—') ?></dd></div>
    </dl>
  </div>
</div>

<!-- ---------------- Losses ---------------- -->
<div class="panel">
  <header>Field losses and wastage</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Type</th>
          <th class="num">Quantity</th>
          <th>Unit</th>
          <th class="num">Value</th>
          <th>Description</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$losses): ?>
        <tr><td colspan="6" class="empty">No losses recorded. Net equals gross.</td></tr>
<?php endif; ?>
<?php foreach ($losses as $loss): ?>
        <tr>
          <td><?= e(ucfirst(str_replace('_', ' ', $loss['loss_type']))) ?></td>
          <td class="num"><?= fmt_qty($loss['qty_lost']) ?></td>
          <td><?= e($loss['unit_code']) ?></td>
          <td class="num"><?= fmt_money($loss['est_value']) ?></td>
          <td><?= e($loss['description'] ?? '—') ?></td>
          <td class="actions">
<?php if ($isDraft && $canManage): ?>
            <form method="post" action="loss_delete.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="loss_id" value="<?= (int) $loss['loss_id'] ?>">
              <input type="hidden" name="harvest_id" value="<?= $harvestId ?>">
              <button class="btn btn-sm btn-danger" type="submit"
                      onclick="return confirm('Remove this loss record?')">Remove</button>
            </form>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($isDraft && $canManage): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post" action="loss_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="harvest_id" value="<?= $harvestId ?>">
      <div class="filters">
        <div class="field">
          <label for="loss_type">Cause</label>
          <select name="loss_type" id="loss_type" required>
<?php foreach (['pest', 'disease', 'weather', 'mechanical', 'handling', 'theft', 'overripe', 'other'] as $type): ?>
            <option value="<?= e($type) ?>"><?= e(ucfirst($type)) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="qty_lost">Quantity lost</label>
          <input type="number" name="qty_lost" id="qty_lost" step="0.001" min="0.001" required>
        </div>
        <div class="field">
          <label for="loss_unit_id">Unit</label>
          <select name="unit_id" id="loss_unit_id" required>
<?php foreach ($units as $unit): ?>
            <option value="<?= (int) $unit['unit_id'] ?>"
              <?= (int) $unit['unit_id'] === (int) $harvest['unit_id'] ? 'selected' : '' ?>>
              <?= e($unit['unit_code']) ?>
            </option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="est_value">Estimated value</label>
          <input type="number" name="est_value" id="est_value" step="0.01" min="0" value="0">
        </div>
        <div class="field" style="flex:1">
          <label for="description">Description</label>
          <input type="text" name="description" id="description" maxlength="255">
        </div>
        <button class="btn" type="submit">Add loss</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Labour ---------------- -->
<div class="panel">
  <header>Harvest labour</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Worker</th>
          <th>Basis</th>
          <th class="num">Hours</th>
          <th class="num">Picked</th>
          <th class="num">Rate</th>
          <th class="num">Pay</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$labour): ?>
        <tr><td colspan="6" class="empty">No labour recorded for this harvest.</td></tr>
<?php endif; ?>
<?php foreach ($labour as $row): ?>
        <tr>
          <td><?= e($row['full_name']) ?></td>
          <td><?= e(str_replace('_', ' ', $row['pay_basis'])) ?></td>
          <td class="num"><?= fmt_qty($row['hours_worked']) ?></td>
          <td class="num"><?= $row['qty_picked'] !== null ? fmt_qty($row['qty_picked']) : '—' ?></td>
          <td class="num"><?= fmt_money($row['rate']) ?></td>
          <td class="num"><?= fmt_money($row['total_pay']) ?></td>
        </tr>
<?php endforeach; ?>
<?php if ($labour): ?>
        <tr>
          <td colspan="5"><strong>Total labour cost</strong></td>
          <td class="num"><strong><?= fmt_money($labourCost) ?></strong></td>
        </tr>
<?php endif; ?>
      </tbody>
    </table>
  </div>

<?php if ($isDraft && $canManage): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post" action="labour_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="harvest_id" value="<?= $harvestId ?>">
      <div class="filters">
        <div class="field">
          <label for="worker_id">Worker</label>
          <select name="worker_id" id="worker_id" required>
<?php foreach ($workers as $worker): ?>
            <option value="<?= (int) $worker['user_id'] ?>"><?= e($worker['full_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="pay_basis">Paid by</label>
          <select name="pay_basis" id="pay_basis">
            <option value="daily">Day</option>
            <option value="hourly">Hour</option>
            <option value="piece_rate">Quantity picked</option>
          </select>
        </div>
        <div class="field">
          <label for="hours_worked">Hours</label>
          <input type="number" name="hours_worked" id="hours_worked" step="0.25" min="0" value="8">
        </div>
        <div class="field">
          <label for="qty_picked">Quantity picked</label>
          <input type="number" name="qty_picked" id="qty_picked" step="0.001" min="0">
        </div>
        <div class="field">
          <label for="rate">Rate</label>
          <input type="number" name="rate" id="rate" step="0.01" min="0" required>
        </div>
        <button class="btn" type="submit">Add worker</button>
      </div>
      <p class="hint">Pay is worked out from the basis: rate &times; hours, rate per day, or rate &times; quantity picked.</p>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Resulting batch ---------------- -->
<?php if ($batches): ?>
<div class="panel">
  <header>Produce created in store</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Batch</th><th>Grade</th><th class="num">In store</th><th>Status</th></tr>
      </thead>
      <tbody>
<?php foreach ($batches as $batch): ?>
        <tr>
          <td><a href="<?= e(base_url('modules/storage/batch_view.php?id=' . (int) $batch['batch_id'])) ?>">
            <?= e($batch['batch_code']) ?></a></td>
          <td><?= e($batch['grade']) ?></td>
          <td class="num"><?= fmt_qty($batch['current_qty']) ?></td>
          <td><?= e(str_replace('_', ' ', $batch['status'])) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($isDraft && user_can(['admin', 'owner', 'manager'])): ?>
<form method="post" action="delete.php">
  <?= csrf_field() ?>
  <input type="hidden" name="harvest_id" value="<?= $harvestId ?>">
  <button class="btn btn-danger" type="submit"
          onclick="return confirm('Delete this draft harvest and everything attached to it?')">
    Delete this draft
  </button>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
