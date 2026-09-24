<?php
/**
 * Module 15 - Batch detail: full traceability from harvest to whatever
 * remains today. Every quantity here is derived from stock_movements,
 * never edited directly, matching the "ledger is the source of truth"
 * design established when Module 11 confirmed the harvest.
 */
$activeNav = 'batches';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

$batchId = get_int('id');
if (!$batchId) {
    redirect('batches.php');
}

$stmt = db()->prepare(
    "SELECT pb.*, c.crop_name, cv.variety_name, u.unit_code, sf.facility_name,
            h.harvest_code, h.harvest_date AS actual_harvest_date
       FROM produce_batches pb
       JOIN crops c ON c.crop_id = pb.crop_id
       JOIN units u ON u.unit_id = pb.unit_id
  LEFT JOIN crop_varieties cv ON cv.variety_id = pb.variety_id
  LEFT JOIN storage_facilities sf ON sf.facility_id = pb.facility_id
  LEFT JOIN harvests h ON h.harvest_id = pb.harvest_id
      WHERE pb.batch_id = :id"
);
$stmt->execute([':id' => $batchId]);
$batch = $stmt->fetch();

if (!$batch) {
    flash('error', 'That batch does not exist.');
    redirect('batches.php');
}

$pageTitle = $batch['batch_code'];
require_once __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT sm.*, fromf.facility_name AS from_name, tof.facility_name AS to_name, u.full_name AS recorded_by_name
       FROM stock_movements sm
  LEFT JOIN storage_facilities fromf ON fromf.facility_id = sm.from_facility_id
  LEFT JOIN storage_facilities tof ON tof.facility_id = sm.to_facility_id
  LEFT JOIN users u ON u.user_id = sm.recorded_by
      WHERE sm.batch_id = :id
   ORDER BY sm.movement_date, sm.movement_id"
);
$stmt->execute([':id' => $batchId]);
$movements = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT qi.*, u.full_name AS inspector_name FROM quality_inspections qi
  LEFT JOIN users u ON u.user_id = qi.inspector_id
      WHERE qi.batch_id = :id ORDER BY qi.inspected_on DESC"
);
$stmt->execute([':id' => $batchId]);
$inspections = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT sr.*, u.full_name AS recorded_by_name FROM spoilage_records sr
  LEFT JOIN users u ON u.user_id = sr.recorded_by
      WHERE sr.batch_id = :id ORDER BY sr.detected_on DESC"
);
$stmt->execute([':id' => $batchId]);
$spoilage = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT gr.*, rb.batch_code AS result_batch_code FROM grading_records gr
  LEFT JOIN produce_batches rb ON rb.batch_id = gr.result_batch_id
      WHERE gr.source_batch_id = :id ORDER BY gr.graded_on DESC"
);
$stmt->execute([':id' => $batchId]);
$gradings = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM packaging_records WHERE batch_id = :id ORDER BY packed_on DESC');
$stmt->execute([':id' => $batchId]);
$packagings = $stmt->fetchAll();

