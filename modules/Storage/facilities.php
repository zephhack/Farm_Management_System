<?php
/**
 * Module 15 - Storage facilities (warehouses, cold rooms, silos).
 * List + create, same combined pattern used for market destinations.
 */
$pageTitle = 'Storage facilities';
$activeNav = 'facilities';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager']);
    csrf_check();

    $name       = post('facility_name');
    $type       = post('facility_type', 'warehouse');
    $capacity   = (float) post('capacity', 0);
    $unitId     = post('capacity_unit_id') ? (int) post('capacity_unit_id') : null;
    $targetTemp = post('target_temp_c') !== '' ? (float) post('target_temp_c') : null;
    $managerId  = post('manager_id') ? (int) post('manager_id') : null;
    $location   = post('location') ?: null;

    $validTypes = ['warehouse', 'cold_room', 'silo', 'shed', 'crib', 'tank'];

    $errors = [];
    if ($name === '' || $name === null)      { $errors[] = 'Enter the facility name.'; }
    if (!in_array($type, $validTypes, true)) { $errors[] = 'Choose a valid facility type.'; }
    if ($capacity < 0)                       { $errors[] = 'Capacity cannot be negative.'; }

    /* farm_id: use the first farm on file, since this project has one farm. */
    $farmId = db()->query('SELECT farm_id FROM farms ORDER BY farm_id LIMIT 1')->fetchColumn();
    if (!$farmId) {
        $errors[] = 'No farm is registered yet. Add a farm before adding storage.';
    }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
    } else {
        db()->prepare(
            "INSERT INTO storage_facilities
               (farm_id, facility_name, facility_type, capacity, capacity_unit_id,
                target_temp_c, location, manager_id)
             VALUES
               (:farm_id, :name, :type, :capacity, :unit_id, :temp, :location, :manager_id)"
        )->execute([
            ':farm_id' => $farmId, ':name' => $name, ':type' => $type, ':capacity' => $capacity,
            ':unit_id' => $unitId, ':temp' => $targetTemp, ':location' => $location, ':manager_id' => $managerId,
        ]);
        flash('success', "$name added.");
    }

    redirect('facilities.php');
}

require_once __DIR__ . '/../../includes/header.php';

$facilities = db()->query(
    "SELECT sf.*, m.full_name AS manager_name,
            COALESCE((SELECT SUM(pb.current_qty) FROM produce_batches pb WHERE pb.facility_id = sf.facility_id), 0) AS stock_qty,
            COALESCE((SELECT COUNT(*) FROM produce_batches pb WHERE pb.facility_id = sf.facility_id AND pb.current_qty > 0), 0) AS batch_count
       FROM storage_facilities sf
  LEFT JOIN users m ON m.user_id = sf.manager_id
      ORDER BY sf.facility_name"
)->fetchAll();

$units      = db()->query('SELECT unit_id, unit_code FROM units ORDER BY unit_code')->fetchAll();
$managers   = db()->query("SELECT user_id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$canManage  = user_can(['admin', 'owner', 'manager']);
$types      = ['warehouse', 'cold_room', 'silo', 'shed', 'crib', 'tank'];
?>

<div class="page-head">
  <div>
    <h1>Storage facilities</h1>
    <p class="lede">Where produce is kept once it leaves the field. Every batch belongs to one of these.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Name</th><th>Type</th><th>Manager</th>
          <th class="num">Capacity</th><th class="num">Batches</th><th class="num">Stock on hand</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
<?php if (!$facilities): ?>
        <tr><td colspan="7" class="empty">No storage facilities yet.</td></tr>
<?php endif; ?>
<?php foreach ($facilities as $f): ?>
        <tr>
          <td><a href="batches.php?facility_id=<?= (int) $f['facility_id'] ?>"><?= e($f['facility_name']) ?></a></td>
          <td><?= e(str_replace('_', ' ', ucfirst($f['facility_type']))) ?></td>
          <td><?= e($f['manager_name'] ?? '—') ?></td>
          <td class="num"><?= fmt_qty($f['capacity']) ?></td>
          <td class="num"><?= (int) $f['batch_count'] ?></td>
          <td class="num"><?= fmt_qty($f['stock_qty']) ?></td>
          <td><span class="status is-<?= $f['is_active'] ? 'confirmed' : 'cancelled' ?>"><?= $f['is_active'] ? 'Active' : 'Closed' ?></span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>Add a facility</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid-3">
        <div class="field">
          <label for="facility_name">Name</label>
          <input type="text" name="facility_name" id="facility_name" required maxlength="120">
        </div>
        <div class="field">
          <label for="facility_type">Type</label>
          <select name="facility_type" id="facility_type">
<?php foreach ($types as $type): ?>
            <option value="<?= e($type) ?>"><?= e(str_replace('_', ' ', ucfirst($type))) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="manager_id">Manager</label>
          <select name="manager_id" id="manager_id">
            <option value="">Unassigned</option>
<?php foreach ($managers as $m): ?>
            <option value="<?= (int) $m['user_id'] ?>"><?= e($m['full_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid-3">
        <div class="field">
          <label for="capacity">Capacity</label>
          <input type="number" name="capacity" id="capacity" step="0.001" min="0" value="0">
        </div>
        <div class="field">
          <label for="capacity_unit_id">Unit</label>
          <select name="capacity_unit_id" id="capacity_unit_id">
            <option value="">—</option>
<?php foreach ($units as $u): ?>
            <option value="<?= (int) $u['unit_id'] ?>"><?= e($u['unit_code']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="target_temp_c">Target temperature (°C)</label>
          <input type="number" name="target_temp_c" id="target_temp_c" step="0.1">
          <p class="hint">Leave blank for ambient storage.</p>
        </div>
      </div>
      <div class="field">
        <label for="location">Location</label>
        <input type="text" name="location" id="location" maxlength="200">
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Add facility</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
