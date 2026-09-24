<?php
/**
 * Module 15 - Stock on hand.
 * Reads through v_stock_on_hand (defined in the schema), so the "what's in
 * store and what's it worth" arithmetic lives in one place, not duplicated
 * across this page and Module 13's dashboard.
 */
$pageTitle = 'Produce in store';
$activeNav = 'batches';
require_once __DIR__ . '/../../includes/header.php';

$facilityFilter = get_int('facility_id');
$cropFilter     = $_GET['crop'] ?? '';
$expiring       = isset($_GET['expiring']);

$where  = [];
$params = [];

if ($facilityFilter) {
    $where[] = 'pb.facility_id = :facility_id';
    $params[':facility_id'] = $facilityFilter;
}
if ($cropFilter !== '') {
    $where[] = 'v.crop_name = :crop';
    $params[':crop'] = $cropFilter;
}
if ($expiring) {
    $where[] = 'v.best_before IS NOT NULL AND v.best_before <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT v.*, pb.facility_id
          FROM v_stock_on_hand v
          JOIN produce_batches pb ON pb.batch_id = v.batch_id
          $whereSql
      ORDER BY v.crop_name, v.batch_code";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

$totals = ['qty' => 0, 'value' => 0];
$expiringSoon = 0;
foreach ($batches as $b) {
    $totals['qty']   += (float) $b['current_qty'];
    $totals['value'] += (float) $b['stock_value'];
    if ($b['best_before'] && $b['best_before'] <= date('Y-m-d', strtotime('+14 days'))) {
        $expiringSoon++;
    }
}

$facilities = db()->query('SELECT facility_id, facility_name FROM storage_facilities ORDER BY facility_name')->fetchAll();
$crops      = db()->query('SELECT DISTINCT crop_name FROM v_stock_on_hand ORDER BY crop_name')->fetchAll();
$canManage  = user_can(['admin', 'owner', 'manager', 'storekeeper']);
?>

<div class="page-head">
  <div>
    <h1>Produce in store</h1>
    <p class="lede">Everything currently held, valued at production cost. Batches come from
      confirmed harvests; quantity changes are all recorded in the stock ledger.</p>
  </div>
  <a class="btn" href="facilities.php">Manage facilities</a>
</div>

<dl class="stats">
  <div><dt>Batches</dt><dd><?= number_format(count($batches)) ?></dd></div>
  <div><dt>Total quantity</dt><dd><?= fmt_qty($totals['qty']) ?></dd></div>
  <div><dt>Stock value</dt><dd><?= fmt_money($totals['value']) ?></dd></div>
  <div><dt>Expiring within 14 days</dt><dd><?= $expiringSoon ?></dd></div>
</dl>

<form class="panel" method="get">
  <div class="body filters">
    <div class="field">
      <label for="facility_id">Store</label>
      <select name="facility_id" id="facility_id">
        <option value="">All stores</option>
<?php foreach ($facilities as $f): ?>
        <option value="<?= (int) $f['facility_id'] ?>" <?= $facilityFilter === (int) $f['facility_id'] ? 'selected' : '' ?>>
          <?= e($f['facility_name']) ?>
        </option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="crop">Crop</label>
      <select name="crop" id="crop">
        <option value="">All crops</option>
<?php foreach ($crops as $c): ?>
        <option value="<?= e($c['crop_name']) ?>" <?= $cropFilter === $c['crop_name'] ? 'selected' : '' ?>>
          <?= e($c['crop_name']) ?>
        </option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label><input type="checkbox" name="expiring" value="1" <?= $expiring ? 'checked' : '' ?>
             style="width:auto;display:inline-block;margin-right:6px"> Expiring within 14 days</label>
    </div>
    <button class="btn" type="submit">Apply</button>
    <a class="btn" href="batches.php">Clear</a>
  </div>
</form>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Batch</th><th>Crop</th><th>Grade</th><th>Store</th>
          <th class="num">Quantity</th><th class="num">Unit cost</th><th class="num">Value</th>
          <th class="num">Days in store</th><th>Best before</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$batches): ?>
        <tr><td colspan="11" class="empty">Nothing in store matches these filters.</td></tr>
<?php endif; ?>
<?php foreach ($batches as $b):
        $isExpiring = $b['best_before'] && $b['best_before'] <= date('Y-m-d', strtotime('+14 days')); ?>
        <tr>
          <td><a href="batch_view.php?id=<?= (int) $b['batch_id'] ?>"><?= e($b['batch_code']) ?></a></td>
          <td><?= e($b['crop_name']) ?></td>
          <td><?= e($b['grade']) ?></td>
          <td><?= e($b['facility_name'] ?? '—') ?></td>
          <td class="num"><?= fmt_qty($b['current_qty']) ?> <?= e($b['unit_code']) ?></td>
          <td class="num"><?= fmt_money($b['unit_cost']) ?></td>
          <td class="num"><?= fmt_money($b['stock_value']) ?></td>
          <td class="num"><?= (int) $b['days_in_storage'] ?></td>
          <td<?= $isExpiring ? ' style="color:var(--rust);font-weight:600"' : '' ?>>
            <?= e(fmt_date($b['best_before'])) ?>
          </td>
          <td><?= e(str_replace('_', ' ', ucfirst($b['status']))) ?></td>
          <td class="actions"><a class="btn btn-sm" href="batch_view.php?id=<?= (int) $b['batch_id'] ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