$facilities = db()->query("SELECT facility_id, facility_name FROM storage_facilities WHERE is_active = 1 ORDER BY facility_name")->fetchAll();
$inspectors = db()->query("SELECT user_id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();

$canManage   = user_can(['admin', 'owner', 'manager', 'storekeeper']);
$hasStock    = (float) $batch['current_qty'] > 0.0001;
$isGraded    = $batch['grade'] !== 'ungraded';
?>

<div class="page-head">
  <div>
    <h1><?= e($batch['batch_code']) ?></h1>
    <p class="lede">
      <?= e($batch['crop_name']) ?><?= $batch['variety_name'] ? ' (' . e($batch['variety_name']) . ')' : '' ?>
<?php if ($batch['harvest_code']): ?>
      · from harvest <a href="<?= e(base_url('modules/harvest/view.php?id=' . (int) $batch['harvest_id'])) ?>">
        <?= e($batch['harvest_code']) ?></a>
<?php endif; ?>
      · grade <?= e($batch['grade']) ?>
      <span class="status is-<?= $hasStock ? 'confirmed' : 'cancelled' ?>">
        <?= e(str_replace('_', ' ', ucfirst($batch['status']))) ?>
      </span>
    </p>
  </div>
  <a class="btn" href="batches.php">Back to store</a>
</div>

<dl class="stats">
  <div><dt>In store</dt><dd><?= fmt_qty($batch['current_qty']) ?> <?= e($batch['unit_code']) ?></dd></div>
  <div><dt>Initial quantity</dt><dd><?= fmt_qty($batch['initial_qty']) ?></dd></div>
  <div><dt>Unit cost</dt><dd><?= fmt_money($batch['unit_cost']) ?></dd></div>
  <div><dt>Stock value</dt><dd><?= fmt_money($batch['current_qty'] * $batch['unit_cost']) ?></dd></div>
  <div><dt>Store</dt><dd><?= e($batch['facility_name'] ?? '—') ?></dd></div>
</dl>

<!-- ---------------- Movement ledger ---------------- -->
<div class="panel">
  <header>Stock movement ledger</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Date</th><th>Type</th><th class="num">Quantity</th><th>From</th><th>To</th><th>Reference</th><th>By</th></tr>
      </thead>
      <tbody>
<?php if (!$movements): ?>
        <tr><td colspan="7" class="empty">No movements recorded.</td></tr>
<?php endif; ?>
<?php foreach ($movements as $m): ?>
        <tr>
          <td><?= e(fmt_date($m['movement_date'])) ?></td>
          <td><?= e(str_replace('_', ' ', ucfirst($m['movement_type']))) ?></td>
          <td class="num"><?= ($m['movement_type'] === 'in' || $m['movement_type'] === 'return') ? '+' : '−' ?><?= fmt_qty($m['qty']) ?></td>
          <td><?= e($m['from_name'] ?? '—') ?></td>
          <td><?= e($m['to_name'] ?? '—') ?></td>
          <td><?= e(ucfirst($m['reference_type'])) ?><?= $m['remarks'] ? ' — ' . e($m['remarks']) : '' ?></td>
          <td><?= e($m['recorded_by_name'] ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ---------------- Grading ---------------- -->
<div class="panel">
  <header>Grading</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Grade</th><th class="num">Quantity</th><th>Resulting batch</th><th>Remarks</th></tr></thead>
      <tbody>
<?php if (!$gradings): ?>
        <tr><td colspan="5" class="empty">Not graded yet.</td></tr>
<?php endif; ?>
<?php foreach ($gradings as $g): ?>
        <tr>
          <td><?= e(fmt_date($g['graded_on'])) ?></td>
          <td><?= e($g['grade']) ?></td>
          <td class="num"><?= fmt_qty($g['qty']) ?></td>
          <td><?= $g['result_batch_code'] ? '<a href="batch_view.php?id=' . (int) $g['result_batch_id'] . '">' . e($g['result_batch_code']) . '</a>' : '—' ?></td>
          <td><?= e($g['remarks'] ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canManage && $hasStock && !$isGraded): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <p class="hint">Grading splits stock out of this batch into a new batch carrying the chosen grade,
      keeping this one's remaining quantity intact. Grade the whole batch by entering its full quantity.</p>
    <form method="post" action="grade_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="batch_id" value="<?= $batchId ?>">
      <div class="filters">
        <div class="field">
          <label for="grade">New grade</label>
          <select name="grade" id="grade" required>
            <option value="A">A</option><option value="B">B</option>
            <option value="C">C</option><option value="reject">Reject</option>
          </select>
        </div>
        <div class="field">
          <label for="qty">Quantity</label>
          <input type="number" name="qty" id="qty" step="0.001" min="0.001"
                 max="<?= e((string) $batch['current_qty']) ?>" value="<?= e((string) $batch['current_qty']) ?>" required>
        </div>
        <div class="field" style="flex:1">
          <label for="remarks">Remarks</label>
          <input type="text" name="remarks" id="remarks" maxlength="255">
        </div>
        <button class="btn" type="submit">Grade</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Quality inspections ---------------- -->
<div class="panel">
  <header>Quality inspections</header>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Date</th><th class="num">Moisture %</th><th class="num">Temp °C</th><th class="num">Pest damage %</th><th>Result</th><th>Findings</th></tr>
      </thead>
      <tbody>
<?php if (!$inspections): ?>
        <tr><td colspan="6" class="empty">No inspections recorded.</td></tr>
<?php endif; ?>
<?php foreach ($inspections as $i): ?>
        <tr>
          <td><?= e(fmt_date($i['inspected_on'])) ?></td>
          <td class="num"><?= $i['moisture_pct'] !== null ? fmt_qty($i['moisture_pct'], 1) : '—' ?></td>
          <td class="num"><?= $i['temperature_c'] !== null ? fmt_qty($i['temperature_c'], 1) : '—' ?></td>
          <td class="num"><?= $i['pest_damage_pct'] !== null ? fmt_qty($i['pest_damage_pct'], 1) : '—' ?></td>
          <td><span class="status is-<?= $i['overall_result'] === 'pass' ? 'confirmed' : ($i['overall_result'] === 'fail' ? 'cancelled' : 'draft') ?>">
            <?= e(ucfirst($i['overall_result'])) ?></span></td>
          <td><?= e($i['findings'] ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canManage): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post" action="inspection_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="batch_id" value="<?= $batchId ?>">
      <div class="grid-3">
        <div class="field">
          <label for="inspected_on">Date</label>
          <input type="date" name="inspected_on" id="inspected_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="moisture_pct">Moisture %</label>
          <input type="number" name="moisture_pct" id="moisture_pct" step="0.1" min="0" max="100">
        </div>
        <div class="field">
          <label for="temperature_c">Temperature °C</label>
          <input type="number" name="temperature_c" id="temperature_c" step="0.1">
        </div>
      </div>
      <div class="grid-3">
        <div class="field">
          <label for="pest_damage_pct">Pest damage %</label>
          <input type="number" name="pest_damage_pct" id="pest_damage_pct" step="0.1" min="0" max="100">
        </div>
        <div class="field">
          <label for="overall_result">Result</label>
          <select name="overall_result" id="overall_result">
            <option value="pass">Pass</option>
            <option value="conditional">Conditional</option>
            <option value="fail">Fail</option>
          </select>
        </div>
        <div class="field"></div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="findings">Findings</label>
          <input type="text" name="findings" id="findings" maxlength="255">
        </div>
        <div class="field">
          <label for="action_taken">Action taken</label>
          <input type="text" name="action_taken" id="action_taken" maxlength="255">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn" type="submit">Log inspection</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Spoilage ---------------- -->
<div class="panel">
  <header>Spoilage</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Cause</th><th class="num">Quantity</th><th class="num">Value lost</th><th>Disposal</th></tr></thead>
      <tbody>
<?php if (!$spoilage): ?>
        <tr><td colspan="5" class="empty">No spoilage recorded.</td></tr>
<?php endif; ?>
<?php foreach ($spoilage as $s): ?>
        <tr>
          <td><?= e(fmt_date($s['detected_on'])) ?></td>
          <td><?= e(str_replace('_', ' ', ucfirst($s['cause']))) ?></td>
          <td class="num"><?= fmt_qty($s['qty_spoiled']) ?></td>
          <td class="num"><?= fmt_money($s['value_lost']) ?></td>
          <td><?= e($s['disposal_method'] ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canManage && $hasStock): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <p class="hint">Recording spoilage removes the quantity from stock through the same ledger
      as a sale, and books the loss to Finance as a write-off.</p>
    <form method="post" action="spoilage_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="batch_id" value="<?= $batchId ?>">
      <div class="filters">
        <div class="field">
          <label for="detected_on">Date detected</label>
          <input type="date" name="detected_on" id="detected_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="cause">Cause</label>
          <select name="cause" id="cause" required>
<?php foreach (['rot', 'mould', 'pest', 'rodent', 'temperature', 'over_storage', 'handling', 'other'] as $cause): ?>
            <option value="<?= e($cause) ?>"><?= e(str_replace('_', ' ', ucfirst($cause))) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="qty_spoiled">Quantity</label>
          <input type="number" name="qty_spoiled" id="qty_spoiled" step="0.001" min="0.001"
                 max="<?= e((string) $batch['current_qty']) ?>" required>
        </div>
        <div class="field">
          <label for="disposal_method">Disposal method</label>
          <input type="text" name="disposal_method" id="disposal_method" maxlength="120">
        </div>
        <button class="btn btn-danger" type="submit">Record spoilage</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Packaging ---------------- -->
<div class="panel">
  <header>Packaging</header>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Package type</th><th class="num">Packages</th><th class="num">Quantity</th><th class="num">Material cost</th></tr></thead>
      <tbody>
<?php if (!$packagings): ?>
        <tr><td colspan="5" class="empty">No packaging logged.</td></tr>
<?php endif; ?>
<?php foreach ($packagings as $p): ?>
        <tr>
          <td><?= e(fmt_date($p['packed_on'])) ?></td>
          <td><?= e($p['package_type']) ?></td>
          <td class="num"><?= (int) $p['packages_made'] ?></td>
          <td class="num"><?= fmt_qty($p['qty_packed']) ?></td>
          <td class="num"><?= fmt_money($p['material_cost']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php if ($canManage && $hasStock): ?>
  <div class="body" style="border-top:1px solid var(--line)">
    <form method="post" action="packaging_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="batch_id" value="<?= $batchId ?>">
      <div class="filters">
        <div class="field">
          <label for="package_type">Package type</label>
          <input type="text" name="package_type" id="package_type" required maxlength="80" placeholder="50 kg poly bag">
        </div>
        <div class="field">
          <label for="packages_made">Number of packages</label>
          <input type="number" name="packages_made" id="packages_made" min="1" step="1" required>
        </div>
        <div class="field">
          <label for="qty_packed">Quantity packed</label>
          <input type="number" name="qty_packed" id="qty_packed" step="0.001" min="0.001"
                 max="<?= e((string) $batch['current_qty']) ?>" required>
        </div>
        <div class="field">
          <label for="material_cost">Material cost</label>
          <input type="number" name="material_cost" id="material_cost" step="0.01" min="0" value="0">
        </div>
        <button class="btn" type="submit">Log packaging</button>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>

<!-- ---------------- Transfer ---------------- -->
<?php if ($canManage && $hasStock && count($facilities) > 1): ?>
<div class="panel">
  <header>Transfer to another store</header>
  <div class="body">
    <form method="post" action="transfer_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="batch_id" value="<?= $batchId ?>">
      <div class="filters">
        <div class="field">
          <label for="to_facility_id">Move to</label>
          <select name="to_facility_id" id="to_facility_id" required>
<?php foreach ($facilities as $f): if ((int) $f['facility_id'] === (int) $batch['facility_id']) continue; ?>
            <option value="<?= (int) $f['facility_id'] ?>"><?= e($f['facility_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="transfer_remarks">Remarks</label>
          <input type="text" name="remarks" id="transfer_remarks" maxlength="255">
        </div>
        <button class="btn" type="submit">Transfer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
